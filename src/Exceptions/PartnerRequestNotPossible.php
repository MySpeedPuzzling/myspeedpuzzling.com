<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * "I'd like to puzzle with you" cannot be sent (docs/features/partner-finder.md D6): the sender's own listing, a closed
 * listing, a kind the listing does not look for, or an event without a partner finder any more. Never thrown for a
 * block - a blocked request is silently not sent.
 */
final class PartnerRequestNotPossible extends UnprocessableEntityHttpException
{
}
