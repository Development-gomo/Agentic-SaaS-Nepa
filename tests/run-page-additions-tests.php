<?php
/**
 * Tests for the page additions in nepa-agent-discovery.php 1.3.0, in-process with tests/ard-stubs.php:
 * Offer schema on /pricing/ (from pricing.json), hreflang links in <head> (from WPML, or from
 * localized-pages.json without it) and the <noscript> contact fallback on the contact pages.
 *
 *   php tests/run-page-additions-tests.php
 *
 * Exit code 0 = all passed. Never writes inside the package.
 */
require __DIR__ . '/ard-stubs.php';
$root = dirname(__DIR__);
define('WP_PLUGIN_DIR', $root); // the plugin's data files are read from <WP_PLUGIN_DIR>/nepa-api/data/
require $root . '/nepa-agent-discovery.php';

$pass = 0;
$fail = 0;
function check($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label  $detail\n"; }
}
function head_for($path) {
    $_SERVER['REQUEST_URI'] = $path;
    ob_start();
    do_action('wp_head');
    return ob_get_clean();
}
function hook_out($hook, $path) {
    $_SERVER['REQUEST_URI'] = $path;
    ob_start();
    do_action($hook);
    return ob_get_clean();
}
function hreflangs($html) {
    preg_match_all('#<link rel="alternate" hreflang="([^"]+)" href="([^"]+)" />#', $html, $m);
    return array_combine($m[1], $m[2]);
}

/* ---------------- 1. price strings ---------------- */
$s = nepa_ad_price_specs('From 1,500 EUR/month (16,300 SEK/month)');
check('monthly price -> EUR and SEK UnitPriceSpecification, minPrice as integers', count($s) === 2 && $s[0] === ['@type' => 'UnitPriceSpecification', 'priceCurrency' => 'EUR', 'minPrice' => 1500, 'unitText' => 'MONTH', 'unitCode' => 'MON'] && $s[1]['priceCurrency'] === 'SEK' && $s[1]['minPrice'] === 16300, json_encode($s));
$s = nepa_ad_price_specs('From 40,000 EUR (434,000 SEK) one-time + 2,500 EUR (27,100 SEK)/month');
check('one-time + monthly price -> one-time fee specs and monthly specs', count($s) === 4 && $s[0]['@type'] === 'PriceSpecification' && $s[0]['minPrice'] === 40000 && $s[2]['@type'] === 'UnitPriceSpecification' && $s[2]['minPrice'] === 2500 && $s[3]['minPrice'] === 27100, json_encode($s));
$s = nepa_ad_price_specs('From 33,000 EUR (358,100 SEK) one-time');
check('one-time price -> two PriceSpecification, no unit', count($s) === 2 && !isset($s[0]['unitText']) && $s[1]['minPrice'] === 358100);
check('"Price on request" -> no price', nepa_ad_price_specs('Price on request') === []);

/* ---------------- 2. Offer schema on /pricing/ ---------------- */
$pricing = json_decode(file_get_contents($root . '/nepa-api/data/pricing.json'), true);
$schema = nepa_ad_pricing_schema();
$offers = array_merge(...array_map(function ($g) { return $g['itemListElement']; }, $schema['itemListElement']));
check('OfferCatalog with one catalog per product (Brand Tracking, MMM, Campaign Pulse)', $schema['@type'] === 'OfferCatalog' && array_column($schema['itemListElement'], 'name') === ['Brand Tracking', 'Marketing Mix Modelling', 'Campaign Pulse']);
check('one Offer per pricing.json plan, same names and order', array_column($offers, 'name') === array_column($pricing['plans'], 'tier'));
$priced = array_filter($pricing['plans'], function ($p) { return stripos($p['price'], 'EUR') !== false; });
check('every plan with a published price has price, priceCurrency EUR and priceSpecification', count(array_filter($offers, function ($o) { return isset($o['priceSpecification'], $o['price']) && $o['priceCurrency'] === 'EUR'; })) === count($priced));
$byName = [];
foreach ($offers as $o) { $byName[$o['itemOffered']['name'] . '/' . $o['name']] = $o; }
check('Brand Tracking Mini: from 1,500 EUR/month (16,300 SEK)', $byName['Brand Tracking/Brand Tracking Mini']['price'] === 1500 && $byName['Brand Tracking/Brand Tracking Mini']['priceSpecification'][1]['minPrice'] === 16300);
check('Enterprise plans: no price, "Price on request." in the description', !isset($byName['Campaign Pulse/Enterprise']['price']) && substr($byName['Campaign Pulse/Enterprise']['description'], -17) === 'Price on request.');
check('offers name the product page and the seller', $byName['Campaign Pulse/Campaign Pulse Basic']['itemOffered']['url'] === 'https://nepa.com/products/campaign-pulse/' && $byName['Campaign Pulse/Campaign Pulse Basic']['seller'] === ['@id' => 'https://nepa.com/#organization']);
$h = head_for('/pricing/');
preg_match('#<script type="application/ld\+json" id="nepa-pricing-offers">(.*?)</script>#s', $h, $m);
check('/pricing/ <head> carries the Offer JSON-LD, valid JSON equal to the schema', isset($m[1]) && json_decode($m[1], true) === json_decode(json_encode($schema), true));
check('/pricing (no trailing slash) also gets it', strpos(head_for('/pricing'), 'nepa-pricing-offers') !== false);
check('other pages do not', strpos(head_for('/about/'), 'nepa-pricing-offers') === false && strpos(head_for('/products/brand-tracking/'), 'nepa-pricing-offers') === false);

/* ---------------- 3. hreflang without WPML (localized-pages.json) ---------------- */
$want = ['da' => 'https://nepa.com/da/contact/', 'en' => 'https://nepa.com/contact-us/', 'fi' => 'https://nepa.com/fi/contact/', 'sv' => 'https://nepa.com/sv/kontakta-oss/', 'x-default' => 'https://nepa.com/contact-us/'];
check('/contact-us/: hreflang en, sv, fi, da and x-default', hreflangs(head_for('/contact-us/')) === $want, json_encode(hreflangs(head_for('/contact-us/'))));
check('/sv/kontakta-oss/: the same set (each version lists all, itself included)', hreflangs(head_for('/sv/kontakta-oss/')) === $want);
$home = hreflangs(head_for('/'));
check('home page: en, sv, fi, da', array_keys($home) === ['da', 'en', 'fi', 'sv', 'x-default'] && $home['sv'] === 'https://nepa.com/sv/', json_encode($home));
check('a page without language versions gets no hreflang', hreflangs(head_for('/investor-relations/')) === []);

/* ---------------- 4. hreflang with WPML ---------------- */
add_filter('wpml_active_languages', function () {
    return [
        'en' => ['code' => 'en', 'url' => 'https://nepa.com/pricing/', 'missing' => 0],
        'sv' => ['code' => 'sv', 'url' => 'https://nepa.com/sv/priser/', 'missing' => 0],
        'fi' => ['code' => 'fi', 'url' => 'https://nepa.com/fi/', 'missing' => 1],
    ];
});
check('WPML: links from its language data, untranslated (missing) languages left out', hreflangs(head_for('/pricing/')) === ['en' => 'https://nepa.com/pricing/', 'sv' => 'https://nepa.com/sv/priser/', 'x-default' => 'https://nepa.com/pricing/']);
add_filter('wpml_setting', function ($v, $key = '') { return $key === 'seo' ? ['head_langs' => 1] : $v; }, 10, 2);
check('WPML already printing hreflang (SEO option on): nothing added, no duplicates', hreflangs(head_for('/pricing/')) === []);
remove_all_filters('wpml_setting');
remove_all_filters('wpml_active_languages');
add_filter('wpml_active_languages', function () { return ['en' => ['code' => 'en', 'url' => 'https://nepa.com/about/', 'missing' => 0]]; });
check('WPML with one language only: no hreflang', hreflangs(head_for('/about/')) === []);
remove_all_filters('wpml_active_languages');

/* ---------------- 5. <noscript> contact fallback ---------------- */
check('not on other pages', hook_out('wp_body_open', '/about/') === '' && hook_out('wp_footer', '/pricing/') === '');
$ns = hook_out('wp_body_open', '/contact-us/');
check('/contact-us/: <noscript> block right after <body> (wp_body_open)', strpos($ns, '<noscript><section class="nepa-noscript-contact"') === 0 && substr(trim($ns), -11) === '</noscript>');
check('it names email, phone, headquarters and the agent contact endpoint', strpos($ns, 'mailto:info@nepa.com') !== false && strpos($ns, 'href="tel:+46840026800"') !== false && strpos($ns, 'Magnus Ladul') !== false && strpos($ns, 'POST https://nepa.com/agent/v1/contact.json') !== false && strpos($ns, 'submit_contact_inquiry') !== false);
check('printed once: wp_footer adds no second copy', hook_out('wp_footer', '/contact-us/') === '');
check('localized contact pages are covered', nepa_ad_contact_paths() === ['/contact-us/', '/sv/kontakta-oss/', '/fi/contact/', '/da/contact/']);
$loc = json_decode(file_get_contents($root . '/nepa-api/data/localized-pages.json'), true);
$contact = array_map(function ($p) { return parse_url($p['url'], PHP_URL_PATH); }, array_filter($loc['pages'], function ($p) { return $p['section'] === 'contact'; }));
check('the contact path list matches localized-pages.json (section "contact")', !array_diff($contact, nepa_ad_contact_paths()), json_encode(array_values($contact)));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
