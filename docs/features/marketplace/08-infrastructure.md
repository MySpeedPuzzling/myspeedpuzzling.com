# 08 - Infrastructure Changes

> **STATUS: COMPLETED** — Mercure has already been separated from the FrankenPHP web container into its own dedicated Docker service. The documentation below is kept for reference.

## Overview

Separate Mercure from the FrankenPHP web container into its own dedicated Docker service. This is required because Mercure community edition does not support HA (high availability) — it uses internal locks that prevent multiple instances. By running Mercure separately, the web container can be restarted during deployments (blue-green) without interrupting real-time connections.

## Current Setup

Currently, Mercure runs embedded within FrankenPHP:

```yaml
# compose.yml (current)
web:
    build:
        context: .
        target: app_dev
    environment:
        MERCURE_JWT_SECRET: "dummy-local-secret"
        MERCURE_SUBSCRIBER_JWT_KEY: "dummy-local-secret"
        MERCURE_URL: "http://web:8080/.well-known/mercure"
        MERCURE_PUBLIC_URL: "http://localhost:8080/.well-known/mercure"
    ports:
        - "8080:8080"
```

FrankenPHP serves both the PHP application and the Mercure hub on the same port (`:8080/.well-known/mercure`).

## Target Setup

### Docker Compose Changes

```yaml
# compose.yml (new)
web:
    build:
        context: .
        target: app_dev
    environment:
        # Mercure publisher connects to the separate mercure service
        MERCURE_URL: "http://mercure:3000/.well-known/mercure"
        MERCURE_PUBLIC_URL: "http://localhost:3001/.well-known/mercure"
        MERCURE_JWT_SECRET: "${MERCURE_JWT_SECRET:-dummy-local-secret}"
        # Remove Mercure subscriber key from web (web only publishes)
    ports:
        - "8080:8080"
    depends_on:
        - mercure

mercure:
    image: dunglas/mercure:latest
    environment:
        SERVER_NAME: ":3000"
        MERCURE_JWT_SECRET: "${MERCURE_JWT_SECRET:-dummy-local-secret}"
        MERCURE_SUBSCRIBER_JWT_KEY: "${MERCURE_SUBSCRIBER_JWT_KEY:-dummy-local-secret}"
        MERCURE_EXTRA_DIRECTIVES: |
            cors_origins http://localhost:8080
            anonymous
    ports:
        - "3001:3000"
    volumes:
        - mercure_data:/data    # Persistent storage for Mercure's Bolt database
    restart: unless-stopped
    # IMPORTANT: This container is NOT restarted during deployments
    # It maintains SSE connections across web container restarts

volumes:
    mercure_data:
```

### FrankenPHP Configuration Changes

Disable Mercure module in FrankenPHP since it's now external:

In the `Caddyfile` or FrankenPHP configuration, remove/comment out the Mercure directives that were previously serving the hub.

For development Dockerfile, ensure the FrankenPHP build does NOT include the Mercure module, or that it's configured but not active.

### Symfony Configuration Changes

Update `config/packages/mercure.php`:

```php
// The URL used by the PHP application to publish updates (server-to-server)
// Points to the internal Docker network address of the Mercure service
$container->extension('mercure', [
    'hubs' => [
        'default' => [
            'url' => '%env(MERCURE_URL)%',
            'public_url' => '%env(MERCURE_PUBLIC_URL)%',
            'jwt' => [
                'secret' => '%env(MERCURE_JWT_SECRET)%',
                'publish' => ['*'],
            ],
        ],
    ],
]);
```

### Environment Variables

Update `.env` and `.env.local`:

```dotenv
# Mercure (separate container)
MERCURE_URL=http://mercure:3000/.well-known/mercure
MERCURE_PUBLIC_URL=http://localhost:3001/.well-known/mercure
MERCURE_JWT_SECRET=dummy-local-secret
MERCURE_SUBSCRIBER_JWT_KEY=dummy-local-secret
```

### Production Deployment Considerations

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   Reverse   │────▶│  Web (new)   │────▶│  Mercure    │
│   Proxy     │     │  FrankenPHP  │     │  (dunglas)  │
│  (nginx/    │     │  Blue/Green  │     │  Persistent │
│   caddy)    │     │  Deployable  │     │  Single     │
│             │────▶│              │     │  Instance   │
└─────────────┘     └─────────────┘     └─────────────┘
       │                                       ▲
       │          SSE connections               │
       └───────────────────────────────────────┘
```

Key points for production:
- Reverse proxy routes `/.well-known/mercure` to the Mercure container
- Reverse proxy routes everything else to the web container(s)
- Mercure container runs as a single instance (no HA, no scaling)
- Mercure container is NOT restarted during deployments
- Web containers can be blue-green deployed without breaking SSE connections
- Mercure uses Bolt database (default) for subscription persistence — volume-mounted

### Reverse Proxy Configuration (nginx example)

```nginx
# Mercure SSE endpoint
location /.well-known/mercure {
    proxy_pass http://mercure:3000;
    proxy_set_header Connection '';
    proxy_http_version 1.1;
    proxy_buffering off;
    proxy_cache off;
    # SSE specific
    proxy_read_timeout 24h;
    chunked_transfer_encoding on;
}

# Everything else → web container
location / {
    proxy_pass http://web:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

## Testing Considerations

### Mercure Mocking in Tests

Since tests cannot rely on a running Mercure hub, create a test double:

```php
// tests/TestDouble/NullMercureHub.php
namespace App\Tests\TestDouble;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;

final class NullMercureHub implements HubInterface
{
    /** @var Update[] */
    private array $publishedUpdates = [];

    public function publish(Update $update): string
    {
        $this->publishedUpdates[] = $update;
        return 'test-id';
    }

    public function getUrl(): string
    {
        return 'http://localhost:3001/.well-known/mercure';
    }

    public function getPublicUrl(): string
    {
        return 'http://localhost:3001/.well-known/mercure';
    }

    public function getProvider(): TokenProviderInterface
    {
        throw new \RuntimeException('Not implemented in test double');
    }

    /** @return Update[] */
    public function getPublishedUpdates(): array
    {
        return $this->publishedUpdates;
    }

    public function reset(): void
    {
        $this->publishedUpdates = [];
    }
}
```

Register in test services:

```yaml
# config/services_test.yaml
services:
    Symfony\Component\Mercure\HubInterface:
        class: App\Tests\TestDouble\NullMercureHub
```

This allows tests to:
- Verify that updates are published with correct topics and data
- Not require a running Mercure server
- Assert on published updates in integration tests

## Migration Path

1. Add `mercure` service to `compose.yml`
2. Update environment variables
3. Update Symfony Mercure configuration
4. Disable embedded Mercure in FrankenPHP
5. Test that publishing from PHP reaches the new Mercure container
6. Test that browser SSE subscriptions work via the new port
7. Update production deployment scripts
8. Deploy Mercure container first (persistent)
9. Deploy web container with new configuration
