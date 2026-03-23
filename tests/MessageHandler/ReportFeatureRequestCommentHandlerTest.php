<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\FeatureRequestCommentReport;
use SpeedPuzzling\Web\Message\ReportFeatureRequestComment;
use SpeedPuzzling\Web\Tests\DataFixtures\FeatureRequestCommentFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\ReportStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ReportFeatureRequestCommentHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    public function testReportingComment(): void
    {
        $this->messageBus->dispatch(new ReportFeatureRequestComment(
            reporterId: PlayerFixture::PLAYER_REGULAR,
            commentId: FeatureRequestCommentFixture::COMMENT_1,
        ));

        $reports = $this->entityManager
            ->getRepository(FeatureRequestCommentReport::class)
            ->findAll();

        $found = false;
        foreach ($reports as $report) {
            if (
                $report->comment->id->toString() === FeatureRequestCommentFixture::COMMENT_1
                && $report->reporter->id->toString() === PlayerFixture::PLAYER_REGULAR
            ) {
                $found = true;
                self::assertSame(ReportStatus::Pending, $report->status);
                break;
            }
        }
        self::assertTrue($found, 'Report should be created');
    }
}
