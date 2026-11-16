<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\MyData;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\TestWith;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use SpeedPuzzling\Web\Tests\FirstTryScenario;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "My data" saves a result with the edit form's rules (docs/features/my-data.md "The edit form's checks"): every
 * case here is run **twice - through the edit form (`EditTimeController`) and through the row sheet of My data** -
 * with the same result, the same change and the same answers, and must end the same way: the same status, the same
 * words (the form's error and the notice asking the question), the same stored result and the same side records.
 *
 * Each run asserts the expected outcome; the second run of a case also compares what it saw with the first one's.
 * The cells edited in place go through the same code: the last test pins that they never answer a question.
 */
final class MyDataEditParityTest extends WebTestCase
{
    private const string FORM = 'edit form';
    private const string MY_DATA = 'my data';
    // A member without results of their own on FirstTryScenario::PUZZLE (3000 pieces)
    private const string PLAYER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string PLAYER_USER = PlayerFixture::PLAYER_WITH_STRIPE_USER_ID;
    private const array SAME_ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];

    private const string FIRST_TRY_ERROR = 'Not saved yet: this puzzle already has a first try.';
    private const string DUPLICATE_ERROR = 'Not saved yet: exactly this time from the same day is already saved for this puzzle.';
    private const string PACE_QUESTION = 'Please check the time before saving';
    private const string TOO_FAST_ERROR = 'there is no way you can solve the puzzle that fast';

    /** @var array<string, array{path: string, outcome: array<mixed>}> what the first path of a case saw, by case and step */
    private static array $seen = [];

    #[TestWith([self::FORM])]
    #[TestWith([self::MY_DATA])]
    public function testAFirstTryHeldElsewhereIsRefusedUntilItIsMovedHere(string $path): void
    {
        $browser = $this->browser();
        $scenario = new FirstTryScenario(self::getContainer());
        $held = $scenario->add(self::PLAYER_USER, daysAgo: 5, firstTry: true, comment: 'the first one');
        $edited = $scenario->add(self::PLAYER_USER, daysAgo: 2, time: '05:10:00', comment: 'the second one');
        TestingLogin::asPlayer($browser, self::PLAYER);

        $crawler = $this->submit($path, $browser, $edited, ['first_try' => true]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::FIRST_TRY_ERROR, $crawler->text());
        $notice = $this->notice($crawler);
        self::assertStringContainsString('Make this result my first try', $notice);
        self::assertCount(1, $crawler->filter('[data-action="first-try-check#move"]'));
        self::assertFalse($scenario->isFirstTry($edited));
        self::assertTrue($scenario->isFirstTry($held));
        $this->same(__FUNCTION__, 'refused', $path, [422, $notice, $this->stored($edited)]);

        $this->submit($path, $browser, $edited, ['first_try' => true], ['first_try_resolution' => 'move']);

        self::assertTrue($browser->getResponse()->isRedirect());
        self::assertTrue($scenario->isFirstTry($edited));
        self::assertFalse($scenario->isFirstTry($held));
        $this->same(__FUNCTION__, 'moved', $path, [$this->stored($edited), $this->stored($held)]);
    }

    #[TestWith([self::FORM])]
    #[TestWith([self::MY_DATA])]
    public function testAFirstTryLaterThanAnEarlierSolveIsOnlyWarnedAbout(string $path): void
    {
        $browser = $this->browser();
        $scenario = new FirstTryScenario(self::getContainer());
        $earlier = $scenario->add(self::PLAYER_USER, daysAgo: 5, comment: 'solved before, without the tag');
        $edited = $scenario->add(self::PLAYER_USER, daysAgo: 2, time: '05:10:00', comment: 'the later one');
        TestingLogin::asPlayer($browser, self::PLAYER);

        $this->submit($path, $browser, $edited, ['first_try' => true]);

        // Saved: an earlier solve without the tag is a heads-up, never a refusal
        self::assertTrue($browser->getResponse()->isRedirect());
        self::assertTrue($scenario->isFirstTry($edited));
        self::assertFalse($scenario->isFirstTry($earlier));
        $this->same(__FUNCTION__, 'saved', $path, [$this->stored($edited)]);
    }

    #[TestWith([self::FORM])]
    #[TestWith([self::MY_DATA])]
    public function testATimeFarOffThePlayersOwnIsSavedOnlyAfterYesItIsRight(string $path): void
    {
        // Sam Steady's usual time on Harbour Lights is about 9:30:00 - the result holds 2:30:00 (SuspiciousTimesFixture)
        $browser = $this->browser();
        $edited = SuspiciousTimesFixture::TIME_STEADY_FAST;
        TestingLogin::asPlayer($browser, SuspiciousTimesFixture::PLAYER_STEADY);
        $before = $this->stored($edited);

        $crawler = $this->submit($path, $browser, $edited, ['seconds' => 2 * 3600 + 30 * 60 + 6]);

        self::assertResponseStatusCodeSame(422);
        $notice = $this->notice($crawler);
        self::assertStringContainsString(self::PACE_QUESTION, $notice);
        self::assertStringContainsString('You entered 02:30:06', $notice);
        self::assertStringNotContainsStringIgnoringCase('suspic', $crawler->text());
        self::assertSame($before, $this->stored($edited));
        self::assertSame(0, $this->confirmationsOf($edited));
        $key = (string) $crawler->filter('[data-action="first-try-check#confirmPace"]')->attr('data-first-try-check-key-param');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);
        $this->same(__FUNCTION__, 'asked', $path, [422, $notice, $key]);

        // An answer about another time is no answer
        $this->submit($path, $browser, $edited, ['seconds' => 2 * 3600 + 30 * 60 + 7], ['pace_confirmed' => $key]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->stored($edited));

        $this->submit($path, $browser, $edited, ['seconds' => 2 * 3600 + 30 * 60 + 6], ['pace_confirmed' => $key]);

        self::assertTrue($browser->getResponse()->isRedirect());
        self::assertSame(2 * 3600 + 30 * 60 + 6, $this->stored($edited)['seconds_to_solve']);
        self::assertSame(1, $this->confirmationsOf($edited));
        $this->same(__FUNCTION__, 'confirmed', $path, [$this->stored($edited), $this->expectedSecondsConfirmed($edited)]);
    }

    #[TestWith([self::FORM])]
    #[TestWith([self::MY_DATA])]
    public function testTheSameTimeFromTheSameDayIsSavedOnlyAfterItIsAnotherSolve(string $path): void
    {
        $browser = $this->browser();
        $scenario = new FirstTryScenario(self::getContainer());
        $scenario->add(self::PLAYER_USER, comment: 'first copy');
        $edited = $scenario->add(self::PLAYER_USER, time: '05:00:01', comment: 'second');
        TestingLogin::asPlayer($browser, self::PLAYER);

        $crawler = $this->submit($path, $browser, $edited, ['seconds' => 5 * 3600]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::DUPLICATE_ERROR, $crawler->text());
        $notice = $this->notice($crawler);
        self::assertStringContainsString('This exact time is already saved', $notice);
        self::assertCount(1, $crawler->filter('[data-action="first-try-check#confirmDuplicate"]'));
        self::assertSame(5 * 3600 + 1, $this->stored($edited)['seconds_to_solve']);
        // The warning is recorded like the form records it
        self::assertSame([['warning_shown', $edited, 'form']], $this->preventions());
        $this->same(__FUNCTION__, 'asked', $path, [422, $notice]);

        // 5:00:00 for 3000 pieces is also far off what anybody is known to do - both questions are answered
        $key = (string) $crawler->filter('[data-action="first-try-check#confirmPace"]')->attr('data-first-try-check-key-param');
        $this->submit($path, $browser, $edited, ['seconds' => 5 * 3600], ['duplicate_confirmed' => '1', 'pace_confirmed' => $key]);

        self::assertTrue($browser->getResponse()->isRedirect());
        self::assertSame(5 * 3600, $this->stored($edited)['seconds_to_solve']);
        self::assertContains(['saved_anyway', $edited, 'form'], $this->preventions());
        $this->same(__FUNCTION__, 'saved', $path, [$this->stored($edited), array_column($this->preventions(), 0)]);
    }

    #[TestWith([self::FORM])]
    #[TestWith([self::MY_DATA])]
    public function testATimeNobodyCanSolveInIsRefused(string $path): void
    {
        $browser = $this->browser();
        $scenario = new FirstTryScenario(self::getContainer());
        $edited = $scenario->add(self::PLAYER_USER, daysAgo: 2);
        TestingLogin::asPlayer($browser, self::PLAYER);
        $before = $this->stored($edited);

        // 3000 pieces in 20 seconds: first the question about the time ...
        $crawler = $this->submit($path, $browser, $edited, ['seconds' => 20]);
        self::assertResponseStatusCodeSame(422);
        $key = (string) $crawler->filter('[data-action="first-try-check#confirmPace"]')->attr('data-first-try-check-key-param');
        self::assertNotSame('', $key);
        $this->same(__FUNCTION__, 'asked', $path, [422, $this->notice($crawler)]);

        // ... and even answered "Yes, it's right" it is not saved
        $crawler = $this->submit($path, $browser, $edited, ['seconds' => 20], ['pace_confirmed' => $key]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(self::TOO_FAST_ERROR, $crawler->text());
        self::assertSame($before, $this->stored($edited));
        self::assertSame(0, $this->confirmationsOf($edited));
        $this->same(__FUNCTION__, 'refused', $path, [422, $this->stored($edited)]);
    }

    #[TestWith([self::FORM])]
    #[TestWith([self::MY_DATA])]
    public function testAResultNeedsItsTimeAndItsDay(string $path): void
    {
        $browser = $this->browser();
        $scenario = new FirstTryScenario(self::getContainer());
        $edited = $scenario->add(self::PLAYER_USER, daysAgo: 2);
        TestingLogin::asPlayer($browser, self::PLAYER);
        $before = $this->stored($edited);

        $crawler = $this->submit($path, $browser, $edited, ['seconds' => 0]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Please enter the time', $crawler->text());

        $crawler = $this->submit($path, $browser, $edited, ['day' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Date completed is required for speed puzzling.', $crawler->text());

        $crawler = $this->submit($path, $browser, $edited, ['day' => '31.02.2024']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This value is not a valid date.', $crawler->text());

        self::assertSame($before, $this->stored($edited));
    }

    #[TestWith([self::FORM])]
    #[TestWith([self::MY_DATA])]
    public function testAResultThatKnowsOnlyItsMonthBecomesExactWhenAnotherDayIsPicked(string $path): void
    {
        $browser = $this->browser();
        $scenario = new FirstTryScenario(self::getContainer());
        $edited = $scenario->add(self::PLAYER_USER, daysAgo: 2);
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE puzzle_solving_time SET finished_at = '2021-03-01 00:00:00', finished_at_precision = 'month' WHERE id = :id", ['id' => $edited]);
        self::getContainer()->get('doctrine')->getManager()->clear();
        TestingLogin::asPlayer($browser, self::PLAYER);

        // Each form sent back with its date as it opened: what is known of the day stays
        $this->submit($path, $browser, $edited, ['comment' => 'Some day in March']);

        self::assertTrue($browser->getResponse()->isRedirect());
        $stored = $this->stored($edited);
        self::assertSame(['Some day in March', 'month', '2021-03-01'], [$stored['comment'], $stored['finished_at_precision'], $stored['solved_on']]);
        $this->same(__FUNCTION__, 'kept', $path, [$stored]);

        $this->submit($path, $browser, $edited, ['day' => '14.03.2021']);

        self::assertTrue($browser->getResponse()->isRedirect());
        $stored = $this->stored($edited);
        self::assertSame([null, '2021-03-14'], [$stored['finished_at_precision'], $stored['solved_on']]);
        $this->same(__FUNCTION__, 'exact', $path, [$stored]);
    }

    /**
     * A cell edited in place saves through the same code - and never answers a question: a refusal that is one says
     * so (`row: true`, the reason in the form's words) and nothing is saved until it is answered in the row sheet.
     */
    public function testACellSavedInPlaceRefusesTheSameAndNeverAnswersAQuestion(): void
    {
        $browser = $this->browser();
        $scenario = new FirstTryScenario(self::getContainer());
        $held = $scenario->add(self::PLAYER_USER, daysAgo: 5, firstTry: true, comment: 'the first one');
        $twin = $scenario->add(self::PLAYER_USER, daysAgo: 2, time: '05:10:00', comment: 'twin');
        $edited = $scenario->add(self::PLAYER_USER, daysAgo: 2, time: '05:10:01', comment: 'edited');
        TestingLogin::asPlayer($browser, self::PLAYER);
        $before = $this->stored($edited);

        $refusals = [
            // The first try is elsewhere
            [['column' => 'first_try', 'from' => '0', 'value' => '1'], self::FIRST_TRY_ERROR],
            // The same time from the same day
            [['column' => 'time', 'from' => '5:10:01', 'value' => '5:10:00'], self::DUPLICATE_ERROR],
            // A time far off what anybody is known to do
            [['column' => 'time', 'from' => '5:10:01', 'value' => '1:00:00'], 'Please check the time'],
        ];

        foreach ($refusals as [$parameters, $message]) {
            // Whatever is sent along as an answer is not read
            $browser->request('POST', '/en/my-data/cell/result/' . $edited, [...$parameters, 'first_try_resolution' => 'move', 'duplicate_confirmed' => '1', 'pace_confirmed' => 'yes'], [], [...self::SAME_ORIGIN, 'HTTP_X_CSRF_TOKEN' => 'csrf-token']);

            self::assertResponseStatusCodeSame(422, $parameters['value']);
            /** @var array{status: string, message: string, row: bool} $answer */
            $answer = json_decode((string) $browser->getResponse()->getContent(), true);
            self::assertTrue($answer['row'], $parameters['value']);
            self::assertStringContainsString($message, $answer['message']);
            self::assertSame($before, $this->stored($edited));
        }

        self::assertTrue($scenario->isFirstTry($held));
        self::assertSame(5 * 3600 + 10 * 60, $this->stored($twin)['seconds_to_solve']);

        // What needs no answer is saved: the comment
        $browser->request('POST', '/en/my-data/cell/result/' . $edited, ['column' => 'comment', 'from' => 'edited', 'value' => 'Edited in place'], [], [...self::SAME_ORIGIN, 'HTTP_X_CSRF_TOKEN' => 'csrf-token']);
        self::assertResponseIsSuccessful();
        self::assertSame('Edited in place', $this->stored($edited)['comment']);
    }

    private function browser(): KernelBrowser
    {
        $browser = self::createClient();
        $browser->disableReboot();

        return $browser;
    }

    /**
     * One save of one result with a change and the answers to the questions - through the edit form or through the
     * row sheet of My data. Everything the change does not name is sent back as the form showed it.
     *
     * @param array{seconds?: int, day?: string, first_try?: bool, comment?: string} $change
     * @param array<string, string> $answers first_try_resolution, duplicate_confirmed, pace_confirmed
     */
    private function submit(string $path, KernelBrowser $browser, string $timeId, array $change, array $answers = []): Crawler
    {
        if ($path === self::FORM) {
            $crawler = $browser->request('GET', '/en/edit-time/' . $timeId);
            self::assertResponseIsSuccessful();
            $value = static fn (string $field): string => (string) $crawler->filter('#edit_puzzle_solving_time_form_' . $field)->attr('value');

            $form = [
                '_token' => $value('_token'),
                'mode' => 'speed_puzzling',
                'brand' => $value('brand'),
                'puzzle' => $value('puzzle'),
                'timeHours' => $value('timeHours'),
                'timeMinutes' => $value('timeMinutes'),
                'timeSeconds' => $value('timeSeconds'),
                'finishedAt' => $value('finishedAt'),
                'comment' => $crawler->filter('#edit_puzzle_solving_time_form_comment')->text(normalizeWhitespace: false),
            ];
            self::assertNotSame('', $form['puzzle']);

            if ($crawler->filter('#edit_puzzle_solving_time_form_firstAttempt[checked]')->count() > 0) {
                $form['firstAttempt'] = '1';
            }

            if (isset($change['seconds'])) {
                $form['timeHours'] = (string) intdiv($change['seconds'], 3600);
                $form['timeMinutes'] = (string) intdiv($change['seconds'] % 3600, 60);
                $form['timeSeconds'] = (string) ($change['seconds'] % 60);
            }

            if (isset($change['day'])) {
                $form['finishedAt'] = $change['day'];
            }

            if (isset($change['comment'])) {
                $form['comment'] = $change['comment'];
            }

            if (isset($change['first_try'])) {
                unset($form['firstAttempt']);

                if ($change['first_try']) {
                    $form['firstAttempt'] = '1';
                }
            }

            return $browser->request('POST', '/en/edit-time/' . $timeId, ['edit_puzzle_solving_time_form' => $form, ...$answers]);
        }

        $crawler = $browser->request('GET', '/en/my-data/result/' . $timeId);
        self::assertResponseIsSuccessful();
        $sheet = $crawler->filter('[data-testid="my-data-row-form"]');
        $value = static fn (string $name): string => (string) $sheet->filter('input[name="' . $name . '"]')->attr('value');

        $fields = [
            '_token' => $value('_token'),
            'hours' => $value('hours'),
            'minutes' => $value('minutes'),
            'seconds' => $value('seconds'),
            'day' => $value('day'),
            'comment' => $sheet->filter('textarea[name="comment"]')->text(normalizeWhitespace: false),
        ];

        if ($sheet->filter('input[name="first_try"][checked]')->count() > 0) {
            $fields['first_try'] = '1';
        }

        if (isset($change['seconds'])) {
            $fields['hours'] = (string) intdiv($change['seconds'], 3600);
            $fields['minutes'] = (string) intdiv($change['seconds'] % 3600, 60);
            $fields['seconds'] = (string) ($change['seconds'] % 60);
        }

        if (isset($change['day'])) {
            $fields['day'] = $change['day'];
        }

        if (isset($change['comment'])) {
            $fields['comment'] = $change['comment'];
        }

        if (isset($change['first_try'])) {
            unset($fields['first_try']);

            if ($change['first_try']) {
                $fields['first_try'] = '1';
            }
        }

        return $browser->request('POST', '/en/my-data/result/' . $timeId, [...$fields, ...$answers], [], self::SAME_ORIGIN);
    }

    /**
     * The notice asking the question - the edit form's own partial on both pages.
     */
    private function notice(Crawler $crawler): string
    {
        $notice = $crawler->filter('[data-first-try-check-target="notice"]');
        self::assertCount(1, $notice);

        return trim((string) preg_replace('/\s+/', ' ', $notice->text()));
    }

    /**
     * The second path of a case saw what the first one saw.
     *
     * @param array<mixed> $outcome
     */
    private function same(string $case, string $step, string $path, array $outcome): void
    {
        $key = $case . ' / ' . $step;

        if (isset(self::$seen[$key]) && self::$seen[$key]['path'] !== $path) {
            self::assertSame(self::$seen[$key]['outcome'], $outcome, sprintf('%s: %s and %s end differently', $key, self::$seen[$key]['path'], $path));

            return;
        }

        self::$seen[$key] = ['path' => $path, 'outcome' => $outcome];
    }

    /**
     * What a result holds - without what differs between two runs of one case (ids, the moment of the save).
     *
     * The solve's **day**, not its time of day - the one thing the two differ in on purpose: the edit form is
     * date-only and stores midnight of the day it shows with every save; My data sends the stored moment back while
     * the day is not changed, so a comment fixed in a cell does not reorder the results of that day
     * (PuzzleSolvingTime::modify() compares days for the same reason).
     *
     * @return array<string, mixed>
     */
    private function stored(string $timeId): array
    {
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT seconds_to_solve, CAST(finished_at AS DATE)::text AS solved_on, finished_at_precision, first_attempt, comment, unboxed, puzzling_type, competition_id, suspicious FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function confirmationsOf(string $timeId): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM suspicious_time_confirmation WHERE time_id = :id', ['id' => $timeId]); // @phpstan-ignore cast.int
    }

    private function expectedSecondsConfirmed(string $timeId): mixed
    {
        return self::getContainer()->get(Connection::class)->fetchOne('SELECT expected_seconds FROM suspicious_time_confirmation WHERE time_id = :id', ['id' => $timeId]);
    }

    /**
     * @return list<array{string, string, string}> kind, time id, via - oldest first
     */
    private function preventions(): array
    {
        /** @var list<array{kind: string, time_id: string, via: string}> $rows */
        $rows = self::getContainer()->get(Connection::class)->fetchAllAssociative(
            'SELECT kind, time_id, via FROM result_duplicate_prevention WHERE player_id = :playerId ORDER BY created_at, id',
            ['playerId' => self::PLAYER],
        );

        return array_map(static fn (array $row): array => [$row['kind'], $row['time_id'], $row['via']], $rows);
    }
}
