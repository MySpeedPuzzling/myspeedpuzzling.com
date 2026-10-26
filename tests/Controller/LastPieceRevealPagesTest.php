<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use DOMElement;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The server side of the last-piece reveal (assets/controllers/last_piece_reveal_controller.js): every unrevealed
 * badge is a puzzle stage carrying its look, its reveal endpoint and its art; the XP launch reveal deals a member's
 * achievements one card each in crescendo order (xp_launch_reveal_controller.js) with their facts and the Achievement
 * Points each brought - never XP, achievements grant none - led by the level card unless the level was revealed in
 * the launch modal, and ends on a summary. Nothing offers a sound switch (the sound is always on).
 * The animation itself is browser-only.
 */
final class LastPieceRevealPagesTest extends WebTestCase
{
    use QueryCountAssertions;

    public function testEveryUnrevealedBadgeIsAPuzzleCarryingItsArtAndRevealEndpoint(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement('DELETE FROM badge WHERE player_id = :player', ['player' => PlayerFixture::PLAYER_ADMIN]);
        $goldId = $this->earn('speed_500_pieces', 3, revealed: false);
        $supporterId = $this->earn('supporter', null, revealed: false);
        $this->earn('streak', 2, revealed: true);

        $crawler = $browser->request('GET', '/en/my/achievement-reveals');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller="last-piece-reveal"]'));
        $stages = $crawler->filter('button.lpr-stage[data-last-piece-reveal-target="stage"]');
        self::assertCount(2, $stages, 'The revealed badge is not offered again');

        $looks = [];

        foreach ($stages as $stage) {
            self::assertInstanceOf(DOMElement::class, $stage);
            $looks['look-' . $stage->getAttribute('data-last-piece-reveal-look-param')] = $stage;
            // the unrevealed look is the CSS tray: nothing is built before the controller plays
            self::assertSame(1, $stage->getElementsByTagName('span')->length);
        }

        self::assertSame(['look-3', 'look-single'], array_keys($looks), 'One puzzle per achievement, in the page order');

        $gold = $looks['look-3'] ?? null;
        self::assertInstanceOf(DOMElement::class, $gold);
        self::assertSame('/en/badges/' . $goldId . '/reveal', $gold->getAttribute('data-last-piece-reveal-url-param'));
        self::assertMatchesRegularExpression('~^/img/badges/webp/320/speed_500_pieces-t3\.webp\?v=\w+$~', $gold->getAttribute('data-last-piece-reveal-image-param'));
        self::assertMatchesRegularExpression('~^/img/badges/webp/480/speed_500_pieces-t3\.webp\?v=\w+$~', $gold->getAttribute('data-last-piece-reveal-image-large-param'));
        self::assertSame('Speed Demon (500pc): Gold', $gold->getAttribute('data-last-piece-reveal-done-label-param'));

        $supporter = $looks['look-single'] ?? null;
        self::assertInstanceOf(DOMElement::class, $supporter);
        self::assertSame('/en/badges/' . $supporterId . '/reveal', $supporter->getAttribute('data-last-piece-reveal-url-param'));
        self::assertStringContainsString('/supporter-single.webp?v=', $supporter->getAttribute('data-last-piece-reveal-image-param'));

        // the tier is in the live region, hidden until the last piece clicks in
        $result = $crawler->filter('.lpr-result-text');
        self::assertCount(1, $result, 'A single-tier achievement has no tier to announce');
        self::assertNotNull($result->attr('hidden'));
        self::assertStringContainsString('Gold', $result->text());
    }

    public function testAMemberMeetsEveryAchievementOnItsOwnCardInCrescendoOrder(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $this->setXp(500, 12);
        $this->earnUpTo('speed_500_pieces', 3);                       // 5 + 10 + 25 AP
        $supporterId = $this->earn('supporter', null, revealed: false); // 25 AP
        $this->earn('streak', 1, revealed: true);                     // 5 AP, revealed before

        $crawler = $browser->request('GET', '/en/my/xp-reveal');

        self::assertResponseIsSuccessful();
        $deck = $crawler->filter('[data-controller="xp-launch-reveal"]');
        self::assertCount(1, $deck);
        self::assertSame('500 XP', trim($crawler->filter('.xpl-counter')->text()), 'The XP from puzzles - achievements add none');
        self::assertCount(0, $crawler->filter('[data-last-piece-reveal-target="soundToggle"], .lpr-sound'), 'The sound is always on');

        $cards = $crawler->filter('#xpl-deck .xpl-card');
        self::assertCount(4, $cards, 'The level was not revealed in the modal: it leads, then every achievement on its own card');
        self::assertSame(
            ['level', '1', 'single', '3'],
            $cards->each(static fn (Crawler $card): string => (string) $card->filter('.lpr-stage')->attr('data-last-piece-reveal-look-param')),
            'The level first; crescendo: Bronze, Early Adopter right before the top tier, Gold last',
        );
        self::assertSame('/-/xp-launch-level-revealed', $cards->eq(0)->filter('.lpr-stage')->attr('data-last-piece-reveal-url-param'));
        self::assertSame(
            ['', '1 / 3', '2 / 3', '3 / 3'],
            $cards->each(static fn (Crawler $card): string => (string) $card->attr('data-xpl-progress')),
            'The counter counts the achievements only',
        );
        self::assertSame(
            ['On to your achievements', 'Next', 'Next', 'See everything'],
            $cards->each(static fn (Crawler $card): string => (string) $card->attr('data-xpl-next')),
        );
        self::assertSame(
            ['+5 AP', '+25 AP', '+40 AP'],
            $cards->filter('.xpl-gain')->each(static fn (Crawler $gain): string => trim($gain->text())),
        );

        // only what was not revealed before is revealed by the deck
        self::assertNull($cards->eq(1)->filter('.lpr-stage')->attr('data-last-piece-reveal-url-param'));
        self::assertSame('/en/badges/' . $supporterId . '/reveal', $cards->eq(2)->filter('.lpr-stage')->attr('data-last-piece-reveal-url-param'));

        // name, tier, the requirement it met, how many hold it and the Achievement Points it brought
        $gold = $cards->eq(3);
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertSame('Speed Demon (500pc)', trim($gold->filter('.xpl-name')->text()));
        self::assertSame('Gold', trim($gold->filter('.xpl-tier b')->text()));
        self::assertCount(3, $gold->filter('.xpl-tier .achievement-pips i.is-held'));
        self::assertStringContainsString($translator->trans('badges.requirement.speed_500_pieces_3'), $gold->filter('.xpl-why')->text());
        self::assertSame('+40 AP', trim($gold->filter('.xpl-gain')->text()));
        self::assertStringContainsString('Held by', $gold->filter('.xpl-facts')->text());

        $supporter = $cards->eq(2);
        self::assertStringContainsString($translator->trans('badges.description.supporter'), $supporter->filter('.xpl-why')->text());
        self::assertStringContainsString($translator->trans('badges.medal.no_longer_earnable'), $supporter->filter('.xpl-facts')->text());
        self::assertStringContainsString($translator->trans('xp.reveal.card.highlight.thanks'), $supporter->filter('.xpl-highlight')->text());

        // the summary is the end state - and what a browser without JavaScript shows
        $summary = $crawler->filter('.xpl-summary');
        self::assertNotNull($summary->attr('hidden'));
        self::assertCount(3, $summary->filter('.xpl-grid-item'));
        self::assertSame('500 XP', trim($summary->filter('.xpl-total')->text()));
    }

    public function testAFreePlayerGetsTheLevelAndHowManyAchievementsWaitButNeverWhich(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        self::getContainer()->get(Connection::class)->executeStatement(
            'DELETE FROM membership WHERE player_id = :player',
            ['player' => PlayerFixture::PLAYER_ADMIN],
        );
        $this->setXp(312, 23);
        $this->earnUpTo('streak', 2);
        $this->earn('supporter', null, revealed: false);

        $crawler = $browser->request('GET', '/en/my/xp-reveal');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-controller="xp-launch-reveal"]'), 'The achievement detail is a member feature');
        self::assertCount(0, $crawler->filter('[data-last-piece-reveal-url-param*="/badges/"]'), 'No achievement gets revealed for a free player');

        // the level not revealed yet: the level's moment - a tap starts it, so the sound can play
        $level = $crawler->filter('[data-controller="last-piece-reveal xp-level-reveal"]');
        self::assertCount(1, $level);
        self::assertNull($level->attr('data-last-piece-reveal-autoplay-value'), 'Tap-started, never by itself');
        self::assertSame('/-/xp-launch-level-revealed', $level->filter('button.lpr-stage')->attr('data-last-piece-reveal-url-param'));
        self::assertCount(1, $level->filter('button[data-action="xp-level-reveal#reveal"]'));
        self::assertSame('23', trim($level->filter('svg.lpr-emblem .lpr-emblem-art text')->last()->text()));
        self::assertSame('312', trim($level->filter('[data-last-piece-reveal-target="count"]')->text()));
        self::assertCount(0, $crawler->filter('.badge-art, img[src*="/img/badges/"]'), 'Which achievements is a member feature');
        self::assertStringContainsString('2 achievements are waiting', $crawler->filter('.xp-teaser')->text());
        self::assertCount(1, $crawler->filter('.xpl-level-after a[href="/en/membership"]'), 'A real way to membership');
    }

    public function testAFreePlayerWhoRevealedTheLevelInTheModalGetsTheLevelAsItStands(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement('DELETE FROM membership WHERE player_id = :player', ['player' => PlayerFixture::PLAYER_ADMIN]);
        $this->setXp(40, 7);
        $this->levelRevealedInTheModal();

        $crawler = $browser->request('GET', '/en/my/xp-reveal');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.lpr-stage'), 'Not the same moment again');
        self::assertSame('Level 7, and counting', trim($crawler->filter('h1')->text()));
        self::assertCount(1, $crawler->filter('svg.xpl-medallion'));
        self::assertCount(1, $crawler->filter('a[href="/en/membership"].btn-outline-primary'));
    }

    public function testWithoutAchievementsTheLevelIsRevealedWithATap(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $this->setXp(40, 7);

        $crawler = $browser->request('GET', '/en/my/xp-reveal');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-controller="xp-launch-reveal"]'));
        $level = $crawler->filter('[data-controller="last-piece-reveal xp-level-reveal"]');
        self::assertSame('level', $level->filter('button.lpr-stage')->attr('data-last-piece-reveal-look-param'));
        self::assertSame('Level 7', $level->filter('button.lpr-stage')->attr('data-last-piece-reveal-done-label-param'));
    }

    public function testAfterTheModalRevealedTheLevelTheDeckIsTheAchievementsOnly(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $this->setXp(500, 12);
        $this->earnUpTo('puzzles_solved', 2);
        $this->earn('streak', 1, revealed: false);
        $this->levelRevealedInTheModal();

        $crawler = $browser->request('GET', '/en/my/xp-reveal');

        self::assertResponseIsSuccessful();
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertSame($translator->trans('xp.reveal.achievements_title'), trim($crawler->filter('h1')->text()), 'The achievements\' chapter, not the modal\'s words again');
        $cards = $crawler->filter('#xpl-deck .xpl-card');
        self::assertCount(0, $crawler->filter('#xpl-deck .xpl-card-level'), 'The level was the modal\'s moment');
        self::assertSame(['1 / 2', '2 / 2'], $cards->each(static fn (Crawler $card): string => (string) $card->attr('data-xpl-progress')));
        self::assertSame(['Next', 'See everything'], $cards->each(static fn (Crawler $card): string => (string) $card->attr('data-xpl-next')));
        // the summary still holds everything, the level included
        self::assertCount(1, $crawler->filter('.xpl-summary svg.xpl-medallion'));
    }

    public function testFromTheModalsButtonThePageOpensOnTheFirstCard(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $this->setXp(500, 12);
        $this->earnUpTo('puzzles_solved', 2);
        $this->earn('streak', 1, revealed: false);
        $this->levelRevealedInTheModal();

        // a direct visit (the newsletter, a bookmark): the intro and its button first, the deck closed
        $crawler = $browser->request('GET', '/en/my/xp-reveal');
        self::assertNull($crawler->filter('.xpl-intro')->attr('hidden'));
        self::assertNotNull($crawler->filter('#xpl-deck')->attr('hidden'));
        self::assertNull($crawler->filter('[data-controller="xp-launch-reveal"]')->attr('data-xp-launch-reveal-autostart-value'));

        // the modal's "Reveal my achievements" (?start=1): no second button to the same thing - the first card at once
        $crawler = $browser->request('GET', '/en/my/xp-reveal?start=1');
        self::assertResponseIsSuccessful();
        self::assertNotNull($crawler->filter('.xpl-intro')->attr('hidden'));
        self::assertNull($crawler->filter('#xpl-deck')->attr('hidden'));
        self::assertSame('true', $crawler->filter('[data-controller="xp-launch-reveal"]')->attr('data-xp-launch-reveal-autostart-value'));
        $cards = $crawler->filter('#xpl-deck .xpl-card');
        self::assertSame(
            [false, true],
            $cards->each(static fn (Crawler $card): bool => $card->attr('hidden') !== null),
            'The first card is open before the script arrives, the rest wait',
        );
    }

    public function testTheDeckCostsTheSameStatementsWhateverItsSize(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $this->setXp(500, 12);
        $this->earn('streak', 1, revealed: false);

        $browser->request('GET', '/en/my/xp-reveal');  // warms the cached rarity aggregate
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/my/xp-reveal');
        $small = $this->queryCount($browser);

        $this->earnUpTo('puzzles_solved', 3);
        $this->earnUpTo('brand_explorer', 5);
        $this->earnUpTo('speed_500_pieces', 2);
        $this->earn('supporter', null, revealed: false);
        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/my/xp-reveal');

        self::assertSame($small, $this->queryCount($browser), 'One read of the badge rows and one stats snapshot for any number of cards');
        // 2026-10-11: 18 = the page chrome 5, the XP profile, the badge rows, the shelf's own stats snapshot (9 - the
        // progress to the next tier), the rarity aggregate (cached 15 minutes outside tests) and the "level revealed"
        // lookup. Once per player; a free player pays neither the snapshot nor the rarity
        $this->assertQueryCountAtMost($browser, 18, 'The launch reveal of a member');
    }

    public function testRevealingTheLevelIsTheLaunchModalsSeenReport(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $database = self::getContainer()->get(Connection::class);

        $browser->request('POST', '/-/xp-launch-level-revealed');
        self::assertResponseStatusCodeSame(204);

        $seen = $database->fetchOne(
            "SELECT seen_at IS NOT NULL FROM player_modal_impression WHERE player_id = :player AND modal = 'xp_launch_reveal'",
            ['player' => PlayerFixture::PLAYER_ADMIN],
        );
        self::assertTrue((bool) $seen, 'It confirms the claimed impression');

        $browser->request('GET', '/-/xp-launch-level-revealed');
        self::assertResponseStatusCodeSame(405);
    }

    public function testTheRevealStaysViewableAndUsesTheLaunchModalUp(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $this->setXp(40, 7);
        self::getContainer()->get(Connection::class)->executeStatement(
            'DELETE FROM player_modal_impression WHERE player_id = :player',
            ['player' => PlayerFixture::PLAYER_ADMIN],
        );

        $browser->request('GET', '/en/my/xp-reveal');
        self::assertResponseIsSuccessful();
        $browser->request('GET', '/en/my/xp-reveal');
        self::assertResponseIsSuccessful('Seen before - still there, the newsletter link never dead-ends');

        $impressions = self::getContainer()->get(Connection::class)->fetchOne(
            "SELECT COUNT(*) FROM player_modal_impression WHERE player_id = :player AND modal = 'xp_launch_reveal'",
            ['player' => PlayerFixture::PLAYER_ADMIN],
        );
        self::assertSame(1, is_numeric($impressions) ? (int) $impressions : -1, 'The modal never offers a reveal already seen');
    }

    public function testAGuestIsSentThroughTheSignInAndBack(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/my/xp-reveal');

        self::assertResponseRedirects();
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringContainsString('/en/my/xp-reveal', rawurldecode($location));
        self::assertStringContainsString('return=', $location);
    }

    public function testAPlayerWhoSwitchedXpOffGoesToTheirProfile(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET experience_system_opted_out = true WHERE id = :player',
            ['player' => PlayerFixture::PLAYER_ADMIN],
        );

        $browser->request('GET', '/en/my/xp-reveal');

        self::assertResponseRedirects();
    }

    /**
     * What the level's puzzle in the launch modal reports once its last piece is in (XpLaunchLevelRevealedController)
     */
    private function levelRevealedInTheModal(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE player_modal_impression SET seen_at = " . TestClock::SQL_NOW . " WHERE player_id = :player AND modal = 'xp_launch_reveal'",
            ['player' => PlayerFixture::PLAYER_ADMIN],
        );
    }

    private function setXp(int $xp, int $level): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET xp_total = :xp, level = :level WHERE id = :player',
            ['xp' => $xp, 'level' => $level, 'player' => PlayerFixture::PLAYER_ADMIN],
        );
    }

    /**
     * The tier and every one below it, as the evaluator gap-fills them.
     */
    private function earnUpTo(string $type, int $tier): void
    {
        foreach (range(1, $tier) as $earnedTier) {
            $this->earn($type, $earnedTier, revealed: false);
        }
    }

    private function earn(string $type, null|int $tier, bool $revealed): string
    {
        $id = Uuid::uuid7()->toString();

        self::getContainer()->get(Connection::class)->executeStatement(
            'INSERT INTO badge (id, player_id, type, earned_at, tier, revealed_at) VALUES (:id, :player, :type, ' . TestClock::SQL_NOW . ', :tier, :revealed)',
            [
                'id' => $id,
                'player' => PlayerFixture::PLAYER_ADMIN,
                'type' => $type,
                'tier' => $tier,
                'revealed' => $revealed ? TestClock::sqlParameter() : null,
            ],
        );

        return $id;
    }
}
