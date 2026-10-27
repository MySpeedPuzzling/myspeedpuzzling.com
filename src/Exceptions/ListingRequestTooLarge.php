<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * More sellers, listings or offered puzzles in one send than AskForListings allows - the sheet never offers that, so only a hand-made request gets here.
 */
final class ListingRequestTooLarge extends UnprocessableEntityHttpException
{
}
