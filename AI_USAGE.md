# AI Usage Disclosure

In compliance with Section 10 of the assessment guidelines and TrippersShield policy, this document truthfully discloses the AI tools used during the design, implementation, and verification of PolicyFlow.

---

## 1. Tools Used
- **Claude (Anthropic)**: Used for drafting the initial engineering build prompt, decomposing requirements from the challenge PDF, and initial architectural review of the idempotency and state machine strategy.
- **Google Antigravity**: Used for pair-programming code generation, domain modeling, automated test generation, test execution inside Docker, and repository scaffolding.

---

## 2. Concrete Ways AI Assisted

1. **Exhaustive State Transition Testing**:
   AI assisted in generating the complete 8×8 (64 transition) matrix test in `tests/Unit/PolicyStateMachineTest.php`, verifying that all 13 valid transitions succeed and all 51 invalid regressions (such as `ISSUED -> SUBMITTED`) are strictly rejected with `InvalidStateTransitionException`.

2. **Decimal-Safe Integer Arithmetic Implementation**:
   AI helped implement the hundredth-multiplier integer formula (`numerator = rate * days * a * d * c` with half-up rounding `intdiv(numerator + 5000, 10000)`) in `App\Domain\Pricing\PremiumCalculator`, ensuring zero reliance on floating point math or PHP's `bcmath` extension.

3. **Multi-Worker Concurrency Simulation**:
   AI generated the bash stress simulation script `examples/concurrent_intake.sh` utilizing `xargs -P` to fire 50 concurrent requests against a multi-worker server instance, validating the atomic race recovery flow.

---

## 3. Real Incorrect Suggestions Encountered & How They Were Caught

During development, three real AI-generated defects were identified and corrected:

### A. Mock Provider Transaction Rollback
- **Defect**: The initial implementation of `MockInsurerService` wrapped the provider logic in a single `DB::transaction()` and threw simulated transport exceptions (`InsurerTimeoutException`, `InsurerTemporaryException`) directly inside that transaction block.
- **Why it was wrong**: In reality, an external insurer's database commits independently of client-side connect or read timeouts. Rolling back the mock table caused `mock_insurer_policies` to lose the record, making post-timeout status lookups return 404.
- **How it was caught**: Caught immediately by `tests/Feature/MockInsurerTest.php` and `tests/Feature/PolicySubmissionTest.php` during the `TIMEOUT_AFTER_ISSUE` test.
- **Fix**: Committed the mock provider state transaction first, then executed a post-commit action closure that throws the transport exception.

### B. Callback Audit Row Rollback on Rejected Events
- **Defect**: In `ProviderCallbackService`, throwing an HTTP 409 `ApiException` inside `DB::transaction()` when an invalid state event arrived rolled back the entire transaction, including the audit row in `provider_events`.
- **How it was caught**: Caught by `tests/Feature/ProviderCallbackTest.php` when asserting that rejected events were persisted with status `REJECTED_INVALID_STATE`.
- **Fix**: Applied the post-commit pattern to persist and commit the audit record in `provider_events` first, followed by raising the 409 response.

### C. Test Assertion Calculation Errors
- **Defect**: When writing the 13 hand-calculated pricing cases in `PremiumCalculatorTest`, initial test expectation data had manual calculation typos (e.g. 132.00 INR entered as 1320 paise instead of 13200 paise).
- **How it was caught**: The integer calculator implemented the exact specification formula; running `php artisan test` caught the mismatched test expectations, which were then recalculated and verified by hand.

---

## 4. Verification Methodology

Every line of code and configuration was independently verified using the following methods:
1. **Automated Test Suite**: 160 automated tests (698 assertions) passing 100% green on both SQLite (in-memory) and MySQL 8.
2. **Schema & Migration Verification**: Executed `php artisan migrate:fresh` and `php artisan migrate:rollback` against a real MySQL 8 container, verifying all tables, foreign keys, and unique indexes.
3. **Multi-Process Concurrency Testing**: Executed `examples/concurrent_intake.sh` with 50 parallel requests, verifying that exactly one row was created in the database and 49 requests received safe HTTP 200 idempotency replays.
4. **Log Privacy Verification**: Created and executed `tests/Feature/LogPrivacyTest.php` to verify that customer names and dates of birth are never logged in plain text or context.
5. **Code Style Audit**: Verified complete compliance using Laravel Pint (`./vendor/bin/pint`).
