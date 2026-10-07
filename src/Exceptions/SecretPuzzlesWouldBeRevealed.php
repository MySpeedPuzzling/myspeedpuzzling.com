<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Services\SecretRevealPreview;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A change that would reveal secret competition puzzles earlier than planned (a round's automatic reveal moved earlier by
 * its start or its reveal delay, a round deleted, a puzzle removed from its round) without the caller saying yes to
 * exactly that (the internal API's `"confirmReveal": true`; the organiser's pages ask before they dispatch -
 * SecretRevealPreview). Nothing was changed.
 *
 * An HTTP exception, so UnwrapHttpExceptionMiddleware hands it to the controller as itself.
 *
 * @phpstan-import-type RevealedPuzzle from SecretRevealPreview
 */
final class SecretPuzzlesWouldBeRevealed extends ConflictHttpException
{
    /**
     * @param list<RevealedPuzzle> $puzzles
     */
    public function __construct(
        readonly public array $puzzles,
    ) {
        parent::__construct(sprintf(
            'This would reveal secret puzzles earlier than planned: %s. Nothing was changed - send "confirmReveal": true to go ahead.',
            implode(', ', array_map(
                static fn (array $puzzle): string => sprintf(
                    '"%s" (%s, %s)',
                    $puzzle['name'],
                    $puzzle['id'],
                    $puzzle['revealsAt'] !== null ? 'at ' . $puzzle['revealsAt']->format(DATE_ATOM) : 'right away',
                ),
                $puzzles,
            )),
        ));
    }

    /**
     * rightAway / revealsAt: when it comes out (revealsAt null = right away); previousRevealsAt: the moment it moves from
     * (a round PATCH: the round's automatic reveal as it is now; null for a removal or a deleted round); scope: how far
     * (SecretRevealPreview) - revealedEverywhere and stillHiddenElsewhereUntil stay for callers that knew only those.
     *
     * @return list<array{puzzleId: string, name: string, rightAway: bool, revealsAt: null|string, previousRevealsAt: null|string, scope: string, revealedEverywhere: bool, stillHiddenElsewhereUntil: null|string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (array $puzzle): array => [
            'puzzleId' => $puzzle['id'],
            'name' => $puzzle['name'],
            'rightAway' => $puzzle['revealsAt'] === null,
            'revealsAt' => $puzzle['revealsAt']?->format(DATE_ATOM),
            'previousRevealsAt' => $puzzle['previousRevealsAt']?->format(DATE_ATOM),
            'scope' => $puzzle['scope'],
            'revealedEverywhere' => $puzzle['everywhere'],
            'stillHiddenElsewhereUntil' => $puzzle['hiddenElsewhereUntil']?->format(DATE_ATOM),
        ], $this->puzzles);
    }
}
