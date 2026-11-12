# CI and pre-publication checks

Updated 2026-11-12.

- Automatic checks run for relevant changes on `main` and ready pull requests targeting `main`.
- Feature-branch pushes do not duplicate their PR checks. Draft PRs wait until `ready_for_review`.
- New PR commits cancel superseded PR tests. Main-branch release runs stay serialized and are not cancelled mid-publication.
- Documentation and Work Graph metadata alone do not start automatic check runs.
- Full test commands, matrices and job dependencies are preserved.

Existing release jobs still run their full tests/self-tests before publishing artifacts. Before a manual production deployment, run the full workflow (`release.yml`) with **Run workflow** on the exact source revision and wait for every required job to succeed. A queued, skipped, cancelled or billing-blocked full check is not a successful release check. Existing local/server preflight commands remain required.

For SMS Auth, workflow/test-only changes are verified without creating a plugin release. Manual dispatch is test-only unless `publish` is explicitly enabled; automatic plugin/build changes still publish only after both PHP test jobs pass.

Where a workflow publishes artifacts, publication remains after its checks. Changes to CI scheduling do not authorize a production deployment, SMS send, signature change or billing change.
