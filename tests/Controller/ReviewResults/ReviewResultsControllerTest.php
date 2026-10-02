<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ReviewResults;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\DuplicateResults\DailyDuplicateDetection;
use SpeedPuzzling\Web\Tests\DataFixtures\DuplicateResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\FirstTryScenario;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\DuplicateDetectedBy;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Review your results", its actions, the recap notice, the banner and the redirects of removed ids
 * (docs/features/duplicate-results.md). The fixtures come with Dana Twin's four open cases and Tom Twin's case
 * of the pair they both saved (DuplicateResultsFixture).
 */
final class ReviewResultsControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string DANA = DuplicateResultsFixture::PLAYER_TWINS;
    private const string TOM = DuplicateResultsFixture::PLAYER_TWINS_TEAMMATE;

    public function testGuestIsSentToLogin(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/review-results');

        $this->assertResponseRedirects('/login?return=/en/review-results');
    }

    public function testThePlayerSeesTheirOwnCasesOnly(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::DANA);

        $crawler = $browser->request('GET', '/en/review-results');

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('noindex', (string) $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertCount(4, $crawler->filter('[data-testid="duplicate-set"]'));
        // Tier A/B first, Tier C last
        self::assertStringContainsString('Possibly the same solve twice', $crawler->filter('[data-testid="duplicate-set"]')->last()->text());

        TestingLogin::asPlayer($browser, self::TOM);
        $crawler = $browser->request('GET', '/en/review-results');

        $cases = $crawler->filter('[data-testid="duplicate-set"]');
        self::assertCount(1, $cases);
        // Dana's copy is hers to delete, Tom may delete his own
        self::assertSame('Only Dana Twin can delete it.', $cases->filter('[data-testid="duplicate-only-tracker"]')->text());
        self::assertCount(1, $cases->filter('form[action$="/keep"] input[name="keep"][value="' . DuplicateResultsFixture::TIME_TEAMMATE_A . '"]'));
        self::assertStringContainsString('Delete my copy', $cases->text());
    }

    public function testNothingToReview(): void
    {
        $browser = self::createClient();
        // Tom has no first tries at all; his only case is decided
        $this->database($browser)->executeStatement(
            "UPDATE result_duplicate_case SET status = 'both_real' WHERE player_id = :playerId",
            ['playerId' => self::TOM],
        );
        TestingLogin::asPlayer($browser, self::TOM);

        $crawler = $browser->request('GET', '/en/review-results');

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="review-results-empty"]'));
        self::assertCount(0, $crawler->filter('[data-testid="duplicate-set"]'));

        // Nor an empty banner wrapper on the Hub
        $browser->request('GET', '/en/hub');
        $this->assertResponseIsSuccessful();
        self::assertDoesNotMatchRegularExpression('~<div class="mt-3">\s*</div>~', (string) $browser->getResponse()->getContent());
    }

    public function testKeepingACopy(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::DANA);
        $crawler = $browser->request('GET', '/en/review-results');

        $form = $this->keepForm($crawler, DuplicateResultsFixture::TIME_STRONG_A);
        $browser->request('POST', (string) $form->attr('action'), [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
            'keep' => DuplicateResultsFixture::TIME_STRONG_A,
            'via' => 'review_page',
            'copies' => $form->filter('input[name="copies[]"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')),
        ]);

        $this->assertResponseRedirects('/en/review-results');
        self::assertStringContainsString('the copy is deleted', $browser->followRedirect()->text());
        self::assertFalse($this->database($browser)->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE id = :id', ['id' => DuplicateResultsFixture::TIME_STRONG_B]));
    }

    public function testAResultSavedByThreeTeammatesIsOneCard(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $scenario = new FirstTryScenario($browser->getContainer());
        $bySarah = $scenario->add(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, ['#admin', '#player3'], daysAgo: 3, comment: 'Sarah');
        $byAdmin = $scenario->add(FirstTryScenario::ADMIN_USER_ID, ['#player4', '#player3'], daysAgo: 3, comment: 'Admin');
        $byMichael = $scenario->add(PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID, ['#player4', '#admin'], daysAgo: 3, comment: 'Michael');
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/review-results');

        $this->assertResponseIsSuccessful();
        $card = $crawler->filter('[data-testid="duplicate-set"]')->reduce(
            static fn (Crawler $set): bool => $set->filter('[data-time-id="' . $bySarah . '"]')->count() > 0,
        );
        self::assertCount(1, $card);
        self::assertSame([$bySarah, $byAdmin, $byMichael], $card->filter('[data-testid="duplicate-copy"]')->each(
            static fn (Crawler $copy): string => (string) $copy->attr('data-time-id'),
        ));
        // Exactly one original - the oldest copy
        self::assertCount(1, $card->filter('[data-testid="duplicate-original"]'));
        self::assertCount(1, $card->filter('[data-time-id="' . $bySarah . '"] [data-testid="duplicate-original"]'));
        // Sarah may delete only her own copy; the others are their trackers'
        self::assertCount(2, $card->filter('[data-testid="duplicate-only-tracker"]'));
        self::assertCount(1, $card->filter('form[action$="/keep"]'));
        self::assertSame('All are real – different solves', trim($card->filter('[data-testid="duplicate-all-real"]')->text()));

        $form = $card->filter('form[action$="/keep"]');
        self::assertSame($byAdmin, $form->filter('input[name="keep"]')->attr('value'));
        $browser->request('POST', (string) $form->attr('action'), [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
            'keep' => $byAdmin,
            'via' => 'review_page',
            'copies' => [$bySarah, $byAdmin, $byMichael],
        ]);

        $this->assertResponseRedirects('/en/review-results');
        self::assertStringContainsString('the copy is deleted', $browser->followRedirect()->text());
        self::assertFalse($scenario->exists($bySarah));
        self::assertTrue($scenario->exists($byAdmin));
        self::assertTrue($scenario->exists($byMichael));
    }

    public function testAStalePageChangesNothing(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::DANA);
        $crawler = $browser->request('GET', '/en/review-results');
        $form = $this->keepForm($crawler, DuplicateResultsFixture::TIME_STRONG_A);

        $browser->request('POST', (string) $form->attr('action'), [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
            'keep' => DuplicateResultsFixture::TIME_STRONG_A,
            // Not the copies of this set
            'copies' => [DuplicateResultsFixture::TIME_STRONG_A, DuplicateResultsFixture::TIME_PRACTICE_A],
        ]);

        $this->assertResponseRedirects('/en/review-results');
        self::assertStringContainsString('This has changed in the meantime', $browser->followRedirect()->text());
        self::assertNotFalse($this->database($browser)->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE id = :id', ['id' => DuplicateResultsFixture::TIME_STRONG_B]));
    }

    public function testSomebodyElsesCaseIsNotFound(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::DANA);
        $crawler = $browser->request('GET', '/en/review-results');
        $form = $this->keepForm($crawler, DuplicateResultsFixture::TIME_STRONG_A);

        TestingLogin::asPlayer($browser, self::TOM);
        // Tom's own page, so his session holds a token
        $crawler = $browser->request('GET', '/en/review-results');
        $tomsToken = (string) $crawler->filter('form[action$="/keep"] input[name="_token"]')->attr('value');

        $browser->request('POST', (string) $form->attr('action'), [
            '_token' => $tomsToken,
            'keep' => DuplicateResultsFixture::TIME_STRONG_A,
        ]);

        $this->assertResponseStatusCodeSame(404);
        self::assertNotFalse($this->database($browser)->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE id = :id', ['id' => DuplicateResultsFixture::TIME_STRONG_B]));
    }

    public function testAnActionWithoutAValidTokenIsRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::DANA);
        $crawler = $browser->request('GET', '/en/review-results');
        $form = $this->keepForm($crawler, DuplicateResultsFixture::TIME_STRONG_A);

        $browser->request('POST', (string) $form->attr('action'), [
            '_token' => 'nope',
            'keep' => DuplicateResultsFixture::TIME_STRONG_A,
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testBothRealFromTheRecapLeadsBackToIt(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::DANA);

        $crawler = $browser->request('GET', '/en/time-added/' . DuplicateResultsFixture::TIME_STRONG_B);

        $this->assertResponseIsSuccessful();
        $notice = $crawler->filter('[data-testid="recap-duplicates"]');
        self::assertCount(1, $notice->filter('[data-testid="duplicate-set"]'));

        $form = $notice->filter('form[action$="/both-real"]');
        $browser->request('POST', (string) $form->attr('action'), [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
            'via' => 'recap',
            'time' => DuplicateResultsFixture::TIME_STRONG_B,
        ]);

        $this->assertResponseRedirects('/en/time-added/' . DuplicateResultsFixture::TIME_STRONG_B);
        $crawler = $browser->followRedirect();
        self::assertCount(0, $crawler->filter('[data-testid="recap-duplicates"]'));
        self::assertSame('recap', $this->database($browser)->fetchOne(
            'SELECT resolved_via FROM result_duplicate_case WHERE time_b_id = :id',
            ['id' => DuplicateResultsFixture::TIME_STRONG_B],
        ));
    }

    public function testARemovedCopyIsListedAndCanBeBroughtBack(): void
    {
        $browser = $this->danaAfterTheDetection();

        $crawler = $browser->request('GET', '/en/review-results');
        $removal = $crawler->filter('[data-testid="review-results-removal"]');
        self::assertCount(1, $removal);
        // Its case is closed - only the three others are left to review
        self::assertCount(3, $crawler->filter('[data-testid="duplicate-set"]'));

        $form = $removal->filter('form');
        $browser->request('POST', (string) $form->attr('action'), [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
        ]);

        $this->assertResponseRedirects('/en/review-results');
        self::assertStringContainsString('The result is back.', $browser->followRedirect()->text());
        self::assertNotFalse($this->database($browser)->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE id = :id', ['id' => DuplicateResultsFixture::TIME_CERTAIN_B]));
    }

    public function testLinksToARemovedCopyLeadToTheKeptOne(): void
    {
        $browser = $this->danaAfterTheDetection();

        $browser->request('GET', '/en/time-added/' . DuplicateResultsFixture::TIME_CERTAIN_B);
        $this->assertResponseRedirects('/en/time-added/' . DuplicateResultsFixture::TIME_CERTAIN_A, 302);

        $browser->request('GET', '/result-image/' . DuplicateResultsFixture::TIME_CERTAIN_B);
        $this->assertResponseRedirects('/result-image/' . DuplicateResultsFixture::TIME_CERTAIN_A, 302);

        // Any other missing id is still a 404
        $browser->request('GET', '/en/time-added/' . Uuid::uuid7()->toString());
        $this->assertResponseStatusCodeSame(404);
    }

    public function testABlockedTeammateIsAPuzzler(): void
    {
        $browser = self::createClient();
        $this->database($browser)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => self::DANA, 'blocked' => self::TOM],
        );
        TestingLogin::asPlayer($browser, self::DANA);

        $crawler = $browser->request('GET', '/en/review-results');

        $this->assertResponseIsSuccessful();
        self::assertStringNotContainsString('Tom Twin', $crawler->text());
        self::assertStringContainsString('pair with a puzzler', $crawler->text());
        self::assertStringContainsString('Only a puzzler can delete it.', $crawler->text());
    }

    public function testAPrivateTeammateIsAPuzzler(): void
    {
        $browser = self::createClient();
        $this->database($browser)->executeStatement('UPDATE player SET is_private = true WHERE id = :id', ['id' => self::TOM]);
        TestingLogin::asPlayer($browser, self::DANA);

        $crawler = $browser->request('GET', '/en/review-results');

        $this->assertResponseIsSuccessful();
        self::assertStringNotContainsString('Tom Twin', $crawler->text());
        self::assertStringContainsString('pair with a puzzler', $crawler->text());
    }

    public function testTheBannerOnTheHubAndTheOwnProfile(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::DANA);

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/hub');

        $this->assertResponseIsSuccessful();
        $banner = $crawler->filter('[data-testid="review-results-banner"]');
        self::assertStringContainsString('4 results may show up twice.', $banner->text());
        self::assertCount(1, $banner->filter('a[href="/en/review-results"]'));
        self::assertCount(1, array_filter($this->executedSql($browser), static fn (string $sql): bool => str_contains($sql, 'result_duplicate_case')), 'One query feeds the banner');

        $crawler = $browser->request('GET', '/en/player-profile/' . self::DANA);
        self::assertCount(1, $crawler->filter('[data-testid="review-results-banner"]'));

        TestingLogin::asPlayer($browser, self::TOM);
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/player-profile/' . self::DANA);

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="review-results-banner"]'));

        foreach ($this->executedSql($browser) as $sql) {
            self::assertStringNotContainsString('result_duplicate_case', $sql, 'Somebody else\'s profile costs no review query');
            self::assertStringNotContainsString('result_auto_removal', $sql, 'Somebody else\'s profile costs no review query');
        }
    }

    public function testTheBannerTellsAboutAnAutomaticRemoval(): void
    {
        $browser = $this->danaAfterTheDetection();

        $crawler = $browser->request('GET', '/en/hub');

        $banner = $crawler->filter('[data-testid="review-results-banner"]');
        self::assertStringContainsString('3 results may show up twice.', $banner->text());
        self::assertStringContainsString('We removed 1 extra copy of a result.', $banner->text());
    }

    public function testGuestsHubPaysNothing(): void
    {
        $browser = self::createClient();

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/hub');

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="review-results-banner"]'));

        foreach ($this->executedSql($browser) as $sql) {
            self::assertStringNotContainsString('result_duplicate_case', $sql);
        }
    }

    private function danaAfterTheDetection(): KernelBrowser
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $browser->getContainer()->get(DailyDuplicateDetection::class)->run(DuplicateDetectedBy::Cron);
        TestingLogin::asPlayer($browser, self::DANA);

        return $browser;
    }

    private function keepForm(Crawler $crawler, string $keepTimeId): Crawler
    {
        return $crawler->filter('form[action$="/keep"]')->reduce(
            static fn (Crawler $form): bool => $form->filter('input[name="keep"][value="' . $keepTimeId . '"]')->count() > 0,
        )->first();
    }

    private function database(KernelBrowser $browser): Connection
    {
        return $browser->getContainer()->get(Connection::class);
    }
}
