<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMail;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use SpeedPuzzling\Web\Services\Sandbox\SandboxPeople;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The sandbox sends real e-mail through production's Amazon SES account (organisers and API developers there need
 * their sign-in links, password resets and claim links), so every message leaving it is made unmistakable and kept
 * away from production (docs/features/sandbox/README.md "E-mail"):
 *
 * - mail to the made-up players (`test-user+…@myspeedpuzzling.com`) is never sent - nobody reads those addresses;
 * - the subject starts with "[Sandbox] ";
 * - every sender address is moved to the sandbox's own SES domain (`notify@notify.myspeedpuzzling.com` →
 *   `notify@sandbox.myspeedpuzzling.com`), which production's SES event handler ignores - a bounce or complaint
 *   about a sandbox mail never changes anything for a player on production;
 * - an SES tag `sandbox=1` says the same in every event.
 *
 * Nothing happens anywhere SANDBOX is 0.
 */
final readonly class SandboxMailSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SandboxMode $sandboxMode,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, array<int, array{0: string, 1: int}>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            MessageEvent::class => [
                // Before everything (EmailAuditSubscriber's 100 included): a mail to made-up players is neither
                // queued nor audited
                ['dropMadeUpRecipients', 200],
                // After MessageListener (0, the global From) and EmailAuditSubscriber (-100, the SES tags)
                ['markAsSandbox', -200],
            ],
        ];
    }

    public function dropMadeUpRecipients(MessageEvent $event): void
    {
        if ($this->sandboxMode->on === false) {
            return;
        }

        $message = $event->getMessage();

        if (!$message instanceof Email) {
            return;
        }

        $to = self::realAddresses($message->getTo());
        $cc = self::realAddresses($message->getCc());
        $bcc = self::realAddresses($message->getBcc());

        if ($to === [] && $cc === [] && $bcc === []) {
            $this->logger->info('Sandbox: e-mail to made-up players only - not sent', [
                'subject' => $message->getSubject(),
            ]);
            $event->reject();

            return;
        }

        $message->to(...$to);
        $message->cc(...$cc);
        $message->bcc(...$bcc);

        $envelope = $event->getEnvelope();
        $recipients = self::realAddresses($envelope->getRecipients());

        if ($recipients !== []) {
            $envelope->setRecipients($recipients);
        }
    }

    public function markAsSandbox(MessageEvent $event): void
    {
        if ($this->sandboxMode->on === false || $event->isQueued()) {
            return;
        }

        $message = $event->getMessage();

        if (!$message instanceof Email) {
            return;
        }

        $subject = $message->getSubject() ?? '';

        if (!str_starts_with($subject, SandboxMail::SUBJECT_PREFIX)) {
            $message->subject(SandboxMail::SUBJECT_PREFIX . $subject);
        }

        $message->from(...array_map(SandboxMail::senderAddress(...), $message->getFrom()));

        if ($message->getSender() !== null) {
            $message->sender(SandboxMail::senderAddress($message->getSender()));
        }

        $envelope = $event->getEnvelope();
        $envelope->setSender(SandboxMail::senderAddress($envelope->getSender()));

        SandboxMail::tag($message->getHeaders());
    }

    /**
     * @param array<Address> $addresses
     * @return list<Address>
     */
    private static function realAddresses(array $addresses): array
    {
        return array_values(array_filter(
            $addresses,
            static fn (Address $address): bool => SandboxPeople::isMadeUpEmail($address->getAddress()) === false,
        ));
    }
}
