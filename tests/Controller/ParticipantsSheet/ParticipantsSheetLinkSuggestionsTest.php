<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ParticipantsSheet;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ParticipantLinkSuggestionsSeeding;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `participants_sheet_link_suggestions` (docs/features/competitions-management/participant-linking.md §7.2, §8.4, R2,
 * R7, D10): every tier, every exclusion, the signals the pre-tick rule reads, the merge refusals, the organisers only,
 * one statement.
 */
final class ParticipantsSheetLinkSuggestionsTest extends WebTestCase
{
    use ParticipantLinkSuggestionsSeeding;
    use QueryCountAssertions;

    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    public function testEveryTierAndNothingExcluded(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $seeded = $this->seedLinkSuggestions($database);
        TestingLogin::asPlayer($browser, self::ORGANISER);

        $people = $this->suggestions($browser, $seeded['event']);

        // Ansel (a common name), Cosima (private), Dorian (linked to another person of the list) and Elodie (refused)
        // have none; Bertram is not blocked yet
        self::assertSame(['Bertram Hidely', 'Ottilie Brandvold', 'QUIRIN  vexley', "zoe o'brien–novak"], array_column($people, 'name'));

        $zoe = $this->person($people, $seeded['participants']['zoe']);
        self::assertCount(1, $zoe['candidates'], 'the best tier per player - her name and country would be tier 3 too');
        $merge = $zoe['candidates'][0];
        self::assertSame('joined_themselves', $merge['tier']);
        self::assertSame($seeded['players']['zoe'], $merge['player']['id']);
        self::assertTrue($merge['player']['visible']);
        self::assertSame(['participant' => $seeded['participants']['zoeSelf'], 'mergeRefusal' => null], $merge['selfJoined']);
        self::assertIsString($merge['joinedAt']);
        self::assertNull($merge['linkedAt']);

        $quirin = $this->person($people, $seeded['participants']['quirin'])['candidates'];
        self::assertCount(1, $quirin);
        self::assertSame('linked_before', $quirin[0]['tier']);
        self::assertNotNull($quirin[0]['linkedAt']);
        self::assertSame('Lantern Linking 2025', $quirin[0]['linkedAt']['name']);
        // What R2's one pre-tick needs: the only candidate, linked before, the same country
        self::assertTrue($quirin[0]['countryMatches']);
        self::assertNull($quirin[0]['selfJoined']);

        $ottilie = $this->person($people, $seeded['participants']['ottilie'])['candidates'];
        self::assertSame('name_country', $ottilie[0]['tier']);
        self::assertSame('Ottilie Brandvold', $ottilie[0]['player']['name']);
        self::assertFalse($ottilie[0]['follows']);
    }

    public function testAFollowerIsSaidAndAFourthPlayerOfANameEndsItsSuggestions(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $seeded = $this->seedLinkSuggestions($database);
        TestingLogin::asPlayer($browser, self::ORGANISER);

        $database->executeStatement(
            'INSERT INTO followed_competition (id, player_id, series_id, created_at) VALUES (:id, :player, :series, ' . TestClock::SQL_NOW . ')',
            ['id' => Uuid::uuid7()->toString(), 'player' => $seeded['players']['ottilie'], 'series' => $seeded['series']],
        );
        // Three players called Ansel are few enough
        $database->executeStatement('DELETE FROM player WHERE id = :id', ['id' => $seeded['players']['ansel4']]);

        $people = $this->suggestions($browser, $seeded['event']);

        self::assertTrue($this->person($people, $seeded['participants']['ottilie'])['candidates'][0]['follows']);
        self::assertCount(3, $this->person($people, $seeded['participants']['ansel'])['candidates']);
    }

    public function testAPlayerTheOrganiserBlockedIsNotSuggestedButTheSelfJoinedRowStaysMasked(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $seeded = $this->seedLinkSuggestions($database);
        $this->blockPlayer($database, self::ORGANISER, $seeded['players']['bertram']);
        $this->blockPlayer($database, self::ORGANISER, $seeded['players']['zoe']);
        TestingLogin::asPlayer($browser, self::ORGANISER);

        $people = $this->suggestions($browser, $seeded['event']);

        self::assertNotContains('Bertram Hidely', array_column($people, 'name'));
        // Zoë's self-joined row is in the sheet already (D10): the merge stays, her profile is withheld (O9)
        $merge = $this->person($people, $seeded['participants']['zoe'])['candidates'][0];
        self::assertSame('joined_themselves', $merge['tier']);
        self::assertFalse($merge['player']['visible']);
        self::assertNull($merge['player']['name']);
        self::assertNull($merge['player']['code']);
    }

    public function testAMergeIsRefusedWhileTheSelfJoinedRowHoldsSomething(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $seeded = $this->seedLinkSuggestions($database);
        TestingLogin::asPlayer($browser, self::ORGANISER);
        $self = $seeded['participants']['zoeSelf'];
        $refusal = fn (): null|string => $this->person($this->suggestions($browser, $seeded['event']), $seeded['participants']['zoe'])['candidates'][0]['selfJoined']['mergeRefusal'] ?? null;

        // A registration counts only while the event manages registration
        $database->executeStatement("UPDATE competition_participant SET registration_status = 'reserved' WHERE id = :id", ['id' => $self]);
        self::assertNull($refusal());

        $database->executeStatement('UPDATE competition SET registration_managed = true WHERE id = :id', ['id' => $seeded['event']]);
        self::assertSame('registered', $refusal());

        $database->executeStatement("UPDATE competition_participant SET registration_status = 'waitlisted' WHERE id = :id", ['id' => $self]);
        self::assertSame('waitlisted', $refusal());

        $database->executeStatement("UPDATE competition_participant SET registration_status = 'paid' WHERE id = :id", ['id' => $self]);
        self::assertSame('paid', $refusal());

        $entry = Uuid::uuid7()->toString();
        $database->executeStatement(
            'INSERT INTO competition_participant_round (id, participant_id, round_id) VALUES (:id, :participant, :round)',
            ['id' => $entry, 'participant' => $self, 'round' => $seeded['round']],
        );
        self::assertSame('round_entries', $refusal());

        $database->executeStatement('UPDATE competition_participant_round SET result_seconds = 3000 WHERE id = :id', ['id' => $entry]);
        self::assertSame('official_data', $refusal());
    }

    public function testAProfilePrivateToTheOrganiserIsSuggestedOnlyWhenTheyMaySeeIt(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $seeded = $this->seedLinkSuggestions($database);
        $database->executeStatement(
            'INSERT INTO private_profile_viewer (id, owner_id, viewer_id, added_at) VALUES (:id, :owner, :viewer, ' . TestClock::SQL_NOW . ')',
            ['id' => Uuid::uuid7()->toString(), 'owner' => $seeded['players']['cosima'], 'viewer' => self::ORGANISER],
        );
        TestingLogin::asPlayer($browser, self::ORGANISER);

        $cosima = $this->person($this->suggestions($browser, $seeded['event']), $seeded['participants']['cosima']);

        self::assertSame('Cosima Privet', $cosima['candidates'][0]['player']['name']);
    }

    public function testOnlyTheOrganisersAndPrivateNoStore(): void
    {
        $browser = self::createClient();
        $seeded = $this->seedLinkSuggestions(self::getContainer()->get(Connection::class));
        $url = '/en/participants-sheet-api/' . $seeded['event'] . '/link-suggestions';

        $browser->request('GET', $url);
        self::assertResponseStatusCodeSame(401);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        TestingLogin::asPlayer($browser, self::ORGANISER);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);

        $browser->request('GET', '/en/participants-sheet-api/' . Uuid::uuid7()->toString() . '/link-suggestions');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * One statement for the suggestions: the endpoint costs what the sheet's version endpoint costs (sign-in, the event,
     * the permissions, then its one statement) - and a bigger list costs the same.
     */
    public function testTheSuggestionsAreOneStatement(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $seeded = $this->seedLinkSuggestions($database);
        TestingLogin::asPlayer($browser, self::ORGANISER);

        $version = $this->statementsOf($browser, '/en/participants-sheet-api/' . $seeded['event'] . '/version');
        $small = $this->statementsOf($browser, '/en/participants-sheet-api/' . $seeded['event'] . '/link-suggestions');

        $database->executeStatement(
            "INSERT INTO competition_participant (id, name, country, competition_id, source)
             SELECT gen_uuid_v7(), 'Invented Person ' || n, 'cz', :event, 'imported' FROM generate_series(1, 300) n",
            ['event' => $seeded['event']],
        );
        $large = $this->statementsOf($browser, '/en/participants-sheet-api/' . $seeded['event'] . '/link-suggestions');

        self::assertSame($version, $small, sprintf('The suggestions ran %d statements: %s', $small, implode("\n", $this->executedSql($browser))));
        self::assertSame($small, $large);
    }

    private function statementsOf(KernelBrowser $browser, string $url): int
    {
        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function suggestions(KernelBrowser $browser, string $event): array
    {
        $browser->request('GET', '/en/participants-sheet-api/' . $event . '/link-suggestions');
        self::assertResponseIsSuccessful();
        $answer = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        self::assertIsArray($answer['people']);

        /** @var list<array<string, mixed>> $people */
        $people = $answer['people'];

        return $people;
    }

    /**
     * @param list<array<string, mixed>> $people
     * @return array{participant: string, name: string, country: null|string, candidates: list<array{tier: string, player: array{id: string, visible: bool, name: null|string, code: null|string}, countryMatches: bool, follows: bool, joinedAt: null|string, linkedAt: null|array{name: string, date: null|string}, selfJoined: null|array{participant: string, mergeRefusal: null|string}}>}
     */
    private function person(array $people, string $participantId): array
    {
        foreach ($people as $person) {
            if ($person['participant'] === $participantId) {
                /** @var array{participant: string, name: string, country: null|string, candidates: list<array{tier: string, player: array{id: string, visible: bool, name: null|string, code: null|string}, countryMatches: bool, follows: bool, joinedAt: null|string, linkedAt: null|array{name: string, date: null|string}, selfJoined: null|array{participant: string, mergeRefusal: null|string}}>} $person */
                return $person;
            }
        }

        self::fail('No suggestion for ' . $participantId);
    }
}
