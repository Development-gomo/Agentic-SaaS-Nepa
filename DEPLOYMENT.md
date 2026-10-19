# Deploying agentic-web-2026-10 to nepa.com

This package has three parts that deploy separately:

| Part | Folder / files | Where it goes |
|---|---|---|
| WordPress plugin "Nepa Agent API" 1.2.0 | `nepa-api/` | `wp-content/plugins/nepa-api/` |
| Discovery files | `llms.txt`, `ai.txt`, `api-catalog.json`, `robots-addition.txt` | Web root (`https://nepa.com/<file>`) and robots.txt |
| MCP server 1.1.0 | `nepa-mcp/` | Render web service |

The plugin serves `/agent/`, `/agent/v1/*.json` and `/openapi.json` itself. It does **not** serve `llms.txt`, `ai.txt` or `api-catalog.json`, and it does not edit `robots.txt`; those are separate steps below.

Requirements: WordPress 6.0+, PHP 7.4+, Node.js 20+ for the MCP server (a locked dependency, `@hono/node-server`, requires Node 20).

Before deploying, run the packaged tests (see `tests/README.md`): `php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_WARNING' tests/run-plugin-tests.php` and `bash tests/mcp-smoke-test.sh`. Both must report 0 failed.

---

## 0. Before you start

1. **Back up** the current plugin folder `wp-content/plugins/nepa-api/`, the current `llms.txt`, `ai.txt`, `api-catalog.json` and `robots.txt` from the web root, and note the Render service's current commit. These are the rollback copies (section 7).
2. **Use staging first.** Every step below should be run on a staging copy of nepa.com before production. The checks in section 6 are the minimum.
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

## 2. Discovery files

1. Upload `llms.txt`, `ai.txt` and `api-catalog.json` to the web root, replacing the current files.
2. Check content types: `llms.txt` and `ai.txt` as `text/plain; charset=utf-8`, `api-catalog.json` as `application/json`.
3. **robots.txt:** the live file already contains every user-agent in `robots-addition.txt`. Copy across only what differs: add the `AI-File` line, and **change the live `Content-Signal: ai-train=no` line to `Content-Signal: ai-train=yes, search=yes, ai-input=yes`** (Nepa decided on 2026-10-16 to allow AI training, matching ai.txt). Don't append the block twice.

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

## 4. Cache and firewall

1. Purge NitroPack and Cloudflare caches for `/agent/`, `/agent/v1/*`, `/openapi.json`, `/llms.txt`, `/ai.txt`, `/api-catalog.json`.
2. **Don't cache filtered API responses as one URL.** `/agent/v1/*.json` responses vary by query string (`?language=`, `?service=`, `?limit=` ...). Make sure the cache key includes the query string, or bypass the cache for `/agent/v1/`.
3. **Never cache `POST /agent/v1/contact.json`.**
4. **Let agents through the edge.** nepa.com sits behind WP Engine's Cloudflare-based edge, so request the exemption through WP Engine. On the live site, GPTBot and ClaudeBot get 429 on the agent files and a generic `python-requests` client gets 403. Ask WP Engine to exempt `/agent/*`, `/openapi.json`, `/api-catalog.json`, `/llms.txt` and `/ai.txt` from bot challenges and crawler rate limits, then verify with section 10.2.

## 5. Keeping content fresh

There are two kinds of content, updated in different ways:

| Content | How it is updated |
|---|---|
| `blog-posts`, `press-releases`, `case-studies`, `products`, `solutions`, `team`, `company-news`, `events` JSON | **Tools → Nepa Agent API → Sync now** in wp-admin, after publishing. Curated fields are kept. All sections succeed or nothing is replaced. |
| `agent-page.html`, `llms.txt`, `api-catalog.json`, and the `pricing`, `company`, `investor-relations`, `localized-pages`, `resources`, `offices`, `industries`, `jobs` JSON | Regenerated by the next package build (a fresh crawl, like this one). Sync does not touch them. |

So between package builds, the JSON API can be newer than the agent page and llms.txt. Recommended rhythm: Sync after each publication; rebuild the package monthly, or straight away after leadership, pricing or product changes.

Synced files live in `wp-content/uploads/nepa-agent-api/`, together with `manifest.json`, which records the release the sync was built on (`NEPA_DATA_RELEASE` in nepa-api.php, currently `agentic-web-2026-10`) and the files it wrote. **Precedence rule:** a synced file is served only when the manifest lists it and its release equals the installed plugin's `NEPA_DATA_RELEASE`; otherwise the bundled `data/` file is served. File timestamps play no part. Installing a new package release (new `NEPA_DATA_RELEASE`) therefore takes effect immediately, and the next Sync builds on it; reinstalling the same release keeps synced content. Include `wp-content/uploads/nepa-agent-api/` in backups.

## 6. Checks after deployment (staging, then production)

```bash
curl -s https://nepa.com/agent/v1/index.json | head -c 300
```
- `/agent/v1/index.json` returns JSON with `"version":"1.2.0"` and lists `localized-pages`, without a 301 to a trailing-slash URL.
- `/agent/v1/case-studies.json?service=Brand%20Tracking` returns 10 items.
- `/agent/v1/press-releases.json?language=sv&limit=5` returns Swedish releases with `"total":151`.
- `/agent/v1/localized-pages.json?language=fi` returns 24 pages.
- `/agent/` shows "last updated 2026-10-16"; `/openapi.json` shows version 1.1.0.
- `https://<render-host>/health?deep=1` returns `"status":"ok"` with the API version; `/tools` lists 18 tools.
- **Contact flow (staging only):** POST a test inquiry; POST it again with the same `idempotency_key` (expect `"duplicate": true` and the same `lead_id`); POST different details with that key (expect 409 `idempotency_key_reused`); send two identical requests at the same moment (expect one lead, one email). Confirm one Agent Lead per accepted inquiry and one email each. Then break SMTP, submit again, confirm the lead shows `mail_sent = 0`, and check that the hourly job re-sends it once SMTP works (leads under 5 minutes old are skipped).
- `npm audit` in `nepa-mcp/` shows no high/critical issues.

## 7. Rollback

1. Restore the backed-up `wp-content/plugins/nepa-api/` folder and re-activate the plugin (Settings → Permalinks → Save).
2. If the problem is synced data, delete `wp-content/uploads/nepa-agent-api/manifest.json` (or the whole folder); the plugin then serves its bundled `data/` files.
3. Restore the backed-up `llms.txt`, `ai.txt`, `api-catalog.json`, `robots.txt`.
4. In Render, redeploy the previous commit.
5. Purge caches as in section 4.

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

Confirmed on staging: scheduled jobs (WP-Cron), notification email delivery, and multilingual Sync.

**Two technical checks remain before go-live** (section 10). Once both pass, the deployment concerns from the reviews are cleared:

1. **Concurrent duplicate submissions.** Simultaneous requests with the same idempotency key must create exactly one lead. If they don't, that blocks enabling contact submissions; read-only agent access can still go live.
2. **Cloudflare.** Agent requests must reach the API without a challenge or block, and you must check whether the origin is directly reachable before enabling `NEPA_TRUST_CLOUDFLARE_IP`.

## 10. The two remaining go-live checks

Both use `tests/staging-checks.sh`, which needs bash, curl and python3.

### 10.1 Concurrent duplicate submissions (staging, after deploying plugin 1.2.0)

```bash
bash tests/staging-checks.sh duplicates https://<staging-host> "wp --path=/path/to/wordpress"
```

- The script sends 10 **simultaneous** identical requests with the same idempotency key, then 10 simultaneous identical requests without a key.
- **Pass:** each scenario has exactly one accepted request; all others get `duplicate: true` or `409 in_progress`; every response names the same lead; and the same key with different content gets `409 idempotency_key_reused`.
- With the optional WP-CLI command, the script also confirms the database holds exactly 2 test leads. On WP Engine, run WP-CLI over SSH, for example `"ssh <install>@<install>.ssh.wpengine.net wp"`. Without WP-CLI, search Agent Leads in wp-admin for the run ID the script prints.
- The script creates 2 test leads and sends 2 notification emails, so delete the leads afterwards.
- It refuses to run against nepa.com.
- If it fails, **don't enable contact submissions** (keep `submit_contact_inquiry` and `/agent/v1/contact.json` unadvertised). Read-only agent access can still go live.

The script's logic was checked against two local mock servers before shipping. It passes a correct implementation, and fails one that creates duplicates (all 10 concurrent requests accepted).

### 10.2 Cloudflare / WP Engine edge

**nepa.com is hosted on WP Engine.** Its IPs (141.193.213.10/11) belong to WPEngine, Inc., and responses come back with `server: cloudflare`, so the site sits behind WP Engine's Cloudflare-based edge (Global Edge Security / Advanced Network). Bot and firewall rules are therefore managed through WP Engine (User Portal or support), not a Cloudflare account of Nepa's own.

1. **Agents must get through unchallenged.** Run this (read-only):

   ```bash
   bash tests/staging-checks.sh edge https://nepa.com <origin-host>
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
   - **What does PHP see as the client address?** On WP Engine, `REMOTE_ADDR` is normally already the visitor's real IP, in which case the setting is unnecessary. To check on staging, temporarily add `error_log('REMOTE_ADDR=' . ($_SERVER['REMOTE_ADDR'] ?? '') . ' CF=' . ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '-'));` to wp-config.php, load any page, and compare the logged `REMOTE_ADDR` with your own public IP. If they match, leave `NEPA_TRUST_CLOUDFLARE_IP` off. Remove the line afterwards.
