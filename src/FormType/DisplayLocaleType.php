<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The language of a display's own texts, each named in itself (like the footer's language menu).
 *
 * @extends AbstractType<string>
 */
final class DisplayLocaleType extends AbstractType
{
    private const array NAMES = [
        'en' => 'English',
        'cs' => 'Čeština',
        'de' => 'Deutsch',
        'es' => 'Español',
        'fr' => 'Français',
        'ja' => '日本語',
    ];

    public function configureOptions(OptionsResolver $resolver): void
    {
        $choices = [];

        foreach (CompetitionDisplay::LOCALES as $locale) {
            $choices[self::NAMES[$locale]] = $locale;
        }

        $resolver->setDefaults([
            'choices' => $choices,
            'choice_translation_domain' => false,
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
