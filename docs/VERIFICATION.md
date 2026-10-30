# Verification status

Source and tests are authored. Runtime/CI evidence is recorded here only after execution.

| Check | Current evidence |
| --- | --- |
| PHP source syntax | Checked with host PHP 8.1 parser; this does not prove Laravel runtime compatibility |
| PowerShell syntax | Parsed without errors |
| Compose definitions | Main/test `config --quiet` passed with private local credentials |
| Dependency lock and Composer audit | Lock resolved with platform PHP 8.4, 102 packages; resolver reported no security vulnerability advisories. Composer strict validation passed. Runtime installation still pending. |
| Docker PHP 8.4 build | Pending coordinated build |
| PHPUnit against PostgreSQL 17 | Pending execution; includes two-process owner race and transactional rollback tests |
| Actual HTTP smoke | Pending execution |
| OpenAPI/Postman validation | JSON syntax and local OpenAPI reference resolution checked; HTTP contract check pending |
| Independent source review | Corrected remove/re-add version reuse, avoided PHPUnit console Mockery dependency, and strengthened the race test with observed PostgreSQL lock contention; runtime proof remains pending |

There are 21 authored PostgreSQL feature tests. The concurrency test uses two independent PHP processes and requires observing the second session blocked on a database lock before the first commits. Its presence does not establish a passing result before execution.

No throughput, uptime, or load-test result has been measured. The repository does not claim a passing runtime test until logs or a CI run establish it.
