<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;

final class NullTokenFactory implements TokenFactoryInterface
{
    public function create(null|array $subscribe = [], null|array $publish = [], array $additionalClaims = []): string
    {
        return 'test-jwt-token';
    }
}
