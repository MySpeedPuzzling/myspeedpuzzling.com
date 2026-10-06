<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use DateTimeImmutable;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A change that would reveal secret competition puzzles before their moment (a round moved into the past, a round
 * deleted, a puzzle removed from its round) without the caller saying yes to exactly that (the internal API's
 * `"confirmReveal": true`; the organiser's pages ask before they dispatch - SecretRevealPreview). Nothing was changed.
 *
 * An HTTP exception, so UnwrapHttpExceptionMiddleware hands it to the controller as itself.
 */
final class SecretPuzzlesWouldBeRevealed extends ConflictHttpException
{
    /**
     * @param list<array{id: string, name: string, everywhere: bool, hiddenElsewhereUntil: null|DateTimeImmutable}> $puzzles
     */
    public function __construct(
        readonly public array $puzzles,
    ) {
        parent::__construct(sprintf(
            'This would reveal secret puzzles before their moment: %s. Nothing was changed - send "confirmReveal": true to go ahead.',
            implode(', ', array_map(
                static fn (array $puzzle): string => sprintf('"%s" (%s)', $puzzle['name'], $puzzle['id']),
                $puzzles,
            )),
        ));
    }

    /**
     * @return list<array{puzzleId: string, name: string, revealedEverywhere: bool, stillHiddenElsewhereUntil: null|string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (array $puzzle): array => [
            'puzzleId' => $puzzle['id'],
            'name' => $puzzle['name'],
            'revealedEverywhere' => $puzzle['everywhere'],
            'stillHiddenElsewhereUntil' => $puzzle['hiddenElsewhereUntil']?->format(DATE_ATOM),
        ], $this->puzzles);
    }
}
