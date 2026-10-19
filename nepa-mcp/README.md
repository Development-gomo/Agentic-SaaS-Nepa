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
