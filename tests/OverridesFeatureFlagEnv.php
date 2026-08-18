<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

/**
 * Flip an env-var feature flag for one test and put it back afterwards.
 *
 * The "put it back" half is the point. These flags have real values in `.env`,
 * and a test that `unset()`s one leaves every kernel booted later in the run
 * unable to resolve the parameter - `EnvNotFoundException` the moment anything
 * touches it. That stayed invisible while a flag was only injected into a
 * single controller, and turned into hundreds of failures the day one became a
 * Twig global evaluated on nearly every rendered page (issue #147).
 */
trait OverridesFeatureFlagEnv
{
    /** @var array<string, string|false> original value, or false when it was unset */
    private array $originalFeatureFlagEnv = [];

    private function overrideFeatureFlagEnv(string $name, bool $enabled): void
    {
        if (!array_key_exists($name, $this->originalFeatureFlagEnv)) {
            $original = $_ENV[$name] ?? false;
            $this->originalFeatureFlagEnv[$name] = is_string($original) ? $original : false;
        }

        $_ENV[$name] = $enabled ? '1' : '0';
        $_SERVER[$name] = $_ENV[$name];
    }

    private function restoreFeatureFlagEnv(): void
    {
        foreach ($this->originalFeatureFlagEnv as $name => $original) {
            if ($original === false) {
                unset($_ENV[$name], $_SERVER[$name]);

                continue;
            }

            $_ENV[$name] = $original;
            $_SERVER[$name] = $original;
        }

        $this->originalFeatureFlagEnv = [];
    }
}
