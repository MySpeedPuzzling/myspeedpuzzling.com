<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\PuzzleNameFormData;
use SpeedPuzzling\Web\FormData\PuzzleNamesFormData;
use SpeedPuzzling\Web\Value\LanguageTag;
use SpeedPuzzling\Web\Value\PuzzleNameLanguageChoices;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every name of a puzzle (docs/features/puzzle-names/README.md, "Writing names and codes"): the main title, its
 * language when the box has no English title, and the other names with theirs. Rendered by
 * templates/puzzle/_names_editor.html.twig, which also adds and removes rows. Rows without a name are dropped.
 *
 * @extends AbstractType<PuzzleNamesFormData>
 */
final class PuzzleNamesType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array{new_name_language: null|string} $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => false,
                'attr' => [
                    'maxlength' => PuzzleNames::MAX_NAME_LENGTH,
                    'autocomplete' => 'off',
                ],
            ])
            ->add('alternativeNames', CollectionType::class, [
                'label' => false,
                'entry_type' => PuzzleNameType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'delete_empty' => static fn (null|PuzzleNameFormData $row): bool => $row === null || trim($row->name ?? '') === '',
                'prototype_data' => new PuzzleNameFormData(language: $options['new_name_language']),
                // "Too many names" belongs to the list, not to the whole form
                'error_bubbling' => false,
            ]);

        $addNameLanguage = static function (FormEvent $event): void {
            PuzzleNameLanguageType::addTo($event, 'nameLanguage', [
                'placeholder' => 'puzzle_names.main_title_language_not_set',
            ]);
        };

        $builder->addEventListener(FormEvents::PRE_SET_DATA, $addNameLanguage);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, $addNameLanguage);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PuzzleNamesFormData::class,
            'label' => false,
            'translation_domain' => 'messages',
            // A new row's language: the page language, none on English pages
            'new_name_language' => fn (Options $options): null|string => $this->pageLanguage(),
        ]);

        $resolver->setAllowedTypes('new_name_language', ['null', 'string']);
    }

    private function pageLanguage(): null|string
    {
        $language = LanguageTag::base($this->translator->getLocale());

        return $language !== 'en' && in_array($language, PuzzleNameLanguageChoices::LANGUAGES, true) ? $language : null;
    }
}
