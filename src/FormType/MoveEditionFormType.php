<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\MoveEditionFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<MoveEditionFormData>
 */
final class MoveEditionFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('seriesId', ChoiceType::class, [
            'label' => 'restructure.move_edition.series',
            'choices' => $options['series_choices'],
            'placeholder' => 'restructure.move_edition.series_placeholder',
            'choice_translation_domain' => false,
            'autocomplete' => true,
        ]);

        $builder->add('slug', TextType::class, [
            'label' => 'restructure.move_edition.slug',
            'help' => 'restructure.move_edition.slug_help',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MoveEditionFormData::class,
            'series_choices' => [],
        ]);
        $resolver->setAllowedTypes('series_choices', 'array');
    }
}
