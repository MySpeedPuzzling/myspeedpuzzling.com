<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;

final class NullTokenFactory implements TokenFactoryInterface
{
    public function create(array $grants = [], array $additionalClaims = []): string
    {
        return 'test-jwt-token';
    }
}
