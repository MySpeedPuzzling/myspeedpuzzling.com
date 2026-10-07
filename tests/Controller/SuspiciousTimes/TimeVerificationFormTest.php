<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\SuspiciousTimes;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Imagick;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\Stopwatch;
use SpeedPuzzling\Web\Query\GetPlayerPaces;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Query\GetSuspicionEntryFacts;
use SpeedPuzzling\Web\Query\GetSuspiciousTimeEvidence;
use SpeedPuzzling\Web\Query\GetSuspiciousTimeReferences;
use SpeedPuzzling\Web\Services\MistypedYearNormalizer;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SingleTimeSuspicionCheck;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeClassifier;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SuspiciousTimeFormCheck;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * docs/features/suspicious-time-review.md, "Catch it while typing" (P5): the add/edit form judges a typed time against
 * the player's own times - the live check shows the notice, a submit is refused (422) until "Yes, it's right", and a
 * confirmed time is saved with a SuspiciousTimeConfirmation.
 *
 * SuspiciousTimesFixture: Sam Steady solved Steady Fields 1 (4000 pieces) in ~9:27 - his next solve is predicted at
 * about 8:30 - and Harbour Lights in 2:30:00 (TIME_STEADY_FAST, ~9:30 usual without it). Pat and Fay are a pair.
 */
final class TimeVerificationFormTest extends WebTestCase
{
    private const string STEADY_FIELDS = '018d0031-0000-0000-0000-000000000101';
    private const string STEADY_FIELDS_2 = '018d0031-0000-0000-0000-000000000102';

    public function testTheLiveCheckAsksAboutATimeMuchFasterThanUsualAndSuggestsTheHours(): void
    {
        $browser = $this->browser(SuspiciousTimesFixture::PLAYER_STEADY);

        $html = $this->liveCheck($browser, ['puzzle' => self::STEADY_FIELDS, 'seconds' => 9000]);
        $notice = new Crawler($html)->filter('.pace-notice');

        self::assertCount(1, $notice);
        self::assertSame('1', $notice->attr('data-pace-checked'));
        self::assertStringContainsString('Please check the time before saving', $notice->text());
        self::assertStringContainsString('You entered 02:30:00 - your usual time here is about 08:30:00.', $notice->text());
        self::assertStringContainsString('Perhaps the hours box was left empty - 07:30:00?', $notice->text());
        // The comparison says it already - the trigger's own line would repeat it
        self::assertStringNotContainsString('much faster than we expected', $notice->text());
        self::assertStringContainsString('Did you puzzle with someone?', $notice->text());
        self::assertSame('27000', $notice->filter('[data-action="first-try-check#useSuggested"]')->attr('data-first-try-check-seconds-param'));
        self::assertStringContainsString('Use 07:30:00', $notice->filter('[data-action="first-try-check#useSuggested"]')->text());
        self::assertCount(1, $notice->filter('[data-action="first-try-check#confirmPace"]'));
        self::assertStringNotContainsStringIgnoringCase('suspic', $html, 'Neutral wording');

        // "Yes, it's right" chosen - the button carries the key of exactly these values
        $key = self::paceKeyIn(new Crawler($html));
        self::assertSame(SuspiciousTimeFormCheck::confirmationKey(self::STEADY_FIELDS, 9000, new DateTimeImmutable('today'), 1), $key);
        $html = $this->liveCheck($browser, ['puzzle' => self::STEADY_FIELDS, 'seconds' => 9000, 'pace_confirmed' => $key]);
        self::assertStringContainsString('Thank you for checking', $html);
        self::assertStringContainsString('first-try-check#undoPace', $html);
        self::assertStringNotContainsString('first-try-check#confirmPace', $html);
    }

    public function testAnAnswerAboutOtherValuesIsNoAnswer(): void
    {
        $browser = $this->browser(SuspiciousTimesFixture::PLAYER_STEADY);
        $key = self::paceKeyIn(new Crawler($this->liveCheck($browser, ['puzzle' => self::STEADY_FIELDS, 'seconds' => 9000])));

        // Another time, another day, another puzzle, or the old "1" - asked again
        foreach (
            [
            ['seconds' => 8400, 'pace_confirmed' => $key],
            ['seconds' => 9000, 'pace_confirmed' => $key, 'date' => new DateTimeImmutable('-1 day')->format('d.m.Y')],
            ['seconds' => 9000, 'pace_confirmed' => $key, 'puzzle' => self::STEADY_FIELDS_2],
            ['seconds' => 9000, 'pace_confirmed' => '1'],
            ] as $parameters
        ) {
            $html = $this->liveCheck($browser, ['puzzle' => self::STEADY_FIELDS, ...$parameters]);
            self::assertStringNotContainsString('Thank you for checking', $html);
            self::assertStringContainsString('first-try-check#confirmPace', $html);
        }

        // The submit refuses a stale answer: "Yes, it's right" was about 2:30:00, the form now holds 2:20:00
        [$fields, $timeId] = $this->addForm($browser, self::STEADY_FIELDS, 2, 20, 0);
        $crawler = $this->submitAdd($browser, $fields, $timeId, paceConfirmed: $key);

        $this->assertResponseStatusCodeSame(422);
        self::assertSame('', $crawler->filter('input[name="pace_confirmed"]')->attr('value'), 'The stale answer is dropped');
        self::assertSame(0, $this->resultsOf($browser, $timeId));
        self::assertNotSame($key, self::paceKeyIn($crawler));

        // ... and so does the edit
        $staleKey = SuspiciousTimeFormCheck::confirmationKey(SuspiciousTimesFixture::PUZZLE_HARBOUR, 2 * 3600 + 30 * 60 + 5, new DateTimeImmutable('-12 days'), 1);
        $this->submitEdit($browser, SuspiciousTimesFixture::TIME_STEADY_FAST, 2, 30, 6, paceConfirmed: $staleKey);
        $this->assertResponseStatusCodeSame(422);
        self::assertSame(SuspiciousTimesFixture::STEADY_FAST_SECONDS, $this->secondsOf($browser, SuspiciousTimesFixture::TIME_STEADY_FAST));
    }

    public function testAMemberWhoDidNotTrackTheResultIsNeverAskedAboutTheTrackersTimes(): void
    {
        // Fay edits the pair Pat saved (150 hours for 3000 pieces, far below the pairs' floor): the check is judged
        // for the tracker - nothing of it is hers to see
        $entry = ['time' => SuspiciousTimesFixture::TIME_SLOW_PAIR, 'seconds' => 540060];
        $html = $this->liveCheck($this->browser(SuspiciousTimesFixture::PLAYER_FLAGGED), [...$entry, 'group_players' => ['#partner1']]);

        self::assertStringNotContainsString('data-pace-checked', $html);
        self::assertStringNotContainsString('Please check the time', $html);

        // Pat himself is asked
        $html = $this->liveCheck($this->browser(SuspiciousTimesFixture::PLAYER_PARTNER, restart: true), [...$entry, 'group_players' => ['#flagged1']]);
        self::assertStringContainsString('Please check the time', $html);
    }

    public function testTheLiveCheckAsksAboutATimeMuchSlowerThanUsual(): void
    {
        $browser = $this->browser(SuspiciousTimesFixture::PLAYER_STEADY);

        // 49:08:00 - days counted instead of the time spent puzzling
        $notice = new Crawler($this->liveCheck($browser, ['puzzle' => self::STEADY_FIELDS, 'seconds' => 176880]))->filter('.pace-notice');

        self::assertStringContainsString('You entered 49:08:00 - your usual time here is about 08:30:00.', $notice->text());
        self::assertStringNotContainsString('much slower than we expected', $notice->text(), 'The comparison says it already');
        self::assertStringContainsString('If you puzzled over several days', $notice->text());
        self::assertStringNotContainsString('Did you puzzle with someone?', $notice->text());
        self::assertCount(0, $notice->filter('[data-action="first-try-check#useSuggested"]'), 'Nothing to suggest');
        self::assertCount(1, $notice->filter('[data-action="first-try-check#confirmPace"]'));
    }

    public function testATimeInsideThePlayersRangeLeavesOnlyTheMarker(): void
    {
        $browser = $this->browser(SuspiciousTimesFixture::PLAYER_STEADY);

        $html = $this->liveCheck($browser, ['puzzle' => self::STEADY_FIELDS, 'seconds' => 33000]);
        $notice = new Crawler($html)->filter('.pace-notice');

        // Judged against his own times - the generic pieces-per-minute modal stays quiet
        self::assertCount(1, $notice);
        self::assertSame('1', $notice->attr('data-pace-checked'));
        self::assertNotNull($notice->attr('hidden'));
        self::assertSame('', trim($notice->text()));
        self::assertStringNotContainsString('Please check the time', $html);
    }

    public function testTheLiveCheckJudgesThePaceOnlyWhenAsked(): void
    {
        $browser = $this->browser(SuspiciousTimesFixture::PLAYER_STEADY);

        // The stopwatch's save form does not ask - a measured time is no typo
        $html = $this->liveCheck($browser, ['puzzle' => self::STEADY_FIELDS, 'seconds' => 9000, 'pace' => null]);

        self::assertStringNotContainsString('data-pace-checked', $html);
        self::assertStringNotContainsString('Please check the time', $html);
    }

    public function testAPlayerWithoutTimesOfTheirOwnIsJudgedByTheCommunity(): void
    {
        $browser = $this->browser(SuspiciousTimesFixture::PLAYER_GROUP);

        // Gina has no solo times besides the one she confirmed: 25:00 for 3000 pieces is beyond anybody's pace
        $notice = new Crawler($this->liveCheck($browser, ['puzzle' => SuspiciousTimesFixture::PUZZLE_MARATHON, 'seconds' => 1500]))->filter('.pace-notice');

        self::assertSame('1', $notice->attr('data-pace-checked'), 'Asked here - not asked again by the generic modal');
        self::assertStringContainsString('This is faster than almost any time recorded for 3000 pieces.', $notice->text());
        self::assertStringNotContainsString('your usual time here', $notice->text(), 'No usual time to compare with');

        // Within the community's range there is nothing to judge her by yet: no marker, the generic modal stays
        self::assertStringNotContainsString('data-pace-checked', $this->liveCheck($browser, ['puzzle' => SuspiciousTimesFixture::PUZZLE_MARATHON, 'seconds' => 40000]));
    }

    public function testTheTeammatesHintNeverRestsOnSomebodyTheTrackerBlocked(): void
    {
        $browser = $this->browser(SuspiciousTimesFixture::PLAYER_GROUP);
        // Gina edits her 25:00 on Mountain Meadow, saved the day Pat and Fay saved their pair of it
        $entry = ['time' => SuspiciousTimesFixture::TIME_GROUP_SOLO, 'seconds' => 1490, 'date' => new DateTimeImmutable('-8 days')->format('d.m.Y')];

        self::assertStringContainsString('Someone saved this puzzle as a pair or team result that day.', $this->liveCheck($browser, $entry));

        $browser->getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (gen_random_uuid(), :blocker, :blocked, NOW(), 'self')",
            ['blocker' => SuspiciousTimesFixture::PLAYER_GROUP, 'blocked' => SuspiciousTimesFixture::PLAYER_PARTNER],
        );

        $html = $this->liveCheck($browser, $entry);
        self::assertStringNotContainsString('Someone saved this puzzle', $html);
        self::assertStringContainsString('Did you puzzle with someone?', $html, 'Still asked - without anything about the pair');
    }

    public function testAPairFarBelowTheCommunitysSlowFloorIsAsked(): void
    {
        $browser = $this->browser(SuspiciousTimesFixture::PLAYER_PARTNER);
        $pair = ['puzzle' => SuspiciousTimesFixture::PUZZLE_MARATHON, 'seconds' => 540000, 'group_players' => ['#flagged1']];

        $notice = new Crawler($this->liveCheck($browser, $pair))->filter('.pace-notice');

        self::assertStringContainsString('This is much slower than usual for 3000 pieces - most puzzlers take about 12:30:00.', $notice->text());
        self::assertStringNotContainsString('Did you puzzle with someone?', $notice->text());

        // Pat alone in the same time: above the solo floor (one puzzler is slower than two), no times of his own
        self::assertStringNotContainsString('Please check the time', $this->liveCheck($browser, [...$pair, 'group_players' => []]));

        // The submit asks the same
        [$fields, $timeId] = $this->addForm($browser, SuspiciousTimesFixture::PUZZLE_MARATHON, 150, 0, 0);
        $crawler = $this->submitAdd($browser, $fields, $timeId, groupPlayers: ['#flagged1']);

        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('most puzzlers take about 12:30:00', $crawler->filter('[data-first-try-check-target="notice"]')->text());

        $this->submitAdd($browser, $fields, $timeId, paceConfirmed: self::paceKeyIn($crawler), groupPlayers: ['#flagged1']);

        $this->assertResponseRedirects('/en/time-added/' . $timeId);
        // The community's median time for a pair on 3000 pieces is what the notice compared it with
        self::assertSame([[$timeId, SuspiciousTimesFixture::PLAYER_PARTNER, 45000]], $this->confirmations($browser, $timeId));
    }

    public function testTheFormNeverLooksForAnotherEdition(): void
    {
        $browser = $this->browser(SuspiciousTimesFixture::PLAYER_EDITION);
        // Her 2:40:00 on the 3000 edition, edited by a few seconds - judged without itself, against her pace. The scan
        // finds the 1600 edition for it; the form leaves that lookup out (it compares every puzzle of the brand)
        $entry = ['time' => SuspiciousTimesFixture::TIME_EDITION_FAST, 'seconds' => 9605];

        $html = $this->liveCheck($browser, $entry);
        self::assertStringContainsString('Please check the time before saving', $html, 'Still asked');
        self::assertStringNotContainsString('Lighthouse Cove', $html);
        self::assertStringNotContainsString('1600-piece edition', $html);
    }

    public function testAnAddIsRefusedUntilTheTimeIsConfirmedAndKeepsEverything(): void
    {
        $browser = $this->browser(SuspiciousTimesFixture::PLAYER_STEADY);

        [$fields, $timeId] = $this->addForm($browser, self::STEADY_FIELDS, 2, 30, 0);
        $crawler = $this->submitAdd($browser, $fields, $timeId);

        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Please check the time - confirm it below if it is right.', $crawler->filter('form')->text());
        $notice = $crawler->filter('[data-first-try-check-target="notice"] .pace-notice');
        self::assertStringContainsString('Perhaps the hours box was left empty - 07:30:00?', $notice->text());
        self::assertCount(1, $notice->filter('[data-action="first-try-check#confirmPace"]'));
        self::assertSame($timeId, $crawler->filter('input[name="time_id"]')->attr('value'), 'The same result id');
        self::assertSame('', $crawler->filter('input[name="pace_confirmed"]')->attr('value'));
        self::assertSame('2', $crawler->filter('#puzzle_add_form_timeHours')->attr('value'));
        self::assertSame('30', $crawler->filter('#puzzle_add_form_timeMinutes')->attr('value'));
        self::assertSame('A good one', $crawler->filter('#puzzle_add_form_comment')->text());
        self::assertSame(0, $this->resultsOf($browser, $timeId));

        // "Yes, it's right"
        $this->submitAdd($browser, $fields, $timeId, paceConfirmed: self::paceKeyIn($crawler));

        $this->assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame(1, $this->resultsOf($browser, $timeId));
        self::assertSame([[$timeId, SuspiciousTimesFixture::PLAYER_STEADY, 30600]], $this->confirmations($browser, $timeId));
    }

    public function testARefusedAddKeepsItsPhoto(): void
    {
        $browser = $this->browser(SuspiciousTimesFixture::PLAYER_STEADY);

        [$fields, $timeId] = $this->addForm($browser, self::STEADY_FIELDS, 2, 30, 0);
        $crawler = $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => $fields,
            'time_id' => $timeId,
        ], ['puzzle_add_form' => ['finishedPuzzlesPhoto' => $this->photo()]]);

        $this->assertResponseStatusCodeSame(422);
        $token = (string) $crawler->filter('input[name="photo_stash[finishedPuzzlesPhoto]"]')->attr('value');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);

        $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => $fields,
            'time_id' => $timeId,
            'pace_confirmed' => self::paceKeyIn($crawler),
            'photo_stash' => ['finishedPuzzlesPhoto' => $token],
        ]);

        $this->assertResponseRedirects('/en/time-added/' . $timeId);
        $photo = $browser->getContainer()->get(Connection::class)->fetchOne('SELECT finished_puzzle_photo FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertIsString($photo, 'Saved with the photo of the refused submit');
    }

    public function testATimeInsideThePlayersRangeIsSavedWithoutAsking(): void
    {
        $browser = $this->browser(SuspiciousTimesFixture::PLAYER_STEADY);

        [$fields, $timeId] = $this->addForm($browser, self::STEADY_FIELDS, 9, 10, 0);
        $this->submitAdd($browser, $fields, $timeId);

        $this->assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame([], $this->confirmations($browser, $timeId));
    }

    public function testAConfirmationOfATimeNobodyAskedAboutIsNotStored(): void
    {
        $browser = $this->browser(SuspiciousTimesFixture::PLAYER_STEADY);

        [$fields, $timeId] = $this->addForm($browser, self::STEADY_FIELDS, 9, 10, 0);
        $this->submitAdd($browser, $fields, $timeId, paceConfirmed: SuspiciousTimeFormCheck::confirmationKey(self::STEADY_FIELDS, 33000, new DateTimeImmutable('today'), 1));

        $this->assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame([], $this->confirmations($browser, $timeId));
    }

    public function testAnEditIsRefusedUntilTheTimeIsConfirmed(): void
    {
        $browser = $this->browser(SuspiciousTimesFixture::PLAYER_STEADY);
        $timeId = SuspiciousTimesFixture::TIME_STEADY_FAST;

        // The live check of the edit leaves the edited time itself out: against his usual 9:30:00
        $html = $this->liveCheck($browser, ['time' => $timeId, 'seconds' => 9005]);
        self::assertStringContainsString('You entered 02:30:05 - your usual time here is about 09:30:00.', $html);
        self::assertStringContainsString('Use 07:30:05', $html);

        $crawler = $this->submitEdit($browser, $timeId, 2, 30, 5);

        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Please check the time - confirm it below if it is right.', $crawler->filter('form')->text());
        self::assertStringContainsString('Perhaps the hours box was left empty - 07:30:05?', $crawler->filter('[data-first-try-check-target="notice"]')->text());
        self::assertSame(SuspiciousTimesFixture::STEADY_FAST_SECONDS, $this->secondsOf($browser, $timeId));

        // The edit modal asks the same
        $crawler = $this->submitEdit($browser, $timeId, 2, 30, 5, modal: true);
        $this->assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('[data-action="first-try-check#confirmPace"]'));

        $this->submitEdit($browser, $timeId, 2, 30, 5, paceConfirmed: self::paceKeyIn($crawler));

        $this->assertResponseRedirects();
        self::assertSame(9005, $this->secondsOf($browser, $timeId));
        self::assertSame([[$timeId, SuspiciousTimesFixture::PLAYER_STEADY, 34200]], $this->confirmations($browser, $timeId));
    }

    public function testAnEditLeavingTheEntryAsItIsIsNotAsked(): void
    {
        $browser = $this->browser(SuspiciousTimesFixture::PLAYER_STEADY);
        $timeId = SuspiciousTimesFixture::TIME_STEADY_FAST;

        // Judged when it was saved - a moderator may even have trusted it since
        self::assertStringNotContainsString('data-pace-checked', $this->liveCheck($browser, ['time' => $timeId, 'seconds' => SuspiciousTimesFixture::STEADY_FAST_SECONDS]));

        $this->submitEdit($browser, $timeId, 2, 30, 0, comment: 'Only the note changes');

        $this->assertResponseRedirects();
        self::assertSame([], $this->confirmations($browser, $timeId));
    }

    public function testAStopwatchSaveIsNeverAsked(): void
    {
        $browser = $this->browser(SuspiciousTimesFixture::PLAYER_STEADY);
        $stopwatchId = $this->stopwatchOfSam($browser);
        $url = '/en/save-stopwatch/' . $stopwatchId;

        $crawler = $browser->request('GET', $url);
        $this->assertResponseIsSuccessful();
        self::assertSame('false', $crawler->filter('form[data-first-try-check-pace-value]')->attr('data-first-try-check-pace-value'), 'Its live check does not ask either');
        $timeId = (string) $crawler->filter('input[name="time_id"]')->attr('value');

        $browser->request('POST', $url, [
            'puzzle_add_form' => [
                '_token' => (string) $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value'),
                'mode' => 'speed_puzzling',
                'brand' => ManufacturerFixture::MANUFACTURER_TREFL,
                'puzzle' => self::STEADY_FIELDS,
                'timeHours' => '2',
                'timeMinutes' => '30',
                'timeSeconds' => '0',
                'finishedAt' => $this->today(),
                'collection' => '__system_collection__',
            ],
            'time_id' => $timeId,
        ]);

        $this->assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame(1, $this->resultsOf($browser, $timeId));
        self::assertSame([], $this->confirmations($browser, $timeId));

        // The add form asks
        self::assertSame('true', $browser->request('GET', '/en/puzzle-add')->filter('form[data-first-try-check-pace-value]')->attr('data-first-try-check-pace-value'));
    }

    public function testAFailingCheckNeverBlocksTheSave(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $container = $browser->getContainer();

        $failingDatabase = self::createStub(Connection::class);
        $failingDatabase->method('fetchAssociative')->willThrowException(new RuntimeException('Database is gone'));
        $database = $container->get(Connection::class);
        $logger = new InMemoryLogger();

        $container->set(SuspiciousTimeFormCheck::class, new SuspiciousTimeFormCheck(
            new SingleTimeSuspicionCheck(
                $container->get(GetPlayerPrediction::class),
                new GetSuspicionEntryFacts($failingDatabase),
                new GetSuspiciousTimeReferences($database),
                new GetPlayerPaces($database),
                new GetSuspiciousTimeEvidence($database, $container->get(ClockInterface::class)),
                new SuspiciousTimeClassifier(),
                $logger,
            ),
            $container->get(MistypedYearNormalizer::class),
            $container->get(ClockInterface::class),
        ));
        TestingLogin::asPlayer($browser, SuspiciousTimesFixture::PLAYER_STEADY);

        self::assertStringNotContainsString('data-pace-checked', $this->liveCheck($browser, ['puzzle' => self::STEADY_FIELDS, 'seconds' => 9000]));

        [$fields, $timeId] = $this->addForm($browser, self::STEADY_FIELDS, 2, 30, 0);
        $this->submitAdd($browser, $fields, $timeId);

        $this->assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertTrue($logger->hasRecord('warning', 'the check of one entry failed'));
    }

    private function browser(string $playerId, bool $restart = false): KernelBrowser
    {
        if ($restart) {
            self::ensureKernelShutdown();
        }

        $browser = self::createClient();
        $browser->disableReboot();
        TestingLogin::asPlayer($browser, $playerId);

        return $browser;
    }

    private function today(): string
    {
        return new DateTimeImmutable()->format('d.m.Y');
    }

    /**
     * @param array<string, null|int|string|list<string>> $parameters null removes a default
     */
    private function liveCheck(KernelBrowser $browser, array $parameters): string
    {
        $parameters = array_filter(
            [...['first_attempt' => '0', 'date' => $this->today(), 'pace' => '1'], ...$parameters],
            static fn(mixed $value): bool => $value !== null,
        );

        $browser->request('GET', '/en/first-try-check?' . http_build_query($parameters));
        $this->assertResponseIsSuccessful();

        return (string) $browser->getResponse()->getContent();
    }

    /**
     * @return array{array<string, string>, string}
     */
    private function addForm(KernelBrowser $browser, string $puzzleId, int $hours, int $minutes, int $seconds): array
    {
        $crawler = $browser->request('GET', '/en/puzzle-add');
        $this->assertResponseIsSuccessful();

        return [
            [
                '_token' => (string) $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value'),
                'mode' => 'speed_puzzling',
                'brand' => ManufacturerFixture::MANUFACTURER_TREFL,
                'puzzle' => $puzzleId,
                'timeHours' => (string) $hours,
                'timeMinutes' => (string) $minutes,
                'timeSeconds' => (string) $seconds,
                'finishedAt' => $this->today(),
                'comment' => 'A good one',
                'collection' => '__system_collection__',
            ],
            (string) $crawler->filter('input[name="time_id"]')->attr('value'),
        ];
    }

    /**
     * @param array<string, string> $fields
     * @param list<string> $groupPlayers
     */
    private function submitAdd(KernelBrowser $browser, array $fields, string $timeId, string $paceConfirmed = '', array $groupPlayers = []): Crawler
    {
        $parameters = [
            'puzzle_add_form' => $fields,
            'time_id' => $timeId,
            'pace_confirmed' => $paceConfirmed,
        ];

        if ($groupPlayers !== []) {
            $parameters['group_players'] = $groupPlayers;
        }

        return $browser->request('POST', '/en/puzzle-add', $parameters);
    }

    private function submitEdit(
        KernelBrowser $browser,
        string $timeId,
        int $hours,
        int $minutes,
        int $seconds,
        string $paceConfirmed = '',
        string $comment = 'Edited',
        bool $modal = false,
    ): Crawler {
        $server = $modal ? ['HTTP_TURBO_FRAME' => 'modal-frame'] : [];
        $crawler = $browser->request('GET', '/en/edit-time/' . $timeId, server: $server);
        $this->assertResponseIsSuccessful();

        return $browser->request('POST', '/en/edit-time/' . $timeId, [
            'edit_puzzle_solving_time_form' => [
                '_token' => (string) $crawler->filter('input[name="edit_puzzle_solving_time_form[_token]"]')->attr('value'),
                'mode' => 'speed_puzzling',
                'brand' => ManufacturerFixture::MANUFACTURER_TREFL,
                'puzzle' => SuspiciousTimesFixture::PUZZLE_HARBOUR,
                'timeHours' => (string) $hours,
                'timeMinutes' => (string) $minutes,
                'timeSeconds' => (string) $seconds,
                'finishedAt' => new DateTimeImmutable('-12 days')->format('d.m.Y'),
                'comment' => $comment,
                'firstAttempt' => '1',
            ],
            'pace_confirmed' => $paceConfirmed,
        ], server: $server);
    }

    /**
     * The key "Yes, it's right" sends back - carried by the notice's button.
     */
    private static function paceKeyIn(Crawler $crawler): string
    {
        $key = (string) $crawler->filter('[data-action="first-try-check#confirmPace"]')->attr('data-first-try-check-key-param');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);

        return $key;
    }

    private function stopwatchOfSam(KernelBrowser $browser): string
    {
        $entityManager = $browser->getContainer()->get('doctrine')->getManager();
        $player = $entityManager->find(Player::class, SuspiciousTimesFixture::PLAYER_STEADY);
        $puzzle = $entityManager->find(Puzzle::class, self::STEADY_FIELDS);
        assert($player instanceof Player && $puzzle instanceof Puzzle);

        $stopwatch = new Stopwatch(Uuid::uuid7(), $player, $puzzle);
        $stopwatch->start(new DateTimeImmutable('-3 hours'));
        $stopwatch->pause(new DateTimeImmutable('-30 minutes'));
        $entityManager->persist($stopwatch);
        $entityManager->flush();

        return $stopwatch->id->toString();
    }

    private function resultsOf(KernelBrowser $browser, string $timeId): int
    {
        $count = $browser->getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);

        return is_numeric($count) ? (int) $count : 0;
    }

    private function secondsOf(KernelBrowser $browser, string $timeId): int
    {
        $seconds = $browser->getContainer()->get(Connection::class)->fetchOne('SELECT seconds_to_solve FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);

        return is_numeric($seconds) ? (int) $seconds : 0;
    }

    /**
     * @return list<array{string, string, int}> time id, player id, expected seconds
     */
    private function confirmations(KernelBrowser $browser, string $timeId): array
    {
        /** @var list<array{time_id: string, player_id: string, expected_seconds: int}> $rows */
        $rows = $browser->getContainer()->get(Connection::class)->fetchAllAssociative(
            'SELECT time_id, player_id, expected_seconds FROM suspicious_time_confirmation WHERE time_id = :timeId ORDER BY confirmed_at',
            ['timeId' => $timeId],
        );

        return array_map(static fn(array $row): array => [$row['time_id'], $row['player_id'], $row['expected_seconds']], $rows);
    }

    private function photo(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pace-photo-');
        assert(is_string($path));

        $image = new Imagick();
        $image->newImage(64, 48, 'orange');
        $image->setImageFormat('jpeg');
        $image->writeImage($path);
        $image->destroy();

        return new UploadedFile($path, 'finished.jpg', 'image/jpeg', null, true);
    }
}
