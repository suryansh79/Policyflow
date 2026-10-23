#!/usr/bin/env bash
# ==============================================================================
# PolicyFlow - Concurrent Intake Stress Simulation (Case A)
# ==============================================================================
# Demonstrates that 50 concurrent requests with identical (agent_id, client_request_id)
# produce exactly ONE persisted record and zero duplicate policies.
#
# Prerequisite:
# Start the server with multi-worker support:
#   PHP_CLI_SERVER_WORKERS=8 php artisan serve --host=127.0.0.1 --port=8000
#
# Run this script:
#   ./examples/concurrent_intake.sh [BASE_URL]
# ==============================================================================

set -euo pipefail

BASE_URL="${1:-http://127.0.0.1:8000}"
ENDPOINT="${BASE_URL}/api/policy-requests"
TOTAL_REQUESTS=50
CONCURRENCY=10

RUN_ID="RACE-$(date +%s)"
AGENT_ID="AGENT-CONCURRENT"
CLIENT_REQUEST_ID="CONCURRENT-${RUN_ID}"

echo "======================================================================"
echo " PolicyFlow Concurrent Intake Verification"
echo "======================================================================"
echo "Target Endpoint  : ${ENDPOINT}"
echo "Client Request ID: ${CLIENT_REQUEST_ID}"
echo "Total Requests   : ${TOTAL_REQUESTS}"
echo "Concurrency level: ${CONCURRENCY}"
echo "----------------------------------------------------------------------"

# Prepare JSON payload
PAYLOAD=$(cat <<EOF
{
  "client_request_id": "${CLIENT_REQUEST_ID}",
  "agent_id": "${AGENT_ID}",
  "customer_name": "Concurrent Test User",
  "date_of_birth": "1995-06-15",
  "destination": "INTERNATIONAL",
  "trip_start": "2026-11-20",
  "trip_end": "2026-11-25",
  "plan_code": "PLUS",
  "sum_insured": 300000
}
EOF
)

TMP_DIR=$(mktemp -d 2>/dev/null || mktemp -d -t 'policyflow_race')
RESULTS_FILE="${TMP_DIR}/status_codes.txt"

cleanup() {
    rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

echo "Firing ${TOTAL_REQUESTS} concurrent requests..."

# Execute requests concurrently using xargs
seq 1 "${TOTAL_REQUESTS}" | xargs -n 1 -P "${CONCURRENCY}" -I {} sh -c "
  curl -s -o /dev/null -w '%{http_code}\n' \
    -X POST '${ENDPOINT}' \
    -H 'Content-Type: application/json' \
    -H 'Accept: application/json' \
    -d '${PAYLOAD}' >> '${RESULTS_FILE}'
"

echo "Requests complete. Analyzing results..."
echo "----------------------------------------------------------------------"

COUNT_201=$(grep -c "^201$" "${RESULTS_FILE}" || true)
COUNT_200=$(grep -c "^200$" "${RESULTS_FILE}" || true)
COUNT_409=$(grep -c "^409$" "${RESULTS_FILE}" || true)
COUNT_422=$(grep -c "^422$" "${RESULTS_FILE}" || true)
COUNT_500=$(grep -c "^500$" "${RESULTS_FILE}" || true)
TOTAL_RESPONSES=$(wc -l < "${RESULTS_FILE}" | tr -d ' ')

echo "Status Code Distribution:"
echo "  HTTP 201 (Created - Winner)   : ${COUNT_201}"
echo "  HTTP 200 (Idempotent Replay)  : ${COUNT_200}"
echo "  HTTP 409 (Conflict Mismatch)  : ${COUNT_409}"
echo "  HTTP 422 (Validation Error)   : ${COUNT_422}"
echo "  HTTP 500 (Server Error)       : ${COUNT_500}"
echo "  Total Responses Captured      : ${TOTAL_RESPONSES}"
echo "----------------------------------------------------------------------"

# Verification 1: Exactly one winner created the record
if [ "${COUNT_201}" -ne 1 ]; then
    echo "❌ FAILURE: Expected exactly 1 HTTP 201 Created, but received ${COUNT_201}"
    exit 1
fi

# Verification 2: All other requests were safely replayed as HTTP 200
EXPECTED_200=$((TOTAL_REQUESTS - 1))
if [ "${COUNT_200}" -ne "${EXPECTED_200}" ]; then
    echo "❌ FAILURE: Expected ${EXPECTED_200} HTTP 200 Idempotent Replays, but received ${COUNT_200}"
    exit 1
fi

# Verification 3: Zero 500 errors
if [ "${COUNT_500}" -ne 0 ]; then
    echo "❌ FAILURE: Server errors occurred (${COUNT_500} HTTP 500 responses)"
    exit 1
fi

# Verification 4: Query the API to confirm exactly ONE record exists for this client_request_id
LIST_RESPONSE=$(curl -s "${BASE_URL}/api/policy-requests?agent_id=${AGENT_ID}")
MATCH_COUNT=$(echo "${LIST_RESPONSE}" | grep -o "\"client_request_id\":\"${CLIENT_REQUEST_ID}\"" | wc -l || true)

echo "Database Record Verification:"
echo "  Records matching ${CLIENT_REQUEST_ID}: ${MATCH_COUNT}"

if [ "${MATCH_COUNT}" -ne 1 ]; then
    echo "❌ FAILURE: Database contains ${MATCH_COUNT} records (expected exactly 1)"
    exit 1
fi

echo "----------------------------------------------------------------------"
echo "✅ SUCCESS: Concurrency verification passed flawlessly."
echo "   - 1 winner (201 Created)"
echo "   - $((TOTAL_REQUESTS - 1)) safe idempotency replays (200 OK)"
echo "   - 0 server errors (500)"
echo "   - Exactly 1 record in database"
echo "======================================================================"
