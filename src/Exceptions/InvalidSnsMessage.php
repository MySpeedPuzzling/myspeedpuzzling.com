<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * A POST to the SES events endpoint that is not a genuine Amazon SNS message for one of our topics
 */
final class InvalidSnsMessage extends \RuntimeException
{
}
