<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Compete;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `POST /{_locale}/compete/{token}/connect` - a signed-in player on a personal link (README §5.4 "Sign-in from the
 * link"): connected automatically only when it is unambiguous.
 */
final class CompeteConnectControllerTest extends WebTestCase
{
    use CompeteApiRequests;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        self::forgetCachedAccess($this->browser);
    }

    public function testAPlayerWhoIsNobodyInTheCompetitionIsConnected(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $this->connect(OnlineRoundsFixture::TOKEN_ANONYMOUS);

        self::assertResponseIsSuccessful();
        self::assertSame('connected', $this->connectStatus());
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $this->connectedPlayer(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS));
    }

    public function testThePlayerOfTheLinkAlready(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->connect(OnlineRoundsFixture::TOKEN_CONNECTED);

        self::assertSame('already_connected', $this->connectStatus());
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->connectedPlayer(OnlineRoundsFixture::PARTICIPANT_CONNECTED));
    }

    public function testALinkOfAnotherAccountStaysTheirs(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $this->connect(OnlineRoundsFixture::TOKEN_CONNECTED);

        self::assertSame('belongs_to_other', $this->connectStatus());
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->connectedPlayer(OnlineRoundsFixture::PARTICIPANT_CONNECTED));
    }

    /**
     * Connecting would move their connection away from the participant they are - the organiser sorts it out
     */
    public function testAnotherParticipantOfTheCompetitionIsNotConnected(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->connect(OnlineRoundsFixture::TOKEN_ANONYMOUS);

        self::assertSame('is_other_participant', $this->connectStatus());
        self::assertNull($this->connectedPlayer(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS));
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->connectedPlayer(OnlineRoundsFixture::PARTICIPANT_CONNECTED));
    }

    public function testAnOrganiserPreviewingALinkIsConnectedOnlyOnThisIsMe(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $this->connect(OnlineRoundsFixture::TOKEN_ANONYMOUS);

        self::assertSame('organiser_confirm_required', $this->connectStatus());
        self::assertNull($this->connectedPlayer(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS));

        // "This is me" - a form post with the token in a field works as well as the header
        $this->browser->request('POST', '/en/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS . '/connect', [
            '_token' => 'csrf-token',
            'confirm' => '1',
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseIsSuccessful();
        self::assertSame('connected', $this->connectStatus());
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->connectedPlayer(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS));
    }

    public function testRefusalsAreJson(): void
    {
        $this->connect(OnlineRoundsFixture::TOKEN_ANONYMOUS);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('sign_in_required', $this->answer()['error']);

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $this->connect(OnlineRoundsFixture::TOKEN_ANONYMOUS, origin: 'https://evil.example');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('invalid_csrf_token', $this->answer()['error']);
        self::assertNull($this->connectedPlayer(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS));

        $this->connect('NoSuchLinkAnywhere2222');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('unknown_link', $this->answer()['error']);
    }

    private function connect(string $token, string $origin = 'http://localhost'): void
    {
        $this->browser->request('POST', '/en/compete/' . $token . '/connect', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => $origin,
            // BrowserKit would send the previous request's URL - a page of another site sends its own
            'HTTP_REFERER' => $origin . '/page',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: '{}');
    }

    private function connectStatus(): mixed
    {
        return $this->answer()['status'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsInt($decoded['serverNowMs'] ?? null);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function connectedPlayer(string $participantId): null|string
    {
        $playerId = $this->browser->getContainer()->get(Connection::class)->fetchOne(
            'SELECT player_id FROM competition_participant WHERE id = :id',
            ['id' => $participantId],
        );

        return is_string($playerId) ? $playerId : null;
    }
}
