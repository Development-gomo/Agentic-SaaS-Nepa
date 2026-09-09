<?php
/**
 * Plugin Name: Nepa Agent API
 * Description: Serves clean, WordPress-agnostic JSON endpoints at /agent/v1/ for AI agents and LLMs. Companion to /agent/ (agent-page.html), llms.txt, ai.txt, and openapi.json.
 * Version: 1.1.0
 * Author: Nepa AB
 */

if (!defined('ABSPATH')) {
    exit;
}

define('NEPA_API_VERSION', '1.1.0');
define('NEPA_API_DIR', plugin_dir_path(__FILE__));

require_once NEPA_API_DIR . 'includes/class-sync.php';

class Nepa_Agent_API {

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
            header('Access-Control-Allow-Headers: Content-Type');
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
                $this->serve_paginated_json('blog-posts.json', 'posts');
                break;
            case 'press-releases':
                $this->serve_paginated_json('press-releases.json', 'releases');
                break;
            default:
                if (isset($this->file_endpoints[$endpoint])) {
                    $this->serve_file(NEPA_API_DIR . 'data/' . $this->file_endpoints[$endpoint], 'application/json');
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
        $path = NEPA_API_DIR . 'data/' . $filename;
        if (!file_exists($path)) {
            return null;
        }
        return json_decode(file_get_contents($path), true);
    }

    /** Filter a list-shaped data file by one or more query-string fields (exact, case-insensitive match). */
    private function serve_filtered_json($filename, $list_key, $filter_fields) {
        $decoded = $this->load_json($filename);
        if ($decoded === null) {
            $this->error(404, 'not_found', 'Data file not found.');
            return;
        }
        $items = $decoded[$list_key] ?? [];
        foreach ($filter_fields as $field) {
            $value = isset($_GET[$field]) ? sanitize_text_field(wp_unslash($_GET[$field])) : null;
            if ($value !== null && $value !== '') {
                $items = array_values(array_filter($items, function ($item) use ($field, $value) {
                    return isset($item[$field]) && strcasecmp((string) $item[$field], $value) === 0;
                }));
            }
        }
        $decoded[$list_key] = $items;
        $decoded['count'] = count($items);
        header('Content-Type: application/json; charset=utf-8');
        status_header(200);
        echo wp_json_encode($decoded);
    }

    /** Paginate a large list-shaped data file (blog posts, press releases) via ?limit=&offset=. */
    private function serve_paginated_json($filename, $list_key) {
        $decoded = $this->load_json($filename);
        if ($decoded === null) {
            $this->error(404, 'not_found', 'Data file not found.');
            return;
        }
        $items = $decoded[$list_key] ?? [];
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
            'mcp_server'  => 'https://nepa.com/mcp',
            'agent_page'  => home_url('/agent/'),
            'llms_txt'    => home_url('/llms.txt'),
            'ai_txt'      => home_url('/ai.txt'),
        ]);
    }

    private function get_schema() {
        $this->serve_file(NEPA_API_DIR . 'data/schema.json', 'application/json');
    }

    /**
     * Best-effort caller IP for rate limiting. REMOTE_ADDR is used deliberately, not any
     * X-Forwarded-For style header, because those are trivially spoofable unless the site
     * is known to sit behind a specific, trusted reverse proxy that overwrites them - a
     * fact this plugin cannot assume. If Nepa's hosting *does* sit behind such a proxy
     * (Cloudflare, a load balancer, etc.), update this method to read the proxy's own
     * documented client-IP header instead.
     */
    private function get_client_ip() {
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

    /** Returns [transient key, max] for the coarse shared bucket appropriate to this request (trusted MCP vs plain IP). */
    private function shared_limit_bucket() {
        if ($this->is_trusted_mcp_request()) {
            return ['nepa_contact_rl_mcp_server', self::MCP_RATE_LIMIT_MAX];
        }
        return ['nepa_contact_rl_' . md5($this->get_client_ip()), self::CONTACT_RATE_LIMIT_MAX];
    }

    /**
     * Cheap, read-only check of the coarse connection-level bucket (IP, or the shared MCP
     * bucket) - true if it's already at capacity. Deliberately does NOT increment anything:
     * it exists purely so a request that's doomed to be rejected anyway (the bucket is
     * already full) can be turned away before spending any effort parsing its body. The
     * actual commit - the write that makes a request "count" against this bucket - happens
     * later, in commit_shared_limit_usage(), only for requests that get all the way through
     * the per-email check below. See that method's doc comment for why the order matters.
     */
    private function is_over_shared_limit() {
        [$key, $max] = $this->shared_limit_bucket();
        return ((int) get_transient($key)) >= $max;
    }

    /**
     * Actually consumes one unit of the coarse shared bucket's quota - called ONLY after a
     * submission has already passed the per-email check, so that a request rejected for
     * repeating the same email doesn't ALSO eat into the pool other, distinct submitters
     * depend on. Getting this ordering backwards was a real bug in an earlier version:
     * checking-and-incrementing this shared bucket before the email check meant every
     * rejected repeat of one email still consumed a slot, so someone retrying a single
     * email enough times could exhaust the entire shared MCP quota and lock out everyone
     * else relayed through it - the opposite of what adding a per-email check was for.
     * Returns false (caller must reject) if the bucket is at capacity at commit time.
     */
    private function commit_shared_limit_usage() {
        [$key, $max] = $this->shared_limit_bucket();
        $count = (int) get_transient($key);
        if ($count >= $max) {
            return false;
        }
        set_transient($key, $count + 1, self::CONTACT_RATE_LIMIT_WINDOW);
        return true;
    }

    /**
     * Per-submitted-email throttle - the actual mechanism that keeps distinct real
     * submitters fair to each other regardless of IP or MCP-bucket sharing (see
     * EMAIL_RATE_LIMIT_MAX's doc comment for why a connection-keyed bucket, however large,
     * can't do this on its own). Note this key is the address the caller CLAIMS, unverified
     * - it stops the same address being hit repeatedly, but does not by itself distinguish
     * a real human from a script cycling through fabricated addresses. That broader volume
     * case is exactly what the coarse shared bucket above still exists to catch: a fresh,
     * never-seen-before email still consumes shared quota once it passes this check, so
     * many distinct (even fabricated) addresses still hit the shared ceiling eventually.
     */
    private function is_email_rate_limited($email) {
        $key = 'nepa_contact_rl_email_' . md5(strtolower($email));
        $count = (int) get_transient($key);
        if ($count >= self::EMAIL_RATE_LIMIT_MAX) {
            return true;
        }
        set_transient($key, $count + 1, self::EMAIL_RATE_LIMIT_WINDOW);
        return false;
    }

    /**
     * Accepts a qualified inquiry from an AI agent acting on behalf of a person.
     * Requires explicit confirmation that the human authorized this submission -
     * enforced here as the final layer of a three-layer gate (MCP tool description,
     * MCP Zod schema, this PHP check) so an agent cannot submit without consent.
     *
     * The inquiry is persisted as a `nepa_lead` post BEFORE attempting to email a
     * notification, so a lead is never silently lost if wp_mail() fails (misconfigured
     * SMTP, host outage, etc.) - the response and this data are the source of truth,
     * not the email.
     */
    private function contact_submission() {
        // Cheap, no-side-effect check: if the shared bucket is already full, reject before
        // spending any effort parsing the body. Does not itself consume any quota - see
        // commit_shared_limit_usage() for where a request actually "counts".
        if ($this->is_over_shared_limit()) {
            $this->error(429, 'rate_limited', 'Too many submissions from this address. Please try again later.');
            return;
        }

        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $this->error(400, 'invalid_json', 'Request body must be valid JSON.');
            return;
        }

        if (($body['agent_context']['user_authorized_submission'] ?? null) !== true) {
            $this->error(403, 'not_authorized', 'agent_context.user_authorized_submission must be true.');
            return;
        }

        $name = sanitize_text_field($body['person']['name'] ?? '');
        $email = sanitize_email($body['person']['email'] ?? '');
        $company = sanitize_text_field($body['company']['name'] ?? '');
        $service_interest = sanitize_text_field($body['inquiry']['service_interest'] ?? '');
        $project_summary = sanitize_textarea_field($body['inquiry']['project_summary'] ?? '');

        if (!$name || !$email || !is_email($email)) {
            $this->error(422, 'invalid_input', 'person.name and a valid person.email are required.');
            return;
        }

        if ($this->is_email_rate_limited($email)) {
            $this->error(429, 'rate_limited', 'Too many submissions for this email address. Please try again later.');
            return;
        }

        // Only now - after the submission has survived the per-email check - does it
        // actually consume a unit of the shared bucket's quota. A repeat of an
        // already-rate-limited email never reaches this line, so it can't eat into the
        // pool that other, distinct submitters depend on (see commit_shared_limit_usage()).
        if (!$this->commit_shared_limit_usage()) {
            $this->error(429, 'rate_limited', 'Too many submissions from this address. Please try again later.');
            return;
        }

        $lead_id = 'nepa-lead-' . gmdate('Y') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));

        // The contact's name and email are embedded directly in the post's own title/content
        // (not just as post_meta) so that a successful wp_insert_post() ALONE - independent
        // of whether any individual update_post_meta() call below also succeeds - already
        // guarantees the one piece of information staff actually need to follow up: how to
        // reach this person. post_meta is still written for structured querying, but its
        // success is no longer the only thing standing between a submission and total loss.
        $lead_post_id = wp_insert_post([
            'post_type'    => 'nepa_lead',
            'post_status'  => 'private',
            'post_title'   => "{$lead_id}: {$name} <{$email}>" . ($company ? " ({$company})" : ''),
            'post_content' => "Email: {$email}\nService interest: {$service_interest}\n\n{$project_summary}",
        ], true);

        $persisted = !is_wp_error($lead_post_id);
        $meta_ok = false;
        if ($persisted) {
            // The 'email' field is the one piece of metadata that actually matters for
            // follow-up (it's also safely embedded in post_title/post_content above as a
            // fallback) - its write result is checked and logged; the rest are best-effort.
            $meta_ok = update_post_meta($lead_post_id, 'email', $email) !== false;
            if (!$meta_ok) {
                error_log("Nepa Agent API: update_post_meta('email') failed for lead {$lead_id} (post #{$lead_post_id}) - email is still recoverable from the post title/content.");
            }
            update_post_meta($lead_post_id, 'lead_id', $lead_id);
            update_post_meta($lead_post_id, 'name', $name);
            update_post_meta($lead_post_id, 'company', $company);
            update_post_meta($lead_post_id, 'service_interest', $service_interest);
            update_post_meta($lead_post_id, 'submitted_at', current_time('mysql'));
        } else {
            // Even if persistence fails, still attempt the email below rather than losing the lead entirely.
            error_log("Nepa Agent API: failed to persist lead {$lead_id} as nepa_lead post: " . $lead_post_id->get_error_message());
        }

        $subject = "New agent-submitted inquiry: {$lead_id}";
        $message = "Lead ID: {$lead_id}\n"
            . "Name: {$name}\n"
            . "Email: {$email}\n"
            . "Company: {$company}\n"
            . "Service interest: {$service_interest}\n\n"
            . "Project summary:\n{$project_summary}\n"
            . ($persisted
                ? ("\n(Saved as Agent Lead #{$lead_post_id} in wp-admin" . ($meta_ok ? '' : ' - note: structured metadata failed to save, but the contact details above are in the post title/content') . ".)\n")
                : "\n(NOTE: failed to save as a wp-admin record - this email is the only copy.)\n");

        $mail_sent = wp_mail(
            'info@nepa.com',
            $subject,
            $message,
            ["Reply-To: {$name} <{$email}>"]
        );

        if (!$mail_sent) {
            error_log("Nepa Agent API: wp_mail() failed for lead {$lead_id}.");
            if ($persisted) {
                update_post_meta($lead_post_id, 'mail_sent', false);
            }
        } elseif ($persisted) {
            update_post_meta($lead_post_id, 'mail_sent', true);
        }

        // The inquiry is durably recorded (nepa_lead post) independently of email delivery,
        // so this remains a genuine "received" as long as persistence succeeded - even if
        // the notification email itself failed, nothing is lost and staff can review Agent
        // Leads in wp-admin. Only report failure if BOTH channels failed.
        if (!$persisted && !$mail_sent) {
            $this->error(502, 'delivery_failed', 'Unable to record or notify about this inquiry right now. Please try again shortly or use the contact form directly.');
            return;
        }

        status_header(200);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode([
            'status'    => 'received',
            'message'   => 'Thank you - a member of the Nepa team will follow up shortly.',
            'lead_id'   => $lead_id,
            'next_step' => 'A Nepa representative will contact the provided email within 1-2 business days.',
        ]);
    }

    private function error($http_status, $code, $message) {
        status_header($http_status);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode(['error' => $code, 'message' => $message]);
    }
}

$nepa_agent_api = new Nepa_Agent_API();

register_activation_hook(__FILE__, function () use ($nepa_agent_api) {
    $nepa_agent_api->register_lead_post_type();
    $nepa_agent_api->grant_lead_capabilities_to_administrators();
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});
