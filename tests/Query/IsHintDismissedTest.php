<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\IsHintDismissed;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\HintType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IsHintDismissedTest extends KernelTestCase
{
    private IsHintDismissed $isHintDismissed;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->isHintDismissed = self::getContainer()->get(IsHintDismissed::class);
    }

    public function testReturnsTrueWhenHintIsDismissed(): void
    {
        $result = ($this->isHintDismissed)(PlayerFixture::PLAYER_PRIVATE, HintType::MarketplaceDisclaimer);

        self::assertTrue($result);
    }

    public function testReturnsFalseWhenHintIsNotDismissed(): void
    {
        $result = ($this->isHintDismissed)(PlayerFixture::PLAYER_REGULAR, HintType::MarketplaceDisclaimer);

        self::assertFalse($result);
    }
}
