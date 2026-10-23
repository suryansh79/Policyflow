# PolicyFlow 3–5 Minute Screen Recording Demonstration Script

This script provides a minute-by-minute walkthrough for recording the 3–5 minute video demonstration required by the campus engineering challenge.

---

## Preparation Before Recording

1. **Terminal 1 (Server):**
   ```bash
   PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8000
   ```
2. **Terminal 2 (Client/CLI):**
   Open in project root. Have `./examples/demo.sh` ready to run or use individual curl commands.
3. **IDE / Browser:**
   Have `examples/requests.http` or VS Code / Postman open.
4. **Resolution:** 1080p, clear font size (16pt+).

---

## Minute-by-Minute Recording Guide

### Minute 0:00 – 0:45 | Introduction & Environment Setup

**Visual:**
- Show repository root in VS Code / terminal.
- Show `php -v`, `git status`, and `phpunit.xml`.

**What to Say:**
> "Hello! My name is Suryansh Sharma, and this is PolicyFlow — a fault-tolerant insurance issuance and reconciliation engine built with PHP 8.2 and Laravel 12.
>
> In high-volume travel insurance, distributor networks face unreliable insurer APIs: network timeouts, retries, and duplicate callbacks. PolicyFlow guarantees that duplicate policies are never issued, and no incorrect final state is reached.
>
> Today, I'll demonstrate the core API contract, deterministic integer pricing, and the four reliability cases: duplicate intake prevention, timeout status lookup reconciliation, and idempotent callback handling."

---

### Minute 0:45 – 1:45 | Policy Intake & Idempotency (Case A)

**Visual:**
- Run the intake request for the benchmark customer (`POST /api/policy-requests`):
  `AGT-2026-0001`, `AGENT-17`, DOB `1998-04-10`, `INTERNATIONAL`, `2026-11-10` to `2026-11-14`, `PLUS`, `300000`.
- Highlight response: `HTTP 201 Created`, status `VALIDATED`, `travel_days: 5`, `premium: "225.00"`.
- Re-run the exact same command.
- Highlight response: `HTTP 200 OK`, `Idempotent-Replayed: true` header, identical ID, zero duplicate rows.
- Change customer name to "Different Name" and re-send.
- Highlight response: `HTTP 409 Conflict` with machine code `IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD`.

**What to Say:**
> "First, let's submit the benchmark purchase request from the specification. Notice the response: HTTP 201 Created, status is VALIDATED, travel days is 5, and the premium is exactly 225.00 INR. Our pricing calculator uses decimal-safe pure integer arithmetic in paise — never floating point.
>
> Now, suppose a network dropout causes the agent to re-send the identical request. PolicyFlow responds with HTTP 200 OK and an 'Idempotent-Replayed: true' header. The database enforces a compound unique constraint on agent_id and client_request_id, backed by an atomic SHA-256 fingerprint check.
>
> If an agent attempts to reuse the same client_request_id with modified customer data, PolicyFlow rejects it immediately with an HTTP 409 Conflict."

---

### Minute 1:45 – 2:45 | Submission & Timeout Reconciliation (Case B)

**Visual:**
- Submit the validated request: `POST /api/policy-requests/{id}/submit`.
- Highlight response: `HTTP 200 OK`, status `ISSUED`, policy number `POL-...`.
- Re-submit the same request.
- Highlight response: `outcome: "ALREADY_ISSUED"`, `provider_called: false`.
- Submit a request configured with `mock_scenario: "TIMEOUT_AFTER_ISSUE"`.
- Show that the provider call times out, but PolicyFlow **never blindly resubmits**. Instead, it marks the request `NEEDS_RECONCILIATION`, queries the mock provider status lookup by client_reference, discovers the policy was issued externally, and safely transitions to `ISSUED`.

**What to Say:**
> "Next is the issuance workflow. We call `POST /submit`. Our submission algorithm uses a three-step protocol: first, a short transaction acquires an optimistic CAS claim moving state to SUBMITTED. Second, the provider call happens entirely OUTSIDE any database lock, preventing connection pool exhaustion. Third, the result is recorded in a short transaction.
>
> Notice that re-submitting an already issued policy is a safe no-op: provider_called is false, protecting against double charges.
>
> Now for Case B: what happens when the external insurer issues a policy, but the network drops before returning a response? Rather than resubmitting blindly and risking duplicate coverage, PolicyFlow handles the timeout by immediately performing a status lookup keyed by our client UUID. It confirms the policy exists at the provider, retrieves the policy number, and moves directly to ISSUED."

---

### Minute 2:45 – 3:30 | Delayed & Duplicate Callbacks (Case C)

**Visual:**
- Demonstrate inbound callback: `POST /api/mock-insurer/callback`.
- Deliver the callback payload once: shows `result: "applied"`.
- Deliver the same callback payload a second and third time: shows `result: "duplicate"`, `received_count` incremented, status untouched.

**What to Say:**
> "For asynchronous insurers, PolicyFlow supports webhooks and callbacks via `POST /api/mock-insurer/callback`.
>
> An incoming callback is recorded in an append-only provider events table with an atomic unique constraint on (provider, event_id). When the insurer re-sends the same event multiple times, our receiver detects the existing event ID, increments received_count, and responds with 'duplicate' without modifying the policy or creating duplicate audit rows. Even if a conflicting event arrives later, our state machine strictly forbids regressions from terminal states like ISSUED."

---

### Minute 3:30 – 4:15 | Automated Test Suite & Architecture

**Visual:**
- Run the full automated test suite:
  ```bash
  php artisan test
  ```
- Show all tests passing (160 tests, 698 assertions, all green).
- Briefly open `README.md` showing the Mermaid state diagram.

**What to Say:**
> "Finally, let's run our test suite. We execute `php artisan test`. All 160 tests pass cleanly with zero setup using an in-memory SQLite database, and the entire suite also runs green against MySQL 8 via Docker Compose.
>
> Our test suite covers the complete 64-transition state matrix, boundary pricing on exact birthdays, lost-race simulations, and log privacy assertions verifying that customer names and dates of birth are never leaked to logs.
>
> Everything is documented in the README with setup instructions, curl examples, and interview preparation guides. Thank you!"

---

## Checklist Before Finishing Video
- [ ] Confirmed video length is between 3 and 5 minutes.
- [ ] Audio is crisp and clear.
- [ ] HTTP status codes (201, 200, 409, 200) are visible.
- [ ] Test suite execution finishes green.
- [ ] Uploaded to Google Drive / YouTube (unlisted) or Loom, with link added to README.
