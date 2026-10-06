<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "This puzzle is still secret until … – you can add it after the reveal." in the page's language, the moment in the
 * zone of the round it waits for.
 */
readonly final class SecretPuzzleRefusalMessage
{
    public function __construct(
        private TranslatorInterface $translator,
        private ZonedDateTimeFormatter $zonedDateTimeFormatter,
    ) {
    }

    public function notRevealedYet(PuzzleNotRevealedYet $refusal): string
    {
        [$key, $parameters] = $this->translation($refusal);

        return $this->translator->trans($key, $parameters);
    }

    /**
     * For a template that translates itself (a Live component keeps the key and its parameters).
     *
     * @return array{string, array<string, string>}
     */
    public function translation(PuzzleNotRevealedYet $refusal): array
    {
        if ($refusal->revealsAt === null) {
            return ['secret_puzzle.not_revealed_yet_manual', []];
        }

        return ['secret_puzzle.not_revealed_yet', [
            '%time%' => $this->zonedDateTimeFormatter->format($refusal->revealsAt, $refusal->timezone),
        ]];
    }
}
