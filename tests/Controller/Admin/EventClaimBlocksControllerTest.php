<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\ClaimEventResultSlot;
use SpeedPuzzling\Web\Message\RemoveEventResultClaim;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Tests\TrackerlessTimeSeeding;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The admin page of players blocked from claiming event results (docs/features/event-results-import/README.md "Gates"):
 * who, since when, the removals that got them there, and "Allow claiming again".
 */
final class EventClaimBlocksControllerTest extends WebTestCase
{
    use TrackerlessTimeSeeding;

    private const string PUZZLE = PuzzleFixture::PUZZLE_1000_04;

    private KernelBrowser $browser;

    private string $palsId = '';

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testAnAdminSeesABlockedPlayerWithTheirRemovalsAndAllowsThemAgain(): void
    {
        $this->blockByTwoRemovals(PlayerFixture::PLAYER_WITH_FAVORITES);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', '/admin/event-claim-blocks');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-testid="event-claim-block"]');
        self::assertCount(1, $rows);
        self::assertStringContainsString(PlayerFixture::PLAYER_WITH_FAVORITES_NAME, $rows->text());
        self::assertCount(2, $rows->filter('[data-testid="event-claim-block-removal"]'));
        self::assertStringContainsString('Wrong team', $rows->text());
        // Each removal links its result and says who removed the spot
        self::assertCount(2, $rows->filter('[data-testid="event-claim-block-removal"] a[href="/en/result/' . $this->palsId . '"]'));
        self::assertStringContainsString('Admin User', $rows->text());

        $form = $rows->filter('form[data-testid="event-claim-unblock-form"]');
        self::assertSame('/admin/event-claim-blocks/' . PlayerFixture::PLAYER_WITH_FAVORITES . '/allow', $form->attr('action'));

        $this->browser->request('POST', '/admin/event-claim-blocks/' . PlayerFixture::PLAYER_WITH_FAVORITES . '/allow', ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects('/admin/event-claim-blocks', 303);
        self::assertNull($this->database()->fetchOne('SELECT event_claims_blocked_at FROM player WHERE id = :id', ['id' => PlayerFixture::PLAYER_WITH_FAVORITES]));

        $crawler = $this->browser->followRedirect();
        self::assertCount(0, $crawler->filter('[data-testid="event-claim-block"]'));
        self::assertStringContainsString('Nobody is blocked', $crawler->text());
    }

    public function testNobodyButAnAdminAllowsClaimingAgain(): void
    {
        $this->blockByTwoRemovals(PlayerFixture::PLAYER_WITH_FAVORITES);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('POST', '/admin/event-claim-blocks/' . PlayerFixture::PLAYER_WITH_FAVORITES . '/allow', ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->database()->fetchOne('SELECT event_claims_blocked_at FROM player WHERE id = :id', ['id' => PlayerFixture::PLAYER_WITH_FAVORITES]));
    }

    private function blockByTwoRemovals(string $playerId): void
    {
        $round = $this->seedTrackerlessEvent(self::PUZZLE);
        $pals = $this->seedTrackerlessTime($round, self::PUZZLE, 'Puzzle Pals', 3600)->id->toString();
        $this->palsId = $pals;
        $bus = self::getContainer()->get(MessageBusInterface::class);

        foreach (['Wrong team', null] as $reason) {
            $bus->dispatch(new ClaimEventResultSlot($pals, $playerId));
            $bus->dispatch(new RemoveEventResultClaim($pals, $playerId, PlayerFixture::PLAYER_ADMIN, $reason));
        }

        self::assertNotNull($this->database()->fetchOne('SELECT event_claims_blocked_at FROM player WHERE id = :id', ['id' => $playerId]));
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
