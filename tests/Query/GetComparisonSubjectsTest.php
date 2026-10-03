<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetComparisonSubjects;
use SpeedPuzzling\Web\Results\ComparisonSubject;
use SpeedPuzzling\Web\Results\PuzzlingTeamMemberView;
use SpeedPuzzling\Web\Tests\ComparisonSeeding;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetComparisonSubjectsTest extends KernelTestCase
{
    use ComparisonSeeding;

    private GetComparisonSubjects $query;
    private string $viewer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetComparisonSubjects::class);
        $this->viewer = $this->seedPlayer('Vera Viewer', private: true, code: 'vera', userId: 'auth0|comparison-vera');
    }

    public function testPlayersAsTheViewerMaySeeThem(): void
    {
        $public = $this->seedPlayer('Paula Public', code: 'paula', country: 'cz', avatar: 'avatars/paula.jpg');
        $private = $this->seedPlayer('Pete Private', private: true);
        $revealed = $this->seedPlayer('Rita Revealed', private: true, code: 'rita');
        $blocked = $this->seedPlayer('Bob Blocked');
        $this->seedAllowList($revealed, $this->viewer);
        $this->seedBlock($this->viewer, $blocked);
        $missing = Uuid::uuid7()->toString();

        $this->signIn();

        $subjects = $this->byRef([
            ComparisonSubjectRef::player($this->viewer),
            ComparisonSubjectRef::player($public),
            ComparisonSubjectRef::player($private),
            ComparisonSubjectRef::player($revealed),
            ComparisonSubjectRef::player($blocked),
            ComparisonSubjectRef::player($missing),
        ]);

        $self = $subjects['p-' . $this->viewer];
        self::assertTrue($self->isAvailable, 'Your own private profile is never hidden from you');
        self::assertTrue($self->isViewer);
        self::assertTrue($self->isSelf());
        self::assertSame('Vera Viewer', $self->playerName);

        $paula = $subjects['p-' . $public];
        self::assertTrue($paula->isAvailable);
        self::assertFalse($paula->isViewer);
        self::assertSame(ComparisonKind::Solo, $paula->kind);
        self::assertSame('Paula Public', $paula->playerName);
        self::assertSame('PAULA', $paula->playerCode);
        self::assertSame('avatars/paula.jpg', $paula->playerAvatar);
        self::assertSame(CountryCode::cz, $paula->playerCountry);
        self::assertSame($public, $paula->playerId());

        self::assertTrue($subjects['p-' . $revealed]->isAvailable, 'On their allow list');
        self::assertSame('Rita Revealed', $subjects['p-' . $revealed]->playerName);

        foreach ([$private, $blocked, $missing] as $hidden) {
            $subject = $subjects['p-' . $hidden];
            self::assertFalse($subject->isAvailable);
            self::assertSame(ComparisonKind::Solo, $subject->kind);
            self::assertNull($subject->playerName);
            self::assertNull($subject->playerCode);
            self::assertNull($subject->playerAvatar);
            self::assertFalse($subject->isSelf());
        }
    }

    public function testABlockByTheOtherPlayerDoesNotShowHere(): void
    {
        // Same as their profile page: the blocked side must not be able to tell
        $blocker = $this->seedPlayer('Blocking Barbara');
        $this->seedBlock($blocker, $this->viewer);

        $this->signIn();

        self::assertTrue($this->byRef([ComparisonSubjectRef::player($blocker)])['p-' . $blocker]->isAvailable);
    }

    public function testPairsAndTeamsWithMaskedMembers(): void
    {
        $public = $this->seedPlayer('Paula Public', code: 'paula', country: 'cz');
        $private = $this->seedPlayer('Pete Private', private: true, code: 'pete', country: 'de');
        $pair = $this->seedTeam([$public, $private], name: 'Pals');
        $team = $this->seedTeam([$this->viewer, $private], ['Eva']);

        $this->signIn();

        $subjects = $this->byRef([ComparisonSubjectRef::team($pair), ComparisonSubjectRef::team($team)]);

        $pals = $subjects['t-' . $pair];
        self::assertTrue($pals->isAvailable);
        self::assertTrue($pals->isTeam());
        self::assertSame(ComparisonKind::Pairs, $pals->kind);
        self::assertSame('Pals', $pals->teamName);
        self::assertSame(2, $pals->teamSize);
        self::assertFalse($pals->includesViewer);
        self::assertNull($pals->playerId());
        self::assertSame(
            [['Paula Public', 'PAULA', false, 'cz'], [null, 'PETE', true, null]],
            array_map(static fn(PuzzlingTeamMemberView $member): array => [$member->playerName, $member->playerCode, $member->isPrivate, $member->playerCountry?->name], $pals->members),
        );

        $ours = $subjects['t-' . $team];
        self::assertTrue($ours->isAvailable, 'All registered members private - but the viewer is one of them');
        self::assertSame(ComparisonKind::Teams, $ours->kind);
        self::assertTrue($ours->includesViewer);
        self::assertTrue($ours->isSelf());
        self::assertNull($ours->teamName);
        self::assertSame(3, $ours->teamSize);
        self::assertSame('Vera Viewer', $ours->members[0]->playerName, 'The viewer never masks themselves');
        self::assertFalse($ours->members[0]->isPrivate);
        self::assertTrue($ours->members[1]->isPrivate);
        self::assertTrue($ours->members[2]->isGuest());
        self::assertSame('Eva', $ours->members[2]->guestName);
    }

    public function testHiddenTeams(): void
    {
        $blocked = $this->seedPlayer('Bob Blocked');
        $public = $this->seedPlayer('Paula Public');
        $private = $this->seedPlayer('Pete Private', private: true);
        $otherPrivate = $this->seedPlayer('Priscilla Private', private: true);
        $this->seedBlock($this->viewer, $blocked);

        $withBlocked = $this->seedTeam([$public, $blocked]);
        $viewerWithBlocked = $this->seedTeam([$this->viewer, $blocked]);
        $allPrivate = $this->seedTeam([$private, $otherPrivate], ['Guest']);
        $missing = Uuid::uuid7()->toString();

        $this->signIn();

        $subjects = $this->byRef([
            ComparisonSubjectRef::team($withBlocked),
            ComparisonSubjectRef::team($viewerWithBlocked),
            ComparisonSubjectRef::team($allPrivate),
            ComparisonSubjectRef::team($missing),
        ]);

        foreach ([$withBlocked, $viewerWithBlocked, $allPrivate] as $teamId) {
            $subject = $subjects['t-' . $teamId];
            self::assertFalse($subject->isAvailable, $teamId);
            self::assertNull($subject->teamName);
            self::assertNull($subject->teamSize);
            self::assertSame([], $subject->members);
            self::assertFalse($subject->includesViewer);
        }

        self::assertSame(ComparisonKind::Pairs, $subjects['t-' . $withBlocked]->kind, 'The line-up still knows where the chip goes');
        self::assertSame(ComparisonKind::Teams, $subjects['t-' . $allPrivate]->kind);
        self::assertFalse($subjects['t-' . $missing]->isAvailable);
        self::assertNull($subjects['t-' . $missing]->kind);
    }

    public function testOrderIsKeptAndDuplicatesDropped(): void
    {
        $first = $this->seedPlayer('First');
        $second = $this->seedPlayer('Second');
        $team = $this->seedTeam([$first, $second]);

        $this->signIn();

        $subjects = $this->query->byRefs([
            ComparisonSubjectRef::player($second),
            ComparisonSubjectRef::team($team),
            ComparisonSubjectRef::player($first),
            ComparisonSubjectRef::player($second),
        ], $this->viewer);

        self::assertSame(
            ['p-' . $second, 't-' . $team, 'p-' . $first],
            array_map(static fn(ComparisonSubject $subject): string => $subject->ref->toString(), $subjects),
        );
        self::assertSame([], $this->query->byRefs([], $this->viewer));
    }

    /**
     * @param list<ComparisonSubjectRef> $refs
     * @return array<string, ComparisonSubject>
     */
    private function byRef(array $refs): array
    {
        $byRef = [];

        foreach ($this->query->byRefs($refs, $this->viewer) as $subject) {
            $byRef[$subject->ref->toString()] = $subject;
        }

        return $byRef;
    }

    private function signIn(): void
    {
        TestingViewer::signIn(self::getContainer(), $this->viewer);
    }
}
