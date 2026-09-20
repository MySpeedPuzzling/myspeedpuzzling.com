<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\RenamePuzzlingTeam;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The public page of a pair/team. Its members are other players, so it follows the same two rules
 * as every player listing: private profiles are masked, hidden (blocked) players are not shown -
 * here by the page not existing, except for a member of the team, whose own history stays whole.
 */
final class PuzzlingTeamDetailControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    public function testGuestSeesThePairWithThePrivateMemberMasked(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/teams/' . $this->fixturePairId());

        $this->assertResponseIsSuccessful();

        $members = $crawler->filter('[data-testid="team-members"]')->text();
        self::assertStringContainsString(PlayerFixture::PLAYER_REGULAR_NAME, $members);
        self::assertStringContainsString('Hidden Puzzler', $members);

        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('Jane Smith', $content);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_PRIVATE, $content, 'Not even the id of a private member may leak through a link');

        // Both times of the pair
        self::assertCount(2, $crawler->filter('[data-testid="team-times"] tbody tr'));
        // Unnamed: thousands of near-identical pages are nothing for a search engine
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        // Stats are for members
        self::assertStringNotContainsString('Best times', $crawler->filter('[data-testid="team-stats"]')->text());
    }

    public function testNamedTeamIsTitledByItsNameAndIndexable(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new RenamePuzzlingTeam($this->fixturePairId(), PlayerFixture::PLAYER_REGULAR, 'Speedsters'));

        $crawler = $browser->request('GET', '/en/teams/' . $this->fixturePairId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Speedsters');
        self::assertCount(0, $crawler->filter('meta[name="robots"][content*="noindex"]'));
    }

    public function testMemberWithMembershipSeesStatsAndTheirActions(): void
    {
        $browser = self::createClient();
        $teamId = $this->teamOf(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin', 'Grandma']);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/teams/' . $teamId);
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/teams/' . $teamId);

        $this->assertResponseIsSuccessful();
        // 4 of its own (team, members, times, related teams) + 1 for the members of related teams when there are any,
        // on top of what every signed-in page runs (account, profile, header badges)
        $this->assertQueryCountAtMost($browser, 9, 'Team page');

        self::assertStringContainsString('Best times', $crawler->filter('[data-testid="team-stats"]')->text());
        self::assertStringContainsString('Grandma', $crawler->filter('[data-testid="team-members"]')->text());
        self::assertCount(1, $crawler->selectLink('Add time'));
    }

    public function testVisitorWithoutMembershipSeesNoStats(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $browser->request('GET', '/en/teams/' . $this->fixturePairId());

        $this->assertResponseIsSuccessful();
        self::assertStringNotContainsString('Best times', $crawler->filter('[data-testid="team-stats"]')->text());
        self::assertCount(0, $crawler->selectLink('Add time'), 'Not a member of this pair');
    }

    public function testTeamOfABlockedPlayerDoesNotExistForTheBlocker(): void
    {
        $browser = self::createClient();
        // PLAYER_REGULAR has blocked PLAYER_PRIVATE; this team of hers is none of his
        $teamId = $this->teamOf(PlayerFixture::PLAYER_PRIVATE_USER_ID, ['#admin']);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/teams/' . $teamId);
        $this->assertResponseStatusCodeSame(404);

        // …while the pair he formed with her himself stays part of his history
        $browser->request('GET', '/en/teams/' . $this->fixturePairId());
        $this->assertResponseIsSuccessful();
    }

    public function testRelatedTeamsAreTheSamePeopleWithSomebodyMoreOrFewer(): void
    {
        $browser = self::createClient();
        $trioId = $this->teamOf(PlayerFixture::PLAYER_REGULAR_USER_ID, ['#player2', 'Eva']);
        // Shares a member, but is neither a sub- nor a superset
        $this->teamOf(PlayerFixture::PLAYER_REGULAR_USER_ID, ['#admin']);

        $crawler = $browser->request('GET', '/en/teams/' . $this->fixturePairId());

        $related = $crawler->filter('[data-testid="related-teams"] a');
        self::assertCount(1, $related);
        self::assertStringEndsWith('/en/teams/' . $trioId, (string) $related->attr('href'));
        self::assertStringContainsString('Eva', $related->text());
        self::assertStringContainsString('Hidden Puzzler', $related->text());

        $crawler = $browser->request('GET', '/en/teams/' . $trioId);
        self::assertStringEndsWith('/en/teams/' . $this->fixturePairId(), (string) $crawler->filter('[data-testid="related-teams"] a')->attr('href'));
    }

    public function testMemberNameOpensTheirProfileNarrowedToThisPair(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/teams/' . $this->fixturePairId());

        $link = $crawler->filter('[data-testid="team-members"]')->selectLink(PlayerFixture::PLAYER_REGULAR_NAME);
        self::assertCount(1, $link);
        self::assertStringContainsString('team=' . $this->fixturePairId(), (string) $link->attr('href'));
        self::assertStringContainsString('category=duo', (string) $link->attr('href'));

        $crawler = $browser->click($link->link());
        $this->assertResponseIsSuccessful();

        // The pair is preselected in the profile's filter, and its results link back to the pair page
        self::assertSame($this->fixturePairId(), $crawler->filter('select[data-model="team"] option[selected]')->attr('value'));
        self::assertGreaterThan(0, $crawler->filter('[data-testid="team-link"][href$="/en/teams/' . $this->fixturePairId() . '"]')->count());
    }

    public function testNonsenseInTheProfileTeamFilterIsIgnored(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR . '?team=%27%20OR%201=1');

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('select[data-model="team"] option[selected][value!=""]'));
    }

    public function testUnknownTeamIsNotFound(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/teams/not-a-uuid');
        $this->assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/teams/018d0000-0000-0000-0000-00000000dead');
        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function teamOf(string $userId, array $groupPlayers): string
    {
        $timeId = Uuid::uuid7();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: PuzzleFixture::PUZZLE_1500_01,
            competitionId: null,
            time: '03:00:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        $teamId = self::getContainer()->get(Connection::class)->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId->toString()]);
        self::assertIsString($teamId);

        return $teamId;
    }

    private function fixturePairId(): string
    {
        $teamId = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_12],
        );
        self::assertIsString($teamId);

        return $teamId;
    }
}
