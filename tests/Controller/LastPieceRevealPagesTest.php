<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use DOMElement;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The server side of the last-piece reveal (assets/controllers/last_piece_reveal_controller.js): every unrevealed
 * badge is a puzzle stage carrying its look, its reveal endpoint and its art; the XP launch reveal deals a member's
 * achievements one card each in crescendo order (xp_launch_reveal_controller.js) with the Achievement Points each
 * brought - never XP, achievements grant none - and ends on the level and a summary.
 * The animation itself is browser-only.
 */
final class LastPieceRevealPagesTest extends WebTestCase
{
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

        $cards = $crawler->filter('#xpl-deck .xpl-card');
        self::assertCount(4, $cards, 'Every achievement on its own card, then the level');
        self::assertSame(
            ['1', 'single', '3', 'level'],
            $cards->each(static fn (Crawler $card): string => (string) $card->filter('.lpr-stage')->attr('data-last-piece-reveal-look-param')),
            'Crescendo: Bronze first, Early Adopter right before the top tier, Gold last',
        );
        self::assertSame(
            ['+5 AP', '+25 AP', '+40 AP'],
            $cards->filter('.xpl-gain')->each(static fn (Crawler $gain): string => trim($gain->text())),
        );

        // only what was not revealed before is revealed by the deck
        self::assertNull($cards->eq(0)->filter('.lpr-stage')->attr('data-last-piece-reveal-url-param'));
        self::assertSame('/en/badges/' . $supporterId . '/reveal', $cards->eq(1)->filter('.lpr-stage')->attr('data-last-piece-reveal-url-param'));

        // name, tier, the requirement it met and the Achievement Points it brought
        $gold = $cards->eq(2);
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertSame('Speed Demon (500pc)', trim($gold->filter('.xpl-name')->text()));
        self::assertSame('Gold', trim($gold->filter('.xpl-tier')->text()));
        self::assertStringContainsString($translator->trans('badges.requirement.speed_500_pieces_3'), $gold->filter('.xpl-why')->text());
        self::assertSame('+40 AP', trim($gold->filter('.xpl-gain')->text()));
        self::assertStringContainsString($translator->trans('badges.description.supporter'), $cards->eq(1)->filter('.xpl-why')->text());

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
        self::assertCount(0, $crawler->filter('[data-last-piece-reveal-url-param]'), 'Nothing gets revealed for a free player');

        $level = $crawler->filter('[data-controller="last-piece-reveal"]');
        self::assertSame('true', $level->attr('data-last-piece-reveal-autoplay-value'));
        self::assertSame('23', trim($level->filter('svg.lpr-emblem .lpr-emblem-art text')->last()->text()));
        self::assertSame('312', trim($level->filter('[data-last-piece-reveal-target="count"]')->text()));
        self::assertCount(0, $crawler->filter('.badge-art, img[src*="/img/badges/"]'), 'Which achievements is a member feature');
        self::assertStringContainsString('2 achievements are waiting', $crawler->filter('.xp-teaser')->text());
        self::assertCount(1, $crawler->filter('.xpl-summary a[href="/en/membership"]'), 'A real way to membership');
    }

    public function testWithoutAchievementsTheLevelAssemblesByItself(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $this->setXp(40, 7);

        $crawler = $browser->request('GET', '/en/my/xp-reveal');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-controller="xp-launch-reveal"]'));
        $level = $crawler->filter('[data-controller="last-piece-reveal"]');
        self::assertSame('level', $level->filter('button.lpr-stage')->attr('data-last-piece-reveal-look-param'));
        self::assertSame('Level 7', $level->filter('button.lpr-stage')->attr('data-last-piece-reveal-done-label-param'));
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
            'INSERT INTO badge (id, player_id, type, earned_at, tier, revealed_at) VALUES (:id, :player, :type, NOW(), :tier, :revealed)',
            [
                'id' => $id,
                'player' => PlayerFixture::PLAYER_ADMIN,
                'type' => $type,
                'tier' => $tier,
                'revealed' => $revealed ? date('Y-m-d H:i:s') : null,
            ],
        );

        return $id;
    }
}
