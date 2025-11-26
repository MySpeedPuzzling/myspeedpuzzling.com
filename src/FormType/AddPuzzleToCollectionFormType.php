<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\AddPuzzleToCollectionFormData;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
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

        $collectionChoices = [];
        if (is_array($collections)) {
            foreach ($collections as $name => $collectionId) {
                $collectionChoices[] = [
                    'value' => $collectionId,
                    'text' => $name,
                ];
            }
        }

        $builder->add('collection', TextType::class, [
            'label' => 'forms.add_puzzle_to_collection.collection',
            'help' => 'forms.add_puzzle_to_collection.collection_help',
            'required' => true,
            'autocomplete' => true,
            'tom_select_options' => [
                'create' => true,
                'persist' => false,
                'maxItems' => 1,
                'options' => $collectionChoices,
                'closeAfterSelect' => true,
                'createOnBlur' => true,
            ],
            'attr' => [
                'class' => 'form-control',
                'maxlength' => 100,
            ],
        ]);

        $builder->add('collectionDescription', TextareaType::class, [
            'label' => 'collections.form.description',
            'required' => false,
            'help' => 'forms.max_characters',
            'attr' => [
                'class' => 'form-control',
                'rows' => 2,
            ],
        ]);

        $builder->add('collectionVisibility', EnumType::class, [
            'class' => CollectionVisibility::class,
            'label' => 'collections.form.visibility',
            'required' => true,
            'choice_label' => fn(CollectionVisibility $visibility) => match ($visibility) {
                CollectionVisibility::Private => 'collections.form.visibility_private',
                CollectionVisibility::Public => 'collections.form.visibility_public',
            },
        ]);

        $builder->add('comment', TextareaType::class, [
            'label' => 'forms.add_puzzle_to_collection.comment',
            'required' => false,
            'help' => 'forms.max_characters',
            'attr' => [
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
