<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\PuzzleNameFormData;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One row of "Other names" in the names editor: the name and its language. Labels live in the partial
 * (templates/puzzle/_names_editor.html.twig).
 *
 * @extends AbstractType<PuzzleNameFormData>
 */
final class PuzzleNameType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => false,
            'required' => false,
            'attr' => [
                'maxlength' => PuzzleNames::MAX_NAME_LENGTH,
                'autocomplete' => 'off',
            ],
        ]);

        $addLanguage = static function (FormEvent $event): void {
            PuzzleNameLanguageType::addTo($event, 'language');
        };

        $builder->addEventListener(FormEvents::PRE_SET_DATA, $addLanguage);
        $builder->addEventListener(FormEvents::PRE_SUBMIT, $addLanguage);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PuzzleNameFormData::class,
            'label' => false,
            'translation_domain' => 'messages',
        ]);
    }
}
