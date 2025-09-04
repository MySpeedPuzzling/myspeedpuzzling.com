<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\Entity\PuzzleBorrowing;
use SpeedPuzzling\Web\FormData\BorrowPuzzleFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
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
        $hasActiveBorrowing = $options['has_active_borrowing'] ?? false;

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

        if ($hasActiveBorrowing) {
            /** @var array<PuzzleBorrowing> $activeBorrowings */
            $activeBorrowings = $options['active_borrowings'] ?? [];

            // Add checkbox for each active borrowing
            foreach ($activeBorrowings as $borrowing) {
                $borrowingId = $borrowing->id->toString();

                // Determine the borrower/lender name
                $personName = '';
                if ($borrowingType === 'to') {
                    if ($borrowing->borrower !== null) {
                        $personName = $borrowing->borrower->name;
                    } elseif ($borrowing->nonRegisteredPersonName !== null) {
                        $personName = $borrowing->nonRegisteredPersonName;
                    } else {
                        $personName = 'Unknown borrower';
                    }
                } else {
                    // When borrowing from someone, the owner is always the logged-in user
                    // The actual lender info is in nonRegisteredPersonName or would be in a different field
                    if ($borrowing->nonRegisteredPersonName !== null) {
                        $personName = $borrowing->nonRegisteredPersonName;
                    } else {
                        $personName = 'Unknown lender';
                    }
                }

                $builder->add('return_' . str_replace('-', '_', $borrowingId), CheckboxType::class, [
                    'label' => sprintf(
                        'Mark as returned from %s (borrowed %s)',
                        $personName,
                        $borrowing->borrowedAt->format('d.m.Y')
                    ),
                    'mapped' => false,
                    'required' => false,
                    'data' => true,
                    'attr' => [
                        'data-borrowing-id' => $borrowingId,
                    ],
                ]);
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BorrowPuzzleFormData::class,
            'borrowing_type' => 'to',
            'has_active_borrowing' => false,
            'active_borrowings' => [],
        ]);

        $resolver->setAllowedValues('borrowing_type', ['to', 'from']);
    }
}
