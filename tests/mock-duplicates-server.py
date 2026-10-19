"""Validates tests/staging-checks.sh 'duplicates' mode without a real site.

  python3 tests/mock-duplicates-server.py 3931 correct   # one lead per submission (should PASS)
  python3 tests/mock-duplicates-server.py 3931 buggy     # check-then-act race (should FAIL)
  bash tests/staging-checks.sh duplicates http://127.0.0.1:3931

'correct' reserves each submission atomically (like the plugin): repeats get the original
lead, overlapping ones get 409 in_progress, key reuse with other content gets 409.
'buggy' checks for an existing lead and creates one without atomicity, so simultaneous
requests all create leads - the defect the staging check must catch.
"""
import json, sys, threading, time, hashlib, itertools
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler

BUGGY = sys.argv[2] == 'buggy'
lock = threading.Lock(); reservations = {}; ids = itertools.count(1)

class Handler(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def send(self, code, obj):
        raw = json.dumps(obj).encode()
        self.send_response(code); self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(raw))); self.end_headers(); self.wfile.write(raw)

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        key = body['agent_context'].get('idempotency_key')
        fp = hashlib.sha256(json.dumps([body['person'], body.get('company'), body.get('inquiry')], sort_keys=True).encode()).hexdigest()
        scope = 'k:' + key if key else 'f:' + fp
        received = lambda lead, dup: self.send(200, {'status': 'received', 'duplicate': dup, 'lead_id': lead, 'hubspot': {'status': 'delivered'}})
        if BUGGY:
            current = reservations.get(scope); time.sleep(0.2)
            if current and current['lead']:
                return received(current['lead'], True)
            lead = 'L%d' % next(ids); reservations[scope] = {'fp': fp, 'lead': lead}
            return received(lead, False)
        with lock:
            current = reservations.get(scope)
            if current is None:
                reservations[scope] = {'fp': fp, 'lead': None}
        if current is not None:
            if current['fp'] != fp:
                return self.send(409, {'error': 'idempotency_key_reused'})
            if current['lead']:
                return received(current['lead'], True)
            return self.send(409, {'error': 'in_progress'})
        time.sleep(0.3)
        lead = 'L%d' % next(ids); reservations[scope]['lead'] = lead
        received(lead, False)

ThreadingHTTPServer(('127.0.0.1', int(sys.argv[1])), Handler).serve_forever()
