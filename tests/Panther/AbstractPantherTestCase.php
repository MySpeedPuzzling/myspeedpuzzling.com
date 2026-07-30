<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther;

use Facebook\WebDriver\Chrome\ChromeOptions;
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
            // app.scss enables `html { scroll-behavior: smooth }` unless the user prefers
            // reduced motion. WebDriver's scroll-element-into-view-before-click then
            // becomes an async animation, so the click fires mid-scroll and lands outside
            // the viewport ("element click intercepted"). Panther's own ChromeManager
            // forces reduced motion for exactly this reason, but only on the local
            // ChromeDriver path - mirror it for the Selenium capabilities we build here.
            $chromeOptions = new ChromeOptions();
            $chromeOptions->addArguments(['--force-prefers-reduced-motion']);

            $capabilities = DesiredCapabilities::chrome();
            $capabilities->setCapability(ChromeOptions::CAPABILITY_W3C, $chromeOptions);

            return self::createPantherClient(
                options: [
                    'browser' => self::SELENIUM,
                    // Use web-test container which runs in test environment with test database
                    'external_base_uri' => $_SERVER['PANTHER_EXTERNAL_BASE_URI'] ?? 'http://web-test:8080',
                ],
                managerOptions: [
                    'host' => $seleniumHost,
                    'capabilities' => $capabilities,
                ],
            );
        }

        // Default: Panther auto-starts PHP server and uses ChromeDriver (local machine).
        // No reduced-motion handling needed here - Panther's ChromeManager forces it by default.
        return self::createPantherClient();
    }

    /**
     * Log in a user for E2E testing.
     *
     * Uses a test-only endpoint that bypasses the login form and creates
     * a native UserAccount session. Only available in dev/test environments.
     *
     * @param Client $client The Panther client
     * @param string $userId The identity string (e.g., 'auth0|regular001' or 'msp|...')
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

        // The Panther browser is reused across tests while each test gets a FRESH
        // database (PantherDatabaseManager) - but cookies and mock-session files
        // survive. Since native login (2d), the session token resolves through the
        // user_account table, so a stale session pointing into a dropped per-test
        // database must never leak into the next test: start every login clean.
        $client->getWebDriver()->manage()->deleteAllCookies();

        $client->request('GET', '/_test/login?' . $params);

        // Fail loudly, with the server's actual response: a silent login failure
        // otherwise surfaces tests later as baffling "element not found" errors on
        // pages that quietly rendered anonymous (bit us in CI, 2026-07-30)
        $loginResponse = $client->getPageSource();

        if (!str_contains($loginResponse, 'Logged in as')) {
            throw new \RuntimeException(sprintf(
                "Test login for %s did not succeed. Response was:\n%s",
                $userId,
                mb_substr(strip_tags($loginResponse), 0, 3000),
            ));
        }

        // ... and verify the session actually STUCK: the login response proves the
        // endpoint ran, not that the next request is authenticated. The whoami
        // probe reports the resolved user and the database the follow-up request
        // talks to, which pins cross-database session bugs immediately.
        $client->request('GET', '/_test/whoami');
        $whoami = strip_tags($client->getPageSource());

        if (!str_contains($whoami, 'whoami=' . $userId)) {
            $cookies = array_map(
                static fn ($cookie): string => sprintf(
                    '%s(domain=%s path=%s secure=%s)',
                    $cookie->getName(),
                    $cookie->getDomain(),
                    $cookie->getPath(),
                    var_export($cookie->isSecure(), true),
                ),
                $client->getWebDriver()->manage()->getCookies(),
            );

            throw new \RuntimeException(sprintf(
                "Test login for %s did not stick.\nLogin response: %s\nWhoami probe: %s\nBrowser cookies: %s",
                $userId,
                mb_substr(strip_tags($loginResponse), 0, 500),
                mb_substr($whoami, 0, 500),
                implode(' | ', $cookies) ?: '(none)',
            ));
        }
    }
}
