<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Repository\PuzzleMergeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class PuzzleMergeRequestControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string FORM = 'puzzle_merge_review_form';

    public function testListIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/puzzle-merge-requests');

        $this->assertResponseRedirects('/login?return=/admin/puzzle-merge-requests');
    }

    public function testApproveIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/admin/puzzle-merge-requests/' . PuzzleReportFixture::MERGE_REQUEST_PENDING);

        $this->assertResponseRedirects('/login');
    }

    public function testRejectIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/admin/puzzle-merge-requests/00000000-0000-0000-0000-000000000000/reject');

        $this->assertResponseRedirects('/login');
    }

    public function testTheReviewKeepsThePuzzleWithTheMostTimesAndPrefillsTheMergedOne(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/admin/puzzle-merge-requests/' . PuzzleReportFixture::MERGE_REQUEST_PENDING);

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '[data-merge-review-target="card"]');

        $values = $crawler->filter('form[data-controller~="merge-review"]')->form()->getValues();
        self::assertSame(PuzzleFixture::PUZZLE_500_01, $values[self::FORM . '[survivorPuzzleId]']);
        // The names editor: the survivor's main title, the other puzzle's one click from being the main title
        self::assertSame('Puzzle 1', $values[self::FORM . '[names][name]']);
        self::assertSame('Puzzle 2', $values[self::FORM . '[names][alternativeNames][0][name]']);
        self::assertSelectorExists('.names-editor [data-action="names-editor#makeMain"]');
        // Every reported puzzle says who added it
        self::assertSelectorCount(2, '[data-role="puzzle-added-by"]');
        self::assertSelectorTextContains('[data-role="puzzle-added-by"]', 'Admin User');
    }

    public function testTheReviewReadsThePuzzlesInOneStatementEachNotOnePerPuzzle(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $this->startCountingQueries($browser);
        $browser->request('GET', '/admin/puzzle-merge-requests/' . PuzzleReportFixture::MERGE_REQUEST_PENDING);
        self::assertResponseIsSuccessful();

        $sql = $this->executedSql($browser);
        // Two reported puzzles: their overviews (GetPuzzleOverview::byIds()) and their stored records (GetPuzzleRecord::byIds())
        self::assertCount(1, array_filter($sql, static fn (string $statement): bool => str_contains($statement, 'puzzle_statistics.fastest_time_team')));
        self::assertCount(1, array_filter($sql, static fn (string $statement): bool => str_contains($statement, 'added_by.code AS added_by_code')));
    }

    public function testTheReviewStartsFromEveryNameInTheLanguagesTheReporterGave(): void
    {
        $browser = self::createClient();
        $mergeRequestId = $this->mergeRequestOfACzechBox($browser);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/admin/puzzle-merge-requests/' . $mergeRequestId);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-puzzle-id="' . PuzzleFixture::PUZZLE_500_05 . '"]', 'The name is in Czech');

        // The Czech box keeps its address (more solving times) - its title starts as the main title, in Czech, so the
        // moderator sees there is no English one yet
        $values = $crawler->filter('form[data-controller~="merge-review"]')->form()->getValues();
        self::assertSame(PuzzleFixture::PUZZLE_500_05, $values[self::FORM . '[survivorPuzzleId]']);
        self::assertSame('Kouzelné ráno', $values[self::FORM . '[names][name]']);
        self::assertSame('cs', $values[self::FORM . '[names][nameLanguage]']);
        self::assertSame('Magischer Morgen', $values[self::FORM . '[names][alternativeNames][0][name]']);
        self::assertSame('de', $values[self::FORM . '[names][alternativeNames][0][language]']);
        self::assertSame('Puzzle 4', $values[self::FORM . '[names][alternativeNames][1][name]']);
        self::assertSame('', $values[self::FORM . '[names][alternativeNames][1][language]']);
    }

    public function testApprovingSavesTheNamesTheReviewerSettled(): void
    {
        $browser = self::createClient();
        $mergeRequestId = $this->mergeRequestOfACzechBox($browser);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/admin/puzzle-merge-requests/' . $mergeRequestId . '?return=/admin/puzzle-approvals');
        $form = $crawler->filter('form[data-controller~="merge-review"]')->form();
        $loaded = $form->getPhpValues()[self::FORM] ?? null;
        self::assertIsArray($loaded);

        // The reviewer types the English title, keeps the Czech one, drops the German one and the other puzzle's title
        $browser->request($form->getMethod(), $form->getUri(), [self::FORM => [
            'survivorPuzzleId' => PuzzleFixture::PUZZLE_500_05,
            'names' => [
                'name' => 'Magic Morning',
                'nameLanguage' => '',
                'alternativeNames' => [
                    1 => ['name' => 'Kouzelné ráno', 'language' => 'cs'],
                ],
            ],
            'piecesCount' => '500',
            'recordVersions' => $loaded['recordVersions'],
            '_token' => $loaded['_token'],
        ]]);

        self::assertResponseRedirects('/admin/puzzle-approvals');

        $survivor = $browser->getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_500_05);
        self::assertSame('Magic Morning', $survivor->name);
        self::assertNull($survivor->nameLanguage);
        self::assertSame([['name' => 'Kouzelné ráno', 'language' => 'cs']], $survivor->alternativeNames);
    }

    public function testAPuzzleSavedWhileTheReviewWasOpenRefusesTheMergeAndKeepsWhatWasTyped(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/admin/puzzle-merge-requests/' . PuzzleReportFixture::MERGE_REQUEST_PENDING);
        $form = $crawler->filter('form[data-controller~="merge-review"]')->form();

        // Somebody edits the other puzzle in the meantime
        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $puzzle = $browser->getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_500_02);
        $puzzle->changeNames('Puzzle 2', null, new PuzzleNames([new PuzzleName('Hádanka 2', 'cs')]), new DateTimeImmutable());
        $entityManager->flush();

        $browser->submit($form, [self::FORM . '[names][name]' => 'Typed In The Review']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[data-controller~="merge-review"]', 'This puzzle was changed while you were editing it.');
        self::assertInputValueSame(self::FORM . '[names][name]', 'Typed In The Review');

        $entityManager->clear();
        $mergeRequest = $browser->getContainer()->get(PuzzleMergeRequestRepository::class)->get(PuzzleReportFixture::MERGE_REQUEST_PENDING);
        self::assertSame(PuzzleReportStatus::Pending, $mergeRequest->status);
    }

    public function testACodeTheReviewerAddsFollowsTheRuleOfEveryForm(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/admin/puzzle-merge-requests/' . PuzzleReportFixture::MERGE_REQUEST_PENDING);
        $form = $crawler->filter('form[data-controller~="merge-review"]')->form();

        // A catalogue number is no barcode - the codes the puzzles carry pass as they are
        $browser->submit($form, [self::FORM . '[eans][0]' => '6000-5533']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[data-controller~="merge-review"]', '6000-5533');
        self::assertInputValueSame(self::FORM . '[eans][0]', '6000-5533');

        $mergeRequest = $browser->getContainer()->get(PuzzleMergeRequestRepository::class)->get(PuzzleReportFixture::MERGE_REQUEST_PENDING);
        self::assertSame(PuzzleReportStatus::Pending, $mergeRequest->status);
    }

    public function testApprovingKeepsTheNoteInTheHistory(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/admin/puzzle-merge-requests/' . PuzzleReportFixture::MERGE_REQUEST_PENDING);
        $form = $crawler->filter('form[data-controller~="merge-review"]')->form();

        $browser->submit($form, [
            self::FORM . '[names][name]' => 'Merged In The Review',
            self::FORM . '[decisionNote]' => 'Same EAN on both boxes',
        ]);

        self::assertResponseRedirects('/admin/puzzle-merge-requests');

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $decision = $entityManager->getRepository(PuzzleModerationDecision::class)->findOneBy([
            'mergeRequestId' => Uuid::fromString(PuzzleReportFixture::MERGE_REQUEST_PENDING),
        ]);
        self::assertNotNull($decision);
        self::assertSame('Same EAN on both boxes', $decision->note);
        self::assertSame('Merged In The Review', $decision->puzzleName);

        // The editor's list is final: the main title typed over is gone, the other puzzle's title stays
        $survivor = $browser->getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_500_01);
        self::assertSame([['name' => 'Puzzle 2', 'language' => null]], $survivor->alternativeNames);
    }

    /**
     * Puzzle 4 and the Czech box "Kouzelné ráno" with a German name (the one with solving times), reported as the
     * Czech box
     */
    private function mergeRequestOfACzechBox(KernelBrowser $browser): string
    {
        $container = $browser->getContainer();
        $duplicate = $container->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_500_05);
        $duplicate->changeNames('Kouzelné ráno', null, new PuzzleNames([new PuzzleName('Magischer Morgen', 'de')]), new DateTimeImmutable());
        $container->get(EntityManagerInterface::class)->flush();

        $mergeRequestId = Uuid::uuid7()->toString();
        $container->get(MessageBusInterface::class)->dispatch(new SubmitPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            sourcePuzzleId: PuzzleFixture::PUZZLE_500_04,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            duplicatePuzzleIds: [PuzzleFixture::PUZZLE_500_05],
            reportedNameLanguages: [PuzzleFixture::PUZZLE_500_05 => 'cs'],
        ));

        return $mergeRequestId;
    }
}
