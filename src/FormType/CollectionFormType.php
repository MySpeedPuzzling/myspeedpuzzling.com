<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\CollectionFormData;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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

        $builder->add('visibility', ChoiceType::class, [
            'label' => 'collections.form.visibility',
            'required' => true,
            'expanded' => true,
            'choices' => [
                'collections.form.visibility_public' => CollectionVisibility::Public,
                'collections.form.visibility_private' => CollectionVisibility::Private,
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CollectionFormData::class,
        ]);
    }
}
