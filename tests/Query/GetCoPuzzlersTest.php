<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Query\GetCoPuzzlers;
use SpeedPuzzling\Web\Results\PersonSuggestion;
use SpeedPuzzling\Web\Results\TeamSuggestion;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GetCoPuzzlersTest extends KernelTestCase
{
    // Every result takes another second: identical results saved within seconds would be one result saved twice
    // (docs/features/duplicate-results.md)
    private int $savedResults = 0;

    private GetCoPuzzlers $query;
    private MessageBusInterface $messageBus;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetCoPuzzlers::class);
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->now = self::getContainer()->get(ClockInterface::class)->now();
    }

    public function testPlayerWithoutGroupTimesGetsOnlyFavorites(): void
    {
        // PLAYER_WITH_FAVORITES never puzzled with anybody
        $suggestions = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertSame([], $suggestions->teams);
        self::assertNotSame([], $suggestions->people);

        foreach ($suggestions->people as $person) {
            self::assertTrue($person->isFavorite);
            self::assertSame(0, $person->timesCount);
            self::assertNotSame(PlayerFixture::PLAYER_WITH_FAVORITES, $person->playerId);
        }
    }

    public function testRecentPartnerOutranksTheOldFrequentOne(): void
    {
        $longAgo = $this->now->modify('-3 years');

        $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['Old Friend'], $longAgo);
        $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['Old Friend'], $longAgo);
        $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['Old Friend'], $longAgo);
        $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin'], $this->now->modify('-20 days'));

        $people = $this->peopleByLabel(PlayerFixture::PLAYER_WITH_STRIPE);
        $labels = array_keys($people);

        self::assertLessThan(
            array_search('Old Friend', $labels, true),
            array_search($this->adminLabel($people), $labels, true),
            'Whoever you puzzled with in the last 30 days comes first, however often you puzzled with others',
        );
        self::assertSame(3, $people['Old Friend']->timesCount);
        self::assertTrue($people['Old Friend']->isGuest());
        self::assertSame('Old Friend', $people['Old Friend']->value);
    }

    public function testRecentPartnersComeLatestFirstThenItIsTheCountThatDecides(): void
    {
        $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['Old Friend'], $this->now->modify('-3 years'));
        $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['Old Friend'], $this->now->modify('-3 years'));
        $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['Old Friend'], $this->now->modify('-3 years'));
        $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['Two Months Ago'], $this->now->modify('-60 days'));
        $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['Last Week'], $this->now->modify('-7 days'));
        $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['Today', 'Somebody'], $this->now);
        $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['Yesterday'], $this->now->modify('-1 day'));

        $suggestions = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_STRIPE);

        $labels = array_values(array_filter(
            array_map(static fn(PersonSuggestion $person): string => $person->label, $suggestions->people),
            static fn(string $label): bool => in_array($label, ['Old Friend', 'Two Months Ago', 'Last Week', 'Today', 'Yesterday'], true),
        ));
        self::assertSame(['Today', 'Yesterday', 'Last Week', 'Old Friend', 'Two Months Ago'], $labels);

        // Teams follow the same rule
        $teamFirstMembers = array_map(static fn(TeamSuggestion $team): string => $team->memberKeys[0], $suggestions->teams);
        self::assertSame(['g:today', 'g:yesterday', 'g:last week', 'g:old friend', 'g:two months ago'], $teamFirstMembers);
    }

    public function testCountsSplitPairFromTeamAndIncludeTimesTrackedByOthers(): void
    {
        $yesterday = $this->now->modify('-1 day');

        $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin'], $yesterday);
        // The same pair, tracked by the other one
        $this->addTime('auth0|admin003', ['#player4'], $yesterday);
        // A team with a guest, typed two ways
        $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin', 'Eva'], $yesterday);
        $this->addTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, [' eva', '#ADMIN'], $yesterday);

        $suggestions = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_STRIPE);

        $teams = [];
        foreach ($suggestions->teams as $team) {
            $teams[$team->size] = $team;
        }

        self::assertCount(2, $suggestions->teams);
        self::assertSame(2, $teams[2]->timesCount);
        self::assertSame([PlayerFixture::PLAYER_ADMIN], $teams[2]->memberKeys);
        self::assertSame(2, $teams[3]->timesCount);
        self::assertEqualsCanonicalizing([PlayerFixture::PLAYER_ADMIN, 'g:eva'], $teams[3]->memberKeys);
        self::assertNull($teams[3]->name);

        $people = $this->peopleByKey($suggestions->people);

        self::assertSame(4, $people[PlayerFixture::PLAYER_ADMIN]->timesCount);
        self::assertSame(2, $people[PlayerFixture::PLAYER_ADMIN]->pairTimesCount);
        self::assertSame('#ADMIN', $people[PlayerFixture::PLAYER_ADMIN]->value);
        self::assertSame(2, $people['g:eva']->timesCount);
        self::assertSame(0, $people['g:eva']->pairTimesCount);
        // Pair mode ranks by the times as a pair: Eva was never one
        self::assertSame($yesterday->format('Y-m-d'), $people['g:eva']->lastTogetherAt?->format('Y-m-d'));
        self::assertNull($people['g:eva']->pairLastTogetherAt);
        self::assertSame($yesterday->format('Y-m-d'), $people[PlayerFixture::PLAYER_ADMIN]->pairLastTogetherAt?->format('Y-m-d'));
        self::assertSame('Eva', $people['g:eva']->value, 'A guest keeps the spelling of their first time');

        self::assertArrayNotHasKey(PlayerFixture::PLAYER_WITH_STRIPE, $people, 'Nobody is their own co-puzzler');
    }

    public function testPrivateCoPuzzlerIsListedByCodeOnly(): void
    {
        // Fixtures: PLAYER_REGULAR + PLAYER_PRIVATE are a pair with two times
        $people = $this->peopleByKey($this->query->forPlayer(PlayerFixture::PLAYER_REGULAR)->people);

        self::assertArrayHasKey(PlayerFixture::PLAYER_PRIVATE, $people);
        self::assertSame('#PLAYER2', $people[PlayerFixture::PLAYER_PRIVATE]->label);
        self::assertSame('#PLAYER2', $people[PlayerFixture::PLAYER_PRIVATE]->value);
        self::assertNull($people[PlayerFixture::PLAYER_PRIVATE]->avatar);
        self::assertNull($people[PlayerFixture::PLAYER_PRIVATE]->country);
        self::assertSame(2, $people[PlayerFixture::PLAYER_PRIVATE]->pairTimesCount);
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function addTime(string $userId, array $groupPlayers, DateTimeImmutable $finishedAt): void
    {
        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: Uuid::uuid7(),
            userId: $userId,
            puzzleId: PuzzleFixture::PUZZLE_1500_01,
            competitionId: null,
            time: sprintf('03:00:%02d', $this->savedResults++),
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: $finishedAt,
            firstAttempt: false,
            unboxed: false,
        ));
    }

    /**
     * @return array<string, PersonSuggestion>
     */
    private function peopleByLabel(string $playerId): array
    {
        $people = [];

        foreach ($this->query->forPlayer($playerId)->people as $person) {
            $people[$person->label] = $person;
        }

        return $people;
    }

    /**
     * @param list<PersonSuggestion> $people
     * @return array<string, PersonSuggestion>
     */
    private function peopleByKey(array $people): array
    {
        $byKey = [];

        foreach ($people as $person) {
            $byKey[$person->key] = $person;
        }

        return $byKey;
    }

    /**
     * @param array<string, PersonSuggestion> $people
     */
    private function adminLabel(array $people): string
    {
        foreach ($people as $label => $person) {
            if ($person->playerId === PlayerFixture::PLAYER_ADMIN) {
                return $label;
            }
        }

        self::fail('The admin is not among the suggestions');
    }
}
