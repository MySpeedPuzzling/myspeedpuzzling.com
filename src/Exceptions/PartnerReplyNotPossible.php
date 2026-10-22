<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * A partner request cannot be answered with a chat (docs/features/partner-finder.md D6): one of the two blocks the other
 * since it was sent. The listed puzzler is told only a neutral line - never why.
 */
final class PartnerReplyNotPossible extends UnprocessableEntityHttpException
{
}
