<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * The mapping the player changed is not the sheet's current one any more (another tab, another device).
 */
#[WithHttpStatus(Response::HTTP_CONFLICT)]
final class ImportMappingChangedMeanwhile extends \DomainException
{
}
