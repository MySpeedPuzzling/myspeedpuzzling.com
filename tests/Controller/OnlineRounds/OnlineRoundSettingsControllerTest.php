<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineAccessLight;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineRoundSettingsLiveUpdates;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\LiveVideoTesting;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\OnlineParticipantSettings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * "Online timing" of a round (online_round_settings, docs/features/online-competitions/, stream C1): who may open it
 * (ONLINE_ROUNDS_MANAGE - admins always, organisers with ONLINE_ROUNDS_FOR_ORGANISERS), turning it on, the settings
 * form's round trip (redirect / 422), the rounds that cannot use it and the participants not entered in the round. Stream
 * SP: a save tells the open participant pages (Mercure after the commit, a hub that is down never fails it), finish
 * detection is locked from the round's start until a Reset.
 */
final class OnlineRoundSettingsControllerTest extends WebTestCase
{
    use LiveVideoTesting;
    use OverridesFeatureFlagEnv;

    private const string FLAG = 'ONLINE_ROUNDS_FOR_ORGANISERS';
    private const string COMPETITION = OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP;
    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string SECOND_ROUND = '018d0050-0000-0000-0000-0000000001b1';
    private const string PAIRS_ROUND = '018d0050-0000-0000-0000-0000000001b2';
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();
        $this->restoreLiveVideo();

        parent::tearDown();
    }

    public function testGuestsAreSentToSignIn(): void
    {
        $browser = self::createClient();
        $browser->request('GET', self::url(self::ROUND));

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testAdminsManageOnlineTimingWhileTheFlagIsOff(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', self::url(self::ROUND));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertCount(1, $crawler->filter('[data-online-timing-on]'));
        self::assertSame('off', $crawler->filter('input[name="online_round_settings_form[detectionMode]"][checked]')->attr('value'));
        self::assertSame('left', $crawler->filter('input[name="online_round_settings_form[unfinishedMeasure]"][checked]')->attr('value'));
        self::assertSame('complete_required', $crawler->filter('input[name="online_round_settings_form[missingPieceRule]"][checked]')->attr('value'));
        self::assertStringContainsString('by ', $crawler->filter('[data-online-timing-since]')->text());
        // The project's own wording for durations (event_rounds.minutes), not a hard-coded unit
        self::assertStringContainsString('60 min', $crawler->filter('[data-online-round-settings]')->text());
        // Who gets a link later, and where from
        self::assertStringContainsString('People who join later by themselves get their link at once', $crawler->filter('[data-online-later-links]')->text());

        // Its tools: the personal links and the round's stopwatch
        self::assertSame($this->path('online_links', ['competitionId' => self::COMPETITION]), $crawler->filter('[data-online-tool="links"]')->attr('href'));
        self::assertSame($this->path('manage_round_stopwatch', ['roundId' => self::ROUND]), $crawler->filter('[data-online-tool="stopwatch"]')->attr('href'));
    }

    public function testAnOrganiserManagesItOnlyWhileTheFlagIsOn(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $browser = self::createClient();
        $this->makeOrganiser($browser);
        TestingLogin::asPlayer($browser, self::ORGANISER);

        $browser->request('GET', self::url(self::ROUND));
        self::assertResponseStatusCodeSame(403);

        // The same test's transaction: still the organiser
        self::ensureKernelShutdown();
        $this->overrideFeatureFlagEnv(self::FLAG, true);
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::ORGANISER);

        $browser->request('GET', self::url(self::ROUND));
        self::assertResponseIsSuccessful();
    }

    public function testOtherPlayersAreRefusedWhateverTheFlag(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, true);
        // Olivia's player - a participant, no organiser
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::url(self::ROUND));
        self::assertResponseStatusCodeSame(403);

        $browser->request('POST', self::url(self::ROUND), ['enable_online_timing' => '1', '_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheSettingsAreSavedAndAnInvalidChoiceIsRefused(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', self::url(self::ROUND));
        $browser->submitForm('Save settings', [
            'online_round_settings_form[detectionMode]' => 'shadow',
            'online_round_settings_form[unfinishedMeasure]' => 'placed',
            'online_round_settings_form[missingPieceRule]' => 'penalty_5s',
        ]);

        self::assertResponseRedirects(self::url(self::ROUND), 303);
        self::assertSame(['shadow', 'placed', 'penalty_5s'], $this->settings(self::ROUND));

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('The online timing settings of Online Round are saved.', $crawler->filter('.alert-success')->text());
        self::assertSame('shadow', $crawler->filter('input[name="online_round_settings_form[detectionMode]"][checked]')->attr('value'));

        // A value that is no choice: 422, nothing saved
        $form = $crawler->selectButton('Save settings')->form();
        $form->disableValidation();
        $form['online_round_settings_form[detectionMode]'] = 'always';
        $crawler = $browser->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertGreaterThan(0, $crawler->filter('[data-online-timing-on] .invalid-feedback')->count());
        self::assertSame(['shadow', 'placed', 'penalty_5s'], $this->settings(self::ROUND));
    }

    public function testTurningOnlineTimingOnGivesTheMissingLinks(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $this->addRound($browser, self::SECOND_ROUND, 'Second Round', 'solo');

        $crawler = $browser->request('GET', self::url(self::SECOND_ROUND));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-online-timing-off]'));
        self::assertCount(0, $crawler->filter('form[name="online_round_settings_form"]'));
        self::assertNull($this->settings(self::SECOND_ROUND));

        $browser->submitForm('Turn on online timing');

        self::assertResponseRedirects(self::url(self::SECOND_ROUND), 303);
        self::assertSame(['off', 'left', 'complete_required'], $this->settings(self::SECOND_ROUND));
        self::assertNotNull($this->token($browser, OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN), 'Nora got her link');

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('Online timing is on for Second Round. 1 participant got a personal link.', $crawler->filter('.alert-success')->text());
        self::assertCount(1, $crawler->filter('[data-online-timing-on]'));

        // A second click (another tab) turns nothing on twice
        $browser->request('POST', self::url(self::SECOND_ROUND), ['enable_online_timing' => '1', '_token' => 'csrf-token']);
        self::assertResponseRedirects(self::url(self::SECOND_ROUND), 303);
        self::assertSame(1, $this->connection($browser)->fetchOne('SELECT COUNT(*) FROM online_round WHERE round_id = :round', ['round' => self::SECOND_ROUND]));
    }

    public function testTurningItOnNeedsTheFormsToken(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $this->addRound($browser, self::SECOND_ROUND, 'Second Round', 'solo');
        $browser->request('GET', self::url(self::SECOND_ROUND));

        $crawler = $browser->request('POST', self::url(self::SECOND_ROUND), ['enable_online_timing' => '1', '_token' => 'forged']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The page was open for too long', $crawler->filter('[data-enable-error]')->text());
        self::assertNull($this->settings(self::SECOND_ROUND));
        self::assertNull($this->token($browser, OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN));
    }

    public function testRoundsThatCannotUseOnlineTimingSayWhy(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);

        // An in-person event
        $crawler = $browser->request('GET', self::url(OfficialResultsFixture::ROUND_GROUP_A));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-online-timing-unavailable="in_person_event"]'));
        self::assertCount(0, $crawler->filter('button[name="enable_online_timing"]'));
        self::assertCount(0, $crawler->filter('[data-online-participants]'));

        // Asked anyway: refused, said, nothing written
        $crawler = $browser->request('POST', self::url(OfficialResultsFixture::ROUND_GROUP_A), ['enable_online_timing' => '1', '_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('this event takes place in person', $crawler->filter('[data-online-timing-unavailable]')->text());
        self::assertNull($this->settings(OfficialResultsFixture::ROUND_GROUP_A));

        // A pair round of an online event
        $this->addRound($browser, self::PAIRS_ROUND, 'Pairs Round', 'duo');
        $crawler = $browser->request('GET', self::url(self::PAIRS_ROUND));
        self::assertCount(1, $crawler->filter('[data-online-timing-unavailable="not_solo"]'));
        self::assertCount(0, $crawler->filter('button[name="enable_online_timing"]'));
    }

    public function testParticipantsNotEnteredInTheRoundAreCountedAndLeadToTheSheet(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', self::url(self::ROUND));

        // Olivia and Arthur are entered, Nora is not
        self::assertSame('Entered in this round: 2 of 3 going to the event.', $crawler->filter('[data-online-entered]')->text());
        self::assertSame('With a personal link: 2 of 3.', $crawler->filter('[data-online-with-link]')->text());
        self::assertSame('1', $crawler->filter('[data-online-not-entered]')->attr('data-online-not-entered'));
        self::assertStringContainsString('1 person going is not entered in this round yet.', $crawler->filter('[data-online-not-entered]')->text());
        // The sheet's People tab, filtered to whoever is not in this round
        self::assertSame(
            $this->path('participants_sheet', ['competitionId' => self::COMPETITION, 'tab' => 'people', 'filter' => 'out:' . self::ROUND]),
            $crawler->filter('[data-enter-participants]')->attr('href'),
        );

        // Everybody entered: nothing to do
        $this->connection($browser)->executeStatement(
            'INSERT INTO competition_participant_round (id, participant_id, round_id, result_did_not_start) VALUES (:id, :participant, :round, false)',
            ['id' => '018d0050-0000-0000-0000-0000000003b1', 'participant' => OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN, 'round' => self::ROUND],
        );
        $crawler = $browser->request('GET', self::url(self::ROUND));
        self::assertCount(0, $crawler->filter('[data-online-not-entered]'));
    }

    /**
     * Live video (contract phase-1b.md §2): the two switches are in the form while a media server is configured
     */
    public function testTheLiveVideoSwitchesAreSaved(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', self::url(self::ROUND));
        self::assertCount(1, $crawler->filter('[data-online-video-settings]'));
        self::assertStringContainsString('Live video', $crawler->filter('[data-online-video-settings] legend')->text());
        self::assertCount(1, $crawler->filter('[data-online-video-settings] .form-switch input[name="online_round_settings_form[videoEnabled]"]:not([checked])'));
        self::assertCount(1, $crawler->filter('[data-online-video-settings] .form-switch input[name="online_round_settings_form[participantsSeeEachOther]"]:not([checked])'));

        $browser->submitForm('Save settings', [
            'online_round_settings_form[videoEnabled]' => true,
            'online_round_settings_form[participantsSeeEachOther]' => true,
        ]);
        self::assertResponseRedirects(self::url(self::ROUND), 303);
        self::assertSame([true, true], $this->videoSettings(self::ROUND));

        $crawler = $browser->followRedirect();
        self::assertCount(1, $crawler->filter('input[name="online_round_settings_form[videoEnabled]"][checked]'));

        $browser->submitForm('Save settings', [
            'online_round_settings_form[videoEnabled]' => true,
            'online_round_settings_form[participantsSeeEachOther]' => false,
        ]);
        self::assertSame([true, false], $this->videoSettings(self::ROUND));
    }

    /**
     * Without a media server the switches are not shown - and saving the page keeps what they hold
     */
    public function testWithoutAMediaServerTheVideoSwitchesAreHiddenAndKept(): void
    {
        $this->turnLiveVideoOff();
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        self::turnRoundVideoOn($this->connection($browser), seeEachOther: true);

        $crawler = $browser->request('GET', self::url(self::ROUND));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-online-video-settings]'));
        self::assertCount(0, $crawler->filter('input[name="online_round_settings_form[videoEnabled]"]'));

        $browser->submitForm('Save settings', [
            'online_round_settings_form[detectionMode]' => 'shadow',
        ]);
        self::assertResponseRedirects(self::url(self::ROUND), 303);
        self::assertSame(['shadow', 'left', 'complete_required'], $this->settings(self::ROUND));
        self::assertSame([true, true], $this->videoSettings(self::ROUND));
    }

    /**
     * Stream SP: a save tells the open participant pages at once - after the commit, on the round's stopwatch topic, the
     * settings hash the phones compare (their next state carries the same one); the page says phones pick it up
     */
    public function testASaveTellsTheOpenPhonesAtOnce(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $browser->disableReboot();
        $hub = self::hubOf($browser);

        $crawler = $browser->request('GET', self::url(self::ROUND));
        self::assertSame('Participants\' phones that are open pick up a change within seconds.', trim($crawler->filter('[data-online-settings-live]')->text()));
        self::assertCount(0, $hub->getPublishedUpdates(), 'opening the page tells nobody');

        $browser->submitForm('Save settings', [
            'online_round_settings_form[detectionMode]' => 'shadow',
            'online_round_settings_form[videoEnabled]' => true,
        ]);
        self::assertResponseRedirects(self::url(self::ROUND), 303);

        $updates = self::hubOf($browser)->getPublishedUpdates();
        self::assertCount(1, $updates);
        self::assertSame([OfficialResultsSubscription::stopwatchTopic(self::ROUND)], $updates[0]->getTopics());
        self::assertFalse($updates[0]->isPrivate(), 'the topic the phones already follow');
        $expected = OnlineParticipantSettings::of($browser->getContainer()->get(OnlineRoundRepository::class)->get(OnlineRoundsFixture::ONLINE_ROUND_SOLO))->hash();
        self::assertSame(
            ['type' => OnlineRoundSettingsLiveUpdates::MESSAGE_TYPE, 'roundId' => self::ROUND, 'settingsHash' => $expected],
            json_decode($updates[0]->getData(), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertSame(['shadow', 'left', 'complete_required'], $this->settings(self::ROUND));

        // The heartbeat's hashes of the round were forgotten - the next beat reads the new ones
        $peek = $browser->getContainer()->get(CacheInterface::class)->get(OnlineAccessLight::roundHashKey(self::ROUND), static function (ItemInterface $item, bool &$save): string {
            $save = false;

            return 'nothing cached';
        });
        self::assertSame('nothing cached', $peek);
    }

    /**
     * A hub that is down never fails the save - the phones learn it from their next heartbeat
     */
    public function testASaveSucceedsWhenTheHubIsDown(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $browser->disableReboot();
        $browser->request('GET', self::url(self::ROUND));
        $hub = self::hubOf($browser);
        $hub->failWith(new \RuntimeException('The Mercure hub is down'));

        $browser->submitForm('Save settings', ['online_round_settings_form[detectionMode]' => 'on']);

        self::assertResponseRedirects(self::url(self::ROUND), 303);
        self::assertSame(['on', 'left', 'complete_required'], $this->settings(self::ROUND));
        self::assertSame([], $hub->getPublishedUpdates());
        $hub->failWith(null);
        $crawler = $browser->followRedirect();
        self::assertStringContainsString('The online timing settings of Online Round are saved.', $crawler->filter('.alert-success')->text());
    }

    /**
     * Finish detection is fixed from the round's start - a scheduled start, the countdown, the round running or over -
     * until a Reset: the field is disabled with the reason, the other settings still save, and a page opened before the
     * start that sends another mode is refused (422, said, nothing saved, nobody told)
     */
    public function testFinishDetectionIsLockedFromTheStartUntilAReset(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);

        // Opened while the round waits: the field is free
        $crawler = $browser->request('GET', self::url(self::ROUND));
        self::assertCount(0, $crawler->filter('input[name="online_round_settings_form[detectionMode]"][disabled]'));
        self::assertCount(0, $crawler->filter('[data-detection-locked]'));
        $stale = $crawler->selectButton('Save settings')->form();
        $stale['online_round_settings_form[detectionMode]'] = 'on';
        $stale['online_round_settings_form[unfinishedMeasure]'] = 'placed';

        // ... the organiser starts the round in another tab, then this page is sent
        self::setStopwatch($this->connection($browser), 'running', '-5 minutes');
        $crawler = $browser->submit($stale);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The round has started (or its start is set), so the finish can\'t change any more.', $crawler->filter('[data-online-timing-on]')->text());
        self::assertSame(['off', 'left', 'complete_required'], $this->settings(self::ROUND), 'nothing saved - not the other settings either');
        self::assertCount(3, $crawler->filter('input[name="online_round_settings_form[detectionMode]"][disabled]'));

        foreach (['scheduled' => ['scheduled', '+1 hour', null], 'running' => ['running', '-5 minutes', null], 'ended' => ['stopped', '-90 minutes', '-30 minutes']] as $case => [$status, $startedAt, $stoppedAt]) {
            self::setStopwatch($this->connection($browser), $status, $startedAt, $stoppedAt);
            $crawler = $browser->request('GET', self::url(self::ROUND));

            self::assertCount(3, $crawler->filter('input[name="online_round_settings_form[detectionMode]"][disabled]'), $case);
            self::assertSame('off', $crawler->filter('input[name="online_round_settings_form[detectionMode]"][checked]')->attr('value'), $case);
            self::assertStringContainsString('Fixed from the round\'s start until a Reset', $crawler->filter('[data-detection-locked]')->text(), $case);
        }

        // The rest of the settings save from a locked page; the detection stays
        $browser->submitForm('Save settings', ['online_round_settings_form[unfinishedMeasure]' => 'placed']);
        self::assertResponseRedirects(self::url(self::ROUND), 303);
        self::assertSame(['off', 'placed', 'complete_required'], $this->settings(self::ROUND));

        // Reset: the round waits again - the detection is free
        self::setStopwatch($this->connection($browser), null, null);
        $crawler = $browser->request('GET', self::url(self::ROUND));
        self::assertCount(0, $crawler->filter('input[name="online_round_settings_form[detectionMode]"][disabled]'));
        self::assertCount(0, $crawler->filter('[data-detection-locked]'));
        $browser->submitForm('Save settings', ['online_round_settings_form[detectionMode]' => 'on']);
        self::assertResponseRedirects(self::url(self::ROUND), 303);
        self::assertSame(['on', 'placed', 'complete_required'], $this->settings(self::ROUND));
    }

    private static function hubOf(KernelBrowser $browser): NullMercureHub
    {
        $hub = $browser->getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);

        return $hub;
    }

    /**
     * The round's stopwatch as stored - `startedAt` / `stoppedAt` relative to now
     */
    private static function setStopwatch(Connection $connection, null|string $status, null|string $startedAt, null|string $stoppedAt = null): void
    {
        $moment = static fn (null|string $relative): null|string => $relative === null ? null : (new DateTimeImmutable($relative))->format('Y-m-d H:i:s');

        $connection->executeStatement(
            'UPDATE competition_round SET stopwatch_status = :status, stopwatch_started_at = :startedAt, stopwatch_stopped_at = :stoppedAt WHERE id = :id',
            ['status' => $status, 'startedAt' => $moment($startedAt), 'stoppedAt' => $moment($stoppedAt), 'id' => self::ROUND],
        );
    }

    private static function url(string $roundId): string
    {
        return '/en/online-rounds/' . $roundId . '/settings';
    }

    private function signedIn(string $playerId): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);

        return $browser;
    }

    /**
     * PLAYER_WITH_STRIPE organises the Online Test Cup with PLAYER_ADMIN (a maintainer) - no admin
     */
    private function makeOrganiser(KernelBrowser $browser): void
    {
        $this->connection($browser)->insert('competition_maintainer', ['competition_id' => self::COMPETITION, 'player_id' => self::ORGANISER]);
    }

    private function addRound(KernelBrowser $browser, string $roundId, string $name, string $category): void
    {
        $this->connection($browser)->executeStatement(
            "INSERT INTO competition_round (id, competition_id, name, minutes_limit, starts_at, slug, category)
             VALUES (:id, :competition, :name, 45, NOW() + INTERVAL '30 days', :slug, :category)",
            ['id' => $roundId, 'competition' => self::COMPETITION, 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'category' => $category],
        );
    }

    /**
     * @return null|array{string, string, string} detection, unfinished measure, missing-piece rule - null = no online timing
     */
    private function settings(string $roundId): null|array
    {
        $connection = self::getContainer()->get(Connection::class);
        $row = $connection->fetchAssociative(
            'SELECT detection_mode, unfinished_measure, missing_piece_rule FROM online_round WHERE round_id = :round',
            ['round' => $roundId],
        );

        if ($row === false) {
            return null;
        }

        /** @var array{detection_mode: string, unfinished_measure: string, missing_piece_rule: string} $row */
        return [$row['detection_mode'], $row['unfinished_measure'], $row['missing_piece_rule']];
    }

    /**
     * @return array{bool, bool} live video, participants see each other
     */
    private function videoSettings(string $roundId): array
    {
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT video_enabled, participants_see_each_other FROM online_round WHERE round_id = :round',
            ['round' => $roundId],
        );
        self::assertIsArray($row);

        return [(bool) $row['video_enabled'], (bool) $row['participants_see_each_other']];
    }

    private function token(KernelBrowser $browser, string $participantId): null|string
    {
        $token = $this->connection($browser)->fetchOne('SELECT online_token FROM competition_participant WHERE id = :id', ['id' => $participantId]);

        return is_string($token) ? $token : null;
    }

    private function connection(KernelBrowser $browser): Connection
    {
        $connection = $browser->getContainer()->get(Connection::class);

        return $connection;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function path(string $route, array $parameters): string
    {
        return self::getContainer()->get(UrlGeneratorInterface::class)->generate($route, $parameters + ['_locale' => 'en']);
    }
}
