<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\ReadsRoundAutomaticReveal;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Never-revealed secret puzzles (competition toolkit G3, A-round-formats.md §4.4): a round whose stopwatch ran without
 * "Reveal" ticked keeps its `stopwatch` puzzles hidden - no automatic fallback. The results desk says so (the round's
 * `stopwatchRevealMissed` count, only on such a round), and the publish dialog may reveal them: AFTER the publish, in a
 * dispatch of its own, only for the list the organiser confirmed (hash) - a stale list publishes and reveals nothing.
 */
final class StopwatchMissedRevealTest extends WebTestCase
{
    use ReadsRoundAutomaticReveal;

    private const string ROUND = CompetitionApiFixture::ROUND_FUTURE;
    private const string SECRET_NAME = 'Missed Reveal Lighthouse';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
    }

    public function testAnUntouchedRoundCarriesNothingOfIt(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $page = $this->browser->request('GET', '/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_B);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('[data-results-desk-target="revealMissed"]'));
        $html = (string) $this->browser->getResponse()->getContent();
        self::assertStringNotContainsString('missedReveal', $html);
        self::assertStringNotContainsString('reveal_missed', $html);
    }

    public function testBeforeTheRoundsTimeIsOverNothingIsSaid(): void
    {
        $this->stopwatchSecret();

        $round = $this->roundState();
        self::assertArrayNotHasKey('stopwatchRevealMissed', $round);

        $page = $this->browser->request('GET', '/en/manage-round-results/' . self::ROUND);
        self::assertResponseIsSuccessful();
        // The notice is there for the live updates, hidden
        self::assertNotNull($page->filter('[data-results-desk-target="revealMissed"]')->attr('hidden'));
    }

    public function testAStopwatchStartedWithoutRevealIsSaidOnTheDeskWithoutTheName(): void
    {
        $this->stopwatchSecret();
        $this->startWithoutReveal();

        $round = $this->roundState();
        self::assertSame(1, $round['stopwatchRevealMissed']);
        // A count only - the state reaches referees too, never the name
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $this->browser->getResponse()->getContent());

        $page = $this->browser->request('GET', '/en/manage-round-results/' . self::ROUND);
        $notice = $page->filter('[data-results-desk-target="revealMissed"]');
        self::assertNull($notice->attr('hidden'));
        self::assertStringContainsString('never started with', $notice->text());
        self::assertStringStartsWith('/en/manage-round-puzzles/' . self::ROUND, (string) $notice->filter('a[data-reveal-missed-link]')->attr('href'));
    }

    public function testThePreviewNamesThePuzzlesToOrganisersOnly(): void
    {
        $this->stopwatchSecret();
        $this->startWithoutReveal();

        $preview = $this->preview();
        self::assertSame([['name' => self::SECRET_NAME, 'scope' => 'everywhere', 'hiddenElsewhereUntil' => null]], $preview['puzzles']);
        self::assertIsString($preview['hash']);

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', '/en/official-results/rounds/' . self::ROUND . '/missed-reveal');
        self::assertResponseStatusCodeSame(403);
    }

    public function testPublishingWithTheConfirmedListRevealsAfterThePublishAndPushes(): void
    {
        $roundPuzzleId = $this->stopwatchSecret();
        $this->startWithoutReveal();
        $hash = $this->preview()['hash'];

        $this->publish(['revealMissed' => true, 'revealHash' => $hash]);

        self::assertResponseIsSuccessful();
        $answer = $this->json();
        self::assertSame('revealed', $answer['reveal']);
        self::assertIsArray($answer['round']);
        self::assertTrue($answer['round']['resultsPublished']);
        self::assertArrayNotHasKey('stopwatchRevealMissed', $answer['round']);
        self::assertNotNull($this->revealAt($roundPuzzleId));

        // The round push, then the reveal push (a refresh on both private topics) - like "Reveal now"
        $refreshes = array_filter(self::hub()->getPublishedUpdates(), static fn ($update): bool => str_contains($update->getData(), 'official_results.refresh'));
        self::assertCount(1, $refreshes);
        self::assertSame([OfficialResultsLiveUpdates::topic(self::ROUND), OfficialResultsLiveUpdates::refereesTopic(self::ROUND)], array_values($refreshes)[0]->getTopics());
    }

    public function testAListChangedMeanwhilePublishesButRevealsNothing(): void
    {
        $roundPuzzleId = $this->stopwatchSecret();
        $this->startWithoutReveal();

        $this->publish(['revealMissed' => true, 'revealHash' => hash('sha256', 'another list')]);

        self::assertResponseIsSuccessful();
        $answer = $this->json();
        self::assertSame('changed', $answer['reveal']);
        self::assertIsArray($answer['round']);
        self::assertTrue($answer['round']['resultsPublished']);
        self::assertSame(1, $answer['round']['stopwatchRevealMissed']);
        self::assertNull($this->revealAt($roundPuzzleId));
    }

    public function testPublishingWithoutTheTickRevealsNothing(): void
    {
        $roundPuzzleId = $this->stopwatchSecret();
        $this->startWithoutReveal();

        $this->publish([]);

        self::assertResponseIsSuccessful();
        $answer = $this->json();
        self::assertArrayNotHasKey('reveal', $answer);
        self::assertIsArray($answer['round']);
        self::assertTrue($answer['round']['resultsPublished']);
        self::assertNull($this->revealAt($roundPuzzleId));
        self::assertSame(RoundPuzzleReveal::Stopwatch->value, self::getContainer()->get(Connection::class)->fetchOne('SELECT reveal_mode FROM competition_round_puzzle WHERE id = :id', ['id' => $roundPuzzleId]));
    }

    private function stopwatchSecret(): string
    {
        $roundPuzzleId = Uuid::uuid7();
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: self::ROUND,
            userId: PlayerFixture::PLAYER_ADMIN,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: self::SECRET_NAME,
            piecesCount: 1000,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
            shownAutomaticRevealAt: self::automaticRevealOf(self::ROUND),
        ));
        $bus->dispatch(new ChangeRoundPuzzleReveal($roundPuzzleId->toString(), PuzzleHideMode::Entirely, RoundPuzzleReveal::Stopwatch));
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $roundPuzzleId->toString();
    }

    private function startWithoutReveal(): void
    {
        $this->browser->request('POST', '/en/start-round-stopwatch/' . self::ROUND, ['_token' => 'csrf-token'], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects();
    }

    /**
     * @return array<string, mixed>
     */
    private function roundState(): array
    {
        $this->browser->request('GET', '/en/official-results/rounds/' . self::ROUND);
        self::assertResponseIsSuccessful();
        $round = $this->json()['round'];
        self::assertIsArray($round);

        /** @var array<string, mixed> $round */
        return $round;
    }

    /**
     * @return array{puzzles: mixed, hash: mixed}
     */
    private function preview(): array
    {
        $this->browser->request('GET', '/en/official-results/rounds/' . self::ROUND . '/missed-reveal');
        self::assertResponseIsSuccessful();
        $preview = $this->json();
        self::assertSame(['puzzles', 'hash'], array_keys($preview));

        return ['puzzles' => $preview['puzzles'], 'hash' => $preview['hash']];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function publish(array $body): void
    {
        self::hub()->reset();
        $this->browser->request('POST', '/en/official-results/rounds/' . self::ROUND . '/publish', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function revealAt(string $roundPuzzleId): null|string
    {
        $revealAt = self::getContainer()->get(Connection::class)->fetchOne('SELECT reveal_at FROM competition_round_puzzle WHERE id = :id', ['id' => $roundPuzzleId]);

        return is_string($revealAt) ? $revealAt : null;
    }

    private static function hub(): NullMercureHub
    {
        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);

        return $hub;
    }
}
