<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use UnexpectedValueException;

/**
 * The id_token Microsoft's token endpoint returned is not one we accept:
 * badly signed, expired, issued to another app, or from a tenant other than
 * the personal-accounts (consumers) one. Nobody is signed in.
 */
final class InvalidMicrosoftIdToken extends UnexpectedValueException
{
}
