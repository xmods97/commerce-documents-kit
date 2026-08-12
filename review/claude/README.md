# Claude review workspace

Claude may implement the approved review fixes in the repository and write review artifacts in this directory.

Allowed:

- `review-report.md`
- `security-findings.md`
- `test-results.md`
- `pdf-engine-decision.md`
- `admin-preview-wiring.md`
- supporting evidence files

Allowed source changes are limited to findings C1, H1-H6, M4-M8 and their focused tests/documentation, the production PDF engine, the admin-only local sandbox `.eml` capture, and the remaining M9/M10 and Low items authorised for the engine pass. Do not modify the main Geward repository. Do not run database migrations, Laragon installation, deployment, real email, external services, push, merge, or release. Keep secrets in server config only. Write the final report to `review-report.md` and the remaining findings/status to `security-findings.md`.
