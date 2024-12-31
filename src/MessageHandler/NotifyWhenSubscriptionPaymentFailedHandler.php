<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\NotifyAboutFailedPayment;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class NotifyWhenSubscriptionPaymentFailedHandler
{
    public function __invoke(NotifyAboutFailedPayment $message): void
    {
    }
}
