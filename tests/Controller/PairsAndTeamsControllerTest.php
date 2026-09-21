<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Events\PuzzlingTeamRenamed;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\PreparePuzzlingTeam;
use SpeedPuzzling\Web\MessageHandler\NotifyWhenPuzzlingTeamRenamed;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

final class PairsAndTeamsControllerTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testAnonymousVisitorIsSentToSignIn(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/pairs-and-teams');

        $this->assertResponseRedirects();
    }

    public function testPageListsPairsAndTeamsOfThePlayer(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $crawler = $browser->request('GET', '/en/pairs-and-teams');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Pairs & teams');

        // The fixture pair with PLAYER_REGULAR: two times, unnamed - titled by its member
        $cards = $crawler->filter('[data-testid="regular-teams"] .pairs-and-teams-card');
        self::assertCount(1, $cards);
        self::assertSame(PlayerFixture::PLAYER_REGULAR_NAME, trim($cards->filter('[data-testid="team-title"]')->text()));
        self::assertStringContainsString('2× together', $cards->text());
        // Results exist: no delete button, ever
        self::assertCount(0, $cards->filter('form[action$="/delete"]'));

        // No Solo on the "new pair or team" picker
        self::assertCount(0, $crawler->filter('form[name="prepare_team"] [data-mode="solo"]'));
        self::assertCount(2, $crawler->filter('form[name="prepare_team"] [role="radio"]'));

        $browser->request('GET', '/en/pairs-and-teams?show=teams');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.alert-info');
    }

    public function testEveryPairLinksToItsPageAndThePageLeadsBack(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $crawler = $browser->request('GET', '/en/pairs-and-teams');
        $card = $crawler->filter('.pairs-and-teams-card');

        // Both the title and the "Results" button
        $teamPath = '/en/teams/' . $this->fixturePairId();
        self::assertStringStartsWith($teamPath . '?', (string) $card->filter('[data-testid="team-title"] a')->attr('href'));
        self::assertStringStartsWith($teamPath . '?', (string) $card->filter('[data-testid="team-results"]')->attr('href'));

        $crawler = $browser->click($card->filter('[data-testid="team-results"]')->link());

        $this->assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-testid="team-times"] tbody tr'));

        // The back button of the team page returns to the list
        $back = $crawler->selectLink('Back to Pairs & teams');
        self::assertCount(1, $back);
        self::assertSame('/en/pairs-and-teams', $back->attr('href'));
    }

    public function testTeamWithoutResultsHasNoResultsButton(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('POST', '/en/pairs-and-teams/new', [
            '_token' => $this->token($browser),
            'group_players' => ['#ADMIN', 'Grandma'],
        ]);
        $crawler = $browser->followRedirect();

        $card = $crawler->filter('.pairs-and-teams-card');
        self::assertCount(1, $card);
        self::assertCount(0, $card->filter('[data-testid="team-results"]'));
        // The title still leads to the (empty) team page - whose back button returns to the Teams tab
        $crawler = $browser->click($card->filter('[data-testid="team-title"] a')->link());
        $this->assertResponseIsSuccessful();
        self::assertSame('/en/pairs-and-teams?show=teams', $crawler->selectLink('Back to Pairs & teams')->attr('href'));
    }

    public function testOneTimeGroupsAreFoldedAway(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        // One of the pair's two times goes to a trio: both the trio and nothing else are one-offs now
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle_solving_time SET puzzling_team_id = NULL WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_41],
        );

        $crawler = $browser->request('GET', '/en/pairs-and-teams');

        self::assertCount(0, $crawler->filter('[data-testid="regular-teams"] .pairs-and-teams-card'));
        self::assertCount(1, $crawler->filter('[data-testid="one-off-teams"] .pairs-and-teams-card'));
        self::assertStringContainsString('1 you puzzled with only once', $crawler->filter('[data-testid="one-off-teams"] summary')->text());
    }

    public function testRenamingRedirectsBackAndShowsTheName(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $crawler = $browser->request('GET', '/en/pairs-and-teams');

        $form = $crawler->filter('form[action$="/rename"]')->form(['name' => 'Speedsters']);
        $browser->submit($form);

        // A full-page form post never answers 200 - Turbo Drive would drop it
        $this->assertResponseRedirects('/en/pairs-and-teams?show=pairs');
        $crawler = $browser->followRedirect();

        self::assertSame('Speedsters', trim($crawler->filter('[data-testid="team-title"]')->text()));
        self::assertStringContainsString(PlayerFixture::PLAYER_REGULAR_NAME, $crawler->filter('.pairs-and-teams-card')->text());
    }

    public function testRenamingNeedsAValidToken(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $browser->request('POST', '/en/pairs-and-teams/' . $this->fixturePairId() . '/rename', ['name' => 'Nope', '_token' => 'wrong']);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testOutsiderCanNeitherRenameNorDelete(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $token = $this->token($browser);

        $browser->request('POST', '/en/pairs-and-teams/' . $this->fixturePairId() . '/rename', ['name' => 'Mine', '_token' => $token]);
        $this->assertResponseStatusCodeSame(403);

        $browser->request('POST', '/en/pairs-and-teams/' . $this->fixturePairId() . '/delete', ['_token' => $token]);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testTeamWithResultsCannotBeDeletedEvenByPostingDirectly(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $browser->request('POST', '/en/pairs-and-teams/' . $this->fixturePairId() . '/delete', ['_token' => $this->token($browser)]);

        $this->assertResponseStatusCodeSame(409);
    }

    public function testPreparedTeamIsListedDeletableAndOneTapAwayInTheAddForm(): void
    {
        $this->overrideFeatureFlagEnv('PAIRS_TEAMS_PICKER_PUBLIC', true);

        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('POST', '/en/pairs-and-teams/new', [
            '_token' => $this->token($browser),
            'group_players' => ['#ADMIN', 'Grandma'],
            'team_name' => 'Knitting circle',
        ]);
        $this->assertResponseRedirects('/en/pairs-and-teams?show=teams');
        $crawler = $browser->followRedirect();

        $card = $crawler->filter('.pairs-and-teams-card');
        self::assertCount(1, $card);
        self::assertSame('Knitting circle', trim($card->filter('[data-testid="team-title"]')->text()));
        self::assertStringContainsString('No time together yet', $card->text());
        self::assertCount(1, $card->filter('form[action$="/delete"]'));

        // "Add time": the form opens with the team chosen
        $crawler = $browser->click($card->selectLink('Add time')->link());
        $this->assertResponseIsSuccessful();
        self::assertSame(
            ['#ADMIN', 'Grandma'],
            $crawler->filter('input[name="group_players[]"]')->each(static fn(Crawler $input): null|string => $input->attr('value')),
        );
        self::assertSame('true', $crawler->filter('.copuzzler-switch [data-mode="team"]')->attr('aria-checked'));

        $browser->request('GET', '/en/pairs-and-teams?show=teams');
        $browser->submit($browser->getCrawler()->filter('form[action$="/delete"]')->form());
        $this->assertResponseRedirects();
        $crawler = $browser->followRedirect();
        self::assertCount(0, $crawler->filter('.pairs-and-teams-card'));
    }

    public function testSomebodyElsesTeamIsNeverFilledIn(): void
    {
        $this->overrideFeatureFlagEnv('PAIRS_TEAMS_PICKER_PUBLIC', true);

        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/puzzle-add?team=' . $this->fixturePairId());

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="group_players[]"]'));
    }

    public function testGuestsTabFixesATypoAndBringsTheResultsTogether(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $bus = self::getContainer()->get(MessageBusInterface::class);
        foreach (['Grandma', 'Grandma', 'Granma'] as $guest) {
            $bus->dispatch(new AddPuzzleSolvingTime(
                timeId: Uuid::uuid7(),
                userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
                puzzleId: PuzzleFixture::PUZZLE_1500_01,
                competitionId: null,
                time: '05:00:00',
                comment: null,
                finishedPuzzlesPhoto: null,
                groupPlayers: [$guest],
                finishedAt: null,
                firstAttempt: false,
                unboxed: false,
            ));
        }

        $crawler = $browser->request('GET', '/en/pairs-and-teams?show=guests');
        $this->assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.pairs-and-teams-guest'));

        $form = $crawler->filter('.pairs-and-teams-guest[data-guest-key="g:granma"] form')->form(['name' => 'Grandma']);
        $browser->submit($form);

        $this->assertResponseRedirects('/en/pairs-and-teams?show=guests');
        $crawler = $browser->followRedirect();

        $guests = $crawler->filter('.pairs-and-teams-guest');
        self::assertCount(1, $guests);
        self::assertSame('Grandma', trim($guests->filter('[data-testid="guest-name"]')->text()));
        self::assertStringContainsString('3× together', $guests->text());

        // …and one pair instead of two
        $crawler = $browser->request('GET', '/en/pairs-and-teams');
        self::assertCount(1, $crawler->filter('.pairs-and-teams-card'));
    }

    public function testGuestCannotBeRenamedToSomethingThatReadsAsAPlayerCode(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('POST', '/en/pairs-and-teams/guests/rename', ['_token' => $this->token($browser), 'guest' => 'g:grandma', 'name' => '#admin']);

        $this->assertResponseRedirects('/en/pairs-and-teams?show=guests');
        $browser->followRedirect();
        $this->assertSelectorTextContains('body', 'It cannot start with #');
    }

    public function testPlayerWithoutGuestsHasNoGuestsTab(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $crawler = $browser->request('GET', '/en/pairs-and-teams');

        self::assertCount(0, $crawler->filter('a[href$="show=guests"]'));
    }

    public function testArchivedPairLeavesTheShortcutsAndComesBackOnRequest(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $crawler = $browser->request('GET', '/en/pairs-and-teams');
        $browser->submit($crawler->filter('[data-testid="regular-teams"] [data-testid="team-archive"]')->form());

        $this->assertResponseRedirects('/en/pairs-and-teams?show=pairs');
        $crawler = $browser->followRedirect();

        // Off the list, into the folded "Archived" section - with its results and its page intact
        self::assertCount(0, $crawler->filter('[data-testid="regular-teams"] .pairs-and-teams-card'));
        $archived = $crawler->filter('[data-testid="archived-teams"] .pairs-and-teams-card');
        self::assertCount(1, $archived);
        self::assertStringContainsString('2× together', $archived->text());
        self::assertCount(1, $archived->filter('[data-testid="team-results"]'));

        // The add-time picker offers neither the pair nor - it being a pair - the person
        $browser->request('GET', '/en/my-co-puzzlers.json');
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString($this->fixturePairId(), $content);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_REGULAR, $content);

        $browser->request('GET', '/en/teams/' . $this->fixturePairId());
        $this->assertResponseIsSuccessful();

        // "Bring back"
        $crawler = $browser->request('GET', '/en/pairs-and-teams');
        $browser->submit($crawler->filter('[data-testid="archived-teams"] [data-testid="team-archive"]')->form());
        $crawler = $browser->followRedirect();

        self::assertCount(1, $crawler->filter('[data-testid="regular-teams"] .pairs-and-teams-card'));
        self::assertCount(0, $crawler->filter('[data-testid="archived-teams"]'));

        $browser->request('GET', '/en/my-co-puzzlers.json');
        self::assertStringContainsString($this->fixturePairId(), (string) $browser->getResponse()->getContent());
    }

    public function testNobodyPickedIsToldSoWithoutAnError(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('POST', '/en/pairs-and-teams/new', ['_token' => $this->token($browser), 'group_players' => []]);

        $this->assertResponseRedirects();
        $browser->followRedirect();
        $this->assertSelectorTextContains('body', 'Pick at least one person');
    }

    public function testRenameNotificationIsListedWithWhoDidIt(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $container = self::getContainer();
        $container->get(MessageBusInterface::class)->dispatch(new PreparePuzzlingTeam(PlayerFixture::PLAYER_REGULAR, ['#player2'], 'Speedsters'));
        ($container->get(NotifyWhenPuzzlingTeamRenamed::class))(new PuzzlingTeamRenamed(Uuid::fromString($this->fixturePairId()), Uuid::fromString(PlayerFixture::PLAYER_REGULAR)));
        $container->get(EntityManagerInterface::class)->flush();

        $browser->request('GET', '/en/notifications');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', PlayerFixture::PLAYER_REGULAR_NAME . ' named your pair/team “Speedsters”.');
        // …and leads to the pair itself
        $this->assertSelectorExists('a[href^="/en/teams/' . $this->fixturePairId() . '?"]');
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

    private function token(KernelBrowser $browser): string
    {
        $crawler = $browser->request('GET', '/en/pairs-and-teams');
        $token = $crawler->filter('form[name="prepare_team"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        return $token;
    }
}
