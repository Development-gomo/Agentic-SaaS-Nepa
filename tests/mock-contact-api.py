"""Mock of the Nepa agent API for the MCP smoke test.

GET  serves the package's data files (like the real read-only endpoints).
POST /contact.json answers like the real endpoint for each delivery outcome, chosen by
the local part of person.email: delivered@, pending@, retryable@, uncertain@,
manual_review@, disabled@, rejected@, inprogress@, unavailable@, slow@ (no answer in time).
Every received body is appended to the file given as the 3rd argument, so the test can
check exactly what the MCP server sent.
"""
import json, sys, time
from http.server import ThreadingHTTPServer, SimpleHTTPRequestHandler

PORT, DATA, LOG = int(sys.argv[1]), sys.argv[2], sys.argv[3]

class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *a, **k):
        super().__init__(*a, directory=DATA, **k)

    def log_message(self, *a):
        pass

    def reply(self, code, body):
        raw = json.dumps(body).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get('Content-Length', 0))) or b'{}')
        with open(LOG, 'a') as f:
            f.write(json.dumps(body) + '\n')
        case = body.get('person', {}).get('email', '').split('@')[0]
        lead = {'lead_id': 'nepa-lead-2026-TEST', 'duplicate': False}
        if case == 'slow':
            time.sleep(4)
        if case in ('delivered', 'pending', 'retryable', 'uncertain', 'manual_review', 'disabled', 'slow'):
            return self.reply(200, {'status': 'received', **lead, 'message': 'x', 'hubspot': {'status': case, 'attempts': 1, 'message': 'x'}})
        if case == 'rejected':
            return self.reply(422, {'status': 'rejected', 'error': 'hubspot_rejected', **lead, 'hubspot': {'status': 'rejected', 'errors': ['BLOCKED_EMAIL']}})
        if case == 'inprogress':
            return self.reply(409, {'error': 'in_progress', 'message': 'x'})
        if case == 'unavailable':
            return self.reply(503, {'error': 'reservation_unavailable', 'message': 'x'})
        return self.reply(422, {'error': 'missing_required_fields', 'message': 'x'})

ThreadingHTTPServer(('127.0.0.1', PORT), Handler).serve_forever()
