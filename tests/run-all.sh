#!/usr/bin/env bash
# Runs every packaged test suite and validator, and prints one summary line per suite.
#
#   bash tests/run-all.sh            # from the package root
#   PYTHON=/path/to/python3 bash tests/run-all.sh
#
# Needs PHP 7.4+ (CLI, with the built-in server), Node 20+ with npm, and a python3 that has the
# jsonschema package. A suite that cannot run fully (e.g. jsonschema missing) is reported as
# INCOMPLETE and makes the run fail: this is a release gate, so "skipped" never counts as passed.
# Exit code 0 only when every suite passed.
set -uo pipefail
cd "$(dirname "$0")/.."
PY="${PYTHON:-python3}"
PHPQ=(php -d "error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING")
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
status=0
run() { # name, command...
  local name="$1"; shift
  local out code
  out="$("$@" 2>&1)"; code=$?
  local last; last="$(printf '%s\n' "$out" | grep -E 'passed|failed|valid|INCOMPLETE|PASS  ARD manifest|FAIL  ARD manifest' | tail -1)"
  if [ $code -eq 0 ]; then printf 'PASS        %-28s %s\n' "$name" "$last"
  elif [ $code -eq 3 ]; then printf 'INCOMPLETE  %-28s %s\n' "$name" "$last"; status=1
  else printf 'FAIL        %-28s %s\n' "$name" "$last"; printf '%s\n' "$out" | grep -E '^FAIL' | head -5 | sed 's/^/            /'; status=1
  fi
}
run "Plugin"               "${PHPQ[@]}" tests/run-plugin-tests.php
run "HubSpot (token)"      "${PHPQ[@]}" tests/run-hubspot-tests.php token
run "HubSpot (no token)"   "${PHPQ[@]}" tests/run-hubspot-tests.php notoken
NEPA_DUMP_RESPONSES="$TMP/responses.jsonl" "${PHPQ[@]}" tests/run-hubspot-tests.php token >/dev/null 2>&1
run "OpenAPI responses"    "$PY" tests/validate-openapi-examples.py "$TMP/responses.jsonl"
run "llms-full.txt up to date" "$PY" tools/build-llms-full.py --check
run "Data files vs OpenAPI" "$PY" tests/validate-data-files.py
export ARD_MANIFEST_OUT="$TMP/ard.json"
run "ARD"                  php tests/run-ard-tests.php
run "Markdown versions"      "${PHPQ[@]}" tests/run-markdown-tests.php
run "Page additions"         php tests/run-page-additions-tests.php
run "ARD manifest schema"  "$PY" tests/validate-ard-manifest.py "$TMP/ard.json" nepa.com
run "MCP smoke test"       bash tests/mcp-smoke-test.sh
exit $status
