<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Value\PuzzleNameLanguageChoices;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `puzzle_name_language_choices()` - label => tag of PuzzleNameLanguageChoices in the page language, for a language
 * select outside a Symfony form (the approval queue's "merge into duplicate", one per candidate). Built once per
 * request and locale - the names and the sorting cost a few milliseconds each time.
 * `puzzle_name_language_option_attributes(tag)` - the flag and search alias of its <option> (language_select_controller.js).
 */
final class PuzzleNameLanguageChoicesTwigExtension extends AbstractExtension implements ResetInterface
{
    /**
     * @var array<string, array<string, string>> Locale => the choices
     */
    private array $choices = [];

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
            new TwigFunction('puzzle_name_language_choices', $this->languageChoices(...)),
            new TwigFunction('puzzle_name_language_option_attributes', PuzzleNameLanguageChoices::optionAttributes(...)),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function languageChoices(): array
    {
        $locale = $this->translator->getLocale();

        return $this->choices[$locale] ??= PuzzleNameLanguageChoices::choices($locale);
    }

    public function reset(): void
    {
        $this->choices = [];
    }
}
