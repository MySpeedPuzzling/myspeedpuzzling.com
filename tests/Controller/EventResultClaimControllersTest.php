<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Events\EventResultSlotClaimed;
use SpeedPuzzling\Web\Message\ClaimEventResultSlot;
use SpeedPuzzling\Web\MessageHandler\NotifyWhenEventResultSlotClaimed;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Tests\TrackerlessTimeSeeding;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "This was my team", "Remove from my profile" and "Not on our team" over the web
 * (docs/features/event-results-import/README.md "Claiming a spot", "Gates"): the buttons only for whom they are, the
 * modals, a success closes the modal and refreshes the page, a refusal answers 422 in the modal, a failed CSRF check
 * changes nothing, the edit and delete links of an imported result lead to the release, the notifications render.
 */
final class EventResultClaimControllersTest extends WebTestCase
{
    use TrackerlessTimeSeeding;

    private const string SAME_ORIGIN = 'http://localhost';
    private const string PUZZLE = PuzzleFixture::PUZZLE_1000_04;
    private const array MODAL = ['HTTP_TURBO_FRAME' => 'modal-frame', 'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html', 'HTTP_ORIGIN' => self::SAME_ORIGIN];

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testTheButtonIsOfferedOnlyToASignedInPlayerWithoutASpotInTheRound(): void
    {
        [$pals] = $this->seed();
        $roundUrl = '/en/events/' . self::TRACKERLESS_EVENT_SLUG . '/results/' . self::TRACKERLESS_ROUND_SLUG;

        $this->browser->request('GET', $roundUrl);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->browser->getCrawler()->filter('[data-testid="event-result-claim-button"]'), 'Guests get no button');

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', $roundUrl);
        self::assertCount(2, $this->browser->getCrawler()->filter('[data-testid="event-result-claim-button"]'));

        $this->bus()->dispatch(new ClaimEventResultSlot($pals->id->toString(), PlayerFixture::PLAYER_REGULAR));

        // One spot per round: neither team is offered any more
        $this->browser->request('GET', $roundUrl);
        self::assertCount(0, $this->browser->getCrawler()->filter('[data-testid="event-result-claim-button"]'));
        // Partly claimed: the claimer and "+3"
        self::assertStringContainsString(PlayerFixture::PLAYER_REGULAR_NAME, (string) $this->browser->getResponse()->getContent());
        self::assertSame('+3', trim($this->browser->getCrawler()->filter('[data-testid="anonymous-more"]')->text()));

        // Somebody else still gets it on the team with a spot left
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->browser->request('GET', $roundUrl);
        self::assertCount(2, $this->browser->getCrawler()->filter('[data-testid="event-result-claim-button"]'));
    }

    public function testTheClaimModalShowsTheResultAndTheFirstTryQuestion(): void
    {
        [$pals] = $this->seed();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', '/en/event-result/' . $pals->id->toString() . '/claim', server: self::MODAL);

        self::assertResponseIsSuccessful();
        $content = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('<turbo-frame id="modal-frame">', $content);
        self::assertStringContainsString('Puzzle Pals', $content);
        self::assertStringContainsString(self::TRACKERLESS_EVENT_NAME, $content);
        self::assertCount(1, $crawler->filter('[data-testid="event-result-claim-form"]'));
        self::assertCount(1, $crawler->filter('input[name="first_try"]'));
        self::assertCount(0, $crawler->filter('[data-testid="event-result-same-result"]'), 'No own result near the event day - no question');
    }

    public function testAClaimFromTheModalClosesItAndRefreshesThePage(): void
    {
        [$pals] = $this->seed();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('POST', '/en/event-result/' . $pals->id->toString() . '/claim', ['_token' => 'csrf-token', 'first_try' => '1'], server: self::MODAL);

        self::assertResponseIsSuccessful();
        $content = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="refresh">', $content);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->trackerOf($pals));
        self::assertTrue($this->database()->fetchOne('SELECT first_attempt FROM puzzle_solving_time WHERE id = :id', ['id' => $pals->id->toString()]));
    }

    public function testAClaimWithoutTheModalLeadsToTheResult(): void
    {
        [$pals] = $this->seed();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('POST', '/en/event-result/' . $pals->id->toString() . '/claim', ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => self::SAME_ORIGIN]);

        self::assertResponseRedirects('/en/result/' . $pals->id->toString(), 303);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->trackerOf($pals));
    }

    public function testARefusedClaimIsAnsweredInTheModalWith422(): void
    {
        [$pals] = $this->seed();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $player = $entityManager->find(Player::class, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($player);
        $player->blockEventClaims(TestClock::now());
        $entityManager->flush();

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->browser->request('POST', '/en/event-result/' . $pals->id->toString() . '/claim', ['_token' => 'csrf-token'], server: self::MODAL);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('<turbo-frame id="modal-frame">', (string) $this->browser->getResponse()->getContent());
        self::assertStringContainsString('can no longer claim', $crawler->filter('[data-testid="event-result-claim-error"]')->text());
        self::assertNull($this->trackerOf($pals));
    }

    public function testAFailedCsrfCheckChangesNothing(): void
    {
        [$pals] = $this->seed();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('POST', '/en/event-result/' . $pals->id->toString() . '/claim', ['_token' => 'csrf-token'], server: [
            'HTTP_TURBO_FRAME' => 'modal-frame',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
            'HTTP_ORIGIN' => 'https://evil.example',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->trackerOf($pals));
    }

    public function testAGuestIsSentToSignIn(): void
    {
        [$pals] = $this->seed();

        $this->browser->request('GET', '/en/event-result/' . $pals->id->toString() . '/claim');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->browser->getResponse()->headers->get('Location'));
    }

    public function testAnOrdinaryResultHasNoClaimPage(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $this->browser->request('GET', '/en/event-result/' . PuzzleSolvingTimeFixture::TIME_12 . '/claim');

        self::assertResponseStatusCodeSame(404);
    }

    public function testEditAndDeleteOfAnImportedResultLeadToRemoveFromMyProfile(): void
    {
        [$pals] = $this->seed();
        $timeId = $pals->id->toString();
        $this->bus()->dispatch(new ClaimEventResultSlot($timeId, PlayerFixture::PLAYER_REGULAR));
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('GET', '/en/edit-time/' . $timeId);
        self::assertResponseRedirects('/en/event-result/' . $timeId . '/remove-from-profile');

        $this->browser->request('GET', '/en/delete-time/' . $timeId);
        self::assertResponseRedirects('/en/event-result/' . $timeId . '/remove-from-profile');
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->trackerOf($pals), 'Nothing was deleted');

        // The result detail offers no edit button, only the release
        $crawler = $this->browser->request('GET', '/en/result/' . $timeId);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href*="/edit-time/' . $timeId . '"]'));
        self::assertCount(1, $crawler->filter('[data-testid="event-result-release-link"]'));
    }

    public function testRemoveFromMyProfile(): void
    {
        [$pals] = $this->seed();
        $timeId = $pals->id->toString();
        $this->bus()->dispatch(new ClaimEventResultSlot($timeId, PlayerFixture::PLAYER_REGULAR));
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->browser->request('GET', '/en/event-result/' . $timeId . '/remove-from-profile', server: self::MODAL);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="event-result-release-form"]'));

        $this->browser->request('POST', '/en/event-result/' . $timeId . '/remove-from-profile', ['_token' => 'csrf-token'], server: self::MODAL);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<turbo-stream action="refresh">', (string) $this->browser->getResponse()->getContent());
        self::assertNull($this->trackerOf($pals));

        // Not theirs any more - nothing to release
        $this->browser->request('GET', '/en/event-result/' . $timeId . '/remove-from-profile', server: self::MODAL);
        self::assertResponseStatusCodeSame(404);
    }

    public function testNotOnOurTeamAndTheNotificationsOfBothSides(): void
    {
        [$pals] = $this->seed();
        $timeId = $pals->id->toString();
        $this->bus()->dispatch(new ClaimEventResultSlot($timeId, PlayerFixture::PLAYER_REGULAR));
        $this->bus()->dispatch(new ClaimEventResultSlot($timeId, PlayerFixture::PLAYER_WITH_FAVORITES));
        // The claim's notification is async - run it
        self::getContainer()->get(NotifyWhenEventResultSlotClaimed::class)(new EventResultSlotClaimed($pals->id, Uuid::fromString(PlayerFixture::PLAYER_WITH_FAVORITES)));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', '/en/notifications');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            PlayerFixture::PLAYER_WITH_FAVORITES_NAME . ' joined Puzzle Pals at ' . self::TRACKERLESS_EVENT_NAME,
            $this->browser->getCrawler()->filter('[data-testid="event-result-notification"]')->text(),
        );

        $crawler = $this->browser->request('GET', '/en/result/' . $timeId);
        self::assertCount(1, $crawler->filter('[data-testid="event-result-remove-link"]'), 'Next to the other claimer');

        $this->browser->request('POST', '/en/event-result/' . $timeId . '/not-on-our-team/' . PlayerFixture::PLAYER_WITH_FAVORITES, ['_token' => 'csrf-token', 'reason' => 'Not at the event'], server: self::MODAL);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<turbo-stream action="refresh">', (string) $this->browser->getResponse()->getContent());
        self::assertSame(
            'removed',
            $this->database()->fetchOne("SELECT action FROM result_claim_log WHERE solving_time_id = :id AND player_id = :player AND action = 'removed'", ['id' => $timeId, 'player' => PlayerFixture::PLAYER_WITH_FAVORITES]),
        );

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->browser->request('GET', '/en/notifications');
        $text = $this->browser->getCrawler()->filter('[data-testid="event-result-notification"]')->text();
        self::assertStringContainsString('Your spot in Puzzle Pals at ' . self::TRACKERLESS_EVENT_NAME . ' was removed', $text);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_REGULAR_NAME, $text, 'Never by whom');
    }

    public function testAStrangerGetsNoRemoveLinkAndIsRefused(): void
    {
        [$pals] = $this->seed();
        $timeId = $pals->id->toString();
        $this->bus()->dispatch(new ClaimEventResultSlot($timeId, PlayerFixture::PLAYER_REGULAR));
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', '/en/result/' . $timeId);
        self::assertCount(0, $crawler->filter('[data-testid="event-result-remove-link"]'));
        self::assertCount(1, $crawler->filter('[data-testid="event-result-claim-button"]'));

        $this->browser->request('POST', '/en/event-result/' . $timeId . '/not-on-our-team/' . PlayerFixture::PLAYER_REGULAR, ['_token' => 'csrf-token'], server: self::MODAL);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->trackerOf($pals));
    }

    /**
     * Two anonymous teams of four in the round: Puzzle Pals 1:00:00, Corner Crew 50:00
     *
     * @return array{PuzzleSolvingTime, PuzzleSolvingTime}
     */
    private function seed(): array
    {
        $round = $this->seedTrackerlessEvent(self::PUZZLE);

        return [
            $this->seedTrackerlessTime($round, self::PUZZLE, 'Puzzle Pals', 3600),
            $this->seedTrackerlessTime($round, self::PUZZLE, 'Corner Crew', 3000),
        ];
    }

    private function trackerOf(PuzzleSolvingTime $time): null|string
    {
        $tracker = $this->database()->fetchOne('SELECT player_id FROM puzzle_solving_time WHERE id = :id', ['id' => $time->id->toString()]);

        return is_string($tracker) ? $tracker : null;
    }

    private function bus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
