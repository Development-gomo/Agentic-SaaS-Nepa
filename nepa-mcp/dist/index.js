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
    { name: "list_blog_posts", phase: 1, method: "GET", description: "List Nepa's blog/insight posts, paginated." },
    { name: "list_press_releases", phase: 1, method: "GET", description: "List Nepa's investor/press releases, paginated." },
    { name: "get_investor_relations", phase: 1, method: "GET", description: "Get Nepa's investor relations summary — financial reports, governance, shareholder meetings. Not investment advice." },
    { name: "submit_contact_inquiry", phase: 2, method: "POST", description: "Submit a qualified B2B inquiry to Nepa on behalf of a human — requires explicit authorization." },
];
function buildServer() {
    const server = new McpServer({ name: "nepa-mcp", version: "1.0.0" });
    server.tool("get_company", "Get Nepa's company profile.", {}, async () => {
        try {
            return ok(await getJson("/company.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    server.tool("list_products", "List Nepa's products, optionally filtered by category.", { category: z.string().optional() }, async ({ category }) => {
        try {
            return ok(await getJson("/products.json", { category }));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    server.tool("list_solutions", "List Nepa's solutions.", {}, async () => {
        try {
            return ok(await getJson("/solutions.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    server.tool("list_industries", "List industries Nepa serves.", {}, async () => {
        try {
            return ok(await getJson("/industries.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    server.tool("list_case_studies", "List Nepa client case studies, optionally filtered by industry, segment, or service.", { industry: z.string().optional(), segment: z.string().optional(), service: z.string().optional() }, async ({ industry, segment, service }) => {
        try {
            return ok(await getJson("/case-studies.json", { industry, segment, service }));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    server.tool("get_case_study", "Get one Nepa case study by id (e.g. 'case-gant-trusts-nepa-to-help-it-grow').", { id: z.string() }, async ({ id }) => {
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
    server.tool("list_team", "List Nepa's leadership team.", {}, async () => {
        try {
            return ok(await getJson("/team.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    server.tool("list_offices", "List Nepa's office locations.", {}, async () => {
        try {
            return ok(await getJson("/offices.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    server.tool("list_jobs", "List Nepa's open roles.", {}, async () => {
        try {
            return ok(await getJson("/jobs.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    server.tool("list_events", "List Nepa's upcoming and past events/webinars.", {}, async () => {
        try {
            return ok(await getJson("/events.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    server.tool("list_company_news", "List client-win and partnership announcements.", {}, async () => {
        try {
            return ok(await getJson("/company-news.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    server.tool("list_resources", "List gated thought-leadership reports and positioning/campaign landing pages.", {}, async () => {
        try {
            return ok(await getJson("/resources.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    server.tool("list_pricing", "List published pricing plans.", {}, async () => {
        try {
            return ok(await getJson("/pricing.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    server.tool("list_blog_posts", "List Nepa's blog/insight posts, paginated.", { limit: z.number().int().min(1).max(200).optional(), offset: z.number().int().min(0).optional() }, async ({ limit, offset }) => {
        try {
            return ok(await getJson("/blog-posts.json", { limit: limit?.toString(), offset: offset?.toString() }));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    server.tool("list_press_releases", "List Nepa's investor/press releases, paginated.", { limit: z.number().int().min(1).max(200).optional(), offset: z.number().int().min(0).optional() }, async ({ limit, offset }) => {
        try {
            return ok(await getJson("/press-releases.json", { limit: limit?.toString(), offset: offset?.toString() }));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    server.tool("get_investor_relations", "Get Nepa's investor relations summary. Informational only — not investment advice.", {}, async () => {
        try {
            return ok(await getJson("/investor-relations.json"));
        }
        catch (e) {
            return errResult(String(e));
        }
    });
    server.tool("submit_contact_inquiry", "Submit a qualified B2B inquiry to Nepa on behalf of a human. You MUST set user_authorized_submission to true to confirm that consent was explicitly given by the person you are acting for — do not call this speculatively.", {
        user_authorized_submission: z.literal(true),
        name: z.string(),
        email: z.string().email(),
        company: z.string().optional(),
        service_interest: z.string().optional(),
        project_summary: z.string().optional(),
    }, async ({ user_authorized_submission, name, email, company, service_interest, project_summary }) => {
        try {
            const { ok: success, data } = await fetchJsonWithTimeout(`${API_BASE}/contact.json`, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    ...(MCP_SHARED_SECRET ? { "X-Nepa-Agent-Key": MCP_SHARED_SECRET } : {}),
                },
                body: JSON.stringify({
                    agent_context: { user_authorized_submission },
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
app.use(express.json());
const limiter = rateLimit({ windowMs: 60 * 1000, max: 100, standardHeaders: true, legacyHeaders: false });
app.use("/mcp", limiter);
app.all("/mcp", async (req, res) => {
    const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined });
    const server = buildServer();
    res.on("finish", () => {
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
app.get("/health", (_req, res) => {
    res.json({ status: "ok", name: "nepa-mcp", version: "1.0.0", api_base: API_BASE });
});
app.get("/tools", (_req, res) => {
    res.json({ tools: TOOLS });
});
app.listen(PORT, () => {
    console.log(`nepa-mcp listening on port ${PORT}, proxying ${API_BASE}`);
});
