<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Results\AutocompletePuzzle;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Options of the puzzle picker (add-time, edit-time, add-to-round and report-duplicate forms). Tom Select shows
 * `text` as HTML (`options_as_html`) and names and codes are player-typed, so it comes from the auto-escaped
 * puzzle/_picker_option.html.twig. Tom Select searches `search` (the form types point `searchField` at it): only
 * what identifies the puzzle, plain text - no markup, no labels, no locale.
 */
readonly final class PuzzleChoicesBuilder
{
    public function __construct(
        private Environment $twig,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param iterable<AutocompletePuzzle|PuzzleOverview> $puzzles
     *
     * @return list<array{value: string, text: string, search: string, piecesCount: int}>
     */
    public function build(iterable $puzzles, string $locale): array
    {
        $template = $this->twig->load('puzzle/_picker_option.html.twig');
        // Translated once and split around the number: the number can be highlighted as a match, the label not
        [$piecesLabelBefore, $piecesLabelAfter] = explode('%count%', $this->translator->trans('pieces_count', ['%count%' => '%count%'], locale: $locale), 2) + ['', ''];
        $options = [];

        foreach ($puzzles as $puzzle) {
            $search = implode(' ', array_filter([
                $puzzle->puzzleName,
                $puzzle->puzzleAlternativeName,
                $puzzle->puzzleIdentificationNumber,
                $puzzle->puzzleEan,
                (string) $puzzle->piecesCount,
            ], static fn (null|string $value): bool => $value !== null && $value !== ''));

            // Plain values render faster than the object's properties (Ravensburger: 6,000 options)
            $text = $template->render([
                'name' => $puzzle->puzzleName,
                // Czech pages lead with the alternative name
                'leading_alternative_name' => $locale === 'cs' ? $puzzle->puzzleAlternativeName : null,
                'identification_number' => $puzzle->puzzleIdentificationNumber,
                'ean' => $puzzle->puzzleEan,
                'pieces_count' => $puzzle->piecesCount,
                'pieces_label_before' => $piecesLabelBefore,
                'pieces_label_after' => $piecesLabelAfter,
                'image' => $puzzle->puzzleImage,
            ]);

            $options[] = [
                'value' => $puzzle->puzzleId,
                'text' => $text,
                'search' => $search,
                'piecesCount' => $puzzle->piecesCount,
            ];
        }

        return $options;
    }
}
