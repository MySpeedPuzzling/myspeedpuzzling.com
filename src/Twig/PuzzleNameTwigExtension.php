<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\PuzzleNameLanguage;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\LanguageTag;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleSearchQuery;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * A puzzle's other names on the page (docs/features/puzzle-names/README.md "Display" and "SEO"):
 * - `puzzle_local_name(names, main_title)` - the second line in the viewer's language (PuzzleNameLanguage::forViewer),
 * - `puzzle_page_local_name(names, main_title)` - the same in the page language only, for what crawlers read,
 * - `puzzle_matched_name(names, main_title, query, shown)` - the name a search found the puzzle by, when the lines
 *   shown do not say it,
 * - `puzzle_other_names(names)` - "Also known as": every name with its language named in the page language,
 * - `puzzle_gtins(ean)` - the valid barcodes for structured data.
 */
final class PuzzleNameTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private PuzzleNameLanguage $puzzleNameLanguage,
        readonly private RequestStack $requestStack,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('puzzle_local_name', $this->localName(...)),
            new TwigFunction('puzzle_page_local_name', $this->pageLocalName(...)),
            new TwigFunction('puzzle_matched_name', $this->matchedName(...)),
            new TwigFunction('puzzle_other_names', $this->otherNames(...)),
            new TwigFunction('puzzle_gtins', EanList::gtins(...)),
        ];
    }

    public function localName(PuzzleNames $names, string $mainTitle): null|PuzzleName
    {
        return $names->shownUnder($mainTitle, $this->puzzleNameLanguage->forViewer());
    }

    public function pageLocalName(PuzzleNames $names, string $mainTitle): null|PuzzleName
    {
        return $names->shownUnder($mainTitle, $this->puzzleNameLanguage->forPage());
    }

    public function matchedName(PuzzleNames $names, string $mainTitle, null|string $query, null|PuzzleName $shown): null|PuzzleName
    {
        return $names->matching(PuzzleSearchQuery::fromUserInput($query), $mainTitle, $shown);
    }

    /**
     * @return list<array{name: PuzzleName, language_name: null|string}>
     */
    public function otherNames(PuzzleNames $names): array
    {
        $pageLocale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';

        return array_map(
            static fn (PuzzleName $name): array => [
                'name' => $name,
                'language_name' => $name->language !== null ? LanguageTag::displayName($name->language, $pageLocale) : null,
            ],
            $names->inListingOrder($this->puzzleNameLanguage->forViewer()),
        );
    }
}
