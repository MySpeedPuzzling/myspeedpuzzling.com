<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ReviewResults;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\DetectSuspiciousTimes;
use SpeedPuzzling\Web\Message\NotifySuspiciousTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The player side of time verification (docs/features/suspicious-time-review.md, "Where they see it"): the banner,
 * the "Awaiting verification" section of "Review your results", the replies and the suggested time in the edit form.
 * Mia's 21:40 on Silent Pier is marked (reasons: faster than usual, hours left out - 1:21:40), she was told by the
 * notice run (SuspiciousTimesFixture).
 */
final class AwaitingVerificationTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string MIA = SuspiciousTimesFixture::PLAYER_MARKED;

    public function testTheBannerOnTheHubAndTheOwnProfile(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::MIA);

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/hub');

        $this->assertResponseIsSuccessful();
        self::assertSame('1 of your results needs verification.', $crawler->filter('[data-testid="review-results-banner-verification"]')->text());
        self::assertCount(1, $crawler->filter('[data-testid="review-results-banner"] a[href="/en/review-results"]'));
        self::assertCount(1, array_filter($this->executedSql($browser), static fn (string $sql): bool => str_contains($sql, 'suspicious_time_notice')), 'The banner query counts it - no query of its own');
        self::assertCount(1, array_filter($this->executedSql($browser), static fn (string $sql): bool => str_contains($sql, 'result_auto_removal')));

        $crawler = $browser->request('GET', '/en/player-profile/' . self::MIA);
        self::assertCount(1, $crawler->filter('[data-testid="review-results-banner-verification"]'));

        // Somebody else on Mia's profile: no banner, no query about it
        TestingLogin::asPlayer($browser, SuspiciousTimesFixture::PLAYER_STEADY);
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/player-profile/' . self::MIA);

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="review-results-banner-verification"]'));

        foreach ($this->executedSql($browser) as $sql) {
            self::assertStringNotContainsString('suspicious_time_notice', $sql, 'Somebody else\'s profile costs no review query');
        }
    }

    public function testTheBannerCountsOnlyUnansweredNoticesOfTheMarkInForce(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::MIA);

        // Told by hand - never by the banner
        $this->database($browser)->executeStatement("UPDATE suspicious_time_notice SET via = 'manual_email' WHERE id = :id", ['id' => SuspiciousTimesFixture::NOTICE_MARKED]);
        self::assertSame(0, $this->bannerLines($browser));

        // Answered
        $this->database($browser)->executeStatement("UPDATE suspicious_time_notice SET via = 'run', response = 'left_as_is' WHERE id = :id", ['id' => SuspiciousTimesFixture::NOTICE_MARKED]);
        self::assertSame(0, $this->bannerLines($browser));

        // A notice of an earlier mark
        $this->database($browser)->executeStatement("UPDATE suspicious_time_notice SET response = NULL, marked_at = marked_at - INTERVAL '1 day' WHERE id = :id", ['id' => SuspiciousTimesFixture::NOTICE_MARKED]);
        self::assertSame(0, $this->bannerLines($browser));

        // The mark in force again, but the time counts again (unmarked)
        $this->database($browser)->executeStatement("UPDATE suspicious_time_notice SET marked_at = marked_at + INTERVAL '1 day' WHERE id = :id", ['id' => SuspiciousTimesFixture::NOTICE_MARKED]);
        self::assertSame(1, $this->bannerLines($browser));
        $this->database($browser)->executeStatement("UPDATE suspicious_time_case SET status = 'trusted' WHERE id = :id", ['id' => SuspiciousTimesFixture::CASE_MARKED]);
        self::assertSame(0, $this->bannerLines($browser));
    }

    public function testTheSectionComesFirstWithTheChosenReasonsAndTheNote(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::MIA);

        $crawler = $browser->request('GET', '/en/review-results');

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="review-results-empty"]'));
        self::assertSame('awaiting-verification', $crawler->filterXPath('//h1[contains(., "Review your results")]/following::h2[1]/parent::section')->attr('id'));

        $card = $crawler->filter('[data-testid="suspicious-time"]');
        self::assertCount(1, $card);
        self::assertSame(SuspiciousTimesFixture::TIME_MARKED, $card->attr('data-time-id'));
        self::assertStringContainsString('Silent Pier', $card->text());
        self::assertStringContainsString('21:40', $card->text());
        self::assertSame([
            'This is much faster than your usual 01:00:00 for 520 pieces.',
            'Perhaps the hours box was left empty - 01:21:40?',
        ], $card->filter('[data-testid="suspicious-time-reasons"] li')->each(static fn (Crawler $reason): string => trim($reason->text())));
        self::assertSame('Note from the moderator: Please check the hours.', trim($card->filter('[data-testid="suspicious-time-note"]')->text()));

        // Fix the time brings the suggested time; nothing suggests a group or another edition
        self::assertSame(
            '/en/edit-time/' . SuspiciousTimesFixture::TIME_MARKED . '?context=review-results&suggested_seconds=4900&suggested_for=1300',
            $card->filter('[data-testid="suspicious-time-fix"]')->attr('href'),
        );
        self::assertCount(0, $card->filter('[data-testid="suspicious-time-add-people"]'));
        self::assertCount(0, $card->filter('[data-testid="suspicious-time-change-puzzle"]'));
        self::assertCount(1, $card->filter('[data-testid="suspicious-time-correct"] textarea[name="message"][maxlength="500"]'));
        self::assertCount(1, $card->filter('[data-testid="suspicious-time-leave"]'));

        // Neutral wording
        self::assertStringNotContainsStringIgnoringCase('suspic', $crawler->filter('#awaiting-verification')->text());
        self::assertStringNotContainsStringIgnoringCase('suspect', $crawler->filter('#awaiting-verification')->text());
    }

    public function testOnlyTheOwnResultsAreListedAlsoForAPrivateProfile(): void
    {
        $browser = self::createClient();
        $this->database($browser)->executeStatement('UPDATE player SET is_private = true WHERE id = :id', ['id' => self::MIA]);

        TestingLogin::asPlayer($browser, self::MIA);
        $crawler = $browser->request('GET', '/en/review-results');
        self::assertCount(1, $crawler->filter('[data-testid="suspicious-time"]'));

        TestingLogin::asPlayer($browser, SuspiciousTimesFixture::PLAYER_STEADY);
        $crawler = $browser->request('GET', '/en/review-results');
        self::assertCount(0, $crawler->filter('#awaiting-verification'));
    }

    public function testAPairResultIsListedForEveryoneOfThePair(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        // The pair Fay saved, flagged "by SQL": a marked case without reasons, Fay and Pat are told
        $bus = $browser->getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new DetectSuspiciousTimes());
        $bus->dispatch(new NotifySuspiciousTimes());

        TestingLogin::asPlayer($browser, SuspiciousTimesFixture::PLAYER_PARTNER);
        $crawler = $browser->request('GET', '/en/review-results');

        $this->assertResponseIsSuccessful();
        $card = $crawler->filter('[data-testid="suspicious-time"][data-time-id="' . SuspiciousTimesFixture::TIME_SQL_FLAGGED . '"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('Garden Gate', $card->text());
        self::assertStringContainsString('Pair result', $card->text());
        self::assertCount(0, $card->filter('[data-testid="suspicious-time-reasons"]'));
        self::assertCount(0, $card->filter('[data-testid="suspicious-time-note"]'));
        self::assertSame(
            '/en/edit-time/' . SuspiciousTimesFixture::TIME_SQL_FLAGGED . '?context=review-results',
            $card->filter('[data-testid="suspicious-time-fix"]')->attr('href'),
        );

        TestingLogin::asPlayer($browser, SuspiciousTimesFixture::PLAYER_FLAGGED);
        $crawler = $browser->request('GET', '/en/review-results');
        self::assertCount(1, $crawler->filter('[data-testid="suspicious-time"][data-time-id="' . SuspiciousTimesFixture::TIME_SQL_FLAGGED . '"]'));
    }

    public function testAnotherEditionCanBeChangedOnlyByWhoeverSavedTheResult(): void
    {
        $browser = self::createClient();
        $this->database($browser)->executeStatement(
            'UPDATE suspicious_time_case SET reasons_shown = :reasons WHERE id = :id',
            [
                'id' => SuspiciousTimesFixture::CASE_MARKED,
                'reasons' => '[{"code": "other_edition", "params": {"puzzle_id": "' . SuspiciousTimesFixture::PUZZLE_ORCHARD . '", "name": "Quiet Orchard", "pieces": 520}}, {"code": "comment_mentions_group", "params": {"word": "team"}}, {"code": "often_in_group", "params": {"group_results": 3, "group_expected": 1500}}]',
            ],
        );
        TestingLogin::asPlayer($browser, self::MIA);

        $crawler = $browser->request('GET', '/en/review-results');

        $card = $crawler->filter('[data-testid="suspicious-time"]');
        self::assertCount(1, $card->filter('[data-testid="suspicious-time-change-puzzle"]'));
        self::assertCount(1, $card->filter('[data-testid="suspicious-time-add-people"]'));
        self::assertCount(0, $card->filter('[data-testid="suspicious-time-only-tracker"]'));
        // No suggested time - and a moderator-only hint is never shown
        self::assertSame('/en/edit-time/' . SuspiciousTimesFixture::TIME_MARKED . '?context=review-results', $card->filter('[data-testid="suspicious-time-fix"]')->attr('href'));
        self::assertCount(2, $card->filter('[data-testid="suspicious-time-reasons"] li'));
        self::assertStringNotContainsString('pairs or teams (3 results)', $card->text());

        // Somebody of the result who did not save it may not move it to another puzzle: Pat, in the pair Fay saved
        // (flagged "by SQL" - the scan gives it a marked case, the notice run tells Fay and Pat)
        $browser->disableReboot();
        $bus = $browser->getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new DetectSuspiciousTimes());
        $bus->dispatch(new NotifySuspiciousTimes());
        $this->database($browser)->executeStatement(
            'UPDATE suspicious_time_case SET reasons_shown = :reasons WHERE time_id = :timeId',
            [
                'timeId' => SuspiciousTimesFixture::TIME_SQL_FLAGGED,
                'reasons' => '[{"code": "other_edition", "params": {"puzzle_id": "' . SuspiciousTimesFixture::PUZZLE_ORCHARD . '", "name": "Quiet Orchard", "pieces": 520}}]',
            ],
        );
        TestingLogin::asPlayer($browser, SuspiciousTimesFixture::PLAYER_PARTNER);
        $crawler = $browser->request('GET', '/en/review-results');

        $card = $crawler->filter('[data-testid="suspicious-time"][data-time-id="' . SuspiciousTimesFixture::TIME_SQL_FLAGGED . '"]');
        self::assertCount(1, $card);
        self::assertCount(0, $card->filter('[data-testid="suspicious-time-change-puzzle"]'));
        self::assertCount(1, $card->filter('[data-testid="suspicious-time-only-tracker"]'));
    }

    public function testAnotherEditionHiddenAfterTheMarkIsNeverNamed(): void
    {
        $browser = self::createClient();
        $this->database($browser)->executeStatement(
            'UPDATE suspicious_time_case SET reasons_shown = :reasons WHERE id = :id',
            [
                'id' => SuspiciousTimesFixture::CASE_MARKED,
                'reasons' => '[{"code": "other_edition", "params": {"puzzle_id": "' . SuspiciousTimesFixture::PUZZLE_ORCHARD . '", "name": "Quiet Orchard", "pieces": 520}}, {"code": "comment_mentions_group", "params": {"word": "team"}}]',
            ],
        );
        // A competition takes the puzzle into a round and keeps it secret until then
        $this->database($browser)->executeStatement(
            "UPDATE puzzle SET approved = false, hide_until = NOW() + INTERVAL '30 days' WHERE id = :id",
            ['id' => SuspiciousTimesFixture::PUZZLE_ORCHARD],
        );
        TestingLogin::asPlayer($browser, self::MIA);

        $crawler = $browser->request('GET', '/en/review-results');

        $card = $crawler->filter('[data-testid="suspicious-time"]');
        self::assertCount(1, $card->filter('[data-testid="suspicious-time-reasons"] li'));
        self::assertStringNotContainsString('Quiet Orchard', $card->text());
        self::assertCount(0, $card->filter('[data-testid="suspicious-time-change-puzzle"]'));
    }

    public function testTheTimeIsCorrect(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::MIA);
        $crawler = $browser->request('GET', '/en/review-results');
        $form = $crawler->filter('[data-testid="suspicious-time-correct"] form');

        $browser->request('POST', (string) $form->attr('action'), [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
            'message' => '  I am that fast on 500s - see my other results.  ',
        ]);

        $this->assertResponseRedirects('/en/review-results#awaiting-verification');
        $crawler = $browser->followRedirect();
        self::assertStringContainsString('Thank you – a moderator will take a look at it.', $crawler->filter('.alert-success')->text());

        $card = $crawler->filter('[data-testid="suspicious-time"]');
        self::assertStringContainsString('Your message: I am that fast on 500s - see my other results.', $card->filter('[data-testid="suspicious-time-waiting"]')->text());
        self::assertCount(0, $card->filter('form'));
        self::assertSame(
            ['response' => 'says_correct', 'response_text' => 'I am that fast on 500s - see my other results.'],
            $this->database($browser)->fetchAssociative('SELECT response, response_text FROM suspicious_time_notice WHERE id = :id', ['id' => SuspiciousTimesFixture::NOTICE_MARKED]),
        );
        self::assertSame(0, $this->bannerLines($browser));
        self::assertTrue((bool) $this->database($browser)->fetchOne('SELECT suspicious FROM puzzle_solving_time WHERE id = :id', ['id' => SuspiciousTimesFixture::TIME_MARKED]));

        // Once per mark
        $browser->request('POST', (string) $form->attr('action'), [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
            'message' => 'Another message',
        ]);

        $this->assertResponseRedirects('/en/review-results#awaiting-verification');
        self::assertStringContainsString('You have already told us this time is correct', $browser->followRedirect()->filter('.alert-info')->text());
        self::assertSame('I am that fast on 500s - see my other results.', $this->database($browser)->fetchOne('SELECT response_text FROM suspicious_time_notice WHERE id = :id', ['id' => SuspiciousTimesFixture::NOTICE_MARKED]));
    }

    public function testLeaveItAsItIs(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::MIA);
        $crawler = $browser->request('GET', '/en/review-results');
        $form = $crawler->filter('form[data-testid="suspicious-time-leave"]');

        $browser->request('POST', (string) $form->attr('action'), [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
        ]);

        $this->assertResponseRedirects('/en/review-results#awaiting-verification');
        $crawler = $browser->followRedirect();
        self::assertStringContainsString('we won\'t ask about this result again', $crawler->filter('.alert-success')->text());

        // Still marked, still listed - it can be fixed or said correct any time
        $card = $crawler->filter('[data-testid="suspicious-time"]');
        self::assertCount(1, $card->filter('[data-testid="suspicious-time-left"]'));
        self::assertCount(1, $card->filter('[data-testid="suspicious-time-fix"]'));
        self::assertCount(1, $card->filter('[data-testid="suspicious-time-correct"]'));
        self::assertCount(0, $card->filter('[data-testid="suspicious-time-leave"]'));
        self::assertSame('left_as_is', $this->database($browser)->fetchOne('SELECT response FROM suspicious_time_notice WHERE id = :id', ['id' => SuspiciousTimesFixture::NOTICE_MARKED]));
        self::assertTrue((bool) $this->database($browser)->fetchOne('SELECT suspicious FROM puzzle_solving_time WHERE id = :id', ['id' => SuspiciousTimesFixture::TIME_MARKED]));
        self::assertSame(0, $this->bannerLines($browser));
    }

    public function testSomebodyElsesCaseIsNotFound(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $bus = $browser->getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new DetectSuspiciousTimes());
        $bus->dispatch(new NotifySuspiciousTimes());

        TestingLogin::asPlayer($browser, self::MIA);
        $crawler = $browser->request('GET', '/en/review-results');
        $correct = $crawler->filter('[data-testid="suspicious-time-correct"] form');
        $leave = $crawler->filter('form[data-testid="suspicious-time-leave"]');

        // Pat's own review page (his pair awaits verification), so his session holds a token
        TestingLogin::asPlayer($browser, SuspiciousTimesFixture::PLAYER_PARTNER);
        $crawler = $browser->request('GET', '/en/review-results');
        $token = (string) $crawler->filter('form[data-testid="suspicious-time-leave"] input[name="_token"]')->attr('value');

        $browser->request('POST', (string) $correct->attr('action'), ['_token' => $token, 'message' => 'Hi']);
        $this->assertResponseStatusCodeSame(404);

        $browser->request('POST', (string) $leave->attr('action'), ['_token' => $token]);
        $this->assertResponseStatusCodeSame(404);

        self::assertNull($this->database($browser)->fetchOne('SELECT response FROM suspicious_time_notice WHERE id = :id', ['id' => SuspiciousTimesFixture::NOTICE_MARKED]));
    }

    public function testAReplyWithoutAValidTokenIsRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::MIA);

        $browser->request('POST', '/en/review-results/verification/' . SuspiciousTimesFixture::CASE_MARKED . '/correct', ['_token' => 'nope']);
        $this->assertResponseStatusCodeSame(403);

        $browser->request('POST', '/en/review-results/verification/' . SuspiciousTimesFixture::CASE_MARKED . '/leave', ['_token' => 'nope']);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testTheModeratorsAnswers(): void
    {
        $browser = self::createClient();
        // "The time is correct", the moderator keeps the mark with a note: on the open card, nothing to send again
        $this->database($browser)->executeStatement(
            "UPDATE suspicious_time_notice SET response = 'says_correct', responded_at = NOW(), answer = 'kept', answer_note = 'The puzzle has 520 pieces, not 52.', answered_at = NOW() WHERE id = :id",
            ['id' => SuspiciousTimesFixture::NOTICE_MARKED],
        );
        TestingLogin::asPlayer($browser, self::MIA);

        $crawler = $browser->request('GET', '/en/review-results');

        $card = $crawler->filter('[data-testid="suspicious-time"]');
        self::assertSame('A moderator checked it: it stays awaiting verification – The puzzle has 520 pieces, not 52.', trim($card->filter('[data-testid="suspicious-time-kept"]')->text()));
        self::assertCount(0, $card->filter('[data-testid="suspicious-time-correct"]'));
        self::assertCount(1, $card->filter('[data-testid="suspicious-time-fix"]'));
        self::assertCount(0, $crawler->filter('[data-testid="suspicious-time-answered"]'));

        // Trusted: the time counts again, the answer is listed for 30 days
        $this->database($browser)->executeStatement("UPDATE suspicious_time_notice SET answer = 'trusted', answer_note = NULL WHERE id = :id", ['id' => SuspiciousTimesFixture::NOTICE_MARKED]);
        $this->database($browser)->executeStatement("UPDATE suspicious_time_case SET status = 'trusted' WHERE id = :id", ['id' => SuspiciousTimesFixture::CASE_MARKED]);
        $this->database($browser)->executeStatement('UPDATE puzzle_solving_time SET suspicious = false WHERE id = :id', ['id' => SuspiciousTimesFixture::TIME_MARKED]);

        $crawler = $browser->request('GET', '/en/review-results');

        self::assertCount(0, $crawler->filter('[data-testid="suspicious-time"]'));
        $answered = $crawler->filter('[data-testid="suspicious-time-answered"]');
        self::assertCount(1, $answered);
        self::assertStringContainsString('Silent Pier', $answered->text());
        self::assertStringContainsString('A moderator checked it: your time counts again.', $answered->text());
        self::assertCount(0, $crawler->filter('[data-testid="review-results-empty"]'));

        $this->database($browser)->executeStatement("UPDATE suspicious_time_notice SET answered_at = NOW() - INTERVAL '31 days' WHERE id = :id", ['id' => SuspiciousTimesFixture::NOTICE_MARKED]);
        $crawler = $browser->request('GET', '/en/review-results');
        self::assertCount(0, $crawler->filter('#awaiting-verification'));
    }

    public function testASuggestionForAnEarlierTimeIsNotOffered(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::MIA);

        // Mia changed her time since the mark (and it still looked off): 01:21:40 was worked out for 21:40
        $this->database($browser)->executeStatement('UPDATE puzzle_solving_time SET seconds_to_solve = 1500 WHERE id = :id', ['id' => SuspiciousTimesFixture::TIME_MARKED]);

        $crawler = $browser->request('GET', '/en/review-results');
        $card = $crawler->filter('[data-testid="suspicious-time"]');

        self::assertCount(1, $card);
        self::assertSame(
            '/en/edit-time/' . SuspiciousTimesFixture::TIME_MARKED . '?context=review-results',
            $card->filter('[data-testid="suspicious-time-fix"]')->attr('href'),
        );
    }

    public function testFixTheTimeOpensTheEditFormWithTheSuggestedTime(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::MIA);

        $crawler = $browser->request('GET', '/en/edit-time/' . SuspiciousTimesFixture::TIME_MARKED . '?context=review-results&suggested_seconds=4900&suggested_for=1300');

        $this->assertResponseIsSuccessful();
        self::assertSame(['1', '21', '40'], $this->timeInputs($crawler));
        self::assertStringContainsString('Suggested time from the verification note: 01:21:40', $crawler->filter('[data-testid="suggested-time-hint"]')->text());
        self::assertStringContainsString('Review your results', $crawler->filter('a[href="/en/review-results#awaiting-verification"]')->text());

        // Anything but a positive number of seconds is ignored, and so is a suggestion made for another time than the
        // result holds (a review page opened before an edit) or for no time at all
        foreach (['0&suggested_for=1300', '-60&suggested_for=1300', 'abc&suggested_for=1300', '12abc&suggested_for=1300', '99999999&suggested_for=1300', '4900&suggested_for=1200', '4900'] as $value) {
            $crawler = $browser->request('GET', '/en/edit-time/' . SuspiciousTimesFixture::TIME_MARKED . '?suggested_seconds=' . $value);

            $this->assertResponseIsSuccessful();
            self::assertSame(['0', '21', '40'], $this->timeInputs($crawler), $value);
            self::assertCount(0, $crawler->filter('[data-testid="suggested-time-hint"]'), $value);
        }

        // Only for somebody who may edit the result
        TestingLogin::asPlayer($browser, SuspiciousTimesFixture::PLAYER_STEADY);
        $browser->request('GET', '/en/edit-time/' . SuspiciousTimesFixture::TIME_MARKED . '?suggested_seconds=4900&suggested_for=1300');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testFixingTheTimeLeadsBackToTheReviewPage(): void
    {
        $browser = self::createClient();
        // Mia's usual time for 520 pieces is about 1:20:00 - the fixed time can be judged (without anything to judge it
        // by it would go back to the moderators)
        $this->database($browser)->executeStatement(
            "INSERT INTO player_baseline (id, player_id, pieces_count, baseline_seconds, qualifying_solves_count, computed_at, baseline_type) VALUES (:id, :playerId, 520, 4800, 5, NOW(), 'direct')",
            ['id' => Uuid::uuid7()->toString(), 'playerId' => self::MIA],
        );
        TestingLogin::asPlayer($browser, self::MIA);
        $crawler = $browser->request('GET', '/en/review-results');
        $crawler = $browser->click($crawler->filter('[data-testid="suspicious-time-fix"]')->link());

        // Saved with the suggested time as it was filled in
        $browser->submit($crawler->filter('form[name="edit_puzzle_solving_time_form"]')->form());

        $this->assertResponseRedirects('/en/review-results#awaiting-verification');
        $crawler = $browser->followRedirect();
        self::assertEquals(4900, $this->database($browser)->fetchOne('SELECT seconds_to_solve FROM puzzle_solving_time WHERE id = :id', ['id' => SuspiciousTimesFixture::TIME_MARKED]));
        // The detector's mark, and the fixed time passes: it counts again
        self::assertFalse((bool) $this->database($browser)->fetchOne('SELECT suspicious FROM puzzle_solving_time WHERE id = :id', ['id' => SuspiciousTimesFixture::TIME_MARKED]));
        self::assertCount(0, $crawler->filter('[data-testid="suspicious-time"]'));
        self::assertSame('fixed', $this->database($browser)->fetchOne('SELECT response FROM suspicious_time_notice WHERE id = :id', ['id' => SuspiciousTimesFixture::NOTICE_MARKED]));
    }

    private function bannerLines(KernelBrowser $browser): int
    {
        return $browser->request('GET', '/en/hub')->filter('[data-testid="review-results-banner-verification"]')->count();
    }

    /**
     * @return list<string> hours, minutes, seconds as the form shows them
     */
    private function timeInputs(Crawler $crawler): array
    {
        return array_map(
            static fn (string $field): string => (string) $crawler->filter('#edit_puzzle_solving_time_form_' . $field)->attr('value'),
            ['timeHours', 'timeMinutes', 'timeSeconds'],
        );
    }

    private function database(KernelBrowser $browser): Connection
    {
        return $browser->getContainer()->get(Connection::class);
    }
}
