# Tests

Two test scripts ship with the package so its results can be reproduced. Neither touches the package's own files, and neither replaces a run on a real WordPress staging site (see DEPLOYMENT.md §6).

## Plugin (PHP 7.4+)

```bash
php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_WARNING' tests/run-plugin-tests.php
```

Runs `nepa-api/` against in-memory stand-ins for WordPress (`tests/wp-stubs.php`, including a fake `wp_options` table with a unique key). It runs on a temporary copy of the plugin. 60 checks cover:

- activation and rewrite rules, plus the CORS headers
- filters
- contact validation, consent and rate limits
- atomic idempotency: duplicates, key reuse, in-flight requests, expired reservations, a replayed race on an expired reservation, a database failure while reserving
- storage and email failures, including failed metadata writes combined with a failed email
- the notification retry job, including exhausted leads, leads with no retry state, and the 7-day retry window
- the retention job, including trashed leads and a cleanup race
- Sync: curated merge, shrink guard, all-or-nothing commit, lock
- release-manifest data precedence

Exit code 0 means everything passed.

What the stubs can't show: real MySQL concurrency, WP-Cron timing, SMTP behaviour, translation-plugin REST output and Cloudflare. Test those on staging.

## MCP server (Node 20+, npm, python3)

```bash
bash tests/mcp-smoke-test.sh
```

Steps:

1. Copies `nepa-mcp/` into a temporary workspace, then installs the locked dependencies, builds and runs `npm audit` there.
2. Type-checks the source.
3. Starts the server against the package's data files, served locally.
4. Checks the tool list and annotations, a tool call, input limits, the consent gate, CORS preflight and the health endpoints.

Everything runs in a temporary copy of `nepa-mcp/`, which is deleted afterwards; the package folder is left untouched, including any existing `node_modules/`, and the last check verifies this. 13 checks; exit code 0 means everything passed.

## Staging and edge checks (real site)

```bash
bash tests/staging-checks.sh duplicates https://<staging-host> [WP-CLI command]
bash tests/staging-checks.sh edge https://nepa.com [origin-host]
```

These are the two go-live checks in DEPLOYMENT.md §10.

- **`duplicates`** creates test leads and sends emails. It is staging only and refuses nepa.com. It checks that simultaneous identical submissions create exactly one lead. Its pass/fail logic was validated against a correct and a deliberately buggy mock server.
- **`edge`** is read-only. It checks that common AI agents get HTTP 200 from the agent endpoints, and whether the origin can be reached directly.

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

The MCP smoke test (now 26 checks) uses `tests/mock-contact-api.py` to check that the tool reports every delivery outcome accurately (delivered, pending, retrying, unconfirmed, needs review, disabled, rejected, in progress, not saved, unknown), and that it sends consent and marketing choices correctly.

`tests/mock-duplicates-server.py` validates `staging-checks.sh duplicates` itself: the check passes against its `correct` mode and fails against its `buggy` mode.

`bash tests/staging-checks.sh hubspot https://<mcp-host>/mcp <test-email>` is the **real** end-to-end acceptance check, through the deployed MCP tool into the real HubSpot form. See DEPLOYMENT.md §11.

## OpenAPI response check (Python + jsonschema)

```bash
NEPA_DUMP_RESPONSES=/tmp/responses.jsonl php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_WARNING' tests/run-hubspot-tests.php token
python3 tests/validate-openapi-examples.py /tmp/responses.jsonl
```

This validates hand-written examples and every `/contact.json` response the plugin produced during the HubSpot suite against `openapi.json`. It needs the `jsonschema` package and skips cleanly without it.

The stubs in `wp-stubs.php` strip slashes from saved meta and post fields exactly like WordPress, so escaping mistakes show up in tests. Like WordPress, post meta is cached per simulated request (`T['ctx']`). `nepa_test_request()` runs code as a separate request, so stale-cache races can be reproduced. Three one-shot hooks replay races: `T['after_insert']` runs right after a lead is created, `T['before_meta_write']` runs just before a chosen field is written, and `T['before_option_insert']` runs just before a lock is taken.
