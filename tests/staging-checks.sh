#!/usr/bin/env bash
# The two remaining go-live checks from DEPLOYMENT.md §10, against a real site.
#
#   bash tests/staging-checks.sh duplicates https://staging.example.com [WP-CLI command]
#   bash tests/staging-checks.sh edge       https://nepa.com [origin-host]
#   bash tests/staging-checks.sh hubspot    https://<mcp-host>/mcp <test-email>
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
# hubspot    - SUBMITS A REAL INQUIRY TO NEPA'S HUBSPOT FORM through the deployed MCP tool
#              (company "Nepa staging check", names "STAGING TEST"), then repeats it to check
#              that no second inquiry or HubSpot submission is created, and prints the
#              checklist to verify in HubSpot. Use a mailbox you control on a business
#              domain (free webmail is rejected by the form).
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
      if [ "$code" = 200 ] || { [ "$code" = 422 ] && [ "$(json "$WORK/$label.$i.json" status)" = rejected ]; }; then
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
      "$({ [ "$code" = 200 ] || [ "$code" = 422 ]; } && [ "$(json "$WORK/$label.after.json" duplicate)" = True ] && echo $leads | grep -q -- "$(json "$WORK/$label.after.json" lead_id)" && echo true || echo false)"
    echo "$(echo $leads | tr ' ' '\n' | sort -u | head -1)" > "$WORK/$label.lead"
  }
  local key="staging-check-$run"
  local email_domain="${NEPA_TEST_EMAIL_DOMAIN:-example.com}"
  local base_person="\"consent\":{\"process\":true},\"person\":{\"first_name\":\"STAGING TEST\",\"last_name\":\"delete me\",\"email\":\"staging-check+$run@$email_domain\",\"phone\":\"+46 70 000 00 00\"},\"company\":{\"name\":\"Nepa staging check\"}"
  scenario keyed "{\"agent_context\":{\"user_authorized_submission\":true,\"idempotency_key\":\"$key\"},$base_person,\"inquiry\":{\"service_interest\":\"Test\",\"message\":\"Concurrency check $run (keyed)\"}}"
  local code
  code=$(curl -s -o "$WORK/reuse.json" -w '%{http_code}' -X POST "$BASE/agent/v1/contact.json" -H 'Content-Type: application/json' \
    --data "{\"agent_context\":{\"user_authorized_submission\":true,\"idempotency_key\":\"$key\"},$base_person,\"inquiry\":{\"message\":\"DIFFERENT content $run\"}}")
  check "same key with different content -> 409 idempotency_key_reused" "$([ "$code" = 409 ] && [ "$(json "$WORK/reuse.json" error)" = idempotency_key_reused ] && echo true || echo false)"
  local base_person2="\"consent\":{\"process\":true},\"person\":{\"first_name\":\"STAGING TEST\",\"last_name\":\"delete me\",\"email\":\"staging-check2+$run@$email_domain\",\"phone\":\"+46 70 000 00 00\"},\"company\":{\"name\":\"Nepa staging check\"}"
  scenario fingerprint "{\"agent_context\":{\"user_authorized_submission\":true},$base_person2,\"inquiry\":{\"service_interest\":\"Test\",\"message\":\"Concurrency check $run (no key)\"}}"
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


hubspot_acceptance() {
  local email="$EXTRA"
  if [ -z "$email" ]; then echo "Give a test email on a business domain you control as the 3rd argument."; exit 2; fi
  local run key; run="$(date +%Y%m%d%H%M%S)"; key="hubspot-acceptance-$run"
  local args="{\"user_authorized_submission\":true,\"consent_to_process\":true,\"marketing_opt_in\":false,\"first_name\":\"STAGING TEST\",\"last_name\":\"delete me\",\"email\":\"$email\",\"phone\":\"+46 70 000 00 00\",\"company\":\"Nepa staging check\",\"message\":\"HubSpot acceptance test $run - please ignore.\",\"service_interest\":\"Test\",\"language\":\"Swedish\",\"idempotency_key\":\"$key\"}"
  call() { curl -s --max-time 60 "$BASE" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
    -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"submit_contact_inquiry\",\"arguments\":$args}}" | sed -n 's/^data: //p'; }
  text() { python3 -c 'import json,sys
r=json.load(sys.stdin)["result"]; t=r["content"][0]["text"]
try: j=json.loads(t); print(("ERR " if r.get("isError") else "OK ")+(j.get("outcome") or j.get("error") or "")); print(j.get("lead_id",""))
except Exception: print(("ERR " if r.get("isError") else "OK ")+t.splitlines()[0]); print("")' <<<"$1"; }
  local first second
  first="$(text "$(call)")"; echo "First call:  $(echo "$first" | head -1)"
  local lead; lead="$(echo "$first" | sed -n 2p)"
  check "the MCP tool saved the inquiry (lead $lead)" "$([ -n "$lead" ] && echo true || echo false)"
  check "HubSpot delivery confirmed (outcome DELIVERED)" "$(echo "$first" | head -1 | grep -q '^OK DELIVERED' && echo true || echo false)"
  sleep 3
  second="$(text "$(call)")"; echo "Second call: $(echo "$second" | head -1)"
  check "repeating the same request returns the same lead, nothing new created or sent" "$(echo "$second" | head -1 | grep -q 'ALREADY SUBMITTED' && [ "$(echo "$second" | sed -n 2p)" = "$lead" ] && echo true || echo false)"
  cat <<CHECKLIST

Now verify in HubSpot (portal 146131568, form 7c631e31-cc3b-44ba-a580-113ab3489db6, "contact-us" English form):
  1. Exactly ONE new submission on that form from $email (the repeat must not add a second).
  2. Contact properties: First name "STAGING TEST", Last name "delete me", Phone "+46 70 000 00 00",
     Company "Nepa staging check"; Message starts "HubSpot acceptance test $run" and ends with
     "Service interest: Test", "Preferred language: Swedish", "Submitted via MCP ...", "Nepa lead ID: $lead".
  3. Consent: processing consent recorded with "I agree to allow Nepa to store and process my personal data.";
     the contact is NOT subscribed to subscription type 789062182 (marketing_opt_in was false).
  4. The form's normal workflows/notifications ran as they do for a website submission (check the
     workflow history and any HubSpot notification email).
  5. In WordPress -> Agent Leads -> $lead: HubSpot status "delivered", 1 attempt.
  6. Temporary-failure recovery: on staging set NEPA_HUBSPOT_SUBMIT_BASE to an unreachable host
     (e.g. https://127.0.0.1:9), submit once (status should be "retryable"), restore the setting,
     wait for the 5-minute worker: the lead must become "delivered" with one HubSpot submission.
  7. Permanent failure handling: set NEPA_HUBSPOT_FORM_ID to an invalid ID on staging, submit once:
     the lead must show "manual_review" with an attention email; restore the ID and use
     "Retry HubSpot delivery now" on the lead - it must become "delivered".
Delete the test contact in HubSpot and the test leads in WordPress afterwards (deleting a WordPress
lead does not delete the HubSpot contact).
CHECKLIST
}

case "$MODE" in
  duplicates) duplicates;;
  edge) edge;;
  hubspot) hubspot_acceptance;;
  *) echo "Unknown mode: $MODE (use duplicates or edge)"; exit 2;;
esac
echo; echo "$PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
