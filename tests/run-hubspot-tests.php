<?php
/**
 * HubSpot delivery tests (WordPress and HubSpot both mocked - see wp-stubs.php).
 *
 * Usage (from the package root), once per mode:
 *   php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_WARNING' tests/run-hubspot-tests.php token
 *   php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_WARNING' tests/run-hubspot-tests.php notoken
 *
 * "token" = NEPA_HUBSPOT_TOKEN set (authenticated submit + submission lookup);
 * "notoken" = unauthenticated submit, uncertain outcomes go to manual review.
 * HubSpot is simulated by a handler that records every request and answers as the
 * documented API does (200 / 400 with errorType / 401 / 429 with rate-limit headers /
 * 5xx / timeout). Runs against a temporary copy of nepa-api/.
 */
$mode = $argv[1] ?? 'token';
if (!in_array($mode, ['token', 'notoken'], true)) { fwrite(STDERR, "mode must be token or notoken\n"); exit(2); }
define('TEST_TOKEN', 'pat-eu1-00000000-test-token-do-not-log');
define('NEPA_HUBSPOT_ENABLED', true);
define('NEPA_HUBSPOT_TOKEN', $mode === 'token' ? TEST_TOKEN : '');
require __DIR__ . '/wp-stubs.php';

$package = dirname(__DIR__);
$copy = sys_get_temp_dir() . '/nepa-hs-tests-' . getmypid();
exec('cp -R ' . escapeshellarg($package . '/nepa-api') . ' ' . escapeshellarg($copy));
$copy = realpath($copy);
register_shutdown_function(function () use ($copy) { exec('rm -rf ' . escapeshellarg($copy) . ' ' . escapeshellarg($GLOBALS['T']['uploads'])); });
require $copy . '/nepa-api.php';
$api = $GLOBALS['nepa_agent_api'];
$hs = $api->hubspot;

$pass = 0; $fail = 0;
function check($label, $cond, $detail = '') { global $pass, $fail; if ($cond) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label  $detail\n"; } }
function invoke($o, $m, ...$a) { $r = new ReflectionMethod($o, $m); ob_start(); $ret = $r->invoke($o, ...$a); return [$ret, ob_get_clean()]; }
class Fake_Input_Stream {
    public $context; private $pos = 0;
    public function stream_open($p, $m, $o, &$op) { $this->pos = 0; return true; }
    public function stream_read($n) { $d = substr($GLOBALS['__body'], $this->pos, $n); $this->pos += strlen($d); return $d; }
    public function stream_eof() { return $this->pos >= strlen($GLOBALS['__body']); }
    public function stream_stat() { return []; }
}
stream_wrapper_unregister('php'); stream_wrapper_register('php', 'Fake_Input_Stream');
function post($body) {
    global $api;
    $GLOBALS['__body'] = json_encode($body);
    $_SERVER['CONTENT_LENGTH'] = strlen($GLOBALS['__body']);
    $GLOBALS['T']['status'] = null;
    $GLOBALS['T']['transients'] = []; // rate limits are covered by the plugin suite
    [, $out] = invoke($api, 'contact_submission');
    if ($dump = getenv('NEPA_DUMP_RESPONSES')) { // optional: record real responses for tests/validate-openapi-examples.py
        file_put_contents($dump, json_encode(['code' => $GLOBALS['T']['status'], 'body' => json_decode($out, true)]) . "\n", FILE_APPEND);
    }
    return [$GLOBALS['T']['status'], json_decode($out, true)];
}
function with($b, $s, $v) { $b[$s] = array_merge($b[$s] ?? [], $v); return $b; }
function lead() { return array_key_last($GLOBALS['T']['posts']); }
/** Assertions read the database itself, not the test request's metadata cache. */
function meta($id, $k) { return (string) ($GLOBALS['T']['meta'][$id][$k] ?? ''); }
function posts_to_hubspot() { return count(array_filter($GLOBALS['T']['http'], function ($c) { return $c['method'] === 'POST' && strpos($c['url'], '/submissions/v3/integration/') !== false; })); }
function last_payload() { foreach (array_reverse($GLOBALS['T']['http']) as $c) { if ($c['method'] === 'POST') return json_decode($c['args']['body'], true); } return null; }
function posts_for($email) { return count(array_filter($GLOBALS['T']['http'], function ($c) use ($email) { return $c['method'] === 'POST' && strpos((string) ($c['args']['body'] ?? ''), json_encode($email)) !== false; })); }
function hubspot($handler) { $GLOBALS['T']['http_handler'] = $handler; }
function respond($code, $body = [], $headers = []) { return function () use ($code, $body, $headers) { return [$code, $body, $headers]; }; }
function backdate($id, $key, $seconds) { update_post_meta($id, $key, (string) (time() - $seconds)); }
$n = 0;
function person($tag) { global $n; $n++; return ['first_name' => 'Ada', 'last_name' => 'Test', 'email' => "{$tag}{$n}@acme-example.com", 'phone' => '+46 70 123 45 67']; }

$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$good = [
    'agent_context' => ['user_authorized_submission' => true, 'via' => 'mcp'],
    'consent' => ['process' => true],
    'company' => ['name' => 'Acme AB'],
    'inquiry' => ['service_interest' => 'Brand Tracking', 'message' => 'We want to track our brand in the Nordics.', 'language' => 'Swedish'],
];
$submit_url = 'https://api.hsforms.com/submissions/v3/integration/' . ($mode === 'token' ? 'secure/submit/' : 'submit/') . '146131568/7c631e31-cc3b-44ba-a580-113ab3489db6';

echo "== Mapping and successful submission ($mode)\n";
hubspot(respond(200, ['inlineMessage' => 'Thanks']));
[$s, $r] = post(array_merge($good, ['person' => person('ok')]));
$id = lead(); $p = last_payload(); $call = end($GLOBALS['T']['http']);
check('response: 200, received, HubSpot status delivered', $s === 200 && $r['status'] === 'received' && $r['hubspot']['status'] === 'delivered');
check('posted to the documented ' . ($mode === 'token' ? 'authenticated' : 'unauthenticated') . ' submit URL for the English form', $call['url'] === $submit_url);
check($mode === 'token' ? 'Authorization: Bearer token sent' : 'no Authorization header without a token',
    $mode === 'token' ? ($call['args']['headers']['Authorization'] ?? '') === 'Bearer ' . TEST_TOKEN : !isset($call['args']['headers']['Authorization']));
$fields = []; foreach ($p['fields'] as $f) { $fields[$f['objectTypeId'] . '/' . $f['name']] = $f['value']; }
check('exact form field names and object types (0-1 contact, 0-2 company)', array_keys($fields) === ['0-1/firstname', '0-1/lastname', '0-1/email', '0-2/name', '0-1/phone', '0-1/message']);
check('field values mapped from the inquiry', $fields['0-1/firstname'] === 'Ada' && $fields['0-1/lastname'] === 'Test' && $fields['0-2/name'] === 'Acme AB' && $fields['0-1/phone'] === '+46 70 123 45 67');
$msg = $fields['0-1/message'];
check('message: original text, then service interest, language, MCP origin and lead ID', strpos($msg, 'We want to track our brand in the Nordics.') === 0 && strpos($msg, 'Service interest: Brand Tracking') && strpos($msg, 'Preferred language: Swedish') && strpos($msg, 'Submitted via MCP') && strpos($msg, (string) meta($id, 'lead_id')));
check('submittedAt is the fixed value stored on the lead', $p['submittedAt'] === meta($id, 'hubspot_submitted_at_ms') && ctype_digit($p['submittedAt']));
check('no fabricated context: no hutk, IP address or page', !isset($p['context']));
$c = $p['legalConsentOptions']['consent'];
check('processing consent: true with the form\'s exact text', $c['consentToProcess'] === true && $c['text'] === 'I agree to allow Nepa to store and process my personal data.');
check('marketing: NOT opted in by default (value false, subscription 789062182, form label)', $c['communications'] === [['value' => false, 'subscriptionTypeId' => 789062182, 'text' => "I have read and understood Nepa's Privacy Policy"]]);
check('lead records delivered state, attempt count and the consent texts shown', meta($id, 'hubspot_status') === 'delivered' && meta($id, 'hubspot_attempts') === '1' && meta($id, 'marketing_opt_in') === '0' && meta($id, 'consent_process_text') !== '');
check('one notification email, sent after the HubSpot attempt and stating its result', count($GLOBALS['T']['mail']) === 1);

$no_last = post(array_merge($good, ['person' => ['first_name' => 'Solo', 'email' => 'solo@acme-example.com', 'phone' => '+358 40 123 4567']]));
$names = array_column(last_payload()['fields'], 'name');
check('last name omitted from the submission when not given (it is optional on the form)', !in_array('lastname', $names, true) && $no_last[1]['hubspot']['status'] === 'delivered');
post(array_merge($good, ['person' => person('opt'), 'consent' => ['process' => true, 'marketing_opt_in' => true]]));
check('marketing opt-in only when explicitly true', last_payload()['legalConsentOptions']['consent']['communications'][0]['value'] === true);

echo "== Rejected by HubSpot (data problems)\n";
$before = posts_to_hubspot();
hubspot(respond(400, ['errors' => [['errorType' => 'BLOCKED_EMAIL', 'message' => 'Email blocked: someone@gmail.com is a free email address']]]));
[$s, $r] = post(array_merge($good, ['person' => person('blocked')]));
$id = lead();
check('BLOCKED_EMAIL -> 422 rejected with the error type, lead kept locally', $s === 422 && $r['status'] === 'rejected' && $r['hubspot']['errors'] === ['BLOCKED_EMAIL'] && meta($id, 'hubspot_status') === 'rejected');
check('sanitized log: no email address from HubSpot\'s error text is stored', strpos((string) meta($id, 'hubspot_log'), '@') === false);
$hs->run_worker();
check('rejected leads are not retried by the worker', posts_to_hubspot() === $before + 1);
hubspot(respond(400, ['errors' => [['errorType' => 'VALUE_NOT_IN_FIELD_DEFINITION', 'message' => 'Value not allowed']]]));
[$s, $r] = post(array_merge($good, ['person' => person('dropdown')]));
check('invalid option value (VALUE_NOT_IN_FIELD_DEFINITION) -> rejected, not retried', $s === 422 && $r['hubspot']['errors'] === ['VALUE_NOT_IN_FIELD_DEFINITION']);
hubspot(respond(400, ['errors' => [['errorType' => 'REQUIRED_FIELD', 'message' => 'missing']]]));
[$s, $r] = post(array_merge($good, ['person' => person('req')]));
check('missing required field reported by HubSpot (REQUIRED_FIELD) -> rejected', $s === 422 && $r['hubspot']['errors'] === ['REQUIRED_FIELD']);

echo "== Configuration and credential failures (need a person)\n";
$mails = count($GLOBALS['T']['mail']);
hubspot(respond(401, ['message' => 'Authentication credentials not found']));
[$s, $r] = post(array_merge($good, ['person' => person('auth')]));
$id = lead();
check('401 -> saved (200) with status manual_review, not reported as delivered', $s === 200 && $r['hubspot']['status'] === 'manual_review' && meta($id, 'hubspot_review_reason') === 'credentials_or_form_id');
check('an attention email goes to Nepa (plus the normal notification)', count($GLOBALS['T']['mail']) === $mails + 2);
$before = posts_to_hubspot(); $hs->run_worker();
check('manual_review leads are not retried automatically', posts_to_hubspot() === $before);
hubspot(respond(400, ['errors' => [['errorType' => 'FIELD_NOT_IN_FORM_DEFINITION', 'message' => 'x']]]));
[, $r] = post(array_merge($good, ['person' => person('cfg')]));
check('form/field mismatch (FIELD_NOT_IN_FORM_DEFINITION) -> manual_review, not "rejected"', $r['hubspot']['status'] === 'manual_review');
hubspot(respond(400, ['errors' => [['errorType' => 'MISSING_PROCESSING_CONSENT_TEXT', 'message' => 'x']]]));
[, $r] = post(array_merge($good, ['person' => person('consentcfg')]));
check('consent configuration error -> manual_review', $r['hubspot']['status'] === 'manual_review');

echo "== Temporary failures, backoff and retry guidance\n";
hubspot(respond(429, ['errorType' => 'RATE_LIMIT', 'policyName' => 'SECONDLY'], ['X-HubSpot-RateLimit-Remaining' => '0', 'X-HubSpot-RateLimit-Interval-Milliseconds' => '10000']));
[$s, $r] = post(array_merge($good, ['person' => person('rate')]));
$id = lead(); $next = (int) meta($id, 'hubspot_next_attempt_at');
check('429 -> saved, retryable, next attempt after the rate-limit window (10 s)', $r['hubspot']['status'] === 'retryable' && $next >= time() + 9 && $next <= time() + 11);
$before = posts_to_hubspot(); $hs->run_worker();
check('not retried before the scheduled time', posts_to_hubspot() === $before);
backdate($id, 'hubspot_next_attempt_at', 1);
hubspot(respond(200, []));
$hs->run_worker();
check('retried when due -> delivered on the second attempt', meta($id, 'hubspot_status') === 'delivered' && meta($id, 'hubspot_attempts') === '2');
hubspot(respond(503, ['message' => 'Service unavailable']));
[, $r] = post(array_merge($good, ['person' => person('503')]));
$id = lead(); $wait = (int) meta($id, 'hubspot_next_attempt_at') - time();
check('503 -> retryable with exponential backoff (first wait 60-72 s)', $r['hubspot']['status'] === 'retryable' && $wait >= 59 && $wait <= 73);
hubspot(function () { return new WP_Error('http_request_failed', 'cURL error 7: Failed to connect to api.hsforms.com port 443: Connection refused'); });
[, $r] = post(array_merge($good, ['person' => person('refused')]));
check('connection refused (nothing sent) -> retryable', $r['hubspot']['status'] === 'retryable');
$id = lead();
update_post_meta($id, 'hubspot_attempts', '8'); backdate($id, 'hubspot_next_attempt_at', 1);
$mails = count($GLOBALS['T']['mail']); $before = posts_to_hubspot();
$hs->run_worker();
check('after 8 attempts -> manual_review with an email, no further sending', meta($id, 'hubspot_status') === 'manual_review' && posts_to_hubspot() === $before && count($GLOBALS['T']['mail']) === $mails + 1);

echo "== HubSpot accepted, but the response was lost\n";
$accepted = [];
hubspot(function ($method, $url, $args) use (&$accepted) {
    if ($method === 'POST') { $accepted[] = json_decode($args['body'], true); return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 10001 milliseconds'); }
    $results = array_map(function ($p) { return ['submittedAt' => (int) $p['submittedAt'], 'values' => array_map(function ($f) { return ['name' => $f['name'], 'value' => $f['value']]; }, $p['fields'])]; }, $accepted);
    return [200, ['results' => array_reverse($results), 'paging' => []]];
});
[$s, $r] = post(array_merge($good, ['person' => person('lost')]));
$id = lead();
check('timeout -> saved (200), HubSpot status uncertain (not delivered, not failed)', $s === 200 && $r['hubspot']['status'] === 'uncertain' && count($accepted) === 1);
$before_lookups = count($GLOBALS['T']['http']);
$hs->run_worker();
check('an uncertain lead is not looked up before its scheduled time', count($GLOBALS['T']['http']) === $before_lookups && meta($id, 'hubspot_status') === 'uncertain');
backdate($id, 'hubspot_next_attempt_at', 1);
$hs->run_worker();
if ($mode === 'token') {
    check('worker looks the submission up and marks it delivered without resending', meta($id, 'hubspot_status') === 'delivered' && meta($id, 'hubspot_delivered_by') === 'lookup' && count($accepted) === 1);
    $lookup = end($GLOBALS['T']['http']);
    check('lookup uses the documented submissions endpoint with the token', strpos($lookup['url'], 'https://api.hubapi.com/form-integrations/v1/submissions/forms/7c631e31-cc3b-44ba-a580-113ab3489db6') === 0 && $lookup['args']['headers']['Authorization'] === 'Bearer ' . TEST_TOKEN);
    // Not found: wait out the grace period before resending.
    hubspot(function ($method) { return $method === 'POST' ? new WP_Error('http_request_failed', 'cURL error 28: Operation timed out') : [200, ['results' => []]]; });
    post(array_merge($good, ['person' => person('lost2')]));
    $id2 = lead(); $before = posts_to_hubspot();
    backdate($id2, 'hubspot_next_attempt_at', 1);
    $hs->run_worker();
    check('not found yet but within 10 minutes -> still uncertain, not resent', meta($id2, 'hubspot_status') === 'uncertain' && posts_to_hubspot() === $before);
    backdate($id2, 'hubspot_uncertain_since', 700); backdate($id2, 'hubspot_next_attempt_at', 1);
    hubspot(function ($method) { return $method === 'POST' ? [200, []] : [200, ['results' => []]]; });
    $hs->run_worker();
    check('not found after the grace period -> resent once with the same submittedAt -> delivered', meta($id2, 'hubspot_status') === 'delivered' && posts_to_hubspot() === $before + 1 && last_payload()['submittedAt'] === meta($id2, 'hubspot_submitted_at_ms'));
    hubspot(function ($method) { return $method === 'POST' ? new WP_Error('x', 'cURL error 28: timed out') : [500, ['message' => 'oops']]; });
    post(array_merge($good, ['person' => person('lookupfail')]));
    $id3 = lead(); backdate($id3, 'hubspot_uncertain_since', 700); backdate($id3, 'hubspot_next_attempt_at', 1); $before = posts_to_hubspot();
    $hs->run_worker();
    check('lookup itself fails -> stays uncertain, never resent blindly', meta($id3, 'hubspot_status') === 'uncertain' && posts_to_hubspot() === $before);
    // R4: lookup refused (401) -> manual review instead of staying uncertain forever.
    hubspot(function ($method) { return $method === 'POST' ? new WP_Error('x', 'cURL error 28: timed out') : [401, ['message' => 'unauthorized']]; });
    post(array_merge($good, ['person' => person('r4a')]));
    $r4a = lead(); backdate($r4a, 'hubspot_next_attempt_at', 1);
    $hs->run_worker();
    check('R4: a lookup refused with 401 sends the lead to manual review (not uncertain forever)', meta($r4a, 'hubspot_status') === 'manual_review' && meta($r4a, 'hubspot_review_reason') === 'lookup_not_permitted');
    // Failing lookups honour the backoff and are bounded.
    hubspot(function ($method) { return $method === 'POST' ? new WP_Error('x', 'cURL error 28: timed out') : [500, ['message' => 'oops']]; });
    post(array_merge($good, ['person' => person('r4b')]));
    $r4b = lead(); backdate($r4b, 'hubspot_next_attempt_at', 1);
    $hs->run_worker();
    $gets = function () { return count(array_filter($GLOBALS['T']['http'], function ($c) { return $c['method'] === 'GET'; })); };
    $g = $gets(); $hs->run_worker(); $hs->run_worker();
    check('R4: after a failed lookup, no new lookup happens before the scheduled time', $gets() === $g && (int) meta($r4b, 'hubspot_next_attempt_at') > time());
    for ($i = 0; $i < 10; $i++) { backdate($r4b, 'hubspot_next_attempt_at', 1); $hs->run_worker(); }
    check('R4: repeated lookup failures end in manual review after 8 tries', meta($r4b, 'hubspot_status') === 'manual_review' && meta($r4b, 'hubspot_review_reason') === 'lookup_failed');
    // T3 (Codex P2 #3): the lookup failure counter can't be stored -> manual review, not endless lookups.
    hubspot(function ($method) { return $method === 'POST' ? new WP_Error('x', 'cURL error 28: timed out') : [500, ['message' => 'oops']]; });
    post(array_merge($good, ['person' => person('t3')]));
    $t3 = lead();
    $GLOBALS['T']['meta_fail'] = ['hubspot_lookup_failures' => true];
    $gets = function () { return count(array_filter($GLOBALS['T']['http'], function ($c) { return $c['method'] === 'GET'; })); };
    $g = $gets();
    for ($i = 0; $i < 10; $i++) { backdate($t3, 'hubspot_next_attempt_at', 1); $hs->run_worker(); }
    $GLOBALS['T']['meta_fail'] = [];
    check('T3: with the lookup counter unwritable, the lead goes to manual review after one failed lookup (was: 10 lookups, still uncertain)', meta($t3, 'hubspot_status') === 'manual_review' && meta($t3, 'hubspot_review_reason') === 'lookup_state_unwritable' && $gets() - $g === 1);
} else {
    check('without a token the outcome can\'t be checked -> manual_review, never resent', meta($id, 'hubspot_status') === 'manual_review' && meta($id, 'hubspot_review_reason') === 'outcome_unknown_no_token' && count($accepted) === 1);
}

echo "== Local status write fails after HubSpot accepted\n";
$accepted = [];
hubspot(function ($method, $url, $args) use (&$accepted) {
    if ($method === 'POST') { $accepted[] = json_decode($args['body'], true); return [200, []]; }
    $results = array_map(function ($p) { return ['submittedAt' => (int) $p['submittedAt'], 'values' => array_map(function ($f) { return ['name' => $f['name'], 'value' => $f['value']]; }, $p['fields'])]; }, $accepted);
    return [200, ['results' => $results]];
});
$GLOBALS['T']['meta_fail_value'] = ['hubspot_status' => 'delivered'];
$nowrite = person('nowrite');
[, $r] = post(array_merge($good, ['person' => $nowrite]));
$id = lead();
// Count only this lead's submissions (earlier uncertain leads may legitimately be resent by the worker).
$mine = function () use (&$accepted, $nowrite) { return count(array_filter($accepted, function ($p) use ($nowrite) { foreach ($p['fields'] as $f) { if ($f['name'] === 'email' && $f['value'] === $nowrite['email']) return true; } return false; })); };
$GLOBALS['T']['meta_fail_value'] = [];
check('status stays "sending" (not falsely failed, not falsely delivered)', meta($id, 'hubspot_status') === 'sending' && $mine() === 1);
$hs->run_worker();
check('within 5 minutes the worker leaves it alone', $mine() === 1 && meta($id, 'hubspot_status') === 'sending');
backdate($id, 'hubspot_attempt_started_at', 400);
backdate($id, 'hubspot_uncertain_since', 0);
backdate($id, 'hubspot_next_attempt_at', 1);
$hs->run_worker();
backdate($id, 'hubspot_next_attempt_at', 1);
$hs->run_worker();
if ($mode === 'token') {
    check('then treated as uncertain, found by lookup -> delivered, still only one submission', meta($id, 'hubspot_status') === 'delivered' && $mine() === 1);
} else {
    check('then treated as uncertain -> manual_review (no token), still only one submission', meta($id, 'hubspot_status') === 'manual_review' && $mine() === 1);
}
$GLOBALS['T']['meta_fail'] = ['hubspot_status' => true];
$before = posts_to_hubspot();
post(array_merge($good, ['person' => person('nosending')]));
$GLOBALS['T']['meta_fail'] = [];
check('if "sending" can\'t be recorded first, nothing is sent at all', posts_to_hubspot() === $before);

echo "== Repeated and simultaneous requests\n";
hubspot(respond(200, []));
$keyed = array_merge(with($good, 'agent_context', ['idempotency_key' => 'hs-repeat-0001']), ['person' => person('rep')]);
[$s1, $r1] = post($keyed); $before = posts_to_hubspot();
[$s2, $r2] = post($keyed);
check('repeat with the same key: same lead, current status (delivered), no second HubSpot submission', $r2['duplicate'] === true && $r2['lead_id'] === $r1['lead_id'] && $r2['hubspot']['status'] === 'delivered' && posts_to_hubspot() === $before);
hubspot(respond(503, []));
$keyed2 = array_merge(with($good, 'agent_context', ['idempotency_key' => 'hs-repeat-0002']), ['person' => person('rep2')]);
post($keyed2); $before = posts_to_hubspot();
[, $r] = post($keyed2);
check('repeat while delivery is retrying: reports "retryable", does not submit again', $r['duplicate'] === true && $r['hubspot']['status'] === 'retryable' && posts_to_hubspot() === $before);
hubspot(respond(400, ['errors' => [['errorType' => 'BLOCKED_EMAIL', 'message' => 'x']]]));
$keyed3 = array_merge(with($good, 'agent_context', ['idempotency_key' => 'hs-repeat-0003']), ['person' => person('rep3')]);
post($keyed3); [$s, $r] = post($keyed3);
check('repeat of a rejected inquiry: 422 rejected again, duplicate, nothing re-sent', $s === 422 && $r['duplicate'] === true && $r['status'] === 'rejected');
// Simultaneous: the second request arrives while the first holds the reservation.
$sim = array_merge(with($good, 'agent_context', ['idempotency_key' => 'hs-simul-0001']), ['person' => person('sim')]);
$fp = hash('sha256', implode("\n", [strtolower($sim['person']['email']), 'Ada', 'Test', $sim['person']['phone'], 'Acme AB', 'Brand Tracking', $sim['inquiry']['message'], 'Swedish', '0']));
$GLOBALS['wpdb']->insert('wp_options', ['option_name' => 'nepa_lead_res_' . sha1('key:hs-simul-0001'), 'option_value' => json_encode(['fingerprint' => $fp, 'created' => time(), 'ttl' => 86400, 'lead_post_id' => 0, 'lead_id' => '', 'token' => 't']), 'autoload' => 'no']);
$before = posts_to_hubspot(); $leads = count($GLOBALS['T']['posts']);
[$s, $r] = post($sim);
check('simultaneous identical request -> 409 in_progress: no second lead, no second HubSpot submission', $s === 409 && $r['error'] === 'in_progress' && posts_to_hubspot() === $before && count($GLOBALS['T']['posts']) === $leads);

echo "== Concurrent workers\n";
hubspot(respond(503, []));
$wp = person('workers');
post(array_merge($good, ['person' => $wp]));
$id = lead(); backdate($id, 'hubspot_next_attempt_at', 1);
$lease = 'nepa_hs_lease_' . $id;
$GLOBALS['wpdb']->insert('wp_options', ['option_name' => $lease, 'option_value' => json_encode(['expires' => time() + 60, 'token' => 'other-worker']), 'autoload' => 'no']);
hubspot(respond(200, []));
$before = posts_for($wp['email']);
$hs->run_worker();
check('a lead claimed by another worker is skipped (no double submission)', posts_for($wp['email']) === $before && meta($id, 'hubspot_status') === 'retryable');
$GLOBALS['wpdb']->update('wp_options', ['option_value' => json_encode(['expires' => time() - 1, 'token' => 'other-worker'])], ['option_name' => $lease]);
$hs->run_worker();
check('an expired claim is taken over and the lead delivered once', posts_for($wp['email']) === $before + 1 && meta($id, 'hubspot_status') === 'delivered');
check('the claim is released afterwards', !isset($GLOBALS['wpdb']->rows[$lease]));

echo "== Administrator actions\n";
hubspot(respond(401, []));
post(array_merge($good, ['person' => person('admin')]));
$id = lead();
$GLOBALS['T']['can'] = false; $_GET = ['lead' => $id, '_wpnonce' => 'nepa_hubspot_retry_' . $id];
$denied = false; try { $hs->admin_lead_from_request('nepa_hubspot_retry'); } catch (Nepa_Test_Die $e) { $denied = $e->getMessage() === '403'; }
check('users without the Agent Leads capability get 403', $denied);
$GLOBALS['T']['can'] = true; $_GET = ['lead' => $id, '_wpnonce' => 'wrong'];
$denied = false; try { $hs->admin_lead_from_request('nepa_hubspot_retry'); } catch (Nepa_Test_Die $e) { $denied = true; }
check('a request without a valid nonce is refused', $denied);
hubspot(respond(200, []));
$before = posts_to_hubspot();
$result = $hs->admin_retry($id, 7);
check('administrator retry of a manual_review lead sends it -> delivered, logged with the user', $result === 'delivered' && posts_to_hubspot() === $before + 1 && strpos((string) meta($id, 'hubspot_log'), 'manual retry by user #7') !== false);
hubspot(function () { return new WP_Error('x', 'cURL error 28: timed out'); });
post(array_merge($good, ['person' => person('verify')]));
$id = lead();
$hs->admin_mark_delivered($id, 7);
check('"Mark as delivered" after checking HubSpot records who verified it', meta($id, 'hubspot_status') === 'delivered' && meta($id, 'hubspot_delivered_by') === 'admin_verified');

echo "== Regressions from the second-pass review\n";
// R1: waiting leads must not fill the batch and starve a due one.
hubspot(respond(503, []));
$waiting = [];
for ($i = 0; $i < 10; $i++) { post(array_merge($good, ['person' => person('wait')])); $waiting[] = lead(); }
foreach ($waiting as $w) { update_post_meta($w, 'hubspot_next_attempt_at', (string) (time() + 3600)); $GLOBALS['T']['posts'][$w]['post_date_gmt'] = time() - 5000; }
$due_person = person('due');
post(array_merge($good, ['person' => $due_person]));
$due = lead(); backdate($due, 'hubspot_next_attempt_at', 1);
hubspot(respond(200, []));
$hs->run_worker();
check('R1: 10 older leads waiting for later retries do not block a newer lead that is due', meta($due, 'hubspot_status') === 'delivered' && posts_for($due_person['email']) === 2);
foreach ($waiting as $w) { $hs->admin_mark_delivered($w, 1); }

// R2: field meta writes fail -> the submission still carries the complete data (snapshot in the post row).
$GLOBALS['T']['meta_fail'] = ['message' => true, 'first_name' => true, 'email' => true, 'company' => true, 'phone' => true, 'hubspot_message' => true, 'hubspot_submitted_at_ms' => true];
$r2p = person('r2');
post(array_merge($good, ['person' => $r2p]));
$GLOBALS['T']['meta_fail'] = [];
$pl = last_payload(); $f = []; foreach ($pl['fields'] as $x) { $f[$x['name']] = $x['value']; }
check('R2: with field meta writes failing, HubSpot still gets the full message, name, email, company, phone and submittedAt',
    strpos($f['message'], 'We want to track our brand') === 0 && $f['firstname'] === 'Ada' && $f['email'] === $r2p['email'] && $f['name'] === 'Acme AB' && $f['phone'] !== '' && ctype_digit($pl['submittedAt']));
// R2b: unreadable snapshot -> nothing sent, manual review.
hubspot(respond(200, []));
post(array_merge($good, ['person' => person('r2b')]));
$bad = lead();
$GLOBALS['T']['posts'][$bad]['post_content_filtered'] = 'nepa-hs-v1:not-base64';
update_post_meta($bad, 'hubspot_status', 'retryable'); backdate($bad, 'hubspot_next_attempt_at', 1);
$before = posts_to_hubspot(); $hs->run_worker();
check('R2b: if the stored submission data cannot be read, nothing is sent and the lead goes to manual review', posts_to_hubspot() === $before && meta($bad, 'hubspot_status') === 'manual_review');

// R3: initial status AND 'sending' writes fail -> nothing sent; once storage recovers the worker finds and delivers it.
$GLOBALS['T']['meta_fail'] = ['hubspot_status' => true];
$r3p = person('r3');
[, $r] = post(array_merge($good, ['person' => $r3p]));
$r3 = lead();
$GLOBALS['T']['meta_fail'] = [];
check('R3: nothing is sent while delivery state cannot be stored', posts_for($r3p['email']) === 0 && meta($r3, 'hubspot_status') === '');
$hs->run_worker();
check('R3: after storage recovers, the worker finds the lead with no delivery state and delivers it', meta($r3, 'hubspot_status') === 'delivered' && posts_for($r3p['email']) === 1);
// Older leads (no submission data) are marked not_applicable once and never sent.
$legacy = wp_insert_post(['post_type' => 'nepa_lead', 'post_status' => 'private', 'post_title' => 'legacy', 'post_content' => 'old']);
$before = posts_to_hubspot(); $hs->run_worker();
check('R3: leads from before HubSpot delivery are marked not_applicable and never sent', meta($legacy, 'hubspot_status') === 'not_applicable' && posts_to_hubspot() === $before);

// R5: the attempt counter cannot be stored -> no request is made (the attempt limit stays enforceable).
hubspot(respond(503, []));
$r5p = person('r5');
$GLOBALS['T']['meta_fail'] = ['hubspot_attempts' => true];
post(array_merge($good, ['person' => $r5p]));
$r5 = lead();
for ($i = 0; $i < 11; $i++) { backdate($r5, 'hubspot_next_attempt_at', 1); $hs->run_worker(); }
$GLOBALS['T']['meta_fail'] = [];
check('R5: with the attempt counter unwritable, not a single submission is sent (was: unlimited)', posts_for($r5p['email']) === 0);

// R6: an administrator retry of a delivered lead never sends again.
hubspot(respond(200, []));
$r6p = person('r6');
post(array_merge($good, ['person' => $r6p]));
$r6 = lead();
$hs->admin_retry($r6, 7); $hs->admin_retry($r6, 7); $hs->process($r6, true);
check('R6: repeated admin retries of a delivered lead send nothing more', posts_for($r6p['email']) === 1 && meta($r6, 'hubspot_status') === 'delivered');

// R7: quotes and backslashes survive storage (WordPress unslashes meta and post fields).
hubspot(respond(400, ['message' => 'He said "no" \\ again', 'errors' => [['errorType' => 'FIELD_NOT_IN_FORM_DEFINITION', 'message' => 'Field "x" is not \\ allowed']]]));
$r7 = array_merge($good, ['person' => person('r7')]);
$r7['inquiry']['message'] = 'Path C:\\Reports\\Q3 and a "quoted" word';
post($r7);
$r7id = lead();
$log = json_decode((string) meta($r7id, 'hubspot_log'), true);
check('R7: the delivery log is valid JSON after an error message containing quotes and backslashes', is_array($log) && count($log) >= 1);
$snapshot = $hs->read_snapshot($r7id);
check('R7: backslashes and quotes in the person\'s message are kept exactly (submission data and wp-admin copy)',
    strpos($snapshot['message'], 'Path C:\\Reports\\Q3 and a "quoted" word') === 0 && strpos((string) meta($r7id, 'message'), 'C:\\Reports\\Q3') !== false);

echo "== Regressions from the third-pass review\n";
// T1 (Codex P1 #1): the worker delivers a brand-new lead before the contact request initializes it.
hubspot(respond(200, []));
$t1p = person('t1');
$GLOBALS['T']['after_insert'] = function ($id) use ($hs) { $hs->run_worker(); };
[$s, $r] = post(array_merge($good, ['person' => $t1p]));
$t1 = lead();
check('T1: worker delivers a new lead mid-request -> the request keeps "delivered", exactly one HubSpot submission', posts_for($t1p['email']) === 1 && meta($t1, 'hubspot_status') === 'delivered' && $r['hubspot']['status'] === 'delivered');
// T1b: the worker holds the lead's lock when the request initializes -> the request doesn't touch it.
$t1bp = person('t1b');
$next = $GLOBALS['T']['next_id'];
$GLOBALS['wpdb']->insert('wp_options', ['option_name' => 'nepa_hs_lease_' . $next, 'option_value' => json_encode(['expires' => time() + 60, 'token' => 'worker']), 'autoload' => 'no']);
[$s, $r] = post(array_merge($good, ['person' => $t1bp]));
check('T1b: while a worker holds the lead, the request sends nothing and writes no delivery state', posts_for($t1bp['email']) === 0 && meta($next, 'hubspot_status') === '' && $s === 200 && $r['hubspot']['status'] === 'pending');
$GLOBALS['wpdb']->delete('wp_options', ['option_name' => 'nepa_hs_lease_' . $next]);
$hs->run_worker();
check('T1b: the worker then delivers it once', posts_for($t1bp['email']) === 1 && meta($next, 'hubspot_status') === 'delivered');

// T2 (Codex P1 #2): a worker finishes delivery while an admin retry is starting.
hubspot(respond(503, []));
$t2p = person('t2');
post(array_merge($good, ['person' => $t2p]));
$t2 = lead(); backdate($t2, 'hubspot_next_attempt_at', 1);
$t2_base = posts_for($t2p['email']); // the initial (failed) attempt
hubspot(respond(200, []));
// Codex's race: a worker runs (and could finish delivery) just before the admin request's
// first status write - i.e. after the admin read the old status.
$GLOBALS['T']['before_meta_write'] = ['hubspot_status', function ($id) use ($hs) { $hs->run_worker(); }];
$result = $hs->admin_retry($t2, 7);
$GLOBALS['T']['before_meta_write'] = null;
check('T2: admin retry racing a worker -> exactly one more submission (the admin\'s), status delivered', posts_for($t2p['email']) === $t2_base + 1 && meta($t2, 'hubspot_status') === 'delivered' && $result === 'delivered');
// The worker finishes first, then the (stale) admin retry arrives.
$hs->admin_retry($t2, 7);
check('T2: a later admin retry of the delivered lead sends nothing', posts_for($t2p['email']) === $t2_base + 1 && meta($t2, 'hubspot_status') === 'delivered');
// Lock held by a worker: the admin action changes nothing.
hubspot(respond(503, []));
$t2c = person('t2c');
post(array_merge($good, ['person' => $t2c]));
$t2cid = lead();
$GLOBALS['wpdb']->insert('wp_options', ['option_name' => 'nepa_hs_lease_' . $t2cid, 'option_value' => json_encode(['expires' => time() + 60, 'token' => 'worker']), 'autoload' => 'no']);
$log_before = meta($t2cid, 'hubspot_log'); $status_before = meta($t2cid, 'hubspot_status');
$r1 = $hs->admin_retry($t2cid, 7); $r2 = $hs->admin_mark_delivered($t2cid, 7);
check('T2: while a worker holds the lead, admin retry and mark-delivered return "locked" and change nothing', $r1 === 'locked' && $r2 === 'locked' && meta($t2cid, 'hubspot_status') === $status_before && meta($t2cid, 'hubspot_log') === $log_before);
$GLOBALS['wpdb']->delete('wp_options', ['option_name' => 'nepa_hs_lease_' . $t2cid]);
$hs->admin_mark_delivered($t2cid, 7);

echo "== Regressions from the fourth-pass review (separate request caches)\n";
// C1 (Codex P1): the contact request has the lead's meta cached (no status yet); a worker running
// as a SEPARATE request delivers it; then the contact request takes the lock.
hubspot(respond(200, []));
$c1p = person('c1');
$GLOBALS['T']['before_option_insert'] = ['nepa_hs_lease_', function () use ($hs) {
    nepa_test_request('worker', function () use ($hs) { $hs->run_worker(); });
}];
[$s, $r] = post(array_merge($good, ['person' => $c1p]));
$GLOBALS['T']['before_option_insert'] = null;
$c1 = lead();
check('C1: worker (separate request) delivers before the contact request locks -> one submission, status stays delivered', posts_for($c1p['email']) === 1 && meta($c1, 'hubspot_status') === 'delivered' && $r['hubspot']['status'] === 'delivered');

// C2: an admin request has the lead loaded (cached), a worker request delivers it, then the admin clicks Retry.
hubspot(respond(503, []));
$c2p = person('c2');
post(array_merge($good, ['person' => $c2p]));
$c2 = lead(); backdate($c2, 'hubspot_next_attempt_at', 1);
$c2_base = posts_for($c2p['email']);
hubspot(respond(200, []));
nepa_test_request('admin', function () use ($hs, $c2) { get_post_meta($c2, 'hubspot_status'); }); // admin page load caches the lead
$GLOBALS['T']['before_option_insert'] = ['nepa_hs_lease_', function () use ($hs) {
    nepa_test_request('worker2', function () use ($hs) { $hs->run_worker(); });
}];
$result = nepa_test_request('admin', function () use ($hs, $c2) { return $hs->admin_retry($c2, 7); });
$GLOBALS['T']['before_option_insert'] = null;
check('C2: admin retry with a stale cached page after a worker delivered -> nothing more sent, status delivered', posts_for($c2p['email']) === $c2_base + 1 && meta($c2, 'hubspot_status') === 'delivered' && $result === 'delivered');
check('C2: the admin request decided on fresh data (no retry logged against a delivered lead)', strpos(meta($c2, 'hubspot_log'), 'manual retry by user #7') === false);

echo "== Secrets\n";
$all = json_encode($GLOBALS['T']['meta']) . json_encode($GLOBALS['wpdb']->rows) . json_encode($GLOBALS['T']['mail']);
check('the token never appears in lead data, options or emails', strpos($all, TEST_TOKEN) === false);

echo "\n{$pass} passed, {$fail} failed ({$mode})\n";
exit($fail ? 1 : 0);
