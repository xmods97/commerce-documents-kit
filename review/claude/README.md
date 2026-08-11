# Claude review workspace

Claude may implement the approved review fixes in the repository and write review artifacts in this directory.

Allowed:

- `review-report.md`
- `security-findings.md`
- `test-results.md`
- supporting evidence files

Allowed source changes are limited to findings C1, H1-H6, M4-M8 and their focused tests/documentation. Do not modify the main Geward repository. Do not run database migrations, Laragon installation, deployment, real email, external services, push, merge, or release. Keep secrets in server config only. Write the final report to `review-report.md` and the remaining findings/status to `security-findings.md`.
