<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Value\PuzzleNameLanguageChoices;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `puzzle_name_language_choices()` - label => tag of PuzzleNameLanguageChoices in the page language, for a language
 * select outside a Symfony form (the approval queue's "merge into duplicate").
 */
final class PuzzleNameLanguageChoicesTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('puzzle_name_language_choices', fn (): array => PuzzleNameLanguageChoices::choices($this->translator->getLocale())),
        ];
    }
}
