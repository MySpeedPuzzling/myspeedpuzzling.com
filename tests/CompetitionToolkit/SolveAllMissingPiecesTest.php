<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordRoundResults;
use SpeedPuzzling\Web\Results\RecordedRoundResults;
use SpeedPuzzling\Web\Results\RoundResultChangeOutcome;
use SpeedPuzzling\Web\Services\RoundResultChangesParser;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * "Solve all 3" end to end (reliability hardening T1, Jan: "they can start 2->3->1 ... if they do not finish in time we
 * know which puzzle did not finish in time and how many pieces were left"): Group B as `pick` K = N = 3 - SECRET (A,
 * 1500 pieces, hidden), PUBLIC (B, Puzzle 14, 2000) and the fixture's Puzzle 17 (C, 5000).
 *
 * - the solving order is the record's order, the puzzle not finished carries its pieces;
 * - "abandoned one, started another" = two puzzles not finished - the write path, the desk, the export and the public
 *   chips take it;
 * - the round form offers "Solve all 3" without typing K = 3, and reads K = N back as that choice.
 */
final class SolveAllMissingPiecesTest extends WebTestCase
{
    use RoundPuzzleFormatSetup;

    private const string IVAN = 'participant_round:' . OfficialResultsFixture::ENTRY_B_IVAN;

    private KernelBrowser $browser;
    private Connection $database;
    private string $big;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
        $this->big = self::addRoundPuzzles($this->database);
        self::makePick($this->database, 3);
    }

    public function testTheOrderTwoThreeOneIsRecordedAndTheLastPuzzleCarriesWhatWasPlaced(): void
    {
        // B → C finished, time ran out on A with 37 of its 1500 missing
        $puzzles = [self::done(self::PUBLIC, 1800), self::done($this->big, 4900), ['roundPuzzleId' => self::SECRET, 'done' => false, 'piecesPlaced' => 1463]];

        $recorded = $this->record([
            self::change('result', ['seconds' => 5000], ['piecesPlaced' => 2000 + 5000 + 1463]),
            self::change('puzzles', null, $puzzles),
        ]);

        self::assertSame(['applied', 'applied'], self::statuses($recorded));
        self::assertSame($puzzles, $this->stored());
        self::assertSame(8463, $this->database->fetchOne('SELECT result_pieces_placed FROM competition_participant_round WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_B_IVAN]));

        // The same record in another order is another record: the order is data
        $reordered = $this->record([self::change('puzzles', $puzzles, [self::done($this->big, 1800), self::done(self::PUBLIC, 4900), $puzzles[2]])]);
        self::assertSame(['applied'], self::statuses($reordered));
        self::assertSame($this->big, $this->stored()[0]['roundPuzzleId'] ?? null);
    }

    public function testTwoPuzzlesNotFinishedAreTakenEverywhere(): void
    {
        // B finished, C abandoned with 400 placed, then A started: 1000 placed when the time ran out
        $puzzles = [self::done(self::PUBLIC, 2100), ['roundPuzzleId' => $this->big, 'done' => false, 'piecesPlaced' => 400], ['roundPuzzleId' => self::SECRET, 'done' => false, 'piecesPlaced' => 1000]];

        // Pieces that are not the sum: refused together
        $wrong = $this->record([self::change('result', ['seconds' => 5000], ['piecesPlaced' => 2400]), self::change('puzzles', null, $puzzles)]);
        self::assertSame(['puzzles_mismatch', 'puzzles_mismatch'], array_map(static fn (RoundResultChangeOutcome $outcome): null|string => $outcome->reason, $wrong->outcomes));

        $recorded = $this->record([self::change('result', ['seconds' => 5000], ['piecesPlaced' => 3400]), self::change('puzzles', null, $puzzles)]);
        self::assertSame(['applied', 'applied'], self::statuses($recorded));
        self::assertSame($puzzles, $this->stored());

        // An abandoned puzzle with nothing placed is fine too (no pieces on the item)
        $none = [$puzzles[0], ['roundPuzzleId' => $this->big, 'done' => false], $puzzles[2]];
        $recorded = $this->record([self::change('result', ['piecesPlaced' => 3400], ['piecesPlaced' => 3000]), self::change('puzzles', $puzzles, $none)]);
        self::assertSame(['applied', 'applied'], self::statuses($recorded));
        $this->record([self::change('result', ['piecesPlaced' => 3000], ['piecesPlaced' => 3400]), self::change('puzzles', $none, $puzzles)]);

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // The desk: the record as stored, both not finished
        $crawler = $this->browser->request('GET', '/en/manage-round-results/' . self::ROUND);
        self::assertResponseIsSuccessful();
        $desk = $crawler->filter('[data-controller="results-desk"]');
        /** @var array{entries: list<array{ref: string, puzzles?: mixed}>} $state */
        $state = json_decode((string) $desk->attr('data-results-desk-state-value'), true, flags: JSON_THROW_ON_ERROR);
        $ivan = array_values(array_filter($state['entries'], static fn (array $entry): bool => $entry['ref'] === self::IVAN))[0] ?? null;
        self::assertIsArray($ivan);
        self::assertSame($puzzles, $ivan['puzzles'] ?? null);
        // The missing-pieces mode's texts are there
        $texts = json_decode((string) $desk->attr('data-results-desk-texts-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);
        self::assertSame('missing %missing% → placed %placed% of %pieces%', $texts['preview_missing'] ?? null);

        // The export: three puzzles, the two not finished with their pieces
        $this->browser->request('GET', '/en/manage-round-results/' . self::ROUND . '/export/csv');
        self::assertResponseIsSuccessful();
        $rows = self::csvRows((string) $this->browser->getInternalResponse()->getContent());
        self::assertSame('Puzzle 3 pieces placed', $rows[0][22] ?? null);
        $row = array_values(array_filter($rows, static fn (array $one): bool => $one[2] === 'Ivan Last'))[0] ?? null;
        self::assertIsArray($row);
        self::assertSame('3400', $row[7]);
        self::assertSame('', $row[14], 'Puzzle 1 finished - no pieces');
        self::assertSame(['', '', '400'], array_slice($row, 16, 3), 'Puzzle 2 not finished: no time, its pieces');
        self::assertSame(['', '', '1000'], array_slice($row, 20, 3));
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $this->browser->getInternalResponse()->getContent());

        // The public round page, published: one chip per puzzle in solving order, the hidden one by its label
        $this->database->executeStatement(
            'UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ', results_first_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
            ['id' => self::ROUND],
        );
        $this->browser->restart();
        $crawler = $this->browser->request('GET', '/en/events/results-cup/results/group-b');
        self::assertResponseIsSuccessful();
        $chips = $crawler->filter('[data-official-entry="' . self::IVAN . '"] [data-official-entry-puzzle]')->each(
            static fn (Crawler $chip): string => trim((string) preg_replace('/\s+/', ' ', $chip->text())),
        );
        self::assertSame(['B ' . self::PUBLIC_NAME . ' 00:35:00', 'C Puzzle 17 400 pcs', 'A 1000 pcs'], $chips);
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $this->browser->getResponse()->getContent());
    }

    public function testTheRoundFormOffersSolveAllWithoutTypingK(): void
    {
        $this->database->executeStatement("UPDATE competition_round SET puzzle_format = 'total', puzzles_to_solve = NULL WHERE id = :round", ['round' => self::ROUND]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', '/en/edit-event-round/' . self::ROUND);
        self::assertResponseIsSuccessful();
        $choices = $crawler->filter('[data-round-puzzle-format] input[name="competition_round_form[puzzleFormat]"]');
        self::assertSame(['total', 'all', 'pick'], $choices->each(static fn (Crawler $input): string => (string) $input->attr('value')));
        self::assertStringContainsString('Solve all 3 - the order each entrant solves them is recorded', $crawler->filter('[data-round-puzzle-format]')->text());

        $form = $crawler->filter('form[name="competition_round_form"]')->form();
        $form->setValues(['competition_round_form[puzzleFormat]' => 'all', 'competition_round_form[puzzlesToSolve]' => '']);
        $this->browser->submit($form);

        self::assertResponseRedirects();
        self::assertSame(['pick', 3], [
            $this->database->fetchOne('SELECT puzzle_format FROM competition_round WHERE id = :id', ['id' => self::ROUND]),
            $this->database->fetchOne('SELECT puzzles_to_solve FROM competition_round WHERE id = :id', ['id' => self::ROUND]),
        ]);

        // Read back as "Solve all", also a K = N typed under "Pick"
        $crawler = $this->browser->request('GET', '/en/edit-event-round/' . self::ROUND);
        self::assertSame('all', $crawler->filter('input[name="competition_round_form[puzzleFormat]"]:checked')->attr('value'));

        // The hub card and the round puzzles page name it
        $crawler = $this->browser->request('GET', '/en/manage-event-toolkit/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);
        self::assertStringContainsString('Solve all 3 (order recorded)', $crawler->filter('[data-toolkit-round="' . self::ROUND . '"] [data-toolkit-round-format]')->text());

        // "Pick" with K < N stays a pick
        $crawler = $this->browser->request('GET', '/en/edit-event-round/' . self::ROUND);
        $form = $crawler->filter('form[name="competition_round_form"]')->form();
        $form->setValues(['competition_round_form[puzzleFormat]' => 'pick', 'competition_round_form[puzzlesToSolve]' => '2']);
        $this->browser->submit($form);
        self::assertResponseRedirects();
        self::assertSame(2, $this->database->fetchOne('SELECT puzzles_to_solve FROM competition_round WHERE id = :id', ['id' => self::ROUND]));

        // And back to one result for the round
        $crawler = $this->browser->request('GET', '/en/edit-event-round/' . self::ROUND);
        $form = $crawler->filter('form[name="competition_round_form"]')->form();
        $form->setValues(['competition_round_form[puzzleFormat]' => 'total']);
        $this->browser->submit($form);
        self::assertResponseRedirects();
        self::assertSame('total', $this->database->fetchOne('SELECT puzzle_format FROM competition_round WHERE id = :id', ['id' => self::ROUND]));
    }

    /**
     * @param list<array<string, mixed>> $changes wire format
     */
    private function record(array $changes): RecordedRoundResults
    {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch(new RecordRoundResults(
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            roundId: self::ROUND,
            actingPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            changes: RoundResultChangesParser::parse($changes),
            resultsOnly: false,
        ));

        $recorded = $envelope->last(HandledStamp::class)?->getResult();
        assert($recorded instanceof RecordedRoundResults);
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();

        return $recorded;
    }

    /**
     * @return null|list<array<string, mixed>>
     */
    private function stored(): null|array
    {
        $stored = $this->database->fetchOne('SELECT result_puzzles FROM competition_participant_round WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_B_IVAN]);

        return is_string($stored) ? \SpeedPuzzling\Web\Value\RoundEntryPuzzles::fromStored($stored)?->toWire() : null;
    }

    /**
     * @return array{roundPuzzleId: string, done: true, atSeconds: int}
     */
    private static function done(string $roundPuzzleId, int $atSeconds): array
    {
        return ['roundPuzzleId' => $roundPuzzleId, 'done' => true, 'atSeconds' => $atSeconds];
    }

    /**
     * @return array<string, mixed>
     */
    private static function change(string $field, mixed $from, mixed $to): array
    {
        return ['clientChangeId' => Uuid::uuid7()->toString(), 'entry' => self::IVAN, 'field' => $field, 'from' => $from, 'to' => $to];
    }

    /**
     * @return list<string>
     */
    private static function statuses(RecordedRoundResults $recorded): array
    {
        return array_map(static fn (RoundResultChangeOutcome $outcome): string => $outcome->status->value, $recorded->outcomes);
    }

    /**
     * @return list<list<string>>
     */
    private static function csvRows(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        self::assertIsString($content);

        $rows = [];
        foreach (explode("\n", trim($content)) as $line) {
            $rows[] = str_getcsv($line, ',', '"', '');
        }

        /** @var list<list<string>> $rows */
        return $rows;
    }
}
