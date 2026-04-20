# OJS PHP SDK — Clean-Code / SRP Quality Audit

Branch: `refactor/clean-code-srp` · Scope: `ojs-php-sdk` (independent repo) · PHP floor: 8.2 · Tooling: Composer, PHPUnit 11, PHPStan 2.2 at level 5 (CI matrix 8.2/8.3/8.4).

## Summary (top five)

- **Baseline was fully red, not just "OjsAssertions".** PHPUnit could not even load: the PSR-4 rule `OpenJobSpec\Testing\ → src/Testing/` pointed at a non-existent directory, and *every* multi-class file (`Errors.php`, `Middleware.php`, `Transport.php`, `EncryptionMiddleware.php`, …) was un-autoloadable because only classes whose name matches their filename resolve under PSR-4. The visible `OjsAssertions` crash was the first of a cascade (66 errors + 10 failures once the loader was fixed). Root cause repaired with an additive `classmap` autoload plus targeted fixes.
- **Two latent fatals were masked by the broken loader.** `OjsException` illegally redeclared the built-in `Exception::$code` as `readonly` (hard fatal on class load); and the `code` string API collided with the numeric `Exception::$code`. Fixed with a virtual read-only `code` property (`__get`): string OJS codes remain available through `->code`, while integer codes populate `getCode()`.
- **Highest-leverage split: `src/Testing.php` (599 lines, 3 unrelated actors) → `src/Testing/{FakeTransport,FakeJob,OjsAssertions}.php`.** This file mixed a fake HTTP backend, a mutable job DTO, and a PHPUnit assertion trait — three different reasons to change — in a single file the declared PSR-4 map could never resolve. Splitting restores one-class-per-file, makes the pre-existing `OpenJobSpec\Testing\` PSR-4 rule correct, and directly removes the root cause of the reported symptom. Zero public-API change.
- **A real worker defect surfaced once the loader worked.** `Worker::getActiveQueues()` used registered handler *type* names (e.g. `email.send`) as *queue* names. Fixed so an unconfigured worker always sends the normative `['default']`, while explicitly configured queue arrays are sent verbatim. Regression tests inspect the fetch body and prove handler type names never become queues. Alongside it, a genuinely missing method — `FakeTransport::findEnqueued()`, called by `enqueued()` but never defined — was added.
- **Independent-review compatibility findings are resolved.** Client-side job type/queue shape checks retain their legacy `InvalidArgumentException` contract, while HTTP 422 responses still map to `ValidationError`. Empty batches remain server-delegated because the HTTP specification requires a `jobs` array but defines no client-side minimum cardinality. Handler `false` is ACKed and preserved as a JSON boolean, and `worker.stopped` retains its pre-existing empty payload because the event specification does not define `shutdown_timeout` as event data.
- **Other certified compatibility behavior remains intact.** `ServerError` retains its original positional order `(message, code, requestId, details, previous)`; `RetryPolicy(maxAttempts: 0)` round-trips as the normative OJS no-retry value while negatives fail; fake health remains `status: ok`; and canonical PHPStan analyzes both `src` and `tests` without suppressions. Final: **277/277 tests, 681 assertions; PHPStan 0 errors**.

## Findings

| ID | Location | Category | Sev | Actor / axis | Cost | Size | Risk | Status |
|----|----------|----------|-----|--------------|------|------|------|--------|
| F1 | `composer.json` autoload; `src/Testing.php` | Autoload / packaging | P0 | Package consumer / loader | Low | L | Low | Fixed (classmap + split) |
| F2 | `src/Errors.php:10` `OjsException` | Correctness (fatal) | P0 | Error-handling consumers | Low | M | Low | Fixed (`__get` virtual `code`) |
| F4 | `src/Worker.php` `getActiveQueues()`/`poll()` | Correctness (bug) | P1 | Worker / queue routing | Low | S | Med | Fixed (`default` fallback; explicit queues verbatim) |
| F5 | `src/Testing/FakeTransport.php` `enqueued()` | Correctness (missing method) | P1 | Test authors | Low | S | Low | Fixed (`findEnqueued()` added) |
| F6 | `src/Errors.php` hierarchy + `fromResponse()` | API consistency | P1 | Error-handling consumers | Med | M | Med | Fixed (`isRetryable()`, int/Throwable ctors) |
| F7 | `src/Client.php` ctor/`enqueue`/`enqueueBatch` | API ergonomics / validation | P1 | Producers | Med | M | Low | Fixed (variadic named args, legacy `InvalidArgumentException`, server-delegated empty batch, `createWorkflow`) |
| F8 | `src/Testing/FakeTransport.php` | Test-support correctness | P1 | Test authors | Med | M | Low | Fixed (`request/requests/enqueue`, polymorphic `drain`, cron `array_values`) |
| F9 | `src/Job.php` `fromArray()` | Wire tolerance | P1 | Deserialization | Low | S | Low | Fixed (accept `retry`\|`retry_policy`) |
| F10 | `src/Transport.php`, `src/Worker.php`, `src/OpenTelemetryMiddleware.php` | Dead / redundant code | P2 | Maintainers | Low | S | Low | Fixed |
| F11 | `src/SSESubscription.php` `$curlHandle` | Type correctness / resource | P2 | Maintainers | Low | S | Low | Fixed (`?\CurlHandle` + `closeHandle()`) |
| F12 | `src/RetryPolicy.php:59` `parseDuration` | Robustness (non-exhaustive match) | P2 | Duration parsing | Low | S | Low | Fixed (default arm) |
| F13 | `src/Worker.php` shutdown timeout | Incomplete feature / dead write | P2 | Worker lifecycle | Low | S | Low | Fixed (removed unused private write; no public event payload change; drain deferred) |
| F14 | `tests/MiddlewareTest.php` insert tests | Test bug (closure capture) | P2 | Middleware ordering coverage | Low | S | Low | Fixed (by-ref capture) |
| F15 | `src/Testing.php` (3 types/1 file) | SRP / packaging | P2 | Fake-server / DTO / assertions | Med | L | Low | Fixed (highest-leverage split) |
| F16 | `src/DurableContext.php` `now/random/sideEffect` | SRP / DRY (duplication) | P2 | Deterministic replay | Low | S | Low | Fixed (extract `replayOrRecord`) |
| F17 | `src/Errors.php` `ServerError` | API compatibility | P1 | Exception consumers | Low | S | Low | Fixed (original positional order; previous appended) |
| F18 | `src/RetryPolicy.php` | Spec correctness | P1 | Producers | Low | S | Low | Fixed (zero accepted and serialized; negatives rejected) |
| F19 | CI / Composer static analysis | Quality gate | P1 | Maintainers / SDK users | Low | S | Low | Fixed (`src` + `tests`, zero suppressions/errors) |
| F20 | `src/Worker.php` handler result dispatch | Result compatibility | P1 | Worker users | Low | S | Low | Fixed (`false`, `0`, `""`, and `[]` ACK as JSON results; only thrown errors NACK) |
| F21 | `src/Worker.php` `WORKER_STOPPED` | Event compatibility | P1 | Event listeners | Low | S | Low | Fixed (restored pre-existing `[]` payload; no undocumented `shutdown_timeout`) |

Legend: Size = code footprint of the fix (S/M/L). Risk = chance of behavioral regression.

## Ordered refactor sequence (as executed)

1. **Autoload repair (F1)** — add `classmap: ["src/"]`; regenerate loader. Unblocks the suite.
2. **Fatal fixes (F2, F6, F17)** — `OjsException` virtual `code` + `isRetryable()`; reconcile subclass constructors and `fromResponse` while preserving `ServerError` positional/named compatibility.
3. **Correctness (F4, F5, F8, F9, F18)** — worker queue routing, `findEnqueued`, fake-transport helpers, retry zero semantics, `Job::fromArray` key tolerance.
4. **API ergonomics/validation (F7)** — variadic named args, legacy local `InvalidArgumentException`, server-side 422 `ValidationError`, server-delegated empty batches, `createWorkflow` alias.
5. **Static-analysis / dead code (F10–F12, F19)** — analyze `src` and `tests`, remove stale test constructs, fix `CurlHandle` type + `closeHandle()`, exhaustive match.
6. **Test bug (F14)** — fix by-value closure capture in the middleware ordering tests.
7. **SRP splits (F15 then F16)** — split `Testing.php`; dedup `DurableContext` replay logic. Characterization = existing suite, run before/after each change.
8. **Final compatibility certification (F20–F21)** — ACK every non-null JSON result including `false`; restore the empty `worker.stopped` payload; remove the unused shutdown-timeout write rather than exposing it through event data.

## Actor-based SRP notes (classes > ~150 lines)

- **`FakeTransport` (~445 lines)** — two actors: the *fake backend* (`post/get/delete` + `handle*`) and the *test-inspection API* (`enqueued/allEnqueued/completed/failed/requests/drain`). They share private `$jobs` state; splitting into two classes would leak state and over-extract. Kept together; the file-level split (F15) was the safe win.
- **`Client` (376 lines)** — a resource-grouped facade (jobs/queues/dead-letter/cron/workflows/schemas/health/events). Each method is a 1–3 line transport call → one cohesive "API client" responsibility. The one distinct axis, input validation/payload assembly (`buildJobPayload`), is small and Client-owned; extracting sub-clients would change ergonomics with no behavior gain (deferred).
- **`Worker` (295 lines)** — axes: lifecycle/poll loop, dispatch+middleware, ack/nack reporting, event emission, backtrace formatting. An `EventEmitter` extraction is plausible but the pieces are small and tightly coupled to the run loop; deferred to avoid over-extraction.
- **`DurableContext` (221 lines)** — checkpoint I/O vs deterministic replay; the replay methods duplicated a record-or-replay step → extracted `replayOrRecord` (F16). `now()` keeps its faithful first-call return (full precision) and is intentionally left inline.
- **`Middleware.php`, `Errors.php`, `Transport.php`, `EncryptionMiddleware.php`** — multiple *small, single-responsibility* classes grouped by family (middleware set, exception hierarchy, transport interface+impl, crypto). SRP holds at the class level; the classmap makes them autoloadable without a churny one-file-per-class sweep (deferred).

## Deferred

- **Full graceful-drain with `shutdown_timeout`.** The synchronous poll loop has no in-flight work to drain. The option remains accepted for configuration compatibility but is not exposed as undocumented event data; real async draining is a product feature.
- **OpenTelemetry trace-context propagation.** The always-null `extractContext()` stub was removed (dead code); real W3C context extraction requires the OTel SDK and is a feature, not a fix.
- **One-class-per-file PSR-4 split of `Errors/Middleware/Transport/EncryptionMiddleware`.** Cohesive class families; classmap already resolves them. Low value / high churn.
## Out of scope

- **Labs modules** (`src/Agent`, `src/Attest`, `src/Recorder`): only the minimal static-analysis fix in `AgentClient` was applied; no behavioral refactor.
- **Public API redesign**: no sub-client split, no method renames, no signature narrowing. Constructor/`enqueue` changes are strictly additive (optional variadic named args).
- **Cross-language normalization** with other OJS SDKs.
- **Runtime dependency changes**: none. PHPStan 2.2 is now a reproducible development dependency instead of being installed by a mutating CI step.
- **Wire format / route / package identity**: unchanged.

## Final verification

- PHPUnit 11.5.56 on PHP 8.5.6: **277 tests, 681 assertions, 0 errors, 0 failures, 0 warnings, 0 deprecations**.
- PHPStan 2.2.8, level 5, `src tests`: **0 errors**.
- Syntax lint: **43 PHP files, 0 syntax errors, 0 deprecations**.
- Composer: strict validation passes after removing the discouraged explicit root
  `version` field (release versions are derived from Git tags). Audit found **0
  security advisories**; all non-dev platform requirements passed; locked install
  dry-run reported no changes.

The coordinated 0.5.0 release pass removed `composer.lock` from `.gitignore`,
refreshed it from final metadata without changing dependency versions, and
re-ran `composer validate --strict` successfully.
- Optimized strict-PSR autoload: **1,603 classes**; runtime autoload smoke passed.
- Package archive: `openjobspec-sdk-0.5.0.zip` created, integrity-tested, checked for key source files, then removed.
- Diff style gate: `git diff --check` passed. No commits or pushes were made.
