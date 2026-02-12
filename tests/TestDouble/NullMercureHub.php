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

    public function publish(Update $update): string
    {
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

    public function getFactory(): null|TokenFactoryInterface
    {
        return null;
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
