<?php
/**
 * Router for `php -S 127.0.0.1:<port> tests/md-hook-probe.php`, used by run-markdown-tests.php.
 * Runs the ?format=md template_include hook of nepa-agent-discovery.php against
 * tests/fixtures/enfold-page.php as the page template, so real status codes and headers can be
 * checked. Each response carries X-Test-Renders (how often the template was rendered).
 *   /?format=md          - cold cache: renders the page and converts it
 *   /?format=md&warm=1   - cache pre-filled: the cached Markdown must be served without rendering
 *   /?format=html        - not a Markdown request: the hook must leave the template alone
 */
require __DIR__ . '/ard-stubs.php';
class WP_Post { public $ID = 11994; public $post_status = 'publish'; public $post_modified_gmt = '2026-08-13 05:22:54'; }
$GLOBALS['md_t'] = ['transients' => [], 'renders' => 0];
$GLOBALS['ARD_T']['singular'] = true;
function get_queried_object() { return new WP_Post(); }
function post_password_required($p) { return false; }
function get_transient($k) { return $GLOBALS['md_t']['transients'][$k] ?? false; }
function set_transient($k, $v, $t) { $GLOBALS['md_t']['transients'][$k] = $v; return true; }
function get_the_title($p) { return 'Brand Tracking'; }
function get_post_meta($id, $k, $s) { return 'Continuous brand tracking from Nepa.'; }
function get_the_modified_date($f, $p) { return '2026-08-13T05:22:54+00:00'; }
function wp_strip_all_tags($s) { return trim(strip_tags($s)); }
function load_template($file, $once) { $GLOBALS['md_t']['renders']++; header('X-Test-Renders: ' . $GLOBALS['md_t']['renders']); require $file; }
header('X-Test-Renders: 0');
require dirname(__DIR__) . '/agentic-resource-discovery.php';
require dirname(__DIR__) . '/nepa-agent-discovery.php';
if (!empty($_GET['warm'])) {
    $GLOBALS['md_t']['transients']['nepa_md_v2_11994_' . md5('2026-08-13 05:22:54' . NEPA_AD_VERSION)] = "CACHED MARKDOWN\n";
}
$template = apply_filters('template_include', __DIR__ . '/fixtures/enfold-page.php');
echo 'TEMPLATE LEFT ALONE: ' . basename($template);
return true;
