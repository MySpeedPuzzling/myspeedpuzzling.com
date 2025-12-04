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
        return self::createPantherClient(
            options: [
                'browser' => self::SELENIUM,
                'external_base_uri' => $_SERVER['PANTHER_EXTERNAL_BASE_URI'] ?? 'http://web:8080',
            ],
            managerOptions: [
                'host' => $_SERVER['PANTHER_SELENIUM_HOST'] ?? 'http://chrome:4444',
                'capabilities' => DesiredCapabilities::chrome(),
            ],
        );
    }
}
