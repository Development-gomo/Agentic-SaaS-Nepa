<?php
/**
 * Plugin Name: Agentic Resource Discovery (ARD)
 * Description: Publishes a site's agentic resources for discovery per the Agentic Resource Discovery
 *              specification v0.91: /.well-known/ard.json manifest, <link rel="ard"> in <head>, and a
 *              `Link: <...>; rel="ard"` response header. Site-agnostic - every value comes from the
 *              `agentic_ard_config` and `agentic_ard_entries` filters (see below), so the same file is
 *              reused unchanged on any WordPress site of the Agentic Web package.
 * Version:     1.0.0
 * Author:      GO MO Group
 *
 * Spec:    https://agenticresourcediscovery.org/spec/  (v0.91, ards-project/ard-spec commit f073b1e, 2026-10-25)
 * Install: upload to wp-content/mu-plugins/agentic-resource-discovery.php (loads automatically).
 *          A site supplies its entries with add_filter( 'agentic_ard_entries', ... ) - for nepa.com that is
 *          done in nepa-agent-discovery.php. With no entries the module stays silent (no manifest, no link).
 *
 * What this module does NOT do: it is a publisher (static discovery, spec §5.1). It does not implement an
 * ARD Registry (POST /search, POST /explore, GET /agents, spec §5.3) - that is the role of search services
 * that crawl published manifests.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const AGENTIC_ARD_MODULE_VERSION = '1.0.0';
const AGENTIC_ARD_SPEC_VERSION   = '0.91';
const AGENTIC_ARD_CONTEXT        = 'https://agenticresourcediscovery.org/context/v1';
const AGENTIC_ARD_PATH           = '/.well-known/ard.json';
/** URN form from the spec's entry schema: urn:air:<publisher>:[<namespace>:]<name>. */
const AGENTIC_ARD_URN_PATTERN    = '/^urn:air:[a-zA-Z0-9.-]+(:[a-zA-Z0-9._-]+)+$/';

/**
 * Module settings. Override any key with the `agentic_ard_config` filter.
 *
 * - publisher:        FQDN used as the <publisher> segment of every urn:air: identifier (default: site host).
 * - host:             optional top-level `host` object of the manifest (transport-defined, ignored by ARD).
 * - updated_at:       ISO 8601 timestamp applied to entries that carry no `updatedAt`.
 * - emit_head_link:   print <link rel="ard"> in wp_head (spec §5.1 HTML Link Tag).
 * - emit_http_link:   send a `Link: <manifest>; rel="ard"` header on front-end responses.
 * - embed_context:    add "@context" to entries in in-page markup (spec §4.1 SHOULD for embedded entries).
 * - cache_seconds:    Cache-Control max-age for the manifest.
 */
function agentic_ard_config(): array {
	$host = (string) wp_parse_url( agentic_ard_site_url(), PHP_URL_HOST );
	return apply_filters(
		'agentic_ard_config',
		array(
			'publisher'      => preg_replace( '/^www\./', '', $host ),
			'host'           => array(),
			'updated_at'     => null,
			'emit_head_link' => true,
			'emit_http_link' => true,
			'embed_context'  => true,
			'cache_seconds'  => 3600,
		)
	);
}

/**
 * The site's root URL plus $path, independent of the language being viewed. home_url() is not used
 * because multilingual plugins (Polylang, WPML) filter it to the current language - on a /sv/ page it
 * returns https://example.com/sv, which would turn /.well-known/ard.json into /sv//.well-known/ard.json.
 * The `home` option holds the unfiltered root. Override with the `agentic_ard_site_url` filter.
 */
function agentic_ard_site_url( string $path = '' ): string {
	return rtrim( (string) apply_filters( 'agentic_ard_site_url', (string) get_option( 'home' ) ), '/' ) . $path;
}

/** Absolute URL of the manifest. */
function agentic_ard_manifest_url(): string {
	return agentic_ard_site_url( AGENTIC_ARD_PATH );
}

/**
 * Checks one entry against the ARD entry rules (spec §4.2, §4.3, Appendix C, §D.2).
 *
 * @return array{errors: string[], warnings: string[]}
 */
function agentic_ard_check_entry( $entry, string $publisher ): array {
	$errors   = array();
	$warnings = array();
	if ( ! is_array( $entry ) ) {
		return array( 'errors' => array( 'entry is not an object' ), 'warnings' => array() );
	}
	foreach ( array( 'identifier', 'displayName', 'type' ) as $term ) {
		if ( ! isset( $entry[ $term ] ) || ! is_string( $entry[ $term ] ) || '' === trim( $entry[ $term ] ) ) {
			$errors[] = "missing required term '$term'";
		}
	}
	if ( isset( $entry['identifier'] ) && is_string( $entry['identifier'] ) ) {
		if ( ! preg_match( AGENTIC_ARD_URN_PATTERN, $entry['identifier'] ) ) {
			$errors[] = 'identifier is not a urn:air: URN';
		} elseif ( '' !== $publisher && 0 !== strpos( $entry['identifier'], 'urn:air:' . $publisher . ':' ) ) {
			$errors[] = "identifier publisher is not '$publisher'";
		}
	}
	if ( isset( $entry['type'] ) && is_string( $entry['type'] ) && ! preg_match( '#^[a-z0-9][a-z0-9!\#$&^_.+-]*/[a-z0-9][a-z0-9!\#$&^_.+-]*(\s*;.*)?$#i', $entry['type'] ) ) {
		$errors[] = 'type is not a media type';
	}
	$has_url  = array_key_exists( 'url', $entry );
	$has_data = array_key_exists( 'data', $entry );
	if ( $has_url === $has_data ) {
		$errors[] = 'exactly one of url or data is required';
	} elseif ( $has_url && ( ! is_string( $entry['url'] ) || ! preg_match( '#^https?://#i', $entry['url'] ) ) ) {
		$errors[] = 'url must be an absolute http(s) URL';
	} elseif ( $has_data && ! agentic_ard_is_object( $entry['data'] ) ) {
		$errors[] = 'data must be an object';  // a PHP list (or empty array) would serialize as a JSON array
	}
	foreach ( array( 'description', 'version', '@id' ) as $term ) {
		if ( isset( $entry[ $term ] ) && ! is_string( $entry[ $term ] ) ) {
			$errors[] = "$term must be a string";
		}
	}
	if ( isset( $entry['updatedAt'] ) && ( ! is_string( $entry['updatedAt'] )
		|| ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $entry['updatedAt'] ) ) ) {
		$errors[] = 'updatedAt must be an RFC 3339 date-time';
	}
	if ( isset( $entry['@context'] ) && ! ( is_string( $entry['@context'] ) || is_array( $entry['@context'] ) ) ) {
		$errors[] = '@context must be a string, object or array';
	}
	foreach ( array( 'representativeQueries', 'capabilities', 'tags' ) as $list ) {
		if ( isset( $entry[ $list ] ) && ( ! is_array( $entry[ $list ] ) || array_filter( $entry[ $list ], function ( $v ) {
			return ! is_string( $v );
		} ) ) ) {
			$errors[] = "$list must be an array of strings";
		}
	}
	if ( isset( $entry['metadata'] ) ) {
		if ( ! agentic_ard_is_object( $entry['metadata'] ) ) {
			$errors[] = 'metadata must be an object';
		} else {
			foreach ( $entry['metadata'] as $k => $v ) {
				if ( ! ( is_scalar( $v ) || null === $v ) ) {
					$errors[] = "metadata.$k must be a string, number, boolean or null";
				}
			}
		}
	}
	if ( isset( $entry['trustManifest'] ) ) {
		if ( ! agentic_ard_is_object( $entry['trustManifest'] ) || empty( $entry['trustManifest']['identity'] ) || ! is_string( $entry['trustManifest']['identity'] ) ) {
			$errors[] = 'trustManifest requires identity';
		} elseif ( '' !== $publisher && ! agentic_ard_identity_matches( $entry['trustManifest']['identity'], $publisher ) ) {
			// Spec §4.5.1: the trust domain in trustManifest.identity MUST align with the URN <publisher>.
			$errors[] = "trustManifest.identity does not belong to '$publisher'";
		}
	}
	$q = isset( $entry['representativeQueries'] ) && is_array( $entry['representativeQueries'] ) ? count( $entry['representativeQueries'] ) : 0;
	if ( $q < 2 || $q > 5 ) {
		$warnings[] = "representativeQueries should hold 2-5 examples (has $q)";
	}
	return array( 'errors' => $errors, 'warnings' => $warnings );
}

/** True for a non-empty associative array, i.e. something wp_json_encode() writes as a JSON object. */
function agentic_ard_is_object( $value ): bool {
	return is_array( $value ) && $value && array_keys( $value ) !== range( 0, count( $value ) - 1 );
}

/**
 * Whether a trust identity (HTTPS URI, did:web, SPIFFE ID or bare domain) is anchored on $publisher or one
 * of its subdomains, e.g. https://nepa.com, did:web:nepa.com, spiffe://nepa.com/mcp, api.nepa.com.
 */
function agentic_ard_identity_matches( string $identity, string $publisher ): bool {
	if ( preg_match( '#^(?:https?|spiffe)://([^/:?\#]+)#i', $identity, $m ) ) {
		$host = $m[1];
	} elseif ( preg_match( '#^did:web:([^:]+)#i', $identity, $m ) ) {
		$host = rawurldecode( $m[1] );
	} else {
		$host = $identity;
	}
	$host = strtolower( $host );
	$publisher = strtolower( $publisher );
	return $host === $publisher || substr( $host, -strlen( '.' . $publisher ) ) === '.' . $publisher;
}

/**
 * The valid entries, in the order supplied. Invalid entries are left out (and logged) so that the
 * published manifest always conforms; duplicates of an identifier keep the first occurrence.
 */
function agentic_ard_entries(): array {
	$cfg     = agentic_ard_config();
	$entries = apply_filters( 'agentic_ard_entries', array(), $cfg );
	$out     = array();
	$seen    = array();
	foreach ( (array) $entries as $i => $entry ) {
		$check = agentic_ard_check_entry( $entry, (string) $cfg['publisher'] );
		if ( $check['errors'] ) {
			error_log( 'ARD: entry #' . $i . ' left out of ' . AGENTIC_ARD_PATH . ': ' . implode( '; ', $check['errors'] ) );
			continue;
		}
		if ( isset( $seen[ $entry['identifier'] ] ) ) {
			error_log( 'ARD: duplicate identifier left out: ' . $entry['identifier'] );
			continue;
		}
		$seen[ $entry['identifier'] ] = true;
		if ( empty( $entry['updatedAt'] ) && ! empty( $cfg['updated_at'] ) ) {
			$entry['updatedAt'] = $cfg['updated_at'];
		}
		$out[] = $entry;
	}
	return $out;
}

/** The /.well-known/ard.json document (spec §5.1, ArdManifest: an `entries` array; other members are ignored by ARD). */
function agentic_ard_manifest(): array {
	$cfg      = agentic_ard_config();
	$manifest = array();
	if ( ! empty( $cfg['host'] ) ) {
		$manifest['host'] = $cfg['host'];
	}
	$manifest['specVersion'] = AGENTIC_ARD_SPEC_VERSION;
	$manifest['entries']     = agentic_ard_entries();
	return $manifest;
}

/**
 * Entries for in-page JSON-LD markup (spec §5.1 In-page markup). Each carries the ARD base context so
 * generic JSON-LD tooling can read it (spec §4.1).
 */
function agentic_ard_jsonld(): array {
	$cfg = agentic_ard_config();
	return array_map( function ( $e ) use ( $cfg ) {
		return ( $cfg['embed_context'] && ! isset( $e['@context'] ) ) ? array( '@context' => AGENTIC_ARD_CONTEXT ) + $e : $e;
	}, agentic_ard_entries() );
}

/* ---- HTTP wiring -------------------------------------------------------- */

add_action( 'init', function () {
	$path = rtrim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
	if ( AGENTIC_ARD_PATH !== $path ) {
		return;
	}
	$manifest = agentic_ard_manifest();
	if ( ! $manifest['entries'] ) {
		return; // Nothing to publish: let WordPress answer (404), whatever the method.
	}
	$method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
	if ( 'OPTIONS' === $method ) {
		status_header( 204 );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, HEAD, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Accept' );
		exit;
	}
	if ( 'GET' !== $method && 'HEAD' !== $method ) {
		status_header( 405 );
		header( 'Allow: GET, HEAD, OPTIONS' );
		exit;
	}
	$cfg  = agentic_ard_config();
	$body = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$etag = '"' . md5( $body ) . '"';
	if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( (string) $_SERVER['HTTP_IF_NONE_MATCH'] ) === $etag ) {
		status_header( 304 );
		header( 'ETag: ' . $etag );
		header( 'Cache-Control: public, max-age=' . (int) $cfg['cache_seconds'] );
		exit;
	}
	status_header( 200 );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Access-Control-Allow-Origin: *' );
	header( 'Cache-Control: public, max-age=' . (int) $cfg['cache_seconds'] );
	header( 'ETag: ' . $etag );
	header( 'X-Robots-Tag: noindex' );
	header( 'Link: <' . agentic_ard_manifest_url() . '>; rel="ard"', false );
	if ( 'HEAD' !== $method ) {
		echo $body;
	}
	exit;
}, 0 );

/* <link rel="ard"> on every front-end page (spec §5.1 HTML Link Tag; consumers MUST honour it). */
add_action( 'wp_head', function () {
	$cfg = agentic_ard_config();
	if ( $cfg['emit_head_link'] && agentic_ard_entries() ) {
		printf( '<link rel="ard" type="application/json" href="%s" />' . "\n", esc_url( agentic_ard_manifest_url() ) );
	}
}, 2 );

/* HTTP Link header on front-end responses, for agents that read headers without parsing HTML. */
add_action( 'send_headers', function () {
	$cfg = agentic_ard_config();
	if ( $cfg['emit_http_link'] && ! headers_sent() && ! is_admin() && agentic_ard_entries() ) {
		header( 'Link: <' . agentic_ard_manifest_url() . '>; rel="ard"', false );
	}
} );
