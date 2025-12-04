<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther;

use Facebook\WebDriver\Remote\DesiredCapabilities;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

abstract class AbstractPantherTestCase extends PantherTestCase
{
    protected static function createBrowserClient(): Client
    {
        // Use Selenium when running in Docker (PANTHER_SELENIUM_HOST is set)
        // Otherwise use ChromeDriver directly (CI/local machine)
        if (isset($_SERVER['PANTHER_SELENIUM_HOST'])) {
            return self::createPantherClient(
                options: [
                    'browser' => self::SELENIUM,
                    'external_base_uri' => $_SERVER['PANTHER_EXTERNAL_BASE_URI'] ?? 'http://web:8080',
                ],
                managerOptions: [
                    'host' => $_SERVER['PANTHER_SELENIUM_HOST'],
                    'capabilities' => DesiredCapabilities::chrome(),
                ],
            );
        }

        // Default: Panther auto-starts PHP server and uses ChromeDriver
        return self::createPantherClient();
    }
}
