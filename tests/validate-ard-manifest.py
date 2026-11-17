"""Validates an ARD manifest against the official ARD v0.91 schema and discovery rules.

  python3 tests/validate-ard-manifest.py <manifest.json | http(s)://.../.well-known/ard.json> [publisher]

Checks (spec v0.91, Appendix D):
  - the document validates as ArdManifest and every entry as ArdEntry, using the unmodified official
    schema in tests/ard-spec/ard-entry.schema.json (Draft 2020-12; needs the jsonschema package -
    without it the rule checks below still run but the result is INCOMPLETE, exit code 3,
    unless ALLOW_SKIP=1);
  - identifiers are urn:air: URNs, unique, and (when given) anchored on the expected publisher;
  - exactly one of url/data; type looks like a media type;
  - representativeQueries present with 2-5 examples (a WARNING, as in the official tester, §D.2);
  - trustManifest, when present, has identity whose domain matches the URN publisher (§4.5.1).
Exit code 0 = no errors (warnings allowed), 1 = errors, 3 = incomplete. This does not replace the official tester
(ards-project/ard-spec conformance/bin/conformance-test); see tests/README.md.
"""
import json, os, re, sys, urllib.parse, urllib.request

root = os.path.dirname(os.path.abspath(__file__))
if len(sys.argv) < 2:
    sys.exit(__doc__)
src, publisher = sys.argv[1], (sys.argv[2] if len(sys.argv) > 2 else None)
raw = urllib.request.urlopen(src, timeout=20).read() if re.match(r'^https?://', src) else open(src, 'rb').read()
errors, warnings = [], []
schema_skipped = False
try:
    doc = json.loads(raw)
except ValueError as e:
    print(f'FAIL  malformed JSON: {e}'); sys.exit(1)

try:
    import jsonschema
    schema = json.load(open(os.path.join(root, 'ard-spec', 'ard-entry.schema.json'), encoding='utf-8'))
    defs = schema['$defs']
    def as_def(name):
        return {'$schema': schema['$schema'], '$defs': defs, '$ref': f'#/$defs/{name}'}
    v = jsonschema.Draft202012Validator
    for e in v(as_def('ArdManifest')).iter_errors(doc):
        errors.append(f'schema ArdManifest: {e.message[:160]} at {"/".join(map(str, e.absolute_path))}')
    for i, entry in enumerate(doc.get('entries', []) if isinstance(doc, dict) else []):
        for e in v(as_def('ArdEntry')).iter_errors(entry):
            errors.append(f'schema ArdEntry #{i}: {e.message[:160]} at {"/".join(map(str, e.absolute_path))}')
    print('PASS  official ARD v0.91 JSON schema' if not errors else 'FAIL  official ARD v0.91 JSON schema')
except ImportError:
    schema_skipped = True
    print('INCOMPLETE  official JSON schema check skipped (pip install jsonschema)')

def identity_matches(identity, publisher):
    m = re.match(r'^(?:https?|spiffe)://([^/:?#]+)', identity, re.I) or re.match(r'^did:web:([^:]+)', identity, re.I)
    host = (urllib.parse.unquote(m.group(1)) if m else identity).lower()
    publisher = publisher.lower()
    return host == publisher or host.endswith('.' + publisher)


URN = re.compile(r'^urn:air:[a-zA-Z0-9.-]+(:[a-zA-Z0-9._-]+)+$')
MEDIA = re.compile(r'^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*(\s*;.*)?$', re.I)
entries = doc.get('entries') if isinstance(doc, dict) else None
if not isinstance(entries, list):
    errors.append("missing 'entries' array")
    entries = []
seen = set()
for i, e in enumerate(entries):
    label = f'#{i} {e.get("identifier", "?") if isinstance(e, dict) else "?"}'
    if not isinstance(e, dict):
        errors.append(f'{label}: entry is not an object'); continue
    ident = e.get('identifier', '')
    if not URN.match(ident):
        errors.append(f'{label}: identifier is not a urn:air: URN')
    elif publisher and ident.split(':')[2] != publisher:
        errors.append(f'{label}: publisher segment is not {publisher}')
    if ident in seen:
        errors.append(f'{label}: duplicate identifier')
    seen.add(ident)
    for t in ('displayName', 'type'):
        if not isinstance(e.get(t), str) or not e[t].strip():
            errors.append(f'{label}: missing {t}')
    if isinstance(e.get('type'), str) and not MEDIA.match(e['type']):
        errors.append(f'{label}: type is not a media type')
    if ('url' in e) == ('data' in e):
        errors.append(f'{label}: exactly one of url/data is required')
    q = e.get('representativeQueries')
    if not isinstance(q, list) or not 2 <= len(q) <= 5:
        warnings.append(f'{label}: representativeQueries should hold 2-5 examples')
    tm = e.get('trustManifest')
    if tm is not None:
        ident_dom = ident.split(':')[2] if URN.match(ident) else ''
        if not isinstance(tm, dict) or not tm.get('identity'):
            errors.append(f'{label}: trustManifest requires identity')
        elif ident_dom and not identity_matches(str(tm['identity']), ident_dom):
            errors.append(f'{label}: trustManifest.identity does not align with publisher {ident_dom}')
for w in warnings:
    print(f'WARN  {w}')
for e in errors:
    print(f'FAIL  {e}')
print(f'{"PASS" if not errors else "FAIL"}  ARD manifest: {len(entries)} entries, {len(errors)} errors, {len(warnings)} warnings')
if errors:
    sys.exit(1)
if schema_skipped and os.environ.get('ALLOW_SKIP') != '1':
    print('INCOMPLETE  rule checks passed, but the schema check did not run (exit 3)')
    sys.exit(3)
sys.exit(0)
