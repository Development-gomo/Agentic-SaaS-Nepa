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


def empty_objects(node, path=''):
    """Paths of empty JSON objects. The plugin re-encodes filtered/paginated responses with PHP,
    which turns an empty object {} into an empty array [] - a type the schema doesn't allow -
    so data files must use null (or leave the field out) instead."""
    found = []
    if isinstance(node, dict):
        if not node:
            found.append(path or '/')
        for k, v in node.items():
            found += empty_objects(v, f'{path}/{k}')
    elif isinstance(node, list):
        for i, v in enumerate(node):
            found += empty_objects(v, f'{path}/{i}')
    return found


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
    doc = json.load(open(f, encoding='utf-8'))
    errors = list(jsonschema.Draft7Validator({**schema, 'definitions': defs}).iter_errors(doc))
    empties = empty_objects(doc)
    checked += 1
    fails += bool(errors or empties)
    if errors:
        print(f"FAIL  {name}: {errors[0].message[:160]} at {'/'.join(map(str, errors[0].absolute_path))}")
    elif empties:
        print(f"FAIL  {name}: empty object(s) at {', '.join(empties[:3])} - use null (the plugin would serve them as [])")
    else:
        print(f'PASS  {name}')
print(f'\n{checked - (fails if fails <= checked else checked)} of {checked} data files valid, {fails} failed')
sys.exit(1 if fails else 0)
