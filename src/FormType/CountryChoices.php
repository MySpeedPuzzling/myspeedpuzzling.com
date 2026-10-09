<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The country choices of the event forms (the same groups as CompetitionFormType's): the most common countries first,
 * then every country - label => lower-case code, each option with its flag (`choiceAttr()`).
 */
readonly final class CountryChoices
{
    private const array MOST_COMMON = ['cz', 'sk', 'pl', 'de', 'at', 'no', 'fi', 'us', 'ca', 'fr', 'nz', 'es', 'nl', 'pt', 'gb'];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function choices(): array
    {
        $common = [];

        foreach (self::MOST_COMMON as $code) {
            $country = CountryCode::fromCode($code);

            if ($country !== null) {
                $common[$country->value] = $country->name;
            }
        }

        $all = [];

        foreach (CountryCode::cases() as $country) {
            $all[$country->value] = $country->name;
        }

        return [
            $this->translator->trans('forms.country_most_common') => $common,
            $this->translator->trans('forms.country_all') => $all,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function choiceAttr(string $countryCode): array
    {
        return ['data-icon' => 'fi fi-' . $countryCode];
    }
}
