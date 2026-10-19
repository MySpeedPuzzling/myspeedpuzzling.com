<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * A preview of an activity digest for a period the player did nothing in - a real digest is never sent for one
 * (docs/features/content-digest/README.md "Who gets what"), so a preview of it would show an e-mail nobody gets.
 */
final class NoActivityInDigestPeriod extends \DomainException
{
}
