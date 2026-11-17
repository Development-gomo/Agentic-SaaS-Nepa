<?php
/**
 * Router for `php -S 127.0.0.1:<port> tests/ard-router.php`, used by run-ard-tests.php.
 * Loads the stubs and both must-use plugins the way WordPress would, then:
 *   - runs the `init` hooks (the plugins answer /.well-known/* there and exit);
 *   - for "/" (and "/sv/", used with ARD_TEST_LANG_PREFIX) renders a front-end page: `send_headers`, then <head> with the `wp_head` hooks;
 *   - otherwise answers 404 like WordPress.
 * ARD_TEST_NO_ENTRIES=1 loads only the generic module, to check it stays silent without entries.
 */
require __DIR__ . '/ard-stubs.php';
$root = dirname(__DIR__);
require $root . '/agentic-resource-discovery.php';
if (!getenv('ARD_TEST_NO_ENTRIES')) {
    require $root . '/nepa-agent-discovery.php';
}
do_action('init');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/' || $path === '/sv/') {
    do_action('send_headers');
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head>\n";
    do_action('wp_head');
    echo "</head><body>front page</body></html>\n";
    return true;
}
http_response_code(404);
echo 'not found';
return true;
