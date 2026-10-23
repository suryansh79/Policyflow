# PolicyFlow - Reliability Verification & Failure-Recovery Guide

This guide provides reproducible, copy-pasteable verification workflows for all four reliability and concurrency scenarios implemented in PolicyFlow:
- **Case A**: Idempotent policy intake and near-simultaneous race recovery.
- **Case B**: Timeout after successful insurer issuance and status lookup reconciliation.
- **Case C**: Duplicate callback / webhook delivery and outbox deduplication.
- **Case D**: Safe retries after transient failures preserving immutable accepted premiums.

---

## Prerequisites

Ensure the local server is running:
```bash
# Start server locally
php artisan serve --host=127.0.0.1 --port=8000
```
Or verify via Docker:
```bash
docker compose up -d
```
All commands below assume `http://127.0.0.1:8000`.

---

## 1. Case A: Idempotent Policy Intake & Conflict Detection

### Objective
Verify that:
1. A new valid intake request returns `HTTP 201 Created` with status `VALIDATED`.
2. An identical repeated submission returns `HTTP 200 OK` with header `Idempotent-Replayed: true` and creates zero duplicate rows.
3. Reusing the same `(agent_id, client_request_id)` with a different business payload returns `HTTP 409 Conflict`.

### Execution

```bash
# 1. Submit initial policy purchase request
curl -i -X POST http://127.0.0.1:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "TEST-INTAKE-001",
    "agent_id": "AGENT-01",
    "customer_name": "Alice Smith",
    "date_of_birth": "1995-05-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-01",
    "trip_end": "2026-11-05",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }'
```
**Expected Response**: `HTTP/1.1 201 Created`, body status `VALIDATED`, premium `"225.00"`.

```bash
# 2. Resend identical payload (Idempotent Replay)
curl -i -X POST http://127.0.0.1:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "TEST-INTAKE-001",
    "agent_id": "AGENT-01",
    "customer_name": "Alice Smith",
    "date_of_birth": "1995-05-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-01",
    "trip_end": "2026-11-05",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }'
```
**Expected Response**: `HTTP/1.1 200 OK`, header `Idempotent-Replayed: true`, identical `id` UUID.

```bash
# 3. Resend same key with altered customer name (Conflict Detection)
curl -i -X POST http://127.0.0.1:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "TEST-INTAKE-001",
    "agent_id": "AGENT-01",
    "customer_name": "Bob Jones",
    "date_of_birth": "1995-05-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-01",
    "trip_end": "2026-11-05",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }'
```
**Expected Response**: `HTTP/1.1 409 Conflict` with `error_code: "IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD"`.

### Automated Test Reference
- `tests/Feature/PolicyIntakeTest.php::test_valid_new_request_creates_policy_and_returns_201`
- `tests/Feature/PolicyIntakeTest.php::test_repeated_identical_request_returns_200_safely`
- `tests/Feature/PolicyIntakeTest.php::test_reused_key_with_different_payload_returns_409`
- `tests/Feature/PolicyIntakeTest.php::test_near_simultaneous_race_simulation_recovers_safely`

---

## 2. Case B: Provider Timeout & Status Lookup Reconciliation

### Objective
Verify that when an insurer creates a policy internally but transport times out, PolicyFlow does **not** blindly resubmit. Instead, it transitions to `NEEDS_RECONCILIATION`, queries the provider status lookup by client reference UUID, and resolves directly to `ISSUED`.

### Execution

```bash
# 1. Create intake with TIMEOUT_AFTER_ISSUE mock scenario
INTAKE_RES=$(curl -s -X POST http://127.0.0.1:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "TEST-TIMEOUT-001",
    "agent_id": "AGENT-01",
    "customer_name": "Charlie Brown",
    "date_of_birth": "1990-01-01",
    "destination": "DOMESTIC",
    "trip_start": "2026-12-01",
    "trip_end": "2026-12-05",
    "plan_code": "BASIC",
    "sum_insured": 100000,
    "mock_scenario": "TIMEOUT_AFTER_ISSUE"
  }')

# Extract Request UUID
REQ_ID=$(echo $INTAKE_RES | grep -o '"id":"[^"]*' | cut -d'"' -f4)
echo "Policy Request ID: ${REQ_ID}"

# 2. Submit policy (Transport times out; inline reconciliation queries status lookup)
curl -i -X POST http://127.0.0.1:8000/api/policy-requests/${REQ_ID}/submit \
  -H "Accept: application/json"
```
**Expected Response**: `HTTP/1.1 200 OK`, `status: "ISSUED"`, `policy_number` populated (e.g. `POL-...`), `submission.outcome: "RECONCILED_ISSUED"`, `submission.provider_called: true`.

```bash
# 3. Repeated submission of the now-issued request (Safe No-Op)
curl -i -X POST http://127.0.0.1:8000/api/policy-requests/${REQ_ID}/submit \
  -H "Accept: application/json"
```
**Expected Response**: `HTTP/1.1 200 OK`, `submission.outcome: "ALREADY_ISSUED"`, `submission.provider_called: false`.

### Automated Test Reference
- `tests/Feature/PolicySubmissionTest.php::test_case_b_timeout_after_issue_inline_reconciliation_resolves_to_issued`
- `tests/Feature/PolicySubmissionTest.php::test_case_b_timeout_reconciliation_deferred_and_resolved_by_artisan_command`

---

## 3. Case C: Duplicate Webhook Callbacks & Terminal State Immutability

### Objective
Verify that:
1. An incoming provider webhook advances `PENDING_CONFIRMATION` or `SUBMITTED` to `ISSUED`.
2. Duplicate deliveries of the identical event ID are acknowledged with `result: "duplicate"` without modifying the policy or creating duplicate audit rows.
3. Callbacks arriving after `ISSUED` cannot overwrite `policy_number` or regress state.

### Execution

```bash
# 1. Create request with DELAYED_CALLBACK scenario
DELAY_RES=$(curl -s -X POST http://127.0.0.1:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "TEST-CALLBACK-001",
    "agent_id": "AGENT-01",
    "customer_name": "Diana Prince",
    "date_of_birth": "1988-08-20",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-15",
    "trip_end": "2026-11-20",
    "plan_code": "PREMIUM",
    "sum_insured": 500000,
    "mock_scenario": "DELAYED_CALLBACK"
  }')

DELAY_ID=$(echo $DELAY_RES | grep -o '"id":"[^"]*' | cut -d'"' -f4)

# 2. Submit policy (Transitions to PENDING_CONFIRMATION)
curl -s -X POST http://127.0.0.1:8000/api/policy-requests/${DELAY_ID}/submit \
  -H "Accept: application/json"

# 3. Deliver Webhook 1st Time
curl -i -X POST http://127.0.0.1:8000/api/mock-insurer/callback \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{
    \"event_id\": \"evt_cb_001\",
    \"event_type\": \"POLICY_ISSUED\",
    \"provider_reference\": \"PRV-DELAY-001\",
    \"client_reference\": \"${DELAY_ID}\",
    \"policy_number\": \"POL-DELAY-001\",
    \"occurred_at\": \"2026-11-15T10:00:00Z\"
  }"
```
**Expected Response**: `HTTP/1.1 200 OK`, `result: "applied"`, `request_status: "ISSUED"`.

```bash
# 4. Deliver Webhook 2nd Time (Duplicate Delivery)
curl -i -X POST http://127.0.0.1:8000/api/mock-insurer/callback \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{
    \"event_id\": \"evt_cb_001\",
    \"event_type\": \"POLICY_ISSUED\",
    \"provider_reference\": \"PRV-DELAY-001\",
    \"client_reference\": \"${DELAY_ID}\",
    \"policy_number\": \"POL-DELAY-001\",
    \"occurred_at\": \"2026-11-15T10:00:00Z\"
  }"
```
**Expected Response**: `HTTP/1.1 200 OK`, `result: "duplicate"`, `request_status: "ISSUED"`.

### Automated Test Reference
- `tests/Feature/ProviderCallbackTest.php::test_first_delivery_applied_successfully`
- `tests/Feature/ProviderCallbackTest.php::test_duplicate_callback_delivery_returns_duplicate_and_increments_count`
- `tests/Feature/ProviderCallbackTest.php::test_late_callback_conflicting_policy_number_ignored_without_overwrite`

---

## 4. Case D: Safe Retry After Transient Failure

### Objective
Verify that when an insurer returns a retriable error (HTTP 503), the request transitions to `RETRY_PENDING`, and subsequent retries preserve the byte-identical original accepted premium snapshot.

### Execution

```bash
# 1. Create request with TEMPORARY_FAILURE scenario
RETRY_RES=$(curl -s -X POST http://127.0.0.1:8000/api/policy-requests \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "TEST-RETRY-001",
    "agent_id": "AGENT-01",
    "customer_name": "Evan Wright",
    "date_of_birth": "1993-04-12",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-20",
    "trip_end": "2026-11-25",
    "plan_code": "PLUS",
    "sum_insured": 300000,
    "mock_scenario": "TEMPORARY_FAILURE"
  }')

RETRY_ID=$(echo $RETRY_RES | grep -o '"id":"[^"]*' | cut -d'"' -f4)

# 2. Attempt 1 fails temporarily -> State moves to RETRY_PENDING
curl -i -X POST http://127.0.0.1:8000/api/policy-requests/${RETRY_ID}/submit \
  -H "Accept: application/json"
# Response: status: "RETRY_PENDING", outcome: "RETRY_PENDING"

# 3. Attempt 2 succeeds -> State moves to ISSUED
curl -i -X POST http://127.0.0.1:8000/api/policy-requests/${RETRY_ID}/submit \
  -H "Accept: application/json"
# Response: status: "ISSUED", outcome: "ISSUED"
```

### Automated Test Reference
- `tests/Feature/PolicySubmissionTest.php::test_case_d_retry_after_temporary_failure_preserves_immutable_premium`
- `tests/Feature/PolicySubmissionTest.php::test_retries_exhausted_transitions_to_failed`

---

## 5. Multi-Worker Concurrency Stress Simulation

To test PolicyFlow under simultaneous load, run the provided stress script:

```bash
# Fires 50 parallel requests with xargs -P 10 against the intake endpoint
./examples/concurrent_intake.sh http://127.0.0.1:8000
```

**Verification Guarantees**:
- Exactly 1 request receives `HTTP 201 Created` (The Winner).
- Exactly 49 requests receive `HTTP 200 OK` (Safe Idempotency Replays).
- Exactly 0 requests receive `HTTP 500`.
- Database query confirms exactly 1 record was persisted.

---

## 6. End-to-End Walkthrough Script

An interactive demonstration script running through all scenarios sequentially is available at:

```bash
./examples/demo.sh http://127.0.0.1:8000
```
