<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Auth0\Symfony\Service;
use Symfony\Contracts\Service\ResetInterface;

final class Auth0ServiceResetter implements ResetInterface
{
    public function __construct(
        private readonly Service $auth0Service,
    ) {
    }

    public function reset(): void
    {
        $reflection = new \ReflectionClass($this->auth0Service);
        $property = $reflection->getProperty('sdk');
        $property->setValue($this->auth0Service, null);
    }
}
