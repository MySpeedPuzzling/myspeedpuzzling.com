<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class NotifyWhenSubscriptionStarted
{

}
