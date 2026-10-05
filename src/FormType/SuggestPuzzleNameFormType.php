<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\SuggestPuzzleNameFormData;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Suggest another name" from the puzzle page's menu: one name and its language. Moderators and admins may also make
 * it the main title - their names are saved at once.
 *
 * @extends AbstractType<SuggestPuzzleNameFormData>
 */
final class SuggestPuzzleNameFormType extends AbstractType
{
    /**
     * @param array{moderator: bool} $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'puzzle_names.suggest_name.name',
                'help' => 'puzzle_names.suggest_name.name_help',
                'attr' => [
                    'maxlength' => PuzzleNames::MAX_NAME_LENGTH,
                    'autocomplete' => 'off',
                ],
            ])
            ->add('language', PuzzleNameLanguageType::class, [
                'label' => 'puzzle_names.suggest_name.language',
            ]);

        if ($options['moderator']) {
            $builder->add('makeMainTitle', CheckboxType::class, [
                'label' => 'puzzle_names.suggest_name.make_main_title',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SuggestPuzzleNameFormData::class,
            'moderator' => false,
        ]);

        $resolver->setAllowedTypes('moderator', 'bool');
    }
}
