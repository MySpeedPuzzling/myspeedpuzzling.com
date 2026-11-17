<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * "Same tables as round X": the round is not an earlier round of the same kind with table numbers (SeatingProposer).
 */
final class SeatingSourceRoundNotAvailable extends \RuntimeException
{
}
