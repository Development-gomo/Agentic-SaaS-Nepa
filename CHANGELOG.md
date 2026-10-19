# Changelog — Nepa Agentic Web package

## HubSpot form delivery — 2026-10-19 (plugin 1.3.0, MCP server 1.2.0, OpenAPI 1.2.0)

Agent inquiries are now forwarded to Nepa's HubSpot contact form, after the durable WordPress lead is stored. The full description is in DEPLOYMENT.md §11.

- **API choice.** HubSpot's Forms v3 submission endpoint is used (`/submissions/v3/integration/[secure/]submit/{portal}/{form}`). It is the only API that creates a real form submission; the newer Forms APIs manage form definitions only. Checked against developers.hubspot.com on 2026-10-19.
- **Form choice.** Every inquiry goes to the English form `7c631e31-…` (Nepa's decision). The Swedish, Finnish and Danish forms require the marketing-subscription checkbox, so they are not used. CAPTCHA is off on all four forms.
- **Mapping.** The form's own field names are used. The MCP origin, service interest and preferred language are appended to the message (Nepa's decision: no hidden field). Full names are never split.
- **Consent.** Authorization, processing consent and marketing opt-in are three separate inputs. Processing consent uses the form's exact text. Marketing is off unless explicitly requested. No cookie, IP address or page context is sent.
- **Delivery tracking.** HubSpot delivery is tracked separately from storage and email:
  - states: `pending`, `sending`, `delivered`, `retryable`, `uncertain`, `rejected`, `manual_review`, `disabled`;
  - attempts, timestamps and a sanitized log are kept per lead;
  - retries use bounded backoff and honour HubSpot's rate-limit headers;
  - data errors are not retried, and configuration errors go to manual review with an email;
  - per-lead locks stop overlapping workers.
- **Uncertain outcomes.** These are looked up in HubSpot (with a token) before any resend, or sent to manual review (without one). Exactly-once delivery is not claimed; the remaining risk and recovery are documented.
- **Responses.** `/contact.json` reports `hubspot.status`, and returns 422 `hubspot_rejected` when HubSpot refused the details. A repeat returns the existing lead and its current status without resending.
- **MCP tool.** The result's first line states the real outcome.
- **Admin.** A HubSpot column, a delivery box with the log, "Retry now" and "Mark as delivered" (administrators only, nonce-protected), and a warning banner.
- **Notifications.** The WordPress email is unchanged (Nepa's decision) and now states the HubSpot status. HubSpot's own form notifications also run, so there may be two notifications per inquiry (documented).
- **Settings.** The token is optional and configured in wp-config.php only. Delivery is off until `NEPA_HUBSPOT_ENABLED` is true.
- **Tests.**
  - New `tests/run-hubspot-tests.php`: 52 checks with a token, 48 without.
  - MCP smoke test: 26 checks, including every outcome message.
  - `staging-checks.sh` has a new `hubspot` mode for real acceptance, and its `duplicates` mode was updated to the new request shape.
  - Six injected defects were each caught by the tests.

**What was tested against real systems: nothing yet.** All HubSpot and WordPress behaviour above was tested with mocks. The real staging acceptance (`staging-checks.sh hubspot`) has not been run, because it needs the staging site and HubSpot access.

### HubSpot delivery — fourth-pass review fix (Codex, 2026-10-19)

**Problem.** WordPress keeps each post's meta in a per-request cache, and the database lock did not refresh it. A contact request could hold a stale copy (no HubSpot status yet), let a separate worker request deliver the inquiry, and then take the lock, see no status and submit it again.

**Fix.** Whenever the per-lead lock is taken, this request's cached copy of the lead's meta is dropped, so every status, counter and timestamp decided under the lock comes from the database. The fix sits in the lock function itself, so request setup, the worker and both admin actions all get it.

**Tests.** The stubs now give each simulated request its own meta cache, as WordPress does. New tests C1 (contact request vs. worker) and C2 (stale admin page vs. worker) replay the sequence with separate request caches. Both failed before the fix: C1 produced two submissions. Both pass after it, and removing the refresh makes them fail again.

| Suite | Result |
|---|---|
| Plugin | 67/67 |
| HubSpot, with token | 76/76 |
| HubSpot, without token | 68/68 |
| MCP | 26/26 |
| OpenAPI | all real plugin responses valid |

**Still mocks only.** Live MCP → WordPress → HubSpot verification is required before production.

### HubSpot delivery — third-pass review fixes (Codex, 2026-10-19)

All 3 findings were confirmed and fixed. Each has a test that replays the reported race, and with the original code put back each test fails.

1. **Lead setup raced the worker.** Lead setup and the background worker now share one per-lead lock. The contact request sets the initial status and makes its first attempt under that lock, and never overwrites a status the worker already recorded. The write that reset the attempt counter to 0 after creation is removed.
   - Replay: the worker delivers the new lead mid-request. Result: one submission, and the status stays `delivered` (it was two submissions).
2. **Admin retry rewrote status outside the lock.** Admin retry and "mark as delivered" now read, decide and write entirely under the lock. Retry records the request in the log only, never by rewriting the status, and returns "locked" while a worker is handling the lead.
   - Replay: a worker runs just before the admin's first status write. Result: one submission (it was two).
3. **The lookup counter could fail to save.** If the counter can't be saved, the lead goes to manual review with an email, instead of being looked up without a limit.
   - Replay: with the counter unwritable, one lookup and then manual review (it was 10 lookups, still unconfirmed).

**Tests:**
| Suite | Result |
|---|---|
| Plugin | 67/67 |
| HubSpot, with token | 73/73 |
| HubSpot, without token | 65/65 |
| MCP | 26/26 |
| OpenAPI | all real plugin responses valid |

The test stubs gained two one-shot hooks, which run a worker after a lead is inserted or before a chosen field is written, to replay races.

**Still mocks only.** All "real responses" are the real plugin answering against simulated WordPress and HubSpot. Live MCP → WordPress → HubSpot verification is still required.

### HubSpot delivery — review fixes (Codex, 2026-10-19)

All 8 findings were confirmed and fixed. Each has a regression test that reproduces the reported scenario, and reverting each fix in a throwaway copy makes its test fail.

1. **Waiting leads blocked due ones.** The worker now selects only leads that are due, inside the query itself, before the batch limit is applied. Ten leads waiting for later retries can no longer starve a newer lead that is due.
2. **Submission data was not verified.** The complete submission is now stored in the lead's own database row by the same insert that creates the lead, and HubSpot's payload is built from it. If the lead exists, its full submission data exists; a failed field write can no longer send an empty message. If the stored data can't be read, nothing is sent and the lead goes to manual review.
3. **Leads with no saved state were stuck.** The worker also picks up leads that have no delivery state yet. Leads saved before HubSpot delivery existed are marked `not_applicable` once and never sent.
4. **Failed lookups were unbounded.** Unconfirmed leads now honour their retry time. A lookup refused with 401/403/404 goes straight to manual review. Other lookup failures back off and go to manual review after 8 tries.
5. **Retry counters weren't verified.** The `sending` status, the attempt counter and the retry time must all be stored before any request is made, so the 8-attempt limit can always be enforced.
6. **Admins could resend delivered leads.** Delivered is final. Administrator retries, including repeated clicks or stale pages, are refused under the per-lead lock.
7. **Saved JSON and text could be corrupted.** Every stored value is slashed before saving, because WordPress strips slashes. Delivery logs with quotes stay valid JSON, and backslashes in a person's message are kept. The same fix covers the post title and body; this wider escaping bug was found during this fix.
8. **The OpenAPI 422 schema was ambiguous.** The two response types are now mutually exclusive: error bodies never have `status`, and stored-lead bodies require `status`, `lead_id` and `hubspot`. All 88 real responses from the test suite validate.

**Tests:**
| Suite | Result |
|---|---|
| Plugin | 67/67 |
| HubSpot, with token | 66/66 |
| HubSpot, without token | 59/59 |
| MCP | 26/26 |
| OpenAPI response check | 96/96 |

The test stubs now strip slashes like WordPress does.

**Still tested with mocks only.** A real run through MCP → WordPress → HubSpot has not been done.

## agentic-web-2026-10 — 2026-10-16

Rebuilt from a fresh crawl of the live https://nepa.com on 2026-10-16 and compared with the previous package (folder `Original/`, crawled 2026-09-08, published 2026-09-09).

**Crawl method.** Firecrawl batch scrape of 520 pages (every sitemap URL plus every URL in the previous package, in all languages), except 277 historical press releases whose sitemap `lastmod` predates the last build. Those were checked by direct HTTP request for status, title, date and language. All 808 known URLs were also status-checked for 404s and redirects. One English blog post (*My 3 key takeaways: the National Grocers Association Show 2019*) returned 200 but was skipped by the scraper; it is unchanged in the package.

**Versions.** Data files v1.0 → v1.1 · Nepa Agent API WordPress plugin 1.1.0 → 1.2.0 · OpenAPI spec 1.0.0 → 1.1.0 · MCP server 1.0.0 → 1.1.0.

**Decisions applied.** (1) Swedish press releases are now included, tagged `language: "sv"`. (2) AI training is **allowed**: `ai.txt` keeps `Allow-Training: yes`, robots.txt keeps allowing every listed crawler, and the `Content-Signal` line changes to `ai-train=yes` (Nepa decision, 2026-10-16). (3) Five duplicate/internal pages are excluded (listed below).

### Summary

| | Count |
|---|---|
| Pages added to the package | 254 |
| — newly published on the site since the last build | 8 |
| — already live but not previously covered | 246 |
| Pages updated (substantive) | 6 |
| Pages re-saved on the site, package fields verified unchanged | 47 |
| Pages removed or redirected | 8 |
| Live pages deliberately excluded | 5 |

### Asset changes

- **llms.txt** — CEO departure; pricing per product; Advertising Equity (as a Brand Tracking measure); Lantmännen proof point; 20 case studies; 2 new posts and new totals (261 posts, 287 releases); upcoming events; BrandGrow Program solution; Contact Finland, Blog, Sessions, Newsroom, Data Quality key pages; YouTube; full Nordic section linking the 94 localized pages; new Localized Pages API link. Removed links to /events/ and /webinar/ archives.
- **ai.txt** — `Allow-Training: yes` with a matching `Content-Signal: ai-train=yes` line; `Last-Updated`; leadership-change key fact; localized citation guidance.
- **robots-addition.txt** — synced to the 21 AI user-agents already in the live robots.txt (added OAI-SearchBot, Claude-User, Perplexity-User, Meta-ExternalAgent, Amazonbot, DuckAssistBot); added `AI-File` and `Content-Signal: ai-train=yes, search=yes, ai-input=yes` lines. **The live robots.txt currently says `ai-train=no` - change that line when deploying.**
- **api-catalog.json** — modified date; new REST endpoint `/agent/v1/localized-pages.json` (19 endpoints) and MCP tool `list_localized_pages` (18 tools); language filters noted; YouTube in `sameAs`; languages.
- **nepa-api/agent-page.html** — dates; package version row; new agent instructions on language and the leadership change; Lantmännen case article; full English and Swedish blog index (261); full bilingual press-release table (287) with language cross-links; rebuilt pricing (11 tiers) and events (2 upcoming); IR recent announcements and next report; Finland office contacts; YouTube, newsletter and investor contacts; complete Nordic page index (94); fixed links to removed archives. JSON-LD: new **FAQPage** (14 Q&As, built from the on-page FAQ), Lantmännen in the case ItemList (20), Anders Dahl note, `dateModified`, YouTube `sameAs`.
- **nepa-api/nepa-api.php (1.2.0)** — new `localized-pages` endpoint (`?language=`, `?section=`); `?language=` on blog-posts and `?language=`/`?category=` on press-releases (applied before pagination); disables WordPress's trailing-slash canonical redirect on the plugin's own URLs (was 301-ing `/agent/v1/*.json` to `*.json/`); optional `NEPA_TRUST_CLOUDFLARE_IP` constant so contact rate limits use `CF-Connecting-IP` behind Cloudflare.
- **nepa-api/includes/class-sync.php** — data version 1.1; press-release sync keeps every language and writes `language`, `regulatory` and `alternate_language_url`; a hand-corrected language (Svenska Spel) is preserved; blog sync writes `language` from the URL prefix.
- **nepa-api/openapi.json (1.1.0)** — `/localized-pages.json` path and schemas; language/category parameters; new optional fields (language, regulatory, alternate_language_url, office contacts, leadership_change, recent_announcements, pricing notes, event summary). All 16 data files validate against it.
- **nepa-api/data/** — see the data section below; new `localized-pages.json`; all files `version: 1.1`, `last_updated: 2026-10-16`.
- **nepa-mcp (1.1.0)** — `list_localized_pages`; language/category arguments; version reported by `/health`; rebuilt `dist/index.js`. `node_modules/` is not shipped (installed by `npm ci`).
- **DEPLOYMENT.md** (new) — full deployment, verification, rollback and monitoring procedure.
- **tests/** (new, not deployed) — `run-plugin-tests.php` + `wp-stubs.php` (plugin, 60 checks) and `mcp-smoke-test.sh` (MCP server, 13 checks, run in a temporary workspace); see `tests/README.md`.
- **Not carried over** — `Original/nepa-api/agent-page copy.html`, an older 2026-09-08 duplicate of the agent page; `.git/` folders and `.DS_Store` files.

### Data file changes

| File | v1.0 | v1.1 | Change |
|---|---|---|---|
| blog-posts.json | 247 | 261 (246 en, 15 sv) | +2 English, +15 Swedish, −3 removed (2 dead, 1 duplicate of a redirected post), `language` field, `/had` excerpt glitch fixed |
| press-releases.json | 135 | 287 (136 en, 151 sv) | +1 English (CEO), +151 Swedish, `language` / `regulatory` / `alternate_language_url` |
| case-studies.json | 19 | 20 | + Lantmännen (Advertising Equity) |
| events.json | 27 | 30 | + 2 upcoming breakfast sessions, + 1 on-demand webinar |
| pricing.json | 4 | 11 | rebuilt from all three pricing tabs; notes |
| resources.json | 32 | 34 | Advertising Equity 2026 retitled; + 2 landing pages |
| localized-pages.json | — | 94 (47 sv, 24 fi, 23 da) | new endpoint |
| company.json | | | leadership_change, social (YouTube), languages |
| team.json | | | Anders Dahl status note |
| investor-relations.json | | | recent_announcements, next_report, investor contact, Certified Adviser |
| offices.json | | | contact pages, three named Finland contacts, panel email |
| company-news.json | | | note updated (/news/ archive now redirects) |
| products.json | | | Brand Tracking price wording |
| schema.json | | | new and changed fields |
| solutions, industries, jobs | | | unchanged content; version/date bump |

### Pages added

#### Newly published on nepa.com since the last build (8)

- 2026-09-30 — [CEO Anders Dahl leaves Nepa – search for successor initiated](https://nepa.com/mfn_news/ceo-anders-dahl-leaves-nepa-search-for-successor-initiated/) — regulatory press release (EN)
- 2026-09-30 — [VD Anders Dahl lämnar Nepa – rekrytering av efterträdare inledd](https://nepa.com/mfn_news/vd-anders-dahl-lamnar-nepa-rekrytering-av-eftertradare-inledd/) — regulatory press release (SV)
- 2026-09-30 — [Research should shape brand strategy, not rescue it](https://nepa.com/blog/research-should-shape-brand-strategy-not-rescue-it/) — blog post
- 2026-09-17 — [Advertising Equity 2026 study](https://nepa.com/blog/advertising-equity-2026-study/) — blog post
- 2026-09-11 — [Case Advertising Equity Analysis for Lantmännen](https://nepa.com/case/measure-the-value-advertising-builds-over-time/) — case study
- 2026-10-09 — [On-demand webinar: Measuring Advertising Equity - The Lantmännen Case](https://nepa.com/webinar/on-demand-webinar-advertising-equity/) — on-demand webinar
- 2026-10-09 — [Upcoming: Nepa Breakfast Session Helsinki - Planning for impact - 11 November](https://nepa.com/events/fi-nepa-breakfast-session-planning-for-impact-fueling-smarter-marketing-in-2027/) — upcoming event, 11 Nov 2026
- 2026-10-09 — [Kommande: Nepa Breakfast Session - Stockholm - 12 November 2026](https://nepa.com/events/nepa-breakfast-session-12-november-2026/) — upcoming event, 12 Nov 2026

#### English pages — already live, not previously covered (7)

- [Market Research Insights Blog](https://nepa.com/blog/)
- [Brand tracking ska inte stanna vid kännedom och kampanjutfall (Swedish-language landing page on the English path)](https://nepa.com/brand-tracking-ska-inte-stanna-vid-kannedom-och-kampanjutfall/)
- [Contact Us | Finland Location](https://nepa.com/contact-finland/)
- [Contact information](https://nepa.com/contact-information/)
- [Nepa Breakfast Session Helsinki – Did your marketing budget for 2026 get cut too? (landing page)](https://nepa.com/nepa-breakfast-session-did-your-marketing-budget-for-2026-get-cut-too/)
- [Subscribe to Our Newsletter](https://nepa.com/newsletter/)
- [Participant Privacy Policy | Nepa – Health Data Use](https://nepa.com/participant-privacy-policy-health/)

#### English press-release archive — already live, not previously covered (1)

- [MFN News Items Archive (all press releases)](https://nepa.com/mfn_news/)

#### Swedish-language press releases (/mfn_news/) — already live, not previously covered (150)

- [AI tar en tydlig roll i researchfasen - och styr köpbesluten](https://nepa.com/mfn_news/ai-tar-en-tydlig-roll-i-researchfasen-och-styr-kopbesluten/) (2025-11-11)
- [Anders Dahl utsedd till ny VD för Nepa](https://nepa.com/mfn_news/anders-dahl-utsedd-till-ny-vd-for-nepa/) (2024-03-15)
- [Ändringar i Nepas valberedning](https://nepa.com/mfn_news/andringar-i-nepas-valberedning/) (2024-03-25)
- [Bokslutskommuniké 2022](https://nepa.com/mfn_news/bokslutskommunike-2022/) (2023-03-17)
- [Bokslutskommuniké Nepa AB (publ), Q4 2017](https://nepa.com/mfn_news/bokslutskommunike-nepa-ab-publ-q4-2017/) (2018-03-28)
- [Bokslutskommuniké Nepa AB (publ), Q4 2018](https://nepa.com/mfn_news/bokslutskommunike-nepa-ab-publ-q4-2018/) (2019-03-22)
- [Delårsrapport Nepa AB (publ), Q2 2018](https://nepa.com/mfn_news/delarsrapport-nepa-ab-publ-q2-2018/) (2018-08-24)
- [Delårsrapport Nepa AB (publ) Q3 2017](https://nepa.com/mfn_news/delarsrapport-nepa-ab-publ-q3-2017/) (2017-11-30)
- [Erbjudandet i Nepa AB tecknat till mer än 400 procent - handel på Nasdaq First North inleds den 26 april 2016](https://nepa.com/mfn_news/erbjudandet-i-nepa-ab-tecknat-till-mer-an-400-procent-handel-pa-nasdaq-first-north-inleds-den-26-april-2016/) (2016-04-26)
- [Ett år av framsteg och förstärkning – Nepas VD Anders Dahl blickar tillbaka på ett händelserikt 2024](https://nepa.com/mfn_news/ett-ar-av-framsteg-och-forstarkning-nepas-vd-anders-dahl-blickar-tillbaka-pa-ett-handelserikt-2024/) (2024-12-17)
- [Förändring i Nepas koncernledning](https://nepa.com/mfn_news/forandring-i-nepas-koncernledning-2/) (2026-04-21)
- [Förändring i Nepas koncernledning](https://nepa.com/mfn_news/forandring-i-nepas-koncernledning/) (2025-05-26)
- [Förändringar i Nepas koncernledning](https://nepa.com/mfn_news/forandringar-i-nepas-koncernledning/) (2023-04-04)
- [Förändringar i styrelsen - kommuniké från extra bolagsstämma i Nepa AB (publ)](https://nepa.com/mfn_news/forandringar-i-styrelsen-kommunike-fran-extra-bolagsstamma-i-nepa-ab-publ/) (2024-03-15)
- [Fortsatt tillväxt under kvartal med fokus på kapitalanskaffning för framtida tillväxt](https://nepa.com/mfn_news/fortsatt-tillvaxt-under-kvartal-med-fokus-pa-kapitalanskaffning-for-framtida-tillvaxt/) (2016-05-31)
- [IKEA Sverige och Nepa i nytt partnerskap](https://nepa.com/mfn_news/ikea-sverige-och-nepa-i-nytt-partnerskap/) (2017-12-06)
- [Inbjudan till Nepas Q4 2022 presentation och telefonkonferens](https://nepa.com/mfn_news/inbjudan-till-nepas-q4-2022-presentation-och-telefonkonferens/) (2023-03-09)
- [Inbjudan till presentation av Nepas bokslutskommuniké 2023](https://nepa.com/mfn_news/inbjudan-till-presentation-av-nepas-bokslutskommunike-2023/) (2024-03-11)
- [Inbjudan till presentation av Nepas bokslutskommuniké 2024](https://nepa.com/mfn_news/inbjudan-till-presentation-av-nepas-bokslutskommunike-2024/) (2025-02-14)
- [Inbjudan till presentation av Nepas bokslutskommuniké 2025](https://nepa.com/mfn_news/inbjudan-till-presentation-av-nepas-bokslutskommunike-2025/) (2026-02-16)
- [Inbjudan till presentation av Nepas delårsrapport Q1 2024](https://nepa.com/mfn_news/inbjudan-till-presentation-av-nepas-delarsrapport-q1-2024/) (2024-05-02)
- [Inbjudan till presentation av Nepas delårsrapport Q1 2025](https://nepa.com/mfn_news/inbjudan-till-presentation-av-nepas-delarsrapport-q1-2025/) (2025-04-29)
- [Inbjudan till presentation av Nepas delårsrapport Q1 2026](https://nepa.com/mfn_news/inbjudan-till-presentation-av-nepas-delarsrapport-q1-2026/) (2026-04-29)
- [Inbjudan till presentation av Nepas delårsrapport Q2 2023](https://nepa.com/mfn_news/inbjudan-till-presentation-av-nepas-delarsrapport-q2-2023/) (2023-08-14)
- [Inbjudan till presentation av Nepas delårsrapport Q2 2024](https://nepa.com/mfn_news/inbjudan-till-presentation-av-nepas-delarsrapport-q2-2024/) (2024-08-12)
- [Inbjudan till presentation av Nepas delårsrapport Q2 2025](https://nepa.com/mfn_news/inbjudan-till-presentation-av-nepas-delarsrapport-q2-2025/) (2025-08-08)
- [Inbjudan till presentation av Nepas delårsrapport Q2 2026](https://nepa.com/mfn_news/inbjudan-till-presentation-av-nepas-delarsrapport-q2-2026/)
- [Inbjudan till presentation av Nepas delårsrapport Q3 2023](https://nepa.com/mfn_news/inbjudan-till-presentation-av-nepas-delarsrapport-q3-2023/) (2023-11-13)
- [Inbjudan till presentation av Nepas delårsrapport Q3 2024](https://nepa.com/mfn_news/inbjudan-till-presentation-av-nepas-delarsrapport-q3-2024/) (2024-10-18)
- [Inbjudan till presentation av Nepas delårsrapport Q3 2025](https://nepa.com/mfn_news/inbjudan-till-presentation-av-nepas-delarsrapport-q3-2025/) (2025-10-20)
- [Jan Scherman blir strategisk rådgivare hos Nepa](https://nepa.com/mfn_news/jan-scherman-blir-strategisk-radgivare-hos-nepa/) (2016-06-30)
- [Kallelse till årsstämma i Nepa AB (publ)](https://nepa.com/mfn_news/kallelse-till-arsstamma-i-nepa-ab-publ-2/) (2023-05-09)
- [KALLELSE TILL ÅRSSTÄMMA I NEPA AB (PUBL)](https://nepa.com/mfn_news/kallelse-till-arsstamma-i-nepa-ab-publ-3/) (2018-04-27)
- [Kallelse till årsstämma i Nepa AB (publ)](https://nepa.com/mfn_news/kallelse-till-arsstamma-i-nepa-ab-publ-4/) (2025-05-21)
- [Kallelse till årsstämma i Nepa AB (publ)](https://nepa.com/mfn_news/kallelse-till-arsstamma-i-nepa-ab-publ-5/) (2026-04-17)
- [Kallelse till årsstämma i Nepa AB (publ)](https://nepa.com/mfn_news/kallelse-till-arsstamma-i-nepa-ab-publ/) (2024-04-26)
- [Kallelse till extra bolagsstämma i Nepa AB (publ)](https://nepa.com/mfn_news/kallelse-till-extra-bolagsstamma-i-nepa-ab-publ-2/) (2024-02-07)
- [Kallelse till extra bolagsstämma i Nepa AB (publ)](https://nepa.com/mfn_news/kallelse-till-extra-bolagsstamma-i-nepa-ab-publ-3/) (2023-08-29)
- [Kallelse till extra bolagsstämma i Nepa AB (publ)](https://nepa.com/mfn_news/kallelse-till-extra-bolagsstamma-i-nepa-ab-publ/) (2024-10-22)
- [Kommuniké från extra bolagsstämma i Nepa AB (publ)](https://nepa.com/mfn_news/kommunike-fran-extra-bolagsstamma-i-nepa-ab-publ/) (2024-11-19)
- [Kommuniké från extra bolagsstämma i Nepa AB](https://nepa.com/mfn_news/kommunike-fran-extra-bolagsstamma-i-nepa-ab/) (2023-09-18)
- [Kommuniké från Nepa AB:s årsstämma 2018](https://nepa.com/mfn_news/kommunike-fran-nepa-abs-arsstamma-2018/) (2018-05-31)
- [Kommuniké från Nepa AB:s årsstämma 2023](https://nepa.com/mfn_news/kommunike-fran-nepa-abs-arsstamma-2023/) (2023-06-09)
- [Kommuniké från Nepa AB:s årsstämma 2024](https://nepa.com/mfn_news/kommunike-fran-nepa-abs-arsstamma-2024/) (2024-05-31)
- [Kommuniké från Nepa AB:s årsstämma 2025](https://nepa.com/mfn_news/kommunike-fran-nepa-abs-arsstamma-2025/) (2025-06-23)
- [Kommuniké från Nepa AB:s årsstämma 2026](https://nepa.com/mfn_news/kommunike-fran-nepa-abs-arsstamma-2026/) (2026-05-21)
- [Kompletterande information till Nepas årsstämma 2023](https://nepa.com/mfn_news/kompletterande-information-till-nepas-arsstamma-2023/) (2023-05-29)
- [Kundinsikter är nyckeln när Varner Ibrahimovic bygger varumärket A-Z](https://nepa.com/mfn_news/kundinsikter-ar-nyckeln-nar-varner-ibrahimovic-bygger-varumarket-a-z/) (2016-06-16)
- [LÖNSAM TILLVÄXT OCH UPPSTART I USA](https://nepa.com/mfn_news/lonsam-tillvaxt-och-uppstart-i-usa/) (2016-08-30)
- [Marketing intelligence-företaget Nepa tar ställning för ökad datakvalitet i marknadsundersökningar](https://nepa.com/mfn_news/marketing-intelligence-foretaget-nepa-tar-stallning-for-okad-datakvalitet-i-marknadsundersokningar/) (2025-05-22)
- [Matt Nitzberg rekryterad som Chief Client Officer till Nepa i USA](https://nepa.com/mfn_news/matt-nitzberg-rekryterad-som-chief-client-officer-till-nepa-i-usa/) (2018-03-21)
- [Möt Filip Tottie: Nepas nya CFO med uppdrag att stärka bolagets finansiella utveckling](https://nepa.com/mfn_news/mot-filip-tottie-nepas-nya-cfo-med-uppdrag-att-starka-bolagets-finansiella-utveckling/) (2025-01-22)
- [Nepa AB (publ): Ändringar i Nepas valberedning](https://nepa.com/mfn_news/nepa-ab-publ-andringar-i-nepas-valberedning/) (2022-12-12)
- [Nepa AB (publ): Ann-Christine Fick avgår som CFO](https://nepa.com/mfn_news/nepa-ab-publ-ann-christine-fick-avgar-som-cfo/) (2022-09-29)
- [Nepa AB (publ): BRUTTORESULTATET ÖKADE MARGINELLT I TREDJE KVARTALET](https://nepa.com/mfn_news/nepa-ab-publ-bruttoresultatet-okade-marginellt-i-tredje-kvartalet/) (2019-11-21)
- [Nepa AB (publ): BRUTTORESULTATET ÖKADE MED 14 PROCENT, TILLVÄXT PÅ SAMTLIGA MARKNADER](https://nepa.com/mfn_news/nepa-ab-publ-bruttoresultatet-okade-med-14-procent-tillvaxt-pa-samtliga-marknader/) (2019-08-21)
- [Nepa AB (publ): BRUTTORESULTATET ÖKADE MED 19 PROCENT, FÖRBÄTTRAT RÖRELSERESULTAT](https://nepa.com/mfn_news/nepa-ab-publ-bruttoresultatet-okade-med-19-procent-forbattrat-rorelseresultat/) (2019-05-21)
- [Nepa AB (publ): DET STARKASTE RÖRELSERESULTATET NÅGONSIN](https://nepa.com/mfn_news/nepa-ab-publ-det-starkaste-rorelseresultatet-nagonsin/) (2020-11-20)
- [Nepa AB (publ): EN BRA START OCH FOKUS PÅ FRAMTIDEN](https://nepa.com/mfn_news/nepa-ab-publ-en-bra-start-och-fokus-pa-framtiden/) (2022-05-19)
- [Nepa AB (publ): FÖRSTÄRKER VÅR POSITION INOM BRANDTECH](https://nepa.com/mfn_news/nepa-ab-publ-forstarker-var-position-inom-brandtech/) (2022-11-18)
- [Nepa AB (publ): FORTSATT STARK LÖNSAMHET OCH TILLVÄXT](https://nepa.com/mfn_news/nepa-ab-publ-fortsatt-stark-lonsamhet-och-tillvaxt/) (2021-11-19)
- [Nepa AB (publ): FORTSATT TILLVÄXT MED ÖKAT LÖNSAMHETSFOKUS](https://nepa.com/mfn_news/nepa-ab-publ-fortsatt-tillvaxt-med-okat-lonsamhetsfokus/) (2020-03-20)
- [Nepa AB (publ): FORTSATT TILLVÄXT OCH LÖNSAMHET](https://nepa.com/mfn_news/nepa-ab-publ-fortsatt-tillvaxt-och-lonsamhet/) (2022-08-19)
- [Nepa AB (publ): Global lifestyle and clothing brand GANT selects Nepa as brand tracking provider in all major markets](https://nepa.com/mfn_news/nepa-ab-publ-global-lifestyle-and-clothing-brand-gant-selects-nepa-as-brand-tracking-provider-in-all-major-markets/) (2020-02-04)
- [Nepa AB (publ): Inbjudan till Nepas Q2 2022 presentation och telefonkonferens](https://nepa.com/mfn_news/nepa-ab-publ-inbjudan-till-nepas-q2-2022-presentation-och-telefonkonferens/) (2022-08-09)
- [Nepa AB (publ): Inbjudan till Nepas Q3 2022 presentation och telefonkonferens](https://nepa.com/mfn_news/nepa-ab-publ-inbjudan-till-nepas-q3-2022-presentation-och-telefonkonferens/) (2022-11-07)
- [Nepa AB (publ): KALLELSE TILL ÅRSSTÄMMA I NEPA AB (PUBL)](https://nepa.com/mfn_news/nepa-ab-publ-kallelse-till-arsstamma-i-nepa-ab-publ-2/) (2021-04-19)
- [Nepa AB (publ): KALLELSE TILL ÅRSSTÄMMA I NEPA AB (PUBL)](https://nepa.com/mfn_news/nepa-ab-publ-kallelse-till-arsstamma-i-nepa-ab-publ-3/) (2020-04-17)
- [Nepa AB (publ): KALLELSE TILL ÅRSSTÄMMA I NEPA AB (PUBL)](https://nepa.com/mfn_news/nepa-ab-publ-kallelse-till-arsstamma-i-nepa-ab-publ-4/) (2019-04-25)
- [Nepa AB (publ): KALLELSE TILL ÅRSSTÄMMA I NEPA AB (PUBL)](https://nepa.com/mfn_news/nepa-ab-publ-kallelse-till-arsstamma-i-nepa-ab-publ/) (2022-04-14)
- [Nepa AB (publ): Kommuniké från Nepa AB:s årsstämma 2019](https://nepa.com/mfn_news/nepa-ab-publ-kommunike-fran-nepa-abs-arsstamma-2019/) (2019-05-29)
- [Nepa AB (publ): Kommuniké från Nepa AB:s årsstämma 2020](https://nepa.com/mfn_news/nepa-ab-publ-kommunike-fran-nepa-abs-arsstamma-2020/) (2020-05-20)
- [Nepa AB (publ): Kommuniké från Nepa AB:s årsstämma 2021](https://nepa.com/mfn_news/nepa-ab-publ-kommunike-fran-nepa-abs-arsstamma-2021/) (2021-05-20)
- [Nepa AB (publ): Kommuniké från Nepa AB:s årsstämma 2022](https://nepa.com/mfn_news/nepa-ab-publ-kommunike-fran-nepa-abs-arsstamma-2022/) (2022-05-19)
- [Nepa AB (publ): Nepa AB utser valberedning](https://nepa.com/mfn_news/nepa-ab-publ-nepa-ab-utser-valberedning/) (2021-10-22)
- [Nepa AB (publ): Nepa byter Certified Adviser till Redeye AB](https://nepa.com/mfn_news/nepa-ab-publ-nepa-byter-certified-adviser-till-redeye-ab/) (2022-12-15)
- [Nepa AB (publ): Nepa byter VD i dotterbolag](https://nepa.com/mfn_news/nepa-ab-publ-nepa-byter-vd-i-dotterbolag/) (2019-09-22)
- [Nepa AB (publ): Nepa påskyndar sin strategiska transformation och initierar ett globalt kostnadsbesparingsprogram](https://nepa.com/mfn_news/nepa-ab-publ-nepa-paskyndar-sin-strategiska-transformation-och-initierar-ett-globalt-kostnadsbesparingsprogram/) (2022-12-14)
- [Nepa AB (publ): Nepa stärker sina tillväxtinitiativ och tillsätter Ferry Wolswinkel som Chief Revenue Officer](https://nepa.com/mfn_news/nepa-ab-publ-nepa-starker-sina-tillvaxtinitiativ-och-tillsatter-ferry-wolswinkel-som-chief-revenue-officer/) (2022-08-17)
- [Nepa AB (publ): Nepa tillsätter Sonja Thorngren som Chief Financial Officer](https://nepa.com/mfn_news/nepa-ab-publ-nepa-tillsatter-sonja-thorngren-som-chief-financial-officer/) (2022-11-10)
- [Nepa AB (publ): Nepa utser Mangold till likviditetsgarant](https://nepa.com/mfn_news/nepa-ab-publ-nepa-utser-mangold-till-likviditetsgarant/) (2019-10-15)
- [Nepa AB (publ): Nepas årsredovisning för 2019 tillgänglig på hemsidan](https://nepa.com/mfn_news/nepa-ab-publ-nepas-arsredovisning-for-2019-tillganglig-pa-hemsidan/) (2020-04-28)
- [Nepa AB (publ): Nepas årsredovisning för 2021 tillgänglig på hemsidan](https://nepa.com/mfn_news/nepa-ab-publ-nepas-arsredovisning-for-2021-tillganglig-pa-hemsidan/) (2022-04-26)
- [Nepa AB (publ): Nepas VD lämnar bolaget på egen begäran, styrelseordförande går in som VD](https://nepa.com/mfn_news/nepa-ab-publ-nepas-vd-lamnar-bolaget-pa-egen-begaran-styrelseordforande-gar-in-som-vd/) (2021-08-24)
- [Nepa AB (publ): P-O Westerlund utsedd till ny VD och koncernchef](https://nepa.com/mfn_news/nepa-ab-publ-p-o-westerlund-utsedd-till-ny-vd-och-koncernchef/) (2020-01-26)
- [Nepa AB (publ): REKORDOMSÄTTNING OCH FÖRBÄTTRAD LÖNSAMHET](https://nepa.com/mfn_news/nepa-ab-publ-rekordomsattning-och-forbattrad-lonsamhet/) (2021-05-20)
- [Nepa AB (publ): RESULTATFÖRSTÄRKNING UNDER FORTSATT TILLVÄXT](https://nepa.com/mfn_news/nepa-ab-publ-resultatforstarkning-under-fortsatt-tillvaxt/) (2020-05-20)
- [Nepa AB (publ): SLÅR OMSÄTTNINGS- OCH LÖNSAMHETSREKORD](https://nepa.com/mfn_news/nepa-ab-publ-slar-omsattnings-och-lonsamhetsrekord/) (2021-08-20)
- [Nepa AB (publ): STARK AVSLUTNING PÅ ETT STARKT ÅR](https://nepa.com/mfn_news/nepa-ab-publ-stark-avslutning-pa-ett-starkt-ar/) (2022-03-18)
- [Nepa AB (publ): STÄRKER LÖNSAMHETEN OCH DEN FINANSIELLA STÄLLNINGEN](https://nepa.com/mfn_news/nepa-ab-publ-starker-lonsamheten-och-den-finansiella-stallningen/) (2020-08-21)
- [Nepa AB (publ): Utser Ann-Christine Fick till CFO och Michael Wallin till ny IR-chef](https://nepa.com/mfn_news/nepa-ab-publ-utser-ann-christine-fick-till-cfo-och-michael-wallin-till-ny-ir-chef/) (2020-02-25)
- [Nepa AB (publ): Valberedning utsedd inför årsstämman 2023](https://nepa.com/mfn_news/nepa-ab-publ-valberedning-utsedd-infor-arsstamman-2023/) (2022-11-03)
- [Nepa AB (publ): VÄNT TILL VINST, LAGT STRATEGI FÖR LÖNSAM TILLVÄXT](https://nepa.com/mfn_news/nepa-ab-publ-vant-till-vinst-lagt-strategi-for-lonsam-tillvaxt/) (2021-03-19)
- [Nepa AB (publ): Vidtar åtgärder för att möta ett förändrat marknadssentiment](https://nepa.com/mfn_news/nepa-ab-publ-vidtar-atgarder-for-att-mota-ett-forandrat-marknadssentiment/) (2020-04-07)
- [Nepa annonserar samarbetspartnerskap med EFFDay och Sveriges Annonsörer](https://nepa.com/mfn_news/nepa-annonserar-samarbetspartnerskap-med-effday-och-sveriges-annonsorer/) (2024-10-15)
- [Nepa bjuder in till bolagspresentation hos Redeye](https://nepa.com/mfn_news/nepa-bjuder-in-till-bolagspresentation-hos-redeye/) (2023-05-10)
- [Nepa blir samarbetspartner till Friends – bidrar med expertis inom varumärkesanalys](https://nepa.com/mfn_news/nepa-blir-samarbetspartner-till-friends-bidrar-med-expertis-inom-varumarkesanalys/) (2025-04-10)
- [Nepa Bokslutskommuniké 2023](https://nepa.com/mfn_news/nepa-bokslutskommunike-2023/) (2024-03-15)
- [Nepa Bokslutskommuniké 2024](https://nepa.com/mfn_news/nepa-bokslutskommunike-2024/) (2025-02-21)
- [Nepa Bokslutskommuniké 2025](https://nepa.com/mfn_news/nepa-bokslutskommunike-2025/) (2026-02-20)
- [Nepa Delårsrapport Q1 2023](https://nepa.com/mfn_news/nepa-delarsrapport-q1-2023/) (2023-05-16)
- [Nepa Delårsrapport Q1 2024](https://nepa.com/mfn_news/nepa-delarsrapport-q1-2024/) (2024-05-07)
- [Nepa Delårsrapport Q1 2025](https://nepa.com/mfn_news/nepa-delarsrapport-q1-2025/) (2025-05-09)
- [Nepa Delårsrapport Q1 2026](https://nepa.com/mfn_news/nepa-delarsrapport-q1-2026/) (2026-05-08)
- [Nepa Delårsrapport Q2 2023](https://nepa.com/mfn_news/nepa-delarsrapport-q2-2023/) (2023-08-18)
- [Nepa Delårsrapport Q2 2024](https://nepa.com/mfn_news/nepa-delarsrapport-q2-2024/) (2024-08-16)
- [Nepa Delårsrapport Q2 2025](https://nepa.com/mfn_news/nepa-delarsrapport-q2-2025/) (2025-08-15)
- [Nepa Delårsrapport Q2 2026](https://nepa.com/mfn_news/nepa-delarsrapport-q2-2026/)
- [Nepa Delårsrapport Q3 2023](https://nepa.com/mfn_news/nepa-delarsrapport-q3-2023/) (2023-11-17)
- [Nepa Delårsrapport Q3 2024](https://nepa.com/mfn_news/nepa-delarsrapport-q3-2024/) (2024-10-25)
- [Nepa Delårsrapport Q3 2025](https://nepa.com/mfn_news/nepa-delarsrapport-q3-2025/) (2025-10-24)
- [Nepa expanderar i den globala sportbranschen under ledning av marknadsveteran från USA](https://nepa.com/mfn_news/nepa-expanderar-i-den-globala-sportbranschen-under-ledning-av-marknadsveteran-fran-usa/) (2018-08-22)
- [Nepa förlorar stort brand tracking-kontrakt](https://nepa.com/mfn_news/nepa-forlorar-stort-brand-tracking-kontrakt/) (2024-09-17)
- [Nepa har genomfört en riktad nyemission om 714 835 aktier och tillförs härigenom cirka 39,3 MSEK](https://nepa.com/mfn_news/nepa-har-genomfort-en-riktad-nyemission-om-714-835-aktier-och-tillfors-harigenom-cirka-393-msek/) (2018-12-12)
- [Nepa Indien expanderar till APAC-regionen](https://nepa.com/mfn_news/nepa-indien-expanderar-till-apac-regionen/) (2023-04-27)
- [Nepa lanserar cMMM – ett verktyg för realtidsoptimering av marknadsinvesteringar](https://nepa.com/mfn_news/nepa-lanserar-cmmm-ett-verktyg-for-realtidsoptimering-av-marknadsinvesteringar-2/) (2024-10-25)
- [Nepa lanserar cMMM – ett verktyg för realtidsoptimering av marknadsinvesteringar](https://nepa.com/mfn_news/nepa-lanserar-cmmm-ett-verktyg-for-realtidsoptimering-av-marknadsinvesteringar/) (2024-10-25)
- [Nepa lanserar ett nytt varumärkeskoncept för att driva tillväxt](https://nepa.com/mfn_news/nepa-lanserar-ett-nytt-varumarkeskoncept-for-att-driva-tillvaxt/) (2025-04-23)
- [Nepa och Opticom levererar varumärkesinsikter för B2B: Inleder ett strategiskt partnerskap utifrån 360°-vy](https://nepa.com/mfn_news/nepa-och-opticom-levererar-varumarkesinsikter-for-b2b-inleder-ett-strategiskt-partnerskap-utifran-360-vy/) (2025-02-26)
- [Nepa och Opticom levererar varumärkesinsikter för B2B: Inleder ett strategiskt partnerskap utifrån en 360-graders vy](https://nepa.com/mfn_news/nepa-och-opticom-levererar-varumarkesinsikter-for-b2b-inleder-ett-strategiskt-partnerskap-utifran-en-360-graders-vy/) (2025-02-26)
- [Nepa publicerar årsredovisning för 2022](https://nepa.com/mfn_news/nepa-publicerar-arsredovisning-for-2022/) (2023-05-03)
- [Nepa publicerar årsredovisning för 2023](https://nepa.com/mfn_news/nepa-publicerar-arsredovisning-for-2023/) (2024-04-26)
- [Nepa publicerar årsredovisning för 2024](https://nepa.com/mfn_news/nepa-publicerar-arsredovisning-for-2024/) (2025-04-25)
- [Nepa publicerar årsredovisning för 2025](https://nepa.com/mfn_news/nepa-publicerar-arsredovisning-for-2025/) (2026-04-24)
- [Nepa publicerar preliminära resultat för Q2 2023](https://nepa.com/mfn_news/nepa-publicerar-preliminara-resultat-for-q2-2023/) (2023-07-31)
- [Nepa sponsrar 100-wattaren - förstärker satsningen på effektfrågan tillsammans med Sveriges Annonsörer](https://nepa.com/mfn_news/nepa-sponsrar-100-wattaren-forstarker-satsningen-pa-effektfragan-tillsammans-med-sveriges-annonsorer/) (2025-10-08)
- [Nepa stärker koncernledningen och utser Anders Dahl till tillförordnad Operativ Chef (COO)](https://nepa.com/mfn_news/nepa-starker-koncernledningen-och-utser-anders-dahl-till-tillforordnad-operativ-chef-coo/) (2023-08-18)
- [Nepa stärker satsningen på Marketing Acceleration med hemvändande toppteam inom MMM](https://nepa.com/mfn_news/nepa-starker-satsningen-pa-marketing-acceleration-med-hemvandande-toppteam-inom-mmm/) (2025-12-17)
- [Nepa tar nästa steg – aktiverar sökprocess för att hitta en permanent VD](https://nepa.com/mfn_news/nepa-tar-nasta-steg-aktiverar-sokprocess-for-att-hitta-en-permanent-vd/) (2023-05-29)
- [Nepa tillsätter Robert Beatus som Chief Product Officer och meddelar ledningsförändringar](https://nepa.com/mfn_news/nepa-tillsatter-robert-beatus-som-chief-product-officer-och-meddelar-ledningsforandringar/) (2023-08-30)
- [Nepa tillsätter Sara Davidsson Nyman som Chief Revenue Officer](https://nepa.com/mfn_news/nepa-tillsatter-sara-davidsson-nyman-som-chief-revenue-officer/) (2024-04-10)
- [Nepa utser Ferry Wolswinkel som tillförordnad VD](https://nepa.com/mfn_news/nepa-utser-ferry-wolswinkel-som-tillforordnad-vd/) (2023-06-01)
- [Nepa utser Filip Tottie till ny Chief Financial Officer](https://nepa.com/mfn_news/nepa-utser-filip-tottie-till-ny-chief-financial-officer/) (2025-01-20)
- [Nepa utser Jakob Kofoed till Chief Technology Officer](https://nepa.com/mfn_news/nepa-utser-jakob-kofoed-till-chief-technology-officer/) (2024-10-24)
- [Nepa värvar Andreas Nordfors från BCG – accelererar satsningen på marknadsföringsoptimering och internationell tillväxt](https://nepa.com/mfn_news/nepa-varvar-andreas-nordfors-fran-bcg-accelererar-satsningen-pa-marknadsforingsoptimering-och-internationell-tillvaxt/) (2025-08-22)
- [Nepas största aktieägare ställer ut köpoptioner till bolagets styrelseledamöter](https://nepa.com/mfn_news/nepas-storsta-aktieagare-staller-ut-kopoptioner-till-bolagets-styrelseledamoter/) (2023-09-25)
- [Ny mätning: Varumärkena som byggt starkast reklamkapital](https://nepa.com/mfn_news/ny-matning-varumarkena-som-byggt-starkast-reklamkapital/)
- [Nytillträdda CEO Anders Dahl och CRO Sara Davidsson Nyman leder Nepas lönsamma expansion](https://nepa.com/mfn_news/nytilltradda-ceo-anders-dahl-och-cro-sara-davidsson-nyman-leder-nepas-lonsamma-expansion/) (2024-10-02)
- [Plantagen, europeisk detaljist, ingår partnerskap med Nepa kring kundupplevelse.](https://nepa.com/mfn_news/plantagen-europeisk-detaljist-ingar-partnerskap-med-nepa-kring-kundupplevelse/) (2018-09-04)
- [PRODUKTLANSERING OCH FORTSATT TILLVÄXT I FÖRSTA KVARTALET](https://nepa.com/mfn_news/produktlansering-och-fortsatt-tillvaxt-i-forsta-kvartalet/) (2018-05-24)
- [Psyonix, den amerikanska videospelsjätten, väljer Nepa för att globalt optimera sina varumärkesinsikter](https://nepa.com/mfn_news/psyonix-den-amerikanska-videospelsjatten-valjer-nepa-for-att-globalt-optimera-sina-varumarkesinsikter/) (2018-07-31)
- [Snap Inc lägger till Nepa i sitt MMM-partnerprogram](https://nepa.com/mfn_news/snap-inc-lagger-till-nepa-i-sitt-mmm-partnerprogram/) (2018-06-28)
- [Sonja Thorngren avgår som CFO för Nepa](https://nepa.com/mfn_news/sonja-thorngren-avgar-som-cfo-for-nepa/) (2024-10-14)
- [TILLVÄXT i BRUTTORESULTAT OM 24,5 PROCENT OCH KRAFTIGT FÖRBÄTTRAT RÖRELSERESULTAT](https://nepa.com/mfn_news/tillvaxt-i-bruttoresultat-om-245-procent-och-kraftigt-forbattrat-rorelseresultat/) (2018-11-23)
- [Tre nya kunder till Nepa – Wolt, Clas Ohlson och Maria Nila inleder insiktssamarbeten](https://nepa.com/mfn_news/tre-nya-kunder-till-nepa-wolt-clas-ohlson-och-maria-nila-inleder-insiktssamarbeten/) (2025-06-25)
- [Tusentals TikTok-annonser i ny AI-analys. Nu lanserar Nepa Creative AI för kreativ optimering](https://nepa.com/mfn_news/tusentals-tiktok-annonser-i-ny-ai-analys-nu-lanserar-nepa-creative-ai-for-kreativ-optimering/)
- [Valberedning utsedd inför årsstämman 2024](https://nepa.com/mfn_news/valberedning-utsedd-infor-arsstamman-2024/) (2024-01-08)
- [Valberedning utsedd inför årsstämman 2025](https://nepa.com/mfn_news/valberedning-utsedd-infor-arsstamman-2025/) (2024-12-17)
- [Valberedning utsedd inför årsstämman 2026 i Nepa AB (publ)](https://nepa.com/mfn_news/valberedning-utsedd-infor-arsstamman-2026-i-nepa-ab-publ/) (2025-10-28)
- [Vi möter Jakob Kofoed – Nepas nya CTO som ska ta techplattformen till nästa nivå](https://nepa.com/mfn_news/vi-moter-jakob-kofoed-nepas-nya-cto-som-ska-ta-techplattformen-till-nasta-niva/) (2024-11-04)

#### Swedish (sv) pages — already live, not previously covered (46)

- [Nepa – Growth by insights](https://nepa.com/sv/)
- [AI påverkar hur svenskar fattar köpbeslut](https://nepa.com/sv/blog/ai-paverkar-hur-svenskar-fattar-kopbeslut-och-forandrar-kundresan/)
- [Varumärkesspårning – Mät och stärk ditt varumärke](https://nepa.com/sv/blog/brand-tracking/)
- [Ta tillbaka positionen i en pressad dagligvarumarknad.](https://nepa.com/sv/blog/coop-sa-anvander-vi-data-for-att-ta-tillbaka-positionen/)
- [De tre vanligaste orsakerna till att kampanjer underlevererar](https://nepa.com/sv/blog/de-tre-vanligaste-orsakerna-till-att-kampanjer-underlevererar/)
- [Hemköp och Nepa på scen under EFFDay 2025](https://nepa.com/sv/blog/hemkop_och_nepa_effday2025/)
- [Långsiktigt varumärkesbygge i praktiken](https://nepa.com/sv/blog/langsiktigt-varumarkesbygge-i-praktiken/)
- [Lantmännen: Långsiktigt varumärkesbygge](https://nepa.com/sv/blog/lantmannen-langsiktigt-varumarkesbygge-fran-jord-till-bord-till-lange-leve-jorden/)
- [Marknadsföringsmix-modellering – Analys & insikter NEPA](https://nepa.com/sv/blog/marknadsforingsmix-modellering/)
- [Marknadssegmentering & mixmodellering](https://nepa.com/sv/blog/marknadssegmentering-marknadsforingsmixmodellering/)
- [När en gul prick slår miljonkontrakt](https://nepa.com/sv/blog/nar-en-gul-prick-slar-miljonkontrakt/)
- [Path to Purchase – så optimerar Nepa kundresan med data](https://nepa.com/sv/blog/path-to-purchase/)
- [Datakvalitet – vägen till tillförlitliga marknadsinsikter](https://nepa.com/sv/blog/starkare-dataintegritet-marknadsundersokning/)
- [Varumärkeskapital: Bygg tillväxt med insikter & integritet](https://nepa.com/sv/blog/varumarkeskapital/)
- [Varumärkespositionering – särskilj ditt varumärke och väx](https://nepa.com/sv/blog/varumarkespositionering/)
- [Vinnare i 100-wattaren 2026 – när kommunikation bevisar sin effekt](https://nepa.com/sv/blog/vinnare-i-100-wattaren-2026-nar-kommunikation-bevisar-sin-effekt/)
- [SBAB transformerade sin marknadsstrategi med hjälp av Nepa](https://nepa.com/sv/case/sbab/)
- [Cookie Policy – Läs om hur NEPA hanterar cookies](https://nepa.com/sv/cookie-policy/)
- [Kontakta NEPA – Få insikter och stöd från vårt team](https://nepa.com/sv/kontakta-oss/)
- [Nepa Kundcase](https://nepa.com/sv/kundcase/)
- [Kundcase Fastighetsbyrån – Tillväxt genom NEPA-insikter](https://nepa.com/sv/kundcase/fastighetsbyran/)
- [Kundcase Fjällräven](https://nepa.com/sv/kundcase/fjallraven/)
- [Kundcase GANT](https://nepa.com/sv/kundcase/gant/)
- [If Insurance](https://nepa.com/sv/kundcase/if-insurance/)
- [Kundcase Kellogg’s](https://nepa.com/sv/kundcase/kelloggs/)
- [Kundcase klädkedja](https://nepa.com/sv/kundcase/mode/)
- [Kundcase MTV](https://nepa.com/sv/kundcase/mtv/)
- [Kundcase Sinebrychoff](https://nepa.com/sv/kundcase/sinebrychoff/)
- [Kundcase Telenor](https://nepa.com/sv/kundcase/telenor/)
- [Kundcase Varner – Hur NEPA hjälpte stärka varumärket](https://nepa.com/sv/kundcase/varner/)
- [Lösningar](https://nepa.com/sv/losningar/)
- [Kampanjmätning](https://nepa.com/sv/losningar/kampanjmatning/)
- [Marknadsstrategi](https://nepa.com/sv/losningar/marknadsstrategi/)
- [Mediastrategi](https://nepa.com/sv/losningar/mediastrategi/)
- [Varumärkesutveckling](https://nepa.com/sv/losningar/varumarkesutveckling/)
- [Visuell effekt](https://nepa.com/sv/losningar/visuell-effekt/)
- [MFN News Items Archive](https://nepa.com/sv/mfn_news/)
- [Ny kund | Avanza väljer Nepa för löpande brand tracking](https://nepa.com/sv/news/avanza/)
- [Enklare.se väljer Nepa](https://nepa.com/sv/news/enklare-se-chooses-nepas-continuous-brand-tracking-to-strengthen-its-brand-for-long-term-success/)
- [Ny kund | Haglöfs väljer Nepa för att stärka sin varumärkesstrategi](https://nepa.com/sv/news/haglofs/)
- [Lundhags väljer Nepa](https://nepa.com/sv/news/tydlighet-i-varje-steg-lundhags-valjer-nepa-for-att-starka-sin-varumarkesresa/)
- [Om oss](https://nepa.com/sv/om-nepa/)
- [Produkter](https://nepa.com/sv/produkter/)
- [Brand Tracking med NEPA – Mät och stärk ditt varumärke](https://nepa.com/sv/produkter/brand-tracking/)
- [Campaign Pulse – Mät kampanjens effekt med NEPA](https://nepa.com/sv/produkter/campaign-pulse/)
- [Marketing Mix Modelling – Optimera ROI med NEPA](https://nepa.com/sv/produkter/marketing-mix-modelling/)

#### Finnish (fi) pages — already live, not previously covered (22)

- [Nepa | Kasvua oivalluksilla markkinatutkimuksen avulla](https://nepa.com/fi/)
- [Miten Vahvistaa Brändiä Ilman Uutta Logoa?](https://nepa.com/fi/case/atria/)
- [Näin Fastighetsbyrån paransi brändistrategiaansa](https://nepa.com/fi/case/fastighetsbyran/)
- [Oivalluksista vaikutukseksi yhdessä Fjällrävenin kanssa](https://nepa.com/fi/case/fjallravenin/)
- [GANT luottaa Nepaan kasvun vauhdittamisessa](https://nepa.com/fi/case/gant/)
- [Kellogg's luottaa Nepaan pysyäkseen relevanttina](https://nepa.com/fi/case/kelloggs/)
- [MTV luottaa Nepaan tv-mainonnan vaikutukseen](https://nepa.com/fi/case/mtv/)
- [Muotikauppiaan matka: Haasteesta menestykseen](https://nepa.com/fi/case/muotialan-jalleenmyyjan-matka/)
- [Sinebrychoff ja Nepa valaisevat ostopolun](https://nepa.com/fi/case/sinebrychoff/)
- [Telenor luottaa Nepaan liiketoiminta-analytiikassa](https://nepa.com/fi/case/telenor/)
- [Varner luottaa Nepaan potentiaalinsa vapauttamisessa](https://nepa.com/fi/case/varner/)
- [MFN News Items Archive](https://nepa.com/fi/mfn_news/)
- [Tuotteet](https://nepa.com/fi/products/)
- [Brändiseurantaratkaisut](https://nepa.com/fi/products/brand-tracking/)
- [Kampanjapulssi](https://nepa.com/fi/products/campaign-pulse/)
- [Myynninmallinnus](https://nepa.com/fi/products/myynninmallinnus/)
- [Ratkaisut](https://nepa.com/fi/solutions/)
- [Brändin kehittäminen](https://nepa.com/fi/solutions/brandin-kehittaminen/)
- [Kampanjamittaus](https://nepa.com/fi/solutions/kampanjamittaus/)
- [Markkinointistrategia](https://nepa.com/fi/solutions/markkinointistrategia/)
- [Mediastrategia](https://nepa.com/fi/solutions/mediastrategia/)
- [Vaikuttavaa visuaalisuutta](https://nepa.com/fi/solutions/vaikuttavaa-visuaalisuutta/)

#### Danish (da) pages — already live, not previously covered (20)

- [Nepa | Vækst gennem indsigt via markedsanalyse](https://nepa.com/da/)
- [En modedetailists rejse: Fra udfordring til gennembrud](https://nepa.com/da/case/fashion/)
- [Fra spredte indsigter til tydelig strategi](https://nepa.com/da/case/fastighetsbyran/)
- [Fjällräven Case Study – Brand Tracking](https://nepa.com/da/case/fjallraven/)
- [GANT vækster globalt med Nepa](https://nepa.com/da/case/gant/)
- [Kellogg’s stoler på Nepa for at forblive relevant](https://nepa.com/da/case/kelloggs/)
- [MTV bruger Nepa til at dokumentere effekten af tv-reklamer](https://nepa.com/da/case/mtv/)
- [Sinebrychoff & Nepa kaster lys over kunderejsen i butik](https://nepa.com/da/case/sinebrychoff/)
- [Telenor vælger Nepa for indsigt med forretningsværdi](https://nepa.com/da/case/telenor/)
- [Varner samarbejder med Nepa for at forløse sit potentiale](https://nepa.com/da/case/varner/)
- [Alle Produkter](https://nepa.com/da/products/)
- [Brand Tracking Løsninger](https://nepa.com/da/products/brand-tracking/)
- [Kampagneindsigt](https://nepa.com/da/products/campaign-evaluation/)
- [Marketing Mix Modelling-løsninger](https://nepa.com/da/products/continuous-marketing-mix-modelling/)
- [Løsninger](https://nepa.com/da/solutions/)
- [Brandudvikling](https://nepa.com/da/solutions/brandudvikling/)
- [Kampagnemaling](https://nepa.com/da/solutions/kampagnemaling/)
- [Marketingstrategi](https://nepa.com/da/solutions/marketingstrategi/)
- [Mediestrategi](https://nepa.com/da/solutions/mediestrategi/)
- [Visuel performance](https://nepa.com/da/solutions/visuel-performance/)

### Pages updated

#### Substantive changes (6)

- [Investor Relations & Financial Info](https://nepa.com/investor-relations/) — last modified 2026-10-08. Leads with the CEO departure release; package adds recent_announcements, next report (Q3 on 2026-10-23), investor contacts and Certified Adviser.
- [Nepa | Growth by insights through marketing research](https://nepa.com/) — last modified 2026-09-18. Homepage now features the two new blog posts; stats unchanged (50+ markets, 300+ clients, 7,500+ brands/campaigns).
- [Advertising Equity 2026](https://nepa.com/downloads/advertising-equity/) — last modified 2026-09-10. Retitled "Advertising Equity 2026"; resource entry rewritten with study scope and related content.
- [Pricing](https://nepa.com/pricing/) — last modified 2026-08-27. Package correction: v1.0 captured only the MMM tab with outdated tiers (MMM Mini/Core/Plus 4,000/5,900/6,400 EUR). Replaced with all 11 live tiers across Brand Tracking, Marketing Mix Modelling and Campaign Pulse.
- [Brand Tracking Dashboard & Insight Platform](https://nepa.com/products/brand-tracking/) — last modified 2026-08-13. Package wording: description now cites the pricing page (from 1,500 EUR / 16,300 SEK per month) alongside the product page's own "from 15,000 SEK/month".
- [Anders Dahl | CEO of Nepa](https://nepa.com/person/anders-dahl/) — last modified 2025-04-23. Person page unchanged; package adds a departure note sourced from the 2026-09-30 release.

#### Re-saved on the site since 2026-09-08; title, date, author and excerpt in the package verified unchanged (47)

Only `last_updated` changes in the package for these.

- 2026-10-09 — [Category Entry Points: Why They Matter in 2024](https://nepa.com/blog/what-are-category-entry-points-and-why-should-you-care-about-them-in-2024/)
- 2026-10-09 — [We went to Global Marketer Week 2026. Here's what stood out.](https://nepa.com/blog/we-went-to-global-marketer-week-2026-heres-what-stood-out/)
- 2026-10-09 — [Rethinking Marketing Effectiveness in 2026](https://nepa.com/blog/shrinking-budgets-bigger-results-rethinking-marketing-effectiveness-in-2026/)
- 2026-10-09 — [Former SAS CEO, on Empowered Employees and Customer-Centric Growth](https://nepa.com/blog/former-sas-ceo-jan-carlzon-on-empowered-employees-and-customer-centric-growth/)
- 2026-10-09 — [Esomar 2025 – Nepa's key take aways](https://nepa.com/blog/esomar-2025/)
- 2026-10-09 — [Brand Touch](https://nepa.com/blog/brand_touch_make_your_brand_impossible_to_ignore/)
- 2026-10-01 — [Thousands of TikTok ads in a new AI analysis. Nepa launches Creative AI for creative optimisation](https://nepa.com/mfn_news/thousands-of-tiktok-ads-in-a-new-ai-analysis-nepa-launches-creative-ai-for-creative-optimisation/)
- 2026-10-01 — [Nepa Interim Report Q2 2026](https://nepa.com/mfn_news/nepa-interim-report-q2-2026/)
- 2026-10-01 — [Nepa appoints Morten Flamand as new VP Sales to accelerate international growth](https://nepa.com/mfn_news/nepa-appoints-morten-flamand-as-new-vp-sales-to-accelerate-international-growth/)
- 2026-10-01 — [Invitation to presentation of Nepa’s Q2 interim report 2026](https://nepa.com/mfn_news/invitation-to-presentation-of-nepas-q2-interim-report-2026/)
- 2026-09-28 — [Willingness to Pay | A KPI linked to brand equity and performance](https://nepa.com/blog/willingness-to-pay/)
- 2026-09-24 — [IIEX Europe 2026](https://nepa.com/blog/iiex-europe-2026/)
- 2026-09-24 — [Assess Your Brand Strength](https://nepa.com/blog/brand-strength/)
- 2026-09-23 — [Varner chooses Nepa for their brand tracking program](https://nepa.com/blog/varner-chooses-nepa-for-their-brand-tracking-program/)
- 2026-09-23 — [Campaign Evaluation vs Brand Tracking](https://nepa.com/blog/campaign-evaluation-vs-brand-tracking-whats-the-difference/)
- 2026-09-22 — [Why Investing In Fan Insights Is a Smart Play](https://nepa.com/blog/why-investing-in-fan-insights-is-a-smart-play/)
- 2026-09-22 — [Why Continuous Brand Tracking Matters](https://nepa.com/blog/why-continuous-brand-tracking/)
- 2026-09-22 — [Value Proposition Canvas | Meaning, Benefits, & More](https://nepa.com/blog/what-is-the-value-proposition-canvas/)
- 2026-09-22 — [Brand Salience vs. Differentiation: Exploring Key Differences](https://nepa.com/blog/salience-vs-differentiation/)
- 2026-09-22 — [Paths to Purchase: Why Brands Should Care](https://nepa.com/blog/paths-to-purchase-what-is-it-and-why-should-brands-care/)
- 2026-09-22 — [Path to Purchase - learn how Nepa uses data](https://nepa.com/blog/path-to-purchase/)
- 2026-09-22 — [Path to Purchase Research Insights](https://nepa.com/blog/path-to-purchase-research/)
- 2026-09-22 — [Meet Nepa at IIEX Europe 2026 in Amsterdam](https://nepa.com/blog/meet-nepa-at-iiex-europe-2026-in-amsterdam/)
- 2026-09-22 — [What is Market Research Consulting & Analysis](https://nepa.com/blog/marketing-research-consulting/)
- 2026-09-22 — [How Brand Campaigns Drive Short & Long-Term Sales](https://nepa.com/blog/have-your-cake-and-eat-it-how-brand-building-campaigns-can-drive-both-short-and-long-term-sales/)
- 2026-09-22 — [Guide: Building a CEP-Centric Marketing Strategy](https://nepa.com/blog/guide-to-building-a-cep-centric-marketing-strategy/)
- 2026-09-22 — [Generation Alpha - understanding Generation Alpha Consumers](https://nepa.com/blog/generation-alpha/)
- 2026-09-22 — [Create a Brand Identity That Resonates](https://nepa.com/blog/creating-a-brand-identity-that-resonates-with-your-audience/)
- 2026-09-22 — [Understanding Brand Associations](https://nepa.com/blog/brand-associations/)
- 2026-09-18 — [What Is Brand Tracking & Why It Matters](https://nepa.com/blog/what-is-brand-tracking-and-why-is-it-important/)
- 2026-09-18 — [Marketing Mix Modelling for FMCG](https://nepa.com/blog/marketing-mix-modelling-mmm/)
- 2026-09-18 — [Market segmentation and marketing mix modelling](https://nepa.com/blog/market-segmentation-and-marketing-mix-modeling/)
- 2026-09-18 — [influencer marketing’s real impact](https://nepa.com/blog/illuminating-the-intangible-understanding-influencer-marketings-real-impact/)
- 2026-09-18 — [Vanessa Lindblad on Caia's Growth Journey](https://nepa.com/blog/from-challenger-to-category-leader-vanessa-lindblad-on-caias-growth-journey/)
- 2026-09-18 — [Brand research: What is it & why is it important?](https://nepa.com/blog/brand-research-what-is-it-why-is-it-important/)
- 2026-09-18 — [Brand Personality FAQs Answered](https://nepa.com/blog/brand-personality-faq/)
- 2026-09-18 — [Brand Performance: Importance & Strategies](https://nepa.com/blog/brand-performance-explained/)
- 2026-09-18 — [What Is Brand Health Tracking? Key Metrics & Benefits](https://nepa.com/blog/brand-health-tracking/)
- 2026-09-18 — [Brand equity: The engine driving growth for modern brands](https://nepa.com/blog/brand-equity/)
- 2026-09-09 — [Identifying Whitespace Opportunities](https://nepa.com/blog/whitespace-opportunities/)
- 2026-09-09 — [What Is Brand Value? Definition, Examples & How to Build It](https://nepa.com/blog/what-is-brand-value/)
- 2026-09-09 — [Mango teams with Nepa for brand tracking and campaign measurement](https://nepa.com/blog/mango-chooses-nepa/)
- 2026-09-09 — [Step-by-Step Guide: Measuring Advertising Effectiveness](https://nepa.com/blog/how-to-measure-advertising-effectiveness/)
- 2026-09-09 — [What is Brand Tracking? The Complete Guide for CMOs](https://nepa.com/blog/brand-tracking/)
- 2026-09-09 — [Nepa's brand tracking dashboard](https://nepa.com/blog/brand-tracking-dashboard/)
- 2026-09-09 — [Brand Positioning: Strategy, Examples & How to Define Yours](https://nepa.com/blog/brand-positioning/)
- 2026-09-08 — [What is Marketing Mix Modelling? The Complete Guide](https://nepa.com/blog/what-is-marketing-mix-modelling-the-complete-guide/)

### Pages removed or redirected (8)

| URL | Live status (2026-10-16) | Package action |
|---|---|---|
| https://nepa.com/blog/why-brand-salience-is-the-new-growth-driver/ | 404 Not Found | removed from blog-posts.json, agent page and page index |
| https://nepa.com/blog/is-your-brand-a-hero-or-villain/ | 301 → https://nepa.com/ | removed from blog-posts.json and agent page |
| https://nepa.com/events/ | 404 Not Found | events archive link replaced by /sessions/ |
| https://nepa.com/webinar/ | 301 → an image file (/wp-content/uploads/2021/11/webinar.png) | webinar archive link replaced by /sessions/ |
| https://nepa.com/news/ | 301 → https://nepa.com/newsletter/ | company-news archive link replaced by /newsroom/ |
| https://nepa.com/the-brands-that-grow-tomorrow-are-already-measuring-differently-today/ | 301 → https://nepa.com/blog/the-brands-that-grow-tomorrow-are-already-measuring-differently-today-2/ | removed: it was a duplicate of the post already listed at the redirect target |
| https://nepa.com/commercial-pr/ | 301 → https://nepa.com/newsroom | not linked in v1.1 |
| https://nepa.com/paths-to-purchase/ | 301 → https://nepa.com/products/Path-to-Purchase | package uses https://nepa.com/products/path-to-purchase/ |

### Live pages deliberately excluded (5)

These are indexable on nepa.com but look like duplicates or internal pages. Recommend Nepa noindexes or removes them.

- https://nepa.com/contact-us-2/ — duplicate of /contact-us/
- https://nepa.com/contact-us-2-2/ — duplicate of /contact-us/
- https://nepa.com/internal_nepa/ — internal copy of the contact page
- https://nepa.com/wp/ — empty embed page ("Loading…")
- https://nepa.com/case-category/case-category/ — empty placeholder taxonomy archive

### Issues found on the live site (for Nepa — not package changes)

- **AI-training policy conflict:** live robots.txt says `Content-Signal: ai-train=no`, while the deployed ai.txt says `Allow-Training: yes`. Nepa decided to allow training, so the live robots.txt `Content-Signal` line must change to `ai-train=yes` when this package is deployed.
- **Cloudflare blocks some agents:** requests to `/agent/` and `/agent/v1/*` with a bare or generic user-agent got a Cloudflare 403 "Sorry, you have been blocked" page; `GPTBot/1.0` got 429. Browser user-agents, ClaudeBot and Node `fetch` (used by the MCP server) got through. Consider a Cloudflare WAF skip rule for `/agent/*`, `/openapi.json`, `/api-catalog.json`, `/llms.txt` and `/ai.txt`.
- **Trailing-slash redirects:** `/agent/v1/*.json` and `/openapi.json` 301 to a trailing-slash URL; fixed by plugin 1.2.0 after deployment.
- **Sitemap lists dead or redirecting URLs:** page-sitemap.xml includes https://nepa.com/fi/vaikuttamisen-aika-mika-ohjaa-suomalaisten-ostopaatoksia/ (404; also declared as an hreflang alternate), https://nepa.com/commercial-pr/ (301 → /newsroom) and https://nepa.com/paths-to-purchase/ (301 → /products/Path-to-Purchase). Remove them from the sitemap or fix the targets.
- **Footer link goes to Danish:** the footer "Marketing Mix Modelling" link (/products/continuous-marketing-mix-modelling/) redirects to the Danish product page; the English page is /products/marketing-mix-modeling/.
- **Brand Tracking price inconsistency:** product-page text and FAQ say "from 15.000 SEK/month"; the price widget shows 16,300 SEK (1,500 EUR)/month.
- **Blog author account:** "Research should shape brand strategy, not rescue it" is attributed to the CMS account `gomo_dev` in its structured data, while the visible byline is Dora Harfman Bromée. One Swedish post is attributed to an agency account and is listed with no author in the package.

### Review fixes (2026-10-16, after build)

A second review of the build found and fixed:

- **Duplicate blog post:** rewriting the redirected root URL of "The brands that grow tomorrow are already measuring differently today" to its target duplicated a post already in the data. The duplicate is removed; blog totals are now 261 (246 English, 15 Swedish) everywhere.
- **Wrong language pair:** the 2018-05-31 English release on Jan Carlzon and Annika Steiber joining the board had been linked to the Swedish AGM report from the same day. The link is removed. The remaining 102 pairs were checked against each other's titles and are true translations.
- **Sync tool – language override:** the hand-corrected language now applies only to releases flagged `language_override: true` (Svenska Spel). Before, every recorded language would have overridden later tag fixes on the live site.
- **Sync tool – events:** events without status/date/location meta in WordPress kept being rewritten as "on-demand" with no date. The sync now keeps recorded values (and marks past "upcoming" events as past).
- **Sync tool – case studies:** now writes `date_published` from WordPress.
- **Language enums:** OpenAPI and the MCP server accepted only `en`/`sv`, but the sync tool can emit `fi`/`da`/`no`. Enums widened; MCP server rebuilt.
- **Stale counts in api-catalog.json:** endpoint descriptions still said 19 case studies, 27 events, 247 posts, 135 English-only releases, 32 resources and "18 paths, 39 schemas". Corrected.
- **llms.txt:** Advertising Equity was listed as a product; it is a Brand Tracking measure and is now described there.
- **Event title:** the Stockholm 12 Nov event now uses the page's own Swedish title.
- **Text artifacts:** 45 excerpts/summaries (most inherited from v1.0) had stray whitespace, byte-order marks, `**` markdown or a broken embedded-video link. Cleaned in the data and the agent page.

### Sitemap cross-check (2026-10-16, after build)

All 784 URLs in https://nepa.com/sitemap_index.xml (781 `<loc>` entries across 12 child sitemaps + 3 hreflang-only alternates) were compared with the package. 776 are in the deployable assets (agent page and/or REST data). The other 8 are the 5 excluded duplicate/internal pages and the 3 dead or redirecting sitemap entries above (the package uses the redirect targets). All 67 sitemap URLs modified since 2026-09-08 appear in this changelog, and all 540 URLs from the previous package are either kept or listed under Pages removed. The sitemap was unchanged between the crawl and this check.

### Second-opinion review (Codex) and fixes — 2026-10-16

An independent review raised 22 items. Each was checked against the code; verdicts and actions:

| # | Finding | Verdict | Action |
|---|---|---|---|
| 1 | Activation flushes rewrite rules before registering them | Confirmed | Activation now registers the rules, then flushes (tested). |
| 2 | Sync wipes curated fields (team status notes, product categories, case-study client/industry/service, summaries) | Confirmed | Sync merges into curated items: WordPress sets id/title/url/date; curated values win elsewhere (tested). |
| 3 | Sync drops landing-page entries (Nepa Trinity, BrandGrow, Marli Juissi case) | Confirmed | Entries flagged `"source": "curated"` are kept (tested). |
| 4 | Blog sync may drop Swedish posts | Plausible (depends on translation plugin) | Sync now also requests `?lang=` and `?wpml_language=`; a language that comes back empty is kept, with a warning. Verify on staging (DEPLOYMENT.md §1.7). |
| 5 | `?service=Brand Tracking` returns 0 | Confirmed | Filters match any entry of a comma-separated list; Fortnox's "Continuous Brand Tracking" normalised, so the filter returns all 10 (tested). `segment`/`category` are documented as mostly empty. |
| 6 | MCP rate limit behind Render's proxy; WordPress behind Cloudflare | Confirmed | `trust proxy` (env `TRUST_PROXY`, default 1); `NEPA_TRUST_CLOUDFLARE_IP` documented as a deployment step. |
| 7 | Contact throttling: race, window extension, email quota consumed on rejection | Confirmed | Fixed windows; quota consumed only after all checks (shared bucket first, then email); atomic counters when a persistent object cache exists. Without one a small race remains (documented). |
| 8 | No type/shape/size validation on contact input | Confirmed | Body ≤ 20,000 bytes (413), object/string type checks (400), field length limits (422), Zod limits in MCP (tested). |
| 9 | "Received" reported without a stored record | Confirmed | Acceptance now requires the stored lead; otherwise 503 `not_recorded` (retry-safe). Failed notifications are retried hourly, up to 6 attempts (tested). |
| 10 | Retries create duplicate leads | Confirmed | `idempotency_key` (24 h) and identical-submission detection (30 min) return the original lead with `duplicate: true` (tested). |
| 11 | robots allows training crawlers while ai.txt says no training | Confirmed contradiction | Resolved by Nepa's decision to allow AI training: ai.txt `Allow-Training: yes` and `Content-Signal: ai-train=yes` in ai.txt and robots-addition.txt; all crawlers stay allowed. |
| 12 | No full deployment procedure; Render root-dir mismatch | Confirmed | New `DEPLOYMENT.md` (backup, staging, wp-config settings, file conflicts, writable folder, caches/WAF, checks, rollback, monitoring); README explains both repo layouts. |
| 13 | Freshness fragmented across assets | Valid (by design) | Documented which assets Sync updates and which need a package rebuild (DEPLOYMENT.md §5); Sync screen says so. |
| 14 | Mutable data inside the plugin folder | Confirmed | Sync writes to `wp-content/uploads/nepa-agent-api/`. Precedence is set by an explicit release manifest (see the follow-up review below) (tested). |
| 15 | Sync not transactional, no lock, no byte check | Confirmed | All-or-nothing commit, lock, full-byte write check, and a guard refusing to shrink a list by more than half (tested). |
| 16 | Same-day translation pairing unreliable | Partly (all 102 current pairs were checked by hand and are correct) | Current pairs marked `alternate_language_verified: true` and kept by Sync; new same-day suggestions also require same category and regulatory flag and are marked unverified. |
| 17 | OpenAPI incomplete for contact errors and pagination bounds | Confirmed | 400/403/405/413/422/429/503 documented; idempotency, `duplicate`, field limits, pagination min/max, filter semantics added. |
| 18 | Brand Tracking price inconsistent (JSON-LD, catalog) | Confirmed | JSON-LD Service offer from 1,500 EUR/month; FAQ answer carries the caveat; catalog pricing descriptions corrected. |
| 19 | `/health` checks only the process | Confirmed | `/health?deep=1` checks the WordPress API too; monitoring guidance in DEPLOYMENT.md §8. |
| 20 | Lead-data lifecycle undefined | Confirmed | Consent assertion, channel and hashed IP stored per lead; optional `NEPA_LEAD_RETENTION_DAYS` purge. Nepa chose manual deletion (default 0). |
| 21 | MCP cleanup on disconnect, annotations, CORS | Confirmed | Cleanup on `close`; read-only/write annotations; CORS for browser clients (tested). |
| 22 | Outdated sync text; no version requirements | Confirmed | Sync screen text updated; plugin requires WordPress 6.0 / PHP 7.4; MCP requires Node 20 (corrected from 18.17 in the follow-up review). |
| — | Dependency audit not run | Done | `npm audit` found 7 issues (1 critical `proxy-addr`, 1 high MCP SDK); fixed with non-breaking updates - 0 vulnerabilities. |

**How this was tested.** See the follow-up review below - the tests now ship in `tests/` and can be re-run.

### Follow-up review (Codex, second pass) and fixes — 2026-10-16

| Finding | Verdict | Action |
|---|---|---|
| [P1] Reusing an idempotency key with different details returned "already received" without saving the new inquiry | Confirmed | The reservation stores the content fingerprint; same key + different content now returns **409 `idempotency_key_reused`** and nothing is stored. |
| [P2] Duplicate check and lead creation weren't atomic | Confirmed | Each submission first **reserves** its key (or fingerprint) with a plain `INSERT` into `wp_options`, whose unique `option_name` makes the second of two concurrent requests fail. `add_option()` was deliberately not used: it does `INSERT … ON DUPLICATE KEY UPDATE`, so both callers would "succeed". A request still in flight makes repeats get **409 `in_progress`**; a finished one returns the original lead (`duplicate: true`); a storage failure releases the reservation so a retry works. Expired reservations are cleaned up daily. |
| [P2] Exhausted notifications could crowd pending ones out of the retry batch | Confirmed | The retry query now filters `mail_attempts < 6` and `mail_sent = 0` itself, oldest first, and skips leads under 5 minutes old. |
| [P2] Metadata writes unchecked | Confirmed | Idempotency no longer depends on post meta (it uses the reservation). Retry state (`mail_sent`, `mail_attempts`) is written before sending and verified by reading it back, with one retry; failures are logged. The notification is rebuilt from the stored post, so it doesn't depend on extra meta. Delivery is at-least-once: if "sent" can't be recorded, a duplicate notification is preferred over a lost one. |
| [P2] CORS didn't allow `Idempotency-Key` | Confirmed | Added `Accept` and `Idempotency-Key` to `Access-Control-Allow-Headers`. |
| [P2] Node 18.17 promised, but a locked dependency needs Node 20 | Confirmed | Minimum raised to Node 20 in package.json, README and DEPLOYMENT.md. (Node 18 is also past end of life.) |
| [P2] Retention purge skipped trashed leads | Confirmed | The purge lists every status explicitly, including `trash`. |
| [P2] Data precedence depended on file timestamps | Confirmed | Replaced with an explicit rule. Bundled data carries `meta.release` = `NEPA_DATA_RELEASE`; Sync writes `manifest.json` naming the release it was built on. Synced files are served only when the manifest's release matches the installed one. |
| Harness not in the package | Confirmed | Tests now ship in `tests/`: `run-plugin-tests.php` (52 checks, WordPress stubbed, including a fake unique-key `wp_options`) and `mcp-smoke-test.sh` (12 checks including `npm audit` and a type check). Both pass. |

OpenAPI now documents 409 (both codes), the `Idempotency-Key` header and the clarified 503.

Still required, and not something these tests can show: a real WordPress staging run (MySQL concurrency, WP-Cron, SMTP, the translation plugin's REST behaviour, Cloudflare), per DEPLOYMENT.md §6.

### Follow-up review (Codex, third pass) and fixes — 2026-10-16

| Finding | Verdict | Action |
|---|---|---|
| [P1] Two requests reading the same expired reservation could both succeed (the second deleted the first's new reservation) | Confirmed | Every reservation value carries a random token, and every change is now compare-and-swap. Replacing an expired reservation, completing it, releasing it and the daily cleanup all match the option name **and** the exact value read or written. The losing request's delete matches nothing; it re-reads and gets the winner's reservation. |
| [P2] A database failure while reserving was reported as 409 `idempotency_key_reused` | Confirmed | A failed INSERT with no existing row is treated as a storage failure, retried, then answered with **503 `reservation_unavailable`** (retry-safe). The fabricated empty reservation is gone. |
| [P2] A lead whose retry state couldn't be stored, and whose first email failed, was never retried | Confirmed | The retry job selects every lead **not confirmed as sent** (`mail_sent` missing or not `1`; `mail_attempts` missing or below 6), within a 7-day window. That window also bounds re-sending if the attempt counter itself can't be stored. |
| [P2] The MCP smoke test ran inside the package (`npm ci`, rebuilt `dist`, deleted an existing `node_modules`) | Confirmed | The test copies `nepa-mcp/` to a temporary workspace and works only there. A final check confirms the package folder is byte-for-byte unchanged. A pre-existing `node_modules` was verified to survive. |

New regression tests replay each defect: the expired-reservation race (with a one-shot stale read), a database failure on insert, lost retry state plus a failed email, the 7-day bound, and a cleanup race. **Mutation check:** with each original defect put back into a throwaway copy of the plugin, the matching tests fail (4/4 defects caught). Results: plugin 60/60, MCP 13/13.

### Open decisions (Nepa)

1. ~~AI training and crawlers~~ — **decided 2026-10-16: allow AI training.** All crawlers stay allowed; ai.txt and the Content-Signal line say `ai-train=yes`. Remaining action: change the live robots.txt line from `ai-train=no` to `ai-train=yes`.
2. ~~Lead retention period~~ — **decided 2026-10-16: manual deletion** (`NEPA_LEAD_RETENTION_DAYS` stays 0).
3. **Cloudflare** - now a technical check, not a decision (DEPLOYMENT.md §10.2): agents must reach the API unchallenged, and the origin must not be directly reachable before `NEPA_TRUST_CLOUDFLARE_IP` is enabled.

Staging status (2026-10-16): scheduled jobs, email delivery and multilingual Sync are confirmed. Remaining technical checks: concurrent duplicate submissions and Cloudflare (DEPLOYMENT.md §10, `tests/staging-checks.sh`).

Edge baseline (read-only check against live nepa.com, 2026-10-16). nepa.com is on **WP Engine** (IPs 141.193.213.10/11, WPEngine, Inc.) behind its Cloudflare-based edge.
- GPTBot and ClaudeBot got **429** on `/llms.txt`, `/openapi.json` and the API after one or two requests.
- A generic `python-requests` client got **403** everywhere.
- The JSON endpoints still return the trailing-slash 301 from plugin 1.1.0, which plugin 1.2.0 fixes.

Exemptions must be requested through WP Engine. On WP Engine, `REMOTE_ADDR` is normally the real client IP, so `NEPA_TRUST_CLOUDFLARE_IP` will most likely stay off (DEPLOYMENT.md §10.2).

### Deployment

See **DEPLOYMENT.md** for the full procedure, checks and rollback.

## v1.0 — 2026-09-09

Initial package (folder `Original/`): crawl of 2026-09-08 covering 540 page URLs (534 English, 6 Nordic), 16 REST data files, OpenAPI 1.0.0, MCP server 1.0.0 with 17 tools.
