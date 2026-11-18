<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\ClaimInvitationSkip;
use SpeedPuzzling\Web\Value\ParticipantSource;

/**
 * Who may get a claim invitation (docs/features/competitions-management/participant-linking.md §3.1, §5.5, R8): an
 * address, on the list, not linked, did not say no, none in the last 24 hours.
 */
final class CompetitionParticipantClaimInvitationTest extends TestCase
{
    /**
     * @return iterable<string, array{callable(CompetitionParticipant, \DateTimeImmutable): void, null|ClaimInvitationSkip}>
     */
    public static function cases(): iterable
    {
        yield 'an address, never invited' => [static function (): void {
        }, null];
        yield 'no address' => [static function (CompetitionParticipant $participant): void {
            $participant->updateEmail(null);
        }, ClaimInvitationSkip::NoEmail];
        yield 'removed from the event' => [static function (CompetitionParticipant $participant, \DateTimeImmutable $now): void {
            $participant->softDelete($now);
        }, ClaimInvitationSkip::NotOnList];
        yield 'linked to a profile' => [static function (CompetitionParticipant $participant, \DateTimeImmutable $now): void {
            $participant->connect((new \ReflectionClass(Player::class))->newInstanceWithoutConstructor(), $now);
        }, ClaimInvitationSkip::Linked];
        yield 'said no' => [static function (CompetitionParticipant $participant, \DateTimeImmutable $now): void {
            $participant->refuseClaimInvitations($now->modify('-30 days'));
        }, ClaimInvitationSkip::SaidNo];
        yield 'invited 23 hours ago' => [static function (CompetitionParticipant $participant, \DateTimeImmutable $now): void {
            $participant->recordClaimInvitation($now->modify('-23 hours'));
        }, ClaimInvitationSkip::InvitedRecently];
        yield 'invited exactly 24 hours ago' => [static function (CompetitionParticipant $participant, \DateTimeImmutable $now): void {
            $participant->recordClaimInvitation($now->modify('-24 hours'));
        }, null];
        yield 'invited a week ago' => [static function (CompetitionParticipant $participant, \DateTimeImmutable $now): void {
            $participant->recordClaimInvitation($now->modify('-7 days'));
        }, null];
    }

    /**
     * @param callable(CompetitionParticipant, \DateTimeImmutable): void $arrange
     */
    #[DataProvider('cases')]
    public function testMayBeInvited(callable $arrange, null|ClaimInvitationSkip $expected): void
    {
        $now = TestClock::now();
        $participant = $this->participant();
        $participant->updateEmail('jane@example.com');
        $arrange($participant, $now);

        self::assertSame($expected, $participant->claimInvitationRefusal($now));
        self::assertSame($expected === null, $participant->mayBeInvited($now));
    }

    public function testTheAddressIsKeptCanonicalAndBlankMeansNone(): void
    {
        $participant = $this->participant();

        $participant->updateEmail('  Jane.Doe@Example.COM ');
        self::assertSame('jane.doe@example.com', $participant->email);

        $participant->updateEmail('   ');
        self::assertNull($participant->email);
    }

    public function testSayingNoAgainKeepsTheFirstMoment(): void
    {
        $participant = $this->participant();
        $first = TestClock::now()->modify('-2 days');

        $participant->refuseClaimInvitations($first);
        $participant->refuseClaimInvitations(TestClock::now());

        self::assertEquals($first, $participant->claimInvitationsRefusedAt);
    }

    private function participant(): CompetitionParticipant
    {
        return new CompetitionParticipant(
            id: Uuid::uuid7(),
            name: 'Jane Doe',
            country: 'us',
            competition: $this->createMock(Competition::class),
            source: ParticipantSource::Imported,
        );
    }
}
