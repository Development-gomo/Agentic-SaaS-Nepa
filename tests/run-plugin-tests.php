<?php
/**
 * Plugin tests for nepa-api (WordPress stubbed - see wp-stubs.php).
 *
 * Usage (from the package root):
 *   php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_WARNING' tests/run-plugin-tests.php
 *
 * Runs against a temporary COPY of nepa-api/, so the package's data files are never
 * modified. Exit code 0 = all passed. (Warnings are hidden because the CLI reports every
 * header() call after output as "headers already sent".)
 */
require __DIR__ . '/wp-stubs.php';

$package = dirname(__DIR__);
$copy = sys_get_temp_dir() . '/nepa-tests-plugin-' . getmypid();
exec('cp -R ' . escapeshellarg($package . '/nepa-api') . ' ' . escapeshellarg($copy));
$copy = realpath($copy); // e.g. macOS /var -> /private/var, so paths compare equal
register_shutdown_function(function () use ($copy) {
    exec('rm -rf ' . escapeshellarg($copy) . ' ' . escapeshellarg($GLOBALS['T']['uploads']));
});
require $copy . '/nepa-api.php';
require_once $copy . '/includes/class-sync.php';
$api = $GLOBALS['nepa_agent_api'];

$pass = 0;
$fail = 0;
function check($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label  $detail\n"; }
}
function invoke($object, $method, ...$args) {
    $m = new ReflectionMethod($object, $method);
    ob_start();
    $ret = $m->invoke($object, ...$args);
    return [$ret, ob_get_clean()];
}

/* ---------- php://input replacement so contact requests can be simulated ---------- */
class Fake_Input_Stream {
    public $context;
    private $pos = 0;
    public function stream_open($path, $mode, $options, &$opened) { $this->pos = 0; return true; }
    public function stream_read($n) { $d = substr($GLOBALS['__body'], $this->pos, $n); $this->pos += strlen($d); return $d; }
    public function stream_eof() { return $this->pos >= strlen($GLOBALS['__body']); }
    public function stream_stat() { return []; }
}
stream_wrapper_unregister('php');
stream_wrapper_register('php', 'Fake_Input_Stream');

function post($body) {
    global $api;
    $GLOBALS['__body'] = is_string($body) ? $body : json_encode($body);
    $_SERVER['CONTENT_LENGTH'] = strlen($GLOBALS['__body']);
    $GLOBALS['T']['status'] = null;
    [, $out] = invoke($api, 'contact_submission');
    return [$GLOBALS['T']['status'], json_decode($out, true)];
}
function with($base, $section, $values) { $base[$section] = array_merge($base[$section] ?? [], $values); return $base; }
function lead_count() { return count($GLOBALS['T']['posts']); }
function mail_count() { return count($GLOBALS['T']['mail']); }
function reset_limits() { $GLOBALS['T']['transients'] = []; }
function age_all_leads($seconds) { foreach ($GLOBALS['T']['posts'] as $id => $p) { $GLOBALS['T']['posts'][$id]['post_date_gmt'] = time() - $seconds; } }

echo "== Activation\n";
($GLOBALS['T']['activate'])();
check('flush happens with the agent rewrite rules registered', isset($GLOBALS['T']['flushed_rules']['^agent/v1/([a-z-]+)\.json$'], $GLOBALS['T']['flushed_rules']['^agent/?$'], $GLOBALS['T']['flushed_rules']['^openapi\.json$']));
check('activation schedules the retry and purge jobs', isset($GLOBALS['T']['cron']['nepa_agent_api_retry_notifications'], $GLOBALS['T']['cron']['nepa_agent_api_purge_leads']));
check('CORS allows the documented Idempotency-Key header', (bool) preg_match("/Access-Control-Allow-Headers: [^']*Idempotency-Key/", file_get_contents($copy . '/nepa-api.php')));

echo "== Filters\n";
$_GET = ['service' => 'brand tracking'];
[, $out] = invoke($api, 'serve_filtered_json', 'case-studies.json', 'case_studies', ['industry', 'segment', 'service']);
check('?service=brand tracking matches entries of comma-separated lists (10)', json_decode($out, true)['count'] === 10);
$_GET = ['service' => 'Brand'];
[, $out] = invoke($api, 'serve_filtered_json', 'case-studies.json', 'case_studies', ['service']);
check('a partial word does not match', json_decode($out, true)['count'] === 0);
$_GET = ['language' => 'sv', 'limit' => '5'];
[, $out] = invoke($api, 'serve_paginated_json', 'press-releases.json', 'releases', ['language', 'category']);
$j = json_decode($out, true);
check('?language=sv is applied before pagination (151 total, 5 returned)', $j['total'] === 151 && count($j['releases']) === 5 && $j['releases'][0]['language'] === 'sv');
$_GET = ['language' => 'fi'];
[, $out] = invoke($api, 'serve_filtered_json', 'localized-pages.json', 'pages', ['language', 'section']);
check('localized-pages ?language=fi (24)', json_decode($out, true)['count'] === 24);
$_GET = [];

echo "== Contact: validation\n";
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
$good = [
    'agent_context' => ['user_authorized_submission' => true],
    'person' => ['name' => 'Ada Test', 'email' => 'ada@example.com'],
    'company' => ['name' => 'Acme'],
    'inquiry' => ['service_interest' => 'Brand Tracking', 'project_summary' => 'Need a tracker'],
];
[$s, $r] = post('not json');
check('invalid JSON -> 400 invalid_json', $s === 400 && $r['error'] === 'invalid_json');
[$s] = post(with($good, 'agent_context', ['user_authorized_submission' => false]));
check('consent not confirmed -> 403', $s === 403);
[$s, $r] = post(array_merge($good, ['person' => 'Ada']));
check('person as a string -> 400 invalid_structure', $s === 400 && $r['error'] === 'invalid_structure');
[$s, $r] = post(with($good, 'person', ['name' => ['x']]));
check('name as an array -> 400 invalid_type', $s === 400 && $r['error'] === 'invalid_type');
[$s, $r] = post(with($good, 'inquiry', ['project_summary' => str_repeat('a', 5001)]));
check('summary over 5,000 characters -> 422 field_too_long', $s === 422 && $r['error'] === 'field_too_long');
[$s] = post(json_encode($good) . str_repeat(' ', 20001));
check('body over 20,000 bytes -> 413', $s === 413);
[$s] = post(with($good, 'agent_context', ['idempotency_key' => 'bad key!']));
check('malformed idempotency key -> 400', $s === 400);
check('rejected requests stored nothing and used no quota', lead_count() === 0 && !$GLOBALS['T']['transients'] && !$GLOBALS['wpdb']->rows);

echo "== Contact: acceptance and idempotency\n";
[$s, $r] = post($good);
$first = $r['lead_id'] ?? '';
check('valid submission -> 200 received, duplicate false', $s === 200 && $r['status'] === 'received' && $r['duplicate'] === false);
$lead = array_key_last($GLOBALS['T']['posts']);
check('lead stored with consent record and channel', get_post_meta($lead, 'user_authorized_submission') === '1' && get_post_meta($lead, 'channel') === 'direct' && get_post_meta($lead, 'mail_sent') === '1');
check('one notification email sent', mail_count() === 1);
[$s, $r] = post($good);
check('identical retry -> duplicate with the same lead, nothing new', $s === 200 && $r['duplicate'] === true && $r['lead_id'] === $first && lead_count() === 1 && mail_count() === 1);

$keyed = with(with($good, 'agent_context', ['idempotency_key' => 'order-0001-abc']), 'inquiry', ['project_summary' => 'Keyed request']);
[, $r1] = post($keyed);
[, $r2] = post($keyed);
check('same idempotency key + same content -> original lead returned', $r1['duplicate'] === false && $r2['duplicate'] === true && $r1['lead_id'] === $r2['lead_id']);
[$s, $r] = post(with($keyed, 'inquiry', ['project_summary' => 'A completely different inquiry']));
check('same idempotency key + different content -> 409 idempotency_key_reused, nothing stored', $s === 409 && $r['error'] === 'idempotency_key_reused' && lead_count() === 2);

// A request that is still being processed holds the reservation without a lead yet.
$pending = with($good, 'inquiry', ['project_summary' => 'Concurrent request']);
$fp = hash('sha256', "ada@example.com\nAda Test\nAcme\nBrand Tracking\nConcurrent request");
$GLOBALS['wpdb']->insert('wp_options', ['option_name' => 'nepa_lead_res_' . sha1('fp:' . $fp), 'option_value' => json_encode(['fingerprint' => $fp, 'created' => time(), 'ttl' => 1800, 'lead_post_id' => 0, 'lead_id' => '']), 'autoload' => 'no']);
[$s, $r] = post($pending);
check('overlapping identical request while the first is in flight -> 409 in_progress, no second lead', $s === 409 && $r['error'] === 'in_progress' && lead_count() === 2);
// Expired reservations don't block.
$GLOBALS['wpdb']->update('wp_options', ['option_value' => json_encode(['fingerprint' => $fp, 'created' => time() - 4000, 'ttl' => 1800, 'lead_post_id' => 0, 'lead_id' => ''])], ['option_name' => 'nepa_lead_res_' . sha1('fp:' . $fp)]);
[$s, $r] = post($pending);
check('an expired reservation is replaced and the submission accepted', $s === 200 && $r['duplicate'] === false && lead_count() === 3);

// Codex race: two requests read the SAME expired reservation. Request A replaces it;
// request B (which read the old value before A acted) must not delete A's new reservation.
$race_name = 'nepa_lead_res_' . sha1('key:race-key-0001');
$race_fp = hash('sha256', "ada@example.com\nAda Test\nAcme\nBrand Tracking\nRace");
$expired_raw = json_encode(['fingerprint' => $race_fp, 'created' => time() - 90000, 'ttl' => 86400, 'lead_post_id' => 0, 'lead_id' => '', 'token' => 'old']);
$GLOBALS['wpdb']->insert('wp_options', ['option_name' => $race_name, 'option_value' => $expired_raw, 'autoload' => 'no']);
[$a] = invoke($api, 'reserve_submission', 'key:race-key-0001', $race_fp, 86400);
$a_raw = $GLOBALS['wpdb']->rows[$race_name] ?? null;
$GLOBALS['wpdb']->stale_reads[$race_name] = [$expired_raw];   // B still sees the old value once
[$b] = invoke($api, 'reserve_submission', 'key:race-key-0001', $race_fp, 86400);
check('race on an expired reservation: only one request owns it', $a['status'] === 'owned' && $b['status'] === 'existing');
check('race on an expired reservation: the winner\'s reservation is not deleted by the loser', ($GLOBALS['wpdb']->rows[$race_name] ?? null) === $a_raw);
invoke($api, 'release_reservation', $a);
check('releasing removes only this request\'s own reservation', !isset($GLOBALS['wpdb']->rows[$race_name]));

// Reservation storage failure (database error, no existing row) -> retryable 503, not 409.
$GLOBALS['wpdb']->insert_fails = true;
[$s, $r] = post(with(with($good, 'agent_context', ['idempotency_key' => 'brand-new-key-777']), 'inquiry', ['project_summary' => 'DB down']));
check('database failure while reserving -> 503 reservation_unavailable (not 409)', $s === 503 && ($r['error'] ?? '') === 'reservation_unavailable');
$GLOBALS['wpdb']->insert_fails = false;

echo "== Contact: rate limits\n";
[$s] = post(with($good, 'inquiry', ['project_summary' => 'fourth for this email']));
check('4th distinct submission for one email within the hour -> 429', $s === 429);
check('a rate-limited request leaves no reservation behind', !array_filter($GLOBALS['wpdb']->rows, function ($v) { return json_decode($v, true)['lead_post_id'] === 0; }));
[$s1] = post(with($good, 'person', ['name' => 'B', 'email' => 'b@example.com']));
[$s2] = post(with($good, 'person', ['name' => 'C', 'email' => 'c@example.com']));
check('IP quota allows 5 accepted submissions in 10 minutes', $s1 === 200 && $s2 === 200);
[$s] = post(with($good, 'person', ['name' => 'D', 'email' => 'd@example.com']));
check('6th from the same IP -> 429', $s === 429);
check('the IP-limited request did not use its email quota', !preg_grep('/^nepa_contact_rl_email_' . md5('d@example.com') . '/', array_keys($GLOBALS['T']['transients'])));
check('counters are fixed-window (window index in the key)', (bool) preg_grep('/^nepa_contact_rl_[0-9a-f]{32}_\d+$/', array_keys($GLOBALS['T']['transients'])));

echo "== Contact: storage and notification failures\n";
reset_limits();
$GLOBALS['T']['insert_fail'] = true;
$before_mail = mail_count();
[$s, $r] = post(with($good, 'person', ['name' => 'E', 'email' => 'e@example.com']));
check('lead cannot be stored -> 503 not_recorded, no email, reservation released', $s === 503 && $r['error'] === 'not_recorded' && mail_count() === $before_mail);
$GLOBALS['T']['insert_fail'] = false;
[$s, $r] = post(with($good, 'person', ['name' => 'E', 'email' => 'e@example.com']));
check('the retry after a storage failure is accepted (not blocked as a duplicate)', $s === 200 && $r['duplicate'] === false);

$GLOBALS['T']['mail_ok'] = false;
[$s] = post(with($good, 'person', ['name' => 'F', 'email' => 'f@example.com']));
$f = array_key_last($GLOBALS['T']['posts']);
check('email failure -> still 200 (lead stored) and queued for retry', $s === 200 && get_post_meta($f, 'mail_sent') === '0' && get_post_meta($f, 'mail_attempts') === '1');

echo "== Notification retry job\n";
$GLOBALS['T']['mail_ok'] = true;
$api->retry_failed_notifications();
check('leads younger than 5 minutes are not retried yet (their own request may still be sending)', get_post_meta($f, 'mail_sent') === '0');
age_all_leads(600);
// 25 exhausted leads, newer than the pending one, must not crowd it out of the 20-item batch.
for ($i = 0; $i < 25; $i++) {
    $id = wp_insert_post(['post_type' => 'nepa_lead', 'post_status' => 'private', 'post_title' => "x{$i}", 'post_content' => '']);
    update_post_meta($id, 'mail_sent', '0');
    update_post_meta($id, 'mail_attempts', '6');
    $GLOBALS['T']['posts'][$id]['post_date_gmt'] = time() - 300 - $i;
}
$GLOBALS['T']['posts'][$f]['post_date_gmt'] = time() - 400;
$sent_before = mail_count();
$api->retry_failed_notifications();
check('pending lead is re-sent even with 25 exhausted leads present', get_post_meta($f, 'mail_sent') === '1' && get_post_meta($f, 'mail_attempts') === '2');
check('exhausted leads are not re-sent', mail_count() === $sent_before + 1);

// Retry state could not be stored at all AND the first email failed: still recovered.
reset_limits();
$GLOBALS['T']['meta_fail'] = ['mail_sent' => true, 'mail_attempts' => true];
$GLOBALS['T']['mail_ok'] = false;
[$s] = post(with($good, 'person', ['name' => 'Lost State', 'email' => 'lost@example.com']));
$lost = array_key_last($GLOBALS['T']['posts']);
$GLOBALS['T']['meta_fail'] = [];
$GLOBALS['T']['mail_ok'] = true;
$GLOBALS['T']['posts'][$lost]['post_date_gmt'] = time() - 600;
check('lead with no retry state and a failed email was stored', $s === 200 && get_post_meta($lost, 'mail_sent') === '' && get_post_meta($lost, 'mail_attempts') === '');
$before = mail_count();
$api->retry_failed_notifications();
check('the retry job recovers it (selected because it is not confirmed sent)', mail_count() === $before + 1 && get_post_meta($lost, 'mail_sent') === '1');
// Leads older than the retry window are left for manual follow-up.
$stale = wp_insert_post(['post_type' => 'nepa_lead', 'post_status' => 'private', 'post_title' => 'stale', 'post_content' => '']);
$GLOBALS['T']['posts'][$stale]['post_date_gmt'] = time() - 8 * 86400;
$before = mail_count();
$api->retry_failed_notifications();
check('leads older than 7 days are not retried (bounds re-sending)', mail_count() === $before);

echo "== Metadata write failures\n";
reset_limits();
$GLOBALS['T']['meta_fail'] = ['mail_sent' => true];
$mail_before_g = mail_count();
[$s, $r] = post(with($good, 'person', ['name' => 'G', 'email' => 'g@example.com']));
$g = array_key_last($GLOBALS['T']['posts']);
check('if retry state cannot be stored, the stored lead is still accepted and its email still sent', $s === 200 && mail_count() === $mail_before_g + 1);
$GLOBALS['T']['meta_fail'] = ['idempotency_key' => true, 'fingerprint' => true];
[, $r1] = post(with($good, 'person', ['name' => 'H', 'email' => 'h@example.com']));
[$s, $r2] = post(with($good, 'person', ['name' => 'H', 'email' => 'h@example.com']));
check('idempotency does not depend on post meta: a retry is still a duplicate when meta writes fail', $s === 200 && $r2['duplicate'] === true && $r1['lead_id'] === $r2['lead_id']);
$GLOBALS['T']['meta_fail'] = [];

echo "== Retention job\n";
$trashed = wp_insert_post(['post_type' => 'nepa_lead', 'post_status' => 'trash', 'post_title' => 'old trashed', 'post_content' => '']);
$old = wp_insert_post(['post_type' => 'nepa_lead', 'post_status' => 'private', 'post_title' => 'old', 'post_content' => '']);
$GLOBALS['T']['posts'][$trashed]['post_date_gmt'] = $GLOBALS['T']['posts'][$old]['post_date_gmt'] = time() - 40 * 86400;
$api->purge_expired_leads();
check('retention off (0 days) keeps old leads', isset($GLOBALS['T']['posts'][$old], $GLOBALS['T']['posts'][$trashed]));
$GLOBALS['wpdb']->insert('wp_options', ['option_name' => 'nepa_lead_res_expired', 'option_value' => json_encode(['fingerprint' => 'x', 'created' => time() - 90000, 'ttl' => 86400, 'lead_post_id' => 1, 'lead_id' => 'x']), 'autoload' => 'no']);
$api->purge_expired_leads();
check('expired reservations are cleaned up', !isset($GLOBALS['wpdb']->rows['nepa_lead_res_expired']));

// Cleanup race: the job read an expired value, but a request replaced it before the delete.
$live_raw = json_encode(['fingerprint' => 'y', 'created' => time(), 'ttl' => 1800, 'lead_post_id' => 0, 'lead_id' => '', 'token' => 'new']);
$GLOBALS['wpdb']->insert('wp_options', ['option_name' => 'nepa_lead_res_cleanup', 'option_value' => $live_raw, 'autoload' => 'no']);
$GLOBALS['wpdb']->stale_results = [(object) ['option_name' => 'nepa_lead_res_cleanup', 'option_value' => json_encode(['fingerprint' => 'y', 'created' => time() - 90000, 'ttl' => 1800, 'token' => 'old'])]];
$api->purge_expired_leads();
check('cleanup never deletes a reservation that was replaced after it was read', ($GLOBALS['wpdb']->rows['nepa_lead_res_cleanup'] ?? null) === $live_raw);
// Retention > 0: run the same query the job uses, with a 30-day setting.
$ids = get_posts(['post_type' => 'nepa_lead', 'post_status' => ['publish', 'private', 'draft', 'pending', 'future', 'trash'], 'posts_per_page' => 200, 'fields' => 'ids', 'date_query' => [['before' => '30 days ago', 'column' => 'post_date_gmt']]]);
check('the retention query includes trashed leads', in_array($trashed, $ids, true) && in_array($old, $ids, true));
check('the job uses that explicit status list (not "any")', strpos(file_get_contents($copy . '/nepa-api.php'), "'post_status'    => ['publish', 'private', 'draft', 'pending', 'future', 'trash']") !== false);

echo "== Sync and data precedence\n";
$sync = new Nepa_Agent_API_Sync();
$products = ['brand-tracking', 'marketing-mix-modeling', 'campaign-pulse', 'brand-touch', 'brand-asset', 'brand-premium', 'segmentation', 'category-insight', 'path-to-purchase', 'creative-ai', 'category-entry-points'];
$GLOBALS['T']['cpt_posts'] = [
    'product' => array_map(function ($slug) { return (object) ['post_name' => $slug, 'post_title' => ucfirst($slug), 'post_excerpt' => 'generic excerpt', 'post_content' => '', 'link' => "https://nepa.com/products/{$slug}/"]; }, $products),
    'person' => [(object) ['post_name' => 'anders-dahl', 'post_title' => 'Anders Dahl', 'post_content' => 'bio', 'link' => 'https://nepa.com/person/anders-dahl/', 'ID' => 1]],
];
[$line] = invoke($sync, 'sync_simple_cpt', 'team.json', 'person', 'team', function ($p) { return ['id' => 'person-' . $p->post_name, 'name' => $p->post_title, 'url' => $p->link]; });
check('a sync that would shrink team.json from 9 to 1 is refused', strpos($line, 'FAILED') === 0);
invoke($sync, 'discard_staged');
[$line] = invoke($sync, 'sync_simple_cpt', 'products.json', 'product', 'products', function ($p) { return ['id' => 'product-' . $p->post_name, 'name' => $p->post_title, 'category' => null, 'description' => 'generic excerpt', 'url' => $p->link]; });
check('before commit, the bundled file is still served', nepa_agent_api_data_path('products.json') === $copy . '/data/products.json');
invoke($sync, 'commit_staged');
$synced_path = nepa_agent_api_upload_data_dir() . 'products.json';
check('after commit, the synced file is served (manifest names this release)', nepa_agent_api_data_path('products.json') === $synced_path);
$written = json_decode(file_get_contents($synced_path), true);
$ids = array_column($written['products'], 'id');
check('curated landing-page entry (Nepa Trinity) is kept', in_array('product-nepa-trinity', $ids, true) && count($ids) === 12);
$bt = $written['products'][array_search('product-brand-tracking', $ids, true)];
check('curated description is kept over a generic WordPress excerpt', strpos($bt['description'], 'continuously updated') !== false);
check('synced file is stamped with release and synced_at', $written['meta']['release'] === NEPA_DATA_RELEASE && !empty($written['meta']['synced_at']));
touch($copy . '/data/products.json', time() + 3600);
check('a newer timestamp on the bundled file does not change which copy is served', nepa_agent_api_data_path('products.json') === $synced_path);
$manifest_path = nepa_agent_api_upload_data_dir() . 'manifest.json';
$m = json_decode(file_get_contents($manifest_path), true);
$m['release'] = 'agentic-web-2026-09';
file_put_contents($manifest_path, json_encode($m));
nepa_agent_api_sync_manifest(true);
check('synced files from a different release are not served (new release takes effect)', nepa_agent_api_data_path('products.json') === $copy . '/data/products.json');

$before = file_get_contents(nepa_agent_api_data_path('products.json'));
$GLOBALS['T']['cpt_posts']['product'] = [];
$GLOBALS['T']['cpt_posts']['solution'] = [(object) ['post_name' => 'x', 'post_title' => 'X', 'post_excerpt' => '', 'post_content' => '', 'link' => 'https://nepa.com/solutions/x/']];
$log = $sync->run_sync();
check('a FAILED section means no file is replaced (all-or-nothing)', strpos(end($log), 'NOTHING WAS CHANGED') === 0 && file_get_contents(nepa_agent_api_data_path('products.json')) === $before);
check('the sync lock is released afterwards', get_transient('nepa_agent_api_sync_lock') === false);
check('no temporary files are left behind', !glob(nepa_agent_api_upload_data_dir() . '*.tmp-*'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
