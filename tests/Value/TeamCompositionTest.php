<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlersGroup;
use SpeedPuzzling\Web\Value\TeamComposition;

final class TeamCompositionTest extends TestCase
{
    private const string ANNA = '018d0000-0000-0000-0000-0000000000a1';
    private const string PETR = '018d0000-0000-0000-0000-0000000000b2';

    public function testOrderOfPeopleDoesNotMatter(): void
    {
        $one = $this->composition([self::ANNA, self::PETR, 'Grandma', 'Eva']);
        $other = $this->composition(['Eva', self::PETR, 'Grandma', self::ANNA]);

        self::assertSame($one->key, $other->key);
        self::assertSame(4, $one->size());
    }

    public function testDifferentPeopleAreDifferentTeams(): void
    {
        self::assertNotSame(
            $this->composition([self::ANNA, self::PETR])->key,
            $this->composition([self::ANNA, self::PETR, 'Eva'])->key,
        );
    }

    public function testGuestNamesIgnoreCaseAccentsAndWhitespace(): void
    {
        $key = $this->composition([self::ANNA, 'Žofie'])->key;

        self::assertSame($key, $this->composition([self::ANNA, '  zofie '])->key);
        self::assertSame($key, $this->composition([self::ANNA, 'ŽOFIE'])->key);
        self::assertNotSame($key, $this->composition([self::ANNA, 'Sofie'])->key);
    }

    public function testGuestKeepsTheSpellingItWasEnteredWith(): void
    {
        $composition = $this->composition([self::ANNA, '  Babička   Marie ']);
        $guests = array_values(array_filter($composition->members, static fn($member) => $member->playerId === null));

        self::assertCount(1, $guests);
        self::assertSame('Babička Marie', $guests[0]->guestName);
        self::assertSame('g:babicka marie', $guests[0]->memberKey);
    }

    public function testGuestNamedLikeAPlayerIsNotThatPlayer(): void
    {
        self::assertNotSame(
            $this->composition([self::ANNA, self::PETR])->key,
            $this->composition([self::ANNA, 'Petr'])->key,
        );
    }

    public function testTwoGuestsOfTheSameNameAreTwoPeople(): void
    {
        $composition = $this->composition([self::ANNA, 'Jana', 'jana']);

        self::assertSame(3, $composition->size());
        self::assertNotSame($this->composition([self::ANNA, 'Jana'])->key, $composition->key);
        // …and it still does not matter which of them was typed first
        self::assertSame($composition->key, $this->composition(['jana', self::ANNA, 'Jana'])->key);
    }

    public function testTheSameAccountListedTwiceIsOnePerson(): void
    {
        $composition = $this->composition([self::ANNA, self::PETR, self::PETR]);

        self::assertSame(2, $composition->size());
        self::assertSame($this->composition([self::ANNA, self::PETR])->key, $composition->key);
    }

    public function testPositionsFollowTheOrderOfEntry(): void
    {
        $composition = $this->composition([self::PETR, 'Eva', self::ANNA]);
        $positions = [];

        foreach ($composition->members as $member) {
            $positions[$member->playerId ?? (string) $member->guestName] = $member->position;
        }

        self::assertSame(0, $positions[self::PETR]);
        self::assertSame(1, $positions['Eva']);
        self::assertSame(2, $positions[self::ANNA]);
    }

    public function testKeyFromMemberKeysMatchesTheKeyOfTheGroup(): void
    {
        $composition = $this->composition([self::ANNA, 'Eva']);

        self::assertSame(
            $composition->key,
            TeamComposition::keyFromMemberKeys([TeamComposition::guestMemberKey('EVA'), self::ANNA]),
        );
    }

    public function testAnonymousSlotsOfAnImportedTeamAreItsOwnPeople(): void
    {
        $composition = TeamComposition::fromGroup(PuzzlersGroup::anonymous(['a:one', 'a:two', 'a:three']));

        self::assertSame(3, $composition->size());

        foreach ($composition->members as $member) {
            self::assertNull($member->playerId);
            self::assertNull($member->guestName);
            self::assertStringStartsWith('a:', $member->memberKey);
        }

        self::assertSame($composition->key, TeamComposition::keyFromMemberKeys(['a:three', 'a:one', 'a:two']));
    }

    public function testTwoAnonymousTeamsOfTheSameSizeAreTwoTeams(): void
    {
        // Nameless guests would both read g:, g:#2, ... - one team for every imported team of four
        self::assertNotSame(
            TeamComposition::fromGroup(PuzzlersGroup::anonymous(['a:1', 'a:2', 'a:3', 'a:4']))->key,
            TeamComposition::fromGroup(PuzzlersGroup::anonymous(['a:5', 'a:6', 'a:7', 'a:8']))->key,
        );
    }

    public function testAClaimedSlotIsThePlayerAndTheRestStayAnonymous(): void
    {
        $composition = TeamComposition::fromGroup(new PuzzlersGroup(null, [
            new Puzzler(self::ANNA, 'Anna', null, null, false),
            new Puzzler(null, null, null, null, false, anonymousSlot: 'a:2'),
        ]));

        $keys = array_map(static fn($member) => $member->memberKey, $composition->members);

        // Sorted by member key, like every composition
        self::assertSame([self::ANNA, 'a:2'], $keys);
    }

    /**
     * @param non-empty-list<string> $people Player ids, anything else is a guest name
     */
    private function composition(array $people): TeamComposition
    {
        $puzzlers = array_map(
            static fn(string $person): Puzzler => str_starts_with($person, '018d')
                ? new Puzzler($person, null, null, null, false)
                : new Puzzler(null, $person, null, null, false),
            $people,
        );

        return TeamComposition::fromGroup(new PuzzlersGroup(null, $puzzlers));
    }
}
