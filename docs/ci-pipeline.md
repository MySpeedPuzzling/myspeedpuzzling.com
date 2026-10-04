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
| `coding-standards` | PR + main | advisory (`continue-on-error`), never blocks a deploy; plain PHP on the runner (`setup-php`), not the app image |
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

## PHPStan and PHPCS (measured 2026-10-04)

Measured side by side on the runners (throwaway workflow on `ci/static-analysis-speed`):

- **PHPStan stays in the app image.** It is a gate, and it must report what it reports locally. On the bare runner,
  PHP was 8.5.11 with a different extension set, and the result cache never matched
  (`metaExtensions`: phpstan-symfony hashes the dumped container, which differed between runs there). Inside the image
  the result cache works: a typical commit takes 5-7s, against ~50s for a full analysis.
  - A full analysis runs whenever the container changes, e.g. any new service class. That is the case where
    `phpstan` can become the slowest gate.
  - `cache:warmup --no-optional-warmers` takes 3s instead of 10s; PHPStan only needs the container dump.
  - "Used memory" sums the parallel workers. 2 GB on a full run is not near any limit.
- **PHPCS runs on plain PHP (`setup-php`), with its cache** (`phpcs.xml`: `cache` + `parallel`). PHPCS only reads
  tokens, so the image's extensions do not matter. Job time: 66-103s down to ~17s.
- The job containers' `Initialize containers` step (pulling the base image) is a fixed cost of every other job - see below.

## Base image

`ghcr.io/myspeedpuzzling/web-base-php85:main` (repo `MySpeedPuzzling/Docker`,
rebuilt daily) is the job container of the gates, the dev `web` service and the
`FROM` of the production image. Since 2026-10-04 (Docker#2) it ships no
compilers or -dev headers (libheif is built in a separate stage) and its layers
are **zstd**-compressed: 640 → 536 MB on amd64, cold pull on a runner 24-33 s →
19-22 s. Pulling needs Docker >= 23 (runners 28, lily 29).

Every runtime tool is kept on purpose - inkscape is ImageMagick's SVG delegate
(SVG avatar uploads), wkhtmltopdf / librsvg2-bin are used by hand for print
PDFs (QR codes, vouchers). A change to the base image is validated the way
myspeedpuzzling.com#235 did it: build the Docker repo branch as its own tag
(`workflow_dispatch` on the branch), run the app CI inside it, and compare every
image generator, the upload pipeline and the PDF tools pixel by pixel against
the current image.

Rollback: `docker buildx imagetools create -t ghcr.io/myspeedpuzzling/web-base-php85:main
ghcr.io/myspeedpuzzling/web-base-php85@<previous digest>`, then push to main
(the next build uses it). The digest before Docker#2 was
`sha256:75d4a8165fdea5ba821601b427f68e9ef0a336f32001392d4793589c31b4e5a6`.

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
