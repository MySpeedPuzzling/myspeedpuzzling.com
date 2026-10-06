<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Requests to the internal API in functional tests - `.env.test` configures the token and the reviewer player
 * (PlayerFixture::PLAYER_ADMIN).
 */
trait InternalApiRequests
{
    private const string INTERNAL_API_TOKEN = 'test-internal-api-token';

    /**
     * @param null|array<mixed> $body
     *
     * @return array<string, mixed> the decoded JSON answer, [] for an empty one
     */
    private static function callInternalApi(
        KernelBrowser $browser,
        string $method,
        string $uri,
        null|array $body = null,
        null|string $token = self::INTERNAL_API_TOKEN,
    ): array {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        $browser->request(
            $method,
            $uri,
            server: $server,
            content: $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null,
        );

        $content = (string) $browser->getResponse()->getContent();

        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $content);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
