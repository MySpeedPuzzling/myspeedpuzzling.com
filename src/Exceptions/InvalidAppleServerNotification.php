<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Exception;

/**
 * The POST to the Sign in with Apple notification endpoint is not an event
 * token Apple issued to us - malformed, badly signed, expired, or meant for
 * another app. Nothing is changed.
 */
final class InvalidAppleServerNotification extends Exception
{
}
