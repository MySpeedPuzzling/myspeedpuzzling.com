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
        // Check for explicit Selenium host configuration
        $seleniumHost = $_SERVER['PANTHER_SELENIUM_HOST'] ?? null;

        // Auto-detect Docker environment: if 'chrome' hostname resolves, we're in Docker
        if ($seleniumHost === null && gethostbyname('chrome') !== 'chrome') {
            $seleniumHost = 'http://chrome:4444';
        }

        // Use Selenium when available (Docker or CI)
        if ($seleniumHost !== null) {
            return self::createPantherClient(
                options: [
                    'browser' => self::SELENIUM,
                    // Use web-test container which runs in test environment with test database
                    'external_base_uri' => $_SERVER['PANTHER_EXTERNAL_BASE_URI'] ?? 'http://web-test:8080',
                ],
                managerOptions: [
                    'host' => $seleniumHost,
                    'capabilities' => DesiredCapabilities::chrome(),
                ],
            );
        }

        // Default: Panther auto-starts PHP server and uses ChromeDriver (local machine)
        return self::createPantherClient();
    }

    /**
     * Log in a user for E2E testing.
     *
     * Uses a test-only endpoint that bypasses Auth0 authentication.
     * This endpoint is only available in dev/test environments.
     *
     * @param Client $client The Panther client
     * @param string $userId The Auth0 user ID (e.g., 'auth0|regular001')
     * @param string $email User's email
     * @param string $name User's name
     */
    protected static function loginUser(
        Client $client,
        string $userId,
        string $email,
        string $name,
    ): void {
        $params = http_build_query([
            'userId' => $userId,
            'email' => $email,
            'name' => $name,
        ]);

        $client->request('GET', '/_test/login?' . $params);
    }
}
