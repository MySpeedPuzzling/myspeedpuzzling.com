<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\ResultReviewContact;
use SpeedPuzzling\Web\Message\SendPlannedResultReviewEmails;
use SpeedPuzzling\Web\Query\GetResultReviewEmails;
use SpeedPuzzling\Web\Repository\ResultAutoRemovalRepository;
use SpeedPuzzling\Web\Repository\ResultReviewContactRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeNoticeRepository;
use SpeedPuzzling\Web\Results\AutoRemovedResult;
use SpeedPuzzling\Web\Results\ResultReviewEmailCase;
use SpeedPuzzling\Web\Results\ResultReviewEmailMarkedTime;
use SpeedPuzzling\Web\Results\ResultReviewEmailVerificationAnswer;
use SpeedPuzzling\Web\Results\ResultReviewSendingSummary;
use SpeedPuzzling\Web\Services\DelayedEmailQueue;
use SpeedPuzzling\Web\Services\DuplicateResults\ResultReviewEmailComposer;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use SpeedPuzzling\Web\Value\ResultReviewContactSkipReason;
use SpeedPuzzling\Web\Value\ResultReviewContactType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The paced sending of "Your results" e-mails (docs/features/duplicate-results.md, "Sending"): at most
 * `result_review_emails_per_run` per run and `result_review_emails_per_day` per Europe/Prague day, through the
 * `notifications` transport - never `transactional`, sign-in links keep their own reputation. The e-mails of a run
 * leave `result_review_email_spacing_seconds` apart (a DelayStamp each: 0 s, 60 s, 120 s, ...), so runs every
 * 5 minutes of 5 e-mails send one a minute.
 *
 * The planning can be days old, so every e-mail is checked again: the switch still on, cases still open, removals
 * still not undone, marks still in force and not reacted to, answers still untold
 * (docs/features/suspicious-time-review.md, "Where they see it"). What was resolved meanwhile is left out; nothing
 * left (or Tier C alone) = skipped. Every notice told records the e-mail that told it - once.
 *
 * One transaction for the run, the queued e-mails included (the Doctrine messenger transport) - a failure sends
 * nothing and the next run tries again. The planned contacts are locked for the run (FOR UPDATE SKIP LOCKED), so a
 * run overlapping a slow one sends only what the slow one did not take.
 */
#[AsMessageHandler]
readonly final class SendPlannedResultReviewEmailsHandler
{
    private const string DAY_TIMEZONE = 'Europe/Prague';

    public function __construct(
        private GetResultReviewEmails $getResultReviewEmails,
        private ResultReviewContactRepository $contactRepository,
        private ResultAutoRemovalRepository $autoRemovalRepository,
        private SuspiciousTimeNoticeRepository $noticeRepository,
        private PlayerAccountEmail $playerAccountEmail,
        private ResultReviewEmailComposer $emailComposer,
        private DelayedEmailQueue $emailQueue,
        private ClockInterface $clock,
        private int $resultReviewEmailsPerRun,
        private int $resultReviewEmailsPerDay,
        private int $resultReviewEmailSpacingSeconds,
    ) {
    }

    public function __invoke(SendPlannedResultReviewEmails $message): ResultReviewSendingSummary
    {
        $now = $this->clock->now();
        $sentToday = $this->getResultReviewEmails->countSentSince($this->startOfDay($now));
        $allowed = max(0, min($this->resultReviewEmailsPerRun, $this->resultReviewEmailsPerDay - $sentToday));
        $plannedIds = $this->getResultReviewEmails->plannedIdsInSendOrder();

        $sent = 0;
        $skipped = 0;

        foreach ($plannedIds as $contactId) {
            if ($sent >= $allowed) {
                break;
            }

            $contact = $this->contactRepository->find($contactId);

            if ($contact === null || $contact->isPlanned() === false) {
                continue;
            }

            if ($this->send($contact, $now, $sent * $this->resultReviewEmailSpacingSeconds)) {
                $sent++;
            } else {
                $skipped++;
            }
        }

        return new ResultReviewSendingSummary(
            sent: $sent,
            skipped: $skipped,
            stillPlanned: count($plannedIds) - $sent - $skipped,
            sentTodayBefore: $sentToday,
        );
    }

    private function send(ResultReviewContact $contact, DateTimeImmutable $now, int $delaySeconds): bool
    {
        $player = $contact->player;
        $playerId = $player->id->toString();

        if ($player->resultEmailsEnabled === false) {
            $contact->skip(ResultReviewContactSkipReason::SwitchedOff);

            return false;
        }

        $playerEmail = $this->playerAccountEmail->ofPlayer($player);

        if ($playerEmail === null) {
            $contact->skip(ResultReviewContactSkipReason::NoEmail);

            return false;
        }

        $cases = $this->getResultReviewEmails->openCasesOf($playerId, $contact->caseIds);
        $removals = $this->getResultReviewEmails->removalsNotUndoneOf($playerId, $contact->removalIds);
        $strongCases = array_filter($cases, static fn (ResultReviewEmailCase $case): bool => $case->triggersEmail());
        $markedTimes = $this->getResultReviewEmails->markedTimesOf($playerId, $contact->suspiciousNoticeIds);
        $answers = $this->getResultReviewEmails->verificationAnswersOf($playerId, $contact->suspiciousNoticeIds);

        if ($strongCases === [] && $removals === [] && $markedTimes === [] && $answers === []) {
            $contact->skip(ResultReviewContactSkipReason::NothingLeft);

            return false;
        }

        $locale = ListmonkNewsletterLists::normalizeLocale($player->locale);
        $email = $this->emailComposer->compose(
            contactId: $contact->id->toString(),
            playerId: $playerId,
            playerName: $player->name,
            emailAddress: $playerEmail,
            locale: $locale,
            first: $contact->type === ResultReviewContactType::First,
            cases: $cases,
            removals: $removals,
            markedTimes: $markedTimes,
            verificationAnswers: $answers,
        );

        $this->emailQueue->queue($email, $delaySeconds);

        $markedNoticeIds = array_map(static fn (ResultReviewEmailMarkedTime $markedTime): string => $markedTime->noticeId, $markedTimes);
        $answerNoticeIds = array_map(static fn (ResultReviewEmailVerificationAnswer $answer): string => $answer->noticeId, $answers);

        $contact->sent(
            caseIds: array_map(static fn (ResultReviewEmailCase $case): string => $case->caseId, $cases),
            removalIds: array_map(static fn (AutoRemovedResult $removal): string => $removal->removalId, $removals),
            now: $now,
            suspiciousNoticeIds: array_values(array_unique([...$markedNoticeIds, ...$answerNoticeIds])),
        );

        foreach ($removals as $removal) {
            $this->autoRemovalRepository->get($removal->removalId)->reported($now);
        }

        foreach ($markedNoticeIds as $noticeId) {
            $this->noticeRepository->get($noticeId)->sentInContact($contact->id);
        }

        foreach ($answerNoticeIds as $noticeId) {
            $this->noticeRepository->get($noticeId)->answerSentInContact($contact->id);
        }

        return true;
    }

    private function startOfDay(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now
            ->setTimezone(new DateTimeZone(self::DAY_TIMEZONE))
            ->setTime(0, 0)
            ->setTimezone($now->getTimezone());
    }
}
