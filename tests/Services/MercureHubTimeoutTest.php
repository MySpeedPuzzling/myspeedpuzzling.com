<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\Exception\RuntimeException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class MercureHubTimeoutTest extends KernelTestCase
{
    private const string HUB_SERVICE_ID = 'mercure.hub.default';

    /** @var array{server: mixed, env: mixed}|null */
    private null|array $originalMercureUrl = null;

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->originalMercureUrl !== null) {
            if ($this->originalMercureUrl['server'] === null) {
                unset($_SERVER['MERCURE_URL']);
            } else {
                $_SERVER['MERCURE_URL'] = $this->originalMercureUrl['server'];
            }

            if ($this->originalMercureUrl['env'] === null) {
                unset($_ENV['MERCURE_URL']);
            } else {
                $_ENV['MERCURE_URL'] = $this->originalMercureUrl['env'];
            }
        }
    }

    /**
     * A hub that accepts the connection and never answers: the publish gives up after the 2 s silence limit of the
     * `mercure_hub.client` scoped client (config/packages/mercure.php) instead of PHP's 60 s default_socket_timeout.
     * The callers catch and log it (fail-open).
     */
    public function testPublishToAHubThatNeverAnswersGivesUpWithinTheLimit(): void
    {
        // The kernel completes the TCP handshake from the listen backlog, nothing ever accepts or answers
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertNotFalse($server, (string) $errorMessage);
        $address = stream_socket_get_name($server, false);
        self::assertIsString($address);

        $this->originalMercureUrl = ['server' => $_SERVER['MERCURE_URL'] ?? null, 'env' => $_ENV['MERCURE_URL'] ?? null];
        $_SERVER['MERCURE_URL'] = $_ENV['MERCURE_URL'] = 'http://' . $address . '/.well-known/mercure';

        self::bootKernel();
        /** @var HubInterface $hub */
        $hub = self::getContainer()->get(self::HUB_SERVICE_ID);

        $startedAt = microtime(true);

        try {
            $hub->publish(new Update('https://myspeedpuzzling.com/test', 'data'));
            self::fail('A hub that never answers must not count as published');
        } catch (RuntimeException) {
            $elapsed = microtime(true) - $startedAt;
            // It did wait for an answer (not a refused connection), but nowhere near the old 60 s - the upper
            // bound leaves a loaded CI worker room above the 3 s max_duration
            self::assertGreaterThan(1.5, $elapsed);
            self::assertLessThan(6.0, $elapsed);
        } finally {
            fclose($server);
        }
    }
}
