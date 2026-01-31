<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\ClaimVoucherFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ClaimVoucherFormData>
 */
final class ClaimVoucherFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('code', TextType::class, [
            'label' => 'Voucher code',
            'attr' => [
                'placeholder' => 'Enter your voucher code',
                'autocomplete' => 'off',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ClaimVoucherFormData::class,
        ]);
    }
}
