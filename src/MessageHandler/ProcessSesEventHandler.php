<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EmailAuditLog;
use SpeedPuzzling\Web\Entity\EmailSuppression;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\ProcessSesEvent;
use SpeedPuzzling\Web\Message\PushNewsletterSubscriberToListmonk;
use SpeedPuzzling\Web\Repository\EmailAuditLogRepository;
use SpeedPuzzling\Web\Repository\EmailSuppressionRepository;
use SpeedPuzzling\Web\Repository\NewsletterSubscriberRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\UserAccountRepository;
use SpeedPuzzling\Web\Value\BounceType;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use SpeedPuzzling\Web\Value\MailStream;
use SpeedPuzzling\Web\Value\SesEvent;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * What an SES event changes (docs/features/mass-mailing/README.md, rules decided by Jan 2026-10-12):
 *
 * - delivery / first open / first click / complaint → stamped on the message's email_audit_log row (the row is
 *   found by the `audit_id` tag; a pruned row is simply skipped);
 * - hard bounce → the address is suppressed: no more notifications, the player sees a banner; transactional mail
 *   is still tried;
 * - complaint → only the kind of e-mail complained about is switched off: newsletter, activity digest ("We miss
 *   you" included), unread-messages digest or "Your results". A complaint about a transactional e-mail changes nothing.
 *
 * Events can arrive twice and out of order; everything here is idempotent.
 */
#[AsMessageHandler]
readonly final class ProcessSesEventHandler
{
    private const array ACTIVITY_DIGEST_EMAIL_TYPES = ['content_digest_activity', 'content_digest_miss_you'];
    private const string UNREAD_DIGEST_EMAIL_TYPE = 'unread_digest';
    private const string RESULT_REVIEW_EMAIL_TYPE = 'result_review';
    private const string TOPIC_VALIDATION_MESSAGE = 'Successfully validated SNS topic for Amazon SES event publishing';

    public function __construct(
        private EmailAuditLogRepository $emailAuditLogRepository,
        private EmailSuppressionRepository $emailSuppressionRepository,
        private UserAccountRepository $userAccountRepository,
        private PlayerRepository $playerRepository,
        private NewsletterSubscriberRepository $newsletterSubscriberRepository,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessSesEvent $message): void
    {
        // SES posts this plain text (no JSON) whenever an event destination is created or changed
        if (str_starts_with($message->eventJson, self::TOPIC_VALIDATION_MESSAGE)) {
            $this->logger->info('SES validated the SNS topic of an event destination', [
                'sns_message_id' => $message->snsMessageId,
            ]);

            return;
        }

        try {
            $event = SesEvent::fromJson($message->eventJson);
        } catch (\JsonException | \InvalidArgumentException $exception) {
            // Signed by SNS, so it came from AWS - a shape we do not understand is worth a look
            $this->logger->warning('SES event could not be read', [
                'exception' => $exception,
                'sns_message_id' => $message->snsMessageId,
            ]);

            return;
        }

        $auditLog = $this->auditLog($event);

        switch ($event->type) {
            case 'Delivery':
                $auditLog?->markAsDelivered($event->occurredAt);
                break;

            case 'Open':
                $auditLog?->markAsOpened($event->occurredAt);
                break;

            case 'Click':
                $auditLog?->markAsClicked($event->occurredAt);
                break;

            case 'Bounce':
                $auditLog?->recordBounce(
                    $event->isPermanentBounce() ? BounceType::Hard : BounceType::Soft,
                    $event->occurredAt,
                    trim(sprintf('%s/%s %s', $event->bounceType, $event->bounceSubType, $event->diagnosticCode ?? '')),
                );

                if ($event->isPermanentBounce()) {
                    $this->suppress($event, $auditLog);
                }
                break;

            case 'Complaint':
                $auditLog?->markAsComplained($event->occurredAt);
                $this->applyComplaint($event, $auditLog);
                break;

            case 'Reject':
            case 'Rendering Failure':
                $this->logger->warning('SES did not send an e-mail', [
                    'type' => $event->type,
                    'email_type' => $event->emailType,
                    'audit_log_id' => $event->auditLogId,
                ]);
                break;
        }
    }

    private function auditLog(SesEvent $event): null|EmailAuditLog
    {
        if ($event->auditLogId === null || !Uuid::isValid($event->auditLogId)) {
            return null;
        }

        return $this->emailAuditLogRepository->find(Uuid::fromString($event->auditLogId));
    }

    private function suppress(SesEvent $event, null|EmailAuditLog $auditLog): void
    {
        $emailType = $event->stream === MailStream::Newsletter ? 'newsletter' : ($event->emailType ?? $auditLog?->emailType);

        foreach ($event->recipients as $recipient) {
            $suppression = $this->emailSuppressionRepository->findByEmail($recipient);

            if ($suppression !== null) {
                $suppression->bouncedAgain($event->occurredAt, $event->bounceSubType, $event->diagnosticCode, $emailType);

                continue;
            }

            $this->emailSuppressionRepository->save(new EmailSuppression(
                Uuid::uuid7(),
                EmailSuppression::canonicalize($recipient),
                $event->occurredAt,
                $event->bounceSubType,
                $event->diagnosticCode,
                $emailType,
            ));
        }
    }

    private function applyComplaint(SesEvent $event, null|EmailAuditLog $auditLog): void
    {
        $emailType = $event->emailType ?? $auditLog?->emailType;

        foreach ($event->recipients as $recipient) {
            if ($event->stream === MailStream::Newsletter) {
                $this->turnOffNewsletter($recipient, $event->occurredAt);

                continue;
            }

            $player = $this->playerByEmail($recipient);

            if ($player === null) {
                continue;
            }

            if (in_array($emailType, self::ACTIVITY_DIGEST_EMAIL_TYPES, true)) {
                $player->changeContentDigestFrequency(ContentDigestFrequency::Off);
            } elseif ($emailType === self::UNREAD_DIGEST_EMAIL_TYPE) {
                $player->changeEmailNotificationsEnabled(false);
            } elseif ($emailType === self::RESULT_REVIEW_EMAIL_TYPE) {
                $player->changeResultEmailsEnabled(false);
            }
        }

        $this->logger->info('Spam complaint about an e-mail', [
            'email_type' => $event->stream === MailStream::Newsletter ? 'newsletter' : $emailType,
            'audit_log_id' => $event->auditLogId,
        ]);
    }

    /**
     * The app is the source of truth for the newsletter: switched off here, pushed to Listmonk, and the sync never
     * switches it back on (only the person can)
     */
    private function turnOffNewsletter(string $email, \DateTimeImmutable $at): void
    {
        $player = $this->playerByEmail($email);
        $player?->changeNewsletterEnabled(false);

        $subscriber = $this->newsletterSubscriberRepository->findByEmail($email);
        $subscriber?->unsubscribe($at);

        if ($player !== null || $subscriber !== null) {
            $this->messageBus->dispatch(new PushNewsletterSubscriberToListmonk($email));
        }
    }

    private function playerByEmail(string $email): null|Player
    {
        $userAccount = $this->userAccountRepository->findByEmail($email);

        return $userAccount === null ? null : $this->playerRepository->findByUserId($userAccount->userId);
    }
}
