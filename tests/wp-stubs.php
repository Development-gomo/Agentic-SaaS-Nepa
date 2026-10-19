<?php
/**
 * Minimal in-memory stand-ins for the WordPress functions and $wpdb methods used by
 * nepa-api.php and class-sync.php. Enough to exercise the plugin's own logic without a
 * WordPress install - NOT a replacement for testing on a real WordPress staging site.
 *
 * Test state lives in $GLOBALS['T']; failure-injection switches:
 *   T['insert_fail']   wp_insert_post() returns a WP_Error
 *   T['mail_ok']       return value of wp_mail()
 *   T['meta_fail'][k]  update_post_meta() silently fails for meta key k
 */
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);

$GLOBALS['T'] = [
    'transients' => [], 'posts' => [], 'meta' => [], 'mail' => [], 'rules' => [], 'flushed_rules' => null,
    'actions' => [], 'filters' => [], 'qv' => [], 'status' => null, 'headers' => [], 'cron' => [],
    'mail_ok' => true, 'insert_fail' => false, 'meta_fail' => [],
    'uploads' => sys_get_temp_dir() . '/nepa-tests-uploads-' . getmypid(),
    'cpt_posts' => [], 'next_id' => 100,
    'ctx' => 'main', 'meta_cache' => [],
];

class WP_Error {
    private $message;
    public function __construct($code = '', $message = '') { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}

/**
 * In-memory wp_options table with a unique option_name, like the real one.
 * Failure / race injection:
 *   $wpdb->insert_fails = true        INSERT fails without a row existing (database error)
 *   $wpdb->stale_reads[name] = [raw]  the next get_var() for name returns raw (one-shot) -
 *                                     replays "this request read the value before another
 *                                     request changed it"
 *   $wpdb->stale_results = [rows]     the next get_results() returns these rows (one-shot)
 */
class Fake_WPDB {
    public $options = 'wp_options';
    public $rows = [];
    public $insert_fails = false;
    public $stale_reads = [];
    public $stale_results = null;
    public function suppress_errors($s = true) { return false; }
    private function matches($where) {
        $name = $where['option_name'];
        if (!isset($this->rows[$name])) return false;
        return !isset($where['option_value']) || $this->rows[$name] === $where['option_value'];
    }
    public function insert($table, $data) {
        // Race replay: T['before_option_insert'] = [name prefix, callable] (one-shot), e.g. run a worker just before a lock is taken.
        if (($h = $GLOBALS['T']['before_option_insert'] ?? null) && strpos($data['option_name'], $h[0]) === 0) { $GLOBALS['T']['before_option_insert'] = null; ($h[1])(); }
        if ($this->insert_fails || isset($this->rows[$data['option_name']])) {
            return false; // database error, or duplicate key as MySQL would report
        }
        $this->rows[$data['option_name']] = $data['option_value'];
        return 1;
    }
    public function update($table, $data, $where) {
        if (!$this->matches($where)) return 0;
        $this->rows[$where['option_name']] = $data['option_value'];
        return 1;
    }
    public function delete($table, $where) {
        if (!$this->matches($where)) return 0;
        unset($this->rows[$where['option_name']]);
        return 1;
    }
    public function prepare($sql, ...$args) { return ['sql' => $sql, 'args' => $args]; }
    public function esc_like($s) { return $s; }
    public function get_var($q) {
        $name = $q['args'][0];
        if (!empty($this->stale_reads[$name])) {
            return array_shift($this->stale_reads[$name]);
        }
        return $this->rows[$name] ?? null;
    }
    public function get_results($q) {
        if ($this->stale_results !== null) {
            $out = $this->stale_results;
            $this->stale_results = null;
            return $out;
        }
        $prefix = rtrim($q['args'][0], '%');
        $out = [];
        foreach ($this->rows as $name => $value) {
            if (strpos($name, $prefix) === 0) {
                $out[] = (object) ['option_name' => $name, 'option_value' => $value];
            }
        }
        return $out;
    }
}
$GLOBALS['wpdb'] = new Fake_WPDB();

function is_wp_error($x) { return $x instanceof WP_Error; }
function plugin_dir_path($f) { return dirname($f) . '/'; }
function trailingslashit($s) { return rtrim($s, '/') . '/'; }
function wp_upload_dir($a = null, $b = true) { return ['basedir' => $GLOBALS['T']['uploads']]; }
function wp_mkdir_p($d) { return is_dir($d) || mkdir($d, 0777, true); }
function wp_is_writable($p) { return is_writable($p); }
function add_action($h, $cb, $p = 10, $a = 1) { $GLOBALS['T']['actions'][$h][] = $cb; }
function add_filter($h, $cb, $p = 10, $a = 1) { $GLOBALS['T']['filters'][$h][] = $cb; }
function add_management_page(...$a) {}
function register_activation_hook($f, $cb) { $GLOBALS['T']['activate'] = $cb; }
function register_deactivation_hook($f, $cb) {}
function add_rewrite_rule($re, $q, $pos) { $GLOBALS['T']['rules'][$re] = $q; }
function flush_rewrite_rules() { $GLOBALS['T']['flushed_rules'] = $GLOBALS['T']['rules']; }
function register_post_type($t, $a) {}
function get_role($r) { return null; }
function get_query_var($k) { return $GLOBALS['T']['qv'][$k] ?? ''; }
function status_header($c) { $GLOBALS['T']['status'] = $c; }
function sanitize_text_field($s) { return is_string($s) ? trim(strip_tags($s)) : ''; }
function sanitize_textarea_field($s) { return is_string($s) ? trim(strip_tags($s)) : ''; }
function sanitize_email($s) { return is_string($s) ? trim($s) : ''; }
function is_email($s) { return (bool) filter_var($s, FILTER_VALIDATE_EMAIL); }
/** Like WordPress: strip/add slashes recursively (WordPress unslashes values passed to update_post_meta() and wp_insert_post()). */
function wp_unslash($s) { return is_array($s) ? array_map('wp_unslash', $s) : (is_string($s) ? stripslashes($s) : $s); }
function wp_slash($s) { return is_array($s) ? array_map('wp_slash', $s) : (is_string($s) ? addslashes($s) : $s); }
function wp_json_encode($d, $f = 0) { return json_encode($d, $f); }
function wp_using_ext_object_cache() { return false; }
function get_transient($k) { return $GLOBALS['T']['transients'][$k] ?? false; }
function set_transient($k, $v, $ttl) { $GLOBALS['T']['transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['T']['transients'][$k]); }
function wp_salt($s) { return 'salt'; }
function current_time($t) { return gmdate('Y-m-d H:i:s'); }
function home_url($p = '') { return 'https://nepa.com' . $p; }
function wp_generate_password($n = 8, $a = false, $b = false) { return substr(md5((string) mt_rand()), 0, $n); }
function wp_next_scheduled($h) { return $GLOBALS['T']['cron'][$h] ?? false; }
function wp_schedule_event($t, $r, $h) { $GLOBALS['T']['cron'][$h] = $r; }
function wp_clear_scheduled_hook($h) { unset($GLOBALS['T']['cron'][$h]); }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function current_user_can($c, ...$args) { return $GLOBALS['T']['can'] ?? true; }
function esc_html($s) { return htmlspecialchars($s); }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
/**
 * Outbound HTTP. Every call is recorded in T['http']; T['http_handler'] (callable taking
 * method, url, args) supplies the response - [status, body, headers] or a WP_Error. With no
 * handler, calls fail like an offline server.
 */
function nepa_test_http($method, $url, $args) {
    $GLOBALS['T']['http'][] = ['method' => $method, 'url' => $url, 'args' => $args];
    $handler = $GLOBALS['T']['http_handler'] ?? null;
    if (!$handler) return new WP_Error('offline', 'no network in tests');
    $r = $handler($method, $url, $args);
    if ($r instanceof WP_Error) return $r;
    return ['response' => ['code' => $r[0]], 'body' => is_string($r[1]) ? $r[1] : json_encode($r[1]), 'headers' => array_change_key_case($r[2] ?? [], CASE_LOWER)];
}
function wp_remote_get($u, $a = []) { return nepa_test_http('GET', $u, $a); }
function wp_remote_post($u, $a = []) { return nepa_test_http('POST', $u, $a); }
function wp_remote_retrieve_response_code($r) { return is_array($r) ? ($r['response']['code'] ?? '') : ''; }
function wp_remote_retrieve_body($r) { return is_array($r) ? ($r['body'] ?? '') : ''; }
function wp_remote_retrieve_header($r, $h) { return is_array($r) ? ($r['headers'][strtolower($h)] ?? '') : ''; }
// Admin-screen helpers.
class Nepa_Test_Die extends Exception {}
function add_meta_box(...$a) {}
function admin_url($p = '') { return 'https://nepa.test/wp-admin/' . $p; }
function wp_nonce_url($url, $action) { return $url . '&_wpnonce=' . $action; }
function check_admin_referer($action) { if (($_GET['_wpnonce'] ?? '') !== $action) throw new Nepa_Test_Die('bad nonce'); return true; }
function wp_die($m = '', $code = 500) { throw new Nepa_Test_Die((string) $code); }
function wp_safe_redirect($u) { $GLOBALS['T']['redirect'] = $u; return true; }
function wp_get_current_user() { return (object) ['ID' => 7]; }
function esc_url($u) { return htmlspecialchars($u); }

function wp_mail($to, $subject, $message, $headers = []) {
    $GLOBALS['T']['mail'][] = [$to, $subject];
    return $GLOBALS['T']['mail_ok'];
}
function wp_insert_post($args, $wp_error = false) {
    if ($GLOBALS['T']['insert_fail']) {
        return new WP_Error('db', 'database unavailable');
    }
    $id = $GLOBALS['T']['next_id']++;
    // Like WordPress, the post fields are expected slashed and are unslashed before saving.
    foreach (['post_title', 'post_content', 'post_content_filtered'] as $field) {
        if (isset($args[$field])) $args[$field] = wp_unslash($args[$field]);
    }
    $GLOBALS['T']['posts'][$id] = $args + ['ID' => $id, 'post_date_gmt' => time(), 'post_content_filtered' => ''];
    // Race replay: T['after_insert'] (one-shot) runs right after the row exists, e.g. a worker.
    if ($hook = $GLOBALS['T']['after_insert'] ?? null) { $GLOBALS['T']['after_insert'] = null; $hook($id); }
    return $id;
}
function get_post($id) { return isset($GLOBALS['T']['posts'][$id]) ? (object) $GLOBALS['T']['posts'][$id] : null; }
function wp_delete_post($id, $force = false) { unset($GLOBALS['T']['posts'][$id], $GLOBALS['T']['meta'][$id]); return true; }
function update_post_meta($id, $k, $v) {
    // Race replay: T['before_meta_write'] = [key, callable] (one-shot) runs just before that key is written.
    if (($w = $GLOBALS['T']['before_meta_write'] ?? null) && $w[0] === $k) { $GLOBALS['T']['before_meta_write'] = null; ($w[1])($id); }
    if (!empty($GLOBALS['T']['meta_fail'][$k])) {
        return false;
    }
    if (isset($GLOBALS['T']['meta_fail_value'][$k]) && $GLOBALS['T']['meta_fail_value'][$k] === $v) {
        return false; // this particular value can't be written (e.g. 'delivered' after HubSpot accepted)
    }
    $GLOBALS['T']['meta'][$id][$k] = wp_unslash($v); // WordPress unslashes meta values before saving
    unset($GLOBALS['T']['meta_cache'][$GLOBALS['T']['ctx']][$id]); // like update_metadata(): clears this request's cache only
    return true;
}
/**
 * Post meta goes through a per-request cache, like WordPress: the first read in a request
 * loads all of the post's meta into that request's cache, later reads come from the cache,
 * and only a write (or wp_cache_delete) in the SAME request clears it. Another request's
 * writes are not seen until then. T['ctx'] names the current request; nepa_test_request()
 * runs code as a different request.
 */
function get_post_meta($id, $k, $single = true) {
    $ctx = $GLOBALS['T']['ctx'];
    if (!isset($GLOBALS['T']['meta_cache'][$ctx]) || !array_key_exists($id, $GLOBALS['T']['meta_cache'][$ctx])) {
        $GLOBALS['T']['meta_cache'][$ctx][$id] = $GLOBALS['T']['meta'][$id] ?? [];
    }
    return $GLOBALS['T']['meta_cache'][$ctx][$id][$k] ?? '';
}
function wp_cache_delete($key, $group = '') {
    if ($group === 'post_meta') unset($GLOBALS['T']['meta_cache'][$GLOBALS['T']['ctx']][$key]);
    return true;
}
function nepa_test_request($ctx, callable $fn) {
    $previous = $GLOBALS['T']['ctx'];
    $GLOBALS['T']['ctx'] = $ctx;
    try { return $fn(); } finally { $GLOBALS['T']['ctx'] = $previous; }
}

/** get_posts() for nepa_lead: supports post_status (incl. arrays/trash), meta_key/value, meta_query, date_query, order, limit. */
function get_posts($q) {
    if (($q['post_type'] ?? '') !== 'nepa_lead') {
        return $GLOBALS['T']['cpt_posts'][$q['post_type']] ?? [];
    }
    $statuses = (array) ($q['post_status'] ?? 'publish');
    $out = [];
    foreach ($GLOBALS['T']['posts'] as $id => $p) {
        $status = $p['post_status'] ?? 'publish';
        if ($statuses !== ['any'] && !in_array($status, $statuses, true)) continue;
        if ($statuses === ['any'] && $status === 'trash') continue;
        if (isset($q['meta_key']) && (string) get_post_meta($id, $q['meta_key']) !== (string) $q['meta_value']) continue;
        $ok = !isset($q['meta_query']) || meta_query_matches($id, $q['meta_query']);
        foreach ((array) ($q['date_query'] ?? []) as $d) {
            if (isset($d['before']) && !($p['post_date_gmt'] < strtotime($d['before']))) $ok = false;
            if (isset($d['after']) && !($p['post_date_gmt'] > strtotime($d['after']))) $ok = false;
        }
        if ($ok) $out[$id] = $p['post_date_gmt'];
    }
    ($q['order'] ?? 'DESC') === 'ASC' ? asort($out) : arsort($out);
    $ids = array_keys($out);
    $limit = $q['posts_per_page'] ?? -1;
    return $limit > 0 ? array_slice($ids, 0, $limit) : $ids;
}
/** Evaluates a WP meta_query (nested AND/OR; =, !=, <, IN, EXISTS, NOT EXISTS). */
function meta_query_matches($id, $query) {
    $relation = strtoupper($query['relation'] ?? 'AND');
    $results = [];
    foreach ($query as $k => $clause) {
        if ($k === 'relation' || !is_array($clause)) continue;
        if (!isset($clause['key'])) { $results[] = meta_query_matches($id, $clause); continue; }
        $exists = array_key_exists($clause['key'], $GLOBALS['T']['meta'][$id] ?? []);
        $have = $GLOBALS['T']['meta'][$id][$clause['key']] ?? null;
        $cmp = $clause['compare'] ?? '=';
        switch ($cmp) {
            case 'NOT EXISTS': $results[] = !$exists; break;
            case 'EXISTS': $results[] = $exists; break;
            case '!=': $results[] = $exists && (string) $have !== (string) $clause['value']; break;
            case '<': $results[] = $exists && (int) $have < (int) $clause['value']; break;
            case '<=': $results[] = $exists && (int) $have <= (int) $clause['value']; break;
            case 'IN': $results[] = $exists && in_array((string) $have, array_map('strval', (array) $clause['value']), true); break;
            default: $results[] = $exists && (string) $have === (string) $clause['value'];
        }
    }
    return $relation === 'OR' ? in_array(true, $results, true) : !in_array(false, $results, true);
}
function post_type_exists($t) { return isset($GLOBALS['T']['cpt_posts'][$t]); }
function get_the_title($p) { return $p->post_title; }
function get_permalink($p) { return $p->link; }
function get_the_date($f, $p) { return $p->date ?? '2026-01-01'; }
function wp_strip_all_tags($s) { return trim(strip_tags($s)); }
function wp_trim_words($s, $n) { return implode(' ', array_slice(explode(' ', $s), 0, $n)); }
function header_stub_reset() { $GLOBALS['T']['headers'] = []; }
