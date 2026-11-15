<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SuspiciousTimeCase;
use SpeedPuzzling\Web\Message\DecideImportTimeCases;
use SpeedPuzzling\Web\Message\NotifySuspiciousTimes;
use SpeedPuzzling\Web\Query\GetResultReviewEmails;
use SpeedPuzzling\Web\Services\DuplicateResults\ResultReviewEmailComposer;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspicionFingerprint;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeClassifier;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\MessageHandler\DataImport\ImportAddingScenario;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DataImportUndoScope;
use SpeedPuzzling\Web\Value\ExpectedTimeSource;
use SpeedPuzzling\Web\Value\SuspicionAssessment;
use SpeedPuzzling\Web\Value\SuspicionCheckOutcome;
use SpeedPuzzling\Web\Value\SuspicionDirection;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;
use SpeedPuzzling\Web\Value\SuspiciousTimeTier;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * docs/features/suspicious-time-review.md "Import cards": the pending cases on results that one "Add" of a player's
 * spreadsheet import made are ONE card of the time verification queue - shown with what a moderator needs to judge a
 * history import, decided together (each case through the single card's own message, so the decision log keeps its
 * shape), or opened one by one. The player is told once for the group. Cases of results an import took back are
 * gone with the results. A typed result's case is never in an import card. Every puzzle here is made up.
 */
final class TimeVerificationImportCardsTest extends WebTestCase
{
    use ImportAddingScenario;

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAnImportsCasesAreOneCardWithWhatAModeratorNeedsAndATypedResultsCaseIsNeverInIt(): void
    {
        $scenario = $this->importWithRaisedResults('cardone', importedSeconds: [3050, 3150, 3250, 3350], raised: 3);
        // A typed result of the same player is raised too: its case is a case like any other
        $typedCase = $this->raise($scenario['typed'][0]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', '/admin/time-verification');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('uspicious', (string) $this->browser->getResponse()->getContent());

        $card = $crawler->filter('[data-testid="time-verification-import-card"][data-batch-id="' . $scenario['batch'] . '"]');
        self::assertCount(1, $card);
        self::assertSame('3', $card->attr('data-cases'));
        self::assertStringContainsString('3 results of one spreadsheet import by', self::spaced($card->filter('h3')->text()));
        // The player: how long a member, how many results - typed and from spreadsheets
        $player = self::spaced($card->filter('[data-testid="time-verification-import-player"]')->text());
        self::assertStringContainsString('Member since', $player);
        self::assertStringContainsString('6 results on the profile: 2 typed or timed, 4 from spreadsheets', $player);
        // The import: the file's kind and what this adding put on the profile - never the file's name
        $file = self::spaced($card->filter('[data-testid="time-verification-import-file"]')->text());
        self::assertStringContainsString('a xlsx file · this adding put 4 results on the profile (4 rows)', $file);
        self::assertStringNotContainsString('results.xlsx', $card->text());
        // How far off, and examples with puzzle, time and what was expected
        self::assertStringContainsString('3 of them are much faster than expected (8.3-8.9× faster).', self::spaced($card->filter('[data-testid="time-verification-import-distribution"]')->text()));
        $examples = $card->filter('[data-testid="time-verification-import-example"]');
        self::assertCount(3, $examples);
        self::assertStringContainsString('Zq Cardone Puzzle', $examples->first()->text());
        self::assertStringContainsString('07:30:00', $examples->first()->text());
        // The player's typed results against the imported ones, on the same piece count: these fit
        self::assertSame('consistent', $card->attr('data-comparison'));
        self::assertStringContainsString('Consistent', $card->filter('[data-testid="time-verification-import-comparison"]')->text());
        $paces = self::spaced($card->filter('[data-testid="time-verification-import-paces"] tbody tr')->first()->text());
        self::assertMatchesRegularExpression('/^500 9\.\d \(4\) 9\.\d \(2\) [01]\.\d\d×$/', $paces);

        // The cases of the card are in the card - not cards of the list; the typed result's case is one of the list
        $listed = $crawler->filter('[data-testid="time-verification-case"]')->each(static fn (Crawler $case): string => (string) $case->attr('id'));
        self::assertContains('case-' . $typedCase, $listed);

        foreach ($scenario['cases'] as $caseId) {
            self::assertNotContains('case-' . $caseId, $listed);
        }

        self::assertCount(0, $card->filter('#case-' . $typedCase));

        // "Open them one by one": the import's cases as the single cases they are - and only they
        $open = $card->filter('[data-testid="time-verification-import-open"]')->attr('href');
        self::assertSame('/admin/time-verification?tab=fast&import=' . $scenario['batch'], $open);
        $crawler = $this->browser->request('GET', (string) $open);
        $listed = $crawler->filter('[data-testid="time-verification-case"]')->each(static fn (Crawler $case): string => (string) $case->attr('id'));
        sort($listed);
        $expected = array_map(static fn (string $caseId): string => 'case-' . $caseId, $scenario['cases']);
        sort($expected);
        self::assertSame($expected, $listed);
        self::assertCount(0, $crawler->filter('[data-testid="time-verification-import-card"]'));
        self::assertCount(1, $crawler->filter('[data-testid="time-verification-only-import"]'));

        // A single case decided in this view comes back to this view - not to the whole queue
        $form = $crawler->filter('form[action$="/' . $scenario['cases'][0] . '/trust"]')->first();
        $fields = self::hidden($form);
        self::assertSame($scenario['batch'], $fields['import'] ?? null);
        $this->browser->request('POST', '/admin/time-verification/' . $scenario['cases'][0] . '/trust', $fields);
        self::assertResponseRedirects('/admin/time-verification?tab=fast&import=' . $scenario['batch']);
        self::assertCount(2, $this->browser->followRedirect()->filter('[data-testid="time-verification-case"]'));
    }

    public function testLooksFineForAllTrustsEveryCaseAsTheSingleDecisionDoes(): void
    {
        $scenario = $this->importWithRaisedResults('cardfine', importedSeconds: [3050, 3150, 3250, 3350, 3450], raised: 4);
        $typedCase = $this->raise($scenario['typed'][0]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $card = $this->browser->request('GET', '/admin/time-verification')->filter('[data-testid="time-verification-import-card"][data-batch-id="' . $scenario['batch'] . '"]');
        $form = $card->filter('form[action$="/trust"]');
        self::assertSame('Looks fine - all 4', self::spaced($form->filter('[data-testid="time-verification-import-trust"]')->text()));
        // The form carries what the card showed: a mark per case and the entry it was raised for
        self::assertCount(4, explode(' ', (string) $form->filter('input[name="seen"]')->attr('value')));
        $fields = [];

        $fields = [...$fields, ...self::hidden($form)];

        // Without the token: refused
        $this->browser->request('POST', '/admin/time-verification/imports/' . $scenario['batch'] . '/trust', [...$fields, '_token' => 'nope']);
        self::assertResponseStatusCodeSame(403);

        // While the moderator reads the card: the scan raises one more result of the import, the time of another is
        // changed (its case is about the old entry now), and a third is marked by somebody else
        $raisedSince = $this->raise($scenario['times'][4]);
        $this->database->executeStatement("UPDATE suspicious_time_case SET fingerprint = 'another-entry' WHERE id = :id", ['id' => $scenario['cases'][0]]);
        $this->database->executeStatement("UPDATE suspicious_time_case SET status = 'marked' WHERE id = :id", ['id' => $scenario['cases'][1]]);

        $this->browser->request('POST', '/admin/time-verification/imports/' . $scenario['batch'] . '/trust', $fields);
        self::assertResponseRedirects('/admin/time-verification?tab=fast');
        $crawler = $this->browser->followRedirect();
        // Only what the card showed and is still as it was shown is decided; the rest is said, not decided unseen
        self::assertStringContainsString('Verified fine: 2 results. 2 changed meanwhile and were left as they are. More of this import are left - the card is still here.', self::spaced($crawler->text()));
        self::assertSame(
            ['pending', 'marked', 'trusted', 'trusted', 'pending'],
            array_map(fn (string $caseId): mixed => $this->database->fetchOne('SELECT status FROM suspicious_time_case WHERE id = :id', ['id' => $caseId]), [...$scenario['cases'], $raisedSince]),
        );
        self::assertSame('2', $crawler->filter('[data-testid="time-verification-import-card"][data-batch-id="' . $scenario['batch'] . '"]')->attr('data-cases'));

        // Each case decided on its own, as "Looks fine" on a single card decides it - the log keeps its shape
        $logged = $this->database->fetchAllAssociative('SELECT decision, decided_by_id, case_id FROM suspicious_time_decision WHERE case_id IN (:ids) ORDER BY case_id', ['ids' => $scenario['cases']], ['ids' => ArrayParameterType::STRING]);
        self::assertCount(2, $logged);
        foreach ($logged as $line) {
            self::assertSame(['trusted', PlayerFixture::PLAYER_ADMIN], [$line['decision'], $line['decided_by_id']]);
        }
        // Nothing is set aside, nobody is told; the typed result's case is as it was
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id AND suspicious', ['id' => $scenario['player']->id->toString()]));
        self::assertSame('pending', $this->database->fetchOne('SELECT status FROM suspicious_time_case WHERE id = :id', ['id' => $typedCase]));

        // A regular player reaches none of it
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('POST', '/admin/time-verification/imports/' . $scenario['batch'] . '/mark', $fields);
        self::assertResponseStatusCodeSame(403);
    }

    public function testMarkAllSetsThemAsideWithTheNoteAndThePlayerIsToldOnceForTheGroup(): void
    {
        // Imported times three times as fast as the typed ones: they do not add up
        $scenario = $this->importWithRaisedResults('cardmark', importedSeconds: [1000, 1010, 1020], raised: 3);
        $playerId = $scenario['player']->id->toString();
        // One of them was decided meanwhile by somebody else: passed over, the others are marked all the same
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $card = $this->browser->request('GET', '/admin/time-verification')->filter('[data-testid="time-verification-import-card"][data-batch-id="' . $scenario['batch'] . '"]');
        self::assertSame('faster', $card->attr('data-comparison'));
        self::assertStringContainsString('Not consistent', $card->filter('[data-testid="time-verification-import-comparison"]')->text());
        $form = $card->filter('form[action$="/mark"]');
        self::assertSame('Mark all 3 as needing verification', self::spaced($form->filter('[data-testid="time-verification-import-mark"]')->text()));
        $fields = ['note' => 'These are far from your other results - please check the file.'];

        $fields = [...$fields, ...self::hidden($form)];

        $this->browser->request('POST', '/admin/time-verification/imports/' . $scenario['batch'] . '/mark', $fields);
        self::assertResponseRedirects('/admin/time-verification?tab=fast');
        self::assertStringContainsString('Marked as needing verification: 3 results.', $this->browser->followRedirect()->text());

        // The existing mark, per case: the flag, the note, a line in the log each
        self::assertSame(3, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id AND suspicious', ['id' => $playerId]));
        self::assertSame(
            [['status' => 'marked', 'moderator_note' => 'These are far from your other results - please check the file.']],
            array_values(array_unique($this->database->fetchAllAssociative('SELECT status, moderator_note FROM suspicious_time_case WHERE id IN (:ids)', ['ids' => $scenario['cases']], ['ids' => ArrayParameterType::STRING]), SORT_REGULAR)),
        );
        self::assertSame(3, $this->pairingCount("SELECT COUNT(*) FROM suspicious_time_decision WHERE tracker_id = :id AND decision = 'marked'", ['id' => $playerId]));

        // Told by the notice run, as any mark: a notice per result ...
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new NotifySuspiciousTimes());
        $noticeIds = array_values(array_filter($this->database->fetchFirstColumn('SELECT CAST(id AS TEXT) FROM suspicious_time_notice WHERE player_id = :id', ['id' => $playerId]), is_string(...)));
        self::assertCount(3, $noticeIds);

        // ... and ONE item of the "Your results" e-mail for the whole import - never a line per result
        $markedTimes = self::getContainer()->get(GetResultReviewEmails::class)->markedTimesOf($playerId, $noticeIds);
        self::assertCount(3, $markedTimes);
        $email = self::getContainer()->get(ResultReviewEmailComposer::class)->compose(Uuid::uuid7()->toString(), $playerId, 'Tester', 'tester@example.com', 'en', false, [], [], $markedTimes, []);
        $context = $email->getContext();
        self::assertSame([3], $context['markedImportGroups']);
        self::assertSame([], $context['markedTimes']);
        self::assertSame(0, $context['moreMarkedTimes']);
        $html = self::getContainer()->get('twig')->render('emails/result_review.html.twig', $context);
        self::assertSame(1, substr_count($html, 'Results from your spreadsheet import, set aside together: 3'));
        self::assertStringNotContainsString('Zq Cardmark Puzzle', $html);

        // "Your spreadsheet import" is the importer's to read: a person of the result who did not import it gets the
        // plain lines (an imported result has no registered teammate today - the rule holds all the same)
        $somebody = $this->seedPairingPlayer('cardmarkother');
        $this->database->update('data_import', ['player_id' => $somebody->id->toString()], ['id' => $scenario['import']]);
        $notImporting = self::getContainer()->get(GetResultReviewEmails::class)->markedTimesOf($playerId, $noticeIds);
        self::assertSame([null, null, null], array_map(static fn ($markedTime): null|string => $markedTime->importBatchId, $notImporting));
        TestingLogin::asPlayer($this->browser, $playerId);
        self::assertCount(0, $this->browser->request('GET', '/en/review-results')->filter('[data-testid="suspicious-import-group"]'));
        $this->database->update('data_import', ['player_id' => $playerId], ['id' => $scenario['import']]);

        // The review page says it once, above the results - each of which can still be answered
        TestingLogin::asPlayer($this->browser, $playerId);
        $crawler = $this->browser->request('GET', '/en/review-results');
        self::assertResponseIsSuccessful();
        $group = $crawler->filter('[data-testid="suspicious-import-group"]');
        self::assertCount(1, $group);
        self::assertSame('3', $group->attr('data-results'));
        self::assertCount(3, $crawler->filter('[data-testid="suspicious-time"]'));
        self::assertStringContainsString('These are far from your other results', $crawler->filter('[data-testid="suspicious-time-note"]')->first()->text());
    }

    public function testCasesOfAnImportThatIsTakenBackAreGoneWithItsResults(): void
    {
        $scenario = $this->importWithRaisedResults('cardundo', importedSeconds: [3050, 3150, 3250], raised: 3);
        self::assertSame(3, $this->pairingCount('SELECT COUNT(*) FROM suspicious_time_case WHERE id IN (:a, :b, :c)', ['a' => $scenario['cases'][0], 'b' => $scenario['cases'][1], 'c' => $scenario['cases'][2]]));

        $this->undo($scenario['import'], $scenario['player'], DataImportUndoScope::Import);

        // Like the case of any deleted result: gone with it - nothing is left open for a result that is not there
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM suspicious_time_case WHERE id IN (:a, :b, :c)', ['a' => $scenario['cases'][0], 'b' => $scenario['cases'][1], 'c' => $scenario['cases'][2]]));
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $this->browser->request('GET', '/admin/time-verification');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="time-verification-import-card"][data-batch-id="' . $scenario['batch'] . '"]'));
        // A decision that arrives for it meanwhile decides nothing
        $outcome = self::getContainer()->get(MessageBusInterface::class)
            ->dispatch(new DecideImportTimeCases($scenario['batch'], SuspicionDirection::Fast, PlayerFixture::PLAYER_ADMIN, false, null))
            ->last(HandledStamp::class)
            ?->getResult();
        self::assertSame(['decided' => 0, 'changed' => 0, 'more' => false], $outcome);
    }

    /**
     * A new player with two typed solo results on a made-up 500-piece puzzle (about 50 minutes each), and an import
     * that added results with the times given on it; the first $raised of them are pending cases, as the scan
     * raises a confirmed time.
     *
     * @param list<int> $importedSeconds
     *
     * @return array{player: Player, import: string, batch: string, cases: list<string>, typed: list<string>, times: list<string>}
     */
    private function importWithRaisedResults(string $name, array $importedSeconds, int $raised): array
    {
        $player = $this->seedPairingPlayer($name);
        $marker = 'Zq ' . ucfirst($name);
        $puzzle = $this->seedCataloguePuzzle($marker . ' Puzzle', 500, $this->seedBrand($marker . ' Brand'));
        $typed = [
            $this->typedResult($player, $puzzle, '2022-01-01', 3000),
            $this->typedResult($player, $puzzle, '2022-02-01', 3100),
        ];
        [$import, $sheet] = $this->importOf($player);
        $rows = [];

        foreach ($importedSeconds as $index => $seconds) {
            $rows[] = $this->resultRow($import, $sheet, $marker . ' Puzzle', 500, $marker . ' Brand', sprintf('2021-0%d-10', $index + 1), $seconds);
        }

        $this->pair($import);
        $outcome = $this->addAndWork($import, $player);
        self::assertNotNull($outcome->id);
        $cases = [];
        $times = [];

        foreach ($rows as $index => $rowId) {
            $timeId = $this->rowNow($rowId)['solving_time_id'];
            self::assertIsString($timeId);
            $times[] = $timeId;

            if ($index < $raised) {
                $cases[] = $this->raise($timeId);
            }
        }

        return ['player' => $player, 'import' => $import->id->toString(), 'batch' => $outcome->id, 'cases' => $cases, 'typed' => $typed, 'times' => $times];
    }

    /**
     * A pending case for the result, the way the scan raises a time three times as fast as expected.
     */
    private function raise(string $timeId): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $time = $entityManager->find(PuzzleSolvingTime::class, $timeId);
        self::assertInstanceOf(PuzzleSolvingTime::class, $time);
        $caseId = Uuid::uuid7();
        $entityManager->persist(SuspiciousTimeCase::detected(
            $caseId,
            $time,
            new SuspicionAssessment(
                outcome: SuspicionCheckOutcome::Raised,
                tier: SuspiciousTimeTier::Strong,
                ratio: 3.0,
                expectedSeconds: 27000,
                expectedSource: ExpectedTimeSource::Baseline,
                reasons: [new SuspiciousTimeReason(SuspiciousTimeReasonCode::FasterThanUsual, ['expected' => 27000, 'entered' => $time->secondsToSolve, 'ratio' => 3.0, 'pieces' => 500, 'source' => 'baseline'])],
                score: 3.0,
            ),
            SuspicionFingerprint::ofTime($time),
            SuspiciousTimeClassifier::VERSION,
            TestClock::now(),
        ));
        $entityManager->flush();
        $entityManager->clear();

        return $caseId->toString();
    }

    /**
     * @return array<string, string> the form's hidden fields
     */
    private static function hidden(Crawler $form): array
    {
        $fields = [];

        $form->filter('input[type="hidden"]')->each(static function (Crawler $input) use (&$fields): void {
            $fields[(string) $input->attr('name')] = (string) $input->attr('value');
        });

        return $fields;
    }

    private static function spaced(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
