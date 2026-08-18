<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\ConversationNotFound;
use SpeedPuzzling\Web\Message\ReportConversation;
use SpeedPuzzling\Web\Query\GetReports;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ReportConversationHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetReports $getReports;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->getReports = $container->get(GetReports::class);
    }

    public function testCreatingReport(): void
    {
        $this->messageBus->dispatch(
            new ReportConversation(
                reporterId: PlayerFixture::PLAYER_REGULAR,
                conversationId: ConversationFixture::CONVERSATION_ACCEPTED,
                reason: 'Test report reason',
            ),
        );

        $pendingReports = $this->getReports->pending();
        $found = false;
        foreach ($pendingReports as $report) {
            if ($report->reason === 'Test report reason') {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Report should be in pending reports');
    }

    public function testOnlyParticipantCanReport(): void
    {
        $this->expectException(ConversationNotFound::class);

        // PLAYER_WITH_FAVORITES is not a participant of CONVERSATION_ACCEPTED
        $this->messageBus->dispatch(
            new ReportConversation(
                reporterId: PlayerFixture::PLAYER_WITH_FAVORITES,
                conversationId: ConversationFixture::CONVERSATION_ACCEPTED,
                reason: 'Should not work',
            ),
        );
    }
}
