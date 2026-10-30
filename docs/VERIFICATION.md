# Verification evidence

The [CI job log for run 41101923892](https://github.com/zalamanga/accesspolicy-api/actions/runs/41101923892/job/124220466251) verifies source commit `e87fc12ccf6fd6f48e33295732aa68eb3d1d2082`. The commands below executed on 30 October 2026 UTC (31 October in Asia/Bangkok). Results come from the executed job log, rather than the presence of test source or the workflow's queued-status metadata.

| Check | Evidence |
| --- | --- |
| Docker PHP build and dependency installation | Test and runtime images built successfully; test runtime reported PHP 8.4.27 |
| PostgreSQL feature tests | PHPUnit 11.5.57 reported **OK (21 tests, 116 assertions)** at 17:53:31 UTC; isolated real PostgreSQL 17, not SQLite or a mocked repository |
| Dependency audit | `composer audit --locked` reported no security vulnerability advisories at 17:53:32 UTC |
| Actual HTTP stack | PostgreSQL and API became healthy; **18 HTTP smoke requests passed** at 17:53:52 UTC |
| Runtime image artifact | The tested runtime image was exported and artifact `accesspolicy-runtime-image` uploaded; artifact ID `12999615847` |
| Cleanup | Test and HTTP Compose stacks were stopped and removed in the job; the laptop's unrelated services were not involved |
| Local source checks | 30 PHP files parsed, five PowerShell scripts parsed, Node smoke script syntax checked, main/test Compose definitions validated, Composer strict validation passed |
| API document checks | JSON syntax and 135 local OpenAPI references resolved; the actual HTTP smoke fetched `/openapi.json` and checked version 3.0.3 |

## Behaviors exercised against PostgreSQL

The passing tests cover authenticated login/logout, missing/expired tokens, organization isolation, token abilities that cannot bypass roles, admin escalation restrictions, and global token revocation after role changes or removal. A newly authenticated, demoted admin receives current viewer permissions and cannot keep its previous administrative rights.

The stale-version cases include removing and re-adding the same user. Persistent organization/user counters prevent an old version from editing a new membership. The failure-injection test confirms that membership role, version counter, token deletion, and audit all roll back together when the mutation fails.

The owner race uses two independent PHP application processes. The first remains inside its mutation transaction while the test observes the second session waiting with `wait_event_type=Lock` in PostgreSQL. Only then is the first released. The assertions require statuses 200 and 409, one owner remaining, and one committed organization audit event. The test therefore exercises actual database contention.

Other passing cases verify that the seed does not reset edited passwords or deleted memberships, the database-backed login window counts both successful and failed attempts and ignores forwarded-IP changes, lists are bounded, unknown fields/string versions are rejected, and malformed/oversized/non-object bodies are rejected.

## Actual HTTP smoke

The running PHP server handled health and OpenAPI requests, rejected an unauthenticated request, issued three synthetic-user tokens, returned user/organization/member data, and returned 404 for another organization. It denied a member's attempted owner escalation, accepted an admin's ordinary-role edit, rejected the old member token, returned 409 for a stale version, restored the member role, exposed the membership audit to an owner, and revoked the owner/admin tokens on logout. The script checked that audit responses did not contain the tokens or passwords it used.

The smoke preserves the final member role while intentionally retaining the incremented version and audit history. It is a functional check, not a load test.

## Scope and limitations

PHP 8.4 application execution happened in the GitHub Actions Docker environment. The laptop's PHP 8.1 installation was used only for source parsing and lock resolution with an explicit PHP 8.4 platform target; it did not run Laravel. No global PHP installation was upgraded.

The Postman files were checked as JSON; the collection was not executed in the Postman desktop application. No throughput, uptime, external penetration-test, or load-test result has been measured. Public deployment hardening described in OPERATIONS.md remains outside this local portfolio verification.


## OpenAPI reference image

On 31 October 2026 local time, Swagger Parser 12.1.0 validated the committed OpenAPI 3.0.3 contract (12 method/route operations). Its SHA-256 was `e61049890a860a11c32dcfc602b971ed04ef43cf97ea629307f5b2d56bcb8bb9`.

The [reference image](screenshots/openapi-reference.png) is a real 1440 x 1100 browser capture of that contract rendered by Swagger UI 5.34.1, with no JavaScript page errors. It displays documented endpoints; it is not an application dashboard or evidence of live API responses. No credentials were entered into the renderer. Runtime and database evidence remains separate above.
