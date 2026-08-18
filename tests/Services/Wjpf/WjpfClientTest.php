<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Wjpf;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Exceptions\WjpfRequestFailed;
use SpeedPuzzling\Web\Services\Wjpf\WjpfClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WjpfClientTest extends TestCase
{
    private const string TOKEN = 'test-token';
    private const string URL = 'https://worldjigsawpuzzle.org/users/users_pr.php';

    public function testSuccessfulLookupReturnsTheirRecord(): void
    {
        $client = $this->client(new MockResponse(json_encode([
            'IdJugador' => '189',
            'NombreURL' => 'cristina-roura-suarez',
            'MySpeedPuzzlingId' => '018dc357-dcfd-70a4-97bf-b6a2c8f0a48e',
            'status' => 'ok',
        ], JSON_THROW_ON_ERROR)));

        $user = $client->findUserByEmail('cristina@example.com');

        self::assertNotNull($user);
        self::assertSame('189', $user->idJugador);
        self::assertSame('cristina-roura-suarez', $user->nombreUrl);
        self::assertSame('018dc357-dcfd-70a4-97bf-b6a2c8f0a48e', $user->mySpeedPuzzlingId);
        self::assertSame('ok', $user->raw['status']);
    }

    public function testPlayerNotFoundIsNotAFailure(): void
    {
        $client = $this->client(new MockResponse('{"status": "error", "mensaje": "player not found"}'));

        self::assertNull($client->findUserByEmail('nobody@example.com'));
    }

    /**
     * Their errors carry a `coderror`; a missing player does not. That is the only thing
     * separating "we are misconfigured" from "this person is not a member".
     */
    public function testRemoteErrorCodeThrows(): void
    {
        $client = $this->client(new MockResponse('{"status": "error", "coderror": 151, "mensaje": "token invalid"}'));

        $this->expectException(WjpfRequestFailed::class);
        $this->expectExceptionMessageMatches('/151/');

        $client->findUserByEmail('someone@example.com');
    }

    /**
     * Their handler runs `echo $BDwjpf->error;` outside the JSON, so a database hiccup glues
     * text to the payload. The usable part must still be recovered.
     */
    public function testJsonSurroundedByDatabaseErrorTextIsSalvaged(): void
    {
        $client = $this->client(new MockResponse(
            'Table \'Jugadores\' is marked as crashed{"IdJugador":"7","NombreURL":"someone","MySpeedPuzzlingId":"","status":"ok"}Warning: something',
        ));

        $user = $client->findUserByEmail('someone@example.com');

        self::assertNotNull($user);
        self::assertSame('7', $user->idJugador);
        self::assertTrue($user->isUnclaimed());
    }

    public function testCompletelyUnreadableResponseThrows(): void
    {
        $client = $this->client(new MockResponse('<html><body>502 Bad Gateway</body></html>'));

        $this->expectException(WjpfRequestFailed::class);

        $client->findUserByEmail('someone@example.com');
    }

    public function testNonSuccessHttpStatusThrows(): void
    {
        $client = $this->client(new MockResponse('nope', ['http_code' => 500]));

        $this->expectException(WjpfRequestFailed::class);

        $client->findUserByEmail('someone@example.com');
    }

    /** Their JSON comes straight off MySQL rows, so ids can arrive unquoted. */
    public function testNumericIdJugadorIsAccepted(): void
    {
        $client = $this->client(new MockResponse('{"IdJugador":189,"NombreURL":"x","MySpeedPuzzlingId":null,"status":"ok"}'));

        $user = $client->findUserByEmail('someone@example.com');

        self::assertNotNull($user);
        self::assertSame('189', $user->idJugador);
    }

    /**
     * Their request_var() reads $_POST, and PHP only fills $_POST for a form-encoded body.
     * Sending JSON would leave every field invisible on their side.
     */
    public function testOutboundRequestIsFormEncodedNotJson(): void
    {
        $contentType = '';

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$contentType): MockResponse {
            foreach (is_array($options['headers'] ?? null) ? $options['headers'] : [] as $header) {
                if (is_string($header) && stripos($header, 'content-type') === 0) {
                    $contentType .= $header;
                }
            }

            return new MockResponse('{"IdJugador":"1","NombreURL":"x","MySpeedPuzzlingId":"","status":"ok"}');
        });

        (new WjpfClient($httpClient, new NullLogger(), self::URL, self::TOKEN))
            ->findUserByEmail('someone@example.com');

        self::assertStringContainsString('application/x-www-form-urlencoded', $contentType);
        self::assertStringNotContainsString('json', $contentType);
    }

    public function testReadOnlyLookupDoesNotSendOurPlayerId(): void
    {
        $body = $this->captureRequestBody(null);

        self::assertStringContainsString('email=someone%40example.com', $body);
        self::assertStringContainsString('token=' . self::TOKEN, $body);
        self::assertStringNotContainsString('myspeedpuzzlingid', $body);
    }

    public function testClaimSendsOurPlayerId(): void
    {
        $body = $this->captureRequestBody('018dc357-dcfd-70a4-97bf-b6a2c8f0a48e');

        self::assertStringContainsString('myspeedpuzzlingid=018dc357-dcfd-70a4-97bf-b6a2c8f0a48e', $body);
    }

    public function testDisabledWhenTokenIsEmpty(): void
    {
        $client = new WjpfClient(new MockHttpClient(), new NullLogger(), self::URL, '');

        self::assertFalse($client->isEnabled());
    }

    public function testTheirRecordHoldingAnotherPlayerIsNeitherUnclaimedNorOurs(): void
    {
        $client = $this->client(new MockResponse('{"IdJugador":"5","NombreURL":"x","MySpeedPuzzlingId":"aaaaaaaa-0000-0000-0000-000000000000","status":"ok"}'));

        $user = $client->findUserByEmail('someone@example.com');

        self::assertNotNull($user);
        self::assertFalse($user->isUnclaimed());
        self::assertFalse($user->isClaimedBy('bbbbbbbb-0000-0000-0000-000000000000'));
        self::assertTrue($user->isClaimedBy('AAAAAAAA-0000-0000-0000-000000000000'));
    }

    private function captureRequestBody(null|string $claimId): string
    {
        $captured = '';

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = is_string($options['body'] ?? null) ? $options['body'] : '';

            return new MockResponse('{"IdJugador":"1","NombreURL":"x","MySpeedPuzzlingId":"","status":"ok"}');
        });

        $client = new WjpfClient($httpClient, new NullLogger(), self::URL, self::TOKEN);
        $client->findUserByEmail('someone@example.com', $claimId);

        return $captured;
    }

    private function client(MockResponse $response): WjpfClient
    {
        return new WjpfClient(new MockHttpClient($response), new NullLogger(), self::URL, self::TOKEN);
    }
}
