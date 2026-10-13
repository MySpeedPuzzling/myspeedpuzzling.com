<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Online timing" of a round (online_round_settings, docs/features/online-competitions/, stream C1): who may open it
 * (ONLINE_ROUNDS_MANAGE - admins always, organisers with ONLINE_ROUNDS_FOR_ORGANISERS), turning it on, the settings
 * form's round trip (redirect / 422), the rounds that cannot use it and the participants not entered in the round.
 */
final class OnlineRoundSettingsControllerTest extends WebTestCase
{
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
        self::assertSame('Entered in this round: 2 of the 3 people going.', $crawler->filter('[data-online-entered]')->text());
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
