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
        echo '<p>Regenerates the JSON files served at <code>/agent/v1/*.json</code> from live WordPress content: products, solutions, case studies (result metrics are preserved, not overwritten), team/person, events + webinars, client-win news, blog posts, and press releases (English-language only). Blog posts and press releases are re-fetched from this site\'s own <code>/wp-json/wp/v2/posts</code> and <code>/wp-json/wp/v2/mfn_news</code> REST API each time you click Sync - they are NOT updated automatically between syncs, so re-run this after publishing new posts or releases if you want the agent API to reflect them.</p>';
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

    private function data_dir() {
        return NEPA_API_DIR . 'data/';
    }

    /**
     * Write a JSON file with a consistent { meta: {...}, ...body } envelope, atomically
     * (write to a temp file, then rename over the target) so a failed or interrupted
     * write can never leave a corrupt/truncated file in place. Returns true only if the
     * write and rename both actually succeeded - callers must check this and must NOT
     * report success on a false return.
     */
    private function write_json($filename, $body) {
        $payload = array_merge([
            'meta' => [
                'source'       => home_url('/agent/v1/' . preg_replace('/\.json$/', '', $filename) . '.json'),
                'version'      => '1.0',
                'last_updated' => gmdate('Y-m-d'),
            ],
        ], $body);
        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        $target = $this->data_dir() . $filename;
        $tmp = $target . '.tmp-' . wp_generate_password(8, false, false);
        $written = file_put_contents($tmp, $json, LOCK_EX);
        if ($written === false) {
            return false;
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    private function load_existing($filename) {
        $path = $this->data_dir() . $filename;
        if (!file_exists($path)) {
            return null;
        }
        $decoded = json_decode(file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function run_sync() {
        $log = [];
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

        return $log;
    }

    /** Generic sync for a flat list-shaped CPT. $list_key is the JSON array key (e.g. "products"). */
    private function sync_simple_cpt($filename, $post_type, $list_key, callable $map, $override_key = null, $count_key = 'count') {
        if (!post_type_exists($post_type)) {
            return "SKIPPED {$filename}: post type '{$post_type}' does not exist on this site - verify NEPA_SYNC_POST_TYPE_* constants in class-sync.php. Existing file left untouched.";
        }
        $posts = get_posts(['post_type' => $post_type, 'post_status' => 'publish', 'numberposts' => -1]);
        $items = array_map($map, $posts);
        $key = $override_key ?: $list_key;
        $ok = $this->write_json($filename, [$key => $items, $count_key => count($items)]);
        if (!$ok) {
            return "FAILED to write {$filename}: check that " . $this->data_dir() . " is writable by the web server. Existing file left untouched.";
        }
        return "OK {$filename}: " . count($items) . " item(s) from post type '{$post_type}'.";
    }

    /**
     * Case studies carry hand-curated 'results' metrics (e.g. "+36% brand attribution")
     * that don't come from any WordPress field yet. A naive re-sync would blank every
     * case study's results back to []. Instead, this preserves the existing 'results'
     * array (matched by id) for any case study that already has one, and only defaults
     * to [] for genuinely new case studies that have never had results recorded.
     */
    private function sync_case_studies() {
        $post_type = NEPA_SYNC_POST_TYPE_CASE;
        if (!post_type_exists($post_type)) {
            return "SKIPPED case-studies.json: post type '{$post_type}' does not exist - verify NEPA_SYNC_POST_TYPE_CASE. Existing file left untouched.";
        }

        $existing = $this->load_existing('case-studies.json');
        $existing_results_by_id = [];
        if ($existing && !empty($existing['case_studies'])) {
            foreach ($existing['case_studies'] as $item) {
                if (!empty($item['id']) && !empty($item['results'])) {
                    $existing_results_by_id[$item['id']] = $item['results'];
                }
            }
        }

        $posts = get_posts(['post_type' => $post_type, 'post_status' => 'publish', 'numberposts' => -1]);
        $items = array_map(function ($p) use ($existing_results_by_id) {
            $id = 'case-' . $p->post_name;
            return [
                'id'       => $id,
                'title'    => get_the_title($p),
                'client'   => get_post_meta($p->ID, 'client', true) ?: null,
                'industry' => get_post_meta($p->ID, 'industry', true) ?: null,
                'segment'  => get_post_meta($p->ID, 'segment', true) ?: null,
                'service'  => get_post_meta($p->ID, 'service', true) ?: null,
                'summary'  => wp_strip_all_tags($p->post_excerpt ?: wp_trim_words(wp_strip_all_tags($p->post_content), 80)),
                // Preserve hand-curated results for this id if we already have any; only
                // ever default to [] for a case study that has never had results.
                'results'  => $existing_results_by_id[$id] ?? [],
                'url'      => get_permalink($p),
            ];
        }, $posts);

        $ok = $this->write_json('case-studies.json', ['case_studies' => $items, 'count' => count($items)]);
        if (!$ok) {
            return "FAILED to write case-studies.json: check that " . $this->data_dir() . " is writable by the web server. Existing file (and its results) left untouched.";
        }
        $preserved = count($existing_results_by_id);
        return "OK case-studies.json: " . count($items) . " item(s) from post type '{$post_type}', preserved hand-curated results for {$preserved} case(s). To add results for a new case study, edit data/case-studies.json by hand - Sync will not overwrite them again.";
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
        if ($existing && !empty($existing['events'])) {
            foreach ($existing['events'] as $item) {
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
                $items[] = [
                    'id'       => $type_label . '-' . $p->post_name,
                    'title'    => get_the_title($p),
                    'type'     => $type_label,
                    'date'     => get_post_meta($p->ID, 'event_date', true) ?: null,
                    'location' => get_post_meta($p->ID, 'location', true) ?: null,
                    'status'   => get_post_meta($p->ID, 'status', true) ?: 'on-demand',
                    'url'      => get_permalink($p),
                ];
            }
        }
        $ok = $this->write_json('events.json', ['events' => $items, 'count' => count($items)]);
        if (!$ok) {
            return "FAILED to write events.json: check that " . $this->data_dir() . " is writable by the web server. Existing file left untouched.";
        }
        $note = empty($carried_forward) ? '' : (' Carried forward unchanged (post type not found): ' . implode(', ', $carried_forward) . '.');
        return 'OK events.json: ' . count($items) . ' item(s) across event + webinar post types.' . $note;
    }

    /**
     * Blog posts are refreshed by calling this same site's own REST API
     * (/wp-json/wp/v2/posts) rather than a direct get_posts() query, so the shape and
     * language-scoping exactly match what was used to originally populate this file
     * (see the project's build script) and won't drift if WPML's query behavior differs
     * between REST and direct WP_Query contexts.
     */
    private function sync_blog_posts() {
        $posts = $this->fetch_all_rest('posts', ['_embed' => 'author']);
        if ($posts === null) {
            return 'SKIPPED blog-posts.json: could not reach this site\'s own /wp-json/wp/v2/posts REST API. Existing file left untouched.';
        }
        $items = array_map(function ($p) {
            $author = null;
            if (!empty($p['_embedded']['author'][0]['name'])) {
                $author = $p['_embedded']['author'][0]['name'];
            }
            return [
                'id'      => 'post-' . $this->slug_from_link($p['link'] ?? ''),
                'title'   => $this->clean_html($p['title']['rendered'] ?? ''),
                'author'  => $author,
                'date'    => substr($p['date'] ?? '', 0, 10),
                'excerpt' => substr($this->clean_html($p['excerpt']['rendered'] ?? ''), 0, 300),
                'url'     => $p['link'] ?? '',
            ];
        }, $posts);
        usort($items, function ($a, $b) { return strcmp($b['date'], $a['date']); });

        $ok = $this->write_json('blog-posts.json', ['posts' => $items, 'total' => count($items)]);
        if (!$ok) {
            return "FAILED to write blog-posts.json: check that " . $this->data_dir() . " is writable by the web server. Existing file left untouched.";
        }
        return 'OK blog-posts.json: ' . count($items) . ' post(s) refreshed live from /wp-json/wp/v2/posts.';
    }

    /**
     * Press releases (mfn_news) are refreshed the same way, filtered to English-language
     * items using the post's own class_list tags (mfn-news-tag-mfn-lang-en /
     * mfn-news-tag-mfn-lang-sv / etc.) exactly as the original build script did - an item
     * with no language tag at all is assumed English (matches older, pre-tagging posts).
     *
     * At least one real release (the Svenska Spel selection announcement) is tagged
     * mfn-lang-sv on the live site despite its actual title/content being English - it was
     * manually added to the data file after being found and verified by hand. A pure
     * tag-based filter would silently drop it again on every future sync. So: any item
     * already present in the existing file that the automated filter excludes THIS time is
     * preserved as-is, as long as the underlying post still exists in the live fetch at all
     * (i.e. it wasn't unpublished/deleted - only the language-tag exclusion is overridden).
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

        // Every still-live release, regardless of language tag, keyed by the id we'd give it -
        // used below to tell "still published but excluded by the language filter" apart from
        // "no longer exists on the site at all".
        $raw_ids = [];
        foreach ($releases as $r) {
            $raw_ids['mfn-' . $this->slug_from_link($r['link'] ?? '')] = true;
        }

        $items = [];
        $included_ids = [];
        foreach ($releases as $r) {
            $classes = $r['class_list'] ?? [];
            $is_en = in_array('mfn-news-tag-mfn-lang-en', $classes, true);
            $is_other_lang = array_intersect(['mfn-news-tag-mfn-lang-sv', 'mfn-news-tag-mfn-lang-fi', 'mfn-news-tag-mfn-lang-da', 'mfn-news-tag-mfn-lang-no'], $classes);
            if (!$is_en && !empty($is_other_lang)) {
                continue;
            }
            $category = 'Press Release';
            foreach ($category_map as $tag => $label) {
                if (in_array($tag, $classes, true)) {
                    $category = $label;
                    break;
                }
            }
            $id = 'mfn-' . $this->slug_from_link($r['link'] ?? '');
            $items[] = [
                'id'       => $id,
                'title'    => $this->clean_html($r['title']['rendered'] ?? ''),
                'date'     => substr($r['date'] ?? '', 0, 10),
                'category' => $category,
                'url'      => $r['link'] ?? '',
            ];
            $included_ids[$id] = true;
        }

        $existing = $this->load_existing('press-releases.json');
        $manually_preserved = [];
        if ($existing && !empty($existing['releases'])) {
            foreach ($existing['releases'] as $existing_item) {
                $id = $existing_item['id'] ?? null;
                if ($id && !isset($included_ids[$id]) && isset($raw_ids[$id])) {
                    // Still live on the site, just excluded by the language filter this time -
                    // a human previously decided to keep it. Preserve, don't re-drop it.
                    $items[] = $existing_item;
                    $manually_preserved[] = $id;
                }
            }
        }

        usort($items, function ($a, $b) { return strcmp($b['date'], $a['date']); });

        $ok = $this->write_json('press-releases.json', [
            'releases' => $items,
            'total'    => count($items),
        ]);
        if (!$ok) {
            return "FAILED to write press-releases.json: check that " . $this->data_dir() . " is writable by the web server. Existing file left untouched.";
        }
        $note = empty($manually_preserved) ? '' : (' Preserved ' . count($manually_preserved) . ' previously-recovered non-English-tagged release(s): ' . implode(', ', $manually_preserved) . '.');
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
