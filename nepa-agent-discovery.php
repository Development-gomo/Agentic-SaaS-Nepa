<?php
/**
 * Plugin Name: Nepa Agent Discovery
 * Description: Agent discovery layer for nepa.com — /.well-known/api-catalog (RFC 9727), /.well-known/mcp.json,
 *              /.well-known/security.txt (RFC 9116), <head> discovery links, Markdown versions of pages
 *              via ?format=md, and nepa.com's Agentic Resource Discovery (ARD) entries for /.well-known/ard.json.
 * Version:     1.2.0
 * Author:      GO MO Group
 *
 * Install: upload to wp-content/mu-plugins/nepa-agent-discovery.php (must-use plugins load automatically),
 *          together with agentic-resource-discovery.php, which serves the ARD manifest from the entries below.
 * After deploy: purge WP Engine cache + NitroPack cache so the new <head> links appear on cached pages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const NEPA_AD_VERSION = '1.2.0';
const NEPA_AD_MCP_URL = 'https://agentic-mcp-saas-nepa.onrender.com/mcp';

/**
 * security.txt (RFC 9116) values, from https://nepa.com/security/ (contact address and PGP key
 * fingerprint published there). Expires must be renewed before it passes - at the latest with the
 * next package build; RFC 9116 recommends less than a year ahead.
 */
const NEPA_AD_SECURITY_CONTACT = 'mailto:security@nepa.com';
const NEPA_AD_SECURITY_PGP_FPR = '477882F5161A97FFD06EF166BFCD587AB30BA567';
const NEPA_AD_SECURITY_EXPIRES = '2027-11-15T23:00:00Z';

/**
 * Site root URL plus $path, the same for every language. home_url() is filtered by the multilingual
 * plugin to the language being viewed (https://nepa.com/sv on Swedish pages), which made 1.0.0 print
 * broken discovery links such as https://nepa.com/sv//llms.txt on every /sv/, /fi/ and /da/ page.
 */
function nepa_ad_url( string $path = '' ): string {
	return rtrim( (string) get_option( 'home' ), '/' ) . $path;
}

/* -------------------------------------------------------------------------
 * 0. nepa.com's ARD entries (served by agentic-resource-discovery.php)
 * ---------------------------------------------------------------------- */

/** Date of the package release the entries describe; also each entry's updatedAt. */
const NEPA_AD_ARD_UPDATED = '2026-11-16T00:00:00Z';

/** The MCP server's tool names (nepa-mcp 1.2.1). Kept in step with nepa-mcp/src/index.ts by tests/run-ard-tests.php. */
const NEPA_AD_MCP_TOOLS = array(
	'get_company', 'list_products', 'list_solutions', 'list_industries', 'list_case_studies', 'get_case_study',
	'list_team', 'list_offices', 'list_jobs', 'list_events', 'list_company_news', 'list_resources', 'list_pricing',
	'list_blog_posts', 'list_press_releases', 'list_localized_pages', 'get_investor_relations', 'submit_contact_inquiry',
);

add_filter( 'agentic_ard_config', function ( array $cfg ) {
	$cfg['publisher']  = 'nepa.com';
	$cfg['updated_at'] = NEPA_AD_ARD_UPDATED;
	$cfg['host']       = array(
		'displayName'      => 'Nepa AB (publ)',
		'identifier'       => 'https://nepa.com',
		'documentationUrl' => nepa_ad_url( '/agent/' ),
	);
	return $cfg;
} );

add_filter( 'agentic_ard_entries', function ( array $entries ) {
	return array_merge( $entries, nepa_ad_ard_entries() );
} );

/** nepa.com's agentic resources as ARD entries (spec v0.91 §4). */
function nepa_ad_ard_entries(): array {
	$home = nepa_ad_url();
	$tags = array( 'marketing-research', 'brand-tracking', 'marketing-mix-modelling', 'campaign-evaluation', 'nordics' );
	return array(
		array(
			'identifier'            => 'urn:air:nepa.com:mcp:nepa-mcp',
			'displayName'           => 'Nepa MCP server',
			'type'                  => 'application/mcp-server-card+json',
			'url'                   => $home . '/.well-known/mcp.json',
			'description'           => 'Model Context Protocol server (Streamable HTTP, no authentication) for Nepa AB, the Swedish marketing research company: company profile, products, solutions, case studies, pricing, leadership, offices, events, blog posts, press releases, investor relations and Swedish/Finnish/Danish pages, plus submit_contact_inquiry for B2B inquiries that a person has explicitly authorized.',
			'capabilities'          => NEPA_AD_MCP_TOOLS,
			'tags'                  => array_merge( $tags, array( 'mcp', 'agent-actionable' ) ),
			'version'               => '1.2.1',
			'representativeQueries' => array(
				'what does Nepa\'s brand tracking cost per month',
				'find a Nordic marketing research agency with marketing mix modelling case studies',
				'list Nepa\'s latest press releases and interim reports',
				'who leads Nepa and where are its offices',
				'send a sales inquiry to Nepa on my behalf',
			),
			'metadata'              => array(
				'endpoint'  => NEPA_AD_MCP_URL,
				'transport' => 'streamable-http',
				'policy'    => $home . '/ai.txt',
			),
		),
		array(
			'identifier'            => 'urn:air:nepa.com:api:agent-rest-api',
			'displayName'           => 'Nepa Agent REST API (OpenAPI)',
			'type'                  => 'application/vnd.oai.openapi+json',
			'url'                   => $home . '/openapi.json',
			'description'           => 'OpenAPI 3.0.3 description of Nepa\'s read-only JSON endpoints under /agent/v1/ (company, products, solutions, industries, case studies, team, offices, jobs, events, blog posts, press releases, company news, resources, pricing, investor relations, localized pages) and the consent-gated POST /agent/v1/contact.json inquiry endpoint.',
			'capabilities'          => array(
				'company', 'products', 'solutions', 'industries', 'case-studies', 'team', 'offices', 'jobs', 'events',
				'blog-posts', 'press-releases', 'company-news', 'resources', 'pricing', 'investor-relations',
				'localized-pages', 'contact',
			),
			'tags'                  => array_merge( $tags, array( 'rest-api', 'openapi' ) ),
			'version'               => '1.4.0',
			'representativeQueries' => array(
				'get Nepa\'s published pricing plans as JSON',
				'fetch Nepa case studies filtered by industry',
				'download Nepa\'s press release archive in English and Swedish',
			),
			'metadata'              => array( 'baseUrl' => $home . '/agent/v1/' ),
		),
		array(
			'identifier'            => 'urn:air:nepa.com:catalog:api-catalog',
			'displayName'           => 'Nepa API catalog (RFC 9727)',
			'type'                  => 'application/linkset+json',
			'url'                   => $home . '/.well-known/api-catalog',
			'description'           => 'RFC 9727 linkset listing Nepa\'s machine-readable APIs (REST API, OpenAPI description, MCP server, APIs.json catalog).',
			'tags'                  => array( 'api-catalog', 'linkset' ),
			'representativeQueries' => array(
				'which APIs does nepa.com offer to AI agents',
				'find the OpenAPI description for Nepa\'s agent API',
			),
		),
		array(
			'identifier'            => 'urn:air:nepa.com:docs:agent-page',
			'displayName'           => 'Nepa agent page',
			'type'                  => 'text/html',
			'url'                   => $home . '/agent/',
			'description'           => 'Single semantic HTML page with JSON-LD that describes all of Nepa for AI agents: products, pricing, case studies, team, offices, events, blog and press-release indexes, investor relations, FAQ and every Swedish, Finnish and Danish page.',
			'tags'                  => array_merge( $tags, array( 'documentation' ) ),
			'representativeQueries' => array(
				'summarize what Nepa does and who its clients are',
				'which Nepa case studies show results for retail brands',
				'what is Nepa\'s Campaign Pulse and how fast are results delivered',
			),
		),
		array(
			'identifier'            => 'urn:air:nepa.com:docs:llms-txt',
			'displayName'           => 'Nepa llms.txt',
			'type'                  => 'text/plain',
			'url'                   => $home . '/llms.txt',
			'description'           => 'llms.txt summary of Nepa AB (Markdown in text/plain): products, solutions, proof points, offices, leadership, key pages and links to every machine-readable resource.',
			'tags'                  => array( 'llms-txt', 'documentation' ),
			'representativeQueries' => array(
				'give me a short overview of Nepa AB for an LLM',
				'what machine-readable resources does nepa.com publish',
			),
		),
	);
}

/* -------------------------------------------------------------------------
 * 1. /.well-known/ discovery endpoints
 * ---------------------------------------------------------------------- */
add_action( 'init', function () {
	$path = rtrim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );

	if ( '/.well-known/api-catalog' === $path ) {
		nepa_ad_api_catalog();
	}
	if ( '/.well-known/mcp.json' === $path || '/.well-known/mcp' === $path ) {
		nepa_ad_mcp_card();
	}
	if ( '/.well-known/security.txt' === $path || '/security.txt' === $path ) {
		nepa_ad_security_txt();
	}
}, 0 );

function nepa_ad_send_json( array $data, string $content_type ) {
	status_header( 200 );
	header( 'Content-Type: ' . $content_type . '; charset=utf-8' );
	header( 'Access-Control-Allow-Origin: *' );
	header( 'Cache-Control: public, max-age=3600' );
	header( 'X-Robots-Tag: noindex' );
	if ( 'HEAD' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
	exit;
}

/** RFC 9727 API catalog (linkset format). */
function nepa_ad_api_catalog() {
	$home = nepa_ad_url();
	header( 'Link: <' . $home . '/.well-known/api-catalog>; rel="api-catalog"' );

	nepa_ad_send_json(
		array(
			'linkset' => array(
				array(
					'anchor' => $home . '/.well-known/api-catalog',
					'item'   => array(
						array( 'href' => $home . '/agent/v1/index.json', 'type' => 'application/json', 'title' => 'Nepa Agent REST API' ),
						array( 'href' => NEPA_AD_MCP_URL, 'title' => 'Nepa MCP server (Streamable HTTP)' ),
						array( 'href' => $home . '/api-catalog.json', 'type' => 'application/json', 'title' => 'APIs.json catalog' ),
						array( 'href' => $home . '/.well-known/ard.json', 'type' => 'application/json', 'title' => 'Agentic Resource Discovery (ARD) manifest' ),
					),
				),
				array(
					'anchor'       => $home . '/agent/v1/',
					'service-desc' => array( array( 'href' => $home . '/openapi.json', 'type' => 'application/vnd.oai.openapi+json' ) ),
					'service-doc'  => array( array( 'href' => $home . '/agent/', 'type' => 'text/html' ) ),
				),
			),
		),
		'application/linkset+json'
	);
}

/** MCP server card (emerging convention — agents probing /.well-known/mcp.json find the server). */
function nepa_ad_mcp_card() {
	nepa_ad_send_json(
		array(
			'name'            => 'nepa-mcp',
			'title'           => 'Nepa MCP server',
			'description'     => 'Tools for Nepa AB company, product, solution, case study, pricing, team, event and press data, plus submit_contact_inquiry (requires explicit human authorization).',
			'endpoint'        => NEPA_AD_MCP_URL,
			'transport'       => 'streamable-http',
			'protocolVersion' => '2025-06-18',
			'authentication'  => array( 'type' => 'none' ),
			'capabilities'    => array( 'tools' => true ),
			'documentation'   => nepa_ad_url( '/agent/' ),
			'policy'          => nepa_ad_url( '/ai.txt' ),
			'openapi'         => nepa_ad_url( '/openapi.json' ),
			'ard'             => nepa_ad_url( '/.well-known/ard.json' ),
			'contact'         => nepa_ad_url( '/agent/v1/contact.json' ),
		),
		'application/json'
	);
}

/** security.txt (RFC 9116), also at the legacy /security.txt location. */
function nepa_ad_security_txt() {
	$lines = array(
		'# Security contact for nepa.com (Nepa AB). Policy and PGP details: https://nepa.com/security/',
		'Contact: ' . NEPA_AD_SECURITY_CONTACT,
		'Expires: ' . NEPA_AD_SECURITY_EXPIRES,
		'Encryption: openpgp4fpr:' . strtolower( NEPA_AD_SECURITY_PGP_FPR ),
		'Policy: ' . nepa_ad_url( '/security/' ),
		'Preferred-Languages: en, sv',
		'Canonical: ' . nepa_ad_url( '/.well-known/security.txt' ),
	);
	status_header( 200 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'Access-Control-Allow-Origin: *' );
	header( 'Cache-Control: public, max-age=86400' );
	if ( 'HEAD' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
		echo implode( "\n", $lines ) . "\n";
	}
	exit;
}

/*
 * Yoast's site schema advertises a SearchAction pointing at /?s=, which robots.txt disallows and
 * which Google stopped using for the sitelinks search box in 2024. Leave it out.
 */
add_filter( 'disable_wpseo_json_ld_search', '__return_true' );

/* -------------------------------------------------------------------------
 * 2. <head> discovery links on every page
 * ---------------------------------------------------------------------- */
add_action( 'wp_head', function () {
	$home = nepa_ad_url();
	echo "\n<!-- Agent discovery -->\n";
	printf( '<link rel="alternate" type="text/plain" title="llms.txt" href="%s" />' . "\n", esc_url( $home . '/llms.txt' ) );
	printf( '<link rel="alternate" type="text/plain" title="llms-full.txt" href="%s" />' . "\n", esc_url( $home . '/llms-full.txt' ) );
	printf( '<link rel="alternate" type="text/html" title="AI agent page" href="%s" />' . "\n", esc_url( $home . '/agent/' ) );
	printf( '<link rel="api-catalog" href="%s" />' . "\n", esc_url( $home . '/.well-known/api-catalog' ) );
	printf( '<link rel="service-desc" type="application/vnd.oai.openapi+json" href="%s" />' . "\n", esc_url( $home . '/openapi.json' ) );
	if ( is_singular() ) {
		printf(
			'<link rel="alternate" type="text/markdown" title="Markdown version" href="%s" />' . "\n",
			esc_url( add_query_arg( 'format', 'md', get_permalink() ) )
		);
	}
}, 2 );

/* -------------------------------------------------------------------------
 * 3. Markdown version of any page/post: https://nepa.com/<page>/?format=md
 *
 * Built from the page exactly as WordPress renders it. Up to 1.1.0 the Markdown came from
 * post_content (or the Avia builder's stored content), which on nepa.com holds almost nothing:
 * the Enfold theme and its templates assemble the visible page, so every ?format=md answer was
 * a title with an empty body. Now the chosen template is rendered into a buffer and the main
 * content area (Enfold's #main, without the site footer) is converted.
 * ---------------------------------------------------------------------- */

/** The post a ?format=md request asks for, if it is a published, public singular page. */
function nepa_ad_md_post() {
	if ( ! isset( $_GET['format'] ) || 'md' !== $_GET['format'] || ! is_singular() ) {
		return null;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
		return null;
	}
	return $post;
}

/*
 * template_include runs after WordPress has chosen the template (so the page renders exactly as
 * for a visitor) and before anything is output. PHP_INT_MAX: after other plugins' choices.
 */
add_filter( 'template_include', function ( $template ) {
	$post = nepa_ad_md_post();
	if ( ! $post || ! is_string( $template ) || '' === $template || ! is_readable( $template ) ) {
		return $template;
	}

	// Versioned key: Markdown cached by 1.1.0 (title only) is not reused.
	$cache_key = 'nepa_md_v2_' . $post->ID . '_' . md5( $post->post_modified_gmt . NEPA_AD_VERSION );
	$out       = get_transient( $cache_key );

	if ( false === $out ) {
		ob_start();
		load_template( $template, false );
		$page = (string) ob_get_clean();

		$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
		$desc  = (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
		$body  = nepa_ad_html_to_md( nepa_ad_main_content_html( $page, $title ) );

		$out  = "---\n";
		$out .= 'title: "' . str_replace( '"', '\"', $title ) . "\"\n";
		$out .= 'url: ' . get_permalink( $post ) . "\n";
		if ( '' !== $desc && false === strpos( $desc, '%%' ) ) {
			$out .= 'description: "' . str_replace( '"', '\"', wp_strip_all_tags( $desc ) ) . "\"\n";
		}
		$out .= 'last_modified: ' . get_the_modified_date( 'c', $post ) . "\n";
		$out .= "source: Nepa AB (https://nepa.com)\n";
		$out .= "---\n\n# {$title}\n\n" . $body . "\n";

		set_transient( $cache_key, $out, 12 * HOUR_IN_SECONDS );
	}

	status_header( 200 );
	header( 'Content-Type: text/markdown; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, follow' ); // avoid duplicate-content indexing of the .md variant
	header( 'Link: <' . get_permalink( $post ) . '>; rel="canonical"' );
	header( 'Access-Control-Allow-Origin: *' );
	echo $out;
	exit;
}, PHP_INT_MAX );

/**
 * The main content of a rendered page, as HTML: Enfold's #main area (falling back to <main>, then
 * <body>) up to the site footer, without navigation, scripts, forms and other page chrome, and
 * without an H1 that only repeats the page title (the Markdown starts with that title already).
 *
 * The content area is cut out of the raw HTML and script/style/iframe/form blocks are removed as
 * text BEFORE parsing: PHP's DOMDocument uses libxml's HTML 4 parser, which mis-nests the theme's
 * HTML5 markup and closed #main early on several pages (losing up to half of the text).
 */
function nepa_ad_main_content_html( string $page, string $title = '' ): string {
	if ( '' === trim( $page ) ) {
		return '';
	}
	$start = null;
	foreach ( array( '/<[a-z]+\b[^>]*\bid=["\']main["\'][^>]*>/i', '/<main\b[^>]*>/i', '/<body\b[^>]*>/i' ) as $re ) {
		if ( preg_match( $re, $page, $m, PREG_OFFSET_CAPTURE ) ) {
			$start = $m[0][1] + strlen( $m[0][0] );
			break;
		}
	}
	if ( null === $start ) {
		$start = 0;
	}
	$end = strlen( $page );
	foreach ( array( '/<[a-z]+\b[^>]*\bid=["\']footer-page["\']/i', '/<[a-z]+\b[^>]*\bid=["\']footer["\']/i', '/<[a-z]+\b[^>]*\bid=["\']socket["\']/i',
		'/<footer\b[^>]*role=["\']contentinfo["\']/i', '/<\/body>/i' ) as $re ) {
		if ( preg_match( $re, $page, $m, PREG_OFFSET_CAPTURE, $start ) && $m[0][1] < $end ) {
			$end = $m[0][1];
		}
	}
	$html = substr( $page, $start, $end - $start );
	// Blocks that never carry readable content, removed as text (see above).
	foreach ( array( 'script', 'style', 'noscript', 'template', 'iframe', 'svg', 'form', 'nav', 'select', 'video', 'audio', 'object' ) as $tag ) {
		$html = preg_replace( '#<' . $tag . '\b[^>]*>.*?</' . $tag . '\s*>#is', ' ', $html );
		$html = preg_replace( '#<' . $tag . '\b[^>]*/?>#i', ' ', $html ); // unclosed / self-closed leftovers
	}
	$html = preg_replace( '#<!--.*?-->#s', '', $html );
	if ( ! class_exists( 'DOMDocument' ) ) {
		return $html;
	}
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8" ?><div id="nepa-md-content">' . $html . '</div>' );
	libxml_clear_errors();
	$xp   = new DOMXPath( $doc );
	$root = $xp->query( '//div[@id="nepa-md-content"]' )->item( 0 );
	if ( ! $root ) {
		return $html;
	}
	$remove = array();
	foreach ( array( './/*[@role="navigation"]', './/*[@role="banner"]', './/*[@role="contentinfo"]', './/button' ) as $expr ) {
		foreach ( $xp->query( $expr, $root ) as $node ) {
			$remove[] = $node;
		}
	}
	$norm = function ( $s ) {
		return strtolower( trim( preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ) ) ) );
	};
	if ( '' !== $title ) {
		foreach ( $xp->query( './/h1', $root ) as $h1 ) {
			if ( $norm( $h1->textContent ) === $norm( $title ) ) {
				$remove[] = $h1;
			}
		}
	}
	foreach ( $remove as $node ) {
		if ( $node->parentNode ) {
			$node->parentNode->removeChild( $node );
		}
	}
	$out = '';
	foreach ( $root->childNodes as $child ) {
		$out .= $doc->saveHTML( $child );
	}
	return $out;
}

/* ---- HTML → Markdown helpers ------------------------------------------- */

function nepa_ad_html_to_md( string $html ): string {
	if ( '' === trim( $html ) ) {
		return '';
	}
	if ( ! class_exists( 'DOMDocument' ) ) {
		return trim( wp_strip_all_tags( $html ) );
	}
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8" ?><div id="nepa-md-root">' . $html . '</div>' );
	libxml_clear_errors();

	$root = ( new DOMXPath( $doc ) )->query( '//div[@id="nepa-md-root"]' )->item( 0 );
	if ( ! $root ) {
		return wp_strip_all_tags( $html );
	}

	$md = nepa_ad_node_to_md( $root );
	$md = preg_replace( "/[ \t]+\n/", "\n", $md );                     // trailing spaces
	$md = preg_replace( '/\n[ \t]++(?!(?:[-*]|\d+\.) )/', "\n", $md ); // stray indents (keeps nested list indents)
	$md = preg_replace( '/(?<=\S) {2,}(?=\S)/', ' ', $md );              // double spaces inside lines
	$md = preg_replace( "/\n{3,}/", "\n\n", $md );                      // collapse blank lines
	return trim( $md );
}

function nepa_ad_node_to_md( DOMNode $node, int $depth = 0 ): string {
	static $skip  = array( 'script', 'style', 'noscript', 'svg', 'form', 'button', 'iframe', 'nav', 'video', 'audio', 'template', 'select', 'img', 'picture' );
	static $block = array( 'p', 'div', 'section', 'article', 'header', 'footer', 'figure', 'figcaption', 'main', 'aside', 'dl', 'dd', 'dt', 'address' );

	$out = '';
	foreach ( $node->childNodes as $child ) {
		if ( $child instanceof DOMText ) {
			$out .= preg_replace( '/\s+/u', ' ', $child->nodeValue );
			continue;
		}
		if ( ! $child instanceof DOMElement ) {
			continue;
		}
		$tag = strtolower( $child->tagName );

		if ( in_array( $tag, $skip, true ) ) {
			continue;
		}
		if ( 'ul' === $tag || 'ol' === $tag ) {
			$out .= "\n\n" . nepa_ad_list_to_md( $child, $depth ) . "\n\n";
			continue;
		}
		if ( 'table' === $tag ) {
			$out .= "\n\n" . nepa_ad_table_to_md( $child ) . "\n\n";
			continue;
		}
		if ( 'br' === $tag ) {
			$out .= "  \n";
			continue;
		}
		if ( 'hr' === $tag ) {
			$out .= "\n\n---\n\n";
			continue;
		}

		$inner = nepa_ad_node_to_md( $child, $depth );
		$text  = trim( $inner );

		if ( preg_match( '/^h([1-6])$/', $tag, $m ) ) {
			// Page title is the only H1 in the Markdown, so content headings start at H2.
			$level = max( 2, (int) $m[1] );
			if ( '' !== $text ) {
				$out .= "\n\n" . str_repeat( '#', $level ) . ' ' . preg_replace( '/\s+/u', ' ', $text ) . "\n\n";
			}
		} elseif ( 'strong' === $tag || 'b' === $tag ) {
			$out .= '' !== $text ? ' **' . $text . '** ' : '';
		} elseif ( 'em' === $tag || 'i' === $tag ) {
			$out .= '' !== $text ? ' *' . $text . '* ' : '';
		} elseif ( 'a' === $tag ) {
			$href = trim( $child->getAttribute( 'href' ) );
			if ( '' === $text ) {
				continue;
			}
			if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === stripos( $href, 'javascript:' ) ) {
				$out .= $text;
			} else {
				$out .= '[' . $text . '](' . nepa_ad_abs_url( $href ) . ')';
			}
		} elseif ( 'blockquote' === $tag ) {
			$out .= "\n\n> " . str_replace( "\n", "\n> ", $text ) . "\n\n";
		} elseif ( in_array( $tag, $block, true ) ) {
			$out .= "\n\n" . $inner . "\n\n";
		} else {
			$out .= $inner; // inline elements: span, small, sup, etc.
		}
	}
	return $out;
}

function nepa_ad_list_to_md( DOMElement $list, int $depth ): string {
	$ordered = 'ol' === strtolower( $list->tagName );
	$lines   = array();
	$i       = 1;

	foreach ( $list->childNodes as $li ) {
		if ( ! $li instanceof DOMElement || 'li' !== strtolower( $li->tagName ) ) {
			continue;
		}
		$clone  = $li->cloneNode( true );
		$nested = array();
		foreach ( iterator_to_array( $clone->childNodes ) as $c ) {
			if ( $c instanceof DOMElement && in_array( strtolower( $c->tagName ), array( 'ul', 'ol' ), true ) ) {
				$nested[] = $c;
				$clone->removeChild( $c );
			}
		}
		$text = trim( preg_replace( '/\s+/u', ' ', nepa_ad_node_to_md( $clone, $depth ) ) );
		if ( '' !== $text ) {
			$lines[] = str_repeat( '  ', $depth ) . ( $ordered ? ( $i++ ) . '. ' : '- ' ) . $text;
		}
		foreach ( $nested as $n ) {
			$lines[] = nepa_ad_list_to_md( $n, $depth + 1 );
		}
	}
	return implode( "\n", $lines );
}

function nepa_ad_table_to_md( DOMElement $table ): string {
	$rows = array();
	foreach ( ( new DOMXPath( $table->ownerDocument ) )->query( './/tr', $table ) as $tr ) {
		$cells = array();
		foreach ( $tr->childNodes as $td ) {
			if ( $td instanceof DOMElement && in_array( strtolower( $td->tagName ), array( 'td', 'th' ), true ) ) {
				$cells[] = str_replace( '|', '\|', trim( preg_replace( '/\s+/u', ' ', nepa_ad_node_to_md( $td ) ) ) );
			}
		}
		if ( $cells ) {
			$rows[] = $cells;
		}
	}
	if ( ! $rows ) {
		return '';
	}
	$cols = max( array_map( 'count', $rows ) );
	$md   = '';
	foreach ( $rows as $k => $r ) {
		$md .= '| ' . implode( ' | ', array_pad( $r, $cols, '' ) ) . " |\n";
		if ( 0 === $k ) {
			$md .= '|' . str_repeat( ' --- |', $cols ) . "\n";
		}
	}
	return rtrim( $md );
}

function nepa_ad_abs_url( string $url ): string {
	if ( preg_match( '#^(https?:|mailto:|tel:)#i', $url ) ) {
		return $url;
	}
	if ( 0 === strpos( $url, '//' ) ) {
		return 'https:' . $url;
	}
	if ( 0 === strpos( $url, '/' ) ) {
		return nepa_ad_url( $url );
	}
	return $url;
}
