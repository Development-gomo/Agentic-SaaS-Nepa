<?php
/**
 * Plugin Name: Nepa Agent API
 * Description: Serves clean, WordPress-agnostic JSON endpoints at /agent/v1/ for AI agents and LLMs. Companion to /agent/ (agent-page.html), llms.txt, ai.txt, and openapi.json.
 * Version: 1.3.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Nepa AB
 */

if (!defined('ABSPATH')) {
    exit;
}

define('NEPA_API_VERSION', '1.3.0');
/**
 * Set to true in wp-config.php ONLY if every request to this site passes through Cloudflare
 * (as nepa.com did at the 2026-10-16 crawl). Rate limiting then keys on Cloudflare's
 * CF-Connecting-IP header instead of REMOTE_ADDR, which behind Cloudflare is the edge
 * node's IP shared by many unrelated visitors. Leave false if the origin is reachable
 * directly, because the header is client-spoofable in that case.
 */
if (!defined('NEPA_TRUST_CLOUDFLARE_IP')) {
    define('NEPA_TRUST_CLOUDFLARE_IP', false);
}
/**
 * Optional lead retention in days. 0 (default) keeps Agent Leads until deleted by hand.
 * Set e.g. define('NEPA_LEAD_RETENTION_DAYS', 365); in wp-config.php to have a daily job
 * permanently delete leads older than that - agree the period with Nepa's privacy policy.
 */
if (!defined('NEPA_LEAD_RETENTION_DAYS')) {
    define('NEPA_LEAD_RETENTION_DAYS', 0);
}
define('NEPA_API_DIR', plugin_dir_path(__FILE__));

/**
 * Identifies the curated data set shipped in this plugin's data/ folder. Change it with
 * every package release (the package build does this); it decides which copy is served.
 */
define('NEPA_DATA_RELEASE', 'agentic-web-2026-10');

/**
 * Data files live in two places:
 *   - data/ inside this plugin: the curated copy shipped with each package release;
 *   - wp-content/uploads/nepa-agent-api/: written by Tools -> Nepa Agent API -> Sync now,
 *     together with manifest.json naming the release the sync was built on and the files
 *     it wrote.
 * Precedence rule (explicit, independent of file timestamps):
 *   - a synced file is served only if manifest.json lists it AND the manifest's release
 *     equals NEPA_DATA_RELEASE, i.e. the sync was made on top of the release now installed;
 *   - otherwise the bundled file is served. Installing a new release therefore takes
 *     effect immediately, and the next Sync builds on that release (it reads the bundled
 *     files as its starting point). Reinstalling the same release keeps the synced files.
 */
function nepa_agent_api_upload_data_dir() {
    $uploads = wp_upload_dir(null, false);
    return trailingslashit($uploads['basedir']) . 'nepa-agent-api/';
}

function nepa_agent_api_sync_manifest($refresh = false) {
    static $manifest = null;
    if ($manifest === null || $refresh) {
        $path = nepa_agent_api_upload_data_dir() . 'manifest.json';
        $decoded = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
        $manifest = is_array($decoded) ? $decoded : [];
    }
    return $manifest;
}

function nepa_agent_api_data_path($filename) {
    $manifest = nepa_agent_api_sync_manifest();
    $synced = nepa_agent_api_upload_data_dir() . $filename;
    if (($manifest['release'] ?? null) === NEPA_DATA_RELEASE
        && in_array($filename, (array) ($manifest['files'] ?? []), true)
        && is_readable($synced)) {
        return $synced;
    }
    return NEPA_API_DIR . 'data/' . $filename;
}

require_once NEPA_API_DIR . 'includes/class-sync.php';
require_once NEPA_API_DIR . 'includes/class-hubspot.php';

class Nepa_Agent_API {

    /** @var Nepa_HubSpot_Delivery HubSpot form delivery (see includes/class-hubspot.php). */
    public $hubspot;

    /** @var array Endpoint slug => data file (relative to data/), or null for computed endpoints */
    private $file_endpoints = [
        'company'              => 'company.json',
        'products'              => 'products.json',
        'solutions'             => 'solutions.json',
        'industries'            => 'industries.json',
        'case-studies'          => 'case-studies.json',
        'team'                  => 'team.json',
        'offices'               => 'offices.json',
        'jobs'                  => 'jobs.json',
        'events'                => 'events.json',
        'company-news'          => 'company-news.json',
        'resources'             => 'resources.json',
        'pricing'               => 'pricing.json',
        'blog-posts'            => 'blog-posts.json',
        'press-releases'        => 'press-releases.json',
        'investor-relations'    => 'investor-relations.json',
        'localized-pages'       => 'localized-pages.json',
    ];

    /** Max contact submissions accepted from one IP within the rate-limit window. */
    const CONTACT_RATE_LIMIT_MAX = 5;
    /** Rate-limit window, in seconds (10 minutes). */
    const CONTACT_RATE_LIMIT_WINDOW = 600;
    /**
     * Higher quota for requests carrying a valid shared secret (see shared_limit_bucket()) -
     * every submission relayed through the MCP server arrives from that ONE server's IP
     * regardless of how many distinct real people are actually behind it, so bucketing
     * MCP traffic by IP the same way as direct public traffic would let the first 5 of
     * potentially hundreds of distinct legitimate users block every other MCP user for
     * the rest of the window. This bucket is still bounded (not unlimited) so a
     * compromised or misbehaving MCP server can't use it to bypass throttling entirely.
     */
    const MCP_RATE_LIMIT_MAX = 100;
    /**
     * Per-submitted-email quota, independent of IP/MCP bucketing entirely - see
     * is_email_rate_limited(). The IP-keyed and MCP-shared-secret buckets above both key
     * on WHERE a request came from, which is exactly the wrong axis for MCP traffic: every
     * distinct real person relayed through the MCP server shares its one outbound IP, so
     * no IP-based (or IP-like) bucket, however large, can distinguish "100 different people
     * each submitting once" from "one script submitting 100 times" - it can only ever raise
     * or lower the point at which that ambiguity starts to matter. Limiting by the
     * submitted email address instead keys on WHO, which both channels can express
     * identically: a real person submitting their own form once (via a browser or via an
     * agent) essentially never approaches this ceiling regardless of how much OTHER
     * traffic shares their IP or MCP bucket that window, while repeated hits on the same
     * target address are caught immediately.
     */
    const EMAIL_RATE_LIMIT_MAX = 3;
    /** Per-email rate-limit window, in seconds (1 hour) - longer than the IP window since legitimate re-submission by the same person is rarer than legitimate re-submission from the same IP/proxy. */
    const EMAIL_RATE_LIMIT_WINDOW = 3600;

    /** Custom capability names for the nepa_lead post type - see register_lead_post_type(). */
    const LEAD_CAPABILITIES = [
        'edit_post'              => 'edit_nepa_lead',
        'read_post'              => 'read_nepa_lead',
        'delete_post'            => 'delete_nepa_lead',
        'edit_posts'             => 'edit_nepa_leads',
        'edit_others_posts'      => 'edit_others_nepa_leads',
        'publish_posts'          => 'publish_nepa_leads',
        'read_private_posts'     => 'read_private_nepa_leads',
        'delete_posts'           => 'delete_nepa_leads',
        'delete_private_posts'   => 'delete_private_nepa_leads',
        'delete_published_posts' => 'delete_published_nepa_leads',
        'delete_others_posts'    => 'delete_others_nepa_leads',
        'edit_private_posts'     => 'edit_private_nepa_leads',
        'edit_published_posts'   => 'edit_published_nepa_leads',
        'create_posts'           => 'create_nepa_leads',
    ];

    public function __construct() {
        add_action('init', [$this, 'add_rewrite_rules']);
        add_action('init', [$this, 'register_lead_post_type']);
        // Idempotent (add_cap is a no-op if the role already has it) - runs every admin
        // load so capabilities are (re)granted even if the plugin's activation hook was
        // missed (e.g. it was already active during an update, or a role was reset).
        add_action('admin_init', [$this, 'grant_lead_capabilities_to_administrators']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('template_redirect', [$this, 'handle_request']);
        add_action('send_headers', [$this, 'send_cors_headers']);
        add_filter('redirect_canonical', [$this, 'skip_canonical_redirect']);
        add_action('nepa_agent_api_retry_notifications', [$this, 'retry_failed_notifications']);
        add_action('nepa_agent_api_purge_leads', [$this, 'purge_expired_leads']);
        // Re-create the schedules if they were lost (e.g. plugin updated without re-activation).
        add_action('admin_init', [$this, 'schedule_jobs']);
    }

    /**
     * WordPress's canonical redirect was 301-ing every endpoint to a trailing-slash URL
     * (/agent/v1/index.json -> /agent/v1/index.json/), seen on the live site at the
     * 2026-10-16 crawl. Some agent HTTP clients don't follow redirects on POST or treat
     * the slashed URL as a different resource, so the canonical redirect is switched off
     * for this plugin's own URLs only.
     */
    public function skip_canonical_redirect($redirect_url) {
        return get_query_var('nepa_endpoint') ? false : $redirect_url;
    }

    public function add_rewrite_rules() {
        add_rewrite_rule('^agent/v1/([a-z-]+)\.json$', 'index.php?nepa_endpoint=$matches[1]', 'top');
        add_rewrite_rule('^openapi\.json$', 'index.php?nepa_endpoint=openapi_spec', 'top');
        // Serves agent-page.html directly at /agent/ - deliberately bypassing the theme
        // entirely (no WP Page/template involved), the same way openapi.json is served,
        // so this page can never be mangled by the block/classic editor's HTML sanitizing
        // (which would strip the JSON-LD <script> tag and other raw markup if the file's
        // contents were ever pasted into a normal WordPress Page instead).
        add_rewrite_rule('^agent/?$', 'index.php?nepa_endpoint=agent_page', 'top');
    }

    /**
     * A minimal, admin-visible record type so a submitted inquiry is never lost even if
     * the notification email fails to send or nobody checks their inbox in time.
     *
     * Uses its OWN capability names (nepa_lead / nepa_leads), not the shared 'post'
     * capability_type. A previous version used capability_type => 'post' with
     * map_meta_cap => true, which maps straight onto WordPress's standard post
     * capabilities - capabilities the built-in Editor role already has by default. That
     * meant any Editor (not just Administrators) could read, edit, or delete every
     * submitted inquiry's name/email/project details, contradicting this plugin's own
     * intent. Custom capability names exist on no role until explicitly granted (see
     * grant_lead_capabilities_to_administrators()), so by default nobody but a "real"
     * super-capability holder can see them until this plugin grants access - which it
     * only ever does to the administrator role.
     */
    public function register_lead_post_type() {
        register_post_type('nepa_lead', [
            'label'        => 'Agent Leads',
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-email-alt',
            'capability_type' => ['nepa_lead', 'nepa_leads'],
            'capabilities' => self::LEAD_CAPABILITIES,
            'map_meta_cap' => true,
            // 'editor' is required for the post_content box to appear at all in the edit
            // screen - without it, the contact/project-summary fallback text stored in
            // post_content (see contact_submission()) is saved correctly but genuinely
            // invisible in wp-admin, defeating the point of storing it there as a fallback.
            'supports'     => ['title', 'editor', 'custom-fields'],
        ]);
    }

    /** Grants the nepa_lead capabilities to the administrator role only - see register_lead_post_type(). */
    public function grant_lead_capabilities_to_administrators() {
        $role = get_role('administrator');
        if (!$role) {
            return;
        }
        foreach (self::LEAD_CAPABILITIES as $cap) {
            if (!$role->has_cap($cap)) {
                $role->add_cap($cap);
            }
        }
    }

    public function add_query_vars($vars) {
        $vars[] = 'nepa_endpoint';
        return $vars;
    }

    public function send_cors_headers() {
        if (get_query_var('nepa_endpoint')) {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Accept, Idempotency-Key');
        }
    }

    public function handle_request() {
        $endpoint = get_query_var('nepa_endpoint');
        if (!$endpoint) {
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            status_header(204);
            exit;
        }

        switch ($endpoint) {
            case 'index':
                $this->get_index();
                break;
            case 'schema':
                $this->get_schema();
                break;
            case 'openapi_spec':
                $this->serve_file(NEPA_API_DIR . 'openapi.json', 'application/json');
                break;
            case 'agent_page':
                $this->serve_file(NEPA_API_DIR . 'agent-page.html', 'text/html');
                break;
            case 'contact':
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $this->contact_submission();
                } else {
                    $this->error(405, 'method_not_allowed', 'Use POST for /agent/v1/contact.json');
                }
                break;
            case 'case-studies':
                $this->serve_filtered_json('case-studies.json', 'case_studies', ['industry', 'segment', 'service']);
                break;
            case 'products':
                $this->serve_filtered_json('products.json', 'products', ['category']);
                break;
            case 'blog-posts':
                $this->serve_paginated_json('blog-posts.json', 'posts', ['language']);
                break;
            case 'press-releases':
                $this->serve_paginated_json('press-releases.json', 'releases', ['language', 'category']);
                break;
            case 'localized-pages':
                $this->serve_filtered_json('localized-pages.json', 'pages', ['language', 'section']);
                break;
            default:
                if (isset($this->file_endpoints[$endpoint])) {
                    $this->serve_file(nepa_agent_api_data_path($this->file_endpoints[$endpoint]), 'application/json');
                } else {
                    $this->error(404, 'not_found', "Unknown endpoint: {$endpoint}");
                }
        }
        exit;
    }

    /** Serve a static JSON file verbatim - no re-encoding, faster than json_encode roundtrip. */
    private function serve_file($path, $content_type) {
        if (!file_exists($path)) {
            $this->error(404, 'not_found', 'Data file not found.');
            return;
        }
        header('Content-Type: ' . $content_type . '; charset=utf-8');
        status_header(200);
        readfile($path);
    }

    private function load_json($filename) {
        $path = nepa_agent_api_data_path($filename);
        if (!file_exists($path)) {
            return null;
        }
        return json_decode(file_get_contents($path), true);
    }

    /**
     * Apply case-insensitive ?field=value filters to a list of items. A field holding a
     * comma-separated list (e.g. a case study's service "Brand Tracking, Campaign
     * Evaluation, Marketing Mix Modelling") matches when ANY one entry equals the value,
     * so ?service=Brand Tracking finds every case study that includes Brand Tracking.
     */
    private function apply_filters_from_query($items, $filter_fields) {
        foreach ($filter_fields as $field) {
            $value = isset($_GET[$field]) ? sanitize_text_field(wp_unslash($_GET[$field])) : null;
            if ($value !== null && $value !== '') {
                $items = array_values(array_filter($items, function ($item) use ($field, $value) {
                    if (!isset($item[$field]) || !is_scalar($item[$field])) {
                        return false;
                    }
                    $haystack = (string) $item[$field];
                    if (strcasecmp($haystack, $value) === 0) {
                        return true;
                    }
                    foreach (explode(',', $haystack) as $entry) {
                        if (strcasecmp(trim($entry), $value) === 0) {
                            return true;
                        }
                    }
                    return false;
                }));
            }
        }
        return $items;
    }

    /** Filter a list-shaped data file by one or more query-string fields (exact, case-insensitive match). */
    private function serve_filtered_json($filename, $list_key, $filter_fields) {
        $decoded = $this->load_json($filename);
        if ($decoded === null) {
            $this->error(404, 'not_found', 'Data file not found.');
            return;
        }
        $items = $this->apply_filters_from_query($decoded[$list_key] ?? [], $filter_fields);
        $decoded[$list_key] = $items;
        $decoded['count'] = count($items);
        header('Content-Type: application/json; charset=utf-8');
        status_header(200);
        echo wp_json_encode($decoded);
    }

    /** Paginate a large list-shaped data file (blog posts, press releases) via ?limit=&offset=, after any ?field= filters. */
    private function serve_paginated_json($filename, $list_key, $filter_fields = []) {
        $decoded = $this->load_json($filename);
        if ($decoded === null) {
            $this->error(404, 'not_found', 'Data file not found.');
            return;
        }
        $items = $this->apply_filters_from_query($decoded[$list_key] ?? [], $filter_fields);
        $total = count($items);
        $limit = isset($_GET['limit']) ? max(1, min(200, (int) $_GET['limit'])) : 25;
        $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
        $decoded[$list_key] = array_slice($items, $offset, $limit);
        $decoded['total'] = $total;
        $decoded['limit'] = $limit;
        $decoded['offset'] = $offset;
        header('Content-Type: application/json; charset=utf-8');
        status_header(200);
        echo wp_json_encode($decoded);
    }

    private function get_index() {
        header('Content-Type: application/json; charset=utf-8');
        status_header(200);
        echo wp_json_encode([
            'name'        => 'Nepa Agent API',
            'version'     => NEPA_API_VERSION,
            'description' => 'Machine-readable REST API describing Nepa AB - marketing research, brand tracking, and insight products.',
            'base_url'    => home_url('/agent/v1/'),
            'endpoints'   => array_merge(
                array_map(function ($e) {
                    return home_url('/agent/v1/' . $e . '.json');
                }, array_keys($this->file_endpoints)),
                [home_url('/agent/v1/contact.json') . ' (POST)']
            ),
            'schema'      => home_url('/agent/v1/schema.json'),
            'openapi'     => home_url('/openapi.json'),
            'mcp_server'  => 'https://agentic-mcp-saas-nepa.onrender.com/mcp',
            'agent_page'  => home_url('/agent/'),
            'llms_txt'    => home_url('/llms.txt'),
            'ai_txt'      => home_url('/ai.txt'),
        ]);
    }

    private function get_schema() {
        $this->serve_file(nepa_agent_api_data_path('schema.json'), 'application/json');
    }

    /**
     * Best-effort caller IP for rate limiting. REMOTE_ADDR is used deliberately, not any
     * X-Forwarded-For style header, because those are trivially spoofable unless the site
     * is known to sit behind a specific, trusted reverse proxy that overwrites them - a
     * fact this plugin cannot assume. nepa.com was served through Cloudflare at the
     * 2026-10-16 crawl; when NEPA_TRUST_CLOUDFLARE_IP is set to true, Cloudflare's own
     * CF-Connecting-IP header is used instead (see the constant's note at the top).
     */
    private function get_client_ip() {
        if (NEPA_TRUST_CLOUDFLARE_IP && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
    }

    /**
     * True if this request carries a valid shared secret identifying it as coming from
     * Nepa's own MCP server, rather than an arbitrary direct caller.
     *
     * Requires the NEPA_MCP_SHARED_SECRET constant to be defined (e.g. in wp-config.php) -
     * if it isn't set, this always returns false and every request (including the MCP
     * server's own) is treated as ordinary public traffic. The secret is never generated
     * or stored by this plugin itself; an admin must set the same value here and in the
     * MCP server's NEPA_MCP_SHARED_SECRET environment variable for this to activate.
     */
    private function is_trusted_mcp_request() {
        if (!defined('NEPA_MCP_SHARED_SECRET') || NEPA_MCP_SHARED_SECRET === '') {
            return false;
        }
        $provided = $_SERVER['HTTP_X_NEPA_AGENT_KEY'] ?? '';
        return is_string($provided) && $provided !== '' && hash_equals(NEPA_MCP_SHARED_SECRET, $provided);
    }

    /** Returns [counter key prefix, max] for the coarse shared bucket appropriate to this request (trusted MCP vs plain IP). */
    private function shared_limit_bucket() {
        if ($this->is_trusted_mcp_request()) {
            return ['nepa_contact_rl_mcp_server', self::MCP_RATE_LIMIT_MAX];
        }
        return ['nepa_contact_rl_' . md5($this->get_client_ip()), self::CONTACT_RATE_LIMIT_MAX];
    }

    /**
     * Fixed-window counter key: the window index is part of the key, so a counter expires
     * at the end of its own window and accepted requests never extend it.
     */
    private function window_key($prefix, $window) {
        return $prefix . '_' . (int) floor(time() / $window);
    }

    private function read_counter($key) {
        if (wp_using_ext_object_cache()) {
            return (int) wp_cache_get($key, 'nepa_agent_api');
        }
        return (int) get_transient($key);
    }

    /**
     * Increments a counter and returns the new value. With a persistent object cache
     * (Redis/Memcached) wp_cache_incr is atomic, so concurrent requests can't both slip
     * under the limit. Without one, WordPress transients are read-then-write; two
     * simultaneous requests can then each see the same count, so the limit is a close
     * approximation rather than exact. Install a persistent object cache if exact limits
     * matter.
     */
    private function increment_counter($key, $ttl) {
        if (wp_using_ext_object_cache()) {
            wp_cache_add($key, 0, 'nepa_agent_api', $ttl);
            $value = wp_cache_incr($key, 1, 'nepa_agent_api');
            return $value === false ? PHP_INT_MAX : (int) $value;
        }
        $value = (int) get_transient($key) + 1;
        set_transient($key, $value, $ttl);
        return $value;
    }

    private function shared_key() {
        [$prefix, $max] = $this->shared_limit_bucket();
        return [$this->window_key($prefix, self::CONTACT_RATE_LIMIT_WINDOW), $max];
    }

    private function email_key($email) {
        return $this->window_key('nepa_contact_rl_email_' . md5(strtolower($email)), self::EMAIL_RATE_LIMIT_WINDOW);
    }

    /** Read-only: true if the coarse bucket (IP, or the shared MCP bucket) is already full. */
    private function is_over_shared_limit() {
        [$key, $max] = $this->shared_key();
        return $this->read_counter($key) >= $max;
    }

    /** Read-only: true if this email address has used up its own quota. */
    private function is_email_rate_limited($email) {
        return $this->read_counter($this->email_key($email)) >= self::EMAIL_RATE_LIMIT_MAX;
    }

    /**
     * Consumes quota only for a submission that has passed every check, in this order:
     * the shared bucket first, then the per-email bucket. A request turned away by the
     * shared bucket therefore never uses up the submitter's own email allowance, and a
     * request rejected for its email never uses up the shared pool (it is refused by the
     * read-only check before reaching here). Returns false if either bucket is full at
     * commit time.
     */
    private function commit_rate_limits($email) {
        [$key, $max] = $this->shared_key();
        if ($this->increment_counter($key, self::CONTACT_RATE_LIMIT_WINDOW) > $max) {
            return false;
        }
        if ($this->increment_counter($this->email_key($email), self::EMAIL_RATE_LIMIT_WINDOW) > self::EMAIL_RATE_LIMIT_MAX) {
            return false;
        }
        return true;
    }

    /** Field length limits for contact submissions (characters). */
    const CONTACT_FIELD_LIMITS = [
        'first_name'       => 100,
        'last_name'        => 100,
        'email'            => 254,
        'phone'            => 40,
        'company'          => 200,
        'service_interest' => 200,
        'message'          => 5000,
        'language'         => 35,
        'idempotency_key'  => 100,
    ];
    /** Largest accepted request body, in bytes. */
    const CONTACT_MAX_BODY_BYTES = 20000;

    /**
     * Validates the request's structure and types before anything is sanitized or stored.
     * Returns [values, null] on success or [null, [http_status, code, message]] on failure.
     *
     * Required fields follow Nepa's English HubSpot contact form, where every inquiry is
     * delivered: first name, email, company, phone and message (last name is optional).
     * A single full name is NOT split into first/last name - the caller must send them
     * separately. Consent to process personal data must be explicit (consent.process =
     * true); marketing subscription is separate, optional and off unless
     * consent.marketing_opt_in is exactly true.
     */
    private function validate_contact_body($body) {
        foreach (['agent_context', 'person', 'company', 'inquiry', 'consent'] as $section) {
            if (isset($body[$section]) && !is_array($body[$section])) {
                return [null, [400, 'invalid_structure', "{$section} must be a JSON object."]];
            }
        }
        $fields = [
            'first_name'       => $body['person']['first_name'] ?? null,
            'last_name'        => $body['person']['last_name'] ?? null,
            'email'            => $body['person']['email'] ?? null,
            'phone'            => $body['person']['phone'] ?? null,
            'company'          => $body['company']['name'] ?? null,
            'service_interest' => $body['inquiry']['service_interest'] ?? null,
            'message'          => $body['inquiry']['message'] ?? ($body['inquiry']['project_summary'] ?? null),
            'language'         => $body['inquiry']['language'] ?? null,
            'idempotency_key'  => $body['agent_context']['idempotency_key'] ?? ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null),
        ];
        foreach ($fields as $field => $value) {
            if ($value !== null && !is_string($value)) {
                return [null, [400, 'invalid_type', "{$field} must be a string."]];
            }
            if (is_string($value) && mb_strlen($value) > self::CONTACT_FIELD_LIMITS[$field]) {
                return [null, [422, 'field_too_long', "{$field} must be at most " . self::CONTACT_FIELD_LIMITS[$field] . ' characters.']];
            }
        }
        foreach (['process', 'marketing_opt_in'] as $flag) {
            if (isset($body['consent'][$flag]) && !is_bool($body['consent'][$flag])) {
                return [null, [400, 'invalid_type', "consent.{$flag} must be true or false."]];
            }
        }
        $via = $body['agent_context']['via'] ?? '';
        $values = [
            'first_name'       => sanitize_text_field($fields['first_name'] ?? ''),
            'last_name'        => sanitize_text_field($fields['last_name'] ?? ''),
            'email'            => sanitize_email($fields['email'] ?? ''),
            'phone'            => sanitize_text_field($fields['phone'] ?? ''),
            'company'          => sanitize_text_field($fields['company'] ?? ''),
            'service_interest' => sanitize_text_field($fields['service_interest'] ?? ''),
            'message'          => sanitize_textarea_field($fields['message'] ?? ''),
            'language'         => sanitize_text_field($fields['language'] ?? ''),
            'idempotency_key'  => $fields['idempotency_key'] ?? '',
            'consent_to_process' => ($body['consent']['process'] ?? null) === true,
            'marketing_opt_in' => ($body['consent']['marketing_opt_in'] ?? false) === true,
            'via'              => $via === 'mcp' ? 'mcp' : 'api',
        ];
        if ($values['idempotency_key'] !== '' && !preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $values['idempotency_key'])) {
            return [null, [400, 'invalid_idempotency_key', 'idempotency_key must be 8-100 characters of letters, digits, ".", "_", ":" or "-".']];
        }
        if ($values['first_name'] === '' && !empty($body['person']['name'])) {
            return [null, [422, 'first_name_required', 'Send person.first_name (and person.last_name if known) separately; a full name is not split automatically.']];
        }
        $missing = [];
        foreach (['first_name' => 'person.first_name', 'email' => 'person.email', 'phone' => 'person.phone', 'company' => 'company.name', 'message' => 'inquiry.message'] as $key => $label) {
            if ($values[$key] === '') {
                $missing[] = $label;
            }
        }
        if ($missing) {
            return [null, [422, 'missing_required_fields', 'Required by Nepa\'s contact form: ' . implode(', ', $missing) . '.']];
        }
        if (!is_email($values['email'])) {
            return [null, [422, 'invalid_email', 'person.email must be a valid email address (a business address - Nepa\'s form rejects free webmail domains).']];
        }
        $digits = preg_replace('/\D/', '', $values['phone']);
        if (!preg_match('/^[0-9+()\-.\/ ]+$/', $values['phone']) || strlen($digits) < 7 || strlen($digits) > 20) {
            return [null, [422, 'invalid_phone', 'person.phone must be a phone number with 7-20 digits (digits, spaces, +, -, (, ), . or / only).']];
        }
        if ($values['language'] !== '' && !preg_match('/^[\p{L} ()-]{2,35}$/u', $values['language'])) {
            return [null, [422, 'invalid_language', 'inquiry.language must be a language name or code, e.g. "sv" or "Swedish".']];
        }
        if (!$values['consent_to_process']) {
            return [null, [422, 'processing_consent_required', 'consent.process must be true: the person must agree that Nepa may store and process their personal data ("' . NEPA_HUBSPOT_PROCESSING_TEXT . '").']];
        }
        return [$values, null];
    }

    /** Seconds a reservation blocks repeats: 24 h for an explicit idempotency key, 30 min for identical content. */
    const IDEMPOTENCY_KEY_TTL = 86400;
    const FINGERPRINT_TTL = 1800;

    /**
     * Atomically reserves a submission before any lead is created, so two overlapping
     * requests (including a retry that arrives while the first is still running) can't
     * both create a lead and send an email.
     *
     * The reservation is a row in wp_options whose option_name is unique in the database.
     * It is written with a plain INSERT, which fails for the second of two concurrent
     * requests. add_option() can't be used: it issues INSERT ... ON DUPLICATE KEY UPDATE,
     * so both concurrent callers would "succeed". The object cache is bypassed for the
     * same reason.
     *
     * Every value carries a random token, and every later change - replacing an expired
     * reservation, completing it, releasing it, purging it - is a compare-and-swap: the
     * UPDATE/DELETE matches both the name AND the exact value this request read or wrote.
     * So if two requests both see the same expired reservation, only one of them can
     * remove it; the other's DELETE matches nothing, it re-reads, and finds the first
     * request's new reservation.
     *
     * Returns one of:
     *   ['status' => 'owned',    'name' => ..., 'raw' => <stored value>]
     *   ['status' => 'existing', 'name' => ..., 'current' => [...]]   (live reservation)
     *   ['status' => 'error',    'name' => ...]   (the database could not store or read it)
     */
    private function reserve_submission($scope, $fingerprint, $ttl) {
        global $wpdb;
        $name = 'nepa_lead_res_' . sha1($scope);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $raw = wp_json_encode([
                'fingerprint' => $fingerprint, 'created' => time(), 'ttl' => $ttl,
                'lead_post_id' => 0, 'lead_id' => '', 'token' => wp_generate_password(16, false, false),
            ]);
            $suppress = $wpdb->suppress_errors(true);
            $inserted = $wpdb->insert($wpdb->options, ['option_name' => $name, 'option_value' => $raw, 'autoload' => 'no']);
            $wpdb->suppress_errors($suppress);
            if ($inserted) {
                return ['status' => 'owned', 'name' => $name, 'raw' => $raw];
            }
            $current_raw = $this->read_reservation_raw($name);
            if ($current_raw === null) {
                // The INSERT failed but no row exists: not a duplicate-key collision, the
                // database itself failed (or the row vanished between the two queries -
                // then the next attempt simply inserts again).
                continue;
            }
            $current = $this->decode_reservation($current_raw);
            if ($current && ($current['created'] + $current['ttl']) > time()) {
                return ['status' => 'existing', 'name' => $name, 'current' => $current];
            }
            // Expired or unreadable: remove exactly the value we read. If another request
            // already replaced it, this matches nothing and the loop re-reads.
            $wpdb->delete($wpdb->options, ['option_name' => $name, 'option_value' => $current_raw]);
        }
        return ['status' => 'error', 'name' => $name];
    }

    /** Raw stored value, or null if there is no row. */
    private function read_reservation_raw($name) {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        return is_string($raw) ? $raw : null;
    }

    private function decode_reservation($raw) {
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) && isset($decoded['created'], $decoded['ttl'], $decoded['fingerprint']) ? $decoded : null;
    }

    /** Marks this request's reservation as finished (only if it is still exactly ours). */
    private function complete_reservation($reservation, $lead_post_id, $lead_id) {
        global $wpdb;
        $value = json_decode($reservation['raw'], true);
        $value['lead_post_id'] = (int) $lead_post_id;
        $value['lead_id'] = $lead_id;
        $value['created'] = time();
        $updated = $wpdb->update(
            $wpdb->options,
            ['option_value' => wp_json_encode($value)],
            ['option_name' => $reservation['name'], 'option_value' => $reservation['raw']]
        );
        return $updated === 1;
    }

    /** Releases this request's reservation (only if it is still exactly ours). */
    private function release_reservation($reservation) {
        global $wpdb;
        $wpdb->delete($wpdb->options, ['option_name' => $reservation['name'], 'option_value' => $reservation['raw']]);
    }

    /**
     * Response for a stored lead, with its HubSpot delivery state reported separately from
     * the local record: HTTP 200 when stored (delivery delivered/pending/retrying/uncertain/
     * under review/disabled), 422 when HubSpot rejected the submitted details.
     */
    private function lead_response($lead_post_id, $lead_id, $duplicate) {
        $hubspot = $this->hubspot ? $this->hubspot->public_status($lead_post_id) : ['status' => 'disabled'];
        $rejected = $hubspot['status'] === 'rejected';
        status_header($rejected ? 422 : 200);
        header('Content-Type: application/json; charset=utf-8');
        $body = [
            'status'    => $rejected ? 'rejected' : 'received',
            'duplicate' => $duplicate,
            'lead_id'   => $lead_id,
            'message'   => ($duplicate ? 'This inquiry was already submitted - nothing new was created or sent. ' : '') . $hubspot['message'],
            'hubspot'   => $hubspot,
        ];
        if ($rejected) {
            $body['error'] = 'hubspot_rejected';
        } else {
            $body['next_step'] = 'A Nepa representative will contact the provided email within 1-2 business days.';
        }
        echo wp_json_encode($body);
    }

    /**
     * Writes one piece of lead metadata and confirms it was stored, retrying once.
     * update_post_meta() returns false both on failure and when the stored value is
     * already identical, so the stored value is read back to tell the two apart.
     */
    private function store_meta($post_id, $key, $value) {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            // update_post_meta() unslashes its input; slash first so backslashes in a
            // person's text (or escaped quotes in JSON) are stored exactly.
            update_post_meta($post_id, $key, wp_slash($value));
            if ((string) get_post_meta($post_id, $key, true) === (string) $value) {
                return true;
            }
        }
        error_log("Nepa Agent API: could not store meta '{$key}' for lead post #{$post_id}.");
        return false;
    }

    /**
     * Accepts a qualified inquiry from an AI agent acting on behalf of a person.
     * Requires explicit confirmation that the human authorized this submission -
     * enforced here as the final layer of a three-layer gate (MCP tool description,
     * MCP Zod schema, this PHP check) so an agent cannot submit without consent.
     *
     * Order of operations:
     *   1. cheap checks (shared rate limit, body size, JSON, consent, field validation);
     *   2. atomic reservation of the idempotency key - or, without one, of the content
     *      fingerprint. A repeat of a finished submission gets the original lead back
     *      (duplicate: true); a repeat of one still being processed gets 409 in_progress;
     *      the same key with different content gets 409 idempotency_key_reused;
     *   3. rate-limit quota is consumed only for a new, valid submission;
     *   4. the lead is stored as a private nepa_lead post - this is the acceptance
     *      condition. If it can't be stored the reservation is released and the caller
     *      gets 503 (safe to retry);
     *   5. the notification email state is recorded as pending, the email is sent, and the
     *      result is recorded. Any lead not confirmed as sent (including one whose retry
     *      state couldn't be stored at all) is retried by the hourly job for up to
     *      RETRY_WINDOW_DAYS; if the email was sent but its success couldn't be recorded,
     *      the job may send it again (at-least-once - a duplicate notification is
     *      preferred over a lost one).
     */
    private function contact_submission() {
        if ($this->is_over_shared_limit()) {
            $this->error(429, 'rate_limited', 'Too many submissions from this address. Please try again later.');
            return;
        }

        $length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($length > self::CONTACT_MAX_BODY_BYTES) {
            $this->error(413, 'payload_too_large', 'Request body must be at most ' . self::CONTACT_MAX_BODY_BYTES . ' bytes.');
            return;
        }
        $raw = file_get_contents('php://input', false, null, 0, self::CONTACT_MAX_BODY_BYTES + 1);
        if (is_string($raw) && strlen($raw) > self::CONTACT_MAX_BODY_BYTES) {
            $this->error(413, 'payload_too_large', 'Request body must be at most ' . self::CONTACT_MAX_BODY_BYTES . ' bytes.');
            return;
        }
        $body = json_decode((string) $raw, true);
        if (!is_array($body)) {
            $this->error(400, 'invalid_json', 'Request body must be a JSON object.');
            return;
        }

        if (isset($body['agent_context']) && !is_array($body['agent_context'])) {
            $this->error(400, 'invalid_structure', 'agent_context must be a JSON object.');
            return;
        }
        if (($body['agent_context']['user_authorized_submission'] ?? null) !== true) {
            $this->error(403, 'not_authorized', 'agent_context.user_authorized_submission must be true.');
            return;
        }

        [$v, $invalid] = $this->validate_contact_body($body);
        if ($invalid) {
            $this->error($invalid[0], $invalid[1], $invalid[2]);
            return;
        }

        $fingerprint = hash('sha256', implode("\n", [strtolower($v['email']), $v['first_name'], $v['last_name'], $v['phone'], $v['company'],
            $v['service_interest'], $v['message'], $v['language'], $v['marketing_opt_in'] ? '1' : '0']));
        $has_key = $v['idempotency_key'] !== '';
        $scope = $has_key ? 'key:' . $v['idempotency_key'] : 'fp:' . $fingerprint;
        $ttl = $has_key ? self::IDEMPOTENCY_KEY_TTL : self::FINGERPRINT_TTL;

        $reservation = $this->reserve_submission($scope, $fingerprint, $ttl);
        if ($reservation['status'] === 'error') {
            error_log('Nepa Agent API: could not store or read a submission reservation (database error).');
            $this->error(503, 'reservation_unavailable', 'The inquiry could not be accepted right now because of a temporary storage problem. Nothing was saved. Please retry shortly; retrying is safe.');
            return;
        }
        if ($reservation['status'] === 'existing') {
            $current = $reservation['current'];
            if (!hash_equals((string) $current['fingerprint'], $fingerprint)) {
                $this->error(409, 'idempotency_key_reused', 'This idempotency_key was already used for a different inquiry in the last 24 hours. Use a new key for a new inquiry.');
                return;
            }
            if (!empty($current['lead_post_id'])) {
                // A repeat never creates a lead or submits to HubSpot again: report the current state.
                $this->lead_response((int) $current['lead_post_id'], (string) $current['lead_id'], true);
                return;
            }
            $this->error(409, 'in_progress', 'An identical submission is still being processed. Wait a few seconds and retry with the same details.');
            return;
        }

        if ($this->is_email_rate_limited($v['email']) || !$this->commit_rate_limits($v['email'])) {
            $this->release_reservation($reservation);
            $this->error(429, 'rate_limited', 'Too many submissions. Please try again later.');
            return;
        }

        $lead_id = 'nepa-lead-' . gmdate('Y') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
        $full_name = trim($v['first_name'] . ' ' . $v['last_name']);
        $submitted_at_ms = (string) (int) round(microtime(true) * 1000);
        $hubspot_message = Nepa_HubSpot_Delivery::compose_message($v['message'], $v['service_interest'], $v['language'], $v['via'], $lead_id);
        // Everything HubSpot needs, stored in the lead's own row by the same INSERT that
        // creates it: the lead and its complete submission data exist together or not at all.
        $snapshot = Nepa_HubSpot_Delivery::encode_snapshot([
            'lead_id'            => $lead_id,
            'first_name'         => $v['first_name'],
            'last_name'          => $v['last_name'],
            'email'              => $v['email'],
            'phone'              => $v['phone'],
            'company'            => $v['company'],
            'message'            => $hubspot_message,
            'consent_to_process' => true,
            'marketing_opt_in'   => $v['marketing_opt_in'],
            'submitted_at_ms'    => $submitted_at_ms,
        ]);
        $email = $v['email'];
        $company = $v['company'];

        // The post itself holds everything needed to follow up and to rebuild the
        // notification email, even if every meta write below failed.
        // wp_insert_post() expects slashed data and unslashes it before saving.
        $lead_post_id = wp_insert_post(wp_slash([
            'post_type'             => 'nepa_lead',
            'post_status'           => 'private',
            'post_title'            => "{$lead_id}: {$full_name} <{$email}>" . ($company ? " ({$company})" : ''),
            'post_content'          => "Email: {$email}\nPhone: {$v['phone']}\nCompany: {$company}\n"
                . ($v['service_interest'] !== '' ? "Service interest: {$v['service_interest']}\n" : '')
                . ($v['language'] !== '' ? "Preferred language: {$v['language']}\n" : '')
                . 'Marketing opt-in: ' . ($v['marketing_opt_in'] ? 'yes' : 'no') . "\n\n{$v['message']}",
            'post_content_filtered' => $snapshot,
        ]), true);

        if (is_wp_error($lead_post_id) || !$lead_post_id) {
            $this->release_reservation($reservation);
            error_log("Nepa Agent API: failed to store lead {$lead_id}: " . (is_wp_error($lead_post_id) ? $lead_post_id->get_error_message() : 'no post id'));
            $this->error(503, 'not_recorded', 'The inquiry could not be recorded right now. Nothing was saved and no notification was sent. Please retry shortly; retrying is safe.');
            return;
        }

        if (!$this->complete_reservation($reservation, $lead_post_id, $lead_id)) {
            // The reservation still exists, so repeats get 409 in_progress (never a duplicate lead) until it expires.
            error_log("Nepa Agent API: could not mark reservation complete for lead {$lead_id}; repeats will get 409 until it expires.");
        }

        // Required state first: the retry job selects on mail_sent / mail_attempts.
        $retry_state_ok = $this->store_meta($lead_post_id, 'lead_id', $lead_id)
            & $this->store_meta($lead_post_id, 'mail_sent', '0')
            & $this->store_meta($lead_post_id, 'mail_attempts', '0');
        if (!$retry_state_ok) {
            error_log("Nepa Agent API: lead {$lead_id} (post #{$lead_post_id}) is stored but its retry state is incomplete; the hourly job will still pick it up because it is not marked as sent.");
        }
        $details = [
            'email'                      => $email,
            'name'                       => $full_name,
            'first_name'                 => $v['first_name'],
            'last_name'                  => $v['last_name'],
            'phone'                      => $v['phone'],
            'company'                    => $company,
            'service_interest'           => $v['service_interest'],
            'language'                   => $v['language'],
            'message'                    => $v['message'],
            'submitted_at'               => current_time('mysql'),
            'fingerprint'                => $fingerprint,
            'idempotency_key'            => $v['idempotency_key'],
            // Three separate records: authorization to submit, consent to processing, marketing opt-in.
            'user_authorized_submission' => '1',
            'authorization_asserted_at'  => gmdate('c'),
            'consent_to_process'         => '1',
            'consent_process_text'       => NEPA_HUBSPOT_PROCESSING_TEXT,
            'marketing_opt_in'           => $v['marketing_opt_in'] ? '1' : '0',
            'marketing_consent_text'     => NEPA_HUBSPOT_SUBSCRIPTION_LABEL . ' | ' . NEPA_HUBSPOT_SUBSCRIPTION_NOTICE,
            'channel'                    => $this->is_trusted_mcp_request() ? 'mcp' : ($v['via'] === 'mcp' ? 'mcp (asserted)' : 'direct'),
            'client_ip_hash'             => hash_hmac('sha256', $this->get_client_ip(), wp_salt('auth')),
            // Copies for wp-admin; delivery itself reads the snapshot stored in the post row.
            'hubspot_message'            => $hubspot_message,
            // Fixed submission time: the same value on every attempt, used to find the submission in HubSpot.
            'hubspot_submitted_at_ms'    => $submitted_at_ms,
            // No hubspot_* delivery state here: the worker may already be delivering this
            // lead (it picks up leads with no status), and these writes must not reset it.
        ];
        foreach ($details as $key => $value) {
            if ($value !== '') {
                $this->store_meta($lead_post_id, $key, $value);
            }
        }
        if (Nepa_HubSpot_Delivery::enabled()) {
            if ($this->hubspot->read_snapshot($lead_post_id) === null) {
                error_log("Nepa Agent API: lead {$lead_id} was stored but its submission data could not be read back; it goes to manual review.");
            }
            // Initial status + first attempt under the same per-lead lock as the worker, never
            // overwriting a status the worker already recorded. Failures are retried by the
            // worker (every 5 minutes) with backoff.
            $this->hubspot->initialize_and_process($lead_post_id);
        } else {
            $this->store_meta($lead_post_id, 'hubspot_status', 'disabled');
        }

        $sent = $this->send_lead_notification($lead_post_id);
        $this->store_meta($lead_post_id, 'mail_attempts', '1');
        if (!$this->store_meta($lead_post_id, 'mail_sent', $sent ? '1' : '0') && $sent) {
            error_log("Nepa Agent API: notification for lead {$lead_id} was sent but could not be marked as sent; the retry job may send it again.");
        }
        if (!$sent) {
            error_log("Nepa Agent API: wp_mail() failed for lead {$lead_id}; queued for retry.");
        }

        $this->lead_response($lead_post_id, $lead_id, false);
    }

    /** Builds the notification from the stored post itself (no dependency on extra meta) and sends it. */
    private function send_lead_notification($post_id, $resend = false) {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }
        $lead_id = (string) get_post_meta($post_id, 'lead_id', true);
        if ($lead_id === '') {
            $lead_id = strtok((string) $post->post_title, ':');
        }
        $name = (string) get_post_meta($post_id, 'name', true);
        $email = (string) get_post_meta($post_id, 'email', true);
        $headers = ($name !== '' && $email !== '') ? ["Reply-To: {$name} <{$email}>"] : [];
        $hubspot_status = (string) get_post_meta($post_id, 'hubspot_status', true);
        $message = "Lead: {$post->post_title}\n\n{$post->post_content}\n\n(Saved as Agent Lead #{$post_id} in wp-admin."
            . ($hubspot_status !== '' ? " HubSpot delivery at the time of this email: {$hubspot_status}." : '') . ")\n"
            . ($resend ? "(Re-sent automatically after an earlier send failure.)\n" : '');
        return (bool) wp_mail(self::LEAD_NOTIFY_TO, "New agent-submitted inquiry: {$lead_id}", $message, $headers);
    }

    /** Recipient of new-lead notifications. */
    const LEAD_NOTIFY_TO = 'info@nepa.com';
    /** Notification send attempts before a lead is left for manual follow-up in wp-admin. */
    const MAX_MAIL_ATTEMPTS = 6;

    public function schedule_jobs() {
        if (!wp_next_scheduled('nepa_agent_api_retry_notifications')) {
            wp_schedule_event(time() + 300, 'hourly', 'nepa_agent_api_retry_notifications');
        }
        if (!wp_next_scheduled('nepa_agent_api_purge_leads')) {
            wp_schedule_event(time() + 600, 'daily', 'nepa_agent_api_purge_leads');
        }
    }

    /** Days after which an unsent notification is no longer retried automatically. */
    const RETRY_WINDOW_DAYS = 7;

    /**
     * Hourly: re-send notifications that haven't been confirmed as sent.
     *
     * Selection is "not confirmed sent" rather than "marked failed", so a lead whose
     * retry state could never be written (mail_sent / mail_attempts missing because the
     * metadata writes failed) is still picked up - a notification is only skipped once
     * mail_sent = '1' was actually stored. Exhausted leads (6 attempts) are excluded in
     * the query itself, oldest first, so they can't crowd out pending ones. Leads under
     * 5 minutes old are skipped (their own request may still be sending), and leads older
     * than RETRY_WINDOW_DAYS are left for manual follow-up - which also bounds re-sending
     * if even the attempt counter can't be stored.
     */
    public function retry_failed_notifications() {
        $ids = get_posts([
            'post_type'      => 'nepa_lead',
            'post_status'    => 'private',
            'posts_per_page' => 20,
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'ASC',
            'date_query'     => [[
                'before' => '5 minutes ago',
                'after'  => self::RETRY_WINDOW_DAYS . ' days ago',
                'column' => 'post_date_gmt',
            ]],
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'relation' => 'OR',
                    ['key' => 'mail_sent', 'compare' => 'NOT EXISTS'],
                    ['key' => 'mail_sent', 'value' => '1', 'compare' => '!='],
                ],
                [
                    'relation' => 'OR',
                    ['key' => 'mail_attempts', 'compare' => 'NOT EXISTS'],
                    ['key' => 'mail_attempts', 'value' => self::MAX_MAIL_ATTEMPTS, 'compare' => '<', 'type' => 'NUMERIC'],
                ],
            ],
            'no_found_rows'  => true,
        ]);
        foreach ($ids as $id) {
            $attempts = (int) get_post_meta($id, 'mail_attempts', true) + 1;
            $sent = $this->send_lead_notification($id, true);
            $this->store_meta($id, 'mail_attempts', (string) $attempts);
            $this->store_meta($id, 'mail_sent', $sent ? '1' : '0');
            if (!$sent && $attempts >= self::MAX_MAIL_ATTEMPTS) {
                error_log("Nepa Agent API: giving up on notification for lead post #{$id} after {$attempts} attempts - follow up from wp-admin -> Agent Leads.");
            }
        }
    }

    /**
     * Daily: deletes expired submission reservations, and - when NEPA_LEAD_RETENTION_DAYS
     * is above 0 - permanently deletes leads older than that, including leads in the
     * Trash ('any' excludes trashed posts, so every status is listed explicitly).
     */
    public function purge_expired_leads() {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500",
            $wpdb->esc_like('nepa_lead_res_') . '%'
        ));
        // Expired HubSpot delivery leases left by a crashed process (normally released at once).
        $leases = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500",
            $wpdb->esc_like('nepa_hs_lease_') . '%'
        ));
        foreach ((array) $leases as $row) {
            $lease = json_decode((string) $row->option_value, true);
            if (!is_array($lease) || ($lease['expires'] ?? 0) < time()) {
                $wpdb->delete($wpdb->options, ['option_name' => $row->option_name, 'option_value' => $row->option_value]);
            }
        }
        foreach ((array) $rows as $row) {
            $value = json_decode((string) $row->option_value, true);
            if (!is_array($value) || ($value['created'] ?? 0) + ($value['ttl'] ?? 0) < time()) {
                // Delete exactly the expired value read above - never a reservation that a
                // request created in the meantime under the same name.
                $wpdb->delete($wpdb->options, ['option_name' => $row->option_name, 'option_value' => $row->option_value]);
            }
        }

        $days = (int) NEPA_LEAD_RETENTION_DAYS;
        if ($days <= 0) {
            return;
        }
        $ids = get_posts([
            'post_type'      => 'nepa_lead',
            'post_status'    => ['publish', 'private', 'draft', 'pending', 'future', 'trash'],
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'date_query'     => [['before' => "{$days} days ago", 'column' => 'post_date_gmt']],
            'no_found_rows'  => true,
        ]);
        foreach ($ids as $id) {
            wp_delete_post($id, true);
        }
    }

    private function error($http_status, $code, $message) {
        status_header($http_status);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode(['error' => $code, 'message' => $message]);
    }
}

$nepa_agent_api = new Nepa_Agent_API();
$nepa_agent_api->hubspot = new Nepa_HubSpot_Delivery();

register_activation_hook(__FILE__, function () use ($nepa_agent_api) {
    // The rules must be registered in this request before flushing - 'init' has already
    // run by the time an activation hook fires, so without this call the flush would
    // store a rule set that doesn't contain /agent/, /agent/v1/*.json or /openapi.json.
    $nepa_agent_api->add_rewrite_rules();
    $nepa_agent_api->register_lead_post_type();
    $nepa_agent_api->grant_lead_capabilities_to_administrators();
    $nepa_agent_api->schedule_jobs();
    $nepa_agent_api->hubspot->schedule();
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('nepa_agent_api_retry_notifications');
    wp_clear_scheduled_hook('nepa_agent_api_purge_leads');
    wp_clear_scheduled_hook('nepa_agent_api_hubspot_worker');
    flush_rewrite_rules();
});
