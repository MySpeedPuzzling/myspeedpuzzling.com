<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\ComparisonSubjectFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Add to comparison" / "Remove from comparison" (docs/features/player-comparison.md, D11): POSTs from the player
 * header, its ⋯ menu and the pair/team page that always answer with a redirect - back to the page they were used on.
 */
final class ComparisonEntryControllersTest extends WebTestCase
{
    private const string ADD = '/en/compare/add';
    private const string REMOVE = '/en/compare/remove';

    // The flashes base.html.twig renders at the top of <main>
    private const string FLASH = '#main-content > .container > ';

    public function testAddingAPlayerGoesBackToThePageWithAFlashThatLinksTheComparison(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $library = '/en/puzzle-library/' . PlayerFixture::PLAYER_WITH_STRIPE;

        $this->post($browser, self::ADD, ['subject' => 'p-' . PlayerFixture::PLAYER_WITH_STRIPE, 'return' => $library]);

        self::assertResponseRedirects($library);
        // The first Solo subject brings the viewer in too
        self::assertSame(
            [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_STRIPE],
            $this->soloLineUp($browser, PlayerFixture::PLAYER_ADMIN),
        );

        $crawler = $browser->followRedirect();
        $this->assertResponseIsSuccessful();

        $alert = $crawler->filter(self::FLASH . '.alert-success');
        self::assertCount(1, $alert);
        self::assertStringContainsString(PlayerFixture::PLAYER_WITH_STRIPE_NAME . ' added to your comparison.', $alert->text());
        self::assertSame('/en/compare?kind=solo', $alert->filter('a.alert-link')->attr('href'));
        self::assertSame('Open comparison', trim($alert->filter('a.alert-link')->text()));
    }

    public function testWithoutAReturnAddingOpensTheComparisonOfThatKind(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);

        $this->post($browser, self::ADD, ['subject' => 'p-' . PlayerFixture::PLAYER_WITH_STRIPE]);
        self::assertResponseRedirects('/en/compare?kind=solo');

        // Anything else than a path on this site is ignored
        $this->post($browser, self::ADD, ['subject' => 'p-' . PlayerFixture::PLAYER_WITH_FAVORITES, 'return' => 'https://evil.example/']);
        self::assertResponseRedirects('/en/compare?kind=solo');
    }

    public function testAddingAPairOpensThePairsLineUpAndSaysPair(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);

        $this->post($browser, self::ADD, ['subject' => 't-' . $this->fixturePairId($browser)]);

        self::assertResponseRedirects('/en/compare?kind=pairs');
        $count = $browser->getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM comparison_subject WHERE player_id = :owner AND subject_team_id = :team',
            ['owner' => PlayerFixture::PLAYER_ADMIN, 'team' => $this->fixturePairId($browser)],
        );
        self::assertEquals(1, $count);

        // The placeholder compare page has no layout yet - the flash shows on the next page that has one
        $crawler = $browser->request('GET', '/en/hub');
        self::assertStringContainsString('Pair added to your comparison.', $crawler->filter(self::FLASH . '.alert-success')->text());
        self::assertSame('/en/compare?kind=pairs', $crawler->filter(self::FLASH . '.alert-success a.alert-link')->attr('href'));
    }

    public function testAddingSomebodyAlreadyThereIsTheSameSuccess(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE);
        $profile = '/en/player-profile/' . PlayerFixture::PLAYER_ADMIN;

        $this->post($browser, self::ADD, ['subject' => 'p-' . PlayerFixture::PLAYER_ADMIN, 'return' => $profile]);

        self::assertResponseRedirects($profile);
        self::assertCount(3, $this->soloLineUp($browser, PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testAFullLineUpOpensTheComparisonWithTheSwapPrompt(): void
    {
        // PLAYER_REGULAR (free) is at "you + 1 other" already
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);

        $this->post($browser, self::ADD, ['subject' => 'p-' . PlayerFixture::PLAYER_ADMIN, 'return' => '/en/player-profile/' . PlayerFixture::PLAYER_ADMIN]);

        self::assertResponseRedirects('/en/compare?kind=solo&swap=p-' . PlayerFixture::PLAYER_ADMIN);
        self::assertSame(
            [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE],
            $this->soloLineUp($browser, PlayerFixture::PLAYER_REGULAR),
        );
    }

    public function testSomebodyNotAvailableIsAFlashBackOnThePage(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $profile = '/en/player-profile/' . PlayerFixture::PLAYER_PRIVATE;

        // A private player who did not allow PLAYER_ADMIN
        $this->post($browser, self::ADD, ['subject' => 'p-' . PlayerFixture::PLAYER_PRIVATE, 'return' => $profile]);
        self::assertResponseRedirects($profile);
        self::assertSame([], $this->soloLineUp($browser, PlayerFixture::PLAYER_ADMIN));

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('not available for comparing', $crawler->filter(self::FLASH . '.alert-danger')->text());

        // Nonsense is no different
        $this->post($browser, self::ADD, ['subject' => 'x-nonsense', 'return' => $profile]);
        self::assertResponseRedirects($profile);
        $this->post($browser, self::ADD, ['subject' => 'p-018d0000-0000-0000-0000-00000000dead', 'return' => $profile]);
        self::assertResponseRedirects($profile);
        self::assertSame([], $this->soloLineUp($browser, PlayerFixture::PLAYER_ADMIN));
    }

    public function testAForgedRequestAddsNothing(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $profile = '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE;

        $browser->request('POST', self::ADD, ['_token' => 'csrf-token', 'subject' => 'p-' . PlayerFixture::PLAYER_WITH_STRIPE, 'return' => $profile], server: ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseRedirects($profile);
        self::assertSame([], $this->soloLineUp($browser, PlayerFixture::PLAYER_ADMIN));
    }

    public function testGuestsAreSentToSignInAndOnlyPostsAreAccepted(): void
    {
        $browser = self::createClient();

        $this->post($browser, self::ADD, ['subject' => 'p-' . PlayerFixture::PLAYER_WITH_STRIPE]);
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', self::ADD);
        self::assertResponseStatusCodeSame(405);
        $browser->request('GET', self::REMOVE);
        self::assertResponseStatusCodeSame(405);
    }

    public function testRemovingGoesBackToThePage(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE);
        $profile = '/en/player-profile/' . PlayerFixture::PLAYER_ADMIN;

        $this->post($browser, self::REMOVE, ['subject' => 'p-' . PlayerFixture::PLAYER_ADMIN, 'return' => $profile]);

        self::assertResponseRedirects($profile);
        self::assertSame(
            [PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_REGULAR],
            $this->soloLineUp($browser, PlayerFixture::PLAYER_WITH_STRIPE),
        );

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('Removed from your comparison.', $crawler->filter(self::FLASH . '.alert-success')->text());

        // Again (a double submit, another tab): nothing left to remove is the same outcome
        $this->post($browser, self::REMOVE, ['subject' => 'p-' . PlayerFixture::PLAYER_ADMIN]);
        self::assertResponseRedirects('/en/compare');
    }

    public function testWithoutAReturnRemovingOpensTheComparisonOfThatKind(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post($browser, self::REMOVE, ['subject' => 't-' . $this->fixturePairId($browser), 'return' => '//evil.example/']);

        self::assertResponseRedirects('/en/compare?kind=pairs');
        $count = $browser->getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM comparison_subject WHERE id = :id',
            ['id' => ComparisonSubjectFixture::STRIPE_PAIR],
        );
        self::assertEquals(0, $count);
    }

    public function testWithoutAMembershipYouStayInYourOwnSoloLineUp(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);
        $hub = '/en/hub';

        $this->post($browser, self::REMOVE, ['subject' => 'p-' . PlayerFixture::PLAYER_REGULAR, 'return' => $hub]);

        self::assertResponseRedirects($hub);
        self::assertSame(
            [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE],
            $this->soloLineUp($browser, PlayerFixture::PLAYER_REGULAR),
        );

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('removing yourself is for members', $crawler->filter(self::FLASH . '.alert-warning')->text());
    }

    public function testAForgedRemoveRemovesNothing(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('POST', self::REMOVE, ['_token' => 'csrf-token', 'subject' => 'p-' . PlayerFixture::PLAYER_ADMIN], server: ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseRedirects('/en/compare');
        self::assertCount(3, $this->soloLineUp($browser, PlayerFixture::PLAYER_WITH_STRIPE));
    }

    private function signedIn(string $playerId): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);

        return $browser;
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(KernelBrowser $browser, string $url, array $fields): void
    {
        // Stateless CSRF: any token, as long as the request comes from our own origin
        $browser->request('POST', $url, ['_token' => 'csrf-token'] + $fields, server: ['HTTP_ORIGIN' => 'http://localhost']);
    }

    /**
     * @return list<string> the owner's Solo subjects, oldest first
     */
    private function soloLineUp(KernelBrowser $browser, string $ownerId): array
    {
        /** @var list<string> $ids */
        $ids = $browser->getContainer()->get(Connection::class)->fetchFirstColumn(
            'SELECT subject_player_id FROM comparison_subject WHERE player_id = :owner AND subject_player_id IS NOT NULL ORDER BY added_at, id',
            ['owner' => $ownerId],
        );

        return $ids;
    }

    private function fixturePairId(KernelBrowser $browser): string
    {
        $teamId = $browser->getContainer()->get(Connection::class)->fetchOne(
            'SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_12],
        );
        self::assertIsString($teamId);

        return $teamId;
    }
}
