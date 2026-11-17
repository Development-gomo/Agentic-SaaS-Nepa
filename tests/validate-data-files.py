"""Validates every bundled data file in nepa-api/data/ against its GET response schema in nepa-api/openapi.json.

  python3 tests/validate-data-files.py [data-dir]

The optional argument validates another folder with the same file names (e.g. a synced generation
in wp-content/uploads/nepa-agent-api/gen-.../). Needs the jsonschema package. Without it the result
is INCOMPLETE (exit code 3) - never a pass - unless ALLOW_SKIP=1 is set.
Exit codes: 0 all valid, 1 a file is invalid or missing, 3 incomplete.
"""
import json, os, sys

root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
data_dir = sys.argv[1] if len(sys.argv) > 1 else os.path.join(root, 'nepa-api', 'data')
try:
    import jsonschema
except ImportError:
    print('INCOMPLETE  jsonschema not installed (pip install jsonschema); data files were NOT validated')
    sys.exit(0 if os.environ.get('ALLOW_SKIP') == '1' else 3)

spec = json.load(open(os.path.join(root, 'nepa-api', 'openapi.json'), encoding='utf-8'))


def conv(node):
    """OpenAPI 3.0 -> JSON Schema: 'nullable' and component $refs."""
    if isinstance(node, dict):
        node = {k: conv(v) for k, v in node.items()}
        if node.pop('nullable', False):
            if 'type' in node:
                node['type'] = [node['type'], 'null']
            if 'enum' in node:
                node['enum'] = node['enum'] + [None]
        if '$ref' in node:
            node['$ref'] = node['$ref'].replace('#/components/schemas/', '#/definitions/')
        return node
    if isinstance(node, list):
        return [conv(x) for x in node]
    return node


defs = conv(spec['components']['schemas'])
fails = checked = 0
for path, ops in spec['paths'].items():
    get = ops.get('get')
    name = path.strip('/')
    if not get or not name.endswith('.json') or name == 'index.json':
        continue
    f = os.path.join(data_dir, name)
    if not os.path.exists(f):
        print(f'FAIL  {name}: missing')
        fails += 1
        continue
    schema = conv(get['responses']['200']['content']['application/json']['schema'])
    errors = list(jsonschema.Draft7Validator({**schema, 'definitions': defs}).iter_errors(json.load(open(f, encoding='utf-8'))))
    checked += 1
    fails += bool(errors)
    print(('PASS' if not errors else 'FAIL') + f'  {name}' + ('' if not errors else f": {errors[0].message[:160]} at {'/'.join(map(str, errors[0].absolute_path))}"))
print(f'\n{checked - (fails if fails <= checked else checked)} of {checked} data files valid, {fails} failed')
sys.exit(1 if fails else 0)
