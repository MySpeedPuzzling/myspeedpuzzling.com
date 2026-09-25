<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Message\RejectPuzzleChangeRequest;
use SpeedPuzzling\Web\Message\RejectPuzzleMergeRequest;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Every catalogue decision leaves a puzzle_moderation_decision row naming who
 * decided - one that survives the request, the puzzle and the decider.
 */
final class PuzzleModerationDecisionLogTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testApprovingAChangeRequestIsLogged(): void
    {
        $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
            changeRequestId: PuzzleReportFixture::CHANGE_REQUEST_PENDING,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            selectedFields: ['name'],
            overrides: [],
        ));

        $decision = $this->onlyDecision(['changeRequestId' => Uuid::fromString(PuzzleReportFixture::CHANGE_REQUEST_PENDING)]);
        self::assertSame(PuzzleModerationAction::ChangeRequestApproved, $decision->action);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $decision->decidedById->toString());
        self::assertSame(['name'], $decision->details['selectedFields'] ?? null);
    }

    public function testRejectingAChangeRequestIsLoggedWithTheReason(): void
    {
        $this->messageBus->dispatch(new RejectPuzzleChangeRequest(
            changeRequestId: PuzzleReportFixture::CHANGE_REQUEST_PENDING,
            reviewerId: PlayerFixture::PLAYER_REGULAR,
            rejectionReason: 'Wrong edition',
        ));

        $decision = $this->onlyDecision(['changeRequestId' => Uuid::fromString(PuzzleReportFixture::CHANGE_REQUEST_PENDING)]);
        self::assertSame(PuzzleModerationAction::ChangeRequestRejected, $decision->action);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $decision->decidedById->toString());
        self::assertNotNull($decision->decidedByCode);
        self::assertSame('Wrong edition', $decision->note);
    }

    public function testRejectingAMergeRequestKeepsWhereTheDecisionCameFrom(): void
    {
        $this->messageBus->dispatch(new RejectPuzzleMergeRequest(
            mergeRequestId: PuzzleReportFixture::MERGE_REQUEST_PENDING,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            rejectionReason: 'Different artwork',
            decisionSource: MergeDecisionSource::InternalApi,
        ));

        $decision = $this->onlyDecision(['mergeRequestId' => Uuid::fromString(PuzzleReportFixture::MERGE_REQUEST_PENDING)]);
        self::assertSame(PuzzleModerationAction::MergeRequestRejected, $decision->action);
        self::assertSame(MergeDecisionSource::InternalApi, $decision->source);
        self::assertSame('Different artwork', $decision->note);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function onlyDecision(array $criteria): PuzzleModerationDecision
    {
        $this->entityManager->clear();
        $decisions = $this->entityManager->getRepository(PuzzleModerationDecision::class)->findBy($criteria);
        self::assertCount(1, $decisions);

        return $decisions[0];
    }
}
