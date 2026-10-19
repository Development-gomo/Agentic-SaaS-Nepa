#!/usr/bin/env bash
# The two remaining go-live checks from DEPLOYMENT.md §10, against a real site.
#
#   bash tests/staging-checks.sh duplicates https://staging.example.com [WP-CLI command]
#   bash tests/staging-checks.sh edge       https://nepa.com [origin-host]
#
# duplicates - CREATES TEST LEADS AND SENDS NOTIFICATION EMAILS. Staging only: refuses
#              nepa.com / www.nepa.com. Fires simultaneous identical requests (same
#              idempotency key, then same content without a key) and checks that exactly
#              one lead is accepted per scenario. If you pass a WP-CLI command (e.g.
#              "wp --path=/var/www/html" or "ssh install@install.ssh.wpengine.net wp"),
#              it also counts the stored leads in the database. Uses about 2 of the
#              5-per-10-minutes contact quota for your IP; wait 10 minutes between runs.
#
# edge       - read-only GET requests. Checks that common AI agents reach the agent
#              endpoints with HTTP 200 (no Cloudflare challenge, block or 429), and - if
#              you give the origin host (e.g. nepa.wpengine.com) - whether the origin can
#              be reached directly, bypassing the edge.
#
# Requires bash, curl and python3. Exit code 0 = check passed.
set -uo pipefail
MODE="${1:-}"; BASE="${2:-}"; EXTRA="${3:-}"
if [ -z "$MODE" ] || [ -z "$BASE" ]; then sed -n '2,24p' "$0"; exit 2; fi
BASE="${BASE%/}"
PASS=0; FAIL=0
check() { if [ "$2" = "true" ]; then PASS=$((PASS+1)); echo "PASS  $1"; else FAIL=$((FAIL+1)); echo "FAIL  $1"; fi; }
WORK="$(mktemp -d "${TMPDIR:-/tmp}/nepa-staging.XXXXXX")"; trap 'rm -rf "$WORK"' EXIT
json() { python3 -c "import json,sys
try: d=json.load(open(sys.argv[1]))
except Exception: d={}
print(d.get(sys.argv[2],''))" "$1" "$2"; }

duplicates() {
  case "$BASE" in
    https://nepa.com|https://www.nepa.com|http://nepa.com|http://www.nepa.com)
      echo "Refusing to create test leads on production ($BASE). Use the staging URL."; exit 2;;
  esac
  local N=10 run; run="$(date +%s)-$$"
  echo "Sending test submissions to $BASE - each scenario should create exactly ONE lead and ONE email."
  scenario() {   # $1 label, $2 JSON body
    local label="$1" body="$2" i
    for i in $(seq 1 $N); do
      curl -s -o "$WORK/$label.$i.json" -w '%{http_code}' -X POST "$BASE/agent/v1/contact.json" \
        -H 'Content-Type: application/json' --data "$body" --max-time 30 > "$WORK/$label.$i.code" &
    done
    wait
    local accepted=0 dup=0 inflight=0 other=0 leads=""
    for i in $(seq 1 $N); do
      local code; code="$(cat "$WORK/$label.$i.code")"
      if [ "$code" = 200 ]; then
        leads="$leads $(json "$WORK/$label.$i.json" lead_id)"
        if [ "$(json "$WORK/$label.$i.json" duplicate)" = "False" ]; then accepted=$((accepted+1)); else dup=$((dup+1)); fi
      elif [ "$code" = 409 ] && [ "$(json "$WORK/$label.$i.json" error)" = in_progress ]; then inflight=$((inflight+1))
      else other=$((other+1)); echo "      unexpected HTTP $code: $(head -c 200 "$WORK/$label.$i.json")"; fi
    done
    local distinct; distinct="$(echo $leads | tr ' ' '\n' | sort -u | grep -c . || true)"
    echo "  $label: $N simultaneous -> accepted=$accepted duplicate=$dup in_progress=$inflight other=$other distinct_lead_ids=$distinct"
    check "$label: exactly one request accepted as new" "$([ $accepted = 1 ] && echo true || echo false)"
    check "$label: every 200 response refers to the same lead" "$([ "$distinct" = 1 ] && echo true || echo false)"
    check "$label: no errors other than 409 in_progress" "$([ $other = 0 ] && echo true || echo false)"
    sleep 2
    local code; code=$(curl -s -o "$WORK/$label.after.json" -w '%{http_code}' -X POST "$BASE/agent/v1/contact.json" -H 'Content-Type: application/json' --data "$body")
    check "$label: a later identical retry returns duplicate=true and the same lead" \
      "$([ "$code" = 200 ] && [ "$(json "$WORK/$label.after.json" duplicate)" = True ] && echo $leads | grep -q -- "$(json "$WORK/$label.after.json" lead_id)" && echo true || echo false)"
    echo "$(echo $leads | tr ' ' '\n' | sort -u | head -1)" > "$WORK/$label.lead"
  }
  local key="staging-check-$run"
  local base_person="\"person\":{\"name\":\"STAGING TEST - delete me\",\"email\":\"staging-check+$run@example.com\"},\"company\":{\"name\":\"Nepa staging check\"}"
  scenario keyed "{\"agent_context\":{\"user_authorized_submission\":true,\"idempotency_key\":\"$key\"},$base_person,\"inquiry\":{\"service_interest\":\"Test\",\"project_summary\":\"Concurrency check $run (keyed)\"}}"
  local code
  code=$(curl -s -o "$WORK/reuse.json" -w '%{http_code}' -X POST "$BASE/agent/v1/contact.json" -H 'Content-Type: application/json' \
    --data "{\"agent_context\":{\"user_authorized_submission\":true,\"idempotency_key\":\"$key\"},$base_person,\"inquiry\":{\"project_summary\":\"DIFFERENT content $run\"}}")
  check "same key with different content -> 409 idempotency_key_reused" "$([ "$code" = 409 ] && [ "$(json "$WORK/reuse.json" error)" = idempotency_key_reused ] && echo true || echo false)"
  local base_person2="\"person\":{\"name\":\"STAGING TEST - delete me\",\"email\":\"staging-check2+$run@example.com\"},\"company\":{\"name\":\"Nepa staging check\"}"
  scenario fingerprint "{\"agent_context\":{\"user_authorized_submission\":true},$base_person2,\"inquiry\":{\"service_interest\":\"Test\",\"project_summary\":\"Concurrency check $run (no key)\"}}"
  if [ -n "$EXTRA" ]; then
    local count
    count=$($EXTRA post list --post_type=nepa_lead --post_status=private --s="Concurrency check $run" --format=count 2>/dev/null)
    check "database holds exactly 2 test leads for this run (found: ${count:-?})" "$([ "$count" = 2 ] && echo true || echo false)"
  else
    echo "INFO  In wp-admin -> Agent Leads, search \"Concurrency check $run\": there must be exactly 2 leads (one per scenario), and 2 emails. Then delete them."
  fi
}

edge() {
  local uas=(
    "Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)"
    "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ChatGPT-User/1.0; +https://openai.com/bot)"
    "Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)"
    "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)"
    "Claude-User/1.0"
    "Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)"
    "Mozilla/5.0 (compatible; Google-Extended)"
    "node"
    "python-requests/2.32.3"
  )
  local ua name url_path code body
  for ua in "${uas[@]}"; do
    name="$(echo "$ua" | grep -oE 'GPTBot|ChatGPT-User|OAI-SearchBot|ClaudeBot|Claude-User|PerplexityBot|Google-Extended|python-requests|^node$' | head -1)"
    for url_path in /agent/v1/index.json /agent/v1/press-releases.json?limit=1 /agent/ /openapi.json /llms.txt; do
      code=$(curl -s -o "$WORK/edge" -w '%{http_code}' -A "$ua" --max-time 20 "$BASE$url_path")
      body=""; grep -qiE 'Attention Required|Just a moment|you have been blocked|cf-chl' "$WORK/edge" && body=" (Cloudflare challenge/block page)"
      check "$name GET $url_path -> HTTP $code$body" "$([ "$code" = 200 ] && [ -z "$body" ] && echo true || echo false)"
      sleep 1
    done
  done
  if [ -n "$EXTRA" ]; then
    code=$(curl -s -o "$WORK/origin" -w '%{http_code}' --max-time 20 -H 'CF-Connecting-IP: 198.51.100.7' "https://$EXTRA/agent/v1/index.json")
    if [ "$code" = 200 ]; then
      echo "WARN  origin https://$EXTRA answers directly (HTTP 200), bypassing the edge: anyone can send a fake CF-Connecting-IP there."
      echo "      Do NOT enable NEPA_TRUST_CLOUDFLARE_IP unless direct origin access is blocked."
      check "origin not directly reachable (required before enabling NEPA_TRUST_CLOUDFLARE_IP)" false
    else
      check "origin not directly reachable (HTTP $code) - NEPA_TRUST_CLOUDFLARE_IP may be enabled if REMOTE_ADDR is the edge IP" true
    fi
  else
    echo "INFO  Origin check skipped. Pass the origin host as the 3rd argument (e.g. nepa.wpengine.com)."
  fi
}

case "$MODE" in
  duplicates) duplicates;;
  edge) edge;;
  *) echo "Unknown mode: $MODE (use duplicates or edge)"; exit 2;;
esac
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
