<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\CollectionFormData;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<CollectionFormData>
 */
final class CollectionFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'collections.form.name',
            'required' => true,
            'attr' => [
                'maxlength' => 100,
            ],
        ]);

        $builder->add('description', TextareaType::class, [
            'label' => 'collections.form.description',
            'required' => false,
            'attr' => [
                'maxlength' => 500,
                'rows' => 4,
            ],
        ]);

        $builder->add('visibility', EnumType::class, [
            'class' => CollectionVisibility::class,
            'label' => 'form.visibility',
            'required' => true,
            'choice_label' => fn(CollectionVisibility $visibility) => match ($visibility) {
                CollectionVisibility::Private => 'form.visibility_private',
                CollectionVisibility::Public => 'form.visibility_public',
            },
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CollectionFormData::class,
        ]);
    }
}
