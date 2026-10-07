<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DuplicateResults;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\AutoRemovedResult;
use SpeedPuzzling\Web\Results\ResultReviewEmailCase;
use SpeedPuzzling\Web\Results\ResultReviewEmailMarkedTime;
use SpeedPuzzling\Web\Results\ResultReviewEmailVerificationAnswer;
use SpeedPuzzling\Web\Services\DuplicateResults\ResultReviewEmailComposer;
use SpeedPuzzling\Web\Value\DuplicateKind;
use SpeedPuzzling\Web\Value\DuplicateTier;
use SpeedPuzzling\Web\Value\SuspiciousTimeReplyAnswer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;

/**
 * The verification sections of the "Your results" e-mail (docs/features/suspicious-time-review.md, "Where they see
 * it"): times awaiting verification and the moderators' answers, with their own subject when the e-mail has nothing
 * else - rendered by the real template through the real mailer.
 */
final class ResultReviewEmailComposerTest extends KernelTestCase
{
    private const string CONTACT_ID = '018d0031-0000-7000-8000-000000009001';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testOnlyTimesAwaitingVerification(): void
    {
        $email = $this->render(markedTimes: [$this->markedTime(1, 'Silent Pier', 1300)]);

        self::assertSame('One of your results is awaiting verification', $email->getSubject());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('<title>One of your results is awaiting verification</title>', $html);
        self::assertMatchesRegularExpression('~<h3[^>]*>One of your results is awaiting verification</h3>~', $html);
        self::assertStringContainsString('so we take a closer look when a result stands out', $html);
        self::assertStringContainsString('just a typo – fixing it', $html, 'The preheader');
        self::assertStringContainsString('Awaiting verification', $html);
        self::assertStringContainsString('much faster or much slower than your usual ones is often a typo', $html);
        self::assertStringContainsString('Silent Pier – 00:21:40', $html);
        self::assertStringContainsString('/en/review-results?from=rc-' . self::CONTACT_ID . '#awaiting-verification', $html);
        self::assertStringNotContainsString('Please check – we weren', $html);
        self::assertStringNotContainsString('Already fixed – we were sure', $html);
        self::assertStringNotContainsString('We had another look', $html);
        self::assertNoWordSuspicious($email);
    }

    public function testSeveralTimesAwaitingVerificationThreeListed(): void
    {
        $email = $this->render(markedTimes: [
            $this->markedTime(1, 'Puzzle One', 1300),
            $this->markedTime(2, 'Puzzle Two', 1400),
            $this->markedTime(3, 'Puzzle Three', 1500),
            $this->markedTime(4, 'Puzzle Four', 1600),
        ]);

        self::assertSame('Some of your results are awaiting verification', $email->getSubject());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('Puzzle Three – 00:25:00', $html);
        self::assertStringNotContainsString('Puzzle Four', $html);
        self::assertStringContainsString('…and 1 more', $html);
    }

    public function testOnlyModeratorsAnswers(): void
    {
        $email = $this->render(verificationAnswers: [
            $this->answer(1, 'Garden Party', 2711, SuspiciousTimeReplyAnswer::Trusted, null),
            $this->answer(2, 'City Lights', 1845, SuspiciousTimeReplyAnswer::Kept, 'The box in your photo is the 500-piece edition.'),
            $this->answer(3, 'Harbour', 3600, SuspiciousTimeReplyAnswer::Kept, null),
        ]);

        self::assertSame('Your results were checked', $email->getSubject());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('We had another look', $html);
        self::assertStringContainsString('Your time 00:45:11 on Garden Party was checked – it counts again.', $html);
        self::assertStringContainsString('Your time 00:30:45 on City Lights was checked – it stays set aside: The box in your photo is the 500-piece edition.', $html);
        self::assertStringContainsString('Your time 01:00:00 on Harbour was checked – it stays set aside.', $html);
        self::assertStringContainsString('#awaiting-verification', $html);
        self::assertStringNotContainsString('is often a typo', $html, 'No time is set aside newly');
        self::assertNoWordSuspicious($email);

        $one = $this->render(verificationAnswers: [$this->answer(1, 'Garden Party', 2711, SuspiciousTimeReplyAnswer::Trusted, null)]);
        self::assertSame('Your result was checked', $one->getSubject());
    }

    public function testTheModeratorsNoteIsEscaped(): void
    {
        $email = $this->render(verificationAnswers: [
            $this->answer(1, 'Garden Party', 2711, SuspiciousTimeReplyAnswer::Kept, '<a href="https://example.com">click</a>'),
        ]);

        $html = (string) $email->getHtmlBody();
        self::assertStringNotContainsString('<a href="https://example.com">', $html);
        // The CSS inliner's DOM round trip writes quotes in text as they are - the tags stay text
        self::assertStringContainsString('&lt;a href="https://example.com"&gt;click&lt;/a&gt;', $html);
    }

    public function testWithResultsToCheckTheDuplicateWordingLeads(): void
    {
        $email = $this->render(
            first: true,
            cases: [$this->case()],
            markedTimes: [$this->markedTime(1, 'Silent Pier', 1300)],
            verificationAnswers: [$this->answer(2, 'Garden Party', 2711, SuspiciousTimeReplyAnswer::Trusted, null)],
        );

        self::assertSame('Could you check a few of your results?', $email->getSubject());
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('Please check – we weren', $html);
        self::assertStringContainsString('Twins Puzzle – 00:37:02', $html);
        self::assertStringContainsString('Silent Pier – 00:21:40', $html);
        self::assertStringContainsString('it counts again', $html);
        self::assertStringContainsString('#awaiting-verification', $html);

        $weekly = $this->render(cases: [$this->case()], markedTimes: [$this->markedTime(1, 'Silent Pier', 1300)]);
        self::assertSame('A few more results to check', $weekly->getSubject());

        $removed = $this->render(removals: [$this->removal()], markedTimes: [$this->markedTime(1, 'Silent Pier', 1300)]);
        self::assertSame('We removed results that showed up twice', $removed->getSubject());
        self::assertStringContainsString('Silent Pier – 00:21:40', (string) $removed->getHtmlBody());
    }

    public function testWithoutVerificationTheLinkHasNoAnchor(): void
    {
        $html = (string) $this->render(first: true, cases: [$this->case()])->getHtmlBody();

        self::assertStringContainsString('/en/review-results?from=rc-' . self::CONTACT_ID . '"', $html);
        self::assertStringNotContainsString('#awaiting-verification', $html);
        self::assertStringNotContainsString('Awaiting verification', $html);
    }

    /**
     * @param list<ResultReviewEmailCase> $cases
     * @param list<AutoRemovedResult> $removals
     * @param list<ResultReviewEmailMarkedTime> $markedTimes
     * @param list<ResultReviewEmailVerificationAnswer> $verificationAnswers
     */
    private function render(
        bool $first = false,
        array $cases = [],
        array $removals = [],
        array $markedTimes = [],
        array $verificationAnswers = [],
    ): TemplatedEmail {
        $email = self::getContainer()->get(ResultReviewEmailComposer::class)->compose(
            contactId: self::CONTACT_ID,
            playerId: '018d0031-0000-7000-8000-000000009002',
            playerName: 'Mia',
            emailAddress: 'mia@example.com',
            locale: 'en',
            first: $first,
            cases: $cases,
            removals: $removals,
            markedTimes: $markedTimes,
            verificationAnswers: $verificationAnswers,
        );
        self::getContainer()->get(MailerInterface::class)->send($email);

        $sent = self::getMailerMessages();
        $rendered = end($sent);
        self::assertInstanceOf(TemplatedEmail::class, $rendered);

        return $rendered;
    }

    private function markedTime(int $number, string $puzzleName, int $seconds): ResultReviewEmailMarkedTime
    {
        return new ResultReviewEmailMarkedTime(
            noticeId: sprintf('018d0031-0000-7000-8000-0000000091%02d', $number),
            puzzleName: $puzzleName,
            secondsToSolve: $seconds,
            solvedAt: new DateTimeImmutable('2026-09-12'),
        );
    }

    private function answer(int $number, string $puzzleName, int $seconds, SuspiciousTimeReplyAnswer $answer, null|string $note): ResultReviewEmailVerificationAnswer
    {
        return new ResultReviewEmailVerificationAnswer(
            noticeId: sprintf('018d0031-0000-7000-8000-0000000092%02d', $number),
            puzzleName: $puzzleName,
            secondsToSolve: $seconds,
            solvedAt: new DateTimeImmutable('2026-09-01'),
            answer: $answer,
            note: $note,
        );
    }

    private function case(): ResultReviewEmailCase
    {
        return new ResultReviewEmailCase(
            caseId: '018d0031-0000-7000-8000-000000009301',
            timeAId: '018d0031-0000-7000-8000-000000009302',
            timeBId: '018d0031-0000-7000-8000-000000009303',
            tier: DuplicateTier::Strong,
            kind: DuplicateKind::SameTracker,
            puzzleName: 'Twins Puzzle',
            secondsToSolve: 2222,
            solvedAt: new DateTimeImmutable('2026-08-20'),
        );
    }

    private function removal(): AutoRemovedResult
    {
        return new AutoRemovedResult(
            removalId: '018d0031-0000-7000-8000-000000009401',
            puzzleId: '018d0031-0000-7000-8000-000000009402',
            puzzleName: 'Disney Family',
            secondsToSolve: 5104,
            solvedAt: new DateTimeImmutable('2026-09-20'),
            savedAt: new DateTimeImmutable('2026-09-20'),
            removedAt: new DateTimeImmutable('2026-09-21'),
            keptTimeId: '018d0031-0000-7000-8000-000000009403',
        );
    }

    private static function assertNoWordSuspicious(TemplatedEmail $email): void
    {
        foreach ([(string) $email->getSubject(), (string) $email->getHtmlBody(), (string) $email->getTextBody()] as $part) {
            self::assertDoesNotMatchRegularExpression('~suspic|suspect~i', $part, 'Nothing a player reads says "suspicious"');
        }
    }
}
