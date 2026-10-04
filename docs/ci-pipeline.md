# CI / deploy pipeline

One workflow, `.github/workflows/test.yml` ("CI"), from push to production. The
old separate Release workflow (it only started building once every test had
passed) is gone. Measured 2026-10-04: push to main → lily webhook ~2 min, → live
~2.5 min (was 7-10 min).

## Jobs

| job | runs on | what |
|---|---|---|
| `tests (1/3..3/3)` | PR + main | ParaTest shards (`--shard=i/3`, round-robin), one worker per CPU, each worker on its own clone of the test database (`tests/bootstrap.php`) |
| `phpstan`, `migrations-up-to-date` | PR + main | gates |
| `coding-standards` | PR + main | advisory (`continue-on-error`), never blocks a deploy |
| `docker` | main | builds the production image **while the gates run**, pushes `website:sha-<commit>` only, plus `website:build-assets` |
| `verified` | same-repo PRs | after every gate passed: uploads an artifact `verified-tree-<tree sha>` for the tree the PR run tested (the PR merged into main) |
| `plan` | main | is there such an artifact for this commit's tree? |
| `deploy` | main | after every gate + `docker` (tree not verified) |
| `deploy-fast` | main | after `docker` only, when `plan` found the tree verified - the squash merge of a PR whose CI ran on the current main. The gates still run as a second opinion |
| `sentry-release` | main | after whichever deploy job fired |

Both deploy jobs run `.github/actions/deploy-production`: skip if the commit is
**behind** the revision production runs (compare API against the
`org.opencontainers.image.revision` label of `website:main` - pipelines of quick
pushes can finish out of order), otherwise `docker buildx imagetools create` the
commit's image as `website:main` (registry-side copy) and fire the lily webhook
(`{"app":"myspeedpuzzling","tag":"main"}`). A red commit never reaches `main`.

## Carried build assets

Each image carries recent builds' hashed `/build` files (see
`.docker/merge-previous-build.php`, age-based retention). They come from the
tiny `website:build-assets` image (Dockerfile target `build-assets`), which every
main build pushes after the app image; builds are serialized
(`concurrency: docker-build-main`) so the chain stays complete. If the tag is
missing, the `docker` job seeds it from `website:main` (one-time ~30 s).

## Operations

- Redeploy / roll back: re-POST the webhook with `tag=sha-<7 chars>` of a prior
  build, or queue a lily job by hand (see the production access notes), or re-tag
  `website:main` with `docker buildx imagetools create`.
- A failed deploy job never touches production: lily keeps the previous
  generation. Re-run the job.
- Locally: `vendor/bin/paratest --testsuite "Project Test Suite"` runs what one
  CI shard runs, all shards at once.
