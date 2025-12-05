<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\BorrowPuzzleFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<BorrowPuzzleFormData>
 */
final class BorrowPuzzleFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ownerCode', TextType::class, [
                'label' => 'lend_borrow.form.owner_code',
                'attr' => [
                    'placeholder' => 'lend_borrow.form.owner_code_placeholder',
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'lend_borrow.form.notes',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'lend_borrow.form.notes_placeholder',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BorrowPuzzleFormData::class,
        ]);
    }
}
