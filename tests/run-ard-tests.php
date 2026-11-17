<?php
/**
 * Tests for Agentic Resource Discovery (ARD) support:
 * agentic-resource-discovery.php (generic module) + nepa-agent-discovery.php (nepa.com entries).
 *
 * Usage (from the package root; needs PHP 7.4+ CLI, which includes the built-in web server):
 *   php tests/run-ard-tests.php
 *
 * 1. Module logic in-process: entry rules, invalid/duplicate entries left out, config filter, JSON-LD.
 * 2. Real HTTP via `php -S` with tests/ard-router.php: /.well-known/ard.json status codes, headers,
 *    HEAD/OPTIONS/POST, <link rel="ard"> and the Link header on pages, silence without entries,
 *    ARD cross-links in the API catalog and MCP card.
 * 3. Package consistency: the agent page's embedded ARD entries equal the served manifest, every
 *    discovery file points to the manifest, and versions/tool lists match the artifacts they describe.
 * The served manifest is also written to the path in $ARD_MANIFEST_OUT (if set) for validate-ard-manifest.py.
 * Exit code 0 = all passed. Never writes inside the package.
 */
require __DIR__ . '/ard-stubs.php';
$root = dirname(__DIR__);
ini_set('error_log', sys_get_temp_dir() . '/ard-tests-' . getmypid() . '.log');
require $root . '/agentic-resource-discovery.php';
require $root . '/nepa-agent-discovery.php';

$pass = 0;
$fail = 0;
function check($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label  $detail\n"; }
}

/* ---------------- 1. module logic ---------------- */
$entries = agentic_ard_entries();
$ids = array_column($entries, 'identifier');
check('5 nepa.com entries published', count($entries) === 5, json_encode($ids));
check('identifiers are unique', count(array_unique($ids)) === count($ids));
$clean = true;
foreach ($entries as $e) {
    $c = agentic_ard_check_entry($e, 'nepa.com');
    if ($c['errors'] || $c['warnings']) { $clean = false; echo '      ' . $e['identifier'] . ': ' . json_encode($c) . "\n"; }
}
check('every entry passes the entry rules with no warnings', $clean);
check('every entry carries updatedAt from the release date', count(array_filter($entries, function ($e) {
    return ($e['updatedAt'] ?? '') === NEPA_AD_ARD_UPDATED;
})) === 5);
check('publisher is nepa.com', agentic_ard_config()['publisher'] === 'nepa.com');

$bad = [
    'missing type'      => ['identifier' => 'urn:air:nepa.com:x:a', 'displayName' => 'A', 'url' => 'https://nepa.com/a'],
    'url and data'      => ['identifier' => 'urn:air:nepa.com:x:b', 'displayName' => 'B', 'type' => 'text/html', 'url' => 'https://nepa.com/b', 'data' => ['a' => 1]],
    'neither url/data'  => ['identifier' => 'urn:air:nepa.com:x:c', 'displayName' => 'C', 'type' => 'text/html'],
    'not a URN'         => ['identifier' => 'https://nepa.com/d', 'displayName' => 'D', 'type' => 'text/html', 'url' => 'https://nepa.com/d'],
    'other publisher'   => ['identifier' => 'urn:air:example.com:x:e', 'displayName' => 'E', 'type' => 'text/html', 'url' => 'https://nepa.com/e'],
    'relative url'      => ['identifier' => 'urn:air:nepa.com:x:f', 'displayName' => 'F', 'type' => 'text/html', 'url' => '/f'],
    'bad media type'    => ['identifier' => 'urn:air:nepa.com:x:g', 'displayName' => 'G', 'type' => 'html', 'url' => 'https://nepa.com/g'],
    'trust w/o identity'=> ['identifier' => 'urn:air:nepa.com:x:h', 'displayName' => 'H', 'type' => 'text/html', 'url' => 'https://nepa.com/h', 'trustManifest' => ['attestations' => []]],
    'trust of other domain' => ['identifier' => 'urn:air:nepa.com:x:t', 'displayName' => 'T', 'type' => 'text/html', 'url' => 'https://nepa.com/t', 'trustManifest' => ['identity' => 'did:web:evil.example']],
    'trust lookalike domain' => ['identifier' => 'urn:air:nepa.com:x:u', 'displayName' => 'U', 'type' => 'text/html', 'url' => 'https://nepa.com/u', 'trustManifest' => ['identity' => 'https://notnepa.com']],
    'data as a list'    => ['identifier' => 'urn:air:nepa.com:x:l', 'displayName' => 'L', 'type' => 'application/json', 'data' => [1, 2]],
    'data empty'        => ['identifier' => 'urn:air:nepa.com:x:e2', 'displayName' => 'E', 'type' => 'application/json', 'data' => []],
    'description not a string' => ['identifier' => 'urn:air:nepa.com:x:d', 'displayName' => 'D', 'type' => 'text/html', 'url' => 'https://nepa.com/d', 'description' => ['a']],
    'version not a string' => ['identifier' => 'urn:air:nepa.com:x:v', 'displayName' => 'V', 'type' => 'text/html', 'url' => 'https://nepa.com/v', 'version' => 2],
    'updatedAt not a date-time' => ['identifier' => 'urn:air:nepa.com:x:w', 'displayName' => 'W', 'type' => 'text/html', 'url' => 'https://nepa.com/w', 'updatedAt' => '16/11/2026'],
    'metadata as a list' => ['identifier' => 'urn:air:nepa.com:x:m', 'displayName' => 'M', 'type' => 'text/html', 'url' => 'https://nepa.com/m', 'metadata' => ['a', 'b']],
    'nested metadata'   => ['identifier' => 'urn:air:nepa.com:x:i', 'displayName' => 'I', 'type' => 'text/html', 'url' => 'https://nepa.com/i', 'metadata' => ['a' => ['b' => 1]]],
    'not an object'     => 'string entry',
];
foreach ($bad as $label => $e) {
    check("rejects entry: $label", (bool) agentic_ard_check_entry($e, 'nepa.com')['errors']);
}
$warn = agentic_ard_check_entry(['identifier' => 'urn:air:nepa.com:x:w', 'displayName' => 'W', 'type' => 'text/html', 'url' => 'https://nepa.com/w', 'representativeQueries' => ['one']], 'nepa.com');
foreach (['https://nepa.com', 'did:web:nepa.com', 'spiffe://nepa.com/mcp', 'api.nepa.com'] as $okid) {
    check("trust identity $okid aligns with nepa.com", !agentic_ard_check_entry(['identifier' => 'urn:air:nepa.com:x:ok', 'displayName' => 'OK', 'type' => 'text/html', 'url' => 'https://nepa.com/ok', 'representativeQueries' => ['a', 'b'], 'trustManifest' => ['identity' => $okid]], 'nepa.com')['errors']);
}
check('inline data as an object is accepted', !agentic_ard_check_entry(['identifier' => 'urn:air:nepa.com:x:o', 'displayName' => 'O', 'type' => 'application/mcp-server-card+json', 'data' => ['name' => 'x'], 'representativeQueries' => ['a', 'b'], 'updatedAt' => '2026-11-16T08:00:00+01:00'], 'nepa.com')['errors']);
check('1 representative query is a warning, not an error', !$warn['errors'] && $warn['warnings']);
check('namespaced @context term is allowed (open vocabulary)', !agentic_ard_check_entry(['@context' => ['acme' => 'https://acme.example/vocab#'], 'identifier' => 'urn:air:nepa.com:x:n', 'displayName' => 'N', 'type' => 'text/html', 'url' => 'https://nepa.com/n', 'acme:tier' => 'gold', 'representativeQueries' => ['a', 'b']], 'nepa.com')['errors']);

add_filter('agentic_ard_entries', function ($list) use ($bad) {
    return array_merge($list, array_values($bad), [$list[0] ?? []]);
}, 20);
$filtered = agentic_ard_entries();
check('invalid and duplicate entries are left out of the manifest', count($filtered) === 5);
check('left-out entries are logged', substr_count((string) @file_get_contents(ini_get('error_log')), 'ARD:') >= count($bad) + 1);
remove_all_filters('agentic_ard_entries');
check('no entries -> empty manifest', agentic_ard_manifest()['entries'] === []);
add_filter('agentic_ard_entries', function ($list) { return array_merge($list, nepa_ad_ard_entries()); });

$ld = agentic_ard_jsonld();
check('JSON-LD entries carry the ARD base context', count(array_filter($ld, function ($e) {
    return ($e['@context'] ?? '') === AGENTIC_ARD_CONTEXT;
})) === 5);
add_filter('agentic_ard_config', function ($c) { $c['embed_context'] = false; return $c; }, 99);
check('embed_context=false omits @context', !isset(agentic_ard_jsonld()[0]['@context']));
unset($GLOBALS['ARD_T']['filters']['agentic_ard_config'][99]);
$m = agentic_ard_manifest();
check('manifest has specVersion 0.91 and host', $m['specVersion'] === '0.91' && $m['host']['identifier'] === 'https://nepa.com');

/* ---------------- 2. real HTTP ---------------- */
function serve($env = []) {
    $port = 18000 + (getmypid() % 1000) + count($GLOBALS['servers'] ?? []);
    $cmd = (count($env) ? 'env ' . implode(' ', array_map(function ($k, $v) {
        return $k . '=' . escapeshellarg($v);
    }, array_keys($env), $env)) . ' ' : '') . escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg(__DIR__ . '/ard-router.php');
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname(__DIR__));
    $GLOBALS['servers'][] = $p;
    for ($i = 0; $i < 50; $i++) {
        if (@fsockopen('127.0.0.1', $port)) { break; }
        usleep(100000);
    }
    return "http://127.0.0.1:$port";
}
function req($url, $method = 'GET', $headers = []) {
    $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 10, 'header' => implode("\r\n", $headers)]]);
    $body = @file_get_contents($url, false, $ctx);
    $h = $http_response_header ?? [];
    preg_match('#HTTP/\S+ (\d+)#', $h[0] ?? '', $mm);
    $headers = [];
    foreach (array_slice($h, 1) as $line) {
        [$k, $v] = array_map('trim', explode(':', $line, 2) + [1 => '']);
        $headers[strtolower($k)][] = $v;
    }
    return ['status' => (int) ($mm[1] ?? 0), 'headers' => $headers, 'body' => (string) $body];
}
register_shutdown_function(function () {
    foreach ($GLOBALS['servers'] ?? [] as $p) { proc_terminate($p); }
});

$base = serve();
$r = req("$base/.well-known/ard.json");
$served = json_decode($r['body'], true);
check('GET /.well-known/ard.json -> 200', $r['status'] === 200, (string) $r['status']);
check('manifest content type is application/json', strpos($r['headers']['content-type'][0] ?? '', 'application/json') === 0);
check('manifest allows cross-origin reads', ($r['headers']['access-control-allow-origin'][0] ?? '') === '*');
check('manifest is cacheable for an hour', ($r['headers']['cache-control'][0] ?? '') === 'public, max-age=3600');
check('manifest has an ETag', !empty($r['headers']['etag'][0]));
$c = req("$base/.well-known/ard.json", 'GET', ['If-None-Match: ' . ($r['headers']['etag'][0] ?? 'x')]);
check('If-None-Match with the current ETag -> 304 without a body', $c['status'] === 304 && $c['body'] === '');
check('a stale ETag -> 200', req("$base/.well-known/ard.json", 'GET', ['If-None-Match: "stale"'])['status'] === 200);
check('manifest response carries Link rel="ard"', in_array('<https://nepa.com/.well-known/ard.json>; rel="ard"', $r['headers']['link'] ?? [], true));
check('served manifest equals agentic_ard_manifest()', $served === json_decode(json_encode(agentic_ard_manifest()), true));
check('trailing slash is served too', req("$base/.well-known/ard.json/")['status'] === 200);
$h = req("$base/.well-known/ard.json", 'HEAD');
check('HEAD -> 200 without a body', $h['status'] === 200 && $h['body'] === '');
$o = req("$base/.well-known/ard.json", 'OPTIONS');
check('OPTIONS preflight -> 204 with CORS', $o['status'] === 204 && ($o['headers']['access-control-allow-methods'][0] ?? '') === 'GET, HEAD, OPTIONS');
$p = req("$base/.well-known/ard.json", 'POST');
check('POST -> 405 with Allow', $p['status'] === 405 && ($p['headers']['allow'][0] ?? '') === 'GET, HEAD, OPTIONS');
$page = req("$base/");
check('pages carry <link rel="ard"> in <head>', strpos($page['body'], '<link rel="ard" type="application/json" href="https://nepa.com/.well-known/ard.json" />') !== false);
check('pages send a Link rel="ard" header', in_array('<https://nepa.com/.well-known/ard.json>; rel="ard"', $page['headers']['link'] ?? [], true));
check('existing discovery links are still printed', strpos($page['body'], 'rel="api-catalog"') !== false && strpos($page['body'], 'title="llms.txt"') !== false);
$cat = json_decode(req("$base/.well-known/api-catalog")['body'], true);
check('RFC 9727 API catalog lists the ARD manifest', in_array('https://nepa.com/.well-known/ard.json', array_column($cat['linkset'][0]['item'] ?? [], 'href'), true));
$card = json_decode(req("$base/.well-known/mcp.json")['body'], true);
check('MCP card links the ARD manifest and keeps its endpoint', ($card['ard'] ?? '') === 'https://nepa.com/.well-known/ard.json' && $card['endpoint'] === NEPA_AD_MCP_URL);

$sec = req("$base/.well-known/security.txt");
check('/.well-known/security.txt -> 200 text/plain', $sec['status'] === 200 && strpos($sec['headers']['content-type'][0] ?? '', 'text/plain') === 0);
check('security.txt has Contact, Expires, Encryption, Policy and Canonical (RFC 9116)', strpos($sec['body'], "Contact: mailto:security@nepa.com\n") !== false && preg_match('/^Expires: (\S+)$/m', $sec['body'], $em) && strpos($sec['body'], 'Encryption: openpgp4fpr:477882f5161a97ffd06ef166bfcd587ab30ba567') !== false && strpos($sec['body'], 'Policy: https://nepa.com/security/') !== false && strpos($sec['body'], 'Canonical: https://nepa.com/.well-known/security.txt') !== false);
$exp = isset($em[1]) ? strtotime($em[1]) : 0;
check('security.txt Expires is in the future and less than 13 months away (renew it with the next package build)', $exp > time() && $exp < time() + 400 * 86400, $em[1] ?? 'none');
check('legacy /security.txt serves the same file', req("$base/security.txt")['body'] === $sec['body']);
check('pages link llms-full.txt in <head>', strpos($page['body'], 'title="llms-full.txt" href="https://nepa.com/llms-full.txt"') !== false);
check("Yoast's SearchAction to /?s= is switched off", !empty($GLOBALS['ARD_T']['filters']['disable_wpseo_json_ld_search']));

$silent = serve(['ARD_TEST_NO_ENTRIES' => '1']);
check('without entries the manifest URL falls through to 404', req("$silent/.well-known/ard.json")['status'] === 404);
check('without entries OPTIONS and POST fall through to 404 too', req("$silent/.well-known/ard.json", 'OPTIONS')['status'] === 404 && req("$silent/.well-known/ard.json", 'POST')['status'] === 404);
$sp = req("$silent/");
check('without entries no link tag or Link header', strpos($sp['body'], 'rel="ard"') === false && empty($sp['headers']['link']));
$other = serve(['ARD_TEST_HOME' => 'https://www.example.org']);
$om = json_decode(req("$other/.well-known/ard.json")['body'], true);
check('URLs follow the site\'s home option (reusable on another host)', strpos($om['entries'][0]['url'] ?? '', 'https://www.example.org/') === 0);
$sv = serve(['ARD_TEST_LANG_PREFIX' => '/sv']);
$svp = req("$sv/sv/");
preg_match_all('#<link rel="[^"]+"[^>]*href="([^"]+)"#', $svp['body'], $lm);
$roots = array_filter($lm[1], function ($u) { return strpos($u, 'format=md') === false; });
check('localized page (language-prefixed home_url): discovery links stay at the site root', count($roots) >= 5 && !array_filter($roots, function ($u) {
    return strpos($u, '/sv/') !== false || strpos(substr($u, 8), '//') !== false;
}), json_encode(array_values($roots)));
check('localized page: <link rel="ard"> and Link header point to the root manifest', strpos($svp['body'], 'href="https://nepa.com/.well-known/ard.json"') !== false && in_array('<https://nepa.com/.well-known/ard.json>; rel="ard"', $svp['headers']['link'] ?? [], true));
$svm = json_decode(req("$sv/.well-known/ard.json")['body'], true);
check('manifest URLs carry no language prefix when home_url() is filtered', $svm === $served);

if ($out = getenv('ARD_MANIFEST_OUT')) {
    file_put_contents($out, $r['body']);
}

/* ---------------- 3. package consistency ---------------- */
$agent = file_get_contents($root . '/nepa-api/agent-page.html');
preg_match('#<script type="application/ld\+json" id="ard-entries">(.*?)</script>#s', $agent, $mm);
$embedded = json_decode($mm[1] ?? 'null', true);
check('agent page embeds the ARD entries as JSON-LD', is_array($embedded) && count($embedded) === 5);
check('agent page ARD JSON-LD equals agentic_ard_jsonld()', $embedded === json_decode(json_encode(agentic_ard_jsonld()), true));
check('agent page has <link rel="ard">', strpos($agent, '<link rel="ard" type="application/json" href="https://nepa.com/.well-known/ard.json">') !== false);
check('robots-addition.txt has the Agentmap directive', strpos(file_get_contents($root . '/robots-addition.txt'), "Agentmap: https://nepa.com/.well-known/ard.json") !== false);
foreach (['llms.txt', 'ai.txt', 'api-catalog.json'] as $f) {
    check("$f points to the ARD manifest", strpos(file_get_contents($root . '/' . $f), 'https://nepa.com/.well-known/ard.json') !== false);
}
preg_match_all('#\{ name: "([a-z_]+)", phase:#', file_get_contents($root . '/nepa-mcp/src/index.ts'), $tm);
check('MCP entry capabilities equal the MCP server tool list', $tm[1] === NEPA_AD_MCP_TOOLS, json_encode(array_diff($tm[1], NEPA_AD_MCP_TOOLS)));
$byId = array_column($entries, null, 'identifier');
check('MCP entry version equals nepa-mcp package.json', $byId['urn:air:nepa.com:mcp:nepa-mcp']['version'] === json_decode(file_get_contents($root . '/nepa-mcp/package.json'), true)['version']);
check('REST entry version equals openapi.json info.version', $byId['urn:air:nepa.com:api:agent-rest-api']['version'] === json_decode(file_get_contents($root . '/nepa-api/openapi.json'), true)['info']['version']);

echo "\n$pass passed, $fail failed\n";
@unlink(ini_get('error_log'));
exit($fail ? 1 : 0);
