<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ParticipantsSheet;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The participants sheet's read-only "Personal link" column (docs/features/online-competitions/README.md §5.4,
 * stream C1): while a round of the event uses online timing every person in the state carries an `online` record (the
 * link, or null), and no other event's state changes - not even by a key.
 */
final class ParticipantsSheetPersonalLinkTest extends WebTestCase
{
    private const string COMPETITION = OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
    }

    public function testEverybodyOfAnEventWithOnlineTimingComesWithTheirLink(): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO competition_participant (id, name, competition_id, source, deleted_at, online_token, online_token_created_at)
             VALUES ('018d0050-0000-0000-0000-0000000002e1', 'Rita Removed', :competition, 'imported', NOW(), 'RemovedTestCupToken234', NOW())",
            ['competition' => self::COMPETITION],
        );

        $people = $this->people(self::COMPETITION);

        self::assertSame(['link' => 'http://localhost/en/compete/' . OnlineRoundsFixture::TOKEN_CONNECTED], $people[OnlineRoundsFixture::PARTICIPANT_CONNECTED]['online'] ?? null);
        self::assertSame(['link' => 'http://localhost/en/compete/' . OnlineRoundsFixture::TOKEN_ANONYMOUS], $people[OnlineRoundsFixture::PARTICIPANT_ANONYMOUS]['online'] ?? null);
        // Going without a link yet - the personal links page makes it
        self::assertSame(['link' => null], $people[OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN]['online'] ?? 'missing');
        // A removed person's link opens nothing any more - not shown
        self::assertSame(['link' => null], $people['018d0050-0000-0000-0000-0000000002e1']['online'] ?? 'missing');
    }

    public function testNoOtherEventsStateChanges(): void
    {
        foreach ($this->people(OfficialResultsFixture::COMPETITION_RESULTS_CUP) as $person) {
            self::assertArrayNotHasKey('online', $person);
        }

        // An online event whose rounds do not use online timing: its people's links are not shown either
        $this->connection()->executeStatement('DELETE FROM online_round WHERE id = :id', ['id' => OnlineRoundsFixture::ONLINE_ROUND_SOLO]);

        foreach ($this->people(self::COMPETITION) as $person) {
            self::assertArrayNotHasKey('online', $person);
        }
    }

    /**
     * @return array<string, array<string, mixed>> participant id => the state's person
     */
    private function people(string $competitionId): array
    {
        $this->browser->request('GET', '/en/participants-sheet-api/' . $competitionId . '/state');
        self::assertResponseIsSuccessful();

        $state = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertIsArray($state['people'] ?? null);

        $people = [];

        foreach ($state['people'] as $person) {
            self::assertIsArray($person);
            /** @var array<string, mixed> $person */
            $id = $person['id'];
            self::assertIsString($id);
            $people[$id] = $person;
        }

        return $people;
    }

    private function connection(): Connection
    {
        $connection = $this->browser->getContainer()->get(Connection::class);

        return $connection;
    }
}
