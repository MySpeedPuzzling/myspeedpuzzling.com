<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\Service\ResetInterface;

final class NullMercureHub implements HubInterface, ResetInterface
{
    /** @var Update[] */
    private array $publishedUpdates = [];

    // Set: every publish throws it (a hub that is down) - until failWith(null); the reset between a test's requests keeps
    // it, so a test can make the hub fail for its next request (each test's kernel has a hub of its own)
    private null|\Throwable $failure = null;

    public function publish(Update $update): string
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->publishedUpdates[] = $update;

        return 'test-id';
    }

    public function getUrl(): string
    {
        return 'http://localhost/.well-known/mercure';
    }

    public function getPublicUrl(): string
    {
        return 'http://localhost/.well-known/mercure';
    }

    public function getProvider(): TokenProviderInterface
    {
        throw new \RuntimeException('Not implemented in test double');
    }

    public function getFactory(): TokenFactoryInterface
    {
        return new NullTokenFactory();
    }

    /** @return Update[] */
    public function getPublishedUpdates(): array
    {
        return $this->publishedUpdates;
    }

    public function failWith(null|\Throwable $failure): void
    {
        $this->failure = $failure;
    }

    public function reset(): void
    {
        $this->publishedUpdates = [];
    }
}
