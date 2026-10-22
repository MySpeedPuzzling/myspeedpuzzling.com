<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PartnerFinder;

use SpeedPuzzling\Web\Value\PartnerStrength;
use SpeedPuzzling\Web\Value\SearchText;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Which preset a typed "Good at" chip means (docs/features/partner-finder.md D3): its label in any of the 6 locales or
 * its value, compared folded (SearchText::fold()). Built once per process on first use - the catalogues do not change
 * while it runs, so no reset between requests is needed.
 */
final class PartnerStrengthCatalogue
{
    public const array LOCALES = ['en', 'cs', 'de', 'es', 'fr', 'ja'];

    /** @var null|array<string, PartnerStrength> folded label or value => preset */
    private null|array $byFoldedText = null;

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function find(string $text): null|PartnerStrength
    {
        $folded = SearchText::fold($text);

        if ($folded === '') {
            return null;
        }

        return $this->byFoldedText()[$folded] ?? null;
    }

    /**
     * @return array<string, PartnerStrength>
     */
    private function byFoldedText(): array
    {
        if ($this->byFoldedText !== null) {
            return $this->byFoldedText;
        }

        $map = [];

        foreach (PartnerStrength::cases() as $strength) {
            $map[SearchText::fold($strength->value)] = $strength;
            $map[SearchText::fold(str_replace('_', ' ', $strength->value))] = $strength;
        }

        foreach (self::LOCALES as $locale) {
            foreach (PartnerStrength::cases() as $strength) {
                $label = $this->translator->trans($strength->translationKey(), domain: 'messages', locale: $locale);

                // A label not translated yet comes back as its key - that is no label
                if ($label === $strength->translationKey()) {
                    continue;
                }

                // The first locale's meaning wins should two locales share a spelling for different presets
                $map[SearchText::fold($label)] ??= $strength;
            }
        }

        return $this->byFoldedText = $map;
    }
}
