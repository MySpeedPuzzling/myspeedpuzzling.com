<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\AddPuzzleToCollectionFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AddPuzzleToCollectionFormData>
 */
final class AddPuzzleToCollectionFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $collections = $options['collections'] ?? [];

        $builder->add('collectionId', ChoiceType::class, [
                'label' => 'forms.add_puzzle_to_collection.collection',
                'choices' => $collections,
                'required' => true,
                'attr' => [
                    'class' => 'form-select',
                ],
            ]);

        $builder->add('comment', TextareaType::class, [
                'label' => 'forms.add_puzzle_to_collection.comment',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'maxlength' => 500,
                    'placeholder' => 'forms.add_puzzle_to_collection.comment_placeholder',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AddPuzzleToCollectionFormData::class,
            'collections' => [],
        ]);

        $resolver->setAllowedTypes('collections', 'array');
    }
}
