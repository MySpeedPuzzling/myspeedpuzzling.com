<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * "Hide my achievements" (docs/features/opt-out.md): no achievements anywhere for anybody, the owner included - the
 * tiers keep counting, so switching it back shows everything again.
 */
final class AchievementsOptOutTest extends WebTestCase
{
    private const string MEMBER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string MEMBER_PAGE = '/en/player-achievements/' . self::MEMBER;
    private const string MEMBER_PROFILE = '/en/player-profile/' . self::MEMBER;

    public function testHideMyAchievementsRoundTripsThroughTheSettings(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $database = $browser->getContainer()->get(Connection::class);

        foreach ([true, false] as $hide) {
            $crawler = $browser->request('GET', '/en/edit-profile');
            self::assertResponseIsSuccessful();
            $form = $crawler->filter('form[name="features_options_form"]')->form();
            $checkbox = $form['features_options_form[achievementsOptedOut]'];
            self::assertInstanceOf(ChoiceFormField::class, $checkbox);
            $hide ? $checkbox->tick() : $checkbox->untick();
            $browser->submit($form);

            self::assertResponseRedirects();
            self::assertSame($hide, (bool) $database->fetchOne('SELECT achievements_opted_out FROM player WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]));
        }
    }

    public function testTheOwnerSeesNoAchievementsOnlyWhereToShowThemAgain(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $this->insertBadge(self::MEMBER, 'puzzles_solved', 1);
        $this->hideAchievements($browser);
        TestingLogin::asPlayer($browser, self::MEMBER);

        $crawler = $browser->request('GET', self::MEMBER_PROFILE);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.achievements-showcase');
        self::assertCount(0, $crawler->filter('a[href^="' . self::MEMBER_PAGE . '"]'));

        $browser->request('GET', self::MEMBER_PAGE);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#achievements-hidden-note a[href="/en/edit-profile"]');
        self::assertSelectorNotExists('.achievement-shelf');

        // The catalogue stays, without the own line
        $browser->request('GET', '/en/achievements');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.achievements-own-line');

        $browser->request('GET', '/en/my/achievement-reveals');
        self::assertResponseRedirects(self::MEMBER_PAGE);
    }

    public function testOthersSeeNoAchievementsOfAPlayerWhoHidThem(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $this->insertBadge(self::MEMBER, 'puzzles_solved', 1);
        $this->hideAchievements($browser);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::MEMBER_PROFILE);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.achievements-showcase');
        self::assertCount(0, $crawler->filter('a[href^="' . self::MEMBER_PAGE . '"]'));

        $browser->request('GET', self::MEMBER_PAGE);
        self::assertResponseRedirects(self::MEMBER_PROFILE);
    }

    public function testAPlayerWhoHidTheirAchievementsIsNotOnTheAchievementPointsBoard(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $this->insertBadge(self::MEMBER, 'puzzles_solved', 1);
        $database = $browser->getContainer()->get(Connection::class);
        $database->executeStatement('UPDATE player SET achievement_points = 10 WHERE id = :id', ['id' => self::MEMBER]);
        TestingLogin::asPlayer($browser, self::MEMBER);

        $browser->request('GET', '/en/ladder/achievement-points');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::MEMBER_PROFILE, (string) $browser->getResponse()->getContent());

        $this->hideAchievements($browser);

        $browser->request('GET', '/en/ladder/achievement-points');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::MEMBER_PROFILE, (string) $browser->getResponse()->getContent());
        self::assertSelectorTextContains('[data-testid="lb-not-on-board"]', 'You chose to hide your achievements');
    }

    private function hideAchievements(KernelBrowser $browser): void
    {
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET achievements_opted_out = true WHERE id = :id',
            ['id' => self::MEMBER],
        );
    }

    private function insertBadge(string $playerId, string $type, int $tier): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO badge (id, player_id, type, earned_at, tier, revealed_at) VALUES (:id, :playerId, :type, ' . TestClock::SQL_NOW . ', :tier, ' . TestClock::SQL_NOW . ')',
            ['id' => Uuid::uuid7()->toString(), 'playerId' => $playerId, 'type' => $type, 'tier' => $tier],
        );
    }
}
