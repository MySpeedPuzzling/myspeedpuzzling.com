<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PartnerFinder;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\EventAttendance;
use SpeedPuzzling\Web\Results\EventRegistration;
use SpeedPuzzling\Web\Results\PartnerCardData;
use SpeedPuzzling\Web\Results\PartnerFinderCard;
use SpeedPuzzling\Web\Results\PartnerFinderOffer;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerFinderCardResolver;
use SpeedPuzzling\Web\Value\PartnerCardState;
use SpeedPuzzling\Web\Value\PartnerKind;
use SpeedPuzzling\Web\Value\RegistrationAvailability;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * The card's four states (docs/features/partner-finder.md "Part B", D8)
 */
final class PartnerFinderCardResolverTest extends TestCase
{
    public function testAGuestGetsNoCard(): void
    {
        self::assertNull(PartnerFinderCardResolver::stateFor(null, self::going()));
    }

    public function testGoingNotListedIsInvitedEvenWithNobodyListed(): void
    {
        self::assertSame(PartnerCardState::Invite, PartnerFinderCardResolver::stateFor(new PartnerCardData(0, []), self::going()));
        self::assertSame(PartnerCardState::Invite, PartnerFinderCardResolver::stateFor(new PartnerCardData(3, []), self::going()));
    }

    public function testManagedRegistrationIsInvitedWhateverTheRegistrationSays(): void
    {
        // Pairs often cannot register without a partner (D2)
        $notRegistered = new EventAttendance(isGoing: false, canChangeParticipant: false, registration: self::registration());

        self::assertSame(PartnerCardState::Invite, PartnerFinderCardResolver::stateFor(new PartnerCardData(0, []), $notRegistered));
    }

    public function testNotGoingSeesTheCardOnlyWhenSomebodyIsListed(): void
    {
        self::assertSame(PartnerCardState::Browse, PartnerFinderCardResolver::stateFor(new PartnerCardData(2, []), EventAttendance::notGoing()));
        self::assertNull(PartnerFinderCardResolver::stateFor(new PartnerCardData(0, []), EventAttendance::notGoing()));
    }

    public function testAListedPlayerGetsTheStatusLineEvenAfterClosingTheCard(): void
    {
        $listed = new PartnerCardData(2, [], ownListingId: 'listing', ownLookingFor: [PartnerKind::Pair], cardDismissed: true);

        self::assertSame(PartnerCardState::Listed, PartnerFinderCardResolver::stateFor($listed, self::going()));
        self::assertSame(PartnerCardState::Listed, PartnerFinderCardResolver::stateFor($listed, EventAttendance::notGoing()));
    }

    public function testAClosedCardStaysClosed(): void
    {
        $dismissed = new PartnerCardData(2, [], cardDismissed: true);

        self::assertNull(PartnerFinderCardResolver::stateFor($dismissed, self::going()));
        self::assertNull(PartnerFinderCardResolver::stateFor($dismissed, EventAttendance::notGoing()));
    }

    public function testAPlayerInAPairOrTeamEntryGetsNoCardAtAll(): void
    {
        $entered = new PartnerCardData(2, [], ownListingId: 'listing', viewerEntered: true);

        self::assertNull(PartnerFinderCardResolver::stateFor($entered, self::going()));
    }

    public function testTheWordingFollowsTheRoundsAndTheListing(): void
    {
        $both = new PartnerFinderCard(null, null, PartnerFinderOffer::fromRoundCategories([]));
        $pairs = new PartnerFinderCard(null, null, PartnerFinderOffer::fromRoundCategories([RoundCategory::Solo, RoundCategory::Duo]));
        $teams = new PartnerFinderCard(
            null,
            null,
            PartnerFinderOffer::fromRoundCategories([RoundCategory::Team]),
            new PartnerCardData(1, [], ownListingId: 'listing', ownLookingFor: [PartnerKind::Pair, PartnerKind::Team]),
        );

        self::assertSame('both', $both->offeredKey());
        self::assertSame('pair', $pairs->offeredKey());
        self::assertSame('team', $teams->offeredKey());
        self::assertSame('both', $teams->ownKey());
        self::assertNull($both->count(), 'a guest has no count');
        self::assertSame(1, $teams->count());
    }

    private static function going(): EventAttendance
    {
        return new EventAttendance(isGoing: true, canChangeParticipant: false);
    }

    private static function registration(): EventRegistration
    {
        return new EventRegistration(
            availability: RegistrationAvailability::Open,
            capacity: 40,
            spotsTaken: 12,
            waitlistedCount: 0,
            opensAt: null,
            closesAt: null,
            timezone: 'Europe/Prague',
            entryFeeText: null,
            paymentInstructions: null,
        );
    }
}
