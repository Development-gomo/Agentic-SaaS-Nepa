import express from "express";
import rateLimit from "express-rate-limit";
import { z } from "zod";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";
const API_BASE = process.env.NEPA_API_BASE ?? "https://nepa.com/agent/v1";
const PORT = parseInt(process.env.PORT ?? "3000", 10);
const API_TIMEOUT = parseInt(process.env.API_TIMEOUT_MS ?? "10000", 10);
// Identifies this server to nepa-api.php's contact endpoint as a trusted relay, so its
// submissions get a shared, higher-ceiling rate-limit bucket instead of being lumped in
// with direct public traffic under this server's one outbound IP (see is_rate_limited()
// in nepa-api.php). Must match the NEPA_MCP_SHARED_SECRET constant set there; if unset
// here, the header is simply omitted and requests fall back to ordinary IP-based limits.
const MCP_SHARED_SECRET = process.env.NEPA_MCP_SHARED_SECRET;
const SERVER_VERSION = "1.1.0";
// Number of reverse proxies in front of this server (Render has one). Needed so the rate
// limiter keys on the real client IP from X-Forwarded-For instead of the proxy's address,
// which every user would otherwise share. Set TRUST_PROXY=0 when running without a proxy.
const TRUST_PROXY = parseInt(process.env.TRUST_PROXY ?? "1", 10);
/** MCP tool annotations: every tool except submit_contact_inquiry only reads public data. */
const READ_ONLY = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false };
function readTool(server, name, description, schema, cb) {
    return server.tool(name, description, schema, READ_ONLY, cb);
}
/**
 * Fetches and fully reads the JSON body within a single timeout-guarded scope.
 *
 * The previous version cleared the abort timer as soon as `fetch()` resolved - but the
 * Fetch API resolves `fetch()` once response *headers* arrive, before the body has been
 * read. Reading the body (`res.json()`) happened afterward, outside the timer's
 * protection, so a connection that stalled mid-body-transfer could hang indefinitely
 * regardless of API_TIMEOUT_MS. Keeping `res.json()` inside the try block, before
 * `clearTimeout` runs in `finally`, means the same AbortSignal still covers the read and
 * will actually abort a stalled body transfer too.
 */
async function fetchJsonWithTimeout(url, options = {}, timeoutMs = API_TIMEOUT) {
    const controller = new AbortController();
    const id = setTimeout(() => controller.abort(), timeoutMs);
    try {
        const res = await fetch(url, { ...options, signal: controller.signal });
        const data = await res.json();
        return { ok: res.ok, status: res.status, data };
    }
    finally {
        clearTimeout(id);
    }
}
function ok(data) {
    return { content: [{ type: "text", text: JSON.stringify(data, null, 2) }] };
}
function errResult(message) {
    return { content: [{ type: "text", text: JSON.stringify({ error: message }, null, 2) }], isError: true };
}
async function getJson(path, query) {
    const url = new URL(`${API_BASE}${path}`);
    if (query) {
        for (const [k, v] of Object.entries(query)) {
            if (v !== undefined && v !== "")
                url.searchParams.set(k, v);
        }
    }
    const { ok, status, data } = await fetchJsonWithTimeout(url.toString());
    if (!ok) {
        const message = typeof data?.message === "string" ? data.message : JSON.stringify(data);
        throw new Error(`Nepa API responded ${status} for ${url.pathname}: ${message}`);
    }
    return data;
}
const TOOLS = [
    { name: "get_company", phase: 1, method: "GET", description: "Get Nepa's company profile — overview, values, leadership summary, contact details." },
    { name: "list_products", phase: 1, method: "GET", description: "List Nepa's products (brand tracking, marketing mix modelling, campaign evaluation, etc.), optionally filtered by category." },
    { name: "list_solutions", phase: 1, method: "GET", description: "List Nepa's solutions, organized by business need (brand development, campaign measurement, media strategy, etc.)." },
    { name: "list_industries", phase: 1, method: "GET", description: "List industries Nepa serves." },
    { name: "list_case_studies", phase: 1, method: "GET", description: "List Nepa client case studies, optionally filtered by industry, segment, or service." },
    { name: "get_case_study", phase: 1, method: "GET", description: "Get one case study by id." },
    { name: "list_team", phase: 1, method: "GET", description: "List Nepa's leadership team." },
    { name: "list_offices", phase: 1, method: "GET", description: "List Nepa's office locations." },
    { name: "list_jobs", phase: 1, method: "GET", description: "List Nepa's open roles." },
    { name: "list_events", phase: 1, method: "GET", description: "List Nepa's upcoming and past events/webinars." },
    { name: "list_company_news", phase: 1, method: "GET", description: "List client-win and partnership announcements (distinct from investor press releases and case studies)." },
    { name: "list_resources", phase: 1, method: "GET", description: "List gated thought-leadership reports and positioning/campaign landing pages." },
    { name: "list_pricing", phase: 1, method: "GET", description: "List published pricing plans." },
    { name: "list_blog_posts", phase: 1, method: "GET", description: "List Nepa's blog/insight posts (English and Swedish), paginated, optionally filtered by language." },
    { name: "list_press_releases", phase: 1, method: "GET", description: "List Nepa's investor/press releases (English and Swedish), paginated, optionally filtered by language or category." },
    { name: "list_localized_pages", phase: 1, method: "GET", description: "List Nepa's Swedish, Finnish and Danish pages with their English equivalents, optionally filtered by language or section." },
    { name: "get_investor_relations", phase: 1, method: "GET", description: "Get Nepa's investor relations summary — financial reports, governance, shareholder meetings. Not investment advice." },
    { name: "submit_contact_inquiry", phase: 2, method: "POST", description: "Submit a qualified B2B inquiry to Nepa on behalf of a human — requires explicit authorization." },
];
function buildServer() {
    const server = new McpServer({ name: "nepa-mcp", version: SERVER_VERSION });
    readTool(server, "get_company", "Get Nepa's company profile.", {}, async () => {
        try {
            return ok(await getJson("/company.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    readTool(server, "list_products", "List Nepa's products, optionally filtered by category.", { category: z.string().optional() }, async ({ category }) => {
        try {
            return ok(await getJson("/products.json", { category }));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    readTool(server, "list_solutions", "List Nepa's solutions.", {}, async () => {
        try {
            return ok(await getJson("/solutions.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    readTool(server, "list_industries", "List industries Nepa serves.", {}, async () => {
        try {
            return ok(await getJson("/industries.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    readTool(server, "list_case_studies", "List Nepa client case studies, optionally filtered by industry, segment, or service.", { industry: z.string().optional(), segment: z.string().optional(), service: z.string().optional() }, async ({ industry, segment, service }) => {
        try {
            return ok(await getJson("/case-studies.json", { industry, segment, service }));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    readTool(server, "get_case_study", "Get one Nepa case study by id (e.g. 'case-gant-trusts-nepa-to-help-it-grow').", { id: z.string() }, async ({ id }) => {
        try {
            const data = await getJson("/case-studies.json");
            const match = (data.case_studies ?? []).find((c) => c.id === id);
            if (!match)
                return errResult(`No case study found with id '${id}'`);
            return ok(match);
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    readTool(server, "list_team", "List Nepa's leadership team.", {}, async () => {
        try {
            return ok(await getJson("/team.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    readTool(server, "list_offices", "List Nepa's office locations.", {}, async () => {
        try {
            return ok(await getJson("/offices.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    readTool(server, "list_jobs", "List Nepa's open roles.", {}, async () => {
        try {
            return ok(await getJson("/jobs.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    readTool(server, "list_events", "List Nepa's upcoming and past events/webinars.", {}, async () => {
        try {
            return ok(await getJson("/events.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    readTool(server, "list_company_news", "List client-win and partnership announcements.", {}, async () => {
        try {
            return ok(await getJson("/company-news.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    readTool(server, "list_resources", "List gated thought-leadership reports and positioning/campaign landing pages.", {}, async () => {
        try {
            return ok(await getJson("/resources.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    readTool(server, "list_pricing", "List published pricing plans.", {}, async () => {
        try {
            return ok(await getJson("/pricing.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    readTool(server, "list_blog_posts", "List Nepa's blog/insight posts (English and Swedish), paginated. Pass language 'en' or 'sv' to filter.", {
        limit: z.number().int().min(1).max(200).optional(),
        offset: z.number().int().min(0).optional(),
        language: z.enum(["en", "sv", "fi", "da", "no"]).optional(),
    }, async ({ limit, offset, language }) => {
        try {
            return ok(await getJson("/blog-posts.json", { limit: limit?.toString(), offset: offset?.toString(), language }));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    readTool(server, "list_press_releases", "List Nepa's investor/press releases (English and Swedish), paginated. Pass language 'en' or 'sv', and/or an exact category such as 'Investor Relations', to filter.", {
        limit: z.number().int().min(1).max(200).optional(),
        offset: z.number().int().min(0).optional(),
        language: z.enum(["en", "sv", "fi", "da", "no"]).optional(),
        category: z.string().optional(),
    }, async ({ limit, offset, language, category }) => {
        try {
            return ok(await getJson("/press-releases.json", { limit: limit?.toString(), offset: offset?.toString(), language, category }));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    readTool(server, "list_localized_pages", "List Nepa's Swedish (sv), Finnish (fi) and Danish (da) pages with their English equivalents. Optionally filter by language and by section (e.g. case-study, product, solution, blog, contact).", { language: z.enum(["sv", "fi", "da"]).optional(), section: z.string().optional() }, async ({ language, section }) => {
        try {
            return ok(await getJson("/localized-pages.json", { language, section }));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    readTool(server, "get_investor_relations", "Get Nepa's investor relations summary. Informational only — not investment advice.", {}, async () => {
        try {
            return ok(await getJson("/investor-relations.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    server.tool("submit_contact_inquiry", "Submit a qualified B2B inquiry to Nepa on behalf of a human. You MUST set user_authorized_submission to true to confirm that consent was explicitly given by the person you are acting for — do not call this speculatively. If the call times out, retry with the same idempotency_key (an identical retry within 30 minutes is also recognised); the response then says duplicate: true instead of creating a second inquiry.", {
        user_authorized_submission: z.literal(true),
        name: z.string().min(1).max(200),
        email: z.string().email().max(254),
        company: z.string().max(200).optional(),
        service_interest: z.string().max(200).optional(),
        project_summary: z.string().max(5000).optional(),
        idempotency_key: z
            .string()
            .regex(/^[A-Za-z0-9._:-]{8,100}$/)
            .optional()
            .describe("Optional. Reuse the same value when retrying the same submission so Nepa records it only once."),
    }, { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true }, async ({ user_authorized_submission, name, email, company, service_interest, project_summary, idempotency_key }) => {
        try {
            const { ok: success, data } = await fetchJsonWithTimeout(`${API_BASE}/contact.json`, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    ...(MCP_SHARED_SECRET ? { "X-Nepa-Agent-Key": MCP_SHARED_SECRET } : {}),
                },
                body: JSON.stringify({
                    agent_context: { user_authorized_submission, ...(idempotency_key ? { idempotency_key } : {}) },
                    person: { name, email },
                    company: { name: company },
                    inquiry: { service_interest, project_summary },
                }),
            });
            if (!success)
                return errResult(JSON.stringify(data));
            return ok(data);
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    return server;
}
const app = express();
app.set("trust proxy", TRUST_PROXY);
app.use(express.json({ limit: "64kb" }));
// CORS for browser-based MCP clients. The server is stateless and holds no cookies or
// credentials, so allowing any origin exposes nothing beyond the public tools.
app.use("/mcp", (req, res, next) => {
    res.setHeader("Access-Control-Allow-Origin", "*");
    res.setHeader("Access-Control-Allow-Methods", "GET, POST, DELETE, OPTIONS");
    res.setHeader("Access-Control-Allow-Headers", "Content-Type, Accept, Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID");
    res.setHeader("Access-Control-Expose-Headers", "Mcp-Session-Id");
    if (req.method === "OPTIONS") {
        res.status(204).end();
        return;
    }
    next();
});
const limiter = rateLimit({ windowMs: 60 * 1000, max: 100, standardHeaders: true, legacyHeaders: false });
app.use("/mcp", limiter);
app.all("/mcp", async (req, res) => {
    const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined });
    const server = buildServer();
    // "close" fires both after a normal response and when the client disconnects early.
    let closed = false;
    res.on("close", () => {
        if (closed)
            return;
        closed = true;
        transport.close().catch(() => { });
        server.close().catch(() => { });
    });
    try {
        await server.connect(transport);
        await transport.handleRequest(req, res, req.body);
    }
    catch (err) {
        console.error("MCP handler error:", err);
        if (!res.headersSent)
            res.status(500).json({ error: "Internal MCP server error" });
    }
});
// /health answers for this process only (cheap, for Render's health check).
// /health?deep=1 also checks that the Nepa REST API answers with a readable index.json.
app.get("/health", async (req, res) => {
    const base = { name: "nepa-mcp", version: SERVER_VERSION, api_base: API_BASE };
    if (req.query.deep !== "1") {
        res.json({ status: "ok", ...base });
        return;
    }
    try {
        const { ok: up, status, data } = await fetchJsonWithTimeout(`${API_BASE}/index.json`);
        if (!up || typeof data?.version !== "string") {
            res.status(503).json({ status: "degraded", ...base, api: { reachable: up, http_status: status } });
            return;
        }
        res.json({ status: "ok", ...base, api: { reachable: true, version: data.version } });
    }
    catch (e) {
        res.status(503).json({ status: "degraded", ...base, api: { reachable: false, error: String(e) } });
    }
});
app.get("/tools", (_req, res) => {
    res.json({ tools: TOOLS });
});
app.listen(PORT, () => {
    console.log(`nepa-mcp listening on port ${PORT}, proxying ${API_BASE}`);
});
