# Nepa MCP server

Standalone MCP server exposing Nepa's agent REST API. Requires Node.js 20 or later (the locked `@hono/node-server` dependency needs it).

## Local development

```sh
npm ci
npm run build
npm start
```

## Render

Connect a Render Node web service to `Development-gomo/Agentic-MCP-SaaS-Nepa` with:

- Branch: `main`
- Root directory: leave blank in `Development-gomo/Agentic-MCP-SaaS-Nepa`, where this project is the repository root. If you deploy from a repository that holds the whole Agentic Web package, set it to `nepa-mcp`.
- Build command: `npm ci && npm run build`
- Start command: `npm start`
- Health check path: `/health`

Environment variables:

- `NEPA_API_BASE`: defaults to `https://nepa.com/agent/v1`; set to the intended Nepa API URL if different.
- `NEPA_MCP_SHARED_SECRET`: optional shared secret matching the API's `NEPA_MCP_SHARED_SECRET` constant. Configure directly in Render, never commit it.
- `API_TIMEOUT_MS`: optional request timeout, defaults to `10000`.
- `TRUST_PROXY`: number of reverse proxies in front of the server, defaults to `1` (Render). Needed so the 100-requests-per-minute limit is per real client rather than shared by everyone behind Render's proxy. Set `0` when running without a proxy.
- `PORT`: supplied by Render.

The MCP endpoint is `https://<your-render-host>/mcp` (CORS enabled for browser-based clients). The server also exposes `/tools`, `/health` (this process only - use it as Render's health check) and `/health?deep=1` (also checks that the Nepa REST API answers; use it for uptime monitoring).

## Version 1.1.0 (2026-10)

- New tool `list_localized_pages` (Swedish, Finnish and Danish pages with English equivalents; optional `language`, `section`). Requires Nepa Agent API plugin v1.2.0, which serves `/agent/v1/localized-pages.json`.
- `list_blog_posts` accepts `language` (`en`|`sv`); `list_press_releases` accepts `language` and `category`.
- `submit_contact_inquiry` accepts an optional `idempotency_key`; field lengths are limited (name 200, email 254, company 200, service interest 200, summary 5,000 characters).
- Tools carry MCP annotations: 17 are read-only; `submit_contact_inquiry` is a non-destructive, idempotent write.
- Rate limiting trusts one proxy hop (`TRUST_PROXY`); session cleanup also runs when a client disconnects early.
- Dependencies updated with `npm audit fix` (MCP SDK 1.32.1, proxy-addr 2.0.8, qs 6.16.1 and others): `npm audit` reports 0 vulnerabilities as of 2026-10-16.
- 18 tools in total. `node_modules/` is not shipped; Render's `npm ci` installs it from `package-lock.json`.

## Version 1.2.0 (2026-10) - HubSpot form delivery

`submit_contact_inquiry` now matches Nepa's HubSpot contact form, where every inquiry is forwarded by the WordPress plugin (1.3.0).

**Inputs:**
- `first_name`, `email` (business address), `phone`, `company` and `message` are required.
- `last_name`, `service_interest`, `language` and `idempotency_key` are optional.
- Three separate consent and authorization inputs:
  - `user_authorized_submission: true` (required);
  - `consent_to_process: true` (required, for the exact statement "I agree to allow Nepa to store and process my personal data.");
  - `marketing_opt_in` (default `false`; `true` only on the person's explicit request).
- Full names are never split.

**Results.** The first line of the result states the real outcome:

| Outcome | Meaning |
|---|---|
| `DELIVERED` | Confirmed in HubSpot |
| `SAVED, DELIVERY PENDING` / `RETRYING` / `UNCONFIRMED` | Saved by Nepa; delivery is still in progress |
| `SAVED, NEEDS NEPA REVIEW` | Saved; Nepa's team must act on the HubSpot delivery |
| `REJECTED` | Error. HubSpot refused the details, for example a webmail address. |
| `IN PROGRESS` | Error. An identical request is still being processed. |
| `NOT SAVED (temporary)` | Error. Nothing was recorded; retry later. |
| `Outcome unknown` | Error. Retry with the same details. |

`ALREADY SUBMITTED` is prefixed when a repeat matched an earlier submission. No credentials for HubSpot live on this server.

**Settings:**
- The contact call waits up to `CONTACT_TIMEOUT_MS` (default 30000) because WordPress makes its first HubSpot attempt inside the request.
- The request body now sends `consent`, `person.first_name`/`last_name`/`phone`, `inquiry.message`/`language`, and `agent_context.via = "mcp"`.
