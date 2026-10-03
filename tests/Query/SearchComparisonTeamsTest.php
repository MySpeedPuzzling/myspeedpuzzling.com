<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\SearchComparisonTeams;
use SpeedPuzzling\Web\Results\ComparisonTeamSearchResult;
use SpeedPuzzling\Web\Tests\ComparisonSeeding;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\ComparisonKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SearchComparisonTeamsTest extends KernelTestCase
{
    use ComparisonSeeding;

    private SearchComparisonTeams $query;
    private DateTimeImmutable $now;
    private string $viewer;
    private string $puzzle;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(SearchComparisonTeams::class);
        $this->now = self::getContainer()->get(ClockInterface::class)->now();
        $this->viewer = $this->seedPlayer('Vera Zvonková', code: 'vera', userId: 'auth0|comparison-team-search');
        $this->puzzle = $this->seedPuzzle(500);
    }

    public function testByTeamNameWithoutAccents(): void
    {
        $anna = $this->seedPlayer('Anna Quokka');
        $ben = $this->seedPlayer('Ben Quokka');
        $cleo = $this->seedPlayer('Cleo Quokka');
        $pair = $this->teamWithTimes([$anna, $ben], times: 2, name: 'Rychlé šneky');
        $team = $this->teamWithTimes([$anna, $ben, $cleo], times: 1, name: 'Rychlé šneky a Cleo');

        $this->signIn();

        $pairs = $this->query->search('rychle sneky', ComparisonKind::Pairs, $this->viewer);
        self::assertSame([$pair], $this->ids($pairs));
        self::assertSame('Rychlé šneky', $pairs[0]->name);
        self::assertSame(2, $pairs[0]->size);
        self::assertSame(ComparisonKind::Pairs, $pairs[0]->kind());
        self::assertSame('t-' . $pair, $pairs[0]->ref()->toString());
        self::assertSame(2, $pairs[0]->timesCount);
        self::assertFalse($pairs[0]->includesViewer);
        self::assertSame(['Anna Quokka', 'Ben Quokka'], array_map(static fn($member): null|string => $member->playerName, $pairs[0]->members));

        self::assertSame([$team], $this->ids($this->query->search('Rychlé', ComparisonKind::Teams, $this->viewer)));
        self::assertSame([], $this->query->search('Rychlé', ComparisonKind::Solo, $this->viewer));
        self::assertSame([], $this->query->search('  ', ComparisonKind::Pairs, $this->viewer));
    }

    public function testByMemberNameOrCodeYourOwnFirst(): void
    {
        $anna = $this->seedPlayer('Anna Wombat', code: 'wombat1');
        $ben = $this->seedPlayer('Ben Bystrý');
        $theirs = $this->teamWithTimes([$anna, $ben], times: 5);
        $ours = $this->teamWithTimes([$this->viewer, $anna], times: 1);
        $guest = $this->teamWithTimes([$anna], guests: ['Eva'], times: 3);

        $this->signIn();

        $results = $this->query->search('wombat', ComparisonKind::Pairs, $this->viewer);
        self::assertSame([$ours, $theirs, $guest], $this->ids($results), 'Yours first, then the most times');
        self::assertTrue($results[0]->includesViewer);
        self::assertFalse($results[1]->includesViewer);

        self::assertSame([$theirs], $this->ids($this->query->search('bystry', ComparisonKind::Pairs, $this->viewer)));
        self::assertSame([$ours, $theirs, $guest], $this->ids($this->query->search('#WOMBAT1', ComparisonKind::Pairs, $this->viewer)));
        self::assertSame([], $this->query->search('Eva', ComparisonKind::Pairs, $this->viewer), 'Guests are no search handle');
    }

    public function testPrivateMembersAreFoundOnlyWhenRevealedAndMaskedOtherwise(): void
    {
        $private = $this->seedPlayer('Pete Hidden', private: true, code: 'pete77');
        $public = $this->seedPlayer('Paula Seen');
        $pair = $this->teamWithTimes([$private, $public], times: 1);

        $this->signIn();

        // Not even the exact #code: it would tie a private code to their pairs
        self::assertSame([], $this->query->search('#pete77', ComparisonKind::Pairs, $this->viewer));
        self::assertSame([], $this->query->search('pete77', ComparisonKind::Pairs, $this->viewer));
        self::assertSame([], $this->query->search('Pete Hidden', ComparisonKind::Pairs, $this->viewer));

        // Found through the public partner - with the private one masked
        $results = $this->query->search('Paula Seen', ComparisonKind::Pairs, $this->viewer);
        self::assertSame([$pair], $this->ids($results));
        self::assertNull($results[0]->members[0]->playerName);
        self::assertTrue($results[0]->members[0]->isPrivate);
        self::assertSame('PETE77', $results[0]->members[0]->playerCode);
        self::assertSame('Paula Seen', $results[0]->members[1]->playerName);

        // Revealed to the viewer (allow list): found like anybody else, unmasked
        $this->seedAllowList($private, $this->viewer);
        $this->signIn();

        $results = $this->query->search('#pete77', ComparisonKind::Pairs, $this->viewer);
        self::assertSame([$pair], $this->ids($results));
        self::assertSame('Pete Hidden', $results[0]->members[0]->playerName);
        self::assertFalse($results[0]->members[0]->isPrivate);
        self::assertSame([$pair], $this->ids($this->query->search('Pete Hidden', ComparisonKind::Pairs, $this->viewer)));
    }

    public function testAPrivateViewerFindsTheirOwnPairsByTheirCode(): void
    {
        $viewer = $this->seedPlayer('Ivo Incognito', private: true, code: 'ivo-incognito', userId: 'auth0|comparison-team-search-private');
        $partner = $this->seedPlayer('Paula Partner');
        $pair = $this->teamWithTimes([$viewer, $partner], times: 1);

        TestingViewer::signIn(self::getContainer(), $viewer);

        $results = $this->query->search('#ivo-incognito', ComparisonKind::Pairs, $viewer);
        self::assertSame([$pair], $this->ids($results));
        self::assertTrue($results[0]->includesViewer);
    }

    public function testInvisibleTeamsAreNeverOffered(): void
    {
        $blocked = $this->seedPlayer('Bob Ocelot');
        $public = $this->seedPlayer('Paula Ocelot');
        $private = $this->seedPlayer('Pete Ocelot', private: true, code: 'ocelot-pete');
        $otherPrivate = $this->seedPlayer('Priscilla Ocelot', private: true);
        $this->seedBlock($this->viewer, $blocked);

        $this->teamWithTimes([$public, $blocked], times: 1, name: 'Ocelots with Bob');
        $this->teamWithTimes([$this->viewer, $blocked], times: 1, name: 'Ocelots with me and Bob');
        $this->teamWithTimes([$private, $otherPrivate], times: 1, name: 'Ocelots in private');
        $this->seedTeam([$public, $private], name: 'Ocelots without results');
        $suspiciousOnly = $this->seedTeam([$public, $otherPrivate], name: 'Ocelots suspicious');
        $this->seedTime($public, $this->puzzle, 1000, $this->now->modify('-1 day'), suspicious: true, teamId: $suspiciousOnly);
        $visible = $this->teamWithTimes([$public, $private], times: 1, name: 'Ocelots visible');

        $this->signIn();

        self::assertSame([$visible], $this->ids($this->query->search('Ocelots', ComparisonKind::Pairs, $this->viewer)));
        self::assertSame([], $this->query->search('#ocelot-pete', ComparisonKind::Pairs, $this->viewer), 'An unrevealed private code never matches');
        self::assertSame([], $this->query->search('Bob Ocelot', ComparisonKind::Pairs, $this->viewer), 'A hidden player never matches');
    }

    public function testYourOwnPairsAndTeams(): void
    {
        $anna = $this->seedPlayer('Anna');
        $ben = $this->seedPlayer('Ben');
        $blocked = $this->seedPlayer('Bob');
        $this->seedBlock($this->viewer, $blocked);

        $older = $this->teamWithTimes([$this->viewer, $anna], times: 3, daysAgo: 30);
        $newer = $this->teamWithTimes([$ben, $this->viewer], times: 1, daysAgo: 2);
        $team = $this->teamWithTimes([$this->viewer, $anna, $ben], times: 1);
        $this->teamWithTimes([$this->viewer, $blocked], times: 9);
        $this->seedTeam([$this->viewer, $ben], ['Eva']);
        $this->teamWithTimes([$anna, $ben], times: 4);

        $this->signIn();

        $pairs = $this->query->forViewer($this->viewer, ComparisonKind::Pairs);
        self::assertSame([$newer, $older], $this->ids($pairs), 'Most recently solved first');
        self::assertTrue($pairs[0]->includesViewer);
        self::assertSame(3, $pairs[1]->timesCount);
        self::assertSame($this->now->modify('-30 days')->format('Y-m-d'), $pairs[1]->lastSolvedAt->format('Y-m-d'));
        self::assertSame('Vera Zvonková', $pairs[0]->members[1]->playerName);

        self::assertSame([$team], $this->ids($this->query->forViewer($this->viewer, ComparisonKind::Teams)));
        self::assertSame([], $this->query->forViewer($this->viewer, ComparisonKind::Solo));
    }

    /**
     * @param list<string> $playerIds
     * @param list<string> $guests
     */
    private function teamWithTimes(array $playerIds, int $times, array $guests = [], null|string $name = null, int $daysAgo = 3): string
    {
        $teamId = $this->seedTeam($playerIds, $guests, $name);

        for ($i = 0; $i < $times; $i++) {
            $this->seedTime($playerIds[0], $this->puzzle, 1000 + $i, $this->now->modify('-' . ($daysAgo + $i) . ' days'), teamId: $teamId);
        }

        return $teamId;
    }

    /**
     * @param list<ComparisonTeamSearchResult> $results
     * @return list<string>
     */
    private function ids(array $results): array
    {
        return array_map(static fn(ComparisonTeamSearchResult $result): string => $result->teamId, $results);
    }

    private function signIn(): void
    {
        TestingViewer::signIn(self::getContainer(), $this->viewer);
    }
}
