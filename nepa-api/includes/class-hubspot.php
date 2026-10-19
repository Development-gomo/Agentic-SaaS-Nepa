<?php
/**
 * HubSpot form delivery for agent-submitted inquiries.
 *
 * Flow: /agent/v1/contact.json stores the inquiry as a private nepa_lead post (the durable
 * local record), then this class submits it to Nepa's English contact form in HubSpot with
 * the Forms submission API (v3):
 *
 *   POST {NEPA_HUBSPOT_SUBMIT_BASE}/submissions/v3/integration/secure/submit/{portal}/{form}
 *     (with NEPA_HUBSPOT_TOKEN, a token with the `forms` scope)
 *   POST {NEPA_HUBSPOT_SUBMIT_BASE}/submissions/v3/integration/submit/{portal}/{form}
 *     (without a token)
 *
 * HubSpot labels this endpoint "legacy", but it is the only API that creates a real
 * submission on a form (so the form's automation runs); the newer Forms APIs only manage
 * form definitions. Checked against developers.hubspot.com on 2026-10-19.
 *
 * Delivery state is kept per lead, separately from the local record and the email:
 *   disabled       HubSpot delivery is switched off (NEPA_HUBSPOT_ENABLED not true)
 *   pending        stored, not attempted yet
 *   sending        an attempt is in flight (set BEFORE the request is sent)
 *   delivered      HubSpot answered 200, or the submission was found by lookup
 *   retryable      temporary failure (429, 500/503, connection refused); retried with backoff
 *   uncertain      HubSpot may have accepted it (timeout, 502/504, or a crash mid-request)
 *   rejected       HubSpot refused the submitted data (e.g. BLOCKED_EMAIL); not retried
 *   manual_review  needs a person: configuration/credential errors, retries exhausted, or
 *                  an uncertain outcome that can't be checked automatically
 *
 * Duplicate handling. HubSpot's submission API has no idempotency key, so exactly-once
 * delivery can't be guaranteed. What this class does:
 *   - each lead is submitted with a fixed submittedAt (the lead's creation time, ms);
 *   - 'sending' is stored before every request; if the result can't be recorded (crash,
 *     lost response, failed status write), the lead is treated as 'uncertain', never resent
 *     blindly;
 *   - an uncertain lead is resent only after the submission was NOT found by looking up
 *     the form's submissions (GET /form-integrations/v1/submissions/forms/{form}, needs the
 *     token) for the same submittedAt and email, at least 10 minutes after the attempt;
 *   - without a token, uncertain leads go to manual review instead of being resent.
 * Remaining risk: HubSpot accepts a submission but it is not yet visible to the lookup
 * after 10 minutes (or beyond the pages searched); the resend then creates a second
 * submission for the same contact. Recovery: delete the duplicate submission in HubSpot.
 */

if (!defined('ABSPATH')) {
    exit;
}

foreach ([
    'NEPA_HUBSPOT_ENABLED'                => false,
    'NEPA_HUBSPOT_PORTAL_ID'              => '146131568',
    'NEPA_HUBSPOT_FORM_ID'                => '7c631e31-cc3b-44ba-a580-113ab3489db6', // English contact form (/contact-us/)
    'NEPA_HUBSPOT_TOKEN'                  => '',
    'NEPA_HUBSPOT_SUBMIT_BASE'            => 'https://api.hsforms.com',
    'NEPA_HUBSPOT_API_BASE'               => 'https://api.hubapi.com',
    // Consent wording and subscription type, exactly as configured on the English form
    // (form definition updated 2026-05-22). Change only together with the form.
    'NEPA_HUBSPOT_PROCESSING_TEXT'        => 'I agree to allow Nepa to store and process my personal data.',
    'NEPA_HUBSPOT_SUBSCRIPTION_TYPE_ID'   => 789062182,
    'NEPA_HUBSPOT_SUBSCRIPTION_LABEL'     => "I have read and understood Nepa's Privacy Policy",
    'NEPA_HUBSPOT_SUBSCRIPTION_NOTICE'    => 'Nepa may occasionally share updates on products, services, events, and exclusive invitations to webinars. You can unsubscribe anytime by clicking the link at the bottom of any email. For details on our privacy practices and your rights, please check out our privacy policy (https://nepa.com/privacy-policy/).',
] as $constant => $default) {
    if (!defined($constant)) {
        define($constant, $default);
    }
}

class Nepa_HubSpot_Delivery {

    const MAX_ATTEMPTS = 8;
    /** Seconds before attempt n+1 (index = attempts made so far - 1), plus up to 20% jitter. */
    const BACKOFF = [60, 300, 900, 1800, 3600, 7200, 14400, 28800];
    const REQUEST_TIMEOUT = 10;
    /** A 'sending' lead older than this is treated as crashed mid-request (uncertain). */
    const SENDING_STALE_AFTER = 300;
    /** Wait this long after an uncertain attempt before trusting a "not found" lookup. */
    const LOOKUP_GRACE = 600;
    const LOOKUP_PAGES = 5;
    const LEASE_SECONDS = 120;
    const LOG_ENTRIES = 15;

    /** HubSpot errorTypes caused by the submitted data: the person must correct it, retrying won't help. */
    const DATA_ERRORS = ['BLOCKED_EMAIL', 'INVALID_EMAIL', 'REQUIRED_FIELD', 'VALUE_NOT_IN_FIELD_DEFINITION',
        'INPUT_TOO_LARGE', 'INVALID_NUMBER', 'NUMBER_OUT_OF_RANGE', 'INVALID_DOMAIN', 'MAX_NUMBER_OF_SUBMITTED_VALUES_EXCEEDED'];

    /** Statuses the worker picks up. */
    const ACTIVE = ['pending', 'retryable', 'uncertain', 'sending'];

    public static function enabled() {
        return NEPA_HUBSPOT_ENABLED === true;
    }

    public static function has_token() {
        return is_string(NEPA_HUBSPOT_TOKEN) && NEPA_HUBSPOT_TOKEN !== '';
    }

    public function __construct() {
        add_filter('cron_schedules', [$this, 'add_schedule']);
        add_action('nepa_agent_api_hubspot_worker', [$this, 'run_worker']);
        add_action('admin_init', [$this, 'schedule']);
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_filter('manage_nepa_lead_posts_columns', [$this, 'add_column']);
        add_action('manage_nepa_lead_posts_custom_column', [$this, 'render_column'], 10, 2);
        add_action('admin_post_nepa_hubspot_retry', [$this, 'handle_admin_retry']);
        add_action('admin_post_nepa_hubspot_mark_delivered', [$this, 'handle_admin_mark_delivered']);
        add_action('admin_notices', [$this, 'admin_notice']);
    }

    public function add_schedule($schedules) {
        $schedules['nepa_five_minutes'] = ['interval' => 300, 'display' => 'Every five minutes (Nepa HubSpot delivery)'];
        return $schedules;
    }

    public function schedule() {
        if (!wp_next_scheduled('nepa_agent_api_hubspot_worker')) {
            wp_schedule_event(time() + 60, 'nepa_five_minutes', 'nepa_agent_api_hubspot_worker');
        }
    }

    /* ------------------------------------------------------------------ payload */

    /**
     * The data to submit, encoded for the lead post's own post_content_filtered column.
     * It is written by the same INSERT that creates the lead, so if the lead exists, the
     * complete submission exists too - delivery never depends on separate meta writes.
     * Base64 keeps it intact through WordPress's slash handling and content filters.
     */
    public static function encode_snapshot($data) {
        return 'nepa-hs-v1:' . base64_encode(wp_json_encode($data));
    }

    /** The stored submission data, or null if the lead has none (older leads) or it can't be read. */
    public function read_snapshot($post_id) {
        $post = get_post($post_id);
        $raw = $post ? (string) ($post->post_content_filtered ?? '') : '';
        if (strpos($raw, 'nepa-hs-v1:') !== 0) {
            return null;
        }
        $data = json_decode((string) base64_decode(substr($raw, 11), true), true);
        foreach (['first_name', 'email', 'company', 'phone', 'message', 'submitted_at_ms'] as $required) {
            if (!is_array($data) || !isset($data[$required]) || $data[$required] === '') {
                return null;
            }
        }
        return $data;
    }

    /**
     * The submission for a stored lead, built from its snapshot. Field names and object
     * types are the English form's own (firstname/lastname/email/phone/message on contacts
     * 0-1, name on companies 0-2). No hutk, IP address or page context is sent: the person
     * never visited the page, so none of that would be true. The MCP origin is stated in
     * the message itself.
     */
    public function build_payload($snapshot) {
        $fields = [['objectTypeId' => '0-1', 'name' => 'firstname', 'value' => (string) $snapshot['first_name']]];
        if (($snapshot['last_name'] ?? '') !== '') {
            $fields[] = ['objectTypeId' => '0-1', 'name' => 'lastname', 'value' => (string) $snapshot['last_name']];
        }
        $fields[] = ['objectTypeId' => '0-1', 'name' => 'email', 'value' => (string) $snapshot['email']];
        $fields[] = ['objectTypeId' => '0-2', 'name' => 'name', 'value' => (string) $snapshot['company']];
        $fields[] = ['objectTypeId' => '0-1', 'name' => 'phone', 'value' => (string) $snapshot['phone']];
        $fields[] = ['objectTypeId' => '0-1', 'name' => 'message', 'value' => (string) $snapshot['message']];
        return [
            'submittedAt' => (string) $snapshot['submitted_at_ms'],
            'fields' => $fields,
            'legalConsentOptions' => [
                'consent' => [
                    // Processing consent was explicitly confirmed by the person (consent.process = true).
                    'consentToProcess' => ($snapshot['consent_to_process'] ?? false) === true,
                    'text' => NEPA_HUBSPOT_PROCESSING_TEXT,
                    'communications' => [[
                        // Marketing subscription only when the person explicitly opted in.
                        'value' => ($snapshot['marketing_opt_in'] ?? false) === true,
                        'subscriptionTypeId' => (int) NEPA_HUBSPOT_SUBSCRIPTION_TYPE_ID,
                        'text' => NEPA_HUBSPOT_SUBSCRIPTION_LABEL,
                    ]],
                ],
            ],
        ];
    }

    /** Message text sent to HubSpot: the person's message plus clearly separated context lines. */
    public static function compose_message($message, $service_interest, $language, $via, $lead_id) {
        $lines = [];
        if ($service_interest !== '') {
            $lines[] = 'Service interest: ' . $service_interest;
        }
        if ($language !== '') {
            $lines[] = 'Preferred language: ' . $language;
        }
        $lines[] = $via === 'mcp'
            ? 'Submitted via MCP (an AI agent using Nepa\'s agent API) on behalf of the person named in this form.'
            : 'Submitted via Nepa\'s agent API (/agent/v1/contact.json) on behalf of the person named in this form.';
        $lines[] = 'Nepa lead ID: ' . $lead_id;
        return $message . "\n\n---\n" . implode("\n", $lines);
    }

    /* ------------------------------------------------------------------ state */

    public function status($post_id) {
        return (string) get_post_meta($post_id, 'hubspot_status', true);
    }

    /** Public, non-sensitive view of a lead's delivery state for API responses. */
    public function public_status($post_id) {
        $status = $this->status($post_id) ?: (self::enabled() ? 'pending' : 'disabled');
        $out = [
            'status'   => $status,
            'attempts' => (int) get_post_meta($post_id, 'hubspot_attempts', true),
            'message'  => self::describe($status),
        ];
        $errors = json_decode((string) get_post_meta($post_id, 'hubspot_error_types', true), true);
        if ($status === 'rejected' && is_array($errors)) {
            $out['errors'] = $errors;
        }
        return $out;
    }

    public static function describe($status) {
        $text = [
            'disabled'      => 'Saved by Nepa. Forwarding to Nepa\'s CRM (HubSpot) is not enabled on this server, so Nepa follows up from its own records.',
            'pending'       => 'Saved by Nepa. Delivery to Nepa\'s CRM (HubSpot) is pending and is retried automatically.',
            'sending'       => 'Saved by Nepa. Delivery to Nepa\'s CRM (HubSpot) is in progress.',
            'retryable'     => 'Saved by Nepa. Delivery to Nepa\'s CRM (HubSpot) failed temporarily and will be retried automatically.',
            'uncertain'     => 'Saved by Nepa. It is not yet confirmed whether Nepa\'s CRM (HubSpot) received it; this is being checked before any resend.',
            'delivered'     => 'Saved by Nepa and confirmed as submitted to Nepa\'s contact form in HubSpot.',
            'rejected'      => 'Nepa\'s contact form (HubSpot) rejected the submitted details, so the inquiry was not forwarded. Correct the details listed in errors (for example, use a business email address) and submit again with a new idempotency_key.',
            'manual_review' => 'Saved by Nepa, but delivery to Nepa\'s CRM (HubSpot) needs attention from Nepa\'s team; Nepa has the inquiry and will follow up.',
            'not_applicable' => 'Saved by Nepa before forwarding to HubSpot was introduced; not sent to HubSpot.',
        ];
        return $text[$status] ?? 'Saved by Nepa.';
    }

    /**
     * Writes delivery state and appends a sanitized log entry. Returns true only if the
     * status AND every accompanying value (attempt counter, next-attempt time, ...) were
     * stored - callers must not send to HubSpot on false.
     */
    private function set_state($post_id, $status, $extra = [], $log = null) {
        $ok = $this->store($post_id, 'hubspot_status', $status);
        foreach ($extra as $key => $value) {
            $ok = $this->store($post_id, $key, $value) && $ok;
        }
        if ($log !== null) {
            $this->append_log($post_id, $status, $log);
        }
        return $ok;
    }

    /** Adds an entry to the lead's delivery log without touching its status. */
    private function append_log($post_id, $status, $log) {
        $entries = json_decode((string) get_post_meta($post_id, 'hubspot_log', true), true);
        $entries = is_array($entries) ? $entries : [];
        $entries[] = ['at' => gmdate('c'), 'status' => $status] + $log;
        $this->store($post_id, 'hubspot_log', wp_json_encode(array_slice($entries, -self::LOG_ENTRIES)));
    }

    /**
     * Write + read-back, retried once (update_post_meta() returns false for unchanged values
     * too). The value is slashed first because update_post_meta() unslashes its input -
     * without that, JSON with escaped quotes (the delivery log) or any text containing a
     * backslash would be stored corrupted.
     */
    private function store($post_id, $key, $value) {
        for ($i = 0; $i < 2; $i++) {
            update_post_meta($post_id, $key, wp_slash($value));
            if ((string) get_post_meta($post_id, $key, true) === (string) $value) {
                return true;
            }
        }
        error_log("Nepa Agent API: HubSpot delivery could not store '{$key}' for lead post #{$post_id}.");
        return false;
    }

    /* ------------------------------------------------------------------ per-lead lease */

    /**
     * Claims a lead so only one process (the contact request, a worker or an admin action)
     * handles it at a time, and refreshes the lead's metadata from the database on success. Same compare-and-swap row technique as the submission reservations: a plain
     * INSERT on the unique option_name; an expired lease is replaced only if the exact
     * expired value is still there.
     */
    private function claim($post_id) {
        global $wpdb;
        $name = 'nepa_hs_lease_' . (int) $post_id;
        for ($i = 0; $i < 3; $i++) {
            $raw = wp_json_encode(['expires' => time() + self::LEASE_SECONDS, 'token' => wp_generate_password(16, false, false)]);
            $suppress = $wpdb->suppress_errors(true);
            $inserted = $wpdb->insert($wpdb->options, ['option_name' => $name, 'option_value' => $raw, 'autoload' => 'no']);
            $wpdb->suppress_errors($suppress);
            if ($inserted) {
                // WordPress caches a post's meta per request, and another process's writes do
                // not clear THIS request's copy. Everything decided under the lease (status,
                // counters, timestamps) must come from the database, so drop the cached copy now.
                wp_cache_delete((int) $post_id, 'post_meta');
                return ['name' => $name, 'raw' => $raw];
            }
            $current = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
            if ($current === null) {
                continue;
            }
            $lease = json_decode((string) $current, true);
            if (is_array($lease) && ($lease['expires'] ?? 0) > time()) {
                return null; // held by another process
            }
            $wpdb->delete($wpdb->options, ['option_name' => $name, 'option_value' => $current]);
        }
        return null;
    }

    private function release($lease) {
        global $wpdb;
        if ($lease) {
            $wpdb->delete($wpdb->options, ['option_name' => $lease['name'], 'option_value' => $lease['raw']]);
        }
    }

    /* ------------------------------------------------------------------ processing */

    /**
     * Called by the contact request right after the lead is created. Setting the initial
     * status and making the first attempt happen under the same per-lead lease the worker
     * uses, and an existing status is never overwritten: if the worker already picked the
     * lead up (it selects leads with no status), the request leaves its result alone.
     * Returns the lead's status afterwards, or 'locked' if the worker holds it right now.
     */
    public function initialize_and_process($post_id) {
        $lease = $this->claim($post_id);
        if (!$lease) {
            return 'locked'; // the worker is handling it; it will record the outcome
        }
        try {
            if ($this->status($post_id) === '') {
                $this->store($post_id, 'hubspot_status', 'pending'); // if this fails the worker still finds the lead
            }
            return $this->process_claimed($post_id, false);
        } finally {
            $this->release($lease);
        }
    }

    /** Processes one lead under a lease. $manual = an administrator asked for this attempt. */
    public function process($post_id, $manual = false) {
        if (!self::enabled()) {
            return $this->status($post_id) ?: 'disabled';
        }
        $lease = $this->claim($post_id);
        if (!$lease) {
            return 'locked';
        }
        try {
            return $this->process_claimed($post_id, $manual);
        } finally {
            $this->release($lease);
        }
    }

    private function process_claimed($post_id, $manual) {
        $status = $this->status($post_id);
        // Delivered is final: never resend, not even on an administrator's retry (a stale
        // admin page or a repeated click must not create a second HubSpot submission).
        if (in_array($status, ['delivered', 'disabled', 'not_applicable'], true)) {
            return $status;
        }
        $snapshot = $this->read_snapshot($post_id);
        if ($snapshot === null) {
            if ($status === '') {
                // A lead from before HubSpot delivery existed: never sent, mark it so the worker stops looking at it.
                $this->set_state($post_id, 'not_applicable', [], ['note' => 'lead has no HubSpot submission data (created before delivery was enabled)']);
                return 'not_applicable';
            }
            $this->set_state($post_id, 'manual_review', ['hubspot_review_reason' => 'submission_data_unreadable'], ['note' => 'stored submission data missing or unreadable; nothing sent']);
            $this->notify_review($post_id, 'The stored submission data for this inquiry could not be read, so it was not sent to HubSpot. The inquiry details are in the lead itself.');
            return 'manual_review';
        }
        if ($status === '') {
            // Accepted, but its initial status could not be written: deliver it now.
            $status = 'pending';
        }
        if (in_array($status, ['rejected', 'manual_review'], true) && !$manual) {
            return $status;
        }
        $next = (int) get_post_meta($post_id, 'hubspot_next_attempt_at', true);
        if (!$manual && in_array($status, ['retryable', 'uncertain'], true) && $next > time()) {
            return $status; // honour the backoff schedule
        }

        // A lead left in 'sending' (crash, lost response, failed status write) may have arrived.
        if ($status === 'sending') {
            $started = (int) get_post_meta($post_id, 'hubspot_attempt_started_at', true);
            if (!$manual && $started > time() - self::SENDING_STALE_AFTER) {
                return 'sending'; // still within its own request's lifetime
            }
            $this->set_state($post_id, 'uncertain', ['hubspot_uncertain_since' => (string) ($started ?: time())],
                ['note' => 'previous attempt never recorded a result']);
            $status = 'uncertain';
        }

        if ($status === 'uncertain' && !$manual) {
            $resolved = $this->resolve_uncertain($post_id);
            if ($resolved !== 'resend') {
                return $resolved;
            }
        }

        return $this->attempt($post_id, $snapshot, $manual);
    }

    /** Returns 'delivered', 'uncertain' (keep waiting), 'manual_review', or 'resend'. */
    private function resolve_uncertain($post_id) {
        if (!self::has_token()) {
            $this->set_state($post_id, 'manual_review', ['hubspot_review_reason' => 'outcome_unknown_no_token'],
                ['note' => 'outcome unknown and no token to look it up; check HubSpot before retrying']);
            $this->notify_review($post_id, 'It is unknown whether HubSpot received this inquiry (no API token is configured to check).');
            return 'manual_review';
        }
        $since = (int) get_post_meta($post_id, 'hubspot_uncertain_since', true);
        [$found, $http] = $this->lookup_submission($post_id);
        if ($found === true) {
            $this->set_state($post_id, 'delivered', ['hubspot_delivered_at' => gmdate('c'), 'hubspot_delivered_by' => 'lookup'],
                ['note' => 'found in HubSpot by lookup; not resent']);
            return 'delivered';
        }
        if ($found === null) {
            // The lookup itself failed. A credential/permission problem won't fix itself;
            // anything else is retried on the backoff schedule, a bounded number of times.
            if (in_array($http, [401, 403, 404], true)) {
                $this->set_state($post_id, 'manual_review', ['hubspot_review_reason' => 'lookup_not_permitted'], ['note' => 'submission lookup refused', 'http' => $http]);
                $this->notify_review($post_id, "HubSpot refused the submission lookup (HTTP {$http}) - check that the token has the forms scope. It is unknown whether this inquiry reached HubSpot.");
                return 'manual_review';
            }
            $failures = (int) get_post_meta($post_id, 'hubspot_lookup_failures', true) + 1;
            if ($failures >= self::MAX_ATTEMPTS) {
                $this->set_state($post_id, 'manual_review', ['hubspot_review_reason' => 'lookup_failed', 'hubspot_lookup_failures' => (string) $failures], ['note' => 'submission lookup failed repeatedly', 'http' => $http]);
                $this->notify_review($post_id, "Checking whether HubSpot received this inquiry failed {$failures} times. It is unknown whether it reached HubSpot.");
                return 'manual_review';
            }
            if (!$this->store($post_id, 'hubspot_lookup_failures', (string) $failures)) {
                // Without a stored count neither the limit nor the backoff can work - stop here.
                $this->set_state($post_id, 'manual_review', ['hubspot_review_reason' => 'lookup_state_unwritable'], ['note' => 'lookup failed and its retry count could not be stored', 'http' => $http]);
                $this->notify_review($post_id, 'Checking whether HubSpot received this inquiry failed, and the retry count could not be saved. It is unknown whether it reached HubSpot.');
                return 'manual_review';
            }
            $this->schedule_next($post_id, 'uncertain', ['note' => 'submission lookup failed', 'http' => $http], $failures);
            return 'uncertain';
        }
        if ($since > time() - self::LOOKUP_GRACE) {
            $this->store($post_id, 'hubspot_next_attempt_at', (string) ($since + self::LOOKUP_GRACE));
            return 'uncertain'; // too early to trust "not found"
        }
        return 'resend';
    }

    /**
     * Looks for this lead's submission on the form: same submittedAt and same email.
     * Returns [found, http]: found = true (found), false (not found in the pages searched)
     * or null (lookup failed; http is the status code, 0 for a network error).
     */
    public function lookup_submission($post_id) {
        $snapshot = $this->read_snapshot($post_id);
        if ($snapshot === null) {
            return [null, 0];
        }
        $submitted_at = (string) $snapshot['submitted_at_ms'];
        $email = strtolower((string) $snapshot['email']);
        $after = '';
        for ($page = 0; $page < self::LOOKUP_PAGES; $page++) {
            $url = rtrim(NEPA_HUBSPOT_API_BASE, '/') . '/form-integrations/v1/submissions/forms/' . rawurlencode(NEPA_HUBSPOT_FORM_ID) . '?limit=50' . ($after !== '' ? '&after=' . rawurlencode($after) : '');
            $response = wp_remote_get($url, ['timeout' => self::REQUEST_TIMEOUT, 'headers' => ['Authorization' => 'Bearer ' . NEPA_HUBSPOT_TOKEN]]);
            $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                return [null, $code];
            }
            $data = json_decode((string) wp_remote_retrieve_body($response), true);
            foreach ((array) ($data['results'] ?? []) as $submission) {
                if ((string) ($submission['submittedAt'] ?? '') !== $submitted_at) {
                    continue;
                }
                foreach ((array) ($submission['values'] ?? []) as $value) {
                    if (($value['name'] ?? '') === 'email' && strtolower((string) ($value['value'] ?? '')) === $email) {
                        return [true, 200];
                    }
                }
            }
            $after = (string) ($data['paging']['next']['after'] ?? '');
            if ($after === '') {
                break;
            }
        }
        return [false, 200];
    }

    /** One submission attempt. Marks 'sending' first; refuses to send if that can't be recorded. */
    private function attempt($post_id, $snapshot, $manual) {
        $attempts = (int) get_post_meta($post_id, 'hubspot_attempts', true);
        if (!$manual && $attempts >= self::MAX_ATTEMPTS) {
            $this->set_state($post_id, 'manual_review', ['hubspot_review_reason' => 'retries_exhausted'], ['note' => 'retries exhausted']);
            $this->notify_review($post_id, 'HubSpot delivery failed ' . $attempts . ' times.');
            return 'manual_review';
        }
        $attempts++;
        // 'sending', the attempt counter and the time after which an unanswered attempt
        // counts as uncertain must ALL be stored before anything is sent: without them a lost
        // response couldn't be detected and the attempt limit couldn't be enforced.
        if (!$this->set_state($post_id, 'sending', [
            'hubspot_attempt_started_at' => (string) time(),
            'hubspot_attempts'           => (string) $attempts,
            'hubspot_next_attempt_at'    => (string) (time() + self::SENDING_STALE_AFTER),
        ])) {
            error_log("Nepa Agent API: delivery state for lead post #{$post_id} could not be stored; not sending.");
            return 'pending';
        }

        $secure = self::has_token();
        $url = rtrim(NEPA_HUBSPOT_SUBMIT_BASE, '/') . '/submissions/v3/integration/' . ($secure ? 'secure/submit/' : 'submit/')
            . rawurlencode(NEPA_HUBSPOT_PORTAL_ID) . '/' . rawurlencode(NEPA_HUBSPOT_FORM_ID);
        $headers = ['Content-Type' => 'application/json'];
        if ($secure) {
            $headers['Authorization'] = 'Bearer ' . NEPA_HUBSPOT_TOKEN;
        }
        $response = wp_remote_post($url, [
            'timeout' => self::REQUEST_TIMEOUT,
            'headers' => $headers,
            'body'    => wp_json_encode($this->build_payload($snapshot)),
        ]);
        return $this->record_outcome($post_id, $response, $attempts, $manual);
    }

    private function record_outcome($post_id, $response, $attempts, $manual) {
        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            // Failed before anything reached HubSpot (DNS, connection refused, TLS handshake): safe to resend.
            if (preg_match('/cURL error (6|7|35|60)\b|Could not resolve|Connection refused|Failed to connect/i', $message)) {
                $this->schedule_next($post_id, 'retryable', ['error' => 'connection_failed'], $attempts);
                return 'retryable';
            }
            // Anything else (typically a timeout) may have been accepted.
            $this->set_state($post_id, 'uncertain', ['hubspot_uncertain_since' => (string) time()], ['error' => 'no_response', 'detail' => self::sanitize($message)]);
            $this->schedule_next($post_id, 'uncertain');
            return 'uncertain';
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $types = [];
        foreach ((array) ($body['errors'] ?? []) as $error) {
            if (!empty($error['errorType'])) {
                $types[] = (string) $error['errorType'];
            }
        }

        if ($code >= 200 && $code < 300) {
            if (!$this->set_state($post_id, 'delivered', ['hubspot_delivered_at' => gmdate('c'), 'hubspot_delivered_by' => $manual ? 'manual_retry' : 'response'],
                ['http' => $code])) {
                // Status stays 'sending' -> later treated as uncertain -> looked up, not resent blindly.
                error_log("Nepa Agent API: HubSpot accepted lead post #{$post_id} but 'delivered' could not be recorded.");
            }
            return 'delivered';
        }
        if ($code === 429) {
            $wait = self::retry_after($response);
            $this->schedule_next($post_id, 'retryable', ['http' => 429, 'error' => 'rate_limited'], $attempts, $wait);
            return 'retryable';
        }
        if ($code === 400 && $types && !array_diff($types, self::DATA_ERRORS)) {
            $this->set_state($post_id, 'rejected', ['hubspot_error_types' => wp_json_encode(array_values(array_unique($types)))],
                ['http' => 400, 'error_types' => $types]);
            return 'rejected';
        }
        if (in_array($code, [400, 401, 403, 404], true)) {
            $reason = $code === 400 ? 'form_configuration' : 'credentials_or_form_id';
            $this->set_state($post_id, 'manual_review', ['hubspot_review_reason' => $reason, 'hubspot_error_types' => wp_json_encode($types)],
                ['http' => $code, 'error_types' => $types, 'detail' => self::sanitize((string) ($body['message'] ?? ''))]);
            $this->notify_review($post_id, "HubSpot answered HTTP {$code}" . ($types ? ' (' . implode(', ', $types) . ')' : '') . ' - check the HubSpot token, form ID and consent settings.');
            return 'manual_review';
        }
        if (in_array($code, [502, 504], true)) {
            // A gateway error can arrive after HubSpot processed the request.
            $this->set_state($post_id, 'uncertain', ['hubspot_uncertain_since' => (string) time()], ['http' => $code]);
            $this->schedule_next($post_id, 'uncertain');
            return 'uncertain';
        }
        $this->schedule_next($post_id, 'retryable', ['http' => $code], $attempts);
        return 'retryable';
    }

    /** Seconds to wait after a 429: Retry-After if given, else the rate-limit window when it is exhausted. */
    public static function retry_after($response) {
        $retry_after = wp_remote_retrieve_header($response, 'retry-after');
        if (is_numeric($retry_after)) {
            return max(1, min(3600, (int) $retry_after));
        }
        $remaining = wp_remote_retrieve_header($response, 'x-hubspot-ratelimit-remaining');
        $interval = wp_remote_retrieve_header($response, 'x-hubspot-ratelimit-interval-milliseconds');
        if ($remaining !== '' && (int) $remaining === 0 && is_numeric($interval)) {
            return max(1, (int) ceil(((int) $interval) / 1000));
        }
        return null;
    }

    private function schedule_next($post_id, $status, $log = null, $attempts = null, $wait = null) {
        $attempts = $attempts ?? max(1, (int) get_post_meta($post_id, 'hubspot_attempts', true));
        if ($wait === null) {
            $base = self::BACKOFF[min($attempts, count(self::BACKOFF)) - 1];
            $wait = $base + mt_rand(0, (int) ($base / 5));
        }
        $this->set_state($post_id, $status, ['hubspot_next_attempt_at' => (string) (time() + $wait)], $log);
    }

    /** Strips anything that looks like an email address or bearer token from an error string. */
    public static function sanitize($text) {
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', (string) $text);
        $text = preg_replace('/(Bearer\s+)\S+/i', '$1[redacted]', $text);
        $text = preg_replace('/pat-[a-z0-9-]{10,}/i', '[redacted]', $text);
        return mb_substr($text, 0, 300);
    }

    /** Email to Nepa when a lead needs a person (once per lead and reason). */
    private function notify_review($post_id, $why) {
        $key = 'hubspot_review_notified_' . md5($why);
        if (get_post_meta($post_id, $key, true)) {
            return;
        }
        $lead_id = (string) get_post_meta($post_id, 'lead_id', true);
        wp_mail(Nepa_Agent_API::LEAD_NOTIFY_TO, "Agent inquiry {$lead_id}: HubSpot delivery needs attention",
            "Lead {$lead_id} (Agent Lead #{$post_id} in wp-admin) was saved in WordPress, but needs attention before it reaches HubSpot:\n\n{$why}\n\nOpen wp-admin -> Agent Leads -> this lead to see the delivery log, retry, or mark it as delivered after checking HubSpot.\n");
        $this->store($post_id, $key, '1');
    }

    /* ------------------------------------------------------------------ worker */

    /**
     * Picks up to 10 leads that are due now, oldest first. Eligibility is decided in the
     * query itself, so leads still waiting for their next attempt can never fill the batch
     * and starve due ones. Leads with no delivery state at all are included too: that is
     * a lead accepted while its initial status couldn't be stored (or an older lead, which
     * is then marked not_applicable once).
     */
    public function run_worker() {
        if (!self::enabled()) {
            return;
        }
        $now = time();
        $ids = get_posts([
            'post_type'      => 'nepa_lead',
            'post_status'    => 'private',
            'posts_per_page' => 10,
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'ASC',
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => 'hubspot_status', 'value' => 'pending'],
                ['key' => 'hubspot_status', 'compare' => 'NOT EXISTS'],
                [
                    'relation' => 'AND',
                    ['key' => 'hubspot_status', 'value' => ['retryable', 'uncertain', 'sending'], 'compare' => 'IN'],
                    [
                        'relation' => 'OR',
                        ['key' => 'hubspot_next_attempt_at', 'compare' => 'NOT EXISTS'],
                        ['key' => 'hubspot_next_attempt_at', 'value' => $now, 'compare' => '<=', 'type' => 'NUMERIC'],
                    ],
                ],
            ],
            'no_found_rows'  => true,
        ]);
        foreach ($ids as $id) {
            $this->process($id);
        }
    }

    /* ------------------------------------------------------------------ admin */

    public function add_column($columns) {
        $columns['nepa_hubspot'] = 'HubSpot';
        return $columns;
    }

    public function render_column($column, $post_id) {
        if ($column === 'nepa_hubspot') {
            echo esc_html($this->status($post_id) ?: '-');
        }
    }

    public function add_meta_box() {
        add_meta_box('nepa_hubspot_delivery', 'HubSpot delivery', [$this, 'render_meta_box'], 'nepa_lead', 'side', 'high');
    }

    public function render_meta_box($post) {
        $id = (int) $post->ID;
        $get = function ($key) use ($id) { return (string) get_post_meta($id, $key, true); };
        $status = $this->status($id) ?: '-';
        echo '<p><strong>Status:</strong> ' . esc_html($status) . '</p>';
        echo '<p><strong>Attempts:</strong> ' . esc_html($get('hubspot_attempts') ?: '0') . '</p>';
        if ($get('hubspot_next_attempt_at')) {
            echo '<p><strong>Next attempt:</strong> ' . esc_html(gmdate('Y-m-d H:i', (int) $get('hubspot_next_attempt_at'))) . ' UTC</p>';
        }
        if ($get('hubspot_review_reason')) {
            echo '<p><strong>Review reason:</strong> ' . esc_html($get('hubspot_review_reason')) . '</p>';
        }
        $ms = $get('hubspot_submitted_at_ms');
        if ($ms !== '') {
            echo '<p><strong>Submission time sent to HubSpot:</strong> ' . esc_html(gmdate('Y-m-d H:i:s', (int) ((int) $ms / 1000))) . ' UTC<br><small>Search the form\'s submissions in HubSpot for this email at this time before retrying an uncertain lead.</small></p>';
        }
        $log = json_decode($get('hubspot_log'), true);
        if (is_array($log) && $log) {
            echo '<details><summary>Delivery log</summary><ul>';
            foreach (array_reverse($log) as $entry) {
                echo '<li><code>' . esc_html(wp_json_encode($entry)) . '</code></li>';
            }
            echo '</ul></details>';
        }
        if (!current_user_can('edit_nepa_lead', $id)) {
            return;
        }
        $url = function ($action) use ($id) {
            return wp_nonce_url(admin_url('admin-post.php?action=' . $action . '&lead=' . $id), $action . '_' . $id);
        };
        if (in_array($status, ['retryable', 'uncertain', 'manual_review', 'rejected', 'pending', 'sending'], true)) {
            echo '<p><a class="button" href="' . esc_url($url('nepa_hubspot_retry')) . '">Retry HubSpot delivery now</a><br><small>For uncertain leads: check HubSpot first - retrying sends the submission again.</small></p>';
        }
        if (in_array($status, ['uncertain', 'manual_review', 'sending'], true)) {
            echo '<p><a class="button" href="' . esc_url($url('nepa_hubspot_mark_delivered')) . '">Mark as delivered (found in HubSpot)</a></p>';
        }
    }

    public function admin_lead_from_request($action) {
        $id = isset($_GET['lead']) ? (int) $_GET['lead'] : 0;
        if (!$id || !current_user_can('edit_nepa_lead', $id)) {
            wp_die('You are not allowed to manage Agent Leads.', 403);
        }
        check_admin_referer($action . '_' . $id);
        return $id;
    }

    public function handle_admin_retry() {
        $id = $this->admin_lead_from_request('nepa_hubspot_retry');
        $result = $this->admin_retry($id, (int) wp_get_current_user()->ID);
        wp_safe_redirect(add_query_arg(['post' => $id, 'action' => 'edit', 'nepa_hubspot' => $result], admin_url('post.php')));
        exit;
    }

    public function handle_admin_mark_delivered() {
        $id = $this->admin_lead_from_request('nepa_hubspot_mark_delivered');
        $result = $this->admin_mark_delivered($id, (int) wp_get_current_user()->ID);
        wp_safe_redirect(add_query_arg(['post' => $id, 'action' => 'edit', 'nepa_hubspot' => $result], admin_url('post.php')));
        exit;
    }

    /**
     * Administrator-requested attempt (bypasses backoff and the attempt limit). Everything -
     * reading the status, refusing a delivered lead, recording the request, sending - happens
     * under the per-lead lease, and the status is never rewritten just to note the request
     * (only the log is). Returns 'locked' if a worker is handling the lead right now.
     */
    public function admin_retry($post_id, $user_id) {
        if (!self::enabled()) {
            return $this->status($post_id) ?: 'disabled';
        }
        $lease = $this->claim($post_id);
        if (!$lease) {
            return 'locked';
        }
        try {
            $status = $this->status($post_id);
            if (in_array($status, ['delivered', 'disabled', 'not_applicable'], true)) {
                return $status;
            }
            $this->append_log($post_id, $status ?: 'pending', ['note' => 'manual retry by user #' . (int) $user_id]);
            return $this->process_claimed($post_id, true);
        } finally {
            $this->release($lease);
        }
    }

    /** Administrator confirmed the submission exists in HubSpot (under the lease, so no attempt is in flight). */
    public function admin_mark_delivered($post_id, $user_id) {
        $lease = $this->claim($post_id);
        if (!$lease) {
            return 'locked';
        }
        try {
            $this->set_state($post_id, 'delivered', ['hubspot_delivered_at' => gmdate('c'), 'hubspot_delivered_by' => 'admin_verified'],
                ['note' => 'marked delivered after checking HubSpot, by user #' . (int) $user_id]);
            return 'delivered';
        } finally {
            $this->release($lease);
        }
    }

    public function admin_notice() {
        if (!self::enabled() || !current_user_can('edit_nepa_leads')) {
            return;
        }
        $ids = get_posts([
            'post_type' => 'nepa_lead', 'post_status' => 'private', 'posts_per_page' => 50, 'fields' => 'ids', 'no_found_rows' => true,
            'meta_query' => [['key' => 'hubspot_status', 'value' => ['manual_review', 'uncertain'], 'compare' => 'IN']],
        ]);
        if ($ids) {
            echo '<div class="notice notice-warning"><p>' . esc_html(count($ids) . ' agent inquiry/inquiries need a HubSpot delivery check.') . ' <a href="' . esc_url(admin_url('edit.php?post_type=nepa_lead')) . '">Agent Leads</a></p></div>';
        }
    }
}
