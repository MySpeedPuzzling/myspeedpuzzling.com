<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Message\DismissHint;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\HintType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The navigation pointers' dismissals ride on the viewer's own profile row (docs/features/navigation INB-05, decision
 * 52): one subselect over the hint types starting with "navigation_", so the pointers cost no query of their own.
 * PLAYER_PRIVATE has dismissed the marketplace disclaimer in the fixtures - an ordinary hint, never on the row.
 */
final class GetPlayerProfileNavigationHintsTest extends KernelTestCase
{
    public function testNothingDismissedYet(): void
    {
        self::assertSame([], $this->ownProfile()->dismissedNavigationHints);
    }

    public function testADismissedPointerIsOnTheRow(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(
            new DismissHint(PlayerFixture::PLAYER_PRIVATE, HintType::NavigationInboxPointer),
        );

        self::assertSame([HintType::NavigationInboxPointer], $this->ownProfile()->dismissedNavigationHints);
    }

    public function testOtherDismissedHintsAreNotOnTheRow(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(
            new DismissHint(PlayerFixture::PLAYER_PRIVATE, HintType::FeatureRequestsIntro),
        );

        // The fixture's marketplace disclaimer and the feature requests intro: dismissed, but not navigation pointers
        self::assertSame([], $this->ownProfile()->dismissedNavigationHints);
    }

    public function testSomebodyElsesProfileNeverCarriesThem(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(
            new DismissHint(PlayerFixture::PLAYER_PRIVATE, HintType::NavigationInboxPointer),
        );

        self::assertSame([], self::getContainer()->get(GetPlayerProfile::class)->byId(PlayerFixture::PLAYER_PRIVATE)->dismissedNavigationHints);
    }

    private function ownProfile(): PlayerProfile
    {
        return self::getContainer()->get(GetPlayerProfile::class)->byUserId(PlayerFixture::PLAYER_PRIVATE_USER_ID);
    }
}
