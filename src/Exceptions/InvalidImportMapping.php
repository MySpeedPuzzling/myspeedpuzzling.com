<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\WithHttpStatus;

/**
 * A sheet's mapping (or an answer to one of its questions) that can not be stored: a shape we do not know, a column
 * outside the table, two columns on one single-valued field, an answer nobody asked for.
 */
#[WithHttpStatus(Response::HTTP_UNPROCESSABLE_ENTITY)]
final class InvalidImportMapping extends \DomainException
{
}
