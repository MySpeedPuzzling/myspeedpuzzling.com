<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EditSolvedPuzzlesFormData;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<EditSolvedPuzzlesFormData>
 */
final class EditSolvedPuzzlesFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('visibility', ChoiceType::class, [
            'label' => 'form.visibility',
            'required' => true,
            'expanded' => true,
            'choices' => [
                'form.visibility_public' => CollectionVisibility::Public,
                'form.visibility_private' => CollectionVisibility::Private,
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditSolvedPuzzlesFormData::class,
        ]);
    }
}
