<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\BorrowPuzzleFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<BorrowPuzzleFormData>
 */
final class BorrowPuzzleFormType extends AbstractType
{
    /**
     * @param array<mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $borrowingType = $options['borrowing_type'] ?? 'to';

        $builder
            ->add('person', TextType::class, [
                'label' => $borrowingType === 'to' ? 'Person borrowing from you' : 'Person you are borrowing from',
                'required' => true,
                'attr' => [
                    'placeholder' => 'Enter player name or ID',
                    'class' => 'form-control',
                ],
                'help' => 'You can enter a player ID or just a person\'s name',
            ])
            ->add('borrowingType', HiddenType::class, [
                'data' => $borrowingType,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BorrowPuzzleFormData::class,
            'borrowing_type' => 'to',
        ]);

        $resolver->setAllowedValues('borrowing_type', ['to', 'from']);
    }
}
