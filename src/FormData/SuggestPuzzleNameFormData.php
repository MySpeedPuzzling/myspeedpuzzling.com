<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\LanguageTag;
use SpeedPuzzling\Web\Value\PuzzleNameLanguageChoices;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * "Suggest another name" (SuggestPuzzleNameFormType): one name and its language.
 */
final class SuggestPuzzleNameFormData
{
    #[NotBlank(normalizer: 'trim')]
    #[Length(max: PuzzleNames::MAX_NAME_LENGTH, maxMessage: 'puzzle_names.name_too_long')]
    public null|string $name = null;

    // A BCP 47 tag from PuzzleNameLanguageChoices, null = not known
    public null|string $language = null;

    // Moderators and admins only
    public bool $makeMainTitle = false;

    /**
     * The language starts as the page's - like a new row of the names editor, none on English pages.
     */
    public static function forPageLocale(string $locale): self
    {
        $language = LanguageTag::base($locale);

        $data = new self();
        $data->language = $language !== 'en' && in_array($language, PuzzleNameLanguageChoices::LANGUAGES, true) ? $language : null;

        return $data;
    }
}
