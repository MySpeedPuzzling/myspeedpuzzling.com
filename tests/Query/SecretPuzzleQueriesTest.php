<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCompetitionRound;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Query\GetCompetitionPuzzles;
use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Query\GetPuzzleChangeRequests;
use SpeedPuzzling\Web\Query\GetPuzzleMergeReviewQueue;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetPuzzleSummary;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use SpeedPuzzling\Web\Results\ManufacturerOverview;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\TagFixture;
use SpeedPuzzling\Web\Tests\ReadsRoundAutomaticReveal;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Every read surface keeps a secret competition puzzle out - brand pickers, the event's tag list, the moderation and
 * merge queues, code search - and the organiser's own puzzles come back in their round picker.
 */
final class SecretPuzzleQueriesTest extends KernelTestCase
{
    use ReadsRoundAutomaticReveal;

    private const string SECRET_EAN = '4005556175512';

    private MessageBusInterface $messageBus;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testBrandPickersCountVisiblePuzzlesAndDropBrandsWhoseEveryPuzzleIsSecret(): void
    {
        $manufacturers = self::getContainer()->get(GetManufacturers::class);
        $before = $this->brandCount($manufacturers->allIncludingUnapproved(), ManufacturerFixture::MANUFACTURER_RAVENSBURGER);

        // A secret puzzle of an existing brand is not counted
        $this->addSecretPuzzle('Counted Nowhere', ManufacturerFixture::MANUFACTURER_RAVENSBURGER);
        self::assertSame($before, $this->brandCount($manufacturers->allIncludingUnapproved(), ManufacturerFixture::MANUFACTURER_RAVENSBURGER));

        // A brand typed for a secret puzzle only - in no picker but the organisers'
        $roundPuzzleId = $this->addSecretPuzzle('Brand New Secret', 'myRavensburger Secret Brand');
        $brandId = $this->roundPuzzle($roundPuzzleId)->puzzle->manufacturer?->id->toString();
        self::assertNotNull($brandId);

        self::assertNull($this->brandCount($manufacturers->allIncludingUnapproved(), $brandId));
        self::assertNull($this->brandCount($manufacturers->allIncludingUnapproved(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024), $brandId));
        self::assertSame(0, $this->brandCount($manufacturers->allIncludingUnapproved(CompetitionFixture::COMPETITION_WJPC_2024), $brandId));

        // Removed from its round: still in its organiser's picker
        $this->messageBus->dispatch(new RemovePuzzleFromCompetitionRound($roundPuzzleId));
        $this->entityManager->clear();
        self::assertNull($this->brandCount($manufacturers->allIncludingUnapproved(CompetitionFixture::COMPETITION_WJPC_2024), $brandId));
        self::assertSame(0, $this->brandCount($manufacturers->allIncludingUnapproved(CompetitionFixture::COMPETITION_WJPC_2024, PlayerFixture::PLAYER_REGULAR), $brandId));
    }

    public function testTheOrganisersOrphanedSecretPuzzleComesBackInTheirPicker(): void
    {
        $roundPuzzleId = $this->addSecretPuzzle('Orphan Secret', ManufacturerFixture::MANUFACTURER_RAVENSBURGER);
        $puzzleId = $this->roundPuzzle($roundPuzzleId)->puzzle->id->toString();
        $this->messageBus->dispatch(new RemovePuzzleFromCompetitionRound($roundPuzzleId));
        $this->entityManager->clear();

        $search = self::getContainer()->get(SearchPuzzle::class);

        self::assertNotContains($puzzleId, self::autocompleteIds($search->byBrandId(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, CompetitionFixture::COMPETITION_WJPC_2024)));
        self::assertContains($puzzleId, self::autocompleteIds($search->byBrandId(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, CompetitionFixture::COMPETITION_WJPC_2024, PlayerFixture::PLAYER_REGULAR)));
        self::assertNotContains($puzzleId, self::autocompleteIds($search->byBrandId(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, CompetitionFixture::COMPETITION_WJPC_2024, PlayerFixture::PLAYER_PRIVATE)));
    }

    public function testTheEventsTagListObeysTheReveal(): void
    {
        $roundPuzzleId = $this->addSecretPuzzle('Tagged Secret', ManufacturerFixture::MANUFACTURER_RAVENSBURGER);
        $puzzleId = $this->roundPuzzle($roundPuzzleId)->puzzle->id->toString();
        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO tag_puzzle (tag_id, puzzle_id) VALUES (:tagId, :puzzleId)',
            ['tagId' => TagFixture::TAG_WJPC, 'puzzleId' => $puzzleId],
        );
        $revealAt = $this->roundPuzzle($roundPuzzleId)->revealsAt();
        self::assertNotNull($revealAt);

        $ids = fn (DateTimeImmutable $at): array => array_map(
            static fn ($overview): string => $overview->puzzleId,
            new GetPuzzleOverview(self::getContainer()->get(Connection::class), new MockClock($at))->byTagId(TagFixture::TAG_WJPC),
        );

        self::assertNotContains($puzzleId, $ids($revealAt->modify('-1 second')));
        self::assertContains($puzzleId, $ids($revealAt));
    }

    public function testAPuzzleHiddenByTheRoundOnlyStaysOffTheEventsTagListAndItsUsedAt(): void
    {
        // A public catalogue puzzle, tagged with the event's tag, kept secret on the event page by its round only
        $puzzleId = PuzzleFixture::PUZZLE_2000;
        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO tag_puzzle (tag_id, puzzle_id) VALUES (:tagId, :puzzleId) ON CONFLICT DO NOTHING',
            ['tagId' => TagFixture::TAG_WJPC, 'puzzleId' => $puzzleId],
        );
        $roundPuzzleId = Uuid::uuid7();
        $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: $puzzleId,
            piecesCount: null,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            shownAutomaticRevealAt: self::automaticRevealOf(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION),
        ));
        $this->entityManager->clear();
        $roundPuzzle = $this->roundPuzzle($roundPuzzleId->toString());
        self::assertFalse($roundPuzzle->hidesEverywhere);
        self::assertFalse($roundPuzzle->puzzle->isImageHiddenAt(new DateTimeImmutable()));
        $revealAt = $roundPuzzle->revealsAt();
        self::assertNotNull($revealAt);

        $connection = self::getContainer()->get(Connection::class);
        $tagList = static fn (DateTimeImmutable $at): array => array_map(
            static fn ($overview): string => $overview->puzzleId,
            new GetPuzzleOverview($connection, new MockClock($at))->byTagId(TagFixture::TAG_WJPC),
        );
        $usedAt = static fn (DateTimeImmutable $at): array => array_map(
            static fn ($reference): null|string => $reference->slug,
            new GetPuzzleSummary($connection, new MockClock($at))->forPuzzle($puzzleId)->usedAt,
        );

        self::assertNotContains($puzzleId, $tagList($revealAt->modify('-1 second')));
        self::assertNotContains('wjpc-2024', $usedAt($revealAt->modify('-1 second')));
        self::assertContains($puzzleId, $tagList($revealAt));
        self::assertContains('wjpc-2024', $usedAt($revealAt));
    }

    public function testTheEventPagesRoundAndSolvedPuzzlesObeyTheRoundsReveal(): void
    {
        // The Qualification round's own puzzle (with three linked results) turned secret on the event page
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "UPDATE competition_round_puzzle SET hide_until_round_starts = true, hide_mode = 'entirely', reveal_mode = 'automatic'
             WHERE round_id = :roundId AND puzzle_id = :puzzleId",
            ['roundId' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, 'puzzleId' => PuzzleFixture::PUZZLE_500_01],
        );
        $round = $this->entityManager->find(\SpeedPuzzling\Web\Entity\CompetitionRound::class, CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        self::assertNotNull($round);
        $revealAt = $round->automaticRevealAt();

        $before = new GetCompetitionPuzzles($connection, new MockClock($revealAt->modify('-1 second')));
        $after = new GetCompetitionPuzzles($connection, new MockClock($revealAt));

        self::assertNotContains(PuzzleFixture::PUZZLE_500_01, self::overviewIds($before->roundPuzzleOverviews(CompetitionFixture::COMPETITION_WJPC_2024)));
        self::assertNotContains(PuzzleFixture::PUZZLE_500_01, self::overviewIds($before->solvedPuzzleOverviews(CompetitionFixture::COMPETITION_WJPC_2024, 50)));
        self::assertContains(PuzzleFixture::PUZZLE_500_01, self::overviewIds($after->roundPuzzleOverviews(CompetitionFixture::COMPETITION_WJPC_2024)));
        self::assertContains(PuzzleFixture::PUZZLE_500_01, self::overviewIds($after->solvedPuzzleOverviews(CompetitionFixture::COMPETITION_WJPC_2024, 50)));
    }

    /**
     * The round row's own delay - set by SQL here, the way an older release's rows look: only the column decides.
     */
    #[DataProvider('customDelays')]
    public function testRoundAndSolvedPuzzleOverviewsObeyACustomDelay(int $delay): void
    {
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "UPDATE competition_round_puzzle SET hide_until_round_starts = true, hide_mode = 'entirely', reveal_mode = 'automatic'
             WHERE round_id = :roundId AND puzzle_id = :puzzleId",
            ['roundId' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, 'puzzleId' => PuzzleFixture::PUZZLE_500_01],
        );
        $connection->executeStatement(
            'UPDATE competition_round SET reveal_delay_minutes = :delay WHERE id = :roundId',
            ['delay' => $delay, 'roundId' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION],
        );
        $round = $this->entityManager->find(\SpeedPuzzling\Web\Entity\CompetitionRound::class, CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        self::assertNotNull($round);
        $revealAt = $round->startsAt->modify(sprintf('+%d minutes', $delay));
        self::assertEquals($revealAt, $round->automaticRevealAt());

        $before = new GetCompetitionPuzzles($connection, new MockClock($revealAt->modify('-1 second')));
        $after = new GetCompetitionPuzzles($connection, new MockClock($revealAt));

        self::assertNotContains(PuzzleFixture::PUZZLE_500_01, self::overviewIds($before->roundPuzzleOverviews(CompetitionFixture::COMPETITION_WJPC_2024)));
        self::assertNotContains(PuzzleFixture::PUZZLE_500_01, self::overviewIds($before->solvedPuzzleOverviews(CompetitionFixture::COMPETITION_WJPC_2024, 50)));
        self::assertContains(PuzzleFixture::PUZZLE_500_01, self::overviewIds($after->roundPuzzleOverviews(CompetitionFixture::COMPETITION_WJPC_2024)));
        self::assertContains(PuzzleFixture::PUZZLE_500_01, self::overviewIds($after->solvedPuzzleOverviews(CompetitionFixture::COMPETITION_WJPC_2024, 50)));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function customDelays(): iterable
    {
        yield 'when the round starts' => [0];
        yield '25 minutes' => [25];
    }

    public function testCodeSearchFindsAPicturelessSecretOnlyFromItsRevealOn(): void
    {
        $roundPuzzleId = $this->addSecretPuzzle('Coded Secret', ManufacturerFixture::MANUFACTURER_RAVENSBURGER, PuzzleHideMode::ImageOnly);
        $puzzleId = $this->roundPuzzle($roundPuzzleId)->puzzle->id->toString();
        $revealAt = $this->roundPuzzle($roundPuzzleId)->revealsAt();
        self::assertNotNull($revealAt);

        $found = function (DateTimeImmutable $at) use ($puzzleId): bool {
            $search = new SearchPuzzle(self::getContainer()->get(Connection::class), new MockClock($at));

            foreach ([...$search->byUserInput(null, self::SECRET_EAN, PiecesRange::any(), null), ...$search->allByEan(self::SECRET_EAN)] as $overview) {
                if ($overview->puzzleId === $puzzleId) {
                    return true;
                }
            }

            return false;
        };

        self::assertFalse($found($revealAt->modify('-1 second')));
        self::assertTrue($found($revealAt));
    }

    public function testChangeRequestsOfASecretPuzzleAreForAdminsOnly(): void
    {
        $this->hideAsCompetitionSecret(PuzzleFixture::PUZZLE_500_01);
        $requests = self::getContainer()->get(GetPuzzleChangeRequests::class);


        self::assertNotContains(PuzzleReportFixture::CHANGE_REQUEST_PENDING, self::changeRequestIds($requests->allPending()));
        self::assertNull($requests->byId(PuzzleReportFixture::CHANGE_REQUEST_PENDING));
        self::assertContains(PuzzleReportFixture::CHANGE_REQUEST_PENDING, self::changeRequestIds($requests->allPending(includeSecret: true)));
        self::assertNotNull($requests->byId(PuzzleReportFixture::CHANGE_REQUEST_PENDING, includeSecret: true));
        self::assertGreaterThan($requests->countByStatus()['pending'], $requests->countByStatus(includeSecret: true)['pending']);
    }

    public function testTheInternalMergeQueueLeavesSecretPuzzlesOut(): void
    {
        $roundPuzzleId = $this->addSecretPuzzle('Merge Me Not', ManufacturerFixture::MANUFACTURER_RAVENSBURGER);
        $secretId = $this->roundPuzzle($roundPuzzleId)->puzzle->id->toString();
        $queue = self::getContainer()->get(GetPuzzleMergeReviewQueue::class);
        $countBefore = $queue->countPending();

        // Filed before the puzzle became secret (a report naming a secret puzzle is refused now)
        $mergeRequestId = $this->mergeRequestFiledEarlier(PuzzleFixture::PUZZLE_500_05, $secretId);

        self::assertSame($countBefore, $queue->countPending());
        foreach ($queue->pending(100) as $item) {
            self::assertNotSame($mergeRequestId, $item->mergeRequestId);
        }
    }

    public function testAReportWithASecretDuplicateNeitherShowsNorBlocksThePublicPuzzle(): void
    {
        $roundPuzzleId = $this->addSecretPuzzle('Reported Secret', ManufacturerFixture::MANUFACTURER_RAVENSBURGER);
        $secretId = $this->roundPuzzle($roundPuzzleId)->puzzle->id->toString();
        $proposals = self::getContainer()->get(\SpeedPuzzling\Web\Query\GetPendingPuzzleProposals::class);
        self::assertFalse($proposals->blocksNewProposal(PuzzleFixture::PUZZLE_500_05));

        // A player with the secret id reports it as a DUPLICATE of a public puzzle: refused, unseen
        try {
            $this->messageBus->dispatch(new SubmitPuzzleMergeRequest(
                mergeRequestId: Uuid::uuid7()->toString(),
                sourcePuzzleId: PuzzleFixture::PUZZLE_500_05,
                reporterId: PlayerFixture::PLAYER_PRIVATE,
                duplicatePuzzleIds: [$secretId],
            ));
            self::fail('A secret puzzle must not be reportable as a duplicate');
        } catch (\SpeedPuzzling\Web\Exceptions\PuzzleNotFound) {
        }

        // ... its organiser too
        try {
            $this->messageBus->dispatch(new SubmitPuzzleMergeRequest(
                mergeRequestId: Uuid::uuid7()->toString(),
                sourcePuzzleId: PuzzleFixture::PUZZLE_500_05,
                reporterId: PlayerFixture::PLAYER_REGULAR,
                duplicatePuzzleIds: [$secretId],
            ));
            self::fail('Not even its organiser reports a secret puzzle');
        } catch (\SpeedPuzzling\Web\Exceptions\PuzzleIsStillSecret) {
        }

        // One filed before it became secret: not on the public puzzle's pages, no badge, no block
        $this->mergeRequestFiledEarlier(PuzzleFixture::PUZZLE_500_05, $secretId);
        self::assertSame([], $proposals->forPuzzle(PuzzleFixture::PUZZLE_500_05));
        self::assertFalse($proposals->hasPendingForPuzzle(PuzzleFixture::PUZZLE_500_05));
        self::assertFalse($proposals->blocksNewProposal(PuzzleFixture::PUZZLE_500_05));
    }

    private function mergeRequestFiledEarlier(string $sourcePuzzleId, string $duplicateId): string
    {
        $mergeRequestId = Uuid::uuid7();
        $source = $this->entityManager->find(Puzzle::class, $sourcePuzzleId);
        $reporter = $this->entityManager->find(\SpeedPuzzling\Web\Entity\Player::class, PlayerFixture::PLAYER_PRIVATE);
        self::assertNotNull($source);
        $this->entityManager->persist(new \SpeedPuzzling\Web\Entity\PuzzleMergeRequest(
            id: $mergeRequestId,
            sourcePuzzle: $source,
            reporter: $reporter,
            submittedAt: new DateTimeImmutable('-1 day'),
            reportedDuplicatePuzzleIds: [$sourcePuzzleId, $duplicateId],
        ));
        $this->entityManager->flush();
        $this->entityManager->clear();

        return $mergeRequestId->toString();
    }

    /**
     * @param array<\SpeedPuzzling\Web\Results\PuzzleOverview> $overviews
     * @return list<string>
     */
    private static function overviewIds(array $overviews): array
    {
        return array_values(array_map(static fn (\SpeedPuzzling\Web\Results\PuzzleOverview $overview): string => $overview->puzzleId, $overviews));
    }

    /**
     * @param array<\SpeedPuzzling\Web\Results\AutocompletePuzzle> $choices
     * @return list<string>
     */
    private static function autocompleteIds(array $choices): array
    {
        return array_values(array_map(static fn (\SpeedPuzzling\Web\Results\AutocompletePuzzle $choice): string => $choice->puzzleId, $choices));
    }

    /**
     * @param array<\SpeedPuzzling\Web\Results\PuzzleChangeRequestOverview> $overviews
     * @return list<string>
     */
    private static function changeRequestIds(array $overviews): array
    {
        return array_values(array_map(static fn (\SpeedPuzzling\Web\Results\PuzzleChangeRequestOverview $overview): string => $overview->id, $overviews));
    }

    private function hideAsCompetitionSecret(string $puzzleId): void
    {
        $puzzle = $this->entityManager->find(Puzzle::class, $puzzleId);
        self::assertNotNull($puzzle);
        $puzzle->approved = false;
        $puzzle->keepSecretUntil(new DateTimeImmutable('+5 days'), new DateTimeImmutable('+5 days'));
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /**
     * @param array<ManufacturerOverview> $overviews
     */
    private function brandCount(array $overviews, string $manufacturerId): null|int
    {
        foreach ($overviews as $overview) {
            if ($overview->manufacturerId === $manufacturerId) {
                return $overview->puzzlesCount;
            }
        }

        return null;
    }

    private function addSecretPuzzle(string $name, string $brand, PuzzleHideMode $hideMode = PuzzleHideMode::Entirely): string
    {
        $roundPuzzleId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: $brand,
            puzzle: $name,
            piecesCount: 500,
            puzzlePhoto: null,
            eans: EanList::fromStored(self::SECRET_EAN),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: $hideMode,
            shownAutomaticRevealAt: self::automaticRevealOf(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION),
        ));
        $this->entityManager->clear();

        return $roundPuzzleId->toString();
    }

    private function roundPuzzle(string $roundPuzzleId): CompetitionRoundPuzzle
    {
        $roundPuzzle = $this->entityManager->find(CompetitionRoundPuzzle::class, $roundPuzzleId);
        self::assertNotNull($roundPuzzle);

        return $roundPuzzle;
    }
}
