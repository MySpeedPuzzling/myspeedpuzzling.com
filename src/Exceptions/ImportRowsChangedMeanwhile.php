<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * The rows the player answered are not what their page showed any more - the sheet is just being read again for a
 * changed mapping, or the row was answered in another tab. Nothing was written.
 */
#[WithHttpStatus(Response::HTTP_CONFLICT)]
final class ImportRowsChangedMeanwhile extends \DomainException
{
}
