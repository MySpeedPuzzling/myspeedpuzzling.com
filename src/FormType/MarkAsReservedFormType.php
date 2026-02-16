<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\MarkAsReservedFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<MarkAsReservedFormData>
 */
final class MarkAsReservedFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('reservedForInput', TextType::class, [
            'label' => 'sell_swap_list.mark_reserved.reserved_for_input',
            'required' => false,
            'help' => 'sell_swap_list.mark_reserved.reserved_for_input_help',
            'attr' => [
                'placeholder' => 'sell_swap_list.mark_reserved.reserved_for_input_placeholder',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MarkAsReservedFormData::class,
        ]);
    }
}
