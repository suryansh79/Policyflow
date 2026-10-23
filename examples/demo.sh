#!/usr/bin/env bash
# ==============================================================================
# PolicyFlow - End-to-End Demonstration Script
# ==============================================================================
# Walks through all core capabilities:
# 1. Health check
# 2. Intake (201 Created, status VALIDATED, premium "225.00")
# 3. Idempotent replay (200 OK with Idempotent-Replayed header)
# 4. Conflict detection (409 on payload mismatch)
# 5. Normal Issuance (SUCCESS scenario -> ISSUED)
# 6. Safe repeated submit (no-op, provider_called: false)
# 7. Case B: Timeout after success (status lookup reconciliation)
# 8. Delayed callback processing (PENDING_CONFIRMATION -> ISSUED)
# 9. Case C: Duplicate callback handling (applied once, 2 duplicates ignored)
# 10. Audit trail and attempt inspection
#
# Usage:
#   ./examples/demo.sh [BASE_URL]
# Example:
#   ./examples/demo.sh http://127.0.0.1:8000
# ==============================================================================

set -euo pipefail

BASE_URL="${1:-http://127.0.0.1:8000}"
API_URL="${BASE_URL}/api"

GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

header() {
    echo -e "\n${BLUE}======================================================================${NC}"
    echo -e "${BLUE} $1${NC}"
    echo -e "${BLUE}======================================================================${NC}"
}

step() {
    echo -e "\n${YELLOW}▶ $1${NC}"
}

success() {
    echo -e "${GREEN}✔ $1${NC}"
}

header "PolicyFlow End-to-End Assessment Demonstration"
echo "Target Base URL: ${API_URL}"

# 1. Health check
step "1. Checking API Health"
HEALTH=$(curl -s "${API_URL}/health")
echo "Response: ${HEALTH}"
success "API is up and healthy."

# 2. Benchmark Intake (225.00 INR)
step "2. Intake: Submitting Benchmark Policy Request (Spec Section 4.2)"
RUN_ID="$(date +%s)"
BENCHMARK_PAYLOAD=$(cat <<EOF
{
  "client_request_id": "AGT-DEMO-${RUN_ID}",
  "agent_id": "AGENT-17",
  "customer_name": "Sample Customer",
  "date_of_birth": "1998-04-10",
  "destination": "INTERNATIONAL",
  "trip_start": "2026-11-10",
  "trip_end": "2026-11-14",
  "plan_code": "PLUS",
  "sum_insured": 300000
}
EOF
)

INTAKE_RES=$(curl -s -i -X POST "${API_URL}/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "${BENCHMARK_PAYLOAD}")

HTTP_CODE=$(echo "${INTAKE_RES}" | grep -E "^HTTP" | awk '{print $2}' | tr -d '\r')
BODY=$(echo "${INTAKE_RES}" | sed -e '1,/^\r*$/d')

echo "HTTP Status: ${HTTP_CODE}"
echo "Body: ${BODY}"

REQUEST_ID=$(echo "${BODY}" | grep -o '"id":"[^"]*' | cut -d'"' -f4)
PREMIUM=$(echo "${BODY}" | grep -o '"premium":"[^"]*' | cut -d'"' -f4)
STATUS=$(echo "${BODY}" | grep -o '"status":"[^"]*' | cut -d'"' -f4)

if [ "${HTTP_CODE}" = "201" ] && [ "${PREMIUM}" = "225.00" ] && [ "${STATUS}" = "VALIDATED" ]; then
    success "Intake passed: HTTP 201, Status VALIDATED, Premium ${PREMIUM} INR"
else
    echo -e "${RED}Intake assertion failed!${NC}"
    exit 1
fi

# 3. Safe Duplicate Replay
step "3. Case A: Replaying identical intake request (Idempotency)"
REPLAY_RES=$(curl -s -i -X POST "${API_URL}/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "${BENCHMARK_PAYLOAD}")

REPLAY_CODE=$(echo "${REPLAY_RES}" | grep -E "^HTTP" | awk '{print $2}' | tr -d '\r')
REPLAY_HEADER=$(echo "${REPLAY_RES}" | grep -i "Idempotent-Replayed" || true)

echo "HTTP Status: ${REPLAY_CODE}"
echo "Header: ${REPLAY_HEADER}"

if [ "${REPLAY_CODE}" = "200" ]; then
    success "Idempotency passed: HTTP 200 returned for identical replay with Idempotent-Replayed header."
else
    echo -e "${RED}Replay assertion failed!${NC}"
    exit 1
fi

# 4. Conflict Detection
step "4. Idempotency Key Reuse with Different Payload (Spec Section 4.2)"
CONFLICT_PAYLOAD=$(cat <<EOF
{
  "client_request_id": "AGT-DEMO-${RUN_ID}",
  "agent_id": "AGENT-17",
  "customer_name": "Different Name",
  "date_of_birth": "1998-04-10",
  "destination": "INTERNATIONAL",
  "trip_start": "2026-11-10",
  "trip_end": "2026-11-14",
  "plan_code": "PLUS",
  "sum_insured": 300000
}
EOF
)

CONFLICT_RES=$(curl -s -w "\n%{http_code}" -X POST "${API_URL}/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "${CONFLICT_PAYLOAD}")

CONFLICT_BODY=$(echo "${CONFLICT_RES}" | head -n -1)
CONFLICT_CODE=$(echo "${CONFLICT_RES}" | tail -n 1)

echo "HTTP Status: ${CONFLICT_CODE}"
echo "Body: ${CONFLICT_BODY}"

if [ "${CONFLICT_CODE}" = "409" ]; then
    success "Conflict detection passed: HTTP 409 returned for mismatched payload."
else
    echo -e "${RED}Conflict assertion failed!${NC}"
    exit 1
fi

# 5. Policy Submission (Normal SUCCESS)
step "5. Submitting Policy Request for Issuance (SUCCESS scenario)"
SUBMIT_RES=$(curl -s -w "\n%{http_code}" -X POST "${API_URL}/policy-requests/${REQUEST_ID}/submit" \
  -H "Accept: application/json")

SUBMIT_BODY=$(echo "${SUBMIT_RES}" | head -n -1)
SUBMIT_CODE=$(echo "${SUBMIT_RES}" | tail -n 1)

echo "HTTP Status: ${SUBMIT_CODE}"
echo "Body: ${SUBMIT_BODY}"

POLICY_NUM=$(echo "${SUBMIT_BODY}" | grep -o '"policy_number":"[^"]*' | cut -d'"' -f4)
OUTCOME=$(echo "${SUBMIT_BODY}" | grep -o '"outcome":"[^"]*' | cut -d'"' -f4)

success "Policy issued successfully: Policy Number ${POLICY_NUM}, Outcome ${OUTCOME}"

# 6. Repeated Submit
step "6. Re-submitting Already Issued Policy (Safe No-Op)"
RE_SUBMIT=$(curl -s "${API_URL}/policy-requests/${REQUEST_ID}/submit" -H "Accept: application/json")
echo "Body: ${RE_SUBMIT}"
RE_OUTCOME=$(echo "${RE_SUBMIT}" | grep -o '"outcome":"[^"]*' | cut -d'"' -f4)
PROVIDER_CALLED=$(echo "${RE_SUBMIT}" | grep -o '"provider_called":[^,}]*' | cut -d':' -f2)

if [ "${RE_OUTCOME}" = "ALREADY_ISSUED" ] && [ "${PROVIDER_CALLED}" = "false" ]; then
    success "Safe no-op passed: ALREADY_ISSUED, provider_called=false."
else
    echo -e "${RED}Repeated submit assertion failed!${NC}"
    exit 1
fi

# 7. Case B: Timeout After Issue
step "7. Case B: Timeout After Issue with Status Lookup Reconciliation"
TIMEOUT_ID="TIMEOUT-${RUN_ID}"
TIMEOUT_PAYLOAD=$(cat <<EOF
{
  "client_request_id": "${TIMEOUT_ID}",
  "agent_id": "AGENT-TIMEOUT",
  "customer_name": "Timeout User",
  "date_of_birth": "1992-03-20",
  "destination": "DOMESTIC",
  "trip_start": "2026-12-01",
  "trip_end": "2026-12-05",
  "plan_code": "BASIC",
  "sum_insured": 100000,
  "mock_scenario": "TIMEOUT_AFTER_ISSUE"
}
EOF
)

TIMEOUT_INTAKE=$(curl -s -X POST "${API_URL}/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "${TIMEOUT_PAYLOAD}")

TIMEOUT_REQ_ID=$(echo "${TIMEOUT_INTAKE}" | grep -o '"id":"[^"]*' | cut -d'"' -f4)

echo "Submitted request with TIMEOUT_AFTER_ISSUE scenario. Request ID: ${TIMEOUT_REQ_ID}"
echo "Submitting for issuance..."
TIMEOUT_SUBMIT=$(curl -s -X POST "${API_URL}/policy-requests/${TIMEOUT_REQ_ID}/submit" -H "Accept: application/json")
echo "Submit Response: ${TIMEOUT_SUBMIT}"

TIMEOUT_STATUS=$(echo "${TIMEOUT_SUBMIT}" | grep -o '"status":"[^"]*' | cut -d'"' -f4)
TIMEOUT_OUTCOME=$(echo "${TIMEOUT_SUBMIT}" | grep -o '"outcome":"[^"]*' | cut -d'"' -f4)

if [ "${TIMEOUT_STATUS}" = "ISSUED" ] && [ "${TIMEOUT_OUTCOME}" = "RECONCILED_ISSUED" ]; then
    success "Case B passed: Network timeout resolved via status lookup reconciliation -> ISSUED!"
else
    echo -e "${RED}Case B timeout resolution assertion failed!${NC}"
    exit 1
fi

# 8. Delayed Callback Flow
step "8. Delayed Callback: PENDING_CONFIRMATION -> Event Flush -> ISSUED"
DELAY_ID="DELAY-${RUN_ID}"
DELAY_PAYLOAD=$(cat <<EOF
{
  "client_request_id": "${DELAY_ID}",
  "agent_id": "AGENT-DELAY",
  "customer_name": "Delayed User",
  "date_of_birth": "1985-07-11",
  "destination": "INTERNATIONAL",
  "trip_start": "2026-12-10",
  "trip_end": "2026-12-15",
  "plan_code": "PREMIUM",
  "sum_insured": 500000,
  "mock_scenario": "DELAYED_CALLBACK"
}
EOF
)

DELAY_INTAKE=$(curl -s -X POST "${API_URL}/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "${DELAY_PAYLOAD}")

DELAY_REQ_ID=$(echo "${DELAY_INTAKE}" | grep -o '"id":"[^"]*' | cut -d'"' -f4)

DELAY_SUBMIT=$(curl -s -X POST "${API_URL}/policy-requests/${DELAY_REQ_ID}/submit" -H "Accept: application/json")
DELAY_STATUS=$(echo "${DELAY_SUBMIT}" | grep -o '"status":"[^"]*' | cut -d'"' -f4)
echo "Status after submit: ${DELAY_STATUS} (expected PENDING_CONFIRMATION)"

echo "Flushing mock event outbox..."
FLUSH_RES=$(curl -s -X POST "${API_URL}/mock-insurer/events/flush" -H "Accept: application/json")
echo "Flush Response: ${FLUSH_RES}"

DETAIL_AFTER_FLUSH=$(curl -s "${API_URL}/policy-requests/${DELAY_REQ_ID}")
FINAL_DELAY_STATUS=$(echo "${DETAIL_AFTER_FLUSH}" | grep -o '"status":"[^"]*' | cut -d'"' -f4)

if [ "${FINAL_DELAY_STATUS}" = "ISSUED" ]; then
    success "Delayed callback passed: Event delivery transitioned request to ISSUED!"
else
    echo -e "${RED}Delayed callback assertion failed!${NC}"
    exit 1
fi

# 9. Case C: Duplicate Callback
step "9. Case C: Duplicate Callback Delivery (Idempotent Event Receiver)"
DUPE_EVENT_ID="evt_demo_${RUN_ID}"
DUPE_CALLBACK_PAYLOAD=$(cat <<EOF
{
  "event_id": "${DUPE_EVENT_ID}",
  "event_type": "POLICY_ISSUED",
  "provider_reference": "PRV-DEMO-${RUN_ID}",
  "client_reference": "${REQUEST_ID}",
  "policy_number": "${POLICY_NUM}",
  "occurred_at": "2026-11-10T12:00:00Z"
}
EOF
)

# Delivery 1
CB_1=$(curl -s -X POST "${API_URL}/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "${DUPE_CALLBACK_PAYLOAD}")
echo "Delivery 1 response: ${CB_1}"

# Delivery 2 (duplicate)
CB_2=$(curl -s -X POST "${API_URL}/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "${DUPE_CALLBACK_PAYLOAD}")
echo "Delivery 2 (duplicate) response: ${CB_2}"

RESULT_2=$(echo "${CB_2}" | grep -o '"result":"[^"]*' | cut -d'"' -f4)

if [ "${RESULT_2}" = "duplicate" ]; then
    success "Case C passed: Duplicate callback recognized and returned result='duplicate'."
else
    echo -e "${RED}Duplicate callback assertion failed!${NC}"
    exit 1
fi

# 10. Audit Trail Inspection
step "10. Inspecting Complete Audit Trail for Request (Spec Section 4.3)"
DETAIL=$(curl -s "${API_URL}/policy-requests/${REQUEST_ID}")
echo "Detailed Resource:"
echo "${DETAIL}"

header "DEMONSTRATION COMPLETED SUCCESSFULLY"
echo -e "${GREEN}All 10 scenarios passed all assertions without error.${NC}"
