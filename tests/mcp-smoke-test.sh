#!/usr/bin/env bash
# MCP server smoke test. Usage (from the package root): bash tests/mcp-smoke-test.sh
# Copies nepa-mcp/ (without node_modules) into a temporary workspace and does everything
# there: installs the locked dependencies, type-checks, builds, audits, starts the server
# against the package's own data files (served read-only by a throwaway local HTTP server)
# and checks the responses. The package itself is never modified - the last check
# verifies that - and only the temporary workspace is deleted afterwards.
# Requires Node 20+, npm and python3.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PKG_MCP="$ROOT/nepa-mcp"; DATA="$ROOT/nepa-api/data"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/nepa-mcp-smoke.XXXXXX")"
MCP="$WORK/nepa-mcp"
API_PORT=3918; MCP_PORT=3917; PASS=0; FAIL=0
check() { if [ "$2" = "true" ]; then PASS=$((PASS+1)); echo "PASS  $1"; else FAIL=$((FAIL+1)); echo "FAIL  $1"; fi; }
cleanup() { kill "${API_PID:-}" "${MCP_PID:-}" 2>/dev/null || true; wait 2>/dev/null || true; rm -rf "$WORK"; }
trap cleanup EXIT
fingerprint() { (cd "$PKG_MCP" && find . -type f -print0 | sort -z | xargs -0 shasum | shasum | cut -d' ' -f1); }
BEFORE="$(fingerprint)"
mkdir -p "$MCP"
(cd "$PKG_MCP" && tar --exclude=./node_modules -cf - .) | (cd "$MCP" && tar -xf -)

node -e 'process.exit(parseInt(process.versions.node) >= 20 ? 0 : 1)' && check "Node >= 20 ($(node -v))" true || check "Node >= 20 ($(node -v))" false
cd "$MCP"
npm ci --no-fund --no-audit >/dev/null
npm run build >/dev/null
AUDIT=$(npm audit --json | python3 -c 'import json,sys;print(json.load(sys.stdin)["metadata"]["vulnerabilities"]["total"])')
check "npm audit: 0 vulnerabilities (found $AUDIT)" "$([ "$AUDIT" = 0 ] && echo true || echo false)"
npx --no-install tsc --noEmit -p . >/dev/null 2>&1 && check "TypeScript compiles cleanly" true || check "TypeScript compiles cleanly" false

python3 -m http.server "$API_PORT" --bind 127.0.0.1 --directory "$DATA" >/dev/null 2>&1 & API_PID=$!
PORT=$MCP_PORT NEPA_API_BASE="http://127.0.0.1:$API_PORT" node dist/index.js >/dev/null 2>&1 & MCP_PID=$!
for _ in $(seq 1 40); do curl -sf "http://127.0.0.1:$MCP_PORT/health" >/dev/null && break; sleep 0.25; done

rpc() { curl -s "http://127.0.0.1:$MCP_PORT/mcp" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "$1" | sed -n 's/^data: //p'; }
TOOLS=$(rpc '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
py() { python3 -c "$1" <<<"$2"; }
check "18 tools listed" "$(py 'import json,sys;print(str(len(json.load(sys.stdin)["result"]["tools"])==18).lower())' "$TOOLS")"
check "17 tools annotated read-only" "$(py 'import json,sys;t=json.load(sys.stdin)["result"]["tools"];print(str(sum(1 for x in t if x.get("annotations",{}).get("readOnlyHint"))==17).lower())' "$TOOLS")"
check "submit_contact_inquiry is an idempotent, non-read-only write with idempotency_key" "$(py 'import json,sys;t=[x for x in json.load(sys.stdin)["result"]["tools"] if x["name"]=="submit_contact_inquiry"][0];a=t["annotations"];print(str((not a["readOnlyHint"]) and a["idempotentHint"] and "idempotency_key" in t["inputSchema"]["properties"]).lower())' "$TOOLS")"
R=$(rpc '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"get_case_study","arguments":{"id":"case-measure-the-value-advertising-builds-over-time"}}}')
check "get_case_study returns the Lantmännen case" "$(py 'import json,sys;print(str("Lantm" in json.load(sys.stdin)["result"]["content"][0]["text"]).lower())' "$R")"
LONG=$(python3 -c 'print("a"*5001)')
R=$(rpc "{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"tools/call\",\"params\":{\"name\":\"submit_contact_inquiry\",\"arguments\":{\"user_authorized_submission\":true,\"name\":\"x\",\"email\":\"x@example.com\",\"project_summary\":\"$LONG\"}}}")
check "submit rejects a summary over 5,000 characters before calling the API" "$(py 'import json,sys;r=json.load(sys.stdin)["result"];print(str(r.get("isError") is True and "5000" in r["content"][0]["text"]).lower())' "$R")"
R=$(rpc '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"submit_contact_inquiry","arguments":{"user_authorized_submission":false,"name":"x","email":"x@example.com"}}}')
check "submit rejects user_authorized_submission=false" "$(py 'import json,sys;print(str(json.load(sys.stdin)["result"].get("isError") is True).lower())' "$R")"
HDR=$(curl -s -i -X OPTIONS "http://127.0.0.1:$MCP_PORT/mcp" -H 'Origin: https://example.com')
check "CORS preflight answers 204 with Access-Control-Allow-Origin" "$(grep -q '204' <<<"$HDR" && grep -qi 'access-control-allow-origin: \*' <<<"$HDR" && echo true || echo false)"
check "/health reports version 1.1.0" "$(curl -s "http://127.0.0.1:$MCP_PORT/health" | grep -q '"version":"1.1.0"' && echo true || echo false)"
CODE=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$MCP_PORT/health?deep=1")
check "/health?deep=1 reports 503 when the API has no index.json (static test server)" "$([ "$CODE" = 503 ] && echo true || echo false)"

check "package nepa-mcp/ is unchanged (no node_modules added, dist not rewritten)" "$([ "$(fingerprint)" = "$BEFORE" ] && echo true || echo false)"

echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
