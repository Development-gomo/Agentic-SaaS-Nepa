<?php
/**
 * Tests for the ?format=md Markdown versions served by nepa-agent-discovery.php (1.2.0).
 *
 *   php tests/run-markdown-tests.php
 *
 * 1. nepa_ad_main_content_html() + nepa_ad_html_to_md() on tests/fixtures/enfold-page.php, a page in
 *    the shape of nepa.com's Enfold theme: content after </main> is kept, the site header/footer,
 *    scripts, iframes, forms and buttons are left out, the title H1 is not repeated.
 * 2. The template_include hook over real HTTP (php -S + tests/md-hook-probe.php): Markdown headers,
 *    front matter, body, caching, and non-Markdown requests left alone.
 * Exit code 0 = all passed.
 */
require __DIR__ . '/ard-stubs.php';
function wp_strip_all_tags($s) { return trim(strip_tags($s)); }
require dirname(__DIR__) . '/agentic-resource-discovery.php';
require dirname(__DIR__) . '/nepa-agent-discovery.php';

$pass = 0; $fail = 0;
function check($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label  $detail\n"; }
}

echo "== Extraction and conversion\n";
ob_start(); require __DIR__ . '/fixtures/enfold-page.php'; $page = ob_get_clean();
$md = nepa_ad_html_to_md(nepa_ad_main_content_html($page, 'Brand Tracking'));
check('content before <main> is kept', strpos($md, 'Prices start at 15.000 SEK/month.') !== false);
check('content inside <main> is kept', strpos($md, '## Inside main') !== false && strpos($md, 'First section text.') !== false);
check('content AFTER </main> is kept (Enfold puts most sections there)', strpos($md, '## Smarter brand tracking') !== false && strpos($md, 'Last content section.') !== false);
check('lists and tables are converted', strpos($md, '- Measure brand awareness') !== false && strpos($md, '| Mini | 1,500 EUR |') !== false);
check('links become absolute Markdown links', strpos($md, '[pricing link](https://nepa.com/pricing/)') !== false);
check('bold text is kept', strpos($md, '**after**') !== false);
check('the H1 that repeats the title is dropped (no "## Brand Tracking" heading)', !preg_match('/^#+ Brand Tracking$/m', $md));
check('an H1 that differs from the title is kept as a heading', strpos(nepa_ad_html_to_md(nepa_ad_main_content_html('<div id="main"><h1>Another heading</h1><p>x</p></div>', 'Brand Tracking')), '## Another heading') !== false);
check('site header and its menu are left out', strpos($md, 'HEADER MENU ITEM') === false);
check('site footer menu, widgets and socket are left out', strpos($md, 'Whistleblower Policy') === false && strpos($md, 'FOOTER WIDGETS') === false && strpos($md, 'SOCKET') === false);
check('scripts (including text/html templates) and styles are left out', strpos($md, 'not content') === false && strpos($md, 'TEMPLATE SCRIPT TEXT') === false && strpos($md, 'nitroBeacon') === false && strpos($md, 'color:red') === false);
check('iframe attributes do not leak as text', strpos($md, 'referrerpolicy') === false && strpos($md, 'picture-in-picture') === false);
check('forms and buttons are left out', strpos($md, 'FORM LABEL') === false && strpos($md, 'BUTTON TEXT') === false);
check('no page without a #main: falls back to <main>', strpos(nepa_ad_html_to_md(nepa_ad_main_content_html('<html><body><header id="header">H</header><main><p>Only main</p></main></body></html>')), 'Only main') !== false);
check('empty page -> empty string', nepa_ad_main_content_html('') === '');

echo "== The ?format=md request (real HTTP)\n";
$port = 19000 + (getmypid() % 900);
$proc = proc_open(escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg(__DIR__ . '/md-hook-probe.php'), [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname(__DIR__));
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
function req($url) {
    $body = @file_get_contents($url, false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]));
    $h = [];
    foreach (array_slice($http_response_header ?? [], 1) as $l) { [$k, $v] = array_map('trim', explode(':', $l, 2) + [1 => '']); $h[strtolower($k)][] = $v; }
    preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
    return [(int) ($m[1] ?? 0), $h, (string) $body];
}
[$s, $h, $b] = req("http://127.0.0.1:$port/?format=md");
check('?format=md -> 200 text/markdown', $s === 200 && strpos($h['content-type'][0] ?? '', 'text/markdown') === 0, ($h['content-type'][0] ?? '') . " $s");
check('noindex, canonical link and CORS headers', ($h['x-robots-tag'][0] ?? '') === 'noindex, follow' && strpos(implode(' ', $h['link'] ?? []), 'rel="canonical"') !== false && ($h['access-control-allow-origin'][0] ?? '') === '*');
check('front matter with title, url, description and last_modified', strpos($b, "---\ntitle: \"Brand Tracking\"\nurl: https://nepa.com/\ndescription: \"Continuous brand tracking from Nepa.\"\nlast_modified: 2026-08-13T05:22:54+00:00") === 0, substr($b, 0, 160));
check('body has the page content after the title (not just the title)', strpos($b, "# Brand Tracking\n\n") !== false && strpos($b, 'Last content section.') !== false && strlen($b) > 400);
check('the page template was rendered once', in_array('1', $h['x-test-renders'] ?? [], true));
[$s, $h, $b] = req("http://127.0.0.1:$port/?format=md&warm=1");
check('a cached Markdown version is served without rendering the page again', $b === "CACHED MARKDOWN\n" && !in_array('1', $h['x-test-renders'] ?? [], true));
[$s, $h, $b] = req("http://127.0.0.1:$port/?format=html");
check('other requests are left to the theme', strpos($b, 'TEMPLATE LEFT ALONE: enfold-page.php') !== false);
proc_terminate($proc);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
