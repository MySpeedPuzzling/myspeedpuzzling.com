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

    public function testSuspiciousTimeIsListedWithALabelButIsNeverABestTime(): void
    {
        $browser = self::createClient();
        $teamId = $this->teamOf(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin', 'Grandma']);
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle_solving_time SET suspicious = true WHERE puzzling_team_id = :teamId',
            ['teamId' => $teamId],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/teams/' . $teamId);

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="team-times"] tbody tr'));
        self::assertCount(1, $crawler->filter('[data-testid="team-times"] [data-testid="suspicious-badge"]'));
        self::assertStringNotContainsString('Best times', $crawler->filter('[data-testid="team-stats"]')->text());
    }

    public function testSignedInVisitorCanAddThePairToTheComparison(): void
    {
        $browser = self::createClient();
        // PLAYER_ADMIN's line-ups are empty
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', '/en/teams/' . $this->fixturePairId());

        // A one-tap link (the shared comparison_entry/_subject_form.html.twig, GEST-15 decision 99)
        $form = $crawler->filter('.team-compare > a[data-controller="post-action"]');
        self::assertCount(1, $form);
        self::assertSame('/en/compare/add', $form->attr('data-post-action-url-value'));
        self::assertSame([
            'subject' => 't-' . $this->fixturePairId(),
            'return' => '/en/teams/' . $this->fixturePairId(),
        ], json_decode((string) $form->attr('data-post-action-fields-value'), true));
        self::assertStringContainsString('Add to comparison', $form->text());
    }

    public function testPairInTheLineUpLinksItsComparison(): void
    {
        $browser = self::createClient();
        // PLAYER_WITH_STRIPE has this pair in the Pairs line-up
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/teams/' . $this->fixturePairId());

        self::assertCount(0, $crawler->filter('form.team-compare'));
        $link = $crawler->filter('a.team-compare');
        self::assertCount(1, $link);
        self::assertSame('/en/compare?kind=pairs', $link->attr('href'));
        self::assertSame('In comparison · Open', trim($link->text()));
    }

    public function testCompareIsOfferedOnlyWhereItWouldBeAccepted(): void
    {
        $browser = self::createClient();

        // Guests: nothing (no form, no session on a shared page)
        $crawler = $browser->request('GET', '/en/teams/' . $this->fixturePairId());
        self::assertCount(0, $crawler->filter('.team-compare'));

        // PLAYER_REGULAR is in this pair but blocks PLAYER_PRIVATE - the line-up would refuse it (ComparisonSubjectVisibility)
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/teams/' . $this->fixturePairId());
        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.team-compare'));
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

    /**
     * docs/features/navigation PROF-05e, decision 71: a member's name opens their profile; the pair's results are on
     * Results - a link of its own, the Results of a member the viewer may open, narrowed to this pair
     */
    public function testMemberNameOpensTheirProfileAndResultsWithThisPairTheirResults(): void
    {
        $browser = self::createClient();
        $pairId = $this->fixturePairId();
        $crawler = $browser->request('GET', '/en/teams/' . $pairId);

        $link = $crawler->filter('[data-testid="team-members"]')->selectLink(PlayerFixture::PLAYER_REGULAR_NAME);
        self::assertCount(1, $link);
        self::assertSame('/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, $link->attr('href'));

        // The private member is no choice for a guest
        $results = $crawler->filter('[data-testid="team-members"] [data-testid="team-results-link"]');
        self::assertCount(1, $results);
        self::assertSame('Results with this pair', trim($results->text()));
        self::assertSame('/en/player-results/' . PlayerFixture::PLAYER_REGULAR . '?team=' . $pairId . '&category=duo', $results->attr('href'));

        $crawler = $browser->click($results->link());
        $this->assertResponseIsSuccessful();

        // The pair is preselected in the Results filter, and its results link back to the pair page
        self::assertSame($pairId, $crawler->filter('select[name="team"] option[selected]')->attr('value'));
        self::assertGreaterThan(0, $crawler->filter('[data-testid="team-link"][href$="/en/teams/' . $pairId . '"]')->count());

        // A member gets their own Results
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $crawler = $browser->request('GET', '/en/teams/' . $pairId);
        self::assertSame('/en/player-results/' . PlayerFixture::PLAYER_PRIVATE . '?team=' . $pairId . '&category=duo', $crawler->filter('[data-testid="team-results-link"]')->attr('href'));
    }

    public function testAnOldProfileLinkNarrowedToThePairOpensTheResults(): void
    {
        $browser = self::createClient();
        $pairId = $this->fixturePairId();

        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR . '?team=' . $pairId . '&category=duo');
        self::assertResponseRedirects('/en/player-results/' . PlayerFixture::PLAYER_REGULAR . '?category=duo&team=' . $pairId, 302);

        $crawler = $browser->followRedirect();
        $this->assertResponseIsSuccessful();
        self::assertSame($pairId, $crawler->filter('select[name="team"] option[selected]')->attr('value'));
    }

    public function testNonsenseInTheProfileTeamFilterIsIgnored(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR . '?team=%27%20OR%201=1');

        // PROF-05e: the old filter goes to Results - without the nonsense
        self::assertResponseRedirects('/en/player-results/' . PlayerFixture::PLAYER_REGULAR, 302);
        $crawler = $browser->followRedirect();

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('select[name="team"] option[selected][value!=""]'));
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
