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
use SpeedPuzzling\Web\Results\AutoRemovedResult;
use SpeedPuzzling\Web\Results\ResultReviewEmailCase;
use SpeedPuzzling\Web\Results\ResultReviewSendingSummary;
use SpeedPuzzling\Web\Services\DuplicateResults\DuplicateSets;
use SpeedPuzzling\Web\Services\DuplicateResults\ResultEmailsUnsubscribeUrl;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use SpeedPuzzling\Web\Value\ResultReviewContactSkipReason;
use SpeedPuzzling\Web\Value\ResultReviewContactType;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The paced sending of "Your results" e-mails (docs/features/duplicate-results.md, "Sending"): at most
 * `result_review_emails_per_run` now and `result_review_emails_per_day` per Europe/Prague day, through the
 * `notifications` transport - never `transactional`, sign-in links keep their own reputation.
 *
 * The planning can be days old, so every e-mail is checked again: the switch still on, cases still open, removals
 * still not undone. What was resolved meanwhile is left out; nothing left (or Tier C alone) = skipped.
 *
 * One transaction for the run, the queued e-mails included (the Doctrine messenger transport) - a failure sends
 * nothing and the next run tries again. The planned contacts are locked for the run (FOR UPDATE SKIP LOCKED), so a
 * run overlapping a slow one sends only what the slow one did not take.
 */
#[AsMessageHandler]
readonly final class SendPlannedResultReviewEmailsHandler
{
    public const int LISTED_MAX = 3;
    private const string DAY_TIMEZONE = 'Europe/Prague';

    public function __construct(
        private GetResultReviewEmails $getResultReviewEmails,
        private ResultReviewContactRepository $contactRepository,
        private ResultAutoRemovalRepository $autoRemovalRepository,
        private PlayerAccountEmail $playerAccountEmail,
        private EmailPreferencesLinkGenerator $emailPreferencesLinkGenerator,
        private ResultEmailsUnsubscribeUrl $unsubscribeUrl,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
        private int $resultReviewEmailsPerRun,
        private int $resultReviewEmailsPerDay,
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

            if ($this->send($contact, $now)) {
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

    private function send(ResultReviewContact $contact, DateTimeImmutable $now): bool
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

        if ($strongCases === [] && $removals === []) {
            $contact->skip(ResultReviewContactSkipReason::NothingLeft);

            return false;
        }

        $locale = ListmonkNewsletterLists::normalizeLocale($player->locale);
        $unsubscribeUrl = $this->unsubscribeUrl->forPlayer($playerId, $locale);
        $subjectKey = match (true) {
            $cases === [] => 'result_review.subject_removed',
            $contact->type === ResultReviewContactType::First => 'result_review.subject_first',
            default => 'result_review.subject_weekly',
        };

        // One line per result, however many copies: a result saved by three teammates is three cases of one set.
        // The first case of a set is its strongest (the cases come ordered so) and describes it
        $sets = array_map(
            static fn (array $keys): ResultReviewEmailCase => $cases[$keys[0]],
            DuplicateSets::group(array_map(static fn (ResultReviewEmailCase $case): array => [$case->timeAId, $case->timeBId], $cases)),
        );

        $email = (new TemplatedEmail())
            ->from(new Address('notify@notify.myspeedpuzzling.com', 'MySpeedPuzzling'))
            ->to($playerEmail)
            ->locale($locale)
            ->subject($this->translator->trans($subjectKey, domain: 'emails', locale: $locale))
            ->htmlTemplate('emails/result_review.html.twig')
            ->context([
                'contactId' => $contact->id->toString(),
                'first' => $contact->type === ResultReviewContactType::First,
                'playerName' => $player->name,
                'sets' => array_slice($sets, 0, self::LISTED_MAX),
                'moreSets' => max(0, count($sets) - self::LISTED_MAX),
                'removals' => array_slice($removals, 0, self::LISTED_MAX),
                'moreRemovals' => max(0, count($removals) - self::LISTED_MAX),
                'locale' => $locale,
                'settingsUrl' => $this->emailPreferencesLinkGenerator->forPlayer($playerId, $playerEmail, $locale),
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'notifications');
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
        $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $this->mailer->send($email);

        $contact->sent(
            caseIds: array_map(static fn (ResultReviewEmailCase $case): string => $case->caseId, $cases),
            removalIds: array_map(static fn (AutoRemovedResult $removal): string => $removal->removalId, $removals),
            now: $now,
        );

        foreach ($removals as $removal) {
            $this->autoRemovalRepository->get($removal->removalId)->reported($now);
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
