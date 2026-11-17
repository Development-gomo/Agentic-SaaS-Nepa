# Changelog — Nepa Agentic Web package

## Fixes after the live audit — 2026-11-17 (discovery plugin 1.2.0, OpenAPI 1.4.0)

Five fixes from an audit of the live site after the 2026-11 deployment. Deployment steps: DEPLOYMENT.md section 14.

- **Markdown versions had no content.** `?format=md` (nepa-agent-discovery.php) converted the post's stored `post_content`, which on nepa.com's Enfold theme holds little more than layout shortcodes, so pages came out as front matter and a title. 1.2.0 renders the page through its normal template and converts the content area (from the element with id `main` up to the site footer; Enfold places most sections after `</main>`), leaving out header, menus, footer, scripts, styles, iframes, forms and buttons, and the H1 that repeats the title. On 10 real pages it keeps 99.7-100% of the visible content text. The cache key includes the plugin version, so entries from 1.1.0 are not reused. New tests: `tests/run-markdown-tests.php` (22 checks, with an Enfold-shaped fixture and a real HTTP probe).
- **OpenAPI version out of step.** `openapi.json` said 1.3.0 while the plugin is 1.4.0; it is now 1.4.0 (ARD entry, agent page and DEPLOYMENT.md follow). The `/pricing.json` summary named products no longer on the pricing page; it now lists Brand Tracking, Marketing Mix Modelling and Campaign Pulse.
- **New `llms-full.txt`** (about 240 KB): the full plain-text reference - company, leadership, offices, products, pricing table, solutions, industries, case studies with results, investor relations, events, FAQ, every blog post, press release and localized page with its source URL. Generated from the data files by the new `tools/build-llms-full.py` (`--check` mode runs in `tests/run-all.sh`), so it cannot drift from the API. Linked from llms.txt, ai.txt (`LLM-Full:`), api-catalog.json, the agent page and every page's `<head>`.
- **robots.txt:** removed `User-agent: Googlebot-Extended` (not a real token; `Google-Extended` stays). **Yoast SearchAction removed:** it pointed agents to `/?s=`, which robots.txt disallows; the plugin now switches off Yoast's SearchAction JSON-LD.
- **New `/.well-known/security.txt`** (RFC 9116, also at `/security.txt`): `Contact: mailto:security@nepa.com`, the PGP key fingerprint, `Policy: https://nepa.com/security/`, `Expires` 2027-11-15 (constant `NEPA_AD_SECURITY_EXPIRES`; renew at each build).

Also still to deploy from the 2026-11-16 fix commit: `agent-page.html` (three restored discovery links) and `data/localized-pages.json` (`alternates` null instead of `{}`).

## agentic-web-2026-11 — 2026-11-16

Rebuilt from a fresh crawl of the live https://nepa.com on 2026-11-16 and compared with the previous package (`agentic-web-2026-10`, crawled 2026-10-16, deployed and live on 2026-11-16). This release also adds **Agentic Resource Discovery (ARD)** support.

**Crawl method.** All 800 URLs in https://nepa.com/sitemap_index.xml (12 child sitemaps, including 3 hreflang-only alternates) plus every page URL in the previous package: 812 URLs, each status-checked without following redirects (811 × 200, 1 × 404, no redirects). The raw HTML of all 811 live pages was fetched for titles, dates, authors, hreflang, navigation and JSON-LD. 532 pages were scraped with the Firecrawl API (batch scrape, `maxAge: 0`, all succeeded); the other 279 are historical press releases whose sitemap `lastmod` predates the last build, checked from their HTML. Every package field (titles, dates, authors, prices, figures, contacts) was compared with the live pages. A **second review** the same day (see below) also compared every item the WordPress REST API publishes (posts, pages, press releases, insight, report and download types, in all four languages) and every internal link found on the crawled pages; it found 12 more pages and a bug in the live discovery plugin.

**Versions.** Data files v1.1 → v1.2 (release `agentic-web-2026-11`) · Nepa Agent API WordPress plugin 1.3.0 → 1.4.0 · OpenAPI 1.2.0 → 1.3.0 · MCP server 1.2.0 → 1.2.1 · Nepa Agent Discovery must-use plugin 1.0.0 → 1.1.0 · new: Agentic Resource Discovery module 1.0.0.

### Summary

| | Count |
|---|---|
| Pages added to the package | 36 |
| — newly published on the site since the last build | 32 |
| — already live but not previously covered | 4 |
| Pages updated (substantive) | 12 |
| Pages re-saved on the site, package fields verified unchanged | 142 |
| Pages removed (404) | 1 |
| Live pages deliberately excluded | 20 |

Totals now: 266 blog posts (250 English, 16 Swedish), 293 press releases (139 English, 154 Swedish), 21 case studies, 30 events, 37 resources, 107 localized pages (49 sv, 35 fi, 23 da), 18 financial reports.

### Pages added

#### Newly published on nepa.com since the last build (32)

English blog posts (3):
- 2026-11-13 — [The changing path to purchase: lessons from Nepa and Lyko](https://nepa.com/blog/changing-path-to-purchase-nepa-breakfast-lyko/) (Christoffer Steiser)
- 2026-11-12 — [Planning for impact: Highlights from Nepa’s Helsinki Breakfast Session](https://nepa.com/blog/planning-for-impact-helsinki-breakfast-session/) (Christoffer Steiser)
- 2026-11-04 — [Nepa’s perspective: Black Week 2026 – advertise smart, measure everything](https://nepa.com/blog/black-week-2026-tips-for-advertisers/) (Christoffer Steiser in the structured data; the visible byline shows Ulrika Berg, Marketing Director)

Case study (1):
- 2026-11-06 — [Using data to sharpen a specialist brand in a crowded market (Laattapiste-Pukkila)](https://nepa.com/case/laattapiste-pukkila/) — booked customer appointments +30%

Press releases, English (4):
- 2026-11-12 — [New Nordic study from Nepa: Never used an AI chatbot? For one in three “non-users”, AI is still part of the shopping journey](https://nepa.com/mfn_news/new-nordic-study-from-nepa-never-used-an-ai-chatbot-for-one-in-three-non-users-ai-is-still-part-of-the-shopping-journey/)
- 2026-10-23 — [Nepa Interim Report Q3 2026](https://nepa.com/mfn_news/nepa-interim-report-q3-2026/) (regulatory)
- 2026-10-19 — [Nepa appoints media industry leader Andrea Sahlgren as VP Sales](https://nepa.com/mfn_news/nepa-appoints-media-industry-leader-andrea-sahlgren-as-vp-sales/) (no Swedish version published)
- 2026-10-16 — [Invitation to presentation of Nepa’s Q3 interim report 2026](https://nepa.com/mfn_news/invitation-to-presentation-of-nepas-q3-interim-report-2026/)

Press releases, Swedish (3) — each linked to its English version (`alternate_language_url`, verified by title):
- 2026-11-12 — [Ny nordisk studie från Nepa: Aldrig använt en AI-chatt? För en av tre ”icke-användare” är AI ändå en del av köpresan](https://nepa.com/mfn_news/ny-nordisk-studie-fran-nepa-aldrig-anvant-en-ai-chatt-for-en-av-tre-icke-anvandare-ar-ai-anda-en-del-av-kopresan/)
- 2026-10-23 — [Nepa Delårsrapport Q3 2026](https://nepa.com/mfn_news/nepa-delarsrapport-q3-2026/) (regulatory)
- 2026-10-16 — [Inbjudan till presentation av Nepas delårsrapport Q3 2026](https://nepa.com/mfn_news/inbjudan-till-presentation-av-nepas-delarsrapport-q3-2026/)

Finnish case pages (10) — the live pages declare no hreflang alternate, so the English case was matched by client name (`alternates_source` notes this):
- [Suomi esiin ruoan avulla](https://nepa.com/fi/case/business-finland/) → Business Finland
- [Vaikuttajamarkkinoinnista mitattavaa vaikutusta](https://nepa.com/fi/case/cure-media-ja-akademibokhandeln/) → Cure Media & Akademibokhandeln
- [Kriisistä selkeyteen: näin Dafgårds palautti kuluttajien luottamuksen insightien avulla](https://nepa.com/fi/case/dafgards/) → Dafgårds
- [Fortnox: tutkimustiedosta selkeä suunta](https://nepa.com/fi/case/fortnox/) → Fortnox
- [Massasta erottuvaksi: Hemköpin rohkea tie selkeään brändiin Nepan kanssa](https://nepa.com/fi/case/hemkop/) → Hemköp
- [If – Pohjoismaiden johtava vahinkovakuutusyhtiö](https://nepa.com/fi/case/if/) → If Insurance
- [Datasta suuntaa – miten erikoisliike terävöitti brändiään kilpaillulla markkinalla](https://nepa.com/fi/case/laattapiste-pukkila/) → Laattapiste-Pukkila
- [Näin Lantmännen mittaa mainonnan pitkän aikavälin arvoa](https://nepa.com/fi/case/lantmannen/) → Lantmännen (Advertising Equity)
- [Insightista vaikutukseen](https://nepa.com/fi/case/sbab/) → SBAB
- [Näin Creative AI -analytiikka paljasti Woltille jopa 40 %:n potentiaalin CPA:n laskuun TikTokissa](https://nepa.com/fi/case/wolt/) → Wolt

Page (1):
- [Brand, campaign and media insights that drive growth](https://nepa.com/insight/) — the new Insights hub (listed under Key Pages in llms.txt and the agent page's page index)

Insights topic pages (7, found in the second review; WordPress `insight` type, published 2026-11-03, not in the sitemap) — linked from the agent page's page index and llms.txt Key Pages:
- [Brand Strategy](https://nepa.com/insight/brand-strategy/) · [Marketing Mix Modelling](https://nepa.com/insight/marketing-mix-modelling/) · [Campaign Effectiveness](https://nepa.com/insight/campaign-effectiveness/) · [Creative](https://nepa.com/insight/creative/) · [Growth](https://nepa.com/insight/growth/) · [Channels](https://nepa.com/insight/channels/) · [Industry](https://nepa.com/insight/industry/)
- The existing [Brand Tracking](https://nepa.com/insight/brand-tracking/) page became one of these eight topic pages (re-saved 2026-11-03); the agent page and llms.txt now present it that way instead of as "Insights Hub".

Downloads (3, found in the second review; WordPress `download` type, not in the sitemap) — added to resources.json (37) and the agent page:
- 2026-11-11 — [Nordic Consumer Decision Study 2026 – Age of Agency](https://nepa.com/downloads/age-of-agency-nordic/) (landing page with summary; also in llms.txt)
- 2026-11-02 — [Age of Agency – Nordic Consumer Decision Study 2026](https://nepa.com/downloads/age-of-agency-sweden/) (gated form only)
- 2026-10-29 — [Creative AI](https://nepa.com/downloads/creative-ai/) (gated whitepaper form only)

Press releases: 287 + 7 new − 1 removed = 293. (The change list shown before the build said 8 new releases, 4 + 4; the Sahlgren appointment has no Swedish version, so it is 4 English + 3 Swedish.)

#### Already live, not previously covered (4)
- [Kundresan – kartlägg, optimera och skapa affärsnytta](https://nepa.com/sv/blog/kundresa/) (Swedish blog post, 2022-11-07; newly listed in the sitemap) → English version /blog/customer-journey/
- [Vaikuttamisen aika: Mikä ohjaa suomalaisten ostopäätöksiä?](https://nepa.com/fi/vaikuttamisen-aika-mika-ohjaa-suomalaisten-ostopaatoksia/) (Finnish on-demand webinar page; returned 404 at the last build, now 200) — section `webinar`
- [Why brand salience is the new growth driver](https://nepa.com/blog/why-brand-salience-is-the-new-growth-driver/) (2026-06-26; found in the second review) — removed last month because it returned 404; it is live again and linked from the new Insights pages, but is not in the sitemap and its page has no body text (title and date only), so it is listed with no author (the structured data names the CMS account `gomo_dev`) and no excerpt.
- [Newsroom (Swedish)](https://nepa.com/sv/newsroom/) (live since 2025, found in the second review; not in the sitemap, so no earlier build saw it) — Swedish news list, added to localized-pages.json as `company-news-index` with the English /newsroom/ as its equivalent.

### Pages updated

#### Substantive changes (12)
- [Investor Relations](https://nepa.com/investor-relations/) — Q3 2026 interim report published 2026-10-23 (ARR +9.7% to SEK 135.8 million, subscription revenue +14.8% to SEK 35.3 million, net sales SEK 47.6 million, adjusted EBITDA-Capex SEK 2.3 million); new Q3 2026 investor presentation; next report is now the **year-end report 2026 on 2027-02-19**. `investor-relations.json`: report added (18), `next_report`, `recent_announcements` (Q3 report, Sahlgren appointment, CEO departure), new `presentations`.
- [Nepa Breakfast Session Helsinki – Planning for impact](https://nepa.com/events/fi-nepa-breakfast-session-planning-for-impact-fueling-smarter-marketing-in-2027/) (2026-11-11) and [Nepa Breakfast Session med Lyko](https://nepa.com/events/nepa-breakfast-session-12-november-2026/) (2026-11-12) — now past; no upcoming events are published. `events.json` status `past`; llms.txt and the agent page say there are no upcoming events.
- Titles brought in line with the live pages:
  - [Hemköp case](https://nepa.com/case/hemkop/) → "From lookalike to standout: Hemköp’s bold path to brand clarity with Nepa"
  - [Fjällräven case](https://nepa.com/case/turning-insights-into-impact-fjallravens-approach-to-global-brand-growth/) → "Fjällräven’s global success through Insights"
  - [Marli Juissi case](https://nepa.com/launching-marli-juissi-energy/) → "How Eckes-Granini launched Marli Juissi® Energy with Nepa"
  - [Word-of-mouth blog post](https://nepa.com/blog/word-of-mouth-product-discovery-sweden/) → "Why friends and family outperform paid media as a source of product discovery in Sweden"
  - [Brand tracking guide](https://nepa.com/blog/brand-tracking/) → "What is Brand Tracking? The Complete Guide for CMOs"
  - [Breakfast Session 29 Jan 2026](https://nepa.com/events/nepa-breakfast-session-29-jan/) → "Nepa Breakfast Session – med Nordnet och Google. Se presentationen!"
  - [Breakfast Session 20 May 2026](https://nepa.com/events/nepa-breakfast-session-stockholm-20-maj-2026/) → "Nepa Breakfast session: Långsiktigt varumärkesbygge med Lantmännen och Coop"
- [Finnish contact page](https://nepa.com/fi/contact/) and [Danish contact page](https://nepa.com/da/contact/) — see Corrections.

Also re-checked and unchanged: [Pricing](https://nepa.com/pricing/) (all 11 tiers; the agent page notes the re-check date).

#### Re-saved on the site since 2026-10-16; title, date, author and package fields verified unchanged (142)

Most are bulk re-saves (113 on 2026-10-19, 21 on 2026-11-02). Live titles, publish dates and structured-data authors were compared for every one; none changed. Excerpts of "Research should shape brand strategy…" and "What is Marketing Mix Modelling? The Complete Guide" were checked against the meta descriptions and are unchanged.

- 2026-10-19 — [Inbjudan till presentation av Nepas delårsrapport Q2 2026](https://nepa.com/mfn_news/inbjudan-till-presentation-av-nepas-delarsrapport-q2-2026/)
- 2026-10-19 — [Invitation to presentation of Nepa’s Q2 interim report 2026](https://nepa.com/mfn_news/invitation-to-presentation-of-nepas-q2-interim-report-2026/)
- 2026-10-19 — [Research should shape brand strategy, not rescue it](https://nepa.com/blog/research-should-shape-brand-strategy-not-rescue-it/)
- 2026-10-19 — [Awesomeness in Omnichannel | Summer 2018](https://nepa.com/blog/awesomeness-in-omnichannel-summer-2018/)
- 2026-10-19 — [Coverage: Customer Experience Magazine](https://nepa.com/blog/coverage-customer-experience-magazine-how-can-advertisers-maximise-social-media-potential/)
- 2026-10-19 — [Coverage – Digital Donut – Nail your media investment](https://nepa.com/blog/coverage-digital-donut-nail-your-media-investment/)
- 2026-10-19 — [Driving long-term and short-term sales using Facebook and Instagram advertising](https://nepa.com/blog/driving-long-term-and-short-term-sales-using-facebook-and-instagram-advertising/)
- 2026-10-19 — [Making good things happen on the omnichannel path to purchase](https://nepa.com/blog/making-good-things-happen-on-the-omnichannel-path-to-purchase/)
- 2026-10-19 — [Omnichannel Revolution Requires New Marketing Measurement](https://nepa.com/blog/omnichannel-revolution-requires-new-marketing-measurement/)
- 2026-10-19 — [Radio Advertising – from Good to Great](https://nepa.com/blog/radio-advertising-from-good-to-great/)
- 2026-10-19 — [Nepa & RealityMine Webinar – Drive Growth: Strategic Marketing Insights](https://nepa.com/blog/realitymine-webinar/)
- 2026-10-19 — [3 Signs Your DIY CX Measurement is Weakening Your CX Strategy](https://nepa.com/blog/3-signs-your-diy-cx-measurement-is-weakening-your-cx-strategy/)
- 2026-10-19 — [What are Brand Associations?](https://nepa.com/blog/brand-associations/)
- 2026-10-19 — [Nepa Brand Impact Series](https://nepa.com/blog/brand-impact/)
- 2026-10-19 — [Outsmart competitors by tracking willingness to pay](https://nepa.com/blog/brandtracking-willingness-to-pay/)
- 2026-10-19 — [CX Business Value Starts with Buy In](https://nepa.com/blog/cx-business-value-starts-with-buy-in/)
- 2026-10-19 — [CX’s second act – Value Creation](https://nepa.com/blog/cxs-second-act-value-creation/)
- 2026-10-19 — [CX: The Art Behind the Science](https://nepa.com/blog/cx-the-art-behind-the-science/)
- 2026-10-19 — [Creating a brand identity that resonates with your audience](https://nepa.com/blog/creating-a-brand-identity-that-resonates-with-your-audience/)
- 2026-10-19 — [Do-it-Yourself CX Software: What you don’t know can cost you](https://nepa.com/blog/do-it-yourself-cx-software-what-you-dont-know-can-cost-you/)
- 2026-10-19 — [Nepa’s data quality pledge](https://nepa.com/blog/data-quality-pledge/)
- 2026-10-19 — [Get to know your customers with brand health tracking](https://nepa.com/blog/get-to-know-your-customers-with-brand-health-tracking/)
- 2026-10-19 — [How to increase brand awareness](https://nepa.com/blog/how-to-increase-brand-awareness/)
- 2026-10-19 — [Introducing AI Trend Boost by Nepa: Make your brand tracking data go further](https://nepa.com/blog/introducing-ai-trend-boost/)
- 2026-10-19 — [Nepa and data quality](https://nepa.com/blog/nepa-and-data-quality/)
- 2026-10-19 — [Nepa’s syndicated 6-month video game tracker with Coronavirus addons](https://nepa.com/blog/nepas-syndicated-6-month-video-game-tracker-with-coronavirus-addons/)
- 2026-10-19 — [Q&A with Virgin Voyages Dir. of Sailor Experience, Christopher Stubbs](https://nepa.com/blog/qa-with-virgin-voyages-dir-of-sailor-experience-christopher-stubbs/)
- 2026-10-19 — [Successful Programs: Services & Insights from Forrester VoC Report](https://nepa.com/blog/successful-voc-programs-include-services-and-other-insights-from-forrester-voc-report/)
- 2026-10-19 — [The CX Loyalty Metrics that Actually Matter](https://nepa.com/blog/the-cx-loyalty-metrics-that-actually-matter/)
- 2026-10-19 — [Time to Assess your CX Measurement Program?](https://nepa.com/blog/time-to-assess-your-cx-measurement-program/)
- 2026-10-19 — [What is a Touchpoint Strength Tracker?](https://nepa.com/blog/touchpoint-strength-tracker/)
- 2026-10-19 — [Tracking healthcare service performance using Nepas customer experience tracker](https://nepa.com/blog/tracking-healthcare-service-performance-using-nepas-updated-customer-experience-tracker/)
- 2026-10-19 — [CX is a game changer – here is why](https://nepa.com/blog/we-know-cx-is-a-game-changer-and-here-is-a-study-that-proves-it/)
- 2026-10-19 — [Talk the language of the C-suite](https://nepa.com/blog/willingness-to-pay/)
- 2026-10-19 — [The impact of advertising must always be questioned](https://nepa.com/blog/advertising-must-always-questioned/)
- 2026-10-19 — [Coverage – Digital Doughnut – Who’s Winning Christmas?](https://nepa.com/blog/coverage-digital-doughnut-whos-winning-christmas/)
- 2026-10-19 — [Have your cake and eat it: How brand building campaigns can drive both short and long-term sales](https://nepa.com/blog/have-your-cake-and-eat-it-how-brand-building-campaigns-can-drive-both-short-and-long-term-sales/)
- 2026-10-19 — [How Influencer Marketing’s Impact Goes Beyond Engagement for Brand Growth](https://nepa.com/blog/impact-of-influencer-marketing/)
- 2026-10-19 — [Measure what really matters – Facebook perspective](https://nepa.com/blog/measure-really-matters-facebook-perspective/)
- 2026-10-19 — [Memories generate sales: Why your Brand Tracker needs to include Category Entry Points](https://nepa.com/blog/memories-generate-sales/)
- 2026-10-19 — [Nepa x Meta Whitepaper 2020](https://nepa.com/blog/nepa-x-meta-whitepaper-2020/)
- 2026-10-19 — [Tackling Your 2019 Marketing KPIs With a Unified Approach](https://nepa.com/blog/tackling-your-2019-marketing-kpis-with-a-unified-approach/)
- 2026-10-19 — [Why a recession is a good time to ramp up marketing](https://nepa.com/blog/why-a-recession-is-a-good-time-to-ramp-up-marketing/)
- 2026-10-19 — [Forget me not, how to stand out and be remembered](https://nepa.com/blog/forget-me-not-stand-out/)
- 2026-10-19 — [How “Tested Concepts” Shape Our Favourite Genres](https://nepa.com/blog/how-tested-concepts-shape-our-favourite-genres/)
- 2026-10-19 — [Nepa’s Communication Pre-testing: Striking the Right Tone](https://nepa.com/blog/striking-the-right-tone-with-communication-pre-testing/)
- 2026-10-19 — [Benefits of Path to Purchase Analysis](https://nepa.com/blog/benefits-of-path-to-purchase-analysis/)
- 2026-10-19 — [Catch the Second Wave](https://nepa.com/blog/catch-the-second-wave/)
- 2026-10-19 — [Data Everywhere – Insights Not Found](https://nepa.com/blog/data-everywhere-insights-not-found/)
- 2026-10-19 — [Embracing AI for business success](https://nepa.com/blog/embracing-ai-for-business-success/)
- 2026-10-19 — [Empower Employees Win Customers](https://nepa.com/blog/empower-employees-win-customers/)
- 2026-10-19 — [Local retailers can use Big Data to bolster growth](https://nepa.com/blog/retailers-use-big-data-bolster-growth/)
- 2026-10-19 — [Buyer Journey Mapping: How to Create One](https://nepa.com/blog/how-to-create-a-buyer-journey-map/)
- 2026-10-19 — [How to get the biggest slice of the meat-free pie](https://nepa.com/blog/how-to-get-the-biggest-slice-of-the-meat-free-pie/)
- 2026-10-19 — [How to steal foot traffic from competitors](https://nepa.com/blog/steal-foot-traffic-competitors/)
- 2026-10-19 — [Innovation and the power of consumer insights](https://nepa.com/blog/innovation-power-consumer-insights/)
- 2026-10-19 — [Liz Earle maximizes Profit with Nepa’s Pricing Optimization Tools](https://nepa.com/blog/natural-skincare-manufacturer-liz-earle-maximizes-profit-with-nepas-pricing-optimization-tools/)
- 2026-10-19 — [Local is the New Global – Using Customer Feedback to grow](https://nepa.com/blog/local-new-global-customer-feedback/)
- 2026-10-19 — [Path to Purchase; the Nepa approach](https://nepa.com/blog/path-to-purchase/)
- 2026-10-19 — [A consumer’s Path to Purchase is never linear](https://nepa.com/blog/path-to-purchase-is-never-linear/)
- 2026-10-19 — [Path to Purchase research; 4 tips for success](https://nepa.com/blog/path-to-purchase-research/)
- 2026-10-19 — [Path to Purchase – Connecting Dots in the “New Era of Shopping”](https://nepa.com/blog/path-to-purchase-connecting-the-dots-in-the-new-era-of-shopping/)
- 2026-10-19 — [Path to Purchase – What’s New and Emerging](https://nepa.com/blog/path-purchase-whats-new-emerging/)
- 2026-10-19 — [We can predict the future](https://nepa.com/blog/predict-future-big-data/)
- 2026-10-19 — [Shopper-centric P2P Analytics](https://nepa.com/blog/shopper-centric-p2p-analytics/)
- 2026-10-19 — [Insights from “The superpowers of data science and analytics”](https://nepa.com/blog/superpowers-data-science-analytics/)
- 2026-10-19 — [Talk is cheap – get better and more predictive insight](https://nepa.com/blog/talk-cheap-better-predicitive-insight/)
- 2026-10-19 — [The value of a magical experience!](https://nepa.com/blog/value-magical-experience/)
- 2026-10-19 — [Thriving in a Transformational Time](https://nepa.com/blog/thriving-in-a-transformational-time/)
- 2026-10-19 — [Transform data into insights – boost your innovation capabilities](https://nepa.com/blog/transform-data-insights-boost-innovation/)
- 2026-10-19 — [Understanding consumers: what gets them to the checkout](https://nepa.com/blog/understanding-consumers/)
- 2026-10-19 — [What are Category Entry Points, and why should you care about them in 2024?](https://nepa.com/blog/what-are-category-entry-points-and-why-should-you-care-about-them-in-2024/)
- 2026-10-19 — [What Is The Value Proposition Canvas?](https://nepa.com/blog/what-is-the-value-proposition-canvas/)
- 2026-10-19 — [How to find your brand’s whitespace opportunities](https://nepa.com/blog/whitespace-opportunities/)
- 2026-10-19 — [2023 RISE Award Winner: Nepa](https://nepa.com/blog/2023-rise-award-winner-nepa/)
- 2026-10-19 — [3 Key Takeaways from CXPA’s Insights Exchange 2019](https://nepa.com/blog/3-key-takeaways-from-cxpas-insights-exchange-2019/)
- 2026-10-19 — [3 Key Takeaways from The National Grocers Association Show 2019](https://nepa.com/blog/my-3-key-takeaways-the-national-grocers-association-show-2019/)
- 2026-10-19 — [5 Key Takeaways – National Sports Forum 2019](https://nepa.com/blog/5-key-takeaways-national-sports-forum-2019/)
- 2026-10-19 — [Block Background Noise – Make Your Brand Data Accurate & Actionable](https://nepa.com/blog/bnr/)
- 2026-10-19 — [Important Customer Experience Learnings From The CXPA Insight Exchange](https://nepa.com/blog/customer-experience-learnings-cxpa-insight-exchange/)
- 2026-10-19 — [Dressmann Chooses Nepa for Customer Experience Measurement](https://nepa.com/blog/dressmann-release/)
- 2026-10-19 — [Looking into a crystal ball: future trends in FMCG and Retail](https://nepa.com/blog/future-trends/)
- 2026-10-19 — [Generation Alpha – The Start Of Something New](https://nepa.com/blog/generation-alpha/)
- 2026-10-19 — [Kindred Extends Brand Tracking & Player Insight Partnership with Nepa](https://nepa.com/blog/kindred-chooses-nepa-for-their-brand-tracking-program/)
- 2026-10-19 — [What you learn when world class CX leaders come together](https://nepa.com/blog/learn-world-class-cx-leaders-come-together/)
- 2026-10-19 — [Lessons From Retail Smarter 2019 – University of Florida](https://nepa.com/blog/lessons-from-retail-smarter-2019-university-of-florida/)
- 2026-10-19 — [MTV Insights Studio and Nepa collaboration – MTV Youth Study](https://nepa.com/blog/mtv-insights-studio-and-nepa-collaboration-mtv-youth-study/)
- 2026-10-19 — [Marketing Society article by Robert Beatus: New challenges, simple solutions](https://nepa.com/blog/marketing-society/)
- 2026-10-19 — [Navigating the COVID-19 world](https://nepa.com/blog/navigating-the-covid-19-world/)
- 2026-10-19 — [Nepa at IIEX 2024](https://nepa.com/blog/nepa-after-iiex-2024/)
- 2026-10-19 — [Nepa Expands: Signs U.S. Based Industry Veteran to Lead Charge](https://nepa.com/blog/nepa-expands-into-global-sports-signs-u-s-based-industry-veteran-to-lead-charge/)
- 2026-10-19 — [Nepa at Cannes Lions Festival 2023](https://nepa.com/blog/nepa-at-cannes-lions-festival-2023/)
- 2026-10-19 — [Nepa in Athens | ESOMAR Congress 2024](https://nepa.com/blog/nepa-in-athens/)
- 2026-10-19 — [What lies ahead for OTT video?](https://nepa.com/blog/ott-video-streaming-future/)
- 2026-10-19 — [Old Clubs Leading the Way with New Fan-driven Approach](https://nepa.com/blog/old-clubs-leading-the-way-with-new-fan-driven-approach/)
- 2026-10-19 — [Nepa insight partner to Oriflame](https://nepa.com/blog/oriflame-choose-nepa-insight-partner/)
- 2026-10-19 — [Psyonix Selects Nepa for Global Brand Measurement Insights](https://nepa.com/blog/psyonix-acclaimed-video-game-developer-selects-nepa-for-global-brand-measurement-insights/)
- 2026-10-19 — [What consumers and shoppers want from brands in the future](https://nepa.com/blog/quirks-london-2022/)
- 2026-10-19 — [Svenska Spel Selects Nepa for Brand Tracking & Campaign Measurement](https://nepa.com/blog/svenska-spel/)
- 2026-10-19 — [The consumer narrative and what matters- Covid edition](https://nepa.com/blog/the-consumer-narrative-and-what-matters-covid-edition/)
- 2026-10-19 — [The Nepa Marketing & Insights Dinner 2023](https://nepa.com/blog/the-nepa-marketing-insights-dinner-2023/)
- 2026-10-19 — [The Win-Win Proposition](https://nepa.com/blog/the-win-win-proposition/)
- 2026-10-19 — [The Young ESOMAR Society awards 2024](https://nepa.com/blog/the-young-esomar-society-awards-2024/)
- 2026-10-19 — [Tydy and Nepa Collab – Onboarding, Productivity and Diversity](https://nepa.com/blog/tydy/)
- 2026-10-19 — [Varner chooses Nepa for their brand tracking program](https://nepa.com/blog/varner-chooses-nepa-for-their-brand-tracking-program/)
- 2026-10-19 — [Nepa at Quirk’s London: An event for Marketing Research and Insight Professionals](https://nepa.com/blog/https-insights-nepa-com-webinar-mo-0hs_previewngqmzmry-72589978776/)
- 2026-10-19 — [When Season Ticket Holders Don’t Renew](https://nepa.com/blog/when-season-ticket-holders-dont-renew/)
- 2026-10-19 — [Why Investing In Fan Insights Is a Smart Play](https://nepa.com/blog/why-investing-in-fan-insights-is-a-smart-play/)
- 2026-10-19 — [Forrester weighs in on marketing measurement and optimization](https://nepa.com/blog/forrester-weighs-in-on-marketing-measurement-and-optimization/)
- 2026-10-19 — [Marketing Mix Modelling 101](https://nepa.com/blog/marketing-mix-modeling-101/)
- 2026-10-19 — [Modelling marketing strategy to sell more pizza](https://nepa.com/blog/modelling-marketing-strategy-sell-pizza/)
- 2026-10-19 — [The Future of Marketing Mix Modelling](https://nepa.com/blog/the-future-of-marketing-mix-modelling/)
- 2026-10-19 — [What is dynamic marketing intelligence, and why do you need to know about it?](https://nepa.com/blog/what-is-dynamic-marketing-intelligence/)
- 2026-10-23 — [Nepa Delårsrapport Q2 2026](https://nepa.com/mfn_news/nepa-delarsrapport-q2-2026/)
- 2026-10-23 — [Nepa Interim Report Q2 2026](https://nepa.com/mfn_news/nepa-interim-report-q2-2026/)
- 2026-10-23 — [What is Marketing Mix Modelling? The Complete Guide](https://nepa.com/blog/what-is-marketing-mix-modelling-the-complete-guide/)
- 2026-11-02 — [Lantmännen: Long-term brand building from “Jord till bord” to “Länge leve jorden”](https://nepa.com/blog/long-term-brand-building-from-jord-till-bord-to-lange-leve-jorden/)
- 2026-11-02 — [Global Brand GANT selects Nepa as Brand Tracking Provider in Markets](https://nepa.com/blog/global-lifestyle-and-clothing-brand-gant-selects-nepa-as-brand-tracking-provider-in-all-major-markets/)
- 2026-11-02 — [Nepa x Meta Whitepaper 2022](https://nepa.com/blog/nepa-x-meta-whitepaper-2022/)
- 2026-11-02 — [Why your brand tracker sucks and how to fix it](https://nepa.com/blog/why-your-brand-tracking-sucks-and-how-to-fix-it/)
- 2026-11-02 — [Long-term brand building in practice – lessons from the Nepa Breakfast Session](https://nepa.com/blog/long-term-brand-building-in-practice-lessons-from-the-nepa-breakfast-session/)
- 2026-11-02 — [What is the value of emotions for a brand?](https://nepa.com/blog/what-is-the-value-of-emotions/)
- 2026-11-02 — [IIEX Europe 2025 – Key trends & takeaways](https://nepa.com/blog/iiex-europe-2025-key-trends-takeaways/)
- 2026-11-02 — [Keeping brand KPIs simple](https://nepa.com/blog/keeping-brand-kpis-simple/)
- 2026-11-02 — [Coop: How we use data to take back our position](https://nepa.com/blog/coop-how-we-use-data-to-take-back-our-position/)
- 2026-11-02 — [The three most common reasons campaigns underperform](https://nepa.com/blog/the-three-most-common-reasons-campaigns-underperform/)
- 2026-11-02 — [Emotion Palette: Optimise the Emotional Impact of Your Marketing Campaigns](https://nepa.com/blog/emotion-palette/)
- 2026-11-02 — [It’s not about more data – it’s about the right insight](https://nepa.com/blog/its-not-about-more-data-its-about-the-right-insight/)
- 2026-11-02 — [Reimagine insights: What to expect at ESOMAR Congress 2025](https://nepa.com/blog/reimagine-insights-what-to-expect-at-esomar-congress-2025-in-prague/)
- 2026-11-02 — [Customer journey agency: Guiding brands to smarter growth](https://nepa.com/blog/customer-journey/)
- 2026-11-02 — [Unveiling the mystery of brand tracking: The top questions answered by trackers](https://nepa.com/blog/unveiling-the-mystery-of-brand-tracking-the-top-questions-answered-by-trackers/)
- 2026-11-02 — [Nordnet: turning insight and patience into brand‑led growth](https://nepa.com/blog/nordnet-turning-insight-and-patience-into-brand-led-growth/)
- 2026-11-02 — [10 years of Nepa in Finland](https://nepa.com/blog/10-years-of-nepa-in-finland/)
- 2026-11-02 — [The meaning of sustainability for Indian consumers](https://nepa.com/blog/brand-sustainability-for-indian-consumers/)
- 2026-11-02 — [Tre’s brand revamp: Strategy for profitable growth](https://nepa.com/blog/tres-brand-revamp-strategy-for-profitable-growth/)
- 2026-11-02 — [How well do you measure your campaigns — really?](https://nepa.com/blog/how-well-do-you-measure-your-campaigns-really/)
- 2026-11-02 — [That’s so OTT! The fast-changing media landscape in India](https://nepa.com/blog/ott-india/)
- 2026-11-12 — [CEO Anders Dahl leaves Nepa – search for successor initiated](https://nepa.com/mfn_news/ceo-anders-dahl-leaves-nepa-search-for-successor-initiated/)
- 2026-11-12 — [MFN News Items Archive](https://nepa.com/mfn_news/)
- 2026-11-12 — [Ny mätning: Varumärkena som byggt starkast reklamkapital](https://nepa.com/mfn_news/ny-matning-varumarkena-som-byggt-starkast-reklamkapital/)
- 2026-11-12 — [VD Anders Dahl lämnar Nepa – rekrytering av efterträdare inledd](https://nepa.com/mfn_news/vd-anders-dahl-lamnar-nepa-rekrytering-av-eftertradare-inledd/)
- 2026-11-13 — [Blog](https://nepa.com/blog/)

### Pages removed (1)

| URL | Live status (2026-11-16) | Package action |
|---|---|---|
| https://nepa.com/mfn_news/nepa-appoints-morten-flamand-as-new-vp-sales-to-accelerate-international-growth/ | 404 Not Found (also gone from the sitemap) | removed from press-releases.json and the agent page. Nepa announced Andrea Sahlgren as VP Sales on 2026-10-19. |

No package URL redirects any more.

### Live pages deliberately excluded (20)
- https://nepa.com/case-category/case-category/ — still an empty placeholder taxonomy archive (excluded since 2026-10). The other four pages excluded last month are no longer in the sitemap.
- Found in the second review — published WordPress pages that are live (200, no `noindex`) but not in the sitemap and not part of the public site structure (ad landing pages, form thank-you pages, cancellation forms, drafts and copies). Excluded; Nepa should noindex or unpublish them:
  - cancellation forms: /nepa-breakfast-session-planning-for-impact-cancellation/, /nepa-breakfast-session-12-november-2026-avbokning/
  - webinar ad landing pages (the webinars themselves are in events.json): /webinar-brand-grow/, /webinar-ce-stop-founding-your-competitors-brands/
  - product ad landing pages: /brand-tracking/landing/, /campaign-pulse/landing/, /marketing-mix-modelling/landing/ and the Swedish /sv/brand-tracking/landing/, /sv/campaign-pulse/landing/, /sv/marketing-mix-modelling/landing/; /learnmore/ ("Product Remarketing"), /product-marketing/, /whitepaper-download/
  - thank-you pages: /thank-you/, /sv/tack/, /fi/kiitos/, /da/tak/
  - internal or copies: /bt-dashboard-update/, /home-2/ ("Home (copy)", a duplicate of the homepage)
- Two REST-listed posts still redirect, as last month: /the-brands-that-grow-tomorrow-are-already-measuring-differently-today/ (→ the `-2` post, which is in the package) and /blog/is-your-brand-a-hero-or-villain/ (→ homepage); /contact_trinity/ redirects to /contact-us/.

### Navigation, contact details and structured data

- **Navigation:** the footer "Marketing Mix Modelling" link now goes to the English page (/products/marketing-mix-modeling/; it went to the Danish page last month). The footer has a new **"For AI agents"** link to https://nepa.com/agent/. Two menu links still go through a 301: header "Marketing Mix Modelling" → /products/marketing-mix-modelling/ (→ /products/marketing-mix-modeling/) and footer "Market Segmentation" → /products/market-segmentation/ (→ /products/segmentation/). The package uses the final URLs.
- **Contact details:** unchanged on the English site (Stockholm HQ, +46 8 400 268 00, info@nepa.com, panel@nepa.com, the three Finland account contacts, investor contacts).
- **Corrections (content missed by earlier builds, not site changes):** the Finnish pages publish a Helsinki office — Erottajankatu 2, 2. krs (VillageWorks), 00120 Helsinki, +358 45 678 6939 (/fi/contact/ last modified 2026-08-27) — and the Danish pages a Copenhagen office — Havnegade 39, 1058 København K, +45 81 61 78 10. Both are now in `offices.json` (Finland office completed; new `office-denmark`), the agent page (Offices, Primary Contact, Quick Answer, Organization JSON-LD `location`) and llms.txt.
- **Products and services:** the same 12 products, 6 solutions and 8 industries; pricing unchanged.
- **Leadership:** the About page still lists the same leadership team; Andrea Sahlgren (VP Sales from early November) appears only in the press release, so she is recorded as an announcement (llms.txt, agent page instructions, `investor-relations.json`), not in `team.json`.
- **Structured data on the live site:** unchanged in type mix (Organization, WebSite, WebPage, BreadcrumbList on every page; Article + Person on posts; FAQPage/Service on product pages). See the issues below.

### Second review (2026-11-16)

Method: every item published through the WordPress REST API (`/wp-json/wp/v2/` posts 252, pages 70 plus 13 sv / 7 fi / 6 da, mfn_news 293, insight 8, report 3, download 6) and every internal link on the 532 scraped pages and on the new pages (923 distinct URLs) was checked against the package. 134 linked URLs outside the sitemap and the package were status-checked: 98 redirect to pages already in the package (3 of them redirect to image files — see the issues list), 30 return 404, and the rest are listed above. The sitemap and every `lastmod` were re-read and had not changed since the crawl. Team titles, the About page leadership list, pricing and contacts were re-checked: unchanged.

Found and added: the 7 Insights topic pages, 3 downloads, the restored brand-salience post and the Swedish newsroom (all above).

**Bug found in the live discovery plugin (fixed in 1.1.0):** on every Swedish, Finnish and Danish page, nepa-agent-discovery.php 1.0.0 printed broken discovery links — `https://nepa.com/sv//llms.txt`, `/sv//agent/`, `/sv//.well-known/api-catalog`, `/sv//openapi.json` (and the same for /fi/ and /da/), all 404 — because the multilingual plugin filters `home_url()` to the language being viewed. The ARD module would have repeated it for `rel="ard"`. Both must-use plugins now build site-wide URLs from the unfiltered `home` option (`nepa_ad_url()`, `agentic_ard_site_url()`, the latter filterable); relative links in `?format=md` output use the same root. Three new ARD tests simulate a language-prefixed `home_url()` and fail with the old code.

### Codex review (2026-11-16) — verdicts and changes

An independent review (Codex, `NOVEMBER-REVIEW.md` next to the package folders) judged the package ready for staging but not yet for production. Each point was checked against the code before anything was changed.

| # | Codex finding | Verdict | Change |
|---|---|---|---|
| 1 | Sync is not all-or-nothing: files were renamed into place one by one before the manifest, so a failure mid-commit left a mix; readers could see files change during a commit; the transient lock was a separate read and write | **Agree** (confirmed in `commit_staged()` and `run_sync()`) | Each Sync now writes a complete generation into `uploads/nepa-agent-api/gen-<time>-<random>/`; files not re-synced (e.g. a SKIPPED section) are copied in, so the generation stands alone; then `manifest.json` is replaced with one atomic rename naming the generation. Any failure before that rename deletes the generation and changes nothing that is served. The current and previous generations are kept (a reader that read the old manifest can finish), older ones pruned. The lock is now an atomic `INSERT` into `wp_options` with a token and expiry (owner-only release, stale-lock takeover after 10 minutes) — the technique the contact endpoint already uses. The data path reads files from the generation the manifest names (older flat manifests still work). |
| 2 | Blog Sync reverses November corrections (`gomo_dev` restored as author; `meta.version` 1.2 rewritten as 1.1) | **Agree** (blog Sync rebuilt each post from REST without the curated merge; `'version' => '1.1'` was hard-coded) | New `NEPA_DATA_VERSION` (1.2) stamps synced files. Blog Sync applies the editorial corrections: an author that is a CMS or agency account (`NEPA_SYNC_CMS_AUTHORS`, default `gomo_dev`, `Mridul GO MO`) is never published — the curated author is kept, including an intentional null or the visible byline (Dora Harfman Bromée); real author changes in WordPress still come through; non-empty curated excerpts are kept. |
| 3 | MCP says "nothing was recorded" after unexpected server errors | **Agree** (any 503, and every other error, was reported as not saved; a JSON 500 after the lead was stored would have been misreported) | "Not saved" is reserved for the API's own error codes that guarantee it (validation, consent, authorization, size, `rate_limited`, `idempotency_key_reused`, `not_recorded`, `reservation_unavailable`); an unexpected 5xx, an unrecognised 503 or any unrecognised body is `OUTCOME UNKNOWN` with retry instructions. "Never creates a second inquiry" now states the real windows (24 hours with the same key, 30 minutes for identical details), in the tool, the README and DEPLOYMENT.md. Kept as MCP 1.2.1 (not yet released). |
| 4 | ARD-only rollback leaves broken references | **Agree** | DEPLOYMENT.md §7 lists every reference to remove (module, MCP card, API catalog linkset, agent page head/metadata/instruction/JSON-LD, `index.json`, OpenAPI, llms.txt, ai.txt, api-catalog.json, robots.txt) and gives a `curl`/`grep` check that nothing still advertises the endpoint; a full rollback to 2026-10 is described as the simpler alternative. |
| 5 | Real-environment acceptance is outstanding | **Agree** — it cannot be done from the build environment | New DEPLOYMENT.md §13: an eight-row production sign-off table (install and routes, localized discovery links, ARD on the real host, edge/cache, concurrent duplicates, HubSpot end to end, MCP on Render, Sync on a non-production copy) with pass criteria and a column for results. The read-only parts can go live after rows 1-4; contact needs 5-7; Sync needs 8. Until then, Sync stays off production. |
| — | MCP card does not follow the draft MCP server-card schema | **Partly agree** — documented, not changed | Relabelling the ARD entry would drop it from registries' MCP-type filters, the main reason to publish it; the limitation stays documented. Revisit when the MCP server-card format is final. |
| — | `agentic_ard_check_entry()` does not enforce the whole schema | **Agree** | `data` and `metadata` must be JSON objects (a PHP list or empty array would serialize as an array), `description`/`version`/`@id` must be strings, `updatedAt` must be RFC 3339, `trustManifest` must be an object; negative tests added. |
| — | Runbook inaccuracies (stale robots step, "two remaining checks", headings) | **Agree** | robots step reduced to the one new line; the "confirmed on staging" note corrected (no staging evidence is part of this package); section 10 renamed and pointed to §13; headings updated; top-of-file test instructions now use `tests/run-all.sh`. |
| — | Website issues "not independently re-verified" | **No change** | They came from this build's 2026-11-16 crawl and are listed as observations for Nepa, not as package defects. |
| — | Make checks repeatable; a missing validator dependency must not look like a pass | **Agree** | New `tests/validate-data-files.py` (17 data files vs OpenAPI, also usable on a synced generation) and `tests/run-all.sh` (every suite, one line each). All Python validators now report INCOMPLETE and exit 3 without `jsonschema` (`ALLOW_SKIP=1` to accept a skip deliberately). |

Mutation checks: with the old behaviour restored in a temporary copy, the new tests fail for each defect (version 1.1; CMS author restored; non-atomic lock; manifest written regardless of a failed switch). With the old MCP mapping, the new smoke cases (a lead saved before a JSON 500, an unrecognised 503) would report "NOT SAVED".

### Fix found by the live-site check (2026-11-17)

A read-only check of nepa.com after deployment found one data bug from this build: the Finnish webinar page in `localized-pages.json` had `alternates: {}`. The plugin re-encodes filtered responses with PHP, which served it as `[]`, while OpenAPI documents `alternates` as an object or null. It is now `null` (re-upload `nepa-api/data/localized-pages.json`). `tests/validate-data-files.py` now fails on any empty JSON object in the data files; it flags the old file and passes the fixed one. The same check confirmed everything else matches the package: plugin 1.4.0, the other 16 data files, llms.txt, ai.txt, api-catalog.json, OpenAPI 1.3.0, the ARD manifest (0 errors against the official schema), discovery links on English and localized pages, robots.txt and MCP 1.2.1. Still open on the live site: the agent page re-upload (restored discovery links) and the WP Engine bot exemption (GPTBot and ClaudeBot get 429, generic clients 403).

### Merged before publishing to GitHub (2026-11-17)

- **Agent page discovery links restored.** The GitHub repository had a commit after the 2026-10 tag ("updated", 2026-10-27) that added three items to the agent page's "LLM Discovery & Machine-Readable Resources" list: `/.well-known/api-catalog`, `/.well-known/mcp.json` and the `?format=md` Markdown versions. This package was built from a local copy without that commit, so the three items were missing; they are back in `nepa-api/agent-page.html`. If the November agent page was already uploaded, upload this version again.
- **Repository hygiene.** A root `.gitignore` (`.DS_Store`, `node_modules/`). The repository previously tracked `nepa-mcp/node_modules/` (about 3,900 files) and `.DS_Store`; they are removed from it - Render installs dependencies with `npm ci`.
- **Package contents in the repository** also gain what the 2026-10 commit lacked: `nepa-agent-discovery.php` (it was deployed to `mu-plugins` but never committed), `agentic-resource-discovery.php`, and the renamed `tests/live-checks.sh` (was `tests/staging-checks.sh`).

### Codex sixth review (2026-11-16)

Codex reran all eight suites (all passing: 108 plugin, 40 MCP) and judged the package suitable for a controlled production rollout after one correction. **Agree with both points.**

- **Cleanup searched the wrong text.** The duplicates check told operators to search Agent Leads for "Concurrency check <run ID>", but its key-reuse request's message is "DIFFERENT content <run ID>"; if that request were wrongly accepted, the search - and the WP-CLI count - would miss it. `tests/live-checks.sh` now searches by the run ID alone (present in every request's email address and message) for the count, the INFO line, the CLEANUP line and the WP-CLI ID command; DEPLOYMENT.md §10.1 says the same and explains why. Rerun against the correct mock server with a stand-in WP-CLI: the count queried `--s=<run ID>` and the check passed (10/10).
- **The switches stop new submissions only.** DEPLOYMENT.md §7a now says so: accepted inquiries stay in Agent Leads and their background jobs continue (HubSpot retries every 5 minutes, notification emails hourly). Setting `NEPA_HUBSPOT_ENABLED` to false as well holds queued HubSpot deliveries - the worker stops and resumes them, unchanged, when re-enabled (confirmed in `run_worker()`, which returns at once when delivery is off). A specific inquiry is withdrawn in wp-admin (and in HubSpot if already delivered).

### Codex fifth review: operational gaps for a production rollout (2026-11-16)

Codex reviewed the direct-to-production runbook: suitable for a controlled production rollout once four operational gaps are closed; full sign-off still depends on DEPLOYMENT.md §13. **Agree with all four.**

1. **"Turn off HubSpot" does not stop contact submissions.** With `NEPA_HUBSPOT_ENABLED` false, WordPress still accepts, stores and emails inquiries. There was no real switch, so one was added:
   - **Plugin (still 1.4.0):** `define('NEPA_CONTACT_ENABLED', false);` in wp-config.php makes `POST /agent/v1/contact.json` answer 503 `contact_disabled` before anything is counted, stored, emailed or sent to HubSpot; `index.json` reports `contact_submissions: "paused"` and drops the contact endpoint from its list; read-only endpoints are unaffected. Read at request time (new `nepa_agent_api_contact_enabled()`).
   - **MCP server (still 1.2.1):** the Render variable `NEPA_CONTACT_ENABLED=false` leaves `submit_contact_inquiry` unregistered (17 tools); `/health` and `/tools` report the state; a `contact_disabled` answer is reported as "NOT SAVED: Nepa has paused agent contact submissions" with the contact page. `dist/index.js` rebuilt.
   - **OpenAPI:** the 503 description and `IndexResponse.contact_submissions`. **DEPLOYMENT.md:** new §7a "Pausing contact submissions" (both switches, cache purge, checks, how to resume); §13 and §10.1 point to it; the wp-config and Render settings list the switch.
   - **Tests:** six plugin checks run the real request router in a separate process (`tests/plugin-route-probe.php`, since `handle_request()` exits) - 108 in total; five MCP smoke checks start a second server with the switch off - 40 in total; one more OpenAPI example (62).
2. **"Creates two leads" is the expected result, not a limit.** If the duplicate protection under test fails, `live-checks.sh duplicates` can create up to 23 leads, emails and HubSpot submissions. The script now prints its run ID, the worst case and a CLEANUP line naming every record to delete (Agent Leads matching "Concurrency check <run ID>", and the HubSpot records for its two test addresses), and the refusal message without the opt-in says the same. DEPLOYMENT.md §10.1 and §13 require cleaning up every record carrying the run ID. The script was rerun against the bundled correct and buggy mock servers: it still passes the correct one and fails the buggy one.
3. **Backup list incomplete.** DEPLOYMENT.md §0 now starts with a WP Engine backup point (database and files - Agent Leads, delivery state and the Sync lock live in the database) and adds `wp-content/uploads/nepa-agent-api/`; §7 explains restoring the database (export leads received since the backup first).
4. **Cached responses after a Sync rollback.** Deleting `manifest.json` restores the bundled data in the plugin at once, but cached `/agent/v1/*` responses may persist: §7 step 2 and §13 row 8 now say to purge them.

### Direct production deployment (2026-11-16, Nepa's decision)

This release is deployed straight to nepa.com; there is no staging site. Earlier text in this changelog and in reviews that says "ready for staging" or "staging acceptance" means the real-environment checks of DEPLOYMENT.md §13, which are now done on production right after deploying.

- **DEPLOYMENT.md:** §0 step 2 now describes a direct production deployment (quiet time, backups first, rollback at hand, checks straight after). §6 is production-only; its contact-flow item points to §13 rows 5 and 6 and drops the "break SMTP" step, which isn't safe on a live site. §10.1, §10.2 and the HubSpot acceptance note use nepa.com. §12 step 6 says to run the first Sync only as §13 row 8. §13 rows 1, 5 and 8 are production steps, row 8 with its immediate rollback (delete `manifest.json`), and the section says what to do if a contact check fails.
- **`tests/live-checks.sh duplicates`:** still refuses nepa.com by default; it now runs there only with `NEPA_ALLOW_PRODUCTION_TEST=1`, and says beforehand what it will create (2 "LIVE TEST" leads, 2 notification emails, and 2 HubSpot submissions if delivery is on) so they can be deleted afterwards. Without the flag it exits before sending anything.
- **tests/README.md and two code comments** (`tests/wp-stubs.php`, `class-sync.php`) no longer refer to a staging site.

### Codex fourth review (2026-11-16)

Codex verified the key-conflict fix (completed and in-flight cases, original leads still reachable) and reran all eight suites: all pass (102 plugin, 34 MCP, 0 ARD errors). Verdict: ready for staging acceptance; production approval needs DEPLOYMENT.md §13 (rows 1-4 read-only, 5-7 contact, 8 Sync). One documentation inconsistency: DEPLOYMENT.md said a reused key is "always refused" while this changelog records the simultaneous-binding exception. **Agree** - DEPLOYMENT.md, the `contact_submission()` doc comment and tests/README.md now state the exception the same way (the request is answered with the existing lead it matches; nothing is created). No code change.

### Codex third review (2026-11-16)

Codex reran all eight suites on the hardened package (all passing, 97 plugin and 34 MCP checks) and confirmed the mixed-key duplicate is fixed, but found a key conflict the hardening introduced: submit inquiry A with key A, inquiry B with key B, then B's content with key A. Expected 409 `idempotency_key_reused`; the plugin answered 200 with B as a duplicate, because the content reservation (checked first) matched B and returned before key A was looked at. **Agree** - it contradicted the documented rule that a key reused for a different inquiry is refused.

Fix (`contact_submission()`): when the content matches an earlier submission and the request carries a key, the key's existing reservation is read first (new read-only `live_reservation()`); if it is live and bound to different content, the answer is 409 `idempotency_key_reused`. Nothing is reserved by that read, so the fixed content-then-key order that stops overlapping requests from waiting on each other is unchanged. Remaining edge: if key A is being bound to other content at the very same moment, the request can still be answered with B's lead - it never creates a lead or a submission, so nothing is duplicated.

Tests: five plugin checks added (102 in total) - Codex's scenario, B in flight with A's key, and A and B both still resolving correctly afterwards. Before the fix, Codex's scenario and the in-flight case failed; after it, all pass.

### Plugin hardening: a retry that adds or drops a key (2026-11-16, Nepa's decision)

The wording fix below only protects agents that follow instructions. On request, the plugin itself now closes the gap: every contact request reserves its content fingerprint (30 minutes) and, when it carries an `idempotency_key`, the key as well (24 hours) - always content first, then key, so two overlapping requests can never each hold one reservation and wait for the other. A request that finds an existing reservation releases any it already took. Effects:

- a keyless request followed by the same content with a key, or a keyed request followed by the same content without one, returns the original lead (`duplicate: true`) instead of creating a second one;
- the original key keeps matching for 24 hours, after the 30-minute content window;
- a key reused for different content is still 409 `idempotency_key_reused` and leaves nothing reserved (see the Codex third review for the case where that content matches another inquiry);
- **trade-off, accepted by Nepa:** two inquiries with identical content but different keys within 30 minutes are treated as one.

Files: `nepa-api/nepa-api.php` (`contact_submission()`, new `release_reservations()`; still plugin 1.4.0, not yet released); OpenAPI `AgentContext.idempotency_key` description; MCP retry wording (the advice to keep the key exactly as on the first call stays, now with the reason: after 30 minutes only the original key matches); MCP README; DEPLOYMENT.md "Duplicates". Eight plugin tests added (97 in total). With the old single-scope reservation restored, seven fail; with the release-on-conflict removed, four fail.

### Codex second review (2026-11-16)

Codex reran all eight suites on the fixed package (all passing, counts matching, no INCOMPLETE) and found one regression introduced by the point-3 fix above: the "outcome unknown" retry advice said "same idempotency_key (set one if you did not)". The API identifies a submission by its key, or by its content when no key is sent (`nepa-api.php` builds the reservation scope from one or the other, never both), so a retry that adds a key is a new submission. Codex reproduced a second lead from an identical inquiry sent first without and then with a key. **Agree.**

Fix: the MCP tool now tells agents to set `idempotency_key` on the first call, and on a retry to keep the key exactly as on the first call - the same key if one was sent, no key if none was, never adding one. The same correction is in the tool description, the key's input description, the MCP README and DEPLOYMENT.md (which now also explains that a repeat is matched by key *or* content). `dist/index.js` rebuilt. Three smoke-test checks added (34 in total); with the old wording restored, two of them fail. The API's matching rule itself is unchanged.

### Bug and anomaly review (2026-11-16)

A third pass checked the finished build for internal inconsistencies rather than missing pages: every data file (duplicate IDs and URLs, count fields, date formats, sort order, language tags, symmetry of the 102 + 3 English/Swedish press-release pairs, event status against dates, IR calendar against `next_report`), every agent-page section against its data file (cases, events, resources, IR tables, team, pricing, offices, news, products), every in-page anchor and element ID, the JSON-LD graph (`hasPart`, ItemList positions, FAQ count), llms.txt link format, and the HTTP status of all 882 URLs the package references. Found and fixed:

1. **API base URL returned 404** (inherited): `https://nepa.com/agent/v1/` — the `baseURL` in api-catalog.json, `base_url` in index.json and `metadata.baseUrl` of the ARD REST entry — had no route. Plugin 1.4.0 adds one; plugin test added (68).
2. **Same Swedish post, two titles** (inherited): 12 Swedish blog posts had their post heading in blog-posts.json but their SEO page title in localized-pages.json. Both now use the post heading; the agent page's Nordic list was regenerated.
3. **ARD trust identity not checked against the publisher** (new code): the module required `trustManifest.identity` but did not enforce spec §4.5.1 (the identity's domain MUST be the URN publisher). It now does, for HTTPS, `did:web`, SPIFFE and bare-domain identities, rejecting look-alike domains; the packaged validator had a substring check that `notnepa.com` would have passed, now fixed the same way. Nepa publishes no trust manifest, so nepa.com's manifest is unaffected; this matters for reuse.
4. **ARD module with no entries still answered OPTIONS/POST** (new code) with 204/405 instead of falling through to WordPress's 404. Fixed and tested.
5. **ETag without conditional requests** (new code, improvement): the manifest now answers `If-None-Match` with 304, so registries re-crawling it don't download it again.

Checked and fine: no duplicate IDs or URLs; all counts, sort orders and language pairs consistent; no upcoming event in the past; every agent-page section matches its data; no broken anchors or duplicate element IDs; JSON-LD consistent; llms.txt links all in Markdown form. Of the 882 referenced URLs, 874 return 200; the rest are `/.well-known/ard.json` (404 until deployed), `/agent/v1/` (fixed above), LinkedIn (403 to non-browser clients), the MCP endpoint (406 to a plain GET, as the protocol requires), one external IR link that redirects (lantero.report) and a link to `/sv` written without its trailing slash only in a code comment.

Not changed, worth knowing: the ARD MCP entry points to nepa.com's own `/.well-known/mcp.json` card, which predates and does not follow the draft official MCP server-card schema; ARD does not validate the referenced artifact, but registries that parse cards strictly may not read it. Whether Polylang/WPML leave the `home` option unfiltered (which the localized-link fix relies on) should be confirmed on the live site with the check in DEPLOYMENT.md §1a.

### Agentic Resource Discovery (ARD) — new

Implements the publisher side of the Agentic Resource Discovery specification **v0.91** (https://agenticresourcediscovery.org/spec/, ards-project/ard-spec commit `f073b1e`, 2026-10-25 — the latest published version). Nothing existing was replaced; all previous endpoints and links behave as before.

- **New must-use plugin `agentic-resource-discovery.php` (1.0.0)** — site-agnostic and reusable on any WordPress site of the package: no Nepa values; entries come from the `agentic_ard_entries` filter and settings from `agentic_ard_config`. It:
  - serves `/.well-known/ard.json` (spec §5.1; `ArdManifest` with an `entries` array, `specVersion` and `host`) with `application/json`, CORS, a one-hour cache, an ETag, HEAD/OPTIONS support and 405 for other methods;
  - prints `<link rel="ard">` in every page's `<head>` and sends `Link: <…/.well-known/ard.json>; rel="ard"` on front-end responses (consumers MUST honour the link, §5.1);
  - validates every entry against the ARD entry rules (required `identifier`/`displayName`/`type`, `urn:air:<publisher>:…` anchored on the site's domain, exactly one of `url`/`data`, media type, flat `metadata`, `trustManifest.identity`) and leaves invalid or duplicate entries out of the manifest, logging them, so the published manifest always conforms; warns when `representativeQueries` is not 2–5 (§D.2);
  - stays silent (404, no link) when a site registers no entries;
  - exposes `agentic_ard_jsonld()` for in-page markup with the ARD base context (§4.1).
  - It is a publisher only: it does not implement an ARD Registry (`/search`, `/explore`, `/agents`), which is the role of search services.
- **`nepa-agent-discovery.php` 1.1.0** registers nepa.com's five entries (each with description, tags, 2–5 representative queries and `updatedAt` 2026-11-16):
  - `urn:air:nepa.com:mcp:nepa-mcp` — `application/mcp-server-card+json` → /.well-known/mcp.json; capabilities = the 18 MCP tool names; version 1.2.1
  - `urn:air:nepa.com:api:agent-rest-api` — `application/vnd.oai.openapi+json` → /openapi.json; version 1.3.0
  - `urn:air:nepa.com:catalog:api-catalog` — `application/linkset+json` → /.well-known/api-catalog
  - `urn:air:nepa.com:docs:agent-page` — `text/html` → /agent/
  - `urn:air:nepa.com:docs:llms-txt` — `text/plain` → /llms.txt
  - It also adds the ARD manifest to the RFC 9727 `/.well-known/api-catalog` linkset and an `ard` link to `/.well-known/mcp.json`.
  - No `trustManifest` is published: Nepa has no verifiable attestation, and an unverified one would be meaningless to registries.
- **Other discovery routes:** `Agentmap: https://nepa.com/.well-known/ard.json` in robots-addition.txt (§5.1 Agentmap directive); the five entries embedded as JSON-LD on the agent page (`<script id="ard-entries">`, §5.1 in-page markup) plus `<link rel="ard">` in its head; ARD lines in llms.txt, ai.txt (`ARD:`), api-catalog.json (new "Nepa Agentic Resource Discovery manifest" entry and `X-discovery.ard_manifest`), `/agent/v1/index.json` (`ard_manifest`), OpenAPI (`externalDocs`) and the agent page metadata and instructions. DNS discovery records (§5.1) are not set up; they need DNS access.

### Asset changes

- **nepa-api/data/** — all 17 files `version: 1.2`, `release: agentic-web-2026-11`, `last_updated: 2026-11-16`. Content: blog-posts (+5), resources (+3 downloads), press-releases (+7, −1), case-studies (+Laattapiste-Pukkila; 3 titles), events (2 now past; 2 titles; note), localized-pages (+13; counts), investor-relations (Q3 report, next report, announcements, presentations), offices (Helsinki address, Copenhagen office). schema.json documents the new optional fields. All 17 files validate against OpenAPI 1.3.0.
- **nepa-api/nepa-api.php 1.4.0** — `NEPA_DATA_RELEASE` = `agentic-web-2026-11` (the bundled data is served immediately, per the release-manifest rule); `index.json` gains `ard_manifest`; new URL rule so the advertised API base URL `/agent/v1/` returns the index instead of a 404 (needs **Settings → Permalinks → Save** after deploying); Sync publishes generations atomically with an atomic lock, stamps `NEPA_DATA_VERSION` (1.2) and keeps blog editorial corrections (see the Codex review section). No other code change.
- **nepa-api/openapi.json 1.3.0** — new optional fields (Office `address_source`, `contact_url_fi`; InvestorRelations `presentations`; LocalizedPage `alternates_source`, section `webinar`; Index `ard_manifest`); `externalDocs` to the ARD manifest.
- **nepa-api/agent-page.html** — dates; corrected package version row (it still said plugin 1.2.0 / MCP 1.1.0); ARD metadata row, instruction and embedded entries; Andrea Sahlgren note; offices; the new case article; full blog (265) and press-release (293) tables regenerated from the data; events (0 upcoming, 10 past); IR (Q3, presentation, next report, 18 reports); Insights hub link; Nordic page index (106). JSON-LD: `dateModified`, case ItemList (21, titles), Organization `location` (Helsinki, Copenhagen).
- **llms.txt** — crawl date; Laattapiste proof point; offices; Andrea Sahlgren; Insights link; ARD link; case list and titles; 3 new blog posts; events; IR (Q3, next report); new totals.
- **ai.txt** — `Last-Updated: 2026-11-16`; `ARD:` canonical source.
- **api-catalog.json** — `modified`; new counts; new ARD manifest API entry (5 `X-ard-entry` properties); `X-discovery.ard_manifest`.
- **robots-addition.txt** — `Agentmap:` line.
- **nepa-mcp 1.2.1** — dependency security update only: `npm audit` found 5 new advisories published after 2026-10-16 (2 high, 3 moderate: `body-parser`, `zod`, `@hono/node-server`, `hono`, `fast-uri`). `npm audit fix` for the transitive packages; `zod` 3.25.76 → 4.6.7 (the high-severity GHSA-mrhx-c7xc-m3w5 is fixed only in zod ≥ 4.6.6; the MCP SDK supports `^3.25 || ^4.0`). Source unchanged apart from the version; `dist/index.js` rebuilt (identical apart from the version string); tool input schemas verified unchanged; 0 vulnerabilities.
- **DEPLOYMENT.md** — four parts (must-use plugins added — they were missing from the previous procedure); §1a must-use plugins and ARD; robots `Agentmap`; caches and WP Engine exemption for `/.well-known/*`; updated checks (versions, counts, ARD); rollback for ARD; §12 short update path from the live 2026-10 release.
- **tests/** — new `run-all.sh`, `validate-data-files.py`, `run-ard-tests.php` (71 checks, including real HTTP via PHP's built-in server), `ard-router.php`, `ard-stubs.php`, `validate-ard-manifest.py` and `ard-spec/` (unmodified official schema and context, Apache-2.0, see NOTICE.md); `run-plugin-tests.php` expected counts updated (154 Swedish releases, 35 Finnish pages); `mcp-smoke-test.sh` expects version 1.2.1; `live-checks.sh edge` also probes `/.well-known/ard.json`; README updated.

### Tests

| Suite | Result |
|---|---|
| ARD (`run-ard-tests.php`) | 71/71 |
| ARD manifest vs official v0.91 schema + Appendix D rules (`validate-ard-manifest.py`) | 0 errors, 0 warnings |
| Plugin (`run-plugin-tests.php`) | 108/108 |
| HubSpot, with token | 76/76 |
| HubSpot, without token | 68/68 |
| OpenAPI contact responses (`validate-openapi-examples.py`, real responses) | 61/61 |
| MCP smoke test (with zod 4) | 40/40 |
| All 17 data files vs OpenAPI 1.3.0 response schemas | 17/17 |

Five injected defects in the ARD code (url/data rule removed, head link removed, a tool missing from the MCP entry, duplicate check removed, and the language-prefixed `home_url()` restored) were each caught by the ARD tests.

**Not run:** the official ARD conformance tester (`conformance/bin/conformance-test` from ards-project/ard-spec). The build environment does not execute code downloaded from the internet, so the packaged validator applies the same official schema file and rules instead. Run `conformance-test publisher nepa.com` from a clone of the spec repository after deployment (DEPLOYMENT.md §6). As before, everything WordPress- and HubSpot-related was tested with stand-ins; the ARD endpoints were tested over real HTTP with PHP's built-in server, not on WP Engine.

### Issues found on the live site (for Nepa — not package changes)

- **Broken discovery links on all /sv/, /fi/ and /da/ pages** (from the deployed discovery plugin 1.0.0; fixed by deploying 1.1.0 from this package).
- **Indexable utility pages outside the sitemap:** the ad landing, thank-you, cancellation and copy pages listed under "Live pages deliberately excluded" are live without `noindex`; noindex or unpublish them. /home-2/ duplicates the homepage.
- **Attachment redirects:** /resources/, /sv/segmentation-2/ and /da/segmentation-2/ redirect to image files.
- **Empty blog post:** /blog/why-brand-salience-is-the-new-growth-driver/ is live (linked from the Insights pages) but has no body text and is attributed to `gomo_dev`.
- **New Insights topic pages and downloads are not in the sitemap** (WordPress `insight` and `download` types); add those types to the XML sitemap so search engines and agents find them.

- **Two menu links redirect:** header "Marketing Mix Modelling" (/products/marketing-mix-modelling/) and footer "Market Segmentation" (/products/market-segmentation/) both 301 to the real pages; link them directly.
- **Duplicate Organization structured data with a different YouTube channel:** every page carries two Organization nodes; the second lists `https://www.youtube.com/@nepa7919`, while the footer links `@NepaInsights`.
- **Finnish contact email:** /fi/contact/ shows `nepafinland@nepa.se` (a .se address) as the visible email while linking `mailto:info@nepa.com`. Check which is intended.
- **English site lists no Helsinki or Copenhagen address:** they appear only on the Finnish/Danish pages.
- **Financial calendar page is stale:** https://nepa.com/calendar/ still lists 2024–2025 dates; the current calendar is on /investor-relations/#financial-calendar.
- **Financial reports page stops at 2024:** /financial-reports-and-presentations/ lists reports up to Q2 2024; newer reports are only on /investor-relations/.
- **Finnish case pages declare no hreflang alternates** (nor do any pages in the HTML head); alternates appear only in the language switcher. Adding hreflang would let search engines and agents pair the languages.
- **Blog author attribution:** "Research should shape brand strategy…" is still attributed to the CMS account `gomo_dev` in structured data; the Black Week post's structured-data author (Christoffer Steiser) differs from its visible byline (Ulrika Berg).
- **Placeholder archive still indexable:** /case-category/case-category/ (empty) — noindex or remove.
- **Brand Tracking price wording** (unchanged from last month): the product page FAQ says "from 15,000 SEK/month", the price widget 16,300 SEK (1,500 EUR)/month.

### Deployment

The 2026-10 package is live. See **DEPLOYMENT.md §12** for the short update path and §1a for ARD. The MCP server needs a Render redeploy for the dependency fixes.

## Live-site checks renamed — 2026-10-19

There is no staging site, so the acceptance checks run on the live site.

- `tests/staging-checks.sh` is now `tests/live-checks.sh`. The modes and arguments are unchanged.
- The HubSpot test inquiry is labelled "LIVE TEST" / "Nepa live check" (previously "STAGING TEST" / "Nepa staging check").
- The checklist marks the failure tests (6 and 7) as optional. It warns that they hold back real inquiries while the setting is changed, and says how to recover them.
- `duplicates` still refuses nepa.com, because it creates test leads and sends emails.
- DEPLOYMENT.md §11 and tests/README.md were updated to match. Earlier changelog entries keep the old name.

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
