<?php
/**
 * Runs ONE request through Nepa_Agent_API::handle_request() in its own process (handle_request()
 * ends with exit) and prints {"status": ..., "body": ...} as JSON. Used by run-plugin-tests.php.
 *
 *   php tests/plugin-route-probe.php <endpoint> <GET|POST> <contact_enabled: 1|0> [json-body]
 *
 * Loads the package's own nepa-api.php; the only paths exercised (index and a refused or validated
 * contact POST) don't write data files. Leads, if any, go to the in-memory stand-ins.
 */
require __DIR__ . '/wp-stubs.php';
[, $endpoint, $method, $enabled] = $argv + [null, 'index', 'GET', '1'];
$GLOBALS['__body'] = $argv[4] ?? '';
if ($enabled === '0') {
    define('NEPA_CONTACT_ENABLED', false);
}
require dirname(__DIR__) . '/nepa-api/nepa-api.php';
$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['CONTENT_LENGTH'] = strlen($GLOBALS['__body']);
$GLOBALS['T']['qv']['nepa_endpoint'] = $endpoint;
ob_start();
register_shutdown_function(function () {
    $body = ob_get_clean();
    echo json_encode(['status' => $GLOBALS['T']['status'], 'body' => json_decode((string) $body, true), 'leads' => count($GLOBALS['T']['posts']), 'mail' => count($GLOBALS['T']['mail']),
        'counters' => count($GLOBALS['T']['transients']) + count($GLOBALS['wpdb']->rows ?? []), 'http' => count($GLOBALS['T']['http'] ?? [])]);
});
$GLOBALS['nepa_agent_api']->handle_request();
