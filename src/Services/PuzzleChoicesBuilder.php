<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Results\AutocompletePuzzle;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleName;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Options of the puzzle picker (add-time, edit-time, add-to-round and report-duplicate forms). Tom Select shows
 * `text` as HTML (`options_as_html`) and names and codes are player-typed: every value is escaped where the HTML is
 * built, like BrandChoicesBuilder does (a Twig render per option cost ~25 ms on Ravensburger's 6,000 puzzles).
 * Tom Select searches the plain-text fields of SEARCH_FIELDS - what identifies the puzzle, no markup, no labels,
 * no locale.
 */
readonly final class PuzzleChoicesBuilder
{
    /**
     * Tom Select's `searchField` for these options, weighted. Its scoring (@orchidjs/sifter) adds up, per field,
     * the typed word's share of the field's length (+0.5 when the field starts with it) times the weight - one long
     * field of every name would push a puzzle with five names below a puzzle with one for the very same title. So the
     * main title is a field of its own and weighs most, the other names come next: a typed main title ranks every
     * puzzle of that title alike, however many names it has, and an exact other name beats a longer main title
     * that merely starts with it. Codes and the piece count weigh least.
     */
    public const array SEARCH_FIELDS = [
        ['field' => 'name', 'weight' => 3],
        ['field' => 'names', 'weight' => 2],
        ['field' => 'codes', 'weight' => 1],
        ['field' => 'piecesCount', 'weight' => 1],
    ];

    public function __construct(
        private ImageThumbnailTwigExtension $imageThumbnail,
        private TranslatorInterface $translator,
        private PuzzleNameLanguage $puzzleNameLanguage,
    ) {
    }

    /**
     * @param iterable<AutocompletePuzzle|PuzzleOverview> $puzzles
     *
     * @return list<array{value: string, text: string, name: string, names: string, codes: string, piecesCount: int}>
     */
    public function build(iterable $puzzles, string $locale): array
    {
        // Translated once and split around the number: the number can be highlighted as a match, the label not.
        // The label is translation markup (&nbsp;), never player input.
        [$piecesLabelBefore, $piecesLabelAfter] = explode('%count%', $this->translator->trans('pieces_count', ['%count%' => '%count%'], locale: $locale), 2) + ['', ''];
        $viewerLanguage = $this->puzzleNameLanguage->forViewer();
        $options = [];

        foreach ($puzzles as $puzzle) {
            $image = self::escape($puzzle->puzzleImage !== null
                ? $this->imageThumbnail->thumbnailUrl($puzzle->puzzleImage, 'puzzle_small')
                : '/img/placeholder-puzzle.jpg');
            $name = self::escape($puzzle->puzzleName);

            // The main title first, the name in the viewer's language after it, as in every list
            $localName = $puzzle->puzzleAlternativeNames->shownUnder($puzzle->puzzleName, $viewerLanguage);

            if ($localName !== null) {
                $name .= ' <small lang="' . self::escape((string) $localName->language) . '">(' . self::escape($localName->name) . ')</small>';
            }

            // Each code as printed on the box - a UPC with its 12th digit (EanList::display())
            $eans = implode(', ', EanList::fromStored($puzzle->puzzleEan)->display());
            $brandCodes = implode(', ', BrandCodeList::fromStored($puzzle->puzzleIdentificationNumber)->display());
            $identificationNumber = self::escape($brandCodes);
            $ean = $eans !== ''
                ? '<small class="text-muted ms-2">EAN: ' . self::escape($eans) . '</small>'
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
                'name' => $puzzle->puzzleName,
                'names' => implode("\n", array_map(
                    static fn (PuzzleName $name): string => $name->name,
                    $puzzle->puzzleAlternativeNames->all(),
                )),
                'codes' => implode("\n", array_filter(
                    [$eans, $brandCodes],
                    static fn (string $codes): bool => $codes !== '',
                )),
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
