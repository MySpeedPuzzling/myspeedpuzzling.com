<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordRoundResults;
use SpeedPuzzling\Web\Services\OnlineRounds\FakeOnlineMediaStorage;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OnlineReviewSeeding;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\OnlineEventType;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\OnlineUnfinishedMeasure;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\RoundResultChange;
use SpeedPuzzling\Web\Value\RoundResultField;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The review of an online round (online_review, online_review_entry, online_review_confirm - docs/features/
 * online-competitions/README.md §5.9, stream C2): who may review (COMPETITION_RESULTS_ENTRY and ONLINE_ROUNDS_MANAGE -
 * a results-only referee is not one of them: the evidence photos stay with the organisers), the list and one
 * participant's evidence as JSON, and the confirmation - JSON, the stateless CSRF header, the official result written
 * and announced to the other pages after the commit, every refusal answered with its reason.
 */
final class OnlineReviewControllerTest extends WebTestCase
{
    use OnlineReviewSeeding;
    use OverridesFeatureFlagEnv;

    private const string FLAG = 'ONLINE_ROUNDS_FOR_ORGANISERS';
    private const string COMPETITION = OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP;
    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string OLIVIA = OnlineRoundsFixture::PARTICIPANT_CONNECTED;
    private const string PAGE = '/en/online-rounds/' . self::ROUND . '/review';
    private const string ENTRY = '/en/online-rounds/' . self::ROUND . '/review/' . self::OLIVIA;
    private const string CONFIRM = '/en/online-rounds/' . self::ROUND . '/review/' . self::OLIVIA . '/confirm';
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string REFEREE = PlayerFixture::PLAYER_WITH_FAVORITES;

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{null|string, bool, int, int}>
     */
    public static function whoMayReview(): iterable
    {
        // player => [flag on, page status, JSON status]
        yield 'admin, flag off' => [PlayerFixture::PLAYER_ADMIN, false, 200, 200];
        yield 'organiser, flag off' => [self::ORGANISER, false, 403, 403];
        yield 'organiser, flag on' => [self::ORGANISER, true, 200, 200];
        yield 'referee, flag on' => [self::REFEREE, true, 403, 403];
        yield 'other player, flag on' => [PlayerFixture::PLAYER_REGULAR, true, 403, 403];
        yield 'guest' => [null, true, 302, 401];
    }

    #[DataProvider('whoMayReview')]
    public function testWhoMayReview(null|string $playerId, bool $flag, int $pageStatus, int $jsonStatus): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, $flag);
        $browser = self::createClient();
        $this->makeOrganiserAndReferee($browser);

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        $browser->request('GET', self::PAGE);
        self::assertResponseStatusCodeSame($pageStatus);

        $browser->request('GET', self::PAGE, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame($jsonStatus);

        $browser->request('GET', self::ENTRY, server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame($jsonStatus);

        $this->post($browser, self::CONFIRM, ['momentMs' => null, 'source' => 'reviewer', 'pieces' => 100, 'seenResult' => null]);
        self::assertSame($jsonStatus === 200 ? 422 : $jsonStatus, $browser->getResponse()->getStatusCode(), 'nothing to confirm before the start - but only those who may are told so');
    }

    public function testThePageCarriesTheListAndItsAddresses(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $startMs = $this->startOnlineRound();
        $this->finishTap($this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED), $startMs + 1_234_567);

        $crawler = $browser->request('GET', self::PAGE . '?participant=' . self::OLIVIA);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        $root = $crawler->filter('[data-controller="online-review"]');
        self::assertSame(self::OLIVIA, $root->attr('data-online-review-selected-value'));

        $urls = json_decode((string) $root->attr('data-online-review-urls-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($urls);
        self::assertSame('/en/online-rounds/' . self::ROUND . '/review/' . Uuid::NIL . '/confirm', $urls['confirm'] ?? null);

        $state = json_decode($crawler->filter('[data-online-review-target="initialState"]')->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertIsArray($state['entries'] ?? null);
        self::assertIsArray($state['round'] ?? null);
        self::assertIsArray($state['mercure'] ?? null);
        self::assertSame(['Arthur Anonymous', 'Olivia Online'], array_column($state['entries'], 'name'));
        self::assertSame(3000, $state['round']['piecesTotal'] ?? null);
        self::assertSame(['/round-results/' . self::ROUND, '/round-stopwatch/' . self::ROUND], $state['mercure']['topics'] ?? null);
    }

    public function testTheListSaysWhatToLookAt(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        // 58 minutes 20 seconds into the hour
        $startMs = $this->startOnlineRound(3500);
        $olivia = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        // Recorded 47:12.345 in, arrived 95 s later while her phone kept sending
        $tapMs = $startMs + (47 * 60 + 12) * 1000 + 345;
        $this->phoneEvent($olivia, OnlineEventType::PageHidden, $tapMs + 10_000, receivedAtMs: $tapMs + 12_000);
        $this->phoneEvent($olivia, OnlineEventType::PageVisible, $tapMs + 25_000);
        $this->finishTap($olivia, $tapMs, $tapMs + 95_000);
        $arthur = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_ANONYMOUS);
        $this->phoneEvent($arthur, OnlineEventType::TimeUpPieces, $startMs + 3_000_000, ['pieces' => 312, 'measure' => 'left']);
        $arthur->reportedPieces = 312;
        $arthur->reportedMeasure = OnlineUnfinishedMeasure::PiecesLeft;
        $this->entityManager()->flush();

        $browser->request('GET', self::PAGE, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $list = $this->json($browser);
        self::assertIsArray($list['entries']);
        [$arthurRow, $oliviaRow] = $list['entries'];
        self::assertIsArray($arthurRow);
        self::assertIsArray($oliviaRow);

        self::assertSame('needs_look', $oliviaRow['status']);
        self::assertSame(['kind' => 'finished', 'momentMs' => $tapMs, 'seconds' => 47 * 60 + 12], array_intersect_key((array) $oliviaRow['suggestion'], ['kind' => true, 'momentMs' => true, 'seconds' => true]));
        self::assertNull($oliviaRow['official']);
        self::assertSame(1, $oliviaRow['taps']);
        self::assertContains(['code' => 'arrived_late', 'count' => 1, 'seconds' => 95], (array) $oliviaRow['flags']);
        self::assertContains(['code' => 'page_hidden', 'count' => 1, 'seconds' => 15], (array) $oliviaRow['flags']);

        self::assertSame('unfinished', $arthurRow['status']);
        self::assertIsArray($arthurRow['suggestion']);
        self::assertSame(2688, $arthurRow['suggestion']['piecesPlaced'] ?? null);
        self::assertSame(['measure' => 'left', 'pieces' => 312], $arthurRow['suggestion']['reported'] ?? null);
    }

    public function testOneParticipantsEvidence(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $startMs = $this->startOnlineRound();
        $olivia = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $tapMs = $startMs + 1_000_000;
        $tapId = $this->finishTap($olivia, $tapMs);
        $burst = $this->evidence($olivia, OnlineMediaKind::Burst, $tapMs, $tapId, self::burstManifest($tapMs));
        $this->evidence($olivia, OnlineMediaKind::Button, $tapMs + 40, $tapId);
        $this->evidence($olivia, OnlineMediaKind::Periodic, $startMs + 30_000);
        $pending = $this->evidence($olivia, OnlineMediaKind::Periodic, $startMs + 60_000, uploaded: false);

        $browser->request('GET', self::ENTRY, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        $detail = $this->json($browser);

        self::assertIsArray($detail['entry']);
        self::assertIsArray($detail['round']);
        self::assertSame(self::OLIVIA, $detail['entry']['participantId']);
        self::assertSame($startMs, $detail['round']['startedAtMs'] ?? null);

        self::assertIsArray($detail['taps']);
        self::assertCount(1, $detail['taps']);
        self::assertSame(['eventId' => $tapId, 'momentMs' => $tapMs, 'undone' => false, 'position' => 'in_round', 'seconds' => 1000], array_intersect_key((array) $detail['taps'][0], ['eventId' => true, 'momentMs' => true, 'undone' => true, 'position' => true, 'seconds' => true]));

        // The fixture's consent, ready and the tap - in order
        self::assertIsArray($detail['events']);
        self::assertSame(['consent.given', 'device.ready', 'finish.button'], array_column($detail['events'], 'type'));

        self::assertIsArray($detail['media']);
        $media = array_column($detail['media'], null, 'id');
        self::assertIsArray($media[$burst->id->toString()] ?? null);
        $burstMedia = $media[$burst->id->toString()];
        self::assertSame('burst', $burstMedia['kind']);
        self::assertSame($tapId, $burstMedia['relatedEventId']);
        self::assertIsArray($burstMedia['manifest']);
        self::assertCount(101, $burstMedia['manifest']);
        self::assertIsString($burstMedia['url']);
        self::assertStringStartsWith(FakeOnlineMediaStorage::BASE_URL . '/primary/online/', $burstMedia['url']);
        self::assertStringContainsString('X-Fake-Expires=900', $burstMedia['url']);
        // Not uploaded yet - listed, never hidden
        self::assertIsArray($media[$pending->id->toString()] ?? null);
        self::assertTrue($media[$pending->id->toString()]['pending']);
        self::assertArrayHasKey('url', $media[$pending->id->toString()]);
        self::assertNull($media[$pending->id->toString()]['url']);
        self::assertCount(5, $detail['media'], 'with the fixture\'s empty mat');
    }

    public function testTheEntryAddressOpenedAsAPageLeadsToTheReview(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', self::ENTRY);

        self::assertResponseRedirects('/en/online-rounds/' . self::ROUND . '/review?participant=' . self::OLIVIA);
    }

    public function testAParticipantNotEnteredHasNoEntry(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/online-rounds/' . self::ROUND . '/review/' . OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'entry_not_found'], $this->json($browser));
    }

    public function testConfirmingNeedsJsonAndTheCsrfHeader(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);

        $browser->request('POST', self::CONFIRM, ['momentMs' => '1'], server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost', 'HTTP_X_CSRF_TOKEN' => 'csrf-token']);
        self::assertResponseStatusCodeSame(415);
        self::assertSame(['error' => 'json_required'], $this->json($browser));

        // From another site: the stateless token is refused
        $this->post($browser, self::CONFIRM, ['momentMs' => null, 'source' => 'reviewer', 'pieces' => 100, 'seenResult' => null], origin: 'https://elsewhere.example');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'invalid_csrf_token'], $this->json($browser));

        $browser->request('POST', self::CONFIRM, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost', 'HTTP_REFERER' => 'http://localhost/'], content: '{"momentMs": null, "source": "reviewer", "pieces": 100, "seenResult": null}');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'invalid_csrf_token'], $this->json($browser));
    }

    public function testAConfirmationWritesTheOfficialResultAndTellsTheOtherPages(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $startMs = $this->startOnlineRound();
        $tapMs = $startMs + 1_000_345;
        $this->finishTap($this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED), $tapMs);
        $this->hub()->reset();

        $this->post($browser, self::CONFIRM, ['momentMs' => $tapMs, 'source' => 'button', 'pieces' => null, 'seenResult' => null]);

        self::assertResponseIsSuccessful();
        $answer = $this->json($browser);
        self::assertSame('applied', $answer['status']);
        self::assertSame(['seconds' => 1000], $answer['result']);
        self::assertSame($tapMs, $answer['momentMs']);
        self::assertSame('button', $answer['source']);
        self::assertIsArray($answer['entry']);
        self::assertSame('confirmed', $answer['entry']['status']);
        self::assertSame(['seconds' => 1000], $answer['entry']['official']);
        self::assertSame(['momentMs' => $tapMs, 'source' => 'button', 'adjustmentMs' => 0], array_intersect_key((array) $answer['entry']['confirmed'], ['momentMs' => true, 'source' => true, 'adjustmentMs' => true]));

        self::assertSame(1000, self::getContainer()->get(Connection::class)->fetchOne('SELECT result_seconds FROM competition_participant_round WHERE id = :id', ['id' => OnlineRoundsFixture::ENTRY_CONNECTED]));

        // After the commit, on the round's private results topic - the results desk, the live entry, other reviewers
        $updates = $this->hub()->getPublishedUpdates();
        self::assertNotEmpty($updates);
        self::assertContains('/round-results/' . self::ROUND, $updates[0]->getTopics());
        self::assertTrue($updates[0]->isPrivate());
        $payload = json_decode($updates[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('official_results.entries', $payload['type'] ?? null);

        // The same again: unchanged, nothing announced
        $this->hub()->reset();
        $this->post($browser, self::CONFIRM, ['momentMs' => $tapMs, 'source' => 'button', 'pieces' => null, 'seenResult' => null]);
        self::assertResponseIsSuccessful();
        self::assertSame('unchanged', $this->json($browser)['status']);
        self::assertSame([], $this->hub()->getPublishedUpdates());
    }

    /**
     * The results desk entered a result after the reviewer opened Olivia's evidence: the confirmation comes back as a
     * conflict with the result as it is now - nothing written, nothing announced; the review shows it and the reviewer
     * decides again
     */
    public function testAResultEnteredMeanwhileComesBackAsAConflict(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $startMs = $this->startOnlineRound();
        $tapMs = $startMs + 1_000_345;
        $this->finishTap($this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED), $tapMs);

        // What the review showed: nothing official yet
        $browser->request('GET', self::ENTRY, server: ['HTTP_ACCEPT' => 'application/json']);
        $detail = $this->json($browser);
        self::assertIsArray($detail['entry']);
        self::assertArrayHasKey('official', $detail['entry']);
        self::assertNull($detail['entry']['official']);

        $this->enterAtTheResultsDesk(1234);
        $this->hub()->reset();

        $this->post($browser, self::CONFIRM, ['momentMs' => $tapMs, 'source' => 'button', 'pieces' => null, 'seenResult' => $detail['entry']['official']]);

        self::assertResponseStatusCodeSame(409);
        $answer = $this->json($browser);
        self::assertSame('changed_meanwhile', $answer['error']);
        self::assertSame('Somebody else changed this meanwhile.', $answer['message']);
        self::assertSame(['seconds' => 1234], $answer['current']);
        self::assertIsArray($answer['entry']);
        self::assertSame(['seconds' => 1234], $answer['entry']['official']);
        self::assertSame('needs_look', $answer['entry']['status']);
        self::assertSame([], $this->hub()->getPublishedUpdates(), 'nothing changed - nothing to tell the other pages');
        self::assertSame(1234, $this->officialSeconds());

        // Decided again, knowing the desk's result
        $this->post($browser, self::CONFIRM, ['momentMs' => $tapMs, 'source' => 'button', 'pieces' => null, 'seenResult' => ['seconds' => 1234]]);

        self::assertResponseIsSuccessful();
        self::assertSame('applied', $this->json($browser)['status']);
        self::assertSame(1000, $this->officialSeconds());
    }

    public function testTheResultTheReviewerSawIsRequired(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $startMs = $this->startOnlineRound();
        $tapMs = $startMs + 600_000;
        $this->finishTap($this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED), $tapMs);

        $bodies = [
            'missing' => ['momentMs' => $tapMs, 'source' => 'button', 'pieces' => null],
            'not a result' => ['momentMs' => $tapMs, 'source' => 'button', 'pieces' => null, 'seenResult' => ['seconds' => 'soon']],
            'two results' => ['momentMs' => $tapMs, 'source' => 'button', 'pieces' => null, 'seenResult' => ['seconds' => 1, 'piecesPlaced' => 2]],
        ];

        foreach ($bodies as $case => $body) {
            $this->post($browser, self::CONFIRM, $body);

            self::assertResponseStatusCodeSame(422, $case);
            self::assertSame('invalid_confirmation', $this->json($browser)['error'], $case);
        }

        self::assertNull($this->officialSeconds());
    }

    public function testARefusalIsAnsweredWithItsReason(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $startMs = $this->startOnlineRound();
        $tapMs = $startMs + 600_000;
        $this->finishTap($this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED), $tapMs);

        // A moment the phone never recorded
        $this->post($browser, self::CONFIRM, ['momentMs' => $tapMs + 123, 'source' => 'reviewer', 'pieces' => null, 'seenResult' => null]);
        self::assertResponseStatusCodeSame(422);
        $answer = $this->json($browser);
        self::assertSame('moment_not_recorded', $answer['error']);
        self::assertSame('The participant\'s phone did not record this moment - pick their tap or a frame around it.', $answer['message']);

        // The official results' own rule: pieces placed stay below the puzzle's pieces
        $this->post($browser, self::CONFIRM, ['momentMs' => null, 'source' => 'reviewer', 'pieces' => 3000, 'seenResult' => null]);
        self::assertResponseStatusCodeSame(422);
        $answer = $this->json($browser);
        self::assertSame('invalid_pieces_placed', $answer['error']);
        self::assertSame('Pieces placed must be at least 1 and fewer than the puzzle has - a finished puzzle gets a time.', $answer['message']);

        // Not a number, not a source
        $this->post($browser, self::CONFIRM, ['momentMs' => '1792000000000', 'source' => 'button', 'pieces' => null, 'seenResult' => null]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid_confirmation', $this->json($browser)['error']);
        $this->post($browser, self::CONFIRM, ['momentMs' => $tapMs, 'source' => 'guess', 'pieces' => null, 'seenResult' => null]);
        self::assertResponseStatusCodeSame(422);

        self::assertNull(self::getContainer()->get(Connection::class)->fetchOne('SELECT result_seconds FROM competition_participant_round WHERE id = :id', ['id' => OnlineRoundsFixture::ENTRY_CONNECTED]));
    }

    public function testARoundWithoutOnlineTimingHasNoReview(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/online-rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/review');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/online-rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/review', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'round_not_online'], $this->json($browser));
    }

    private function signedIn(string $playerId): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);
        $browser->disableReboot();

        return $browser;
    }

    private function makeOrganiserAndReferee(KernelBrowser $browser): void
    {
        $connection = $browser->getContainer()->get(Connection::class);
        $connection->insert('competition_maintainer', ['competition_id' => self::COMPETITION, 'player_id' => self::ORGANISER]);
        $connection->insert('competition_referee', [
            'id' => Uuid::uuid7()->toString(),
            'competition_id' => self::COMPETITION,
            'player_id' => self::REFEREE,
            'added_by_id' => PlayerFixture::PLAYER_ADMIN,
            'added_at' => '2026-10-01 10:00:00',
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(KernelBrowser $browser, string $url, array $body, string $origin = 'http://localhost'): void
    {
        $browser->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => $origin,
            // BrowserKit would send the previous request's address - a page of our own site - as the Referer
            'HTTP_REFERER' => $origin . '/',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(KernelBrowser $browser): array
    {
        $decoded = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The organiser types a time for Olivia at the results desk - the official results write path, as its page sends it
     */
    private function enterAtTheResultsDesk(int $seconds): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new RecordRoundResults(
            competitionId: self::COMPETITION,
            roundId: self::ROUND,
            actingPlayerId: self::ORGANISER,
            changes: [RoundResultChange::forEntry(
                clientChangeId: Uuid::uuid7()->toString(),
                entry: RoundEntryRef::participantRound(OnlineRoundsFixture::ENTRY_CONNECTED),
                field: RoundResultField::Result,
                from: RoundEntryResult::none(),
                to: RoundEntryResult::finished($seconds),
            )],
        ));
    }

    private function officialSeconds(): null|int
    {
        $seconds = self::getContainer()->get(Connection::class)->fetchOne('SELECT result_seconds FROM competition_participant_round WHERE id = :id', ['id' => OnlineRoundsFixture::ENTRY_CONNECTED]);

        return is_numeric($seconds) ? (int) $seconds : null;
    }

    private static function hub(): NullMercureHub
    {
        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);

        return $hub;
    }
}
