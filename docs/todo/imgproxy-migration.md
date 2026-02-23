# Migration: Liip Imagine → imgproxy + Nginx Cache

## Goal

Replace Liip Imagine bundle (PHP-based thumbnail generation) with imgproxy (Go-based image processing service) fronted by Nginx reverse proxy cache. This eliminates PHP from the image serving path entirely, removes Redis metadata caching for thumbnails, and simplifies the architecture.

The migration is **progressive** — both systems run side by side, controlled by an environment variable. This allows deploying imgproxy infrastructure first, verifying it works, warming caches, and then switching traffic with a single env change. Rollback is equally simple — flip the env back.

## Current Architecture

```
Browser → S3/MinIO (direct URL from imagine_filter)
             ↑ thumbnails stored here with "thumbnails/" prefix
             |
   Liip Imagine generates on first request (lazy mode)
             |
   Loads original from S3 via flysystem_loader
             |
   Redis caches file metadata (Lustmored\Flysystem\Cache\CacheAdapter)
             |
   WarmupCache messages dispatched async via Messenger after upload
```

**Components to eventually remove (Phase 4):**
- `liip/imagine-bundle` (composer dependency)
- `lustmored/flysystem-v2-simple-cache-adapter` (composer dependency)
- `liip_imagine.php` config
- `oneup_flysystem.php` — `cached` adapter and `cached` filesystem (keep `minio` adapter/filesystem)
- `cache.flysystem.psr6` cache pool from `cache.php`
- `minio.cache.adapter` service from `services.php`
- `WarmupCache` dispatches from all message handlers (6 occurrences)
- `WarmupCache::class => 'async'` routing from `messenger.php`
- `FRANKENPHP_IMAGE_WORKER_NUM` and `FRANKENPHP_IMAGE_WORKER_MATCH` env vars from `compose.yml`
- `liip_imagine` route import

**Templates using `imagine_filter` (20 occurrences across 13 files):**
- `_player_solvings.html.twig` — `puzzle_small` (1×)
- `messaging/_avatar.html.twig` — `puzzle_small` (1×)
- `notifications.html.twig` — `puzzle_small` (5×)
- `_solving_time_form.html.twig` — `puzzle_medium` (2×)
- `stopwatch.html.twig` — `puzzle_medium` (1×)
- `puzzle_detail.html.twig` — `puzzle_medium` (1× og:image)
- `event_detail.html.twig` — `puzzle_small` (1×)
- `edit-profile.html.twig` — `puzzle_medium` (1×)
- `_competition_event.html.twig` — `puzzle_small` (1×)
- `rating/_rating_card.html.twig` — `puzzle_small` (1×)
- `marketplace/_listing_card.html.twig` — `puzzle_small` (1×)
- `player_profile.html.twig` — `puzzle_small` (1×) + `puzzle_medium` (1× og:image)
- `added_tracking_recap.html.twig` — `puzzle_medium` (2×)

**Note:** `puzzle_small_webp` and `puzzle_medium_webp` filter sets are defined in config but **never used** in any template. They can be dropped entirely — imgproxy will handle format negotiation automatically via `Accept` header.

**Image fields in entities:**
- `Puzzle.image` — puzzle photo
- `Player.avatar` — player avatar
- `PuzzleSolvingTime.finishedPuzzlePhoto` — result photo
- `Competition.logo` — competition/event logo
- `Manufacturer.logo` — manufacturer logo

**Database stores:** S3 object keys (e.g. `abc-123-1709012345.jpg`, `avatars/player-id-123.jpg`). No changes needed — imgproxy references the same keys.

## New Architecture

```
Browser → Nginx (disk cache hit?) → imgproxy → S3/MinIO
```

- **imgproxy**: Stateless Go service, fetches original from S3, transforms on the fly
- **Nginx**: Reverse proxy with disk cache, serves cached thumbnails as static files
- **Presets**: Named presets in imgproxy config matching current Liip Imagine filter names
- **Format negotiation**: imgproxy reads browser `Accept` header, serves WebP/AVIF automatically
- **No storage**: Transformed images only live in Nginx disk cache, not in S3

---

## Phase 1: Deploy imgproxy infrastructure (no traffic switch)

Goal: Get imgproxy + Nginx running alongside existing Liip Imagine. No changes to PHP code. Both systems serve from the same S3 originals. You can manually test imgproxy URLs in browser.

### Step 1.1: Add imgproxy + Nginx to Docker Compose

Add two new services to `compose.yml`:

```yaml
imgproxy:
    image: darthsim/imgproxy:latest
    restart: unless-stopped
    environment:
        IMGPROXY_USE_S3: "true"
        IMGPROXY_S3_REGION: "global"
        IMGPROXY_S3_ENDPOINT: "http://minio:9000"
        AWS_ACCESS_KEY_ID: "speedpuzzling"
        AWS_SECRET_ACCESS_KEY: "speedpuzzling"
        IMGPROXY_PREFERRED_FORMATS: "avif,webp,jpeg"
        IMGPROXY_PRESETS: "puzzle_small=rs:fit:200:200/q:88,puzzle_medium=rs:fit:400:400/q:91/el:1"
        IMGPROXY_AUTO_ROTATE: "true"
        IMGPROXY_ENFORCE_THUMBNAIL: "true"
        # Security: use signed URLs in production
        # IMGPROXY_KEY: "<hex key>"
        # IMGPROXY_SALT: "<hex salt>"
    depends_on:
        - minio

images-cache:
    image: nginx:alpine
    restart: unless-stopped
    volumes:
        - .docker/nginx-imgproxy.conf:/etc/nginx/conf.d/default.conf
        - .docker-data/imgproxy-cache:/var/cache/nginx/imgproxy
    depends_on:
        - imgproxy
    ports:
        - "19100:80"
```

Notes:
- `IMGPROXY_PRESETS` — preset names match current Liip Imagine filter names for consistency
- `el:1` on `puzzle_medium` means "enlarge: false" (equivalent to current `allow_upscale: false`)
- Port `19100` for development access — you can test manually alongside existing `19000`
- In production, the `images-cache` nginx would be the entry point for all image URLs

### Step 1.2: Create Nginx config for imgproxy caching

Create `.docker/nginx-imgproxy.conf`:

```nginx
proxy_cache_path /var/cache/nginx/imgproxy
    levels=1:2
    keys_zone=thumbnails:128m
    inactive=365d;

server {
    listen 80;

    location / {
        proxy_pass http://imgproxy:8080;
        proxy_cache thumbnails;
        proxy_cache_valid 200 365d;
        proxy_cache_use_stale error timeout updating;
        proxy_cache_lock on;

        # Pass Accept header so imgproxy can do format negotiation
        proxy_set_header Accept $http_accept;

        # Include format in cache key so WebP and JPEG are cached separately
        proxy_cache_key "$scheme$request_method$host$request_uri$http_accept";

        add_header X-Cache-Status $upstream_cache_status;
    }
}
```

Note: Using `$http_accept` in cache key is important — without it, a WebP response cached from Chrome would be served to Safari which doesn't support WebP. This does mean more cache entries per image, but the disk cost is negligible.

### Step 1.3: Manual verification

After `docker compose up`, test manually:

```
# Direct imgproxy (bypassing nginx cache):
curl -I http://localhost:19100/preset:puzzle_small/plain/s3://puzzle/some-existing-image.jpg

# Check X-Cache-Status header — should be MISS first time, HIT second time
curl -I http://localhost:19100/preset:puzzle_small/plain/s3://puzzle/some-existing-image.jpg
```

Verify:
- Correct dimensions (200×200 for small, 400×400 for medium)
- Auto-rotate works
- WebP served when `Accept: image/webp` header is present
- Nginx caching works (HIT on second request)

---

## Phase 2: Build the switchable Twig layer + warmup command

Goal: Create a Twig filter that can serve from either Liip Imagine or imgproxy, controlled by an env variable. Both systems active, no user impact yet. Also create the warmup command.

### Step 2.1: Add env variable for image provider switch

Add to `.env`:

```
# Image thumbnail provider: "imagine" (default, current) or "imgproxy" (new)
IMAGE_PROVIDER=imagine
IMGPROXY_BASE_URL=http://localhost:19100
```

Register as parameter in `services.php`:

```php
->parameters([
    'imageProvider' => env('IMAGE_PROVIDER'),
    'imgproxyBaseUrl' => env('IMGPROXY_BASE_URL'),
    'imgproxyBucket' => 'puzzle',
])
```

### Step 2.2: Create switchable Twig filter

Create `src/Twig/ImageThumbnailExtension.php`:

```php
final class ImageThumbnailExtension extends AbstractExtension
{
    public function __construct(
        private readonly string $imageProvider,   // "imagine" or "imgproxy"
        private readonly string $imgproxyBaseUrl,
        private readonly string $imgproxyBucket,
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('thumbnail', $this->thumbnailUrl(...)),
        ];
    }

    public function thumbnailUrl(string $path, string $preset): string
    {
        if ($this->imageProvider === 'imgproxy') {
            return sprintf(
                '%s/preset:%s/plain/s3://%s/%s',
                $this->imgproxyBaseUrl,
                $preset,
                $this->imgproxyBucket,
                ltrim($path, '/'),
            );
        }

        // Delegate to Liip Imagine — return the imagine filter URL
        // This needs CacheManager injected to generate the URL
        // Or simply keep using imagine_filter in templates for now
        // (see Step 2.3 for approach)
    }
}
```

**Alternative simpler approach for Step 2.2:** Instead of a switchable filter, create the `thumbnail` Twig filter that only handles imgproxy URLs. Then in templates, use a Twig conditional:

```twig
{# Not recommended — too verbose in 20 places #}
```

**Recommended approach:** Create the `thumbnail` filter that internally delegates to either Liip Imagine's `CacheManager` or imgproxy URL generation based on the env variable. Inject `Liip\ImagineBundle\Imagine\Cache\CacheManager` conditionally.

```php
use Liip\ImagineBundle\Imagine\Cache\CacheManager;

final class ImageThumbnailExtension extends AbstractExtension
{
    public function __construct(
        private readonly string $imageProvider,
        private readonly string $imgproxyBaseUrl,
        private readonly string $imgproxyBucket,
        private readonly ?CacheManager $imagineCacheManager = null,
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('thumbnail', $this->thumbnailUrl(...)),
        ];
    }

    public function thumbnailUrl(string $path, string $preset): string
    {
        return match ($this->imageProvider) {
            'imgproxy' => sprintf(
                '%s/preset:%s/plain/s3://%s/%s',
                $this->imgproxyBaseUrl,
                $preset,
                $this->imgproxyBucket,
                ltrim($path, '/'),
            ),
            default => $this->imagineCacheManager?->getBrowserPath($path, $preset)
                ?? throw new \RuntimeException('Liip Imagine CacheManager not available'),
        };
    }
}
```

### Step 2.3: Replace `imagine_filter` with `thumbnail` in all templates

Search & replace across all 13 template files (20 occurrences):

```twig
{# Before #}
{{ image|imagine_filter('puzzle_small') }}
{{ image|imagine_filter('puzzle_medium') }}

{# After #}
{{ image|thumbnail('puzzle_small') }}
{{ image|thumbnail('puzzle_medium') }}
```

At this point with `IMAGE_PROVIDER=imagine`, everything works exactly as before — the `thumbnail` filter delegates to Liip Imagine's `CacheManager`. No behavior change, safe to deploy.

### Step 2.4: Create warmup console command

Command: `myspeedpuzzling:imgproxy:warmup`

Purpose: Iterate over all images in the database and make HTTP requests to the imgproxy nginx cache for each image + each preset. This pre-populates the Nginx disk cache before switching traffic.

```php
// src/Command/WarmupImgproxyCacheCommand.php

#[AsCommand(
    name: 'myspeedpuzzling:imgproxy:warmup',
    description: 'Warmup imgproxy cache by requesting all image thumbnails',
)]
final class WarmupImgproxyCacheCommand extends Command
{
    // Inject: EntityManagerInterface, HttpClientInterface, string $imgproxyBaseUrl, string $imgproxyBucket

    protected function configure(): void
    {
        $this->addOption('concurrency', 'c', InputOption::VALUE_REQUIRED, 'Concurrent requests', '20');
        $this->addOption('preset', 'p', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Presets to warm', ['puzzle_small', 'puzzle_medium']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 1. Query all unique image paths from database:
        //    - Puzzle.image (WHERE image IS NOT NULL)
        //    - Player.avatar (WHERE avatar IS NOT NULL)
        //    - PuzzleSolvingTime.finishedPuzzlePhoto (WHERE finishedPuzzlePhoto IS NOT NULL)
        //    - Competition.logo (WHERE logo IS NOT NULL)
        //    - Manufacturer.logo (WHERE logo IS NOT NULL)
        //
        // 2. For each image path, for each preset:
        //    - Build the imgproxy URL
        //    - Make HTTP GET request via Symfony HttpClient
        //    - Use async responses with concurrency pool
        //
        // 3. Use Symfony HttpClient with concurrency (configurable, default 20)
        //    30,000+ images × 2 presets = 60,000+ requests
        //
        // 4. Warm with two Accept headers per image:
        //    - First pass: Accept: image/avif,image/webp,image/jpeg (modern browsers)
        //    - Second pass (optional --jpeg flag): Accept: image/jpeg (Safari/OG crawlers)
        //
        // 5. Use batch queries (e.g. 1000 at a time) to avoid memory issues
        //
        // 6. Output progress bar and summary (X images warmed, Y errors)
        //
        // Estimated runtime: ~60,000 requests at 20 concurrent = ~50 minutes

        return Command::SUCCESS;
    }
}
```

### Step 2.5: Create cleanup console command

Command: `myspeedpuzzling:imgproxy:cleanup-old-thumbnails`

Purpose: Remove all files under the `thumbnails/` prefix in the S3 bucket that were generated by Liip Imagine. Run after migration is fully verified.

```php
// src/Command/CleanupOldThumbnailsCommand.php

#[AsCommand(
    name: 'myspeedpuzzling:imgproxy:cleanup-old-thumbnails',
    description: 'Delete old Liip Imagine thumbnails from S3 (thumbnails/ prefix)',
)]
final class CleanupOldThumbnailsCommand extends Command
{
    // Inject: Filesystem (Flysystem)

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview what would be deleted without deleting');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 1. List all files under "thumbnails/" prefix in S3
        //    Using Flysystem: $this->filesystem->listContents('thumbnails', true)
        //
        // 2. If --dry-run: count and report, don't delete
        //
        // 3. If not --force: ask for confirmation with SymfonyStyle confirm()
        //
        // 4. Delete each file: $this->filesystem->delete($path)
        //
        // 5. Output progress and summary (X files deleted)
        //
        // Liip Imagine stores as: thumbnails/{filter_name}/{image_path}
        // e.g. thumbnails/puzzle_small/abc-123.jpg
        // With 30,000 images × 4 filter sets = ~120,000 files

        return Command::SUCCESS;
    }
}
```

### Step 2.6: Verify with IMAGE_PROVIDER=imagine (no change)

Deploy Phase 2 code with `IMAGE_PROVIDER=imagine`. Everything works exactly as before. The `thumbnail` filter delegates to Liip Imagine. imgproxy infrastructure is running but not serving traffic. Safe, zero-risk deployment.

---

## Phase 3: Switch traffic to imgproxy

Goal: Flip the env variable, verify, and confirm imgproxy is serving all thumbnails.

### Step 3.1: Run warmup command

Before switching traffic, pre-populate the Nginx cache:

```bash
docker compose exec web php bin/console myspeedpuzzling:imgproxy:warmup
```

This ensures users won't experience cold-cache latency on the first request after switching.

### Step 3.2: Switch the env variable

```
IMAGE_PROVIDER=imgproxy
```

Deploy (or just restart PHP workers if env is read at runtime). All thumbnail URLs now point to the Nginx-cached imgproxy instead of Liip Imagine.

### Step 3.3: Verify in production

Check:
- Thumbnails load correctly on key pages (puzzle detail, player profile, notifications, marketplace)
- OG images work (puzzle_detail.html.twig, player_profile.html.twig use `absolute_url`)
- `X-Cache-Status` header on image URLs shows `HIT` for most requests
- No broken images in browser console
- Mobile and desktop browsers serve appropriate formats (WebP/AVIF)

### Step 3.4: Rollback plan

If anything goes wrong:

```
IMAGE_PROVIDER=imagine
```

Redeploy. Liip Imagine is still fully configured and operational. Zero downtime rollback. WarmupCache messages are still dispatched on new uploads. Everything reverts instantly.

---

## Phase 4: Clean up Liip Imagine (after verification period)

Goal: After running imgproxy in production for a sufficient period (e.g. 1-2 weeks) and confirming stability, remove all Liip Imagine infrastructure.

### Step 4.1: Remove WarmupCache dispatches from message handlers

Remove `WarmupCache` import and dispatch from these 6 handlers:
- `AddPuzzleHandler.php`
- `AddPuzzleSolvingTimeHandler.php`
- `AddPuzzleTrackingHandler.php`
- `EditPuzzleSolvingTimeHandler.php`
- `EditProfileHandler.php`
- `ApprovePuzzleChangeRequestHandler.php`

The upload flow becomes: optimize → upload to S3 → done.

### Step 4.2: Remove Liip Imagine bundle and related config

1. `composer remove liip/imagine-bundle lustmored/flysystem-v2-simple-cache-adapter`
2. Delete `config/packages/liip_imagine.php`
3. Delete Liip Imagine route import (check `config/routes/`)
4. From `config/packages/oneup_flysystem.php` — remove `cached` adapter and `cached` filesystem (keep `minio` only)
5. From `config/packages/cache.php` — remove `cache.flysystem.psr6` pool
6. From `config/services.php` — remove `minio.cache.adapter` service definition
7. From `config/packages/messenger.php` — remove `WarmupCache::class => 'async'` routing

### Step 4.3: Remove FrankenPHP image worker config

From `compose.yml`, remove:
```yaml
FRANKENPHP_IMAGE_WORKER_NUM: "15"
FRANKENPHP_IMAGE_WORKER_MATCH: "/media/cache/resolve/*"
```

This frees up 15 PHP workers that were dedicated to Liip Imagine's proxy endpoint.

### Step 4.4: Simplify ImageThumbnailExtension

Remove the `imagine` branch and `CacheManager` dependency. The filter only generates imgproxy URLs now:

```php
final class ImageThumbnailExtension extends AbstractExtension
{
    public function __construct(
        private readonly string $imgproxyBaseUrl,
        private readonly string $imgproxyBucket,
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('thumbnail', $this->thumbnailUrl(...)),
        ];
    }

    public function thumbnailUrl(string $path, string $preset): string
    {
        return sprintf(
            '%s/preset:%s/plain/s3://%s/%s',
            $this->imgproxyBaseUrl,
            $preset,
            $this->imgproxyBucket,
            ltrim($path, '/'),
        );
    }
}
```

Remove the `IMAGE_PROVIDER` env variable — no longer needed.

### Step 4.5: Delete old thumbnails from S3

Run the cleanup command:

```bash
docker compose exec web php bin/console myspeedpuzzling:imgproxy:cleanup-old-thumbnails --force
```

This removes ~120,000 files under the `thumbnails/` prefix, reclaiming S3 storage.

### Step 4.6: Clean up Redis cache pool (if no longer needed)

If `cache.flysystem.psr6` was the only user of the Redis-backed Flysystem cache, it's already been removed in Step 4.2. Verify no other service uses it. The main Redis instance stays for other application caching.

---

## `GetResultImage` service — No changes needed

The `GetResultImage` service reads original images from S3 via Flysystem to generate OG share images. It uses `Intervention\Image` directly, NOT Liip Imagine. It will continue working as-is since the Flysystem `minio` filesystem is preserved throughout all phases.

---

## Production deployment considerations

**Environment variables needed in production:**
```
IMAGE_PROVIDER=imagine          # Phase 2-3: start with imagine, switch to imgproxy
IMGPROXY_BASE_URL=https://images.speedpuzzling.com  # or whatever the production image domain is
```

**URL signing (production, recommended):**
Enable imgproxy URL signing to prevent abuse (someone generating arbitrary resizes via raw URL manipulation). Add to imgproxy container env:
```
IMGPROXY_KEY=<generate hex key>
IMGPROXY_SALT=<generate hex salt>
```

Update `ImageThumbnailExtension` to sign URLs using HMAC-SHA256. imgproxy docs specify the signing format. The key/salt are injected as parameters.

**Phase-by-phase deployment timeline:**

| Phase | Deploy | Risk | Rollback |
|-------|--------|------|----------|
| Phase 1 | imgproxy + nginx containers only | Zero — no PHP changes | Remove containers |
| Phase 2 | PHP code + `IMAGE_PROVIDER=imagine` | Zero — delegating to same Liip Imagine | Revert `thumbnail` → `imagine_filter` |
| Phase 3 | `IMAGE_PROVIDER=imgproxy` | Low — both systems active | `IMAGE_PROVIDER=imagine` |
| Phase 4 | Remove Liip Imagine | Medium — no rollback to Imagine | Re-add package (unlikely needed) |

**Optional future CDN layer:**
If geographic distribution becomes needed later, put Cloudflare/CloudFront in front of the nginx cache:
`Browser → CDN → Nginx → imgproxy → S3`

This is additive and doesn't require any PHP code changes — just DNS + CDN config.

---

## Summary of changes per phase

### Phase 1 (infrastructure only)
| Area | Action |
|------|--------|
| `compose.yml` | Add `imgproxy` + `images-cache` services |
| `.docker/nginx-imgproxy.conf` | New file — Nginx reverse proxy cache config |

### Phase 2 (switchable code, no traffic change)
| Area | Action |
|------|--------|
| `src/Twig/ImageThumbnailExtension.php` | New file — switchable `thumbnail` Twig filter |
| `src/Command/WarmupImgproxyCacheCommand.php` | New file — cache warmup command |
| `src/Command/CleanupOldThumbnailsCommand.php` | New file — S3 thumbnail cleanup command |
| 13 Twig templates | Replace `imagine_filter` → `thumbnail` (20 occurrences) |
| `config/services.php` | Add imgproxy parameters |
| `.env` | Add `IMAGE_PROVIDER`, `IMGPROXY_BASE_URL` |

### Phase 3 (traffic switch)
| Area | Action |
|------|--------|
| `.env` (production) | `IMAGE_PROVIDER=imgproxy` |

### Phase 4 (cleanup)
| Area | Action |
|------|--------|
| 6 message handlers | Remove `WarmupCache` dispatch |
| `config/packages/liip_imagine.php` | Delete |
| `config/packages/oneup_flysystem.php` | Remove `cached` adapter/filesystem |
| `config/packages/cache.php` | Remove `cache.flysystem.psr6` pool |
| `config/packages/messenger.php` | Remove `WarmupCache` routing |
| `config/services.php` | Remove `minio.cache.adapter`, remove `IMAGE_PROVIDER`, simplify imgproxy params |
| `config/routes/` | Remove Liip Imagine routes |
| `compose.yml` | Remove `FRANKENPHP_IMAGE_WORKER_NUM` + `FRANKENPHP_IMAGE_WORKER_MATCH` |
| `composer.json` | Remove `liip/imagine-bundle`, `lustmored/flysystem-v2-simple-cache-adapter` |
| `src/Twig/ImageThumbnailExtension.php` | Simplify — remove Imagine branch |
| S3 bucket | Run cleanup command to delete `thumbnails/` prefix |
