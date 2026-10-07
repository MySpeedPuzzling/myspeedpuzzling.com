<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\SendResultReviewEmailPreview;
use SpeedPuzzling\Web\Results\AutoRemovedResult;
use SpeedPuzzling\Web\Results\ResultReviewEmailCase;
use SpeedPuzzling\Web\Results\ResultReviewEmailMarkedTime;
use SpeedPuzzling\Web\Results\ResultReviewEmailVerificationAnswer;
use SpeedPuzzling\Web\Services\DuplicateResults\ResultReviewEmailComposer;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use SpeedPuzzling\Web\Value\DuplicateKind;
use SpeedPuzzling\Web\Value\DuplicateTier;
use SpeedPuzzling\Web\Value\ResultReviewEmailPreviewVariant;
use SpeedPuzzling\Web\Value\SuspiciousTimeReplyAnswer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A "Your results" e-mail with sample data, to see it in a real inbox before players get it
 * (docs/features/duplicate-results.md, "Sending"; times awaiting verification: docs/features/suspicious-time-review.md). Composed by the same ResultReviewEmailComposer as the real
 * e-mail - template, translations, headers, `notifications` transport - only the subject says "[Preview]".
 *
 * Writes nothing: no contact, no removal touched. The ids behind its links belong to nobody - the review button
 * opens the real review page (the `from=rc-` visit matches no contact, nothing is recorded), the unsubscribe and
 * settings links answer 404, so clicking them changes nothing for any player.
 */
#[AsMessageHandler]
readonly final class SendResultReviewEmailPreviewHandler
{
    public const string PREVIEW_PLAYER_ID = '00000000-0000-7000-8000-000000000000';
    public const string PREVIEW_CONTACT_ID = '00000000-0000-7000-8000-000000000001';
    public const string SUBJECT_PREFIX = '[Preview] ';

    public function __construct(
        private ResultReviewEmailComposer $emailComposer,
        private MailerInterface $mailer,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SendResultReviewEmailPreview $message): void
    {
        $now = $this->clock->now();
        $variant = $message->variant;

        $email = $this->emailComposer->compose(
            contactId: self::PREVIEW_CONTACT_ID,
            playerId: self::PREVIEW_PLAYER_ID,
            playerName: 'Alex',
            emailAddress: $message->emailAddress,
            locale: ListmonkNewsletterLists::normalizeLocale($message->locale),
            first: $variant === ResultReviewEmailPreviewVariant::First,
            cases: match ($variant) {
                ResultReviewEmailPreviewVariant::First, ResultReviewEmailPreviewVariant::Weekly => $this->sampleCases($now),
                default => [],
            },
            removals: match ($variant) {
                ResultReviewEmailPreviewVariant::First, ResultReviewEmailPreviewVariant::Weekly, ResultReviewEmailPreviewVariant::Removed => $this->sampleRemovals($now),
                default => [],
            },
            markedTimes: match ($variant) {
                ResultReviewEmailPreviewVariant::First => array_slice($this->sampleMarkedTimes($now), 0, 1),
                ResultReviewEmailPreviewVariant::Verification => $this->sampleMarkedTimes($now),
                default => [],
            },
            verificationAnswers: match ($variant) {
                ResultReviewEmailPreviewVariant::Verification => array_slice($this->sampleAnswers($now), 1),
                ResultReviewEmailPreviewVariant::Answered => $this->sampleAnswers($now),
                default => [],
            },
        );
        $email->subject(self::SUBJECT_PREFIX . $email->getSubject());

        $this->mailer->send($email);
    }

    /**
     * Four results - three listed, "…and 1 more".
     *
     * @return list<ResultReviewEmailCase>
     */
    private function sampleCases(DateTimeImmutable $now): array
    {
        return [
            $this->sampleCase(1, DuplicateKind::SameTracker, 'Circle of Colors: Tropical', 4226, $now->modify('-9 days')),
            $this->sampleCase(2, DuplicateKind::TeammateCopy, 'Songs of Extinct Birds', 4928, $now->modify('-23 days')),
            $this->sampleCase(3, DuplicateKind::SoloAndGroup, 'Starry Night', 2773, $now->modify('-41 days')),
            $this->sampleCase(4, DuplicateKind::SameTracker, 'Wildlife Collage', 7395, $now->modify('-64 days')),
        ];
    }

    private function sampleCase(int $number, DuplicateKind $kind, string $puzzleName, int $seconds, DateTimeImmutable $solvedAt): ResultReviewEmailCase
    {
        return new ResultReviewEmailCase(
            caseId: sprintf('00000000-0000-7000-8000-0000000001%02d', $number),
            timeAId: sprintf('00000000-0000-7000-8000-0000000002%02d', $number),
            timeBId: sprintf('00000000-0000-7000-8000-0000000003%02d', $number),
            tier: DuplicateTier::Strong,
            kind: $kind,
            puzzleName: $puzzleName,
            secondsToSolve: $seconds,
            solvedAt: $solvedAt,
        );
    }

    /**
     * Two times awaiting verification - one much faster, one much slower than usual.
     *
     * @return list<ResultReviewEmailMarkedTime>
     */
    private function sampleMarkedTimes(DateTimeImmutable $now): array
    {
        return [
            new ResultReviewEmailMarkedTime(
                noticeId: '00000000-0000-7000-8000-000000000701',
                puzzleName: 'Mountain Lake at Dawn',
                secondsToSolve: 3780,
                solvedAt: $now->modify('-3 days'),
            ),
            new ResultReviewEmailMarkedTime(
                noticeId: '00000000-0000-7000-8000-000000000702',
                puzzleName: 'Vintage Postcards',
                secondsToSolve: 176880,
                solvedAt: $now->modify('-12 days'),
            ),
        ];
    }

    /**
     * A time that counts again and one that stays set aside, with the moderator's note.
     *
     * @return list<ResultReviewEmailVerificationAnswer>
     */
    private function sampleAnswers(DateTimeImmutable $now): array
    {
        return [
            new ResultReviewEmailVerificationAnswer(
                noticeId: '00000000-0000-7000-8000-000000000801',
                puzzleName: 'Garden Party',
                secondsToSolve: 2711,
                solvedAt: $now->modify('-20 days'),
                answer: SuspiciousTimeReplyAnswer::Trusted,
                note: null,
            ),
            new ResultReviewEmailVerificationAnswer(
                noticeId: '00000000-0000-7000-8000-000000000802',
                puzzleName: 'City Lights at Night',
                secondsToSolve: 1845,
                solvedAt: $now->modify('-26 days'),
                answer: SuspiciousTimeReplyAnswer::Kept,
                note: 'The box in your photo is the 500-piece edition - you can move the time to it.',
            ),
        ];
    }

    /**
     * @return list<AutoRemovedResult>
     */
    private function sampleRemovals(DateTimeImmutable $now): array
    {
        return [
            new AutoRemovedResult(
                removalId: '00000000-0000-7000-8000-000000000401',
                puzzleId: '00000000-0000-7000-8000-000000000501',
                puzzleName: 'Disney Family',
                secondsToSolve: 5104,
                solvedAt: $now->modify('-5 days'),
                savedAt: $now->modify('-5 days'),
                removedAt: $now->modify('-1 day'),
                keptTimeId: '00000000-0000-7000-8000-000000000601',
            ),
        ];
    }
}
