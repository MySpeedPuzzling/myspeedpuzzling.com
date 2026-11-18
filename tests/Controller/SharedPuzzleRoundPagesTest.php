<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddUnfinishedPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Package S3 of docs/features/competitions-management/shared-puzzles-and-divisions.md on the pages (§2.4 rows 4, 15,
 * 16): "Add to my profile" files the time in the entry's round, the round page offers "This is my time - link it" for
 * the viewer's time no round holds, and the result detail lets its player choose the round.
 *
 * Results Cup (OfficialResultsFixture): Group A (solo, PUZZLE_1000_05, published) - Anna (PLAYER_ADMIN) won it in
 * 1:00:00. Here Group B (solo) gets the same puzzle, as if the organiser shared it (inserted directly until S2).
 */
final class SharedPuzzleRoundPagesTest extends WebTestCase
{
    public function testAddToMyProfileFilesTheTimeInTheEntrysRound(): void
    {
        $browser = self::createClient();
        $this->shareGroupAsPuzzleWithGroupB();
        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);
        // Anna is in Group B too, with the same time - the evidence alone could not tell the rounds apart
        $entryId = $this->addEntry(OfficialResultsFixture::PARTICIPANT_ANNA, OfficialResultsFixture::ROUND_GROUP_B, 3600);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', sprintf(
            '/en/puzzle-add/%s?competition=%s&official_entry=participant_round:%s',
            PuzzleFixture::PUZZLE_1000_05,
            OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            $entryId,
        ));
        self::assertResponseIsSuccessful();
        $browser->submit($crawler->filter('form[name="puzzle_add_form"]')->form());

        self::assertResponseRedirects();
        self::assertSame(
            ['competition_round_id' => OfficialResultsFixture::ROUND_GROUP_B, 'round_chosen' => true],
            self::getContainer()->get(Connection::class)->fetchAssociative(
                'SELECT competition_round_id, round_chosen FROM puzzle_solving_time WHERE player_id = :player AND puzzle_id = :puzzle',
                ['player' => PlayerFixture::PLAYER_ADMIN, 'puzzle' => PuzzleFixture::PUZZLE_1000_05],
            ),
        );

        // Group B says it is on the profile; Group A still offers its own
        $browser->request('GET', '/en/events/results-cup/results/group-b');
        $this->assertSelectorExists('[data-official-on-profile]');
        $browser->request('GET', '/en/events/results-cup/results/group-a');
        $this->assertSelectorExists('[data-official-add-to-profile]');
    }

    public function testTheRoundPageLinksTheViewersUnlinkedTimeInsteadOfAddingItAgain(): void
    {
        $browser = self::createClient();
        $this->shareGroupAsPuzzleWithGroupB();
        $timeId = $this->unlinkedTimeOfAnna();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', '/en/events/results-cup/results/group-a');

        self::assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-official-add-to-profile]');
        $this->assertSelectorTextContains('[data-official-link-to-round]', 'This is my time - link it to Group A');

        $browser->submit($crawler->filter('[data-official-link-to-round]')->form());

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/en/events/results-cup/results/group-a', $browser->getResponse()->headers->get('Location'));
        self::assertSame(
            ['competition_round_id' => OfficialResultsFixture::ROUND_GROUP_A, 'round_chosen' => true],
            $this->stored($timeId),
        );

        $browser->followRedirect();
        $this->assertSelectorExists('[data-official-on-profile]');
        $this->assertSelectorNotExists('[data-official-link-to-round]');
    }

    public function testGuestsAndOtherPlayersSeeNoLink(): void
    {
        $browser = self::createClient();
        $this->shareGroupAsPuzzleWithGroupB();
        $this->unlinkedTimeOfAnna();

        $browser->request('GET', '/en/events/results-cup/results/group-a');
        $this->assertSelectorNotExists('[data-official-link-to-round]');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/events/results-cup/results/group-a');
        $this->assertSelectorNotExists('[data-official-link-to-round]');
    }

    public function testTheResultDetailOffersTheSharingRoundsToItsPlayerOnly(): void
    {
        $browser = self::createClient();
        $this->shareGroupAsPuzzleWithGroupB();
        $timeId = $this->unlinkedTimeOfAnna();

        // A guest: nothing to choose
        $crawler = $browser->request('GET', '/en/result/' . $timeId, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-round-choice]'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', '/en/result/' . $timeId, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('[data-round-choice]');
        self::assertCount(1, $form);
        // A form inside the modal frame names its action (CLAUDE.md, Turbo)
        self::assertSame('/en/result/' . $timeId . '/round', $form->attr('action'));
        self::assertSame(['Group A', 'Group B'], $form->filter('option')->each(static fn (Crawler $option): string => trim($option->text())));

        $values = $form->form()->getPhpValues();
        $values['round_id'] = OfficialResultsFixture::ROUND_GROUP_B;
        $browser->request('POST', '/en/result/' . $timeId . '/round', $values, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/en/result/' . $timeId, $browser->getResponse()->headers->get('Location'));
        self::assertSame(
            ['competition_round_id' => OfficialResultsFixture::ROUND_GROUP_B, 'round_chosen' => true],
            $this->stored($timeId),
        );

        // Chosen: nothing more to choose
        $crawler = $browser->request('GET', '/en/result/' . $timeId, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        self::assertCount(0, $crawler->filter('[data-round-choice]'));
    }

    public function testAForgedTokenChangesNothing(): void
    {
        $browser = self::createClient();
        $this->shareGroupAsPuzzleWithGroupB();
        $timeId = $this->unlinkedTimeOfAnna();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('POST', '/en/result/' . $timeId . '/round', ['round_id' => OfficialResultsFixture::ROUND_GROUP_A, '_token' => 'forged']);

        self::assertResponseStatusCodeSame(303);
        self::assertSame(['competition_round_id' => null, 'round_chosen' => false], $this->stored($timeId));
    }

    public function testAnUnlinkedTimeWithAnotherResultLeavesAddToMyProfile(): void
    {
        $browser = self::createClient();
        $this->shareGroupAsPuzzleWithGroupB();
        // Not Group A's 1:00:00 - maybe Group B's solve: never linked to Group A from here
        $this->unlinkedTimeOfAnna(seconds: 3500);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', '/en/events/results-cup/results/group-a');

        self::assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-official-link-to-round]');
        $this->assertSelectorExists('[data-official-add-to-profile]');
    }

    public function testAnUnfinishedResultAddedToItsRoundCanBeEditedWhenTheSharingRoundsHaveOtherLimits(): void
    {
        $browser = self::createClient();
        $this->shareGroupAsPuzzleWithGroupB();
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement('UPDATE competition_round SET minutes_limit = 60 WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_B]);

        // What Add to my profile of an unfinished Group A entry saves
        $timeId = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddUnfinishedPuzzleSolvingTime(
            timeId: $timeId,
            userId: 'auth0|admin003',
            puzzleId: PuzzleFixture::PUZZLE_1000_05,
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            piecesPlaced: 500,
            totalTime: null,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: new DateTimeImmutable('2026-10-04'),
            firstAttempt: false,
            unboxed: false,
            roundId: OfficialResultsFixture::ROUND_GROUP_A,
            createdVia: SolvingTimeSource::Form,
        ));
        self::assertSame(['competition_round_id' => OfficialResultsFixture::ROUND_GROUP_A, 'round_chosen' => true], $this->stored($timeId->toString()));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', '/en/edit-time/' . $timeId->toString());
        self::assertResponseIsSuccessful();
        // The question names the result's own round, also to the hint endpoint
        self::assertSame(OfficialResultsFixture::ROUND_GROUP_A, $crawler->filter('form[name="edit_puzzle_solving_time_form"]')->attr('data-unfinished-result-named-round-value'));

        $values = $crawler->filter('form[name="edit_puzzle_solving_time_form"]')->form()->getPhpValues();
        /** @var array<string, mixed> $fields */
        $fields = $values['edit_puzzle_solving_time_form'];
        $values['edit_puzzle_solving_time_form'] = [...$fields, 'comment' => 'Ran out of time'];
        $browser->request('POST', '/en/edit-time/' . $timeId->toString(), $values);

        self::assertResponseRedirects();
        self::assertSame('Ran out of time', $database->fetchOne('SELECT comment FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId->toString()]));
        self::assertSame(['competition_round_id' => OfficialResultsFixture::ROUND_GROUP_A, 'round_chosen' => true], $this->stored($timeId->toString()));
    }

    public function testTheHintEndpointKeepsTheNamedRound(): void
    {
        $browser = self::createClient();
        $this->shareGroupAsPuzzleWithGroupB();
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE competition_round SET minutes_limit = 60 WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_B]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $query = ['competition' => OfficialResultsFixture::COMPETITION_RESULTS_CUP, 'puzzle' => PuzzleFixture::PUZZLE_1000_05, 'puzzlers' => '1', 'date' => '05.10.2026'];

        // Two limits, nothing named: no question
        self::assertSame(['round' => null], $this->hint($browser, $query));

        self::assertSame(
            ['round' => ['name' => 'Group A', 'minutesLimit' => 90, 'category' => 'solo']],
            $this->hint($browser, [...$query, 'round' => OfficialResultsFixture::ROUND_GROUP_A]),
        );
    }

    /**
     * @param array<string, string> $query
     */
    private function hint(KernelBrowser $browser, array $query): mixed
    {
        $browser->request('GET', '/en/solving-time-round-hint?' . http_build_query($query));
        self::assertResponseIsSuccessful();

        return json_decode((string) $browser->getResponse()->getContent(), true);
    }

    public function testARefusedChoiceInsideTheModalIsSaidNextToThePicker(): void
    {
        $browser = self::createClient();
        $this->shareGroupAsPuzzleWithGroupB();
        $timeId = $this->unlinkedTimeOfAnna();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', '/en/result/' . $timeId, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        $values = $crawler->filter('[data-round-choice]')->form()->getPhpValues();
        // The pairs round: no candidate for a solo time
        $values['round_id'] = OfficialResultsFixture::ROUND_PAIRS;
        $browser->request('POST', '/en/result/' . $timeId . '/round', $values, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);

        self::assertResponseStatusCodeSame(303);
        self::assertSame('/en/result/' . $timeId . '?round_choice=not_a_candidate', $browser->getResponse()->headers->get('Location'));
        self::assertSame(['competition_round_id' => null, 'round_chosen' => false], $this->stored($timeId));

        $crawler = $browser->request('GET', '/en/result/' . $timeId . '?round_choice=not_a_candidate', server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        self::assertStringContainsString("can't be placed in that round", $crawler->filter('[data-round-choice-refused]')->text());
    }

    private function shareGroupAsPuzzleWithGroupB(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_round_puzzle SET puzzle_id = :puzzle WHERE round_id = :round',
            ['puzzle' => PuzzleFixture::PUZZLE_1000_05, 'round' => OfficialResultsFixture::ROUND_GROUP_B],
        );
    }

    private function publish(string $roundId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ', results_first_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
            ['id' => $roundId],
        );
    }

    private function addEntry(string $participantId, string $roundId, int $seconds): string
    {
        $entryId = Uuid::uuid7()->toString();
        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO competition_participant_round (id, participant_id, round_id, result_seconds, result_did_not_start) VALUES (:id, :participant, :round, :seconds, false)',
            ['id' => $entryId, 'participant' => $participantId, 'round' => $roundId, 'seconds' => $seconds],
        );

        return $entryId;
    }

    /**
     * Anna's own solo time on the shared puzzle, linked to the event in no round (the rule could not tell)
     */
    private function unlinkedTimeOfAnna(int $seconds = 3600): string
    {
        $timeId = Uuid::uuid7()->toString();
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO puzzle_solving_time (id, seconds_to_solve, player_id, puzzle_id, tracked_at, verified, finished_at, competition_id, competition_round_id, puzzling_type, puzzlers_count, created_via)
             VALUES (:id, :seconds, :player, :puzzle, TIMESTAMP '2026-10-10 12:00:00', true, TIMESTAMP '2026-10-04 00:00:00', :competition, NULL, 'solo', 1, 'form')",
            [
                'id' => $timeId,
                'seconds' => $seconds,
                'player' => PlayerFixture::PLAYER_ADMIN,
                'puzzle' => PuzzleFixture::PUZZLE_1000_05,
                'competition' => OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            ],
        );

        return $timeId;
    }

    /**
     * @return array{competition_round_id: null|string, round_chosen: bool}
     */
    private function stored(string $timeId): array
    {
        /** @var array{competition_round_id: null|string, round_chosen: bool} $row */
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT competition_round_id, round_chosen FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );

        return $row;
    }
}
