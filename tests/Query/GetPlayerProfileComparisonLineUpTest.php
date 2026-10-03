<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddComparisonSubject;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Results\ComparisonLineUp;
use SpeedPuzzling\Web\Results\ComparisonLineUpRecentSubject;
use SpeedPuzzling\Web\Tests\DataFixtures\ComparisonSubjectFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The viewer's line-ups ride on their own profile row (docs/features/player-comparison.md) - no query of their own.
 * PLAYER_WITH_STRIPE (ComparisonSubjectFixture): Solo = self, PLAYER_ADMIN, PLAYER_REGULAR; Pairs = REGULAR & PRIVATE.
 */
final class GetPlayerProfileComparisonLineUpTest extends KernelTestCase
{
    private GetPlayerProfile $getPlayerProfile;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getPlayerProfile = self::getContainer()->get(GetPlayerProfile::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheViewersOwnRowCarriesTheirLineUps(): void
    {
        $lineUp = $this->lineUpOf(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID);
        $pair = $lineUp->refsForKind(ComparisonKind::Pairs)[0];

        self::assertSame(3, $lineUp->count(), 'Everybody but the viewer');
        self::assertTrue($lineUp->hasOthers());
        self::assertSame(3, $lineUp->countForKind(ComparisonKind::Solo), 'The viewer counts against the cap');
        self::assertSame(1, $lineUp->countForKind(ComparisonKind::Pairs));
        self::assertSame(0, $lineUp->countForKind(ComparisonKind::Teams));
        self::assertTrue($lineUp->contains(ComparisonSubjectRef::player(PlayerFixture::PLAYER_ADMIN)));
        self::assertTrue($lineUp->contains(ComparisonSubjectRef::player(PlayerFixture::PLAYER_WITH_STRIPE)));
        self::assertFalse($lineUp->contains(ComparisonSubjectRef::player(PlayerFixture::PLAYER_PRIVATE)));
        self::assertSame(ComparisonSubjectFixture::STRIPE_ADMIN, $lineUp->rowIdOf(ComparisonSubjectRef::player(PlayerFixture::PLAYER_ADMIN)));
        self::assertSame(ComparisonSubjectFixture::STRIPE_PAIR, $lineUp->rowIdOf($pair));
        self::assertSame(ComparisonKind::Pairs, $lineUp->newestKind());

        // Newest first, never the viewer; a pair is a people icon without identity
        $recent = $lineUp->recent();
        self::assertSame(
            [$pair->toString(), 'p-' . PlayerFixture::PLAYER_REGULAR, 'p-' . PlayerFixture::PLAYER_ADMIN],
            array_map(static fn (ComparisonLineUpRecentSubject $subject): string => $subject->ref->toString(), $recent),
        );
        self::assertTrue($recent[0]->isTeam());
        self::assertNull($recent[0]->name);
        self::assertFalse($recent[1]->isMasked);
        self::assertSame(PlayerFixture::PLAYER_REGULAR_NAME, $recent[1]->name);
        self::assertSame('J', $recent[1]->initial());
        self::assertSame(CountryCode::cz, $recent[1]->countryCode);
        self::assertSame('Admin User', $recent[2]->name);
    }

    public function testTheNewestThreeOnly(): void
    {
        $this->messageBus()->dispatch(new AddComparisonSubject(
            PlayerFixture::PLAYER_WITH_STRIPE,
            ComparisonSubjectRef::player(PlayerFixture::PLAYER_WITH_FAVORITES)->toString(),
        ));

        $lineUp = $this->lineUpOf(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID);

        self::assertSame(4, $lineUp->count());
        self::assertCount(ComparisonLineUp::RECENT_LIMIT, $lineUp->recent());
        self::assertSame('p-' . PlayerFixture::PLAYER_WITH_FAVORITES, $lineUp->recent()[0]->ref->toString());
        self::assertSame(ComparisonKind::Solo, $lineUp->newestKind());
    }

    public function testAPrivateSubjectIsAGenericIconUntilRevealed(): void
    {
        // Added while she let PLAYER_WITH_STRIPE see her - the read side re-checks every time
        $this->insertRow(PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_PRIVATE);

        $newest = $this->lineUpOf(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID)->recent()[0];
        self::assertSame('p-' . PlayerFixture::PLAYER_PRIVATE, $newest->ref->toString());
        self::assertTrue($newest->isMasked);
        self::assertNull($newest->name);
        self::assertNull($newest->initial());
        self::assertNull($newest->avatar);
        self::assertNull($newest->countryCode);

        $this->database->executeStatement(
            'INSERT INTO private_profile_viewer (id, owner_id, viewer_id, added_at) VALUES (:id, :owner, :viewer, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'owner' => PlayerFixture::PLAYER_PRIVATE, 'viewer' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        $newest = $this->lineUpOf(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID)->recent()[0];
        self::assertFalse($newest->isMasked);
        self::assertSame('Jane Smith', $newest->name);
    }

    public function testOnlyTheViewersOwnBlockMasksTheSubject(): void
    {
        // The viewer stops seeing PLAYER_REGULAR; PLAYER_ADMIN stops seeing the viewer - which she must never notice
        $this->block(PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_REGULAR);
        $this->block(PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_STRIPE);

        $recent = $this->lineUpOf(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID)->recent();

        self::assertTrue($recent[1]->isMasked);
        self::assertNull($recent[1]->name);
        self::assertFalse($recent[2]->isMasked);
        self::assertSame('Admin User', $recent[2]->name);
        // A masked one keeps its place in the line-up - only the read side decides what it shows
        self::assertSame(3, $this->lineUpOf(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID)->count());
    }

    public function testOnlyTheViewerInTheLineUp(): void
    {
        $this->messageBus()->dispatch(new AddComparisonSubject(
            PlayerFixture::PLAYER_WITH_FAVORITES,
            ComparisonSubjectRef::player(PlayerFixture::PLAYER_WITH_FAVORITES)->toString(),
        ));

        $lineUp = $this->lineUpOf(PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID);

        self::assertSame(0, $lineUp->count());
        self::assertFalse($lineUp->hasOthers());
        self::assertSame(1, $lineUp->countForKind(ComparisonKind::Solo));
        self::assertSame([], $lineUp->recent());
        self::assertSame(ComparisonKind::Solo, $lineUp->newestKind());
    }

    public function testNothingToShowAnywhereElse(): void
    {
        self::assertSame(0, $this->getPlayerProfile->byId(PlayerFixture::PLAYER_WITH_STRIPE)->comparisonLineUp->countForKind(ComparisonKind::Solo));

        $empty = $this->getPlayerProfile->byUserId(PlayerFixture::PLAYER_PRIVATE_USER_ID)->comparisonLineUp;
        self::assertSame([], $empty->items);
        self::assertNull($empty->newestKind());
    }

    private function lineUpOf(string $userId): ComparisonLineUp
    {
        return $this->getPlayerProfile->byUserId($userId)->comparisonLineUp;
    }

    private function messageBus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }

    private function insertRow(string $ownerId, string $subjectPlayerId): void
    {
        $this->database->executeStatement(
            'INSERT INTO comparison_subject (id, player_id, subject_player_id, added_at) VALUES (:id, :owner, :subject, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'owner' => $ownerId, 'subject' => $subjectPlayerId],
        );
    }

    private function block(string $blockerId, string $blockedId): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
