<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use SpeedPuzzling\Web\Services\Mailer\SesMessageHeaders;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Stands in for the mailer's one transport: answers its attempts from a script - an exception fails that attempt,
 * then it sends
 */
final class ScriptedTransports implements TransportInterface
{
    public int $attempts = 0;

    /** @var list<null|string> the stream marker of every message sent */
    public array $streams = [];

    /** @var list<null|string> X-Transport of every attempt - must always be null, the routing takes it off */
    public array $transportHeaders = [];

    /**
     * @param list<\Throwable> $failures
     */
    public function __construct(
        private array $failures = [],
    ) {
    }

    public function send(RawMessage $message, null|Envelope $envelope = null): null|SentMessage
    {
        \assert($message instanceof Message);
        $headers = $message->getHeaders();
        $this->attempts++;
        $this->transportHeaders[] = $headers->get('X-Transport')?->getBodyAsString();

        $failure = array_shift($this->failures);

        if ($failure !== null) {
            throw $failure;
        }

        $this->streams[] = $headers->get(SesMessageHeaders::STREAM_HEADER)?->getBodyAsString();

        return null;
    }

    public function __toString(): string
    {
        return 'scripted://';
    }
}
