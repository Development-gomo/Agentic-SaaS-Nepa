<?php
/**
 * Minimal WordPress stand-ins for the two must-use plugins agentic-resource-discovery.php and
 * nepa-agent-discovery.php. Used by run-ard-tests.php (in-process) and ard-router.php (served by
 * `php -S`, so real HTTP status codes and headers can be checked). Not a WordPress replacement.
 */
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
// 'lang' imitates a multilingual plugin (Polylang/WPML): home_url() then returns the language-prefixed
// URL (https://nepa.com/sv), while the `home` option keeps the site root - as on nepa.com's /sv/ pages.
$GLOBALS['ARD_T'] = ['actions' => [], 'filters' => [], 'home' => getenv('ARD_TEST_HOME') ?: 'https://nepa.com', 'lang' => getenv('ARD_TEST_LANG_PREFIX') ?: ''];

function add_action($h, $cb, $p = 10, $a = 1) { $GLOBALS['ARD_T']['actions'][$h][$p][] = $cb; }
function add_filter($h, $cb, $p = 10, $a = 1) { $GLOBALS['ARD_T']['filters'][$h][$p][] = [$cb, $a]; }
function remove_all_filters($h) { unset($GLOBALS['ARD_T']['filters'][$h]); }
function do_action($h, ...$args) {
    $byp = $GLOBALS['ARD_T']['actions'][$h] ?? [];
    ksort($byp);
    foreach ($byp as $cbs) { foreach ($cbs as $cb) { $cb(...$args); } }
}
function apply_filters($h, $value, ...$args) {
    $byp = $GLOBALS['ARD_T']['filters'][$h] ?? [];
    ksort($byp);
    foreach ($byp as $cbs) {
        foreach ($cbs as [$cb, $n]) { $value = $cb(...array_slice(array_merge([$value], $args), 0, max(1, $n))); }
    }
    return $value;
}
function home_url($p = '') { return rtrim($GLOBALS['ARD_T']['home'], '/') . $GLOBALS['ARD_T']['lang'] . ($p === '' ? '' : '/' . ltrim($p, '/')); }
function get_option($k, $d = false) { return $k === 'home' ? rtrim($GLOBALS['ARD_T']['home'], '/') : $d; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function wp_json_encode($d, $o = 0) { return json_encode($d, $o); }
function status_header($c) { http_response_code($c); }
function esc_url($u) { return htmlspecialchars($u, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function trailingslashit($s) { return rtrim((string) $s, '/\\') . '/'; }
function is_admin() { return false; }
function is_singular() { return !empty($GLOBALS['ARD_T']['singular']); }
function get_permalink() { return home_url('/'); }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
