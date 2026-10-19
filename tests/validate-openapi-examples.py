"""Validates example /contact.json responses against nepa-api/openapi.json.

  python3 tests/validate-openapi-examples.py [responses.jsonl]

With a file argument it also validates every response the plugin actually produced in the
HubSpot test suite (NEPA_DUMP_RESPONSES=responses.jsonl php tests/run-hubspot-tests.php token).

Needs the jsonschema package (pip install jsonschema); exits 0 with a SKIP note if it is
missing. Each example is the exact shape the plugin returns for that case.
"""
import json, os, sys
try:
    import jsonschema
except ImportError:
    print('SKIP  jsonschema not installed (pip install jsonschema)'); sys.exit(0)

root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
spec = json.load(open(os.path.join(root, 'nepa-api', 'openapi.json'), encoding='utf-8'))

def to_json_schema(node):
    """OpenAPI 3.0 -> JSON Schema: 'nullable' and component $refs."""
    if isinstance(node, dict):
        node = {k: to_json_schema(v) for k, v in node.items()}
        if node.pop('nullable', False):
            if 'type' in node: node['type'] = [node['type'], 'null']
            if 'enum' in node: node['enum'] = node['enum'] + [None]
        if '$ref' in node: node['$ref'] = node['$ref'].replace('#/components/schemas/', '#/definitions/')
        return node
    if isinstance(node, list): return [to_json_schema(x) for x in node]
    return node

defs = to_json_schema(spec['components']['schemas'])
responses = spec['paths']['/contact.json']['post']['responses']
def schema_for(code):
    s = to_json_schema(responses[code]['content']['application/json']['schema'])
    return {**s, 'definitions': defs}

hubspot = lambda status, **extra: {'status': status, 'attempts': 1, 'message': 'x', **extra}
examples = [
    ('200', 'delivered', {'status': 'received', 'duplicate': False, 'lead_id': 'nepa-lead-2026-AB12CD34', 'message': 'x', 'hubspot': hubspot('delivered'), 'next_step': 'x'}, True),
    ('200', 'duplicate, retrying', {'status': 'received', 'duplicate': True, 'lead_id': 'nepa-lead-2026-AB12CD34', 'message': 'x', 'hubspot': hubspot('retryable'), 'next_step': 'x'}, True),
    ('422', 'validation error', {'error': 'missing_required_fields', 'message': 'Required: person.phone.'}, True),
    ('422', 'HubSpot rejection', {'status': 'rejected', 'duplicate': False, 'lead_id': 'nepa-lead-2026-AB12CD34', 'message': 'x', 'hubspot': hubspot('rejected', errors=['BLOCKED_EMAIL']), 'error': 'hubspot_rejected'}, True),
    ('409', 'in progress', {'error': 'in_progress', 'message': 'x'}, True),
    ('503', 'not recorded', {'error': 'reservation_unavailable', 'message': 'x'}, True),
    ('422', 'invalid: error body with a status', {'status': 'rejected', 'error': 'x', 'message': 'x'}, False),
    ('200', 'invalid: missing hubspot', {'status': 'received', 'duplicate': False, 'lead_id': 'x', 'message': 'x'}, False),
]
if len(sys.argv) > 1:
    for i, line in enumerate(open(sys.argv[1], encoding='utf-8')):
        rec = json.loads(line)
        code = str(rec['code'])
        if code in responses and 'content' in responses[code]:
            examples.append((code, f'real response #{i + 1}', rec['body'], True))
fails = 0
for code, label, body, should_pass in examples:
    errors = list(jsonschema.Draft7Validator(schema_for(code)).iter_errors(body))
    ok = (not errors) == should_pass
    fails += not ok
    print(('PASS' if ok else 'FAIL') + f'  {code} {label}: ' + ('valid' if not errors else 'invalid') + ('' if ok else f' -> {errors[0].message[:120]}'))
print(f'\n{len(examples) - fails} passed, {fails} failed')
sys.exit(1 if fails else 0)
