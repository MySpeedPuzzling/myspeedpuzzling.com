<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Mime\RawMessage;

/**
 * `MailerInterface::send()` with a delay: queues the e-mail exactly the way Symfony's Mailer does (the queued
 * `MessageEvent` for the listeners and their stamps, then `SendEmailMessage` on the default bus → the async
 * transport) plus a `DelayStamp`, so a batch dispatched in one go leaves spaced out. Everything at send time - the
 * `X-Transport` choice, the mail log (EmailAuditSubscriber), the VERP sender - runs in the worker as for any e-mail.
 */
readonly final class DelayedEmailQueue
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private EventDispatcherInterface $eventDispatcher,
        #[Autowire(service: 'mailer.transports')]
        private TransportInterface $transports,
    ) {
    }

    public function queue(RawMessage $message, int $delaySeconds): void
    {
        // A clone for the listeners, the original goes to the queue - as Mailer::send() does
        $clonedMessage = clone $message;
        $event = new MessageEvent($clonedMessage, Envelope::create($clonedMessage), (string) $this->transports, true);
        $this->eventDispatcher->dispatch($event);

        if ($event->isRejected()) {
            return;
        }

        $stamps = $event->getStamps();
        $stamps[] = new DelayStamp($delaySeconds * 1000);

        try {
            $this->messageBus->dispatch(new SendEmailMessage($message), $stamps);
        } catch (HandlerFailedException $e) {
            foreach ($e->getWrappedExceptions() as $nested) {
                if ($nested instanceof TransportExceptionInterface) {
                    throw $nested;
                }
            }

            throw $e;
        }
    }
}
