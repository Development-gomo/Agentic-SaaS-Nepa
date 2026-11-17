# Tests

Test scripts ship with the package so its results can be reproduced. None touches the package's own files, and none replaces the real-environment checks in DEPLOYMENT.md §13.

## Everything at once

```bash
bash tests/run-all.sh                      # PYTHON=/path/to/python3 to choose the interpreter
```

Runs every suite below and prints one PASS / FAIL / INCOMPLETE line each; exit code 0 only when all passed. The Python validators need `jsonschema`. Without it they report **INCOMPLETE** and exit with code 3 instead of passing (set `ALLOW_SKIP=1` to accept a skip deliberately), so a missing dependency can never look like a successful release gate.

## Plugin (PHP 7.4+)

```bash
php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_WARNING' tests/run-plugin-tests.php
```

Runs `nepa-api/` against in-memory stand-ins for WordPress (`tests/wp-stubs.php`, including a fake `wp_options` table with a unique key). It runs on a temporary copy of the plugin. 108 checks cover:

- activation and rewrite rules, plus the CORS headers
- filters
- contact validation, consent and rate limits
- atomic idempotency: duplicates, key reuse, in-flight requests, expired reservations, a replayed race on an expired reservation, a database failure while reserving; retries that add or drop a key (both return the original lead), the documented identical-content/different-key case, a key outliving the 30-minute content window, the content reservation being released when a request loses on its key, and a key already bound to inquiry A being refused (409) when sent with inquiry B's content, whether B is finished or still in flight (not covered: the narrow case of key A being bound at the very same moment, which returns B's existing lead and creates nothing)
- storage and email failures, including failed metadata writes combined with a failed email
- the notification retry job, including exhausted leads, leads with no retry state, and the 7-day retry window
- the retention job, including trashed leads and a cleanup race
- Sync: curated merge, shrink guard, all-or-nothing sections; publication as one generation (a failed manifest switch changes nothing that is served, files not re-synced are carried forward, a reader holding the previous manifest can finish, old generations are pruned); the atomic lock (exclusive, owner-only release, stale-lock takeover); blog posts keeping editorial corrections (CMS-account authors never restored, an intentional null author kept, the visible byline kept, real author changes still applied, curated excerpts kept) and `meta.version` 1.2
- release-manifest data precedence
- the contact switch (`NEPA_CONTACT_ENABLED` false: 503 `contact_disabled`, nothing stored, emailed, counted or sent; `index.json` reports "paused"; read-only endpoints unaffected), run through the real request router in a separate process by `tests/plugin-route-probe.php`

Exit code 0 means everything passed.

What the stubs can't show: real MySQL concurrency, WP-Cron timing, SMTP behaviour, translation-plugin REST output and Cloudflare. There is no staging site, so those are checked on production after deploying (DEPLOYMENT.md §13).

## MCP server (Node 20+, npm, python3)

```bash
bash tests/mcp-smoke-test.sh
```

Steps:

1. Copies `nepa-mcp/` into a temporary workspace, then installs the locked dependencies, builds and runs `npm audit` there.
2. Type-checks the source.
3. Starts the server against the package's data files, served locally.
4. Checks the tool list and annotations, a tool call, input limits, the consent gate, CORS preflight and the health endpoints.

Everything runs in a temporary copy of `nepa-mcp/`, which is deleted afterwards; the package folder is left untouched, including any existing `node_modules/`, and the last check verifies this. 40 checks; exit code 0 means everything passed.

## Agentic Resource Discovery (PHP 7.4+ CLI; python3 for the schema check)

```bash
ARD_MANIFEST_OUT=/tmp/ard.json php tests/run-ard-tests.php
python3 tests/validate-ard-manifest.py /tmp/ard.json nepa.com
python3 tests/validate-ard-manifest.py https://nepa.com/.well-known/ard.json nepa.com   # after deployment
```

`run-ard-tests.php` tests `agentic-resource-discovery.php` and `nepa-agent-discovery.php` with small WordPress stand-ins (`tests/ard-stubs.php`, which can also imitate a multilingual plugin that prefixes `home_url()` with the language). 71 checks:

- **Module logic, in-process:** the entry rules (required terms, `urn:air:` identifiers anchored on the publisher, exactly one of `url`/`data`, media types, `trustManifest.identity`, flat `metadata`), invalid and duplicate entries left out and logged, the 2-5 `representativeQueries` warning, open `@context` vocabulary, the config filter and the in-page JSON-LD.
- **Real HTTP:** starts PHP's built-in server with `tests/ard-router.php` and checks `/.well-known/ard.json` (status, content type, CORS, caching, ETag, `Link` header, HEAD, OPTIONS, 405 for POST), `<link rel="ard">` and the `Link` header on pages, the existing discovery links, the ARD links in `/.well-known/api-catalog` and `/.well-known/mcp.json`, a 404 when no entries are registered, URLs following the site's `home` option on another host, and links that stay at the site root on a language-prefixed page (/sv/).
- **Package consistency:** the agent page's embedded ARD JSON-LD equals the served entries; robots-addition.txt, llms.txt, ai.txt and api-catalog.json point to the manifest; the MCP entry's capabilities equal the tool list in `nepa-mcp/src/index.ts`; entry versions equal `nepa-mcp/package.json` and `openapi.json`.

Five deliberately injected defects (url/data rule removed, head link removed, a tool missing from the entry, duplicate check removed, language-prefixed `home_url()` restored) were each caught. Also covered: entries whose `data` or `metadata` would serialize as a JSON array, non-string `description`/`version`, a malformed `updatedAt`, trust-manifest identities that don't belong to the publisher domain (including look-alike domains), conditional requests (`If-None-Match` → 304) and a module with no entries answering 404 for every method.

`validate-ard-manifest.py` validates a manifest (file or URL) against the **unmodified official ARD v0.91 JSON schema** in `tests/ard-spec/` (needs `pip install jsonschema`; without it that part is skipped) and applies the discovery rules of spec Appendix D (URN form and publisher, uniqueness, url/data, 2-5 representative queries as a warning, trust-manifest identity alignment). The official conformance tester (`conformance/bin/conformance-test` in https://github.com/ards-project/ard-spec) is not bundled; run it from a clone of that repository: `conformance-test publisher nepa.com`.

## Live-site checks (real site)

```bash
NEPA_ALLOW_PRODUCTION_TEST=1 bash tests/live-checks.sh duplicates https://nepa.com [WP-CLI command]
bash tests/live-checks.sh edge https://nepa.com [origin-host]
```

These are the two go-live checks in DEPLOYMENT.md §10.

- **`duplicates`** is expected to create 2 test leads ("LIVE TEST"), 2 notification emails and, if HubSpot delivery is on, 2 HubSpot submissions - up to 23 of each if the duplicate protection it tests fails. It prints a run ID and a CLEANUP line naming every record to delete. On nepa.com it runs only with `NEPA_ALLOW_PRODUCTION_TEST=1`; without it, it refuses and sends nothing. Tell Nepa first and delete the test leads afterwards. It checks that simultaneous identical submissions create exactly one lead. Its pass/fail logic was validated against a correct and a deliberately buggy mock server.
- **`edge`** is read-only. It checks that common AI agents get HTTP 200 from the agent endpoints (including `/.well-known/ard.json`), and whether the origin can be reached directly.

## HubSpot delivery (PHP 7.4+)

```bash
php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_WARNING' tests/run-hubspot-tests.php token
php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_WARNING' tests/run-hubspot-tests.php notoken
```

WordPress **and HubSpot are both mocked**. The HubSpot mock answers as HubSpot's documented API does: 200; 400 with `errorType`; 401; 429 with rate-limit headers; 5xx; timeouts; and the submissions lookup. The suite runs in two modes:

- **`token`**, with `NEPA_HUBSPOT_TOKEN` set: 76 checks.
- **`notoken`**, without a token: 68 checks.

The checks cover:
- field mapping and successful submission;
- missing required fields, and invalid option values reported by HubSpot;
- consent without unintended marketing opt-in;
- repeated and simultaneous requests;
- concurrent retry workers;
- rate limits, server errors and timeouts;
- permanent validation and configuration failures;
- HubSpot accepting a submission whose response was lost;
- the local status write failing after HubSpot accepted;
- administrator actions and permissions;
- the token never being stored.

The MCP smoke test (40 checks) uses `tests/mock-contact-api.py` to check that the tool reports every delivery outcome accurately (delivered, pending, retrying, unconfirmed, needs review, disabled, rejected, in progress, not saved for the API's own codes, and unknown for a lead saved before a JSON 500, an unrecognised 503, a non-JSON gateway error and a timeout), and that it sends consent and marketing choices correctly. It also starts a second server with `NEPA_CONTACT_ENABLED=false` (17 tools, no submit tool, "paused" in `/health` and `/tools`, nothing reaches the API, read-only tools still work), and checks that the retry guidance never tells an agent to add an `idempotency_key` on a retry: the API matches a repeat by its key, or by content when there is no key, so a key added on retry would create a second inquiry.

`tests/mock-duplicates-server.py` validates `live-checks.sh duplicates` itself: the check passes against its `correct` mode and fails against its `buggy` mode.

`bash tests/live-checks.sh hubspot https://<mcp-host>/mcp <test-email>` is the **real** end-to-end acceptance check, through the deployed MCP tool into the real HubSpot form. See DEPLOYMENT.md §11.

## OpenAPI response check (Python + jsonschema)

```bash
NEPA_DUMP_RESPONSES=/tmp/responses.jsonl php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_WARNING' tests/run-hubspot-tests.php token
python3 tests/validate-openapi-examples.py /tmp/responses.jsonl
```

This validates hand-written examples and every `/contact.json` response the plugin produced during the HubSpot suite against `openapi.json`. It needs the `jsonschema` package; without it the result is INCOMPLETE (exit 3).

```bash
python3 tests/validate-data-files.py [folder]
```

Validates all 17 data files in `nepa-api/data/` (or another folder, such as a synced `gen-…` generation) against their response schemas in `openapi.json`, and fails on any empty JSON object (`{}`): the plugin re-encodes filtered and paginated responses with PHP, which serves `{}` as `[]`. Same INCOMPLETE rule.

The stubs in `wp-stubs.php` strip slashes from saved meta and post fields exactly like WordPress, so escaping mistakes show up in tests. Like WordPress, post meta is cached per simulated request (`T['ctx']`). `nepa_test_request()` runs code as a separate request, so stale-cache races can be reproduced. Three one-shot hooks replay races: `T['after_insert']` runs right after a lead is created, `T['before_meta_write']` runs just before a chosen field is written, and `T['before_option_insert']` runs just before a lock is taken.
