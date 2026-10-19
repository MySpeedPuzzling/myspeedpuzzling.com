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
 * Stands in for the mailer's Transports: picks by X-Transport like it does (and puts the header back after a
 * failure), and answers each transport's attempts from a script - an exception fails that attempt, then it sends
 */
final class ScriptedTransports implements TransportInterface
{
    /** @var list<null|string> X-Transport of every attempt */
    public array $attempts = [];

    /** @var list<null|string> the stream marker of every message sent */
    public array $streams = [];

    /** @var array<string, list<\Throwable>> */
    private array $script;

    /**
     * @param list<\Throwable> $ses
     * @param list<\Throwable> $transactional
     */
    public function __construct(array $ses = [], array $transactional = [])
    {
        $this->script = ['ses' => $ses, 'transactional' => $transactional];
    }

    public function send(RawMessage $message, null|Envelope $envelope = null): null|SentMessage
    {
        \assert($message instanceof Message);
        $headers = $message->getHeaders();
        $transport = $headers->get('X-Transport')?->getBodyAsString();
        $this->attempts[] = $transport;
        $headers->remove('X-Transport');

        $key = $transport ?? 'transactional';
        $this->script[$key] ??= [];
        $failure = array_shift($this->script[$key]);

        if ($failure !== null) {
            if ($transport !== null) {
                $headers->addTextHeader('X-Transport', $transport);
            }

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
