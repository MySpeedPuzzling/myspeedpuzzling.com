<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\CustomFieldFormData;
use SpeedPuzzling\Web\Value\CustomFieldDefinition;
use SpeedPuzzling\Web\Value\CustomFieldType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One of a player's own fields - adding it, or (`with_type: false`) editing it: the type of a field never changes
 * (docs/features/custom-fields.md).
 *
 * @extends AbstractType<CustomFieldFormData>
 */
final class CustomFieldFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'my_fields.form.name',
            'attr' => [
                'maxlength' => CustomFieldDefinition::NAME_MAX_LENGTH,
                'autocomplete' => 'off',
            ],
        ]);

        if ($options['with_type'] === true) {
            $builder->add('type', EnumType::class, [
                'class' => CustomFieldType::class,
                'label' => 'my_fields.form.type',
                'choice_label' => static fn (CustomFieldType $type): string => 'my_fields.type.' . $type->value,
            ]);
        }

        if ($options['with_choices'] === true) {
            $builder->add('choices', TextareaType::class, [
                'label' => 'my_fields.form.choices',
                'help' => 'my_fields.form.choices_help',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                ],
            ]);
        }

        $builder->add('forResults', CheckboxType::class, [
            'label' => 'my_fields.form.for_results',
            'required' => false,
        ]);

        $builder->add('forCollectionItems', CheckboxType::class, [
            'label' => 'my_fields.form.for_collection_items',
            'help' => 'my_fields.form.for_collection_items_help',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CustomFieldFormData::class,
            'with_type' => true,
            'with_choices' => true,
        ]);

        $resolver->setAllowedTypes('with_type', 'bool');
        $resolver->setAllowedTypes('with_choices', 'bool');
    }
}
