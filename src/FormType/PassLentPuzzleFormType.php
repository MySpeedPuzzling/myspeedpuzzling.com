<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\PassLentPuzzleFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PassLentPuzzleFormData>
 */
final class PassLentPuzzleFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('newHolderCode', TextType::class, [
                'label' => 'lend_borrow.form.new_holder_code',
                'attr' => [
                    'placeholder' => 'lend_borrow.form.new_holder_code_placeholder',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PassLentPuzzleFormData::class,
        ]);
    }
}
