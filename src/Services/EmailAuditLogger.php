<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EmailAuditLog;
use SpeedPuzzling\Web\Repository\EmailAuditLogRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Service\ResetInterface;

final class EmailAuditLogger implements ResetInterface
{
    /** @var array<int, EmailAuditLog> */
    private array $pendingAudits = [];

    public function __construct(
        private readonly EmailAuditLogRepository $emailAuditLogRepository,
        private readonly ClockInterface $clock,
        private readonly string $bounceEmailDomain,
    ) {
    }

    public function createAuditLog(Email $message, string $transportName): EmailAuditLog
    {
        $recipientEmail = $message->getTo()[0]->getAddress();
        $subject = $message->getSubject() ?? '';
        $emailType = $this->extractEmailType($message);

        $auditLog = new EmailAuditLog(
            id: Uuid::uuid7(),
            sentAt: $this->clock->now(),
            recipientEmail: $recipientEmail,
            subject: $subject,
            transportName: $transportName,
            emailType: $emailType,
        );

        $this->emailAuditLogRepository->save($auditLog);

        $objectId = spl_object_id($message);
        $this->pendingAudits[$objectId] = $auditLog;

        return $auditLog;
    }

    public function buildVerpAddress(EmailAuditLog $auditLog): null|Address
    {
        if ($this->bounceEmailDomain === '') {
            return null;
        }

        return new Address('bounce+' . $auditLog->id->toString() . '@' . $this->bounceEmailDomain);
    }

    public function recordSuccess(int $messageObjectId, string $messageId, string $smtpDebugLog): void
    {
        if (!isset($this->pendingAudits[$messageObjectId])) {
            return;
        }

        $auditLog = $this->pendingAudits[$messageObjectId];
        $auditLog->markAsSent($messageId, $smtpDebugLog);

        $this->emailAuditLogRepository->save($auditLog);
        unset($this->pendingAudits[$messageObjectId]);
    }

    public function recordFailure(int $messageObjectId, \Throwable $error): void
    {
        if (!isset($this->pendingAudits[$messageObjectId])) {
            return;
        }

        $auditLog = $this->pendingAudits[$messageObjectId];

        $debugLog = null;
        if ($error instanceof TransportExceptionInterface) {
            $debugLog = $error->getDebug();
        }

        $auditLog->markAsFailed($error->getMessage(), $debugLog);

        $this->emailAuditLogRepository->save($auditLog);
        unset($this->pendingAudits[$messageObjectId]);
    }

    public function reset(): void
    {
        $this->pendingAudits = [];
    }

    private function extractEmailType(Email $message): null|string
    {
        if (!$message instanceof TemplatedEmail) {
            return null;
        }

        $template = $message->getHtmlTemplate();

        if ($template === null) {
            return null;
        }

        return basename($template, '.html.twig');
    }
}
