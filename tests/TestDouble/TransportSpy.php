<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

final class TransportSpy implements TransportInterface
{
    /** @var list<RawMessage> */
    public array $sent = [];

    public function __construct(
        private readonly null|\Throwable $throwOnSend = null,
    ) {
    }

    public function send(RawMessage $message, null|Envelope $envelope = null): null|SentMessage
    {
        if ($this->throwOnSend !== null) {
            throw $this->throwOnSend;
        }

        $this->sent[] = $message;

        return null;
    }

    public function __toString(): string
    {
        return 'spy://';
    }
}
