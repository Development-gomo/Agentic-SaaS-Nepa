"""Builds llms-full.txt (the full plain-text/Markdown reference for LLMs) from the package's own data.

  python3 tools/build-llms-full.py           # (re)writes llms-full.txt at the package root
  python3 tools/build-llms-full.py --check   # exit 1 if llms-full.txt is not what this script produces

Every fact comes from nepa-api/data/*.json (the same data the REST API serves) plus the FAQ on the
agent page; nothing is written by hand here, so the file stays in step with the API. Run it after
any data change and before a package build. Not deployed itself - only its output is.
"""
import html, json, os, re, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, 'nepa-api', 'data')
OUT = os.path.join(ROOT, 'llms-full.txt')


def load(name):
    return json.load(open(os.path.join(DATA, name), encoding='utf-8'))


def clean(s):
    return re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', ' ', str(s or '')))).strip()


def faq():
    page = open(os.path.join(ROOT, 'nepa-api', 'agent-page.html'), encoding='utf-8').read()
    sec = re.search(r'<section id="faq".*?</section>', page, re.S)
    out = []
    for q, a in re.findall(r'<summary>(.*?)</summary>(.*?)</details>', sec.group(0) if sec else '', re.S):
        out.append((clean(q), clean(a)))
    return out


def build():
    company, team, offices = load('company.json'), load('team.json'), load('offices.json')
    products, pricing, solutions = load('products.json'), load('pricing.json'), load('solutions.json')
    industries, cases, events = load('industries.json'), load('case-studies.json'), load('events.json')
    news, resources, ir = load('company-news.json'), load('resources.json'), load('investor-relations.json')
    jobs, posts, releases = load('jobs.json'), load('blog-posts.json'), load('press-releases.json')
    localized = load('localized-pages.json')
    release = company['meta']['release']
    updated = company['meta']['last_updated']
    L = []
    w = L.append

    w(f"# {company['name']} - full reference for LLMs")
    w('')
    w(f"> {company['tagline']}. {company['description']}")
    w('')
    w(f"Generated from the Nepa Agent API data (release {release}, content as of {updated}). Short version: "
      "[llms.txt](https://nepa.com/llms.txt). Structured data: [REST API](https://nepa.com/agent/v1/index.json), "
      "[OpenAPI](https://nepa.com/openapi.json), [MCP server](https://agentic-mcp-saas-nepa.onrender.com/mcp), "
      "[ARD manifest](https://nepa.com/.well-known/ard.json). Human-readable agent page: [/agent/](https://nepa.com/agent/). "
      "Cite Nepa by linking the specific source page given with each fact. Investor information is not investment advice.")

    w('\n## Company')
    w(clean(company['overview']))
    w('')
    w(f"- Legal name: {company['legal_name']}")
    w(f"- Headquarters: {company['headquarters']}")
    w(f"- Phone: {company['phone']} | Email: {company['email']} | Website: {company['website']}")
    lc = company.get('leadership_change') or {}
    if lc:
        w(f"- Leadership change: {clean(lc.get('summary') or lc.get('note') or json.dumps(lc, ensure_ascii=False))}")
    social = company.get('social') or {}
    if social:
        w('- Social: ' + ', '.join(f'[{k}]({v})' for k, v in social.items() if isinstance(v, str)))

    w('\n## Leadership and board')
    for p in team['team']:
        line = f"### {p['name']} - {p.get('title') or ''}".rstrip(' -')
        w(line)
        if p.get('status_note'):
            w(f"Note: {clean(p['status_note'])}")
        if p.get('bio'):
            w(clean(p['bio']))
        if p.get('url'):
            w(f"Source: {p['url']}")
        w('')

    w('## Offices and contact')
    for o in offices['offices']:
        parts = [o.get('city') or o.get('country'), o.get('role')]
        w(f"### {' - '.join(x for x in parts if x)}")
        for k, label in (('address', 'Address'), ('phone', 'Phone'), ('email', 'Email'), ('panel_email', 'Panel and participant questions'), ('contact_url', 'Contact page')):
            if o.get(k):
                w(f'- {label}: {o[k]}')
        for c in o.get('contacts') or []:
            w(f"- {c.get('name')}, {c.get('title')}: {c.get('email')} | {c.get('phone')}")
        w('')
    w('Agents can submit a B2B inquiry that a person has explicitly authorized through POST https://nepa.com/agent/v1/contact.json '
      'or the MCP tool submit_contact_inquiry (rules: https://nepa.com/ai.txt).')

    w('\n## Products')
    for p in products['products']:
        w(f"### {p['name']}")
        if p.get('description'):
            w(clean(p['description']))
        w(f"Page: {p['url']}")
        w('')

    w('## Pricing')
    w(f"Published \"from\" prices on {pricing.get('page_url', 'https://nepa.com/pricing/')}.")
    for n in pricing.get('notes') or []:
        w(f'- Note: {clean(n)}')
    w('')
    w('| Product | Plan | Price | Includes |')
    w('| --- | --- | --- | --- |')
    for p in pricing['plans']:
        inc = ''
        for x in (clean(x) for x in (p.get('includes') or [])):
            inc += ('' if not inc else (' ' if inc.endswith(':') else '; ')) + x.replace('|', '/')
        w(f"| {p['product']} | {p['tier']} | {clean(p['price'])} | {inc} |")

    w('\n## Solutions')
    for s in solutions['solutions']:
        w(f"### {s['name']}")
        w(clean(s.get('description')))
        w(f"Page: {s['url']}")
        w('')

    w('## Industries')
    for i in industries['industries']:
        w(f"- **{i['name']}**: {clean(i.get('description'))} ({i['url']})")

    w('\n## Case studies')
    for c in cases['case_studies']:
        w(f"### {c['title']}")
        meta = [f"Client: {c['client']}" if c.get('client') else '', f"Industry: {c['industry']}" if c.get('industry') else '',
                f"Service: {c['service']}" if c.get('service') else '']
        w(' | '.join(x for x in meta if x))
        w(clean(c.get('summary')))
        for r in c.get('results') or []:
            w(f"- {clean(r.get('metric'))}: {clean(r.get('value'))}")
        w(f"Source: {c['url']}")
        w('')
    w('Case-study results are past outcomes for a specific client, not guarantees.')

    w('\n## Investor relations')
    w(clean(ir.get('overview')))
    nr = ir.get('next_report') or {}
    if nr:
        w(f"- Next report: {nr.get('event')} on {nr.get('date')}")
    for a in ir.get('recent_announcements') or []:
        w(f"- {a['date']} - {a['title']} ({a.get('category', '')}): {clean(a.get('summary'))} {a['url']}")
    w('\nFinancial calendar:')
    for c in ir.get('financial_calendar') or []:
        w(f"- {c['date']}: {c['event']}")
    w('\nFinancial reports:')
    for r in ir.get('financial_reports') or []:
        w(f"- {r['date']}: [{r['title']}]({r['url']})")
    for p in ir.get('presentations') or []:
        w(f"- Presentation {p['date']}: [{p['title']}]({p['url']})")

    w('\n## Events and webinars')
    for status in ('upcoming', 'past', 'on-demand'):
        items = [e for e in events['events'] if e.get('status') == status]
        w(f"\n### {status.capitalize()} ({len(items)})")
        if not items:
            w('None published.')
        for e in items:
            bits = [x for x in (e.get('date'), e.get('location')) if x]
            w(f"- [{e['title']}]({e['url']})" + (f" - {', '.join(bits)}" if bits else '') + (f": {clean(e['summary'])}" if e.get('summary') else ''))

    w('\n## Careers')
    w(clean(jobs.get('note')) or 'See https://nepa.com/jobs/.')

    w('\n## Client news')
    for n in news['news']:
        w(f"- [{n['title']}]({n['url']})" + (f": {clean(n['excerpt'])}" if n.get('excerpt') else ''))

    w('\n## Reports, downloads and landing pages')
    for r in resources['resources']:
        w(f"- [{r['title']}]({r['url']}) ({r['type']})" + (f": {clean(r['summary'])}" if r.get('summary') else ''))

    q = faq()
    if q:
        w('\n## FAQ (from the Brand Tracking product page)')
        for question, answer in q:
            w(f'\n### {question}')
            w(answer)

    w(f"\n## Blog posts ({posts['total']}, newest first)")
    for p in posts['posts']:
        by = f" by {p['author']}" if p.get('author') else ''
        lang = '' if p.get('language') == 'en' else f" [{p.get('language')}]"
        w(f"- {p['date']}{lang} [{clean(p['title'])}]({p['url']}){by}" + (f": {clean(p['excerpt'])}" if p.get('excerpt') else ''))

    w(f"\n## Press releases ({releases['total']}, newest first)")
    for r in releases['releases']:
        flag = ' (regulatory)' if r.get('regulatory') else ''
        w(f"- {r['date']} [{r['language']}] [{clean(r['title'])}]({r['url']}){flag}")

    w(f"\n## Swedish, Finnish and Danish pages ({localized['count']})")
    for p in localized['pages']:
        en = f" - English: {p['english_equivalent']}" if p.get('english_equivalent') else ''
        w(f"- [{p['language']}] [{clean(p['title'])}]({p['url']}) ({p['section']}){en}")

    return '\n'.join(L).rstrip() + '\n'


if __name__ == '__main__':
    text = build()
    if '--check' in sys.argv:
        current = open(OUT, encoding='utf-8').read() if os.path.exists(OUT) else ''
        if current != text:
            print('FAIL  llms-full.txt is out of date - run python3 tools/build-llms-full.py')
            sys.exit(1)
        print(f'PASS  llms-full.txt is up to date ({len(text.encode())} bytes)')
        sys.exit(0)
    open(OUT, 'w', encoding='utf-8').write(text)
    print(f'wrote {OUT} ({len(text.encode())} bytes, {text.count(chr(10))} lines)')
