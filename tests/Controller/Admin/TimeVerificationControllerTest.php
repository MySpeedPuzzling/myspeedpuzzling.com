<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\GrantModeratorRole;
use SpeedPuzzling\Web\Repository\SuspiciousTimeCaseRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeNoticeRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SuspiciousTimesFixture;
use SpeedPuzzling\Web\Tests\SuspiciousTimeQueueCases;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;
use SpeedPuzzling\Web\Value\SuspiciousTimeQueueTab;
use SpeedPuzzling\Web\Value\SuspiciousTimeReplyAnswer;
use SpeedPuzzling\Web\Value\SuspiciousTimeResponse;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/suspicious-time-review.md, "Moderator queue": /admin/time-verification for admins and community
 * moderators, nobody else.
 */
final class TimeVerificationControllerTest extends WebTestCase
{
    use SuspiciousTimeQueueCases;

    /**
     * @return iterable<string, array{string}>
     */
    public static function actions(): iterable
    {
        yield 'mark' => ['/admin/time-verification/' . SuspiciousTimesFixture::CASE_PENDING_FAST . '/mark'];
        yield 'trust' => ['/admin/time-verification/' . SuspiciousTimesFixture::CASE_PENDING_FAST . '/trust'];
        yield 'keep' => ['/admin/time-verification/' . SuspiciousTimesFixture::CASE_MARKED . '/keep'];
        yield 'slow threshold' => ['/admin/time-verification/puzzles/' . SuspiciousTimesFixture::PUZZLE_ORCHARD . '/slow-threshold'];
    }

    /**
     * @return iterable<string, array{SuspiciousTimeQueueTab}>
     */
    public static function tabs(): iterable
    {
        foreach (SuspiciousTimeQueueTab::cases() as $tab) {
            yield $tab->value => [$tab];
        }
    }

    public function testAdminReachesTheQueue(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/admin/time-verification');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Time verification');
    }

    public function testModeratorReachesTheQueueAndSeesItInTheMenu(): void
    {
        $browser = $this->signedInModerator();

        $crawler = $browser->request('GET', '/admin/time-verification');

        self::assertResponseIsSuccessful();
        $link = $crawler->filter('a.dropdown-item[href="/admin/time-verification"]');
        self::assertCount(1, $link);
        // Two pending cases in the fixtures
        self::assertStringContainsString('Time verification 2', preg_replace('/\s+/', ' ', $link->text()) ?? '');
    }

    public function testRegularPlayerIsForbiddenAndDoesNotSeeTheLink(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/admin/time-verification');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $crawler = $browser->request('GET', '/en/hub');
        self::assertCount(0, $crawler->filter('a[href="/admin/time-verification"]'));
    }

    public function testGuestIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/admin/time-verification');

        self::assertResponseRedirects('/login?return=/admin/time-verification');
    }

    #[DataProvider('actions')]
    public function testActionsAreForbiddenToRegularPlayers(string $path): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', $path, ['_token' => 'whatever']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertNothingDecided($browser);
    }

    #[DataProvider('actions')]
    public function testActionsSendGuestsToSignIn(string $path): void
    {
        $browser = self::createClient();

        $browser->request('POST', $path, ['_token' => 'whatever']);

        self::assertResponseRedirects();
        self::assertStringStartsWith('/login', (string) $browser->getResponse()->headers->get('Location'));
        $this->assertNothingDecided($browser);
    }

    #[DataProvider('actions')]
    public function testActionsNeedAValidCsrfToken(string $path): void
    {
        $browser = $this->signedInModerator();

        $browser->request('POST', $path, ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertNothingDecided($browser);
    }

    #[DataProvider('tabs')]
    public function testEveryTabRendersWithoutTheWordSuspicious(SuspiciousTimeQueueTab $tab): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        // Something on every tab: a reply, a trusted case, a decision
        $this->answerTheMarkedTimeIsCorrect($browser);
        $browser->getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO suspicious_time_decision (id, decision, decided_at, puzzle_id, reasons_shown, snapshot) VALUES (gen_random_uuid(), 'pieces_confirmed', NOW(), :puzzleId, '[]', '{}')",
            ['puzzleId' => SuspiciousTimesFixture::PUZZLE_HARBOUR],
        );

        $crawler = $browser->request('GET', '/admin/time-verification?tab=' . $tab->value);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.nav-tabs .nav-link.active');
        self::assertStringContainsString($tab->label(), $crawler->filter('.nav-tabs .nav-link.active')->text());
        self::assertStringNotContainsStringIgnoringCase('suspic', $crawler->filter('main')->text());
        self::assertStringNotContainsStringIgnoringCase('suspect', $crawler->filter('main')->text());
    }

    public function testTheFastTabShowsTheCaseCard(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/admin/time-verification?tab=fast');

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('#case-' . SuspiciousTimesFixture::CASE_PENDING_FAST);
        self::assertCount(1, $card);
        $text = $card->text();
        self::assertStringContainsString('02:30:00', $text);
        self::assertStringContainsString('Harbour Lights', $text);
        self::assertStringContainsString('Sam Steady', $text);
        self::assertStringContainsString('Expected about 09:30:00', $text);
        self::assertStringContainsString('3.8× faster than expected', $text);
        self::assertStringContainsString('Perhaps the hours box was left empty - 07:30:00?', $text);
        self::assertStringContainsString('Suggested time: 07:30:00', $text);
        // Both reasons a player may read are ticked
        self::assertCount(2, $card->filter('input[name="reasons[]"][checked]'));
        // The slow case is on its own tab
        self::assertCount(0, $crawler->filter('#case-' . SuspiciousTimesFixture::CASE_PENDING_SLOW));
    }

    public function testModeratorMarksATimeWithTheReasonsTheyKeep(): void
    {
        $browser = $this->signedInModerator();
        $crawler = $browser->request('GET', '/admin/time-verification?tab=fast');

        $form = $this->formOf($crawler, SuspiciousTimesFixture::CASE_PENDING_FAST, 'mark');
        $values = $form->getPhpValues();
        self::assertSame(['faster_than_usual', 'hours_left_out'], $values['reasons']);
        // Untick "faster than usual", keep "hours left out"
        $values['reasons'] = ['hours_left_out'];
        $values['note'] = 'Did the hours box stay empty?';
        $browser->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects('/admin/time-verification?tab=fast');
        $case = $this->case($browser, SuspiciousTimesFixture::CASE_PENDING_FAST);
        self::assertSame(SuspiciousTimeCaseStatus::Marked, $case->status);
        self::assertSame(['hours_left_out'], array_column($case->reasonsShown, 'code'));
        self::assertSame('Did the hours box stay empty?', $case->moderatorNote);
        // Credited to the moderator
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $case->decidedById?->toString());
        self::assertTrue($case->time->suspicious);

        $browser->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Marked as needing verification');
    }

    public function testATooLongNoteIsRefused(): void
    {
        $browser = $this->signedInModerator();
        $crawler = $browser->request('GET', '/admin/time-verification?tab=fast');

        $form = $this->formOf($crawler, SuspiciousTimesFixture::CASE_PENDING_FAST, 'mark');
        $browser->submit($form, ['note' => str_repeat('a', 1001)]);

        self::assertResponseRedirects('/admin/time-verification?tab=fast');
        self::assertSame(SuspiciousTimeCaseStatus::Pending, $this->case($browser, SuspiciousTimesFixture::CASE_PENDING_FAST)->status);
    }

    public function testAdminSaysASlowTimeLooksFine(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', '/admin/time-verification?tab=slow');

        $browser->submit($this->formOf($crawler, SuspiciousTimesFixture::CASE_PENDING_SLOW, 'trust'));

        self::assertResponseRedirects('/admin/time-verification?tab=slow');
        self::assertSame(SuspiciousTimeCaseStatus::Trusted, $this->case($browser, SuspiciousTimesFixture::CASE_PENDING_SLOW)->status);

        $crawler = $browser->request('GET', '/admin/time-verification?tab=trusted');
        self::assertCount(1, $crawler->filter('#case-' . SuspiciousTimesFixture::CASE_PENDING_SLOW));
    }

    public function testModeratorKeepsARepliedTimeMarkedWithANote(): void
    {
        $browser = $this->signedInModerator();
        $this->answerTheMarkedTimeIsCorrect($browser);

        $crawler = $browser->request('GET', '/admin/time-verification?tab=replied');
        self::assertResponseIsSuccessful();
        $card = $crawler->filter('#case-' . SuspiciousTimesFixture::CASE_MARKED);
        self::assertStringContainsString('says the time is correct', $card->text());
        self::assertStringContainsString('I really did it this fast.', $card->text());

        // A note is required
        $browser->submit($this->formOf($crawler, SuspiciousTimesFixture::CASE_MARKED, 'keep'), ['note' => ' ']);
        self::assertResponseRedirects('/admin/time-verification?tab=replied');
        self::assertNull($this->notice($browser)->answer);

        $browser->submit($this->formOf($crawler, SuspiciousTimesFixture::CASE_MARKED, 'keep'), ['note' => 'Far beyond your other 520s.']);
        self::assertResponseRedirects('/admin/time-verification?tab=replied');
        $notice = $this->notice($browser);
        self::assertSame(SuspiciousTimeReplyAnswer::Kept, $notice->answer);
        self::assertSame('Far beyond your other 520s.', $notice->answerNote);
        self::assertSame(SuspiciousTimeCaseStatus::Marked, $this->case($browser, SuspiciousTimesFixture::CASE_MARKED)->status);

        $crawler = $browser->request('GET', '/admin/time-verification?tab=replied');
        self::assertCount(0, $crawler->filter('#case-' . SuspiciousTimesFixture::CASE_MARKED));
    }

    public function testAdminUnmarksFromNeedsVerification(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', '/admin/time-verification?tab=marked');

        // Keeping it marked is an answer to a reply - not offered here
        self::assertCount(0, $crawler->filter('form[action$="/' . SuspiciousTimesFixture::CASE_MARKED . '/keep"]'));
        $browser->submit($this->formOf($crawler, SuspiciousTimesFixture::CASE_MARKED, 'trust'));

        self::assertResponseRedirects('/admin/time-verification?tab=marked');
        $case = $this->case($browser, SuspiciousTimesFixture::CASE_MARKED);
        self::assertSame(SuspiciousTimeCaseStatus::Trusted, $case->status);
        self::assertFalse($case->time->suspicious);
    }

    public function testEveryTimeOfAPuzzleCardIsDecidedRightInIt(): void
    {
        $browser = $this->signedInModerator();
        $this->raiseCopyOf(SuspiciousTimesFixture::TIME_STEADY_FAST, ['player_id' => SuspiciousTimesFixture::PLAYER_EDITION]);

        $crawler = $browser->request('GET', '/admin/time-verification?tab=fast');
        $card = $crawler->filter('#puzzle-' . SuspiciousTimesFixture::PUZZLE_HARBOUR);
        self::assertCount(1, $card);
        self::assertStringContainsString('2 players are much faster here than usual', $card->text());
        self::assertCount(1, $card->filter('a[href^="/admin/puzzles/' . SuspiciousTimesFixture::PUZZLE_HARBOUR . '/edit"]'));
        // A hard puzzle is about slow times only
        self::assertCount(0, $card->filter('form[action$="/slow-threshold"]'));
        // Each case is a card of its own inside the puzzle card, with every action
        self::assertCount(1, $card->filter('#case-' . SuspiciousTimesFixture::CASE_PENDING_FAST));
        self::assertCount(1, $card->filter('form[action$="/' . SuspiciousTimesFixture::CASE_PENDING_FAST . '/mark"]'));

        $browser->submit($this->formOf($crawler, SuspiciousTimesFixture::CASE_PENDING_FAST, 'trust'));

        self::assertResponseRedirects('/admin/time-verification?tab=fast');
        self::assertSame(SuspiciousTimeCaseStatus::Trusted, $this->case($browser, SuspiciousTimesFixture::CASE_PENDING_FAST)->status);
    }

    public function testModeratorSetsAHardPuzzlesSlowThresholdAndItsCasesCloseAtOnce(): void
    {
        $browser = $this->signedInModerator();
        // Players are usually far slower on the orchard than on others - a card of its own
        $browser->getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO puzzle_difficulty (puzzle_id, difficulty_score, confidence, sample_size, computed_at) VALUES (:id, 2.5, 'high', 30, NOW())
             ON CONFLICT (puzzle_id) DO UPDATE SET difficulty_score = 2.5, confidence = 'high'",
            ['id' => SuspiciousTimesFixture::PUZZLE_ORCHARD],
        );

        $crawler = $browser->request('GET', '/admin/time-verification?tab=slow');
        $card = $crawler->filter('#puzzle-' . SuspiciousTimesFixture::PUZZLE_ORCHARD);
        self::assertCount(1, $card);
        self::assertCount(1, $card->filter('#case-' . SuspiciousTimesFixture::CASE_PENDING_SLOW));
        $form = $card->filter('form[action$="/slow-threshold"]');
        self::assertCount(1, $form);
        // 49× against the prediction - suggested a quarter above
        self::assertSame('62', $form->filter('input[name="slow_threshold"]')->attr('value'));

        // Out of range: nothing saved
        $browser->submit($form->form(), ['slow_threshold' => '2']);
        self::assertResponseRedirects('/admin/time-verification?tab=slow');
        self::assertFalse($this->slowThresholdOf($browser, SuspiciousTimesFixture::PUZZLE_ORCHARD));

        $browser->submit($form->form(), ['slow_threshold' => '60']);

        self::assertResponseRedirects('/admin/time-verification?tab=slow');
        $crawler = $browser->followRedirect();
        self::assertStringContainsString('too slow only from 60× the expected time', $crawler->text());
        self::assertStringContainsString('1 case closed', $crawler->text());
        self::assertCount(0, $crawler->filter('#puzzle-' . SuspiciousTimesFixture::PUZZLE_ORCHARD));
        self::assertSame(SuspiciousTimeCaseStatus::Gone, $this->case($browser, SuspiciousTimesFixture::CASE_PENDING_SLOW)->status);
        self::assertSame(60.0, $this->slowThresholdOf($browser, SuspiciousTimesFixture::PUZZLE_ORCHARD));
    }

    public function testADecisionOnACaseThatChangedMeanwhileIsRefused(): void
    {
        $browser = $this->signedInModerator();
        $crawler = $browser->request('GET', '/admin/time-verification?tab=fast');
        $form = $this->formOf($crawler, SuspiciousTimesFixture::CASE_PENDING_FAST, 'mark');

        // The player fixes the time while the moderator looks at it
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle_solving_time SET seconds_to_solve = 27000 WHERE id = :id',
            ['id' => SuspiciousTimesFixture::TIME_STEADY_FAST],
        );

        $browser->submit($form);

        self::assertResponseRedirects('/admin/time-verification?tab=fast');
        self::assertSame(SuspiciousTimeCaseStatus::Pending, $this->case($browser, SuspiciousTimesFixture::CASE_PENDING_FAST)->status);
        $crawler = $browser->followRedirect();
        self::assertSelectorTextContains('.alert-warning', 'This case changed meanwhile');
        // The card says why there is nothing to decide now
        self::assertStringContainsString('changed this time after the scan raised it', $crawler->filter('#case-' . SuspiciousTimesFixture::CASE_PENDING_FAST)->text());
        self::assertCount(0, $crawler->filter('form[action$="/' . SuspiciousTimesFixture::CASE_PENDING_FAST . '/mark"]'));
    }

    public function testThePageKeepsItsPlaceAfterAnAction(): void
    {
        $browser = $this->signedInModerator();
        $crawler = $browser->request('GET', '/admin/time-verification?tab=slow&page=1');

        $form = $this->formOf($crawler, SuspiciousTimesFixture::CASE_PENDING_SLOW, 'trust');
        $form['page'] = '3';
        $browser->submit($form);

        self::assertResponseRedirects('/admin/time-verification?tab=slow&page=3');
    }

    private function formOf(Crawler $crawler, string $caseId, string $action): \Symfony\Component\DomCrawler\Form
    {
        $form = $crawler->filter('form[action$="/' . $caseId . '/' . $action . '"]');
        self::assertCount(1, $form, sprintf('No %s form for case %s', $action, $caseId));

        return $form->form();
    }

    /**
     * The puzzle's stored slow threshold - false without a row.
     */
    private function slowThresholdOf(KernelBrowser $browser, string $puzzleId): null|false|float
    {
        $threshold = $browser->getContainer()->get(Connection::class)->fetchOne('SELECT slow_threshold FROM suspicious_time_puzzle_confirmation WHERE puzzle_id = :id', ['id' => $puzzleId]);

        assert($threshold === false || $threshold === null || is_float($threshold) || is_string($threshold));

        return $threshold === false || $threshold === null ? $threshold : (float) $threshold;
    }

    private function signedInModerator(): KernelBrowser
    {
        $browser = self::createClient();
        $browser->getContainer()->get(MessageBusInterface::class)->dispatch(new GrantModeratorRole(PlayerFixture::PLAYER_REGULAR));
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        return $browser;
    }

    private function answerTheMarkedTimeIsCorrect(KernelBrowser $browser): void
    {
        $container = $browser->getContainer();
        $container->get(SuspiciousTimeNoticeRepository::class)->get(SuspiciousTimesFixture::NOTICE_MARKED)
            ->respond(SuspiciousTimeResponse::SaysCorrect, 'I really did it this fast.', $container->get(ClockInterface::class)->now());
        $container->get(EntityManagerInterface::class)->flush();
    }

    private function case(KernelBrowser $browser, string $caseId): \SpeedPuzzling\Web\Entity\SuspiciousTimeCase
    {
        $browser->getContainer()->get(EntityManagerInterface::class)->clear();

        return $browser->getContainer()->get(SuspiciousTimeCaseRepository::class)->get($caseId);
    }

    private function notice(KernelBrowser $browser): \SpeedPuzzling\Web\Entity\SuspiciousTimeNotice
    {
        $browser->getContainer()->get(EntityManagerInterface::class)->clear();

        return $browser->getContainer()->get(SuspiciousTimeNoticeRepository::class)->get(SuspiciousTimesFixture::NOTICE_MARKED);
    }

    private function assertNothingDecided(KernelBrowser $browser): void
    {
        self::assertSame(0, $browser->getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM suspicious_time_decision'));
        self::assertSame(SuspiciousTimeCaseStatus::Pending, $this->case($browser, SuspiciousTimesFixture::CASE_PENDING_FAST)->status);
    }
}
