<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Results\AutocompletePuzzle;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Options of the puzzle picker (add-time, edit-time, add-to-round and report-duplicate forms). Tom Select shows
 * `text` as HTML (`options_as_html`) and names and codes are player-typed: every value is escaped where the HTML is
 * built, like BrandChoicesBuilder does (a Twig render per option cost ~25 ms on Ravensburger's 6,000 puzzles).
 * Tom Select searches `search` (the form types point `searchField` at it): only what identifies the puzzle, plain
 * text - no markup, no labels, no locale.
 */
readonly final class PuzzleChoicesBuilder
{
    public function __construct(
        private ImageThumbnailTwigExtension $imageThumbnail,
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
        // Translated once and split around the number: the number can be highlighted as a match, the label not.
        // The label is translation markup (&nbsp;), never player input.
        [$piecesLabelBefore, $piecesLabelAfter] = explode('%count%', $this->translator->trans('pieces_count', ['%count%' => '%count%'], locale: $locale), 2) + ['', ''];
        $options = [];

        foreach ($puzzles as $puzzle) {
            $alternativeName = $puzzle->puzzleAlternativeNames->legacyAlternativeName();
            $search = implode(' ', array_filter([
                $puzzle->puzzleName,
                $alternativeName,
                $puzzle->puzzleIdentificationNumber,
                $puzzle->puzzleEan,
                (string) $puzzle->piecesCount,
            ], static fn (null|string $value): bool => $value !== null && $value !== ''));

            $image = self::escape($puzzle->puzzleImage !== null
                ? $this->imageThumbnail->thumbnailUrl($puzzle->puzzleImage, 'puzzle_small')
                : '/img/placeholder-puzzle.jpg');
            $name = self::escape($puzzle->puzzleName);

            // Czech pages lead with the alternative name
            if ($locale === 'cs' && $alternativeName !== null) {
                $name = self::escape($alternativeName) . ' <small>(' . $name . ')</small>';
            }

            $identificationNumber = self::escape($puzzle->puzzleIdentificationNumber ?? '');
            $ean = $puzzle->puzzleEan !== null
                ? '<small class="text-muted ms-2">EAN: ' . self::escape($puzzle->puzzleEan) . '</small>'
                : '';

            // "no-highlight" keeps Tom Select from marking a typed "pieces" in the label
            $text = <<<HTML
<div class="py-1 d-flex low-line-height">
    <div class="icon me-2"><img alt="Puzzle image" class="img-fluid rounded-2" style="max-width: 60px; max-height: 60px;" src="{$image}"></div>
    <div class="pe-1">
        <div class="mb-1">
            <span class="h6">{$name}</span>
            <small class="text-muted">{$identificationNumber}</small>
        </div>
        <div class="description"><small><span class="no-highlight">{$piecesLabelBefore}</span>{$puzzle->piecesCount}<span class="no-highlight">{$piecesLabelAfter}</span></small>{$ean}</div>
    </div>
</div>
HTML;

            $options[] = [
                'value' => $puzzle->puzzleId,
                'text' => $text,
                'search' => $search,
                'piecesCount' => $puzzle->piecesCount,
            ];
        }

        return $options;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
