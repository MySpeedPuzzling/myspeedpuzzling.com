<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ParticipantsSheet;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as Cup;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The participants sheet's profile search (`participants_sheet_player_search`, review B-m5): organiser tooling, so a
 * player the organiser blocked is found - blocking must not make anybody unassignable (player-blocklist.md rule 7) -
 * in the co-puzzler search shape; the event's organisers only.
 */
final class ParticipantsSheetPlayerSearchTest extends WebTestCase
{
    private const string SEARCH = '/en/participants-sheet-api/' . Cup::COMPETITION_RESULTS_CUP . '/player-search';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testAPlayerTheOrganiserBlocksIsFound(): void
    {
        // PLAYER_WITH_STRIPE organises the Results Cup and blocks Michael Johnson
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_WITH_STRIPE, 'blocked' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $found = array_values(array_filter(
            $this->search('Michael Johnson'),
            static fn (array $row): bool => $row['key'] === PlayerFixture::PLAYER_WITH_FAVORITES,
        ));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $found);
        self::assertSame(['key', 'value', 'label', 'code', 'guest', 'country', 'avatar', 'hidden'], array_keys($found[0]));
        self::assertSame('#PLAYER3', $found[0]['value']);
        self::assertSame('Michael Johnson', $found[0]['label']);
        self::assertSame('PLAYER3', $found[0]['code']);
        self::assertFalse($found[0]['guest']);
        self::assertFalse($found[0]['hidden']);
        $headers = $this->browser->getResponse()->headers;
        self::assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
        self::assertSame('noindex, nofollow', $headers->get('X-Robots-Tag'));

        // The site's search leaves them out for the organiser - the sheet must not
        $this->browser->request('GET', '/en/player-search-autocomplete/?format=co-puzzler&query=' . rawurlencode('Michael Johnson'));
        $site = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($site);
        self::assertNotContains(PlayerFixture::PLAYER_WITH_FAVORITES, array_column($site, 'key'));
    }

    public function testAPrivatePlayerIsFoundByTheirExactCodeOnlyAndWithoutIdentity(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertNotContains(PlayerFixture::PLAYER_PRIVATE, array_column($this->search('Jane Smith'), 'key'));

        $byCode = array_values(array_filter($this->search('player2'), static fn (array $row): bool => $row['key'] === PlayerFixture::PLAYER_PRIVATE));
        self::assertCount(1, $byCode);
        self::assertTrue($byCode[0]['hidden']);
        self::assertSame('#PLAYER2', $byCode[0]['label']);
        self::assertNull($byCode[0]['avatar']);
    }

    public function testAShortQueryFindsNobody(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertSame([], $this->search(' M '));
        self::assertResponseIsSuccessful();
    }

    public function testOnlyTheEventsOrganisersSearch(): void
    {
        $this->search('Michael');
        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'sign_in_required'], $this->json());

        // PLAYER_REGULAR does not organise the Results Cup
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->search('Michael');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'forbidden'], $this->json());

        $this->browser->request('GET', '/en/participants-sheet-api/' . Uuid::uuid7()->toString() . '/player-search?query=Michael');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('competition_not_found', $this->json()['error']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function search(string $query): array
    {
        $this->browser->request('GET', self::SEARCH . '?query=' . rawurlencode($query), server: ['HTTP_ACCEPT' => 'application/json']);

        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        if (!array_is_list($decoded)) {
            // An error answer (401, 403, 404) - read it with json()
            return [];
        }

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
