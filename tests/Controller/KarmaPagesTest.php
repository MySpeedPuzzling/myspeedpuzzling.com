<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\Karma\KarmaRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestDouble\FixedXpLaunchReadiness;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Karma's pages (docs/features/karma.md): the history (owner only), the explainer (public), the karma list (a tab of
 * the leaderboards) and the proposer's track record in the review queues. Karma launches with XP behind the same
 * switch - the tests run with it on for admins only, like production before the launch.
 */
final class KarmaPagesTest extends WebTestCase
{
    use QueryCountAssertions;

    public function testTheExplainerIsPublicWithoutThePersonalPart(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/how-karma-works');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/en/my/karma"]');
    }

    public function testTheHistoryNeedsASignedInPlayer(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/my/karma');

        self::assertResponseRedirects();
    }

    public function testBeforeTheLaunchOnlyAdminsReachTheHistory(): void
    {
        // Before the XP launch (the switch off): only admins see progression
        FixedXpLaunchReadiness::$ready = false;
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/my/karma');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnAdminSeesTheyHaveNoKarma(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $this->startCountingQueries($browser);

        $browser->request('GET', '/en/my/karma');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-info');
        self::assertLessThanOrEqual(2, $this->derivationRuns($browser), 'The history page derives karma at most twice (breakdown + one page of items)');
    }

    public function testThePlayerSeesWhatTheyGotTheirKarmaFor(): void
    {
        $browser = self::createClient();
        $this->launch($browser);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        // The fixtures' approved change request is PLAYER_REGULAR's
        $browser->getContainer()->get(KarmaRecalculator::class)->recalculateAll();

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/my/karma');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'What did I get my karma for?');
        self::assertSelectorExists('#karma-items li');
        self::assertSelectorTextContains('#karma-items', 'Your suggested change was accepted');
        self::assertSame(2, $this->derivationRuns($browser));

        // The chip on their own profile opens the history
        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
        self::assertSelectorExists('a.player-chip-karma[href="/en/my/karma"]');
    }

    public function testTheKarmaListLeavesOutPrivateProfilesAndAdmins(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $database = $browser->getContainer()->get(Connection::class);
        $database->executeStatement("UPDATE player SET karma = 7 WHERE id = :id", ['id' => PlayerFixture::PLAYER_WITH_STRIPE]);
        $database->executeStatement("UPDATE player SET karma = 9, is_private = true WHERE id = :id", ['id' => PlayerFixture::PLAYER_WITH_FAVORITES]);
        $database->executeStatement("UPDATE player SET karma = 11 WHERE id = :id", ['id' => PlayerFixture::PLAYER_ADMIN]);

        $browser->request('GET', '/en/players/xp-leaderboard?tab=karma');

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE, $content);
        self::assertStringNotContainsString('/en/player-profile/' . PlayerFixture::PLAYER_WITH_FAVORITES, $content);
        self::assertStringNotContainsString('<a href="/en/player-profile/' . PlayerFixture::PLAYER_ADMIN, $content);
    }

    public function testTheKarmaListFiltersByKindAndPeriod(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $this->startCountingQueries($browser);

        $browser->request('GET', '/en/players/xp-leaderboard?tab=karma&kind=fixes&period=12m');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#karma-kind-fixes:checked');
        self::assertSame(1, $this->derivationRuns($browser), 'One aggregate statement, the viewer\'s own row included');
    }

    public function testTheReviewQueuesShowTheProposersTrackRecordInOneStatement(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        foreach (['/admin/puzzle-change-requests', '/admin/puzzle-merge-requests', '/admin/puzzle-approvals'] as $url) {
            $this->startCountingQueries($browser);
            $browser->request('GET', $url);

            self::assertResponseIsSuccessful();
            $trackRecordStatements = array_filter(
                $this->executedSql($browser),
                static fn (string $sql): bool => str_contains($sql, 'WITH proposals AS'),
            );
            self::assertCount(1, $trackRecordStatements, $url);
        }

        $browser->request('GET', '/admin/puzzle-change-requests');
        self::assertSelectorExists('.karma-proposer');
    }

    public function testHideMyKarmaRoundTripsThroughTheSettings(): void
    {
        $browser = self::createClient();
        $this->launch($browser);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $database = $browser->getContainer()->get(Connection::class);

        foreach ([true, false] as $hide) {
            $crawler = $browser->request('GET', '/en/edit-profile');
            self::assertResponseIsSuccessful();
            $form = $crawler->filter('form[name="features_options_form"]')->form();
            $checkbox = $form['features_options_form[karmaOptedOut]'];
            self::assertInstanceOf(\Symfony\Component\DomCrawler\Field\ChoiceFormField::class, $checkbox);
            $hide ? $checkbox->tick() : $checkbox->untick();
            $browser->submit($form);

            self::assertResponseRedirects();
            self::assertSame($hide, (bool) $database->fetchOne('SELECT karma_opted_out FROM player WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]));
        }
    }

    public function testHiddenKarmaHasNoChipForAnybodyAndANoteOnTheOwnersHistory(): void
    {
        $browser = self::createClient();
        $this->launch($browser);
        $database = $browser->getContainer()->get(Connection::class);
        $database->executeStatement('UPDATE player SET karma = 5, karma_opted_out = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.player-chip-karma');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
        self::assertSelectorNotExists('.player-chip-karma');

        $browser->request('GET', '/en/my/karma');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#karma-hidden-note');
    }

    public function testHiddenKarmaIsLeftOutOfTheKarmaListWhateverTheFilter(): void
    {
        $browser = self::createClient();
        $this->launch($browser);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $database = $browser->getContainer()->get(Connection::class);
        // The fixtures' approved change request is PLAYER_REGULAR's - a fixes karma of their own
        $browser->getContainer()->get(KarmaRecalculator::class)->recalculateAll();
        $profileLink = '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR;

        $browser->request('GET', '/en/players/xp-leaderboard?tab=karma&kind=fixes');
        self::assertStringContainsString($profileLink, (string) $browser->getResponse()->getContent());

        $database->executeStatement('UPDATE player SET karma_opted_out = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);

        foreach (['', '&kind=fixes', '&period=12m'] as $filter) {
            $browser->request('GET', '/en/players/xp-leaderboard?tab=karma' . $filter);
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString($profileLink, (string) $browser->getResponse()->getContent(), $filter);
        }

        // Still recalculated - nothing is lost
        $karma = $database->fetchOne('SELECT karma FROM player WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);
        self::assertIsNumeric($karma);
        self::assertGreaterThan(0, (int) $karma);
    }

    private function launch(KernelBrowser $browser): void
    {
        // The tests run launched (FixedXpLaunchReadiness) - one kernel for the SQL log across requests
        $browser->disableReboot();
    }

    private function derivationRuns(KernelBrowser $browser): int
    {
        return count(array_filter(
            $this->executedSql($browser),
            static fn (string $sql): bool => str_contains($sql, 'karma_items'),
        ));
    }
}
