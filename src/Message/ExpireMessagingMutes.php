<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Lifts every messaging mute whose end has passed (myspeedpuzzling:moderation:expire-mutes). Safe to run any time and
 * again.
 */
readonly final class ExpireMessagingMutes
{
}
