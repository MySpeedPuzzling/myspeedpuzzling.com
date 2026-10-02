<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\DuplicateResults;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\StopwatchFixture;
use SpeedPuzzling\Web\Tests\FirstTryScenario;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Layer 2 of docs/features/duplicate-results.md: the add/edit forms ask before saving the same time from the same
 * day twice, the live check says the same, the API refuses nothing new. FirstTryScenario saves 5:00:00 on
 * PUZZLE_3000, which no fixture solved.
 */
final class DuplicateCheckFormTest extends WebTestCase
{
    use QueryCountAssertions;

    public function testTheSameTimeFromTheSameDayIsSavedOnlyAfterAConfirmation(): void
    {
        $browser = $this->browser();
        $twin = $this->scenario($browser)->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, comment: 'first copy');
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        [$fields, $timeId] = $this->addForm($browser);
        $crawler = $this->submitAdd($browser, $fields, $timeId);

        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('this time from the same day is already saved', $crawler->filter('form')->text());
        $notice = $crawler->filter('[data-first-try-check-target="notice"]');
        self::assertStringContainsString('This exact time is already saved', $notice->text());
        self::assertStringContainsString('You saved exactly 05:00:00 on this puzzle today at', $notice->text());
        self::assertCount(1, $notice->filter('a[href="/en/result/' . $twin . '"]'));
        self::assertCount(1, $notice->filter('[data-action="first-try-check#confirmDuplicate"]'));
        self::assertSame('Second copy', $crawler->filter('#puzzle_add_form_comment')->text(), 'Every field stays');
        self::assertSame(1, $this->resultsOf($browser, PlayerFixture::PLAYER_WITH_STRIPE));
        self::assertSame([['warning_shown', $timeId, 'form']], $this->preventionsOf($browser, PlayerFixture::PLAYER_WITH_STRIPE));

        // "It's another solve, save it"
        $this->submitAdd($browser, $fields, $timeId, duplicateConfirmed: true);

        $this->assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame(2, $this->resultsOf($browser, PlayerFixture::PLAYER_WITH_STRIPE));
        self::assertSame(
            [['warning_shown', $timeId, 'form'], ['saved_anyway', $timeId, 'form']],
            $this->preventionsOf($browser, PlayerFixture::PLAYER_WITH_STRIPE),
        );

        // The very form sent again is no copy of itself: it lands on the saved result (Layer 1)
        $this->submitAdd($browser, $fields, $timeId);

        $this->assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame(2, $this->resultsOf($browser, PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testTheSameTimeFromAnotherDaySavesWithoutAsking(): void
    {
        $browser = $this->browser();
        $this->scenario($browser)->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, daysAgo: 10);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        [$fields, $timeId] = $this->addForm($browser);
        $this->submitAdd($browser, $fields, $timeId);

        $this->assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame([], $this->preventionsOf($browser, PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testAConfirmationWithoutATwinRecordsNothing(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        [$fields, $timeId] = $this->addForm($browser);
        $this->submitAdd($browser, $fields, $timeId, duplicateConfirmed: true);

        $this->assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertSame([], $this->preventionsOf($browser, PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testATeammatesCopyOfThePairIsExplained(): void
    {
        $browser = $this->browser();
        $copy = $this->scenario($browser)->add(FirstTryScenario::ADMIN_USER_ID, ['#player4']);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        [$fields, $timeId] = $this->addForm($browser);
        $crawler = $this->submitAdd($browser, $fields, $timeId, groupPlayers: ['#admin']);

        $this->assertResponseStatusCodeSame(422);
        $notice = $crawler->filter('[data-first-try-check-target="notice"]');
        self::assertStringContainsString('Admin User already saved your pair result with exactly 05:00:00', $notice->text());
        self::assertStringContainsString('it is on your profile', $notice->text());
        self::assertCount(1, $notice->filter('a[href="/en/result/' . $copy . '"]'));
    }

    public function testACopyOfAFirstTryIsAskedAboutTheCopyFirst(): void
    {
        $browser = $this->browser();
        $this->scenario($browser)->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, firstTry: true, comment: 'first copy');
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        [$fields, $timeId] = $this->addForm($browser);
        $crawler = $this->submitAdd($browser, [...$fields, 'firstAttempt' => '1'], $timeId);

        $this->assertResponseStatusCodeSame(422);
        $notice = $crawler->filter('[data-first-try-check-target="notice"]');
        self::assertStringContainsString('This exact time is already saved', $notice->text());
        self::assertCount(0, $notice->filter('[data-action="first-try-check#move"]'), 'Never "make this my first try" for a copy');
        self::assertStringNotContainsString('this puzzle already has a first try', $crawler->filter('form')->text());

        // Another solve after all: now the first-try rules have their say
        $crawler = $this->submitAdd($browser, [...$fields, 'firstAttempt' => '1'], $timeId, duplicateConfirmed: true);

        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('You already have a first try', $crawler->filter('[data-first-try-check-target="notice"]')->text());
    }

    public function testTheStopwatchSaveAsksToo(): void
    {
        $browser = $this->browser();
        $url = '/en/save-stopwatch/' . StopwatchFixture::STOPWATCH_PAUSED;

        // STOPWATCH_PAUSED: PLAYER_REGULAR, PUZZLE_500_01, 30 minutes
        $this->messageBus($browser)->dispatch(new AddPuzzleSolvingTime(
            timeId: Uuid::uuid7(),
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_500_01,
            competitionId: null,
            time: '00:30:00',
            comment: 'typed in by hand',
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: new DateTimeImmutable(),
            firstAttempt: false,
            unboxed: false,
        ));
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', $url);
        $timeId = (string) $crawler->filter('input[name="time_id"]')->attr('value');
        $form = [
            '_token' => (string) $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value'),
            'mode' => 'speed_puzzling',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'puzzle' => PuzzleFixture::PUZZLE_500_01,
            'timeHours' => '0',
            'timeMinutes' => '30',
            'timeSeconds' => '0',
            'finishedAt' => $this->today(),
            'collection' => '__system_collection__',
        ];

        $browser->request('POST', $url, ['puzzle_add_form' => $form, 'time_id' => $timeId]);

        $this->assertResponseStatusCodeSame(422);
        self::assertSame([['warning_shown', $timeId, 'stopwatch']], $this->preventionsOf($browser, PlayerFixture::PLAYER_REGULAR));

        $browser->request('POST', $url, ['puzzle_add_form' => $form, 'time_id' => $timeId, 'duplicate_confirmed' => '1']);

        $this->assertResponseRedirects('/en/time-added/' . $timeId);
        self::assertContains(['saved_anyway', $timeId, 'stopwatch'], $this->preventionsOf($browser, PlayerFixture::PLAYER_REGULAR));
    }

    public function testRelaxTrackingIsNeverAsked(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        foreach (['first', 'second'] as $comment) {
            [$fields, $timeId] = $this->addForm($browser);
            $this->submitAdd($browser, [
                ...$fields,
                'mode' => 'relax',
                'timeHours' => null,
                'timeMinutes' => null,
                'timeSeconds' => null,
                'comment' => $comment,
            ], $timeId);

            $this->assertResponseRedirects('/en/tracking-added/' . $timeId);
        }

        self::assertSame([], $this->preventionsOf($browser, PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testTheApiRefusesNothingNew(): void
    {
        $browser = $this->browser();
        $this->scenario($browser)->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, comment: 'from the web');
        PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, PlayerFixture::PLAYER_WITH_STRIPE));

        $browser->request(
            'POST',
            '/api/v1/me/solving-times',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode([
                'puzzle_id' => FirstTryScenario::PUZZLE,
                'time' => '05:00:00',
            ]),
        );

        $this->assertResponseStatusCodeSame(201);
        self::assertSame(2, $this->resultsOf($browser, PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testAnEditTypingTheTimeOfAnotherResultIsAskedAbout(): void
    {
        $browser = $this->browser();
        $scenario = $this->scenario($browser);
        $scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, comment: 'first copy');
        $edited = $scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, time: '05:00:01', comment: 'second');
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->submitEdit($browser, $edited, '0');

        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This exact time is already saved', $crawler->filter('[data-first-try-check-target="notice"]')->text());
        self::assertSame([['warning_shown', $edited, 'form']], $this->preventionsOf($browser, PlayerFixture::PLAYER_WITH_STRIPE));

        $this->submitEdit($browser, $edited, '0', duplicateConfirmed: true);

        $this->assertResponseRedirects();
        self::assertSame(5 * 3600, $this->secondsOf($browser, $edited));
        self::assertContains(['saved_anyway', $edited, 'form'], $this->preventionsOf($browser, PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testTheEditModalAsksToo(): void
    {
        $browser = $this->browser();
        $scenario = $this->scenario($browser);
        $scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, comment: 'first copy');
        $edited = $scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, time: '05:00:01', comment: 'second');
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->submitEdit($browser, $edited, '0', modal: true);

        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This exact time is already saved', $crawler->filter('[data-first-try-check-target="notice"]')->text());
        self::assertSame(5 * 3600 + 1, $this->secondsOf($browser, $edited));
    }

    public function testEditingAnOldCopyWithoutTouchingTheTimeIsNotAsked(): void
    {
        $browser = $this->browser();
        $scenario = $this->scenario($browser);
        $scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, comment: 'first copy');
        $edited = $scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, comment: 'second');
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->submitEdit($browser, $edited, '0', comment: 'Fixed a typo');

        $this->assertResponseRedirects();
        self::assertSame([], $this->preventionsOf($browser, PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testTheLiveCheckAsksWithoutTheTagAndCostsNoExtraQuery(): void
    {
        $browser = $this->browser();
        $this->scenario($browser)->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, firstTry: true);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $base = '/en/first-try-check?puzzle=' . FirstTryScenario::PUZZLE;

        // Whatever the first request of the kernel loads once (the signed-in player, ...) stays out of the count
        $browser->request('GET', $base . '&first_attempt=0');

        // Before this feature: the tag only
        $this->startCountingQueries($browser);
        $browser->request('GET', $base);
        $firstTryOnly = $this->queryCount($browser);
        self::assertStringContainsString('first-try-check#move', (string) $browser->getResponse()->getContent());

        // The tag and the time: one read of the puzzle's results for both
        $this->startCountingQueries($browser);
        $browser->request('GET', $base . '&first_attempt=1&seconds=18000');
        self::assertSame($firstTryOnly, $this->queryCount($browser), 'The duplicate check reuses the first-try rows');
        self::assertCount(1, array_filter($this->executedSql($browser), static fn(string $sql): bool => str_contains($sql, 'WITH relevant AS')));
        $html = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('first-try-check#confirmDuplicate', $html);
        self::assertStringNotContainsString('first-try-check#move', $html, 'The copy is answered first');

        // Without the tag
        $browser->request('GET', $base . '&first_attempt=0&seconds=18000');
        self::assertStringContainsString('This exact time is already saved', (string) $browser->getResponse()->getContent());

        // "It's another solve" chosen
        $browser->request('GET', $base . '&first_attempt=0&seconds=18000&duplicate_confirmed=1');
        $html = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('this is another solve', $html);
        self::assertStringContainsString('first-try-check#undoDuplicate', $html);

        // Another second, no tag: nothing to say
        $browser->request('GET', $base . '&first_attempt=0&seconds=18001');
        self::assertSame('', $browser->getResponse()->getContent());
    }

    public function testTheLiveCheckWithoutTagAndTimeRunsNoQuery(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/first-try-check?first_attempt=0&puzzle=' . FirstTryScenario::PUZZLE);

        self::assertSame('', $browser->getResponse()->getContent());
        self::assertCount(0, array_filter($this->executedSql($browser), static fn(string $sql): bool => str_contains($sql, 'WITH relevant AS')));
    }

    private function browser(): KernelBrowser
    {
        $browser = self::createClient();
        $browser->disableReboot();

        return $browser;
    }

    private function scenario(KernelBrowser $browser): FirstTryScenario
    {
        return new FirstTryScenario($browser->getContainer());
    }

    private function messageBus(KernelBrowser $browser): MessageBusInterface
    {
        return $browser->getContainer()->get(MessageBusInterface::class);
    }

    private function today(): string
    {
        return new DateTimeImmutable()->format('d.m.Y');
    }

    /**
     * @return array{array<string, null|string>, string}
     */
    private function addForm(KernelBrowser $browser): array
    {
        $crawler = $browser->request('GET', '/en/puzzle-add');
        $this->assertResponseIsSuccessful();

        return [
            [
                '_token' => $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value'),
                'mode' => 'speed_puzzling',
                'brand' => ManufacturerFixture::MANUFACTURER_TREFL,
                'puzzle' => FirstTryScenario::PUZZLE,
                'timeHours' => '5',
                'timeMinutes' => '0',
                'timeSeconds' => '0',
                'finishedAt' => $this->today(),
                'comment' => 'Second copy',
                'collection' => '__system_collection__',
            ],
            (string) $crawler->filter('input[name="time_id"]')->attr('value'),
        ];
    }

    /**
     * @param array<string, null|string> $fields
     * @param list<string> $groupPlayers
     */
    private function submitAdd(
        KernelBrowser $browser,
        array $fields,
        string $timeId,
        bool $duplicateConfirmed = false,
        array $groupPlayers = [],
    ): Crawler {
        $parameters = [
            'puzzle_add_form' => array_filter($fields, static fn(null|string $value): bool => $value !== null),
            'time_id' => $timeId,
            'duplicate_confirmed' => $duplicateConfirmed ? '1' : '',
        ];

        if ($groupPlayers !== []) {
            $parameters['group_players'] = $groupPlayers;
        }

        return $browser->request('POST', '/en/puzzle-add', $parameters);
    }

    private function submitEdit(
        KernelBrowser $browser,
        string $timeId,
        string $seconds,
        bool $duplicateConfirmed = false,
        string $comment = 'Edited',
        bool $modal = false,
    ): Crawler {
        $server = $modal ? ['HTTP_TURBO_FRAME' => 'modal-frame'] : [];
        $crawler = $browser->request('GET', '/en/edit-time/' . $timeId, server: $server);
        $this->assertResponseIsSuccessful();

        return $browser->request('POST', '/en/edit-time/' . $timeId, [
            'edit_puzzle_solving_time_form' => [
                '_token' => $crawler->filter('input[name="edit_puzzle_solving_time_form[_token]"]')->attr('value'),
                'mode' => 'speed_puzzling',
                'brand' => ManufacturerFixture::MANUFACTURER_TREFL,
                'puzzle' => FirstTryScenario::PUZZLE,
                'timeHours' => '5',
                'timeMinutes' => '0',
                'timeSeconds' => $seconds,
                'finishedAt' => $this->today(),
                'comment' => $comment,
            ],
            'duplicate_confirmed' => $duplicateConfirmed ? '1' : '',
        ], server: $server);
    }

    private function resultsOf(KernelBrowser $browser, string $playerId): int
    {
        $count = $browser->getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :playerId AND puzzle_id = :puzzleId',
            ['playerId' => $playerId, 'puzzleId' => FirstTryScenario::PUZZLE],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    private function secondsOf(KernelBrowser $browser, string $timeId): int
    {
        $seconds = $browser->getContainer()->get(Connection::class)->fetchOne(
            'SELECT seconds_to_solve FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );

        return is_numeric($seconds) ? (int) $seconds : 0;
    }

    /**
     * @return list<array{string, string, string}> kind, time id, via - oldest first
     */
    private function preventionsOf(KernelBrowser $browser, string $playerId): array
    {
        /** @var list<array{kind: string, time_id: string, via: string}> $rows */
        $rows = $browser->getContainer()->get(Connection::class)->fetchAllAssociative(
            'SELECT kind, time_id, via FROM result_duplicate_prevention WHERE player_id = :playerId ORDER BY created_at, id',
            ['playerId' => $playerId],
        );

        return array_map(static fn(array $row): array => [$row['kind'], $row['time_id'], $row['via']], $rows);
    }
}
