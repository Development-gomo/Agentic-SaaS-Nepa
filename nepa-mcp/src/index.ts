import express from "express";
import rateLimit from "express-rate-limit";
import { z, type ZodRawShape } from "zod";
import { McpServer, type ToolCallback } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";

const API_BASE = process.env.NEPA_API_BASE ?? "https://nepa.com/agent/v1";
const PORT = parseInt(process.env.PORT ?? "3000", 10);
const API_TIMEOUT = parseInt(process.env.API_TIMEOUT_MS ?? "10000", 10);
// submit_contact_inquiry waits while WordPress stores the lead, makes its first HubSpot
// attempt (up to 10 s) and sends the notification, so it gets a longer limit.
const CONTACT_TIMEOUT = Math.max(API_TIMEOUT, parseInt(process.env.CONTACT_TIMEOUT_MS ?? "30000", 10));
// Identifies this server to nepa-api.php's contact endpoint as a trusted relay, so its
// submissions get a shared, higher-ceiling rate-limit bucket instead of being lumped in
// with direct public traffic under this server's one outbound IP (see is_rate_limited()
// in nepa-api.php). Must match the NEPA_MCP_SHARED_SECRET constant set there; if unset
// here, the header is simply omitted and requests fall back to ordinary IP-based limits.
const MCP_SHARED_SECRET = process.env.NEPA_MCP_SHARED_SECRET;
const SERVER_VERSION = "1.2.0";
// Number of reverse proxies in front of this server (Render has one). Needed so the rate
// limiter keys on the real client IP from X-Forwarded-For instead of the proxy's address,
// which every user would otherwise share. Set TRUST_PROXY=0 when running without a proxy.
const TRUST_PROXY = parseInt(process.env.TRUST_PROXY ?? "1", 10);

/** MCP tool annotations: every tool except submit_contact_inquiry only reads public data. */
const READ_ONLY = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false } as const;

function readTool<Args extends ZodRawShape>(server: McpServer, name: string, description: string, schema: Args, cb: ToolCallback<Args>) {
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
async function fetchJsonWithTimeout(url: string, options: RequestInit = {}, timeoutMs = API_TIMEOUT): Promise<{ ok: boolean; status: number; data: any }> {
  const controller = new AbortController();
  const id = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const res = await fetch(url, { ...options, signal: controller.signal });
    const data = await res.json();
    return { ok: res.ok, status: res.status, data };
  } finally {
    clearTimeout(id);
  }
}

function ok(data: unknown) {
  return { content: [{ type: "text" as const, text: JSON.stringify(data, null, 2) }] };
}

function errResult(message: string) {
  return { content: [{ type: "text" as const, text: JSON.stringify({ error: message }, null, 2) }], isError: true };
}

async function getJson(path: string, query?: Record<string, string | undefined>) {
  const url = new URL(`${API_BASE}${path}`);
  if (query) {
    for (const [k, v] of Object.entries(query)) {
      if (v !== undefined && v !== "") url.searchParams.set(k, v);
    }
  }
  const { ok, status, data } = await fetchJsonWithTimeout(url.toString());
  if (!ok) {
    const message = typeof data?.message === "string" ? data.message : JSON.stringify(data);
    throw new Error(`Nepa API responded ${status} for ${url.pathname}: ${message}`);
  }
  return data;
}


/**
 * Turns the /contact.json answer into a result whose first line states the real outcome,
 * so an agent never reports "sent to HubSpot" when the inquiry was only saved, or success
 * when it was rejected.
 */
export function contactResult(httpStatus: number, data: any) {
  const hubspot = data?.hubspot?.status as string | undefined;
  const outcome: Record<string, string> = {
    delivered: "DELIVERED: saved by Nepa and confirmed in Nepa's HubSpot contact form.",
    pending: "SAVED, DELIVERY PENDING: saved by Nepa; forwarding to HubSpot is pending and retried automatically.",
    sending: "SAVED, DELIVERY IN PROGRESS: saved by Nepa; forwarding to HubSpot is in progress.",
    retryable: "SAVED, DELIVERY RETRYING: saved by Nepa; HubSpot failed temporarily and delivery is retried automatically.",
    uncertain: "SAVED, DELIVERY UNCONFIRMED: saved by Nepa; whether HubSpot received it is being checked before any resend.",
    manual_review: "SAVED, NEEDS NEPA REVIEW: saved by Nepa; HubSpot delivery needs attention from Nepa's team, who have the inquiry.",
    disabled: "SAVED: saved by Nepa; forwarding to HubSpot is not enabled on Nepa's server.",
  };
  const text = (line: string) => `${line}\n\n${JSON.stringify(data, null, 2)}`;
  if (httpStatus === 200 && data?.status === "received") {
    const first = outcome[hubspot ?? ""] ?? "SAVED: saved by Nepa.";
    return ok({ outcome: (data.duplicate ? "ALREADY SUBMITTED (nothing new created). " : "") + first, ...data });
  }
  if (httpStatus === 422 && data?.status === "rejected") {
    return { content: [{ type: "text" as const, text: text("REJECTED by Nepa's HubSpot form: not forwarded. Fix the fields listed in hubspot.errors (e.g. use a business email) and submit again with a new idempotency_key.") }], isError: true };
  }
  if (httpStatus === 409 && data?.error === "in_progress") {
    return { content: [{ type: "text" as const, text: text("IN PROGRESS: an identical submission is being processed. Wait a few seconds and retry with the same details.") }], isError: true };
  }
  if (httpStatus === 429 || httpStatus === 503) {
    return { content: [{ type: "text" as const, text: text("NOT SAVED (temporary): nothing was recorded. Retry later with the same details.") }], isError: true };
  }
  return { content: [{ type: "text" as const, text: text(`NOT SAVED: the request was refused (HTTP ${httpStatus}). Fix the problem described below; nothing was recorded.`) }], isError: true };
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
  { name: "submit_contact_inquiry", phase: 2, method: "POST", description: "Submit a B2B inquiry to Nepa on behalf of a person (saved by Nepa and forwarded to Nepa's HubSpot contact form) - requires explicit authorization and consent to processing; marketing opt-in is separate and off by default." },
];

function buildServer(): McpServer {
  const server = new McpServer({ name: "nepa-mcp", version: SERVER_VERSION });

  readTool(server, "get_company", "Get Nepa's company profile.", {}, async () => {
    try {
      return ok(await getJson("/company.json"));
    } catch (e) {
      return errResult(String(e));
    }
  });

  readTool(
    server,
    "list_products",
    "List Nepa's products, optionally filtered by category.",
    { category: z.string().optional() },
    async ({ category }) => {
      try {
        return ok(await getJson("/products.json", { category }));
      } catch (e) {
        return errResult(String(e));
      }
    }
  );

  readTool(server, "list_solutions", "List Nepa's solutions.", {}, async () => {
    try {
      return ok(await getJson("/solutions.json"));
    } catch (e) {
      return errResult(String(e));
    }
  });

  readTool(server, "list_industries", "List industries Nepa serves.", {}, async () => {
    try {
      return ok(await getJson("/industries.json"));
    } catch (e) {
      return errResult(String(e));
    }
  });

  readTool(
    server,
    "list_case_studies",
    "List Nepa client case studies, optionally filtered by industry, segment, or service.",
    { industry: z.string().optional(), segment: z.string().optional(), service: z.string().optional() },
    async ({ industry, segment, service }) => {
      try {
        return ok(await getJson("/case-studies.json", { industry, segment, service }));
      } catch (e) {
        return errResult(String(e));
      }
    }
  );

  readTool(
    server,
    "get_case_study",
    "Get one Nepa case study by id (e.g. 'case-gant-trusts-nepa-to-help-it-grow').",
    { id: z.string() },
    async ({ id }) => {
      try {
        const data: any = await getJson("/case-studies.json");
        const match = (data.case_studies ?? []).find((c: any) => c.id === id);
        if (!match) return errResult(`No case study found with id '${id}'`);
        return ok(match);
      } catch (e) {
        return errResult(String(e));
      }
    }
  );

  readTool(server, "list_team", "List Nepa's leadership team.", {}, async () => {
    try {
      return ok(await getJson("/team.json"));
    } catch (e) {
      return errResult(String(e));
    }
  });

  readTool(server, "list_offices", "List Nepa's office locations.", {}, async () => {
    try {
      return ok(await getJson("/offices.json"));
    } catch (e) {
      return errResult(String(e));
    }
  });

  readTool(server, "list_jobs", "List Nepa's open roles.", {}, async () => {
    try {
      return ok(await getJson("/jobs.json"));
    } catch (e) {
      return errResult(String(e));
    }
  });

  readTool(server, "list_events", "List Nepa's upcoming and past events/webinars.", {}, async () => {
    try {
      return ok(await getJson("/events.json"));
    } catch (e) {
      return errResult(String(e));
    }
  });

  readTool(server, "list_company_news", "List client-win and partnership announcements.", {}, async () => {
    try {
      return ok(await getJson("/company-news.json"));
    } catch (e) {
      return errResult(String(e));
    }
  });

  readTool(server, "list_resources", "List gated thought-leadership reports and positioning/campaign landing pages.", {}, async () => {
    try {
      return ok(await getJson("/resources.json"));
    } catch (e) {
      return errResult(String(e));
    }
  });

  readTool(server, "list_pricing", "List published pricing plans.", {}, async () => {
    try {
      return ok(await getJson("/pricing.json"));
    } catch (e) {
      return errResult(String(e));
    }
  });

  readTool(
    server,
    "list_blog_posts",
    "List Nepa's blog/insight posts (English and Swedish), paginated. Pass language 'en' or 'sv' to filter.",
    {
      limit: z.number().int().min(1).max(200).optional(),
      offset: z.number().int().min(0).optional(),
      language: z.enum(["en", "sv", "fi", "da", "no"]).optional(),
    },
    async ({ limit, offset, language }) => {
      try {
        return ok(await getJson("/blog-posts.json", { limit: limit?.toString(), offset: offset?.toString(), language }));
      } catch (e) {
        return errResult(String(e));
      }
    }
  );

  readTool(
    server,
    "list_press_releases",
    "List Nepa's investor/press releases (English and Swedish), paginated. Pass language 'en' or 'sv', and/or an exact category such as 'Investor Relations', to filter.",
    {
      limit: z.number().int().min(1).max(200).optional(),
      offset: z.number().int().min(0).optional(),
      language: z.enum(["en", "sv", "fi", "da", "no"]).optional(),
      category: z.string().optional(),
    },
    async ({ limit, offset, language, category }) => {
      try {
        return ok(await getJson("/press-releases.json", { limit: limit?.toString(), offset: offset?.toString(), language, category }));
      } catch (e) {
        return errResult(String(e));
      }
    }
  );

  readTool(
    server,
    "list_localized_pages",
    "List Nepa's Swedish (sv), Finnish (fi) and Danish (da) pages with their English equivalents. Optionally filter by language and by section (e.g. case-study, product, solution, blog, contact).",
    { language: z.enum(["sv", "fi", "da"]).optional(), section: z.string().optional() },
    async ({ language, section }) => {
      try {
        return ok(await getJson("/localized-pages.json", { language, section }));
      } catch (e) {
        return errResult(String(e));
      }
    }
  );

  readTool(
    server,
    "get_investor_relations",
    "Get Nepa's investor relations summary. Informational only — not investment advice.",
    {},
    async () => {
      try {
        return ok(await getJson("/investor-relations.json"));
      } catch (e) {
        return errResult(String(e));
      }
    }
  );

  server.tool(
    "submit_contact_inquiry",
    [
      "Submit a B2B inquiry to Nepa on behalf of a person. It is saved by Nepa and forwarded to Nepa's English contact form in HubSpot.",
      "Before calling, you MUST have, from the person in this conversation: (1) their explicit authorization to send this inquiry (user_authorized_submission: true);",
      "(2) their agreement to this exact statement: \"I agree to allow Nepa to store and process my personal data.\" (consent_to_process: true);",
      "(3) a separate, optional marketing choice. Show them: \"Nepa may occasionally share updates on products, services, events, and exclusive invitations to webinars. You can unsubscribe anytime by clicking the link at the bottom of any email. For details see https://nepa.com/privacy-policy/.\"",
      "Set marketing_opt_in: true ONLY if they explicitly ask to receive these updates; otherwise leave it false. Nepa's form records this choice with the checkbox label \"I have read and understood Nepa's Privacy Policy\".",
      "Required: first_name, email (a business address - free webmail is rejected), phone, company, message. Ask for last_name separately; never split a full name yourself.",
      "The result states whether the inquiry was confirmed in HubSpot, is pending/being retried, needs Nepa's review, or was rejected (fix the listed fields and resubmit with a new idempotency_key).",
      "If the call times out or fails, retry with exactly the same details (and the same idempotency_key if you set one): Nepa recognises the repeat and never creates a second inquiry.",
    ].join(" "),
    {
      user_authorized_submission: z.literal(true),
      consent_to_process: z.literal(true).describe("The person agreed: \"I agree to allow Nepa to store and process my personal data.\""),
      marketing_opt_in: z.boolean().default(false).describe("true only if the person explicitly asked to receive Nepa's marketing updates."),
      first_name: z.string().min(1).max(100),
      last_name: z.string().max(100).optional(),
      email: z.string().email().max(254),
      phone: z.string().min(7).max(40).describe("Phone number with 7-20 digits; include the country code."),
      company: z.string().min(1).max(200),
      message: z.string().min(1).max(5000).describe("What the person wants to discuss with Nepa."),
      service_interest: z.string().max(200).optional(),
      language: z.string().max(35).optional().describe("The person's preferred language, e.g. \"sv\" or \"Swedish\"; Nepa's English form is used for every language."),
      idempotency_key: z
        .string()
        .regex(/^[A-Za-z0-9._:-]{8,100}$/)
        .optional()
        .describe("Optional. Reuse the same value when retrying the same submission so Nepa records it only once."),
    },
    { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: true },
    async (args) => {
      const body = {
        agent_context: {
          user_authorized_submission: args.user_authorized_submission,
          via: "mcp",
          ...(args.idempotency_key ? { idempotency_key: args.idempotency_key } : {}),
        },
        consent: { process: args.consent_to_process, marketing_opt_in: args.marketing_opt_in === true },
        person: { first_name: args.first_name, last_name: args.last_name, email: args.email, phone: args.phone },
        company: { name: args.company },
        inquiry: { message: args.message, service_interest: args.service_interest, language: args.language },
      };
      try {
        const { status, data } = await fetchJsonWithTimeout(`${API_BASE}/contact.json`, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            ...(MCP_SHARED_SECRET ? { "X-Nepa-Agent-Key": MCP_SHARED_SECRET } : {}),
          },
          body: JSON.stringify(body),
        }, CONTACT_TIMEOUT);
        return contactResult(status, data);
      } catch (e) {
        return errResult(
          `Outcome unknown: no answer from Nepa (${String(e)}). The inquiry may or may not have been saved. ` +
            "Retry with exactly the same details (and the same idempotency_key, if any) - a repeat is recognised and never creates a second inquiry."
        );
      }
    }
  );

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
    if (closed) return;
    closed = true;
    transport.close().catch(() => {});
    server.close().catch(() => {});
  });
  try {
    await server.connect(transport);
    await transport.handleRequest(req, res, req.body);
  } catch (err) {
    console.error("MCP handler error:", err);
    if (!res.headersSent) res.status(500).json({ error: "Internal MCP server error" });
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
  } catch (e) {
    res.status(503).json({ status: "degraded", ...base, api: { reachable: false, error: String(e) } });
  }
});

app.get("/tools", (_req, res) => {
  res.json({ tools: TOOLS });
});

app.listen(PORT, () => {
  console.log(`nepa-mcp listening on port ${PORT}, proxying ${API_BASE}`);
});
