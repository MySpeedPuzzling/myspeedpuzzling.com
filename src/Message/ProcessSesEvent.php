<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * One verified Amazon SES event (the SNS notification's Message, JSON) - async, so the endpoint answers SNS at once
 */
readonly final class ProcessSesEvent
{
    public function __construct(
        public string $eventJson,
        public string $snsMessageId,
    ) {
    }
}
