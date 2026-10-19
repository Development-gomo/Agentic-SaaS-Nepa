<?php
/**
 * Admin sync tool: regenerates the static JSON files in data/ from live WordPress
 * content, so editors don't have to hand-edit JSON when a product/case study/team
 * member changes. Adds a "Nepa Agent API" submenu under Tools with a single "Sync now" button.
 *
 * IMPORTANT — verify post type slugs before first use:
 * The constants below were inferred from this site's public sitemap filenames
 * (Yoast SEO generates one sitemap per post type, e.g. "cases-sitemap.xml" for
 * post_type "case"), but sitemap filenames are pluralized/formatted labels, not
 * always the literal post_type slug. Confirm each constant against
 * Admin -> a post's edit screen URL (?post=123&action=edit, then check the
 * "post_type" hidden field) or Admin -> Screen Options, before running Sync.
 * Getting a slug wrong just means that section's JSON stays untouched (sync now
 * skips rather than blanking the file) - it will not error or corrupt other sections.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Verify these against the live WordPress admin before relying on Sync (see note above).
define('NEPA_SYNC_POST_TYPE_PRODUCT', 'product');
define('NEPA_SYNC_POST_TYPE_SOLUTION', 'solution');
define('NEPA_SYNC_POST_TYPE_CASE', 'case');
define('NEPA_SYNC_POST_TYPE_PERSON', 'person');
define('NEPA_SYNC_POST_TYPE_EVENT', 'event');
define('NEPA_SYNC_POST_TYPE_WEBINAR', 'webinar');
define('NEPA_SYNC_POST_TYPE_NEWS', 'news'); // client-win/partnership announcements at /news/

class Nepa_Agent_API_Sync {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
    }

    public function add_admin_menu() {
        add_management_page(
            'Nepa Agent API Sync',
            'Nepa Agent API',
            'manage_options',
            'nepa-agent-api-sync',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $results = [];
        if (isset($_POST['nepa_sync_now']) && check_admin_referer('nepa_agent_api_sync')) {
            $results = $this->run_sync();
        }
        echo '<div class="wrap"><h1>Nepa Agent API Sync</h1>';
        echo '<p>Regenerates the JSON files served at <code>/agent/v1/*.json</code> from live WordPress content: products, solutions, case studies (result metrics are preserved, not overwritten), team/person, events + webinars, client-win news, blog posts, and press releases (English and Swedish, tagged by language). Hand-curated fields (case-study results and client details, team status notes, product categories, curated landing-page entries such as Nepa Trinity) are kept; new values from WordPress only fill empty fields. Blog posts and press releases are re-fetched from this site\'s own <code>/wp-json/wp/v2/posts</code> and <code>/wp-json/wp/v2/mfn_news</code> REST API each time you click Sync - they are NOT updated automatically between syncs, so re-run this after publishing new posts or releases if you want the agent API to reflect them.</p>';
        echo '<p>Output is written to <code>' . esc_html(nepa_agent_api_upload_data_dir()) . '</code>. Every section must succeed or nothing is replaced, and a section that would shrink to less than half its current size is refused. Sync does <strong>not</strong> regenerate agent-page.html, llms.txt or api-catalog.json, or the pricing, company, investor, localized-pages and resources files - see DEPLOYMENT.md.</p>';
        echo '<form method="post">';
        wp_nonce_field('nepa_agent_api_sync');
        echo '<p><button type="submit" name="nepa_sync_now" class="button button-primary">Sync now</button></p>';
        echo '</form>';
        if (!empty($results)) {
            echo '<h2>Results</h2><ul>';
            foreach ($results as $line) {
                echo '<li>' . esc_html($line) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    /** Synced output goes to wp-content/uploads/nepa-agent-api/ (see nepa_agent_api_data_path()). */
    private function data_dir() {
        $dir = nepa_agent_api_upload_data_dir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        return $dir;
    }

    /** @var array<string,string> target path => staged temp file, committed together at the end of run_sync(). */
    private $staged = [];

    /**
     * Stages a JSON file with a consistent { meta: {...}, ...body } envelope. Nothing
     * replaces a served file until commit_staged() runs after every section has
     * succeeded, so a sync that fails half-way leaves all files as they were. Top-level
     * keys and meta notes that the sync doesn't produce (hand-written notes, extra
     * summary fields) are carried over from the current file. Returns false if the temp
     * file can't be written in full.
     */
    private function write_json($filename, $body) {
        $existing = $this->load_existing($filename) ?: [];
        $meta = array_merge(is_array($existing['meta'] ?? null) ? $existing['meta'] : [], [
            'source'       => home_url('/agent/v1/' . preg_replace('/\.json$/', '', $filename) . '.json'),
            'version'      => '1.1',
            'release'      => NEPA_DATA_RELEASE,
            'last_updated' => gmdate('Y-m-d'),
            'synced_at'    => gmdate('c'),
        ]);
        unset($existing['meta']);
        $payload = array_merge(['meta' => $meta], $existing, $body);
        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        $dir = $this->data_dir();
        if (!is_dir($dir) || !wp_is_writable($dir)) {
            return false;
        }
        $target = $dir . $filename;
        $tmp = $target . '.tmp-' . wp_generate_password(8, false, false);
        $written = file_put_contents($tmp, $json, LOCK_EX);
        if ($written !== strlen($json)) {
            @unlink($tmp);
            return false;
        }
        $this->staged[$target] = $tmp;
        return true;
    }

    /**
     * Moves every staged file into place, then writes manifest.json (atomically) naming
     * the release this sync was built on and every synced file - the manifest is what
     * makes the synced copies served (see nepa_agent_api_data_path()). Files from an
     * earlier sync of the same release stay listed; after a new release only files
     * synced on top of it are listed. Returns the list of files that failed to move.
     */
    private function commit_staged() {
        $failed = [];
        $moved = [];
        foreach ($this->staged as $target => $tmp) {
            if (@rename($tmp, $target)) {
                $moved[] = basename($target);
            } else {
                @unlink($tmp);
                $failed[] = basename($target);
            }
        }
        $this->staged = [];
        if ($moved) {
            $previous = nepa_agent_api_sync_manifest(true);
            $files = ($previous['release'] ?? null) === NEPA_DATA_RELEASE ? (array) ($previous['files'] ?? []) : [];
            $manifest = [
                'release'   => NEPA_DATA_RELEASE,
                'synced_at' => gmdate('c'),
                'files'     => array_values(array_unique(array_merge($files, $moved))),
            ];
            $path = nepa_agent_api_upload_data_dir() . 'manifest.json';
            $tmp = $path . '.tmp-' . wp_generate_password(8, false, false);
            $json = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !@rename($tmp, $path)) {
                @unlink($tmp);
                $failed[] = 'manifest.json';
            }
            nepa_agent_api_sync_manifest(true);
        }
        return $failed;
    }

    private function discard_staged() {
        foreach ($this->staged as $tmp) {
            @unlink($tmp);
        }
        $this->staged = [];
    }

    /** Reads the copy currently being served (synced copy in uploads, or the bundled one). */
    private function load_existing($filename) {
        $path = nepa_agent_api_data_path($filename);
        if (!file_exists($path)) {
            return null;
        }
        $decoded = json_decode(file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Existing list items keyed by id. */
    private function existing_by_id($filename, $list_key) {
        $existing = $this->load_existing($filename);
        $out = [];
        foreach (($existing[$list_key] ?? []) as $item) {
            if (is_array($item) && !empty($item['id'])) {
                $out[$item['id']] = $item;
            }
        }
        return $out;
    }

    /**
     * Merges a freshly generated item into the hand-curated one with the same id.
     * WordPress is authoritative for the identity fields (id, title/name, url, date);
     * for every other field the curated value is kept whenever it is non-empty, and the
     * WordPress value only fills fields that are empty or missing. Fields that exist only
     * in the curated item (results, status_note, category, client, segment, ...) are kept.
     */
    private function merge_curated($fresh, $curated) {
        if (!$curated) {
            return $fresh;
        }
        $merged = $curated;
        foreach ($fresh as $field => $value) {
            $identity = in_array($field, ['id', 'title', 'name', 'url', 'date'], true);
            if ($identity && $value !== null && $value !== '') {
                $merged[$field] = $value;
            } elseif (!isset($curated[$field]) || $curated[$field] === null || $curated[$field] === '' || $curated[$field] === []) {
                $merged[$field] = $value;
            }
        }
        return $merged;
    }

    /**
     * Refuses to replace a list with one that is much smaller - the usual sign of a wrong
     * post type, a REST/translation-plugin filter, or a partial fetch rather than real
     * deletions. Returns an error string, or null if the new count is acceptable.
     */
    private function shrink_guard($filename, $old_count, $new_count) {
        if ($old_count >= 5 && $new_count < (int) ceil($old_count * 0.5)) {
            return "FAILED {$filename}: sync produced {$new_count} item(s) where the current file has {$old_count}. Refusing to replace it - check the post type / REST output. Nothing was written.";
        }
        return null;
    }

    public function run_sync() {
        // One sync at a time: a second click (or a second admin) waits for the first to finish.
        if (get_transient('nepa_agent_api_sync_lock')) {
            return ['SKIPPED: another sync is already running. Try again in a few minutes.'];
        }
        set_transient('nepa_agent_api_sync_lock', 1, 10 * MINUTE_IN_SECONDS);

        $log = [];
        try {
            $log[] = $this->sync_simple_cpt('products.json', NEPA_SYNC_POST_TYPE_PRODUCT, 'products', function ($p) {
                return [
                    'id'          => 'product-' . $p->post_name,
                    'name'        => get_the_title($p),
                    'category'    => null,
                    'description' => wp_strip_all_tags($p->post_excerpt ?: wp_trim_words(wp_strip_all_tags($p->post_content), 60)),
                    'url'         => get_permalink($p),
                ];
            });

            $log[] = $this->sync_simple_cpt('solutions.json', NEPA_SYNC_POST_TYPE_SOLUTION, 'solutions', function ($p) {
                return [
                    'id'          => 'solution-' . $p->post_name,
                    'name'        => get_the_title($p),
                    'description' => wp_strip_all_tags($p->post_excerpt ?: wp_trim_words(wp_strip_all_tags($p->post_content), 60)),
                    'url'         => get_permalink($p),
                ];
            });

            $log[] = $this->sync_simple_cpt('team.json', NEPA_SYNC_POST_TYPE_PERSON, 'team', function ($p) {
                return [
                    'id'    => 'person-' . $p->post_name,
                    'name'  => get_the_title($p),
                    'title' => get_post_meta($p->ID, 'title', true) ?: null,
                    'email' => get_post_meta($p->ID, 'email', true) ?: null,
                    'linkedin' => get_post_meta($p->ID, 'linkedin', true) ?: null,
                    'bio'   => wp_strip_all_tags($p->post_content) ?: null,
                    'url'   => get_permalink($p),
                ];
            });

            $log[] = $this->sync_simple_cpt('company-news.json', NEPA_SYNC_POST_TYPE_NEWS, 'news', function ($p) {
                return [
                    'id'      => 'news-' . $p->post_name,
                    'title'   => get_the_title($p),
                    'date'    => get_the_date('Y-m-d', $p),
                    'excerpt' => wp_strip_all_tags($p->post_excerpt ?: wp_trim_words(wp_strip_all_tags($p->post_content), 40)),
                    'url'     => get_permalink($p),
                ];
            }, 'news', 'count');

            $log[] = $this->sync_case_studies();
            $log[] = $this->sync_events_and_webinars();
            $log[] = $this->sync_blog_posts();
            $log[] = $this->sync_press_releases();

            $failures = array_filter($log, function ($line) { return strpos($line, 'FAILED') === 0; });
            if ($failures) {
                $this->discard_staged();
                $log[] = 'NOTHING WAS CHANGED: ' . count($failures) . ' section(s) failed, so no file was replaced (all-or-nothing). Fix the FAILED lines above and sync again.';
            } else {
                $not_moved = $this->commit_staged();
                $log[] = $not_moved
                    ? 'PARTIAL: could not move these files into place: ' . implode(', ', $not_moved) . '. Check permissions on ' . nepa_agent_api_upload_data_dir() . '.'
                    : 'DONE: files written to ' . nepa_agent_api_upload_data_dir() . ' and now served by /agent/v1/. Remember that agent-page.html, llms.txt and api-catalog.json are NOT regenerated by Sync - see DEPLOYMENT.md, "Keeping content fresh".';
            }
        } finally {
            $this->discard_staged();
            delete_transient('nepa_agent_api_sync_lock');
        }
        return $log;
    }

    /**
     * Generic sync for a flat list-shaped CPT. $list_key is the JSON array key (e.g. "products").
     * Each WordPress item is merged into the curated item with the same id (see
     * merge_curated()). Curated items with "source": "curated" (landing-page entries such as
     * Nepa Trinity or the BrandGrow Program, which are not posts of this type) are kept.
     */
    private function sync_simple_cpt($filename, $post_type, $list_key, callable $map, $override_key = null, $count_key = 'count') {
        if (!post_type_exists($post_type)) {
            return "SKIPPED {$filename}: post type '{$post_type}' does not exist on this site - verify NEPA_SYNC_POST_TYPE_* constants in class-sync.php. Existing file left untouched.";
        }
        $key = $override_key ?: $list_key;
        $curated = $this->existing_by_id($filename, $key);
        $posts = get_posts(['post_type' => $post_type, 'post_status' => 'publish', 'numberposts' => -1]);
        $items = [];
        $seen = [];
        foreach ($posts as $p) {
            $fresh = $map($p);
            $items[] = $this->merge_curated($fresh, $curated[$fresh['id']] ?? null);
            $seen[$fresh['id']] = true;
        }
        $kept = 0;
        foreach ($curated as $id => $item) {
            if (!isset($seen[$id]) && ($item['source'] ?? '') === 'curated') {
                $items[] = $item;
                $kept++;
            }
        }
        if ($guard = $this->shrink_guard($filename, count($curated), count($items))) {
            return $guard;
        }
        $ok = $this->write_json($filename, [$key => $items, $count_key => count($items)]);
        if (!$ok) {
            return "FAILED to write {$filename}: check that " . nepa_agent_api_upload_data_dir() . " is writable by the web server. Existing file left untouched.";
        }
        return "OK {$filename}: " . count($items) . " item(s) from post type '{$post_type}'" . ($kept ? ", plus {$kept} curated landing-page item(s) kept" : '') . '.';
    }

    /**
     * Case studies: WordPress supplies id, title, url and publish date; the hand-curated
     * client, industry, segment, service, summary and results are kept (see
     * merge_curated()), so a sync can't replace them with empty meta fields or a generic
     * excerpt. A case study that exists only in WordPress gets WordPress's values and an
     * empty results list - add its results to the data file by hand.
     */
    private function sync_case_studies() {
        $post_type = NEPA_SYNC_POST_TYPE_CASE;
        if (!post_type_exists($post_type)) {
            return "SKIPPED case-studies.json: post type '{$post_type}' does not exist - verify NEPA_SYNC_POST_TYPE_CASE. Existing file left untouched.";
        }
        $curated = $this->existing_by_id('case-studies.json', 'case_studies');
        $posts = get_posts(['post_type' => $post_type, 'post_status' => 'publish', 'numberposts' => -1]);
        $items = [];
        $seen = [];
        foreach ($posts as $p) {
            $id = 'case-' . $p->post_name;
            $fresh = [
                'id'       => $id,
                'title'    => get_the_title($p),
                'client'   => get_post_meta($p->ID, 'client', true) ?: null,
                'industry' => get_post_meta($p->ID, 'industry', true) ?: null,
                'segment'  => get_post_meta($p->ID, 'segment', true) ?: null,
                'service'  => get_post_meta($p->ID, 'service', true) ?: null,
                'summary'  => wp_strip_all_tags($p->post_excerpt ?: wp_trim_words(wp_strip_all_tags($p->post_content), 80)),
                'results'  => [],
                'url'      => get_permalink($p),
                'date_published' => get_the_date('Y-m-d', $p) ?: null,
            ];
            $items[] = $this->merge_curated($fresh, $curated[$id] ?? null);
            $seen[$id] = true;
        }
        // Curated case studies published as ordinary pages (e.g. /launching-marli-juissi-energy/).
        foreach ($curated as $id => $item) {
            if (!isset($seen[$id]) && ($item['source'] ?? '') === 'curated') {
                $items[] = $item;
            }
        }
        if ($guard = $this->shrink_guard('case-studies.json', count($curated), count($items))) {
            return $guard;
        }
        $ok = $this->write_json('case-studies.json', ['case_studies' => $items, 'count' => count($items)]);
        if (!$ok) {
            return "FAILED to write case-studies.json: check that " . nepa_agent_api_upload_data_dir() . " is writable by the web server. Existing file (and its results) left untouched.";
        }
        return "OK case-studies.json: " . count($items) . " item(s); curated fields and results kept for existing case studies. Add results for new case studies by hand.";
    }

    /**
     * Only overwrites events.json if at least one of the two configured post types
     * actually exists on this site. If NEITHER exists (e.g. the constants haven't been
     * verified yet), the sync is skipped entirely rather than replacing a real,
     * hand-maintained events.json with an empty list.
     *
     * If exactly ONE of the two exists (e.g. 'event' is right but 'webinar' was never
     * verified), a naive version would silently drop every item of the missing type from
     * the output the moment the other type synced successfully. Instead, for whichever
     * type is unavailable, this carries forward that type's existing entries from the
     * current file unchanged, so a wrong/unverified constant for one type can never erase
     * real data for the other.
     */
    private function sync_events_and_webinars() {
        $event_exists = post_type_exists(NEPA_SYNC_POST_TYPE_EVENT);
        $webinar_exists = post_type_exists(NEPA_SYNC_POST_TYPE_WEBINAR);
        if (!$event_exists && !$webinar_exists) {
            return "SKIPPED events.json: neither post type '" . NEPA_SYNC_POST_TYPE_EVENT . "' nor '" . NEPA_SYNC_POST_TYPE_WEBINAR . "' exists - verify NEPA_SYNC_POST_TYPE_EVENT/WEBINAR. Existing file left untouched.";
        }

        $existing = $this->load_existing('events.json');
        $existing_by_type = ['event' => [], 'webinar' => []];
        $existing_by_id = [];
        if ($existing && !empty($existing['events'])) {
            foreach ($existing['events'] as $item) {
                if (!empty($item['id'])) {
                    $existing_by_id[$item['id']] = $item;
                }
                $t = $item['type'] ?? null;
                if (isset($existing_by_type[$t])) {
                    $existing_by_type[$t][] = $item;
                }
            }
        }

        $items = [];
        $carried_forward = [];
        foreach ([NEPA_SYNC_POST_TYPE_EVENT => 'event', NEPA_SYNC_POST_TYPE_WEBINAR => 'webinar'] as $post_type => $type_label) {
            if (!post_type_exists($post_type)) {
                // This type's post_type wasn't found - keep whatever this type already had
                // in the file rather than silently deleting it.
                $items = array_merge($items, $existing_by_type[$type_label]);
                if (!empty($existing_by_type[$type_label])) {
                    $carried_forward[] = $type_label . ' (' . count($existing_by_type[$type_label]) . ')';
                }
                continue;
            }
            $posts = get_posts(['post_type' => $post_type, 'post_status' => 'publish', 'numberposts' => -1]);
            foreach ($posts as $p) {
                $id = $type_label . '-' . $p->post_name;
                // Hand-recorded values (date, location, status, summary) are kept when the
                // post has no matching meta field - otherwise an upcoming event with no
                // 'status' meta would be rewritten as 'on-demand' on every sync.
                $prev = $existing_by_id[$id] ?? [];
                $date = get_post_meta($p->ID, 'event_date', true) ?: ($prev['date'] ?? null);
                $status = get_post_meta($p->ID, 'status', true) ?: ($prev['status'] ?? ($type_label === 'webinar' ? 'on-demand' : 'past'));
                if ($status === 'upcoming' && $date && strlen($date) === 10 && $date < gmdate('Y-m-d')) {
                    $status = 'past';
                }
                $item = [
                    'id'       => $id,
                    'title'    => get_the_title($p),
                    'type'     => $type_label,
                    'date'     => $date,
                    'location' => get_post_meta($p->ID, 'location', true) ?: ($prev['location'] ?? null),
                    'status'   => $status,
                    'url'      => get_permalink($p),
                ];
                foreach (['summary', 'language'] as $extra) {
                    if (!empty($prev[$extra])) {
                        $item[$extra] = $prev[$extra];
                    }
                }
                $items[] = $item;
            }
        }
        if ($guard = $this->shrink_guard('events.json', count($existing['events'] ?? []), count($items))) {
            return $guard;
        }
        $ok = $this->write_json('events.json', ['events' => $items, 'count' => count($items)]);
        if (!$ok) {
            return "FAILED to write events.json: check that " . $this->data_dir() . " is writable by the web server. Existing file left untouched.";
        }
        $note = empty($carried_forward) ? '' : (' Carried forward unchanged (post type not found): ' . implode(', ', $carried_forward) . '.');
        return 'OK events.json: ' . count($items) . ' item(s) across event + webinar post types.' . $note;
    }

    /**
     * Blog posts are refreshed from this site's own REST API (/wp-json/wp/v2/posts). Each
     * language that already has posts in the file is requested explicitly as well
     * (?lang=sv for Polylang, ?wpml_language=sv for WPML - a plugin ignores the other's
     * parameter), because translation plugins often return only the default language from
     * the plain endpoint. If a language that had posts still comes back empty, its
     * existing posts are carried over unchanged instead of being dropped, and the log says
     * so - verify on staging which parameter this site honours.
     */
    private function sync_blog_posts() {
        $posts = $this->fetch_all_rest('posts', ['_embed' => 'author']);
        if ($posts === null) {
            return 'SKIPPED blog-posts.json: could not reach this site\'s own /wp-json/wp/v2/posts REST API. Existing file left untouched.';
        }
        $curated = $this->existing_by_id('blog-posts.json', 'posts');
        $existing_languages = [];
        foreach ($curated as $item) {
            $existing_languages[$item['language'] ?? 'en'] = true;
        }
        foreach (array_keys($existing_languages) as $lang) {
            if ($lang === 'en') {
                continue;
            }
            foreach ([['lang' => $lang], ['wpml_language' => $lang]] as $args) {
                $extra = $this->fetch_all_rest('posts', array_merge(['_embed' => 'author'], $args));
                if ($extra) {
                    $posts = array_merge($posts, $extra);
                }
            }
        }

        $items = [];
        foreach ($posts as $p) {
            $author = null;
            if (!empty($p['_embedded']['author'][0]['name'])) {
                $author = $p['_embedded']['author'][0]['name'];
            }
            $link = $p['link'] ?? '';
            $language = $this->language_from_link($link);
            $id = 'post-' . ($language === 'en' ? '' : $language . '-') . $this->slug_from_link($link);
            $items[$id] = [
                'id'       => $id,
                'title'    => $this->clean_html($p['title']['rendered'] ?? ''),
                'author'   => $author,
                'date'     => substr($p['date'] ?? '', 0, 10),
                'excerpt'  => substr($this->clean_html($p['excerpt']['rendered'] ?? ''), 0, 300),
                'url'      => $link,
                'language' => $language,
            ];
        }

        // Language protection: never drop a whole language because the REST output omitted it.
        $carried = [];
        $new_languages = [];
        foreach ($items as $item) {
            $new_languages[$item['language']] = true;
        }
        foreach ($curated as $id => $item) {
            $lang = $item['language'] ?? 'en';
            if (!isset($new_languages[$lang]) && !isset($items[$id])) {
                $items[$id] = $item;
                $carried[$lang] = ($carried[$lang] ?? 0) + 1;
            }
        }
        $items = array_values($items);
        usort($items, function ($a, $b) { return strcmp($b['date'], $a['date']); });

        if ($guard = $this->shrink_guard('blog-posts.json', count($curated), count($items))) {
            return $guard;
        }
        $ok = $this->write_json('blog-posts.json', ['posts' => $items, 'total' => count($items)]);
        if (!$ok) {
            return "FAILED to write blog-posts.json: check that " . nepa_agent_api_upload_data_dir() . " is writable by the web server. Existing file left untouched.";
        }
        $note = '';
        foreach ($carried as $lang => $n) {
            $note .= " WARNING: the REST API returned no '{$lang}' posts, so {$n} existing '{$lang}' post(s) were kept unchanged - check the translation plugin's REST language parameter.";
        }
        return 'OK blog-posts.json: ' . count($items) . ' post(s) refreshed live from /wp-json/wp/v2/posts.' . $note;
    }

    /** "sv", "fi" or "da" when a URL lives under that language prefix, otherwise "en". */
    private function language_from_link($link) {
        $path = (string) wp_parse_url($link, PHP_URL_PATH);
        if (preg_match('#^/(sv|fi|da)/#', $path, $m)) {
            return $m[1];
        }
        return 'en';
    }

    /**
     * Press releases (mfn_news) are refreshed the same way. Since data v1.1 (2026-10)
     * every release is kept, in every language, and tagged with a 'language' field taken
     * from the post's own class_list tags (mfn-news-tag-mfn-lang-en / -sv / -fi / -da /
     * -no) - an item with no language tag at all is assumed English (older, pre-tagging
     * posts). Agents filter with ?language=.
     *
     * At least one real release (the Svenska Spel selection announcement) is tagged
     * mfn-lang-sv on the live site although its title/content are English. A human
     * corrected its language by hand and marked it 'language_override' => true in the data
     * file; only items carrying that flag keep their recorded language over the tag, so a
     * later tag fix on the live site is still picked up for every other release.
     *
     * 'alternate_language_url' links an English release to its Swedish counterpart; see
     * the translation-link comment below for how recorded and suggested links are handled.
     */
    private function sync_press_releases() {
        $releases = $this->fetch_all_rest('mfn_news');
        if ($releases === null) {
            return 'SKIPPED press-releases.json: could not reach this site\'s own /wp-json/wp/v2/mfn_news REST API. Existing file left untouched.';
        }
        $category_map = [
            'mfn-news-tag-mfn-type-ir'       => 'Investor Relations',
            'mfn-news-tag-mfn-type-pr'       => 'Press Release',
            'mfn-news-tag-mfn-report-interim' => 'Interim Report',
            'mfn-news-tag-mfn-report-annual'  => 'Annual Report',
            'mfn-news-tag-mfn-regulatory'     => 'Regulatory Disclosure',
        ];

        // Hand-corrected languages (flagged language_override) - win over the tag.
        $existing = $this->load_existing('press-releases.json');
        $recorded_language = [];
        if ($existing && !empty($existing['releases'])) {
            foreach ($existing['releases'] as $existing_item) {
                if (!empty($existing_item['id']) && !empty($existing_item['language']) && !empty($existing_item['language_override'])) {
                    $recorded_language[$existing_item['id']] = $existing_item['language'];
                }
            }
        }

        $items = [];
        $kept_recorded = [];
        foreach ($releases as $r) {
            $classes = $r['class_list'] ?? [];
            $language = 'en';
            foreach (['sv', 'fi', 'da', 'no', 'en'] as $code) {
                if (in_array('mfn-news-tag-mfn-lang-' . $code, $classes, true)) {
                    $language = $code;
                    break;
                }
            }
            $id = 'mfn-' . $this->slug_from_link($r['link'] ?? '');
            $override = isset($recorded_language[$id]);
            if ($override && $recorded_language[$id] !== $language) {
                $language = $recorded_language[$id];
                $kept_recorded[] = $id;
            }
            $category = 'Press Release';
            foreach ($category_map as $tag => $label) {
                if (in_array($tag, $classes, true)) {
                    $category = $label;
                    break;
                }
            }
            $item = [
                'id'       => $id,
                'title'    => $this->clean_html($r['title']['rendered'] ?? ''),
                'date'     => substr($r['date'] ?? '', 0, 10),
                'category' => $category,
                'url'      => $r['link'] ?? '',
                'language' => $language,
            ];
            if (in_array('mfn-news-tag-mfn-regulatory', $classes, true)) {
                $item['regulatory'] = true;
            }
            if ($override) {
                $item['language_override'] = true;
            }
            $items[] = $item;
        }

        // Translation links. Pairs already in the file were checked by a person
        // (alternate_language_verified) and are kept as they are. For releases that have no
        // recorded pair, a same-day English/Swedish match is suggested only when exactly one
        // unpaired release of each language exists that day with the same category and
        // regulatory status; it is marked alternate_language_verified = false until checked.
        $recorded_pairs = [];
        foreach (($existing['releases'] ?? []) as $existing_item) {
            if (!empty($existing_item['url']) && !empty($existing_item['alternate_language_url'])) {
                $recorded_pairs[$existing_item['url']] = [
                    $existing_item['alternate_language_url'],
                    !empty($existing_item['alternate_language_verified']),
                ];
            }
        }
        $index_by_url = [];
        foreach ($items as $i => $item) {
            $index_by_url[$item['url']] = $i;
        }
        foreach ($items as $i => $item) {
            if (isset($recorded_pairs[$item['url']]) && isset($index_by_url[$recorded_pairs[$item['url']][0]])) {
                $items[$i]['alternate_language_url'] = $recorded_pairs[$item['url']][0];
                $items[$i]['alternate_language_verified'] = $recorded_pairs[$item['url']][1];
            }
        }
        $by_day = [];
        foreach ($items as $i => $item) {
            if (empty($item['alternate_language_url'])) {
                $group = $item['date'] . '|' . $item['category'] . '|' . (empty($item['regulatory']) ? '0' : '1');
                $by_day[$group][$item['language']][] = $i;
            }
        }
        $suggested = 0;
        foreach ($by_day as $langs) {
            if (count($langs['en'] ?? []) === 1 && count($langs['sv'] ?? []) === 1) {
                $en = $langs['en'][0];
                $sv = $langs['sv'][0];
                $items[$en]['alternate_language_url'] = $items[$sv]['url'];
                $items[$sv]['alternate_language_url'] = $items[$en]['url'];
                $items[$en]['alternate_language_verified'] = false;
                $items[$sv]['alternate_language_verified'] = false;
                $suggested++;
            }
        }

        usort($items, function ($a, $b) { return strcmp($b['date'], $a['date']); });

        if ($guard = $this->shrink_guard('press-releases.json', count($existing['releases'] ?? []), count($items))) {
            return $guard;
        }

        $ok = $this->write_json('press-releases.json', [
            'releases' => $items,
            'total'    => count($items),
        ]);
        if (!$ok) {
            return "FAILED to write press-releases.json: check that " . $this->data_dir() . " is writable by the web server. Existing file left untouched.";
        }
        $note = empty($kept_recorded) ? '' : (' Kept hand-corrected language for ' . count($kept_recorded) . ' release(s): ' . implode(', ', $kept_recorded) . '.');
        if ($suggested) {
            $note .= " {$suggested} new same-day translation link(s) suggested (alternate_language_verified = false) - check them.";
        }
        return 'OK press-releases.json: ' . count($items) . ' release(s) refreshed live from /wp-json/wp/v2/mfn_news (of ' . count($releases) . ' total fetched).' . $note;
    }

    /**
     * Paginate through this site's own REST API for a given post type, following the same
     * per_page=100 pattern used to originally build the data files.
     *
     * Returns null on ANY failure at ANY page - a network error, an unexpected status code,
     * a response body that isn't valid JSON, or a JSON body that isn't structurally a list -
     * never a partial array. Two specific failure modes look deceptively like legitimate
     * "end of data" if checked loosely, so both are checked precisely rather than by HTTP
     * status code alone:
     *
     *   1. A 400 on page > 1 is WordPress's real "you've gone past the last page" signal
     *      ONLY when the body's own `code` field is exactly `rest_post_invalid_page_number`.
     *      Any OTHER 400 (a WAF block, a different validation error, a misconfigured proxy
     *      that happens to also return 400) is a genuine failure, not "no more content" -
     *      even though the HTTP status code alone can't tell them apart.
     *   2. A 200 response must be a JSON ARRAY to mean "here are the posts" - an empty
     *      array `[]` genuinely means zero results. An empty JSON OBJECT `{}` decodes to the
     *      same empty PHP array as `[]` once passed through json_decode(..., true), making
     *      them indistinguishable if checked only with is_array()/count() on the assoc-mode
     *      result. A real WP REST collection endpoint never returns `{}` for a list, so the
     *      body is first decoded WITHOUT forcing objects to arrays (json_decode($body)) to
     *      check its genuine top-level shape: PHP represents a JSON array as a PHP array and
     *      a JSON object as a stdClass, so this distinguishes them where assoc-mode cannot.
     */
    private function fetch_all_rest($rest_base, $extra_args = []) {
        $items = [];
        $page = 1;
        while (true) {
            $args = array_merge(['per_page' => 100, 'page' => $page], $extra_args);
            $url = add_query_arg($args, home_url("/wp-json/wp/v2/{$rest_base}"));
            $response = wp_remote_get($url, ['timeout' => 20]);
            if (is_wp_error($response)) {
                return null;
            }
            $code = wp_remote_retrieve_response_code($response);
            $raw_body = wp_remote_retrieve_body($response);

            // Decode once without forcing assoc-arrays, purely to inspect the real top-level
            // JSON shape (array vs object) before any downstream processing.
            $structural = json_decode($raw_body);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null; // malformed body entirely - always a failure, at any page
            }

            if ($code === 400) {
                $error_code = is_object($structural) ? ($structural->code ?? null) : null;
                if ($page > 1 && $error_code === 'rest_post_invalid_page_number') {
                    return $items; // WordPress's specific, genuine end-of-pagination signal
                }
                return null; // any other 400 (WAF, different error, page 1, etc.) is a real failure
            }
            if ($code !== 200) {
                return null;
            }
            if (!is_array($structural)) {
                // Valid JSON, HTTP 200, but not actually a JSON array (e.g. `{}`) - not a
                // real "list of posts" response regardless of what it might coerce to.
                return null;
            }
            if (count($structural) === 0) {
                return $items; // genuine, unambiguous end-of-data: a real empty list
            }

            $batch = json_decode($raw_body, true); // now safe to decode in assoc mode for field access
            $items = array_merge($items, $batch);
            $page++;
        }
    }

    private function slug_from_link($link) {
        $trimmed = rtrim($link, '/');
        $parts = explode('/', $trimmed);
        return end($parts) ?: '';
    }

    private function clean_html($s) {
        $s = wp_strip_all_tags((string) $s);
        $s = html_entity_decode($s, ENT_QUOTES);
        $s = trim(preg_replace('/\s+/', ' ', $s));
        $s = preg_replace('/\s*\[…\]\s*$/u', '', $s);
        return $s;
    }
}

new Nepa_Agent_API_Sync();
