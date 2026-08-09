<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V0;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\Wjpf\WjpfPairingCodeStore;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\WjpfPairingStatus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class WjpfPairingControllerTest extends WebTestCase
{
    private const string PATH = '/api/v0/wjpf-pairing';
    private const string TOKEN = 'test-wjpf-token';

    public function testKnownEmailIsAnsweredWithThePlayerId(): void
    {
        $browser = self::createClient();

        $browser->request('POST', self::PATH, [
            'token' => self::TOKEN,
            'idusuario' => '189',
            'email' => PlayerFixture::PLAYER_REGULAR_EMAIL,
            'nombreurl' => 'john-doe',
        ]);

        self::assertResponseIsSuccessful();

        $response = $this->decode($browser);
        self::assertSame('ok', $response['status']);
        // Exact casing matters - their client reads $respuesta['MySpeedPuzzlingId'].
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $response['MySpeedPuzzlingId']);

        $row = $this->identityRow($browser, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($row);
        self::assertSame('189', $row['wjpf_id']);
        self::assertSame('john-doe', $row['wjpf_name_url']);
        self::assertSame(WjpfPairingStatus::Paired->value, $row['status']);
    }

    /**
     * Their client writes whatever it finds straight into its database, so the id key must
     * be absent rather than empty when we have no match.
     */
    public function testUnknownEmailReturnsErrorWithoutAnIdKey(): void
    {
        $browser = self::createClient();

        $browser->request('POST', self::PATH, [
            'token' => self::TOKEN,
            'idusuario' => '999',
            'email' => 'nobody-at-all@example.com',
        ]);

        self::assertResponseIsSuccessful();

        $response = $this->decode($browser);
        self::assertSame('error', $response['status']);
        self::assertSame('player not found', $response['mensaje']);
        self::assertArrayNotHasKey('MySpeedPuzzlingId', $response);
    }

    public function testInvalidTokenIsRejected(): void
    {
        $browser = self::createClient();

        $browser->request('POST', self::PATH, [
            'token' => 'nope',
            'idusuario' => '189',
            'email' => PlayerFixture::PLAYER_REGULAR_EMAIL,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame(151, $this->decode($browser)['coderror']);
    }

    public function testMissingTokenIsRejected(): void
    {
        $browser = self::createClient();

        $browser->request('POST', self::PATH, [
            'idusuario' => '189',
            'email' => PlayerFixture::PLAYER_REGULAR_EMAIL,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** The legacy /api/v0 endpoints carry the token in the query string. */
    public function testTokenIsAcceptedFromTheQueryString(): void
    {
        $browser = self::createClient();

        $browser->request('POST', self::PATH . '?token=' . self::TOKEN, [
            'idusuario' => '189',
            'email' => PlayerFixture::PLAYER_REGULAR_EMAIL,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('ok', $this->decode($browser)['status']);
    }

    /** `idjugador` is their own column name and appeared in an earlier draft of the call. */
    public function testIdjugadorAliasIsAccepted(): void
    {
        $browser = self::createClient();

        $browser->request('POST', self::PATH, [
            'token' => self::TOKEN,
            'idjugador' => '42',
            'email' => PlayerFixture::PLAYER_REGULAR_EMAIL,
        ]);

        self::assertResponseIsSuccessful();

        $row = $this->identityRow($browser, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($row);
        self::assertSame('42', $row['wjpf_id']);
    }

    /** The code flow: identity came from the player's session, so no address is involved. */
    public function testPairingCodeIsRedeemed(): void
    {
        $browser = self::createClient();
        $store = $browser->getContainer()->get(WjpfPairingCodeStore::class);
        $code = $store->issue(PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', self::PATH, [
            'token' => self::TOKEN,
            'idusuario' => '777',
            'code' => $code,
            'nombreurl' => 'john-doe',
        ]);

        self::assertResponseIsSuccessful();

        $response = $this->decode($browser);
        self::assertSame('ok', $response['status']);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $response['MySpeedPuzzlingId']);

        $row = $this->identityRow($browser, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($row);
        self::assertSame('777', $row['wjpf_id']);
    }

    public function testPairingCodeCannotBeReplayed(): void
    {
        $browser = self::createClient();
        $store = $browser->getContainer()->get(WjpfPairingCodeStore::class);
        $code = $store->issue(PlayerFixture::PLAYER_REGULAR);

        $payload = ['token' => self::TOKEN, 'idusuario' => '777', 'code' => $code];

        $browser->request('POST', self::PATH, $payload);
        self::assertSame('ok', $this->decode($browser)['status']);

        $browser->request('POST', self::PATH, $payload);
        self::assertSame('error', $this->decode($browser)['status']);
        self::assertArrayNotHasKey('MySpeedPuzzlingId', $this->decode($browser));
    }

    public function testUnknownPairingCodeIsRejected(): void
    {
        $browser = self::createClient();

        $browser->request('POST', self::PATH, [
            'token' => self::TOKEN,
            'idusuario' => '777',
            'code' => 'made-up-code',
        ]);

        $response = $this->decode($browser);
        self::assertSame('error', $response['status']);
        self::assertArrayNotHasKey('MySpeedPuzzlingId', $response);
    }

    /** A code proves consent; an address only proves the two sites agree on a string. */
    public function testCodeTakesPrecedenceOverEmail(): void
    {
        $browser = self::createClient();
        $store = $browser->getContainer()->get(WjpfPairingCodeStore::class);
        $code = $store->issue(PlayerFixture::PLAYER_WITH_FAVORITES);

        $browser->request('POST', self::PATH, [
            'token' => self::TOKEN,
            'idusuario' => '777',
            'code' => $code,
            'email' => PlayerFixture::PLAYER_REGULAR_EMAIL,
        ]);

        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $this->decode($browser)['MySpeedPuzzlingId']);
    }

    public function testMissingPlayerIdIsRejected(): void
    {
        $browser = self::createClient();

        $browser->request('POST', self::PATH, [
            'token' => self::TOKEN,
            'email' => PlayerFixture::PLAYER_REGULAR_EMAIL,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testEmailMatchingIsCaseInsensitive(): void
    {
        $browser = self::createClient();

        $browser->request('POST', self::PATH, [
            'token' => self::TOKEN,
            'idusuario' => '189',
            'email' => mb_strtoupper(PlayerFixture::PLAYER_REGULAR_EMAIL),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->decode($browser)['MySpeedPuzzlingId']);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(KernelBrowser $browser): array
    {
        $content = $browser->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return null|array{wjpf_id: null|string, wjpf_name_url: null|string, status: string}
     */
    private function identityRow(KernelBrowser $browser, string $playerId): null|array
    {
        $connection = $browser->getContainer()->get(Connection::class);

        $row = $connection->executeQuery(
            'SELECT wjpf_id, wjpf_name_url, status FROM wjpf_identity WHERE player_id = :playerId',
            ['playerId' => $playerId],
        )->fetchAssociative();

        if ($row === false) {
            return null;
        }

        /** @var array{wjpf_id: null|string, wjpf_name_url: null|string, status: string} $row */
        return $row;
    }
}
