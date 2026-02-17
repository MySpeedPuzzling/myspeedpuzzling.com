<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\DismissHint;
use SpeedPuzzling\Web\Query\IsHintDismissed;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\HintType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class DismissHintHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private IsHintDismissed $isHintDismissed;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->isHintDismissed = self::getContainer()->get(IsHintDismissed::class);
    }

    public function testDismissesHint(): void
    {
        self::assertFalse(($this->isHintDismissed)(PlayerFixture::PLAYER_REGULAR, HintType::MarketplaceDisclaimer));

        $this->messageBus->dispatch(new DismissHint(
            playerId: PlayerFixture::PLAYER_REGULAR,
            type: HintType::MarketplaceDisclaimer,
        ));

        self::assertTrue(($this->isHintDismissed)(PlayerFixture::PLAYER_REGULAR, HintType::MarketplaceDisclaimer));
    }

    public function testDismissingAlreadyDismissedHintIsIdempotent(): void
    {
        self::assertTrue(($this->isHintDismissed)(PlayerFixture::PLAYER_PRIVATE, HintType::MarketplaceDisclaimer));

        $this->messageBus->dispatch(new DismissHint(
            playerId: PlayerFixture::PLAYER_PRIVATE,
            type: HintType::MarketplaceDisclaimer,
        ));

        self::assertTrue(($this->isHintDismissed)(PlayerFixture::PLAYER_PRIVATE, HintType::MarketplaceDisclaimer));
    }
}
