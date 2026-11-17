# Deploying agentic-web-2026-11 to nepa.com

**What changes compared with the live agentic-web-2026-10 (deployed):** new data from the 2026-11-16 crawl (plugin 1.4.0, data release `agentic-web-2026-11`), a new agent page, llms.txt, ai.txt, api-catalog.json and robots lines, the discovery must-use plugin 1.1.0 plus the new **Agentic Resource Discovery (ARD) module** (`agentic-resource-discovery.php`), OpenAPI 1.4.0 (aligned with the plugin version on 2026-11-17), and MCP server 1.2.1 (dependency security update, no behaviour change). The short version for an update of the live site is in section 12; sections 0-9 are the full procedure.

This package has four parts that deploy separately:

| Part | Folder / files | Where it goes |
|---|---|---|
| WordPress plugin "Nepa Agent API" 1.4.0 | `nepa-api/` | `wp-content/plugins/nepa-api/` |
| Must-use plugins: "Nepa Agent Discovery" 1.2.0 and "Agentic Resource Discovery (ARD)" 1.0.0 | `nepa-agent-discovery.php`, `agentic-resource-discovery.php` | `wp-content/mu-plugins/` |
| Discovery files | `llms.txt`, `llms-full.txt`, `ai.txt`, `api-catalog.json`, `robots-addition.txt` | Web root (`https://nepa.com/<file>`) and robots.txt |
| MCP server 1.2.1 | `nepa-mcp/` | Render web service |

The plugin serves `/agent/`, `/agent/v1/*.json` and `/openapi.json` itself. The must-use plugins serve `/.well-known/api-catalog`, `/.well-known/mcp.json`, `/.well-known/ard.json`, the `<head>` discovery links (including `<link rel="ard">`), the `Link: rel="ard"` header and `?format=md` page versions. Nothing serves `llms.txt`, `ai.txt` or `api-catalog.json`, and nothing edits `robots.txt`; those are separate steps below.

Requirements: WordPress 6.0+, PHP 7.4+, Node.js 20+ for the MCP server (a locked dependency, `@hono/node-server`, requires Node 20).

Before deploying, run every packaged test with `bash tests/run-all.sh` (see `tests/README.md`; needs PHP, Node 20+ and a python3 with `jsonschema`). Every line must say PASS; INCOMPLETE (a suite could not run fully) counts as a failure. These tests use stand-ins for WordPress and HubSpot, so they are necessary but not sufficient: production sign-off also needs the real-environment checks in **section 13**.

---

## 0. Before you start

1. **Back up.** These are the rollback copies (section 7):
   - **the WordPress database** - on WP Engine, create a backup point in the User Portal (Backup points → Back up now) right before deploying; it covers the database and the files. Agent Leads, their delivery state and the Sync lock live in the database;
   - `wp-content/plugins/nepa-api/` and `wp-content/mu-plugins/nepa-agent-discovery.php`;
   - `wp-content/uploads/nepa-agent-api/` (synced data and `manifest.json`, if Sync was ever used);
   - `llms.txt`, `ai.txt`, `api-catalog.json` and `robots.txt` from the web root;
   - the Render service's current commit (note its ID).
2. **This release is deployed directly to production (nepa.com); there is no staging site.** So: deploy at a quiet time, take the backups in step 1 first, keep the rollback in section 7 at hand, and run the checks in section 6 straight after deploying. The contact and Sync checks in section 13 (rows 5, 6 and 8) create test data on the live site - tell Nepa beforehand and delete the test leads afterwards.
3. **Decide the settings in `wp-config.php`** (all optional):

   ```php
   // nepa.com is served through Cloudflare. Set this ONLY if the origin server can't be
   // reached directly (all traffic goes through Cloudflare). Otherwise every visitor
   // shares Cloudflare's edge IPs and the contact limit (5 per 10 min per IP) is shared.
   define('NEPA_TRUST_CLOUDFLARE_IP', true);

   // Shared secret between the MCP server and WordPress, so MCP traffic gets its own
   // rate-limit bucket. Same value as NEPA_MCP_SHARED_SECRET on Render. Never commit it.
   define('NEPA_MCP_SHARED_SECRET', '<random 32+ characters>');

   // Days to keep Agent Leads before automatic permanent deletion. 0 = keep (default).
   // Agree the period with Nepa's privacy policy before enabling.
   define('NEPA_LEAD_RETENTION_DAYS', 0);

   // Contact switch (section 7a). Leave undefined, or true, to accept agent inquiries.
   // false: POST /agent/v1/contact.json answers 503 contact_disabled; nothing is stored or sent.
   // define('NEPA_CONTACT_ENABLED', false);

   // HubSpot form delivery (section 11). Off until set to true. Turning it off does NOT stop
   // inquiries: they are still accepted, stored and emailed - only the forwarding stops.
   define('NEPA_HUBSPOT_ENABLED', true);
   // Optional private-app token with the `forms` scope. Never commit it.
   define('NEPA_HUBSPOT_TOKEN', 'pat-eu1-...');
   // Defaults, shown for reference - only change them together with the HubSpot form:
   // define('NEPA_HUBSPOT_PORTAL_ID', '146131568');
   // define('NEPA_HUBSPOT_FORM_ID', '7c631e31-cc3b-44ba-a580-113ab3489db6'); // English /contact-us/ form
   // define('NEPA_HUBSPOT_SUBMIT_BASE', 'https://api.hsforms.com');
   ```

4. **Persistent object cache (recommended).** With Redis or Memcached, the contact rate limits are exact (atomic counters). Without one they use WordPress transients and two simultaneous requests can occasionally both pass the limit.

## 1. WordPress plugin

1. Replace `wp-content/plugins/nepa-api/` with this package's `nepa-api/` folder.
2. In **Plugins**, deactivate and re-activate **Nepa Agent API**. Activation registers the URL rules, flushes them and schedules the two background jobs (notification retry hourly, lead retention daily).
3. Go to **Settings → Permalinks → Save** once more. Activation already flushes the rules, but this also clears any cached rules from a caching plugin.
4. **Physical-file conflicts:** if a real file named `openapi.json` or a folder named `agent` exists in the web root, the web server serves it instead of the plugin. Remove or rename it.
5. **Writable data folder:** "Sync now" writes to `wp-content/uploads/nepa-agent-api/`. The web server must be able to create and write that folder. The plugin's own `data/` folder can stay read-only.
6. **Before the first "Sync now", verify the post types.** In `includes/class-sync.php`, the constants `NEPA_SYNC_POST_TYPE_*` (`product`, `solution`, `case`, `person`, `event`, `webinar`, `news`) were inferred from sitemap names. Check each one in wp-admin. A wrong value makes that section skip; it never blanks a file.
7. **Before the first "Sync now", check multilingual REST output.** Open `https://nepa.com/wp-json/wp/v2/posts?per_page=5&lang=sv` and `...&wpml_language=sv`. One of them should return Swedish posts. If neither does, Sync keeps the existing Swedish posts and says so in its log.

## 1a. Must-use plugins (agent discovery and ARD)

1. Upload `nepa-agent-discovery.php` (1.1.0, replaces 1.0.0) **and** `agentic-resource-discovery.php` (new) to `wp-content/mu-plugins/`. Must-use plugins load automatically; there is nothing to activate.
2. Both files are needed for ARD: the generic module serves the manifest, and the Nepa plugin supplies nepa.com's five entries through the `agentic_ard_entries` filter. With only the module, `/.well-known/ard.json` stays a 404 and no `rel="ard"` link is printed.
3. **Physical-file conflicts:** if a real `/.well-known/ard.json` file exists in the web root, the web server serves it instead. Remove it.
4. Purge WP Engine and NitroPack caches (section 4) so cached pages pick up the new `<link rel="ard">` tag.
5. **Fixes broken links on localized pages:** 1.0.0 printed `https://nepa.com/sv//llms.txt`-style discovery links (404) on every /sv/, /fi/ and /da/ page; 1.1.0 links to the site root. After deploying, view the source of https://nepa.com/sv/ and check that the `llms.txt`, `api-catalog`, `service-desc` and `ard` links have no `/sv/` in them.
6. **No `.well-known` folder is needed.** On WP Engine there is no physical `.well-known` folder for these files, and none should be created: WordPress answers `/.well-known/ard.json`, `/.well-known/mcp.json` and `/.well-known/api-catalog` from the must-use plugins (the latter two are already served this way on nepa.com). Upload the two PHP files with SFTP to `wp-content/mu-plugins/` (the folder already exists on WP Engine) and purge the WP Engine cache.
7. **Reuse on another site:** `agentic-resource-discovery.php` contains no Nepa-specific values. On another WordPress site, upload it unchanged and add the site's entries with `add_filter( 'agentic_ard_entries', ... )` (see `nepa_ad_ard_entries()` in `nepa-agent-discovery.php` for the shape); settings such as the publisher domain go through `agentic_ard_config`. Entries that break the ARD v0.91 rules are left out of the manifest and logged (`ARD:` lines in the PHP error log).

## 2. Discovery files

1. Upload `llms.txt`, `llms-full.txt`, `ai.txt` and `api-catalog.json` to the web root, replacing the current files.
2. Check content types: `llms.txt`, `llms-full.txt` and `ai.txt` as `text/plain; charset=utf-8`, `api-catalog.json` as `application/json`.
3. **robots.txt:** the live file (checked 2026-11-16) already has every user-agent in `robots-addition.txt`, the `LLM-Readable`, `LLM-Text`, `AI-File` and `Sitemap` lines, and `Content-Signal: ai-train=yes, search=yes, ai-input=yes`. The only line to add in this release is `Agentmap: https://nepa.com/.well-known/ard.json`, next to `AI-File`. Don't append the whole block again.

## 3. MCP server (Render)

The MCP server is deployed from the GitHub repository `Development-gomo/Agentic-MCP-SaaS-Nepa`, where the MCP project sits at the **repository root**. In this package it sits in `nepa-mcp/`.

- To update that repository, copy the **contents** of `nepa-mcp/` (`src/`, `dist/`, `package.json`, `package-lock.json`, `tsconfig.json`, `README.md`, `.gitignore`) to its root and commit.
- If you deploy straight from a repository that contains this whole package instead, set Render's **Root Directory** to `nepa-mcp`.

Render settings: build `npm ci && npm run build`, start `npm start`, health check path `/health`. Environment variables:

| Variable | Value |
|---|---|
| `NEPA_API_BASE` | `https://nepa.com/agent/v1` (default) |
| `NEPA_MCP_SHARED_SECRET` | same value as in wp-config.php (optional) |
| `TRUST_PROXY` | `1` (default; Render has one proxy hop, so rate limits key on the real client IP) |
| `API_TIMEOUT_MS` | `10000` (default) |
| `NEPA_CONTACT_ENABLED` | unset or `true` (default). `false` removes the `submit_contact_inquiry` tool (section 7a); read-only tools keep working |

## 4. Cache and firewall

1. Purge NitroPack and Cloudflare caches for `/agent/`, `/agent/v1/*`, `/openapi.json`, `/llms.txt`, `/ai.txt`, `/api-catalog.json`, `/.well-known/*` and the HTML pages (for the new `<link rel="ard">`).
2. **Don't cache filtered API responses as one URL.** `/agent/v1/*.json` responses vary by query string (`?language=`, `?service=`, `?limit=` ...). Make sure the cache key includes the query string, or bypass the cache for `/agent/v1/`.
3. **Never cache `POST /agent/v1/contact.json`.**
4. **Let agents through the edge.** nepa.com sits behind WP Engine's Cloudflare-based edge, so request the exemption through WP Engine. On the live site, GPTBot and ClaudeBot get 429 on the agent files and a generic `python-requests` client gets 403. Ask WP Engine to exempt `/agent/*`, `/openapi.json`, `/api-catalog.json`, `/llms.txt`, `/ai.txt` and `/.well-known/*` (including `/.well-known/ard.json`) from bot challenges and crawler rate limits, then verify with section 10.2.

## 5. Keeping content fresh

There are two kinds of content, updated in different ways:

| Content | How it is updated |
|---|---|
| `blog-posts`, `press-releases`, `case-studies`, `products`, `solutions`, `team`, `company-news`, `events` JSON | **Tools → Nepa Agent API → Sync now** in wp-admin, after publishing. Curated fields are kept (blog posts: curated excerpts, and the curated author whenever WordPress names a CMS or agency account - `gomo_dev`, `Mridul GO MO`, configurable with `NEPA_SYNC_CMS_AUTHORS` in wp-config.php). All sections succeed and are published together, or nothing changes. |
| `agent-page.html`, `llms.txt`, `llms-full.txt`, `api-catalog.json`, and the `pricing`, `company`, `investor-relations`, `localized-pages`, `resources`, `offices`, `industries`, `jobs` JSON | Regenerated by the next package build (a fresh crawl, like this one). Sync does not touch them. `llms-full.txt` is generated from the data files with `python3 tools/build-llms-full.py` (run it after any data change; `tests/run-all.sh` fails if it is out of date). |

So between package builds, the JSON API can be newer than the agent page and llms.txt. Recommended rhythm: Sync after each publication; rebuild the package monthly, or straight away after leadership, pricing or product changes.

Since plugin 1.4.0 each Sync writes a complete set of files into its own folder, `wp-content/uploads/nepa-agent-api/gen-<time>-<random>/`, and then replaces `manifest.json` with one atomic rename. The manifest records the release the sync was built on (`NEPA_DATA_RELEASE` in nepa-api.php, currently `agentic-web-2026-11`), the generation folder and the files. Until that rename nothing a visitor sees changes; if anything fails before it, the new folder is deleted and the previous data stays served. The current and the previous generation are kept; older ones are deleted. Only one Sync can run at a time (an atomic database lock, taken over after 10 minutes if a run crashed). **Precedence rule:** a synced file is served only when the manifest lists it and its release equals the installed plugin's `NEPA_DATA_RELEASE`; otherwise the bundled `data/` file is served. File timestamps play no part. Installing a new package release (new `NEPA_DATA_RELEASE`) therefore takes effect immediately, and the next Sync builds on it; reinstalling the same release keeps synced content. Include `wp-content/uploads/nepa-agent-api/` in backups.

## 6. Checks after deployment (production)

```bash
curl -s https://nepa.com/agent/v1/index.json | head -c 300
```
- `/agent/v1/` returns the same JSON as `/agent/v1/index.json` (it was a 404 before 1.4.0); `/agent/v1/index.json` returns JSON with `"version":"1.4.0"`, lists `localized-pages` and has `"ard_manifest":"https://nepa.com/.well-known/ard.json"`, without a 301 to a trailing-slash URL.
- `/agent/v1/case-studies.json` returns `"count":21`; `?service=Brand%20Tracking` returns 10 items.
- `/agent/v1/press-releases.json?language=sv&limit=5` returns Swedish releases with `"total":154`.
- `/agent/v1/localized-pages.json?language=fi` returns 35 pages.
- `/agent/` shows "last updated 2026-11-16"; `/openapi.json` shows version 1.4.0 (the same number as the plugin, since 2026-11-17).
- **ARD:** `curl -si https://nepa.com/.well-known/ard.json` returns 200, `Content-Type: application/json`, `Access-Control-Allow-Origin: *` and 5 entries; `python3 tests/validate-ard-manifest.py https://nepa.com/.well-known/ard.json nepa.com` reports 0 errors; the homepage HTML contains `<link rel="ard"` and its response headers contain `Link: <https://nepa.com/.well-known/ard.json>; rel="ard"`; `/.well-known/api-catalog` and `/.well-known/mcp.json` both mention `ard.json`.
- **Official ARD tester (optional, recommended):** from a clone of https://github.com/ards-project/ard-spec run `conformance/bin/conformance-test publisher nepa.com`. It was not run during the build (the build environment does not execute downloaded code); the packaged `validate-ard-manifest.py` applies the same official schema and rules.
- `https://<render-host>/health?deep=1` returns `"status":"ok"` with version `1.2.1` and the API version; `/tools` lists 18 tools.
- **Markdown versions:** `https://nepa.com/products/brand-tracking/?format=md` returns `text/markdown` with the page's content (headings, text and links - several thousand characters), not just the title and front matter. Responses cached by 1.1.0 are not reused.
- `https://nepa.com/llms-full.txt` returns 200 `text/plain` (about 240 KB); `https://nepa.com/.well-known/security.txt` returns 200 with `Contact: mailto:security@nepa.com` and an `Expires` date in the future (renew `NEPA_AD_SECURITY_EXPIRES` in nepa-agent-discovery.php before it passes - at the latest with the next package build).
- The homepage's JSON-LD no longer contains a `SearchAction` (`potentialAction` with `?s=`).
- **Contact flow (production, creates test data):** covered by section 13 rows 5 and 6 - `live-checks.sh duplicates` (with `NEPA_ALLOW_PRODUCTION_TEST=1`) and `live-checks.sh hubspot`. Both label their inquiries "LIVE TEST" / "Nepa live check"; confirm one Agent Lead per accepted inquiry and one notification email each, then delete the test leads in wp-admin → Agent Leads and any test submissions in HubSpot. Don't break SMTP on the live site to test email retries; instead, after go-live, check Agent Leads once for any lead whose `mail_sent` is not `1` (section 8).
- `npm audit` in `nepa-mcp/` shows no high/critical issues.

## 7. Rollback

1. Restore the backed-up `wp-content/plugins/nepa-api/` folder and re-activate the plugin (Settings → Permalinks → Save). If only contact submissions are the problem, pause them instead (section 7a) and keep the rest live. If the database itself must go back, restore the WP Engine backup point taken in section 0 (this also removes any Agent Leads received since then - export them first).
2. If the problem is synced data, delete `wp-content/uploads/nepa-agent-api/manifest.json` (or the whole folder); the plugin then serves its bundled `data/` files at once. Responses already cached by WP Engine, NitroPack or Cloudflare can still show the synced data until you purge `/agent/v1/*` (section 4).
3. Restore the backed-up `llms.txt`, `ai.txt`, `api-catalog.json`, `robots.txt` and `mu-plugins/nepa-agent-discovery.php`. A full rollback to the 2026-10 package (plugin, must-use plugin, discovery files, robots.txt and the MCP commit together) removes every ARD reference at once; also delete `wp-content/mu-plugins/agentic-resource-discovery.php`, which 2026-10 did not have.
   **Withdrawing ARD only, keeping the rest of 2026-11:** deleting the module alone is not enough, because these files still point to `/.well-known/ard.json`. Remove or revert every reference:
   - `wp-content/mu-plugins/agentic-resource-discovery.php` — delete (removes the manifest, the `<link rel="ard">` tag and the `Link: rel="ard"` header);
   - `wp-content/mu-plugins/nepa-agent-discovery.php` — remove the `'ard'` line in `nepa_ad_mcp_card()` and the ARD item in `nepa_ad_api_catalog()` (the `agentic_ard_*` filters and `nepa_ad_ard_entries()` are harmless without the module but can be removed too), or restore 1.0.0 (which brings back the broken `/sv//…` links fixed in 1.1.0);
   - `nepa-api/agent-page.html` — the `<link rel="ard">` in `<head>`, the "Agent discovery" row in Page Metadata, the "Discovery" item in the agent instructions and the `<script id="ard-entries">` block;
   - `nepa-api/nepa-api.php` — the `'ard_manifest'` line in `get_index()`; `nepa-api/openapi.json` — `externalDocs`, the ARD sentence in `info.description` and `ard_manifest` in `IndexResponse`;
   - `llms.txt` — the "Agentic Resource Discovery manifest (ARD)" line; `ai.txt` — the `ARD:` line; `api-catalog.json` — the "Nepa Agentic Resource Discovery manifest" API entry and `X-discovery.ard_manifest`; `robots.txt` — the `Agentmap` line.
   Then purge caches and confirm nothing still advertises the endpoint: `for u in / /sv/ /agent/ /llms.txt /ai.txt /api-catalog.json /openapi.json /agent/v1/index.json /.well-known/mcp.json /.well-known/api-catalog /robots.txt; do curl -s "https://nepa.com$u" | grep -qE 'ard\.json|rel="ard"' && echo "still references ARD: $u"; done` should print nothing.
4. In Render, redeploy the previous commit.
5. Purge caches as in section 4.

### 7a. Pausing contact submissions (read-only access stays)

Use this when a contact check in section 13 fails, or whenever agent inquiries must stop. `NEPA_HUBSPOT_ENABLED = false` is **not** enough: it only stops the HubSpot forwarding, while WordPress keeps accepting, storing and emailing inquiries.

1. **WordPress:** add `define('NEPA_CONTACT_ENABLED', false);` to wp-config.php. `POST /agent/v1/contact.json` then answers 503 `contact_disabled` to everyone (MCP server and direct callers) before anything is counted, stored, emailed or sent to HubSpot. `/agent/v1/index.json` reports `"contact_submissions":"paused"` and no longer lists the contact endpoint. Every read-only endpoint keeps working.
2. **MCP server:** set the Render environment variable `NEPA_CONTACT_ENABLED=false` and let it restart. The `submit_contact_inquiry` tool disappears (17 tools); `/health` and `/tools` report `"contact_submissions":"paused"`. Even before this step, an agent that calls the tool gets "NOT SAVED: Nepa has paused agent contact submissions" from step 1.
3. Purge the cache for `/agent/v1/index.json` (section 4).
4. Check: `curl -s https://nepa.com/agent/v1/index.json` shows `"contact_submissions":"paused"`, and `curl -s https://<render-host>/health` shows the same.

**What the pause does not do:** it stops **new** submissions only. Inquiries already accepted stay in Agent Leads, and their background jobs carry on: queued HubSpot deliveries are retried every 5 minutes and unsent notification emails hourly. To also hold queued HubSpot deliveries, set `NEPA_HUBSPOT_ENABLED` to false as well - the delivery worker then stops, the leads keep their status and are picked up again when it is set back to true (nothing is cancelled or lost). To withdraw a specific inquiry, handle it in wp-admin → Agent Leads (and in HubSpot if it was already delivered).

The agent page, llms.txt, ai.txt and the ARD manifest still describe the contact option; for a short pause that is fine (agents get a clear "paused" answer), for a long one, remove those passages in the next package build. To resume, remove the wp-config line (or set it to true), unset the Render variable, and purge the cache again.

## 8. Monitoring

- Point an uptime monitor at `https://<render-host>/health?deep=1` (fails when the MCP server or the WordPress API is down, or index.json is unreadable).
- Watch the PHP error log for `Nepa Agent API:` lines - failed lead storage, failed notifications ("giving up ... after 5 retries"), etc.
- In wp-admin → Agent Leads, check once a week for leads whose `mail_sent` is not `1`. The hourly job retries unsent notifications for 7 days (up to 6 attempts); after that they need manual follow-up.
- After each Sync, read the log: `WARNING` lines (e.g. a language missing from the REST output) and `FAILED` lines mean nothing or only part of the data was refreshed.

## 9. Lead data

Each Agent Lead stores the submitted name, email, company, service interest and summary, the time and channel (`mcp` or `direct`) of the submission, the caller's assertion that the person authorized it, and a salted hash of the caller's IP (not the IP itself). Leads are private posts visible only to administrators. They are kept until deleted by hand: Nepa decided on 2026-10-16 to keep manual deletion, so leave `NEPA_LEAD_RETENTION_DAYS` at 0. Nepa should add the agent channel to its privacy notice.

## Decisions and remaining checks

Business decisions (all made, 2026-10-16):

- **AI training:** allowed. See section 2, step 3.
- **Lead retention:** manual deletion. Keep `NEPA_LEAD_RETENTION_DAYS` at 0.

There is no staging site: this release is deployed directly to production, and scheduled jobs, email delivery and multilingual Sync are verified there, through section 13. Earlier notes that called them "confirmed on staging" are not evidence for this release. **Section 13 is the complete production sign-off list.** Section 10 describes the two checks that `tests/live-checks.sh` automates; passing those two alone is not sign-off.

## 10. Go-live checks automated by tests/live-checks.sh

Both use `tests/live-checks.sh`, which needs bash, curl and python3.

### 10.1 Concurrent duplicate submissions (production, plugin 1.4.0)

```bash
NEPA_ALLOW_PRODUCTION_TEST=1 NEPA_TEST_EMAIL_DOMAIN=<a-business-domain-you-control> bash tests/live-checks.sh duplicates https://nepa.com "ssh <install>@<install>.ssh.wpengine.net wp"
```

- The script sends 10 **simultaneous** identical requests with the same idempotency key, then 10 simultaneous identical requests without a key.
- **Pass:** each scenario has exactly one accepted request; all others get `duplicate: true` or `409 in_progress`; every response names the same lead; and the same key with different content gets `409 idempotency_key_reused`.
- With the optional WP-CLI command, the script also confirms the database holds exactly 2 leads carrying the run ID. On WP Engine, run WP-CLI over SSH, for example `"ssh <install>@<install>.ssh.wpengine.net wp"`. Without WP-CLI, search Agent Leads in wp-admin for the run ID the script prints.
- **What it does on the live site:** if duplicate protection works, it creates 2 test leads (first name "LIVE TEST", company "Nepa live check"), 2 notification emails to Nepa's inbox and, if HubSpot delivery is on, 2 submissions to the HubSpot form. That is the expected result, not a limit: the check fires simultaneous requests precisely to find duplicates, so if protection fails it can create up to 23 leads, emails and HubSpot submissions. Tell Nepa beforehand and run it at a quiet time.
- **Cleanup, whatever the result:** the script prints a run ID at the start and a CLEANUP line at the end. Delete every Agent Lead that a search for the **run ID alone** finds (wp-admin → Agent Leads search, or the WP-CLI command the script prints) - not a search for "Concurrency check", because the key-reuse request's message is "DIFFERENT content <run ID>" and would be missed if it was wrongly accepted, and in HubSpot every submission and contact for `live-check+<run ID>@<domain>` and `live-check2+<run ID>@<domain>`. If the check failed, also pause contact submissions (section 7a) until the cause is fixed. It counts against the 5-per-10-minutes contact quota of your IP address only; real visitors are not affected.
- Without `NEPA_ALLOW_PRODUCTION_TEST=1` it refuses to run against nepa.com and sends nothing.
- If it fails, **pause contact submissions** (section 7a) until the cause is fixed. Read-only agent access stays live.

The script's logic was checked against two local mock servers before shipping. It passes a correct implementation, and fails one that creates duplicates (all 10 concurrent requests accepted).

### 10.2 Cloudflare / WP Engine edge

**nepa.com is hosted on WP Engine.** Its IPs (141.193.213.10/11) belong to WPEngine, Inc., and responses come back with `server: cloudflare`, so the site sits behind WP Engine's Cloudflare-based edge (Global Edge Security / Advanced Network). Bot and firewall rules are therefore managed through WP Engine (User Portal or support), not a Cloudflare account of Nepa's own.

1. **Agents must get through unchallenged.** Run this (read-only):

   ```bash
   bash tests/live-checks.sh edge https://nepa.com <origin-host>
   ```

   **Baseline from 2026-10-16, before deployment:**

   | Agent | Result |
   |---|---|
   | All tested agents | `/agent/` returned 200. |
   | All except `python-requests` | `/agent/v1/*.json` and `/openapi.json` returned 301 to a trailing-slash URL. This is the old plugin 1.1.0 behaviour, fixed in 1.2.0. |
   | GPTBot and ClaudeBot | Got **429** on `/openapi.json`, `/llms.txt` and the press-releases endpoint after one or two requests: the edge rate-limits declared AI crawlers. |
   | Generic `python-requests` | Got **403 on everything** (a Cloudflare block). Many agent frameworks send this user agent. |

   Ask WP Engine to exempt `/agent/*`, `/openapi.json`, `/llms.txt`, `/ai.txt` and `/api-catalog.json` from bot challenges and crawler rate limits, in line with the robots.txt policy (all crawlers allowed). **Pass:** every line of the edge check returns 200.

2. **Before enabling `NEPA_TRUST_CLOUDFLARE_IP`**, check two things:
   - **Is the origin directly reachable?** WP Engine installs normally also answer on `<install>.wpengine.com`. Pass that host as the third argument. If it answers 200, anyone can bypass the edge and send a fake `CF-Connecting-IP`, so **leave the setting off**.
   - **What does PHP see as the client address?** On WP Engine, `REMOTE_ADDR` is normally already the visitor's real IP, in which case the setting is unnecessary. To check on production, temporarily add `error_log('REMOTE_ADDR=' . ($_SERVER['REMOTE_ADDR'] ?? '') . ' CF=' . ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '-'));` to wp-config.php, load any page, and compare the logged `REMOTE_ADDR` with your own public IP. If they match, leave `NEPA_TRUST_CLOUDFLARE_IP` off. Remove the line afterwards.


## 11. HubSpot form delivery (since plugin 1.3.0 / MCP server 1.2.0)

Agent inquiries now follow this path:

`MCP client → submit_contact_inquiry → /agent/v1/contact.json → stored Agent Lead in WordPress → HubSpot Forms submission API → Nepa's English contact form`

All HubSpot logic and credentials stay on the WordPress server.

### What is sent

- **Form.** Every inquiry goes to the English form `7c631e31-cc3b-44ba-a580-113ab3489db6` on portal 146131568 (EU), whatever the person's language. The Swedish, Finnish and Danish forms are not used because each of them **requires** the marketing-subscription checkbox (see "Open points for Nepa" below). The person's preferred language is written into the message.
- **Fields.** These are the form's own internal names:

  | Field | Object | Required? |
  |---|---|---|
  | `firstname` | contact (0-1) | Yes |
  | `lastname` | contact (0-1) | Optional; only sent when given |
  | `email` | contact (0-1) | Yes. HubSpot's default block list rejects free webmail. |
  | `name` | company (0-2) | Yes |
  | `phone` | contact (0-1) | Yes, 7–20 digits |
  | `message` | contact (0-1) | Yes |

  A full name is never split. The tool and endpoint require `first_name` separately.
- **Message.** The person's text, then a separated block with:
  - `Service interest: …` (if given)
  - `Preferred language: …` (if given)
  - `Submitted via MCP (an AI agent using Nepa's agent API) on behalf of the person named in this form.`
  - `Nepa lead ID: …`
- **Consent.** Three separate inputs, each recorded on the lead:
  1. Authorization to submit (`agent_context.user_authorized_submission`).
  2. Consent to processing (`consent.process`, required), sent as `consentToProcess: true` with the form's text "I agree to allow Nepa to store and process my personal data."
  3. Marketing (`consent.marketing_opt_in`, default **false**), sent as `communications[0].value` for subscription type 789062182 with the form's checkbox label.

  Nobody is opted into marketing unless they explicitly asked.
- **Not sent:** no HubSpot cookie (`hutk`), IP address or page URL. The person never visited a page, so none of that would be true.

### Is a HubSpot token needed?

**No, it is optional.** Without `NEPA_HUBSPOT_TOKEN` the plugin uses HubSpot's unauthenticated submit endpoint, and submissions work. With a token (a private app with the `forms` scope, created by a HubSpot super admin), the plugin:

- uses the authenticated endpoint, which has higher rate limits;
- and, more importantly, **can check whether an unconfirmed submission arrived** before sending it again (`GET /form-integrations/v1/submissions/forms/{form}`).

Without a token, unconfirmed submissions go to manual review instead of being resent.

### Delivery states (WordPress → Agent Leads → "HubSpot" column and side box)

| State | Meaning | What happens next |
|---|---|---|
| `delivered` | HubSpot answered 200, or the submission was found by lookup | Nothing |
| `pending` / `sending` | Saved, being sent | Automatic |
| `retryable` | Temporary failure: 429, 500/503, or connection refused | The worker (every 5 minutes) retries with backoff of 1 min, 5 min, 15 min, 30 min, 1 h, 2 h, 4 h and 8 h, honouring HubSpot's rate-limit window. After 8 attempts the lead moves to `manual_review`. |
| `uncertain` | HubSpot may have accepted it (timeout, 502/504, crash mid-request, or the result couldn't be recorded) | **With a token:** looked up first, and resent only if not found after 10 minutes. **Without a token:** `manual_review`. |
| `rejected` | HubSpot refused the person's details (for example `BLOCKED_EMAIL`, `REQUIRED_FIELD`, `VALUE_NOT_IN_FIELD_DEFINITION`) | Not retried. The agent is told which details to fix and to submit again. |
| `manual_review` | Needs a person: wrong token or form ID (401/403/404), a form or consent configuration error, retries exhausted, or an unknown outcome without a token | An email goes to info@nepa.com once per lead and reason |
| `disabled` | `NEPA_HUBSPOT_ENABLED` is not true | Saved in WordPress only |
| `not_applicable` | A lead saved before HubSpot delivery existed | Never sent |

**Operations.** Open the lead in wp-admin → Agent Leads. The **HubSpot delivery** box shows the attempts, next attempt, review reason, the exact submission time sent to HubSpot, and a log. Two buttons are available to administrators only (nonce-protected):

- **Retry HubSpot delivery now**
- **Mark as delivered (found in HubSpot)**

A banner lists leads that need a check. Errors are logged without email addresses or tokens.

### Duplicates: what is and isn't guaranteed

- **One inquiry per submission, within the recognition window.** Every request reserves its content (30 minutes) and, when it has an `idempotency_key`, the key as well (24 hours), always in that order. So a repeated or simultaneous request with the same key within 24 hours, or with identical content within 30 minutes - with or without a key, including a retry that adds or drops one - creates no second lead or HubSpot submission. After those windows a repeat is a new inquiry (after 30 minutes only the original key matches, which is why the MCP tool tells agents to keep the key exactly as on the first call). Trade-off (Nepa's decision, 2026-11-16): two inquiries with identical content but different keys within 30 minutes are treated as one. A key already used for a different inquiry is refused with 409 `idempotency_key_reused`, also when the content it is sent with matches another inquiry. One narrow exception: if that key is being bound to other content at the very same moment, the request can be answered with the existing lead whose content it matches instead of the 409 - it never creates a lead or a HubSpot submission, so nothing is duplicated. It returns the existing lead and its current delivery status.
- **No blind resends.** HubSpot's submission API has **no idempotency key**, so exactly-once delivery can't be guaranteed. The plugin records `sending` before every request and never sends if it can't. It sends every attempt with the same fixed `submittedAt`. It never blindly resends anything that may have arrived.
- **Remaining risk (token mode only).** HubSpot accepts a submission, the response is lost, and after 10 minutes the submission is still not visible in the newest 250 submissions of the form. The plugin then resends it and the contact gets a second submission on the form, which could re-trigger form workflows. **Recovery:** delete the duplicate submission in HubSpot. Without a token this can't happen, because unknown outcomes always go to manual review.

### Notifications

Unchanged, as Nepa decided:

- WordPress emails info@nepa.com for every new inquiry. The email now states the HubSpot delivery status at the time it was sent.
- The HubSpot form's own notification and workflows also run for every delivered submission.

Nepa may therefore receive **two notifications per inquiry** (one from WordPress, one from HubSpot). To keep only one, remove info@nepa.com from the form's notification settings in HubSpot, or ask to switch off the WordPress email for delivered leads.

### Retention

Agent Leads in WordPress, including their delivery metadata, are deleted only by hand (`NEPA_LEAD_RETENTION_DAYS` = 0, as decided). **Deleting a WordPress lead does not delete the HubSpot contact or form submission.** Those follow Nepa's HubSpot retention and deletion processes.

### Live acceptance (real WordPress + real HubSpot)

After deploying plugin 1.3.0 and MCP server 1.2.0, with `NEPA_HUBSPOT_ENABLED` true:

```bash
bash tests/live-checks.sh hubspot https://agentic-mcp-saas-nepa.onrender.com/mcp <test-email-on-a-business-domain>
```

This submits a real inquiry to the live HubSpot form through the MCP tool (company "Nepa live check", names "LIVE TEST"), repeats it, and prints the checklist to verify in HubSpot:

1. one submission on the intended form;
2. correct contact properties;
3. consent recorded and no marketing subscription;
4. workflows and notifications ran;
5. the repeat created nothing new;
6. temporary-failure recovery (optional);
7. permanent-failure handling (optional).

Steps 6 and 7 change a live setting for a few minutes, so real inquiries arriving in that window are also held back from HubSpot (still saved in WordPress). Do them at a quiet time; afterwards, check Agent Leads for any real inquiry not marked "delivered" and use "Retry HubSpot delivery now" on it.

The `duplicates` check (section 10.1) runs on nepa.com with `NEPA_ALLOW_PRODUCTION_TEST=1` and covers simultaneous submissions; step 5 above covers a single repeat through the MCP tool. Delete the test leads and HubSpot submissions from both checks afterwards.

### Open points for Nepa

- **Mislabelled marketing checkbox.** On all four contact forms, the marketing subscription checkbox (type 789062182) is labelled as a privacy-policy acknowledgement. On the Swedish, Finnish and Danish forms it is **required**, so submitting those forms subscribes people to marketing. Fix the labels, and the required setting on the localized forms. Agent inquiries avoid this by using the English form, where the box is optional.
- **Unverified on real HubSpot (checked during live acceptance):**
  - whether this EU account needs a regional API address (configurable via `NEPA_HUBSPOT_SUBMIT_BASE`);
  - whether HubSpot stores the `submittedAt` sent with each submission, which the lookup relies on;
  - the exact phone format HubSpot's validation accepts.

## 12. Updating the live site from agentic-web-2026-10 (short version)

The 2026-10 package is live (checked 2026-11-16: plugin 1.3.0, MCP 1.2.0, discovery plugin 1.0.0, robots.txt with `ai-train=yes`). To move to 2026-11:

1. Back up as in section 0.
2. Replace `wp-content/plugins/nepa-api/` with `nepa-api/` (1.4.0), then go to **Settings → Permalinks → Save** (required: 1.4.0 adds a URL rule so the API base URL `/agent/v1/` answers; until the rules are flushed it stays a 404). Re-activating also works. The new `NEPA_DATA_RELEASE` (`agentic-web-2026-11`) makes the plugin serve the bundled data straight away; run **Sync now** afterwards if you want posts published after 2026-11-16 included.
3. Upload both must-use plugins (section 1a).
4. Upload `llms.txt`, `ai.txt`, `api-catalog.json`; add the `Agentmap` line to robots.txt (section 2).
5. Update the MCP repository with the contents of `nepa-mcp/` and let Render redeploy (`npm ci && npm run build`), which installs the patched dependencies (section 3).
6. Purge caches (section 4), run the checks in section 6, and record the results in section 13. Don't click Sync now until you do row 8 of section 13 (after rows 1-4 pass); the bundled data is served without it.

Nothing in HubSpot delivery, the contact endpoint or `wp-config.php` changes in this release.

## 13. Production sign-off (real-environment acceptance)

The packaged tests run against stand-ins (WordPress, HubSpot) and PHP's built-in server; they cannot show behaviour on WP Engine, Cloudflare, real MySQL or the real HubSpot form. There is no staging site, so every row is done on production right after deploying; record each result. Rows 1-4 are read-only. Rows 5 and 6 create test leads (labelled "LIVE TEST") - tell Nepa beforehand and delete them afterwards - every record carrying the run ID the script prints (rows 5 and 6 can create more than the expected number if the protection they test fails). If row 5, 6 or 7 fails, pause contact submissions as in section 7a (`NEPA_CONTACT_ENABLED` in wp-config.php and on Render - turning off HubSpot alone does not stop inquiries) until it is fixed; the read-only parts stay live. Row 8 is the first Sync on production: do it last, at a quiet time.

| # | Check | How | Pass when | Result / date / by |
|---|---|---|---|---|
| 1 | Install on production | Plugin 1.4.0, both must-use plugins, discovery files, robots line; Settings → Permalinks → Save; purge caches | `/agent/`, `/agent/v1/`, `/agent/v1/index.json` (version 1.4.0), `/openapi.json` (1.4.0) and every `/.well-known/*` route return 200 | |
| 2 | Discovery links on localized pages | View source of `/sv/`, `/fi/`, `/da/products/` | `llms.txt`, `agent`, `api-catalog`, `service-desc` and `ard` links have no `/sv/`, `/fi/`, `/da/` in them (confirms the multilingual plugin leaves the `home` option unfiltered) | |
| 3 | ARD on the real host | `python3 tests/validate-ard-manifest.py https://<host>/.well-known/ard.json nepa.com`; optionally the official `conformance-test publisher <host>` | 0 errors; `Link: rel="ard"` header present on HTML pages | |
| 4 | Edge and cache | `bash tests/live-checks.sh edge https://nepa.com` (section 10.2); inspect WP Engine/NitroPack rules | Declared agent user agents get 200 on `/agent/*`, `/openapi.json`, `/llms.txt`, `/ai.txt`, `/api-catalog.json`, `/.well-known/*`; cache keys include the query string for `/agent/v1/`; `POST /agent/v1/contact.json` is never cached; origin not directly reachable before `NEPA_TRUST_CLOUDFLARE_IP` is enabled | |
| 5 | Concurrent duplicate inquiries | `NEPA_ALLOW_PRODUCTION_TEST=1 bash tests/live-checks.sh duplicates https://nepa.com` (section 10.1); delete the 2 test leads afterwards | One lead and one intended delivery per inquiry; same key with different details → 409 `idempotency_key_reused` | |
| 6 | HubSpot end to end | `bash tests/live-checks.sh hubspot https://<mcp-host>/mcp <test-email>` (section 11, "Live acceptance"); afterwards delete every Agent Lead and HubSpot record for the test email / "HubSpot acceptance test <run>" | Lands in the intended English form with the right fields; processing consent set; marketing false by default; status `delivered`; WordPress and HubSpot notifications as documented; a repeat is not re-sent; token lookup works if a token is configured | |
| 7 | MCP on Render | `/health?deep=1`, `/tools` | Version 1.2.1, API reachable, 18 tools; an unreachable API is reported as "OUTCOME UNKNOWN", never as "not saved", and its retry advice keeps the `idempotency_key` exactly as on the first call (no key added) | |
| 8 | First Sync on production | Verify post types (section 1.6) and multilingual REST (1.7), then Sync now twice. If anything looks wrong, delete `wp-content/uploads/nepa-agent-api/manifest.json` - the plugin serves the bundled data again immediately - then purge `/agent/v1/*` so cached responses are replaced | Both runs end with `DONE`; `manifest.json` names a `gen-…` folder; the November corrections survive (the brand-salience post has no author, "Research should shape…" keeps Dora Harfman Bromée); `meta.version` stays 1.2; `python3 tests/validate-data-files.py wp-content/uploads/nepa-agent-api/<gen-folder>` passes | |

## 14. Update of 2026-11-17 (fixes after the live audit)

For a site that already runs the 2026-11 package. Nothing in wp-config.php, HubSpot or the MCP server changes.

| Upload from the package | To |
|---|---|
| `nepa-agent-discovery.php` (1.2.0) | `wp-content/mu-plugins/` (replace) |
| `nepa-api/openapi.json` (1.4.0) | `wp-content/plugins/nepa-api/openapi.json` |
| `nepa-api/agent-page.html` | `wp-content/plugins/nepa-api/agent-page.html` (also restores the three discovery links from the 2026-10-27 commit) |
| `nepa-api/data/localized-pages.json` | `wp-content/plugins/nepa-api/data/localized-pages.json` |
| `llms-full.txt` (new), `llms.txt`, `ai.txt`, `api-catalog.json` | Web root |

Then:

1. **robots.txt:** delete the line `User-agent: Googlebot-Extended` (not a real crawler token; `Google-Extended` stays).
2. **Purge caches** (section 4), including the HTML pages (new `llms-full.txt` link in `<head>`, SearchAction removed) and `/agent/`, `/openapi.json`, `/agent/v1/localized-pages.json`.
3. **Check** (section 6): `?format=md` pages have content; `/openapi.json` shows 1.4.0 and the corrected pricing summary; `/llms-full.txt` and `/.well-known/security.txt` answer 200; the homepage JSON-LD has no SearchAction; `/agent/v1/localized-pages.json?language=fi` shows `"alternates": null` for the webinar page.

What these fix: empty Markdown versions (the converter read the post's stored content, which the Enfold theme leaves almost empty - it now converts the rendered page), the OpenAPI/plugin version mismatch and the stale pricing summary, the missing `llms-full.txt` and `security.txt`, the non-existent `Googlebot-Extended` token, and the SearchAction to a URL robots.txt disallows. See CHANGELOG.md.

