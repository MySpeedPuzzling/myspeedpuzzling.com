<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\PuzzleRecordFormData;
use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Value\PuzzleBoxPhoto;
use SpeedPuzzling\Web\Value\PuzzleImageChoice;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A puzzle's whole catalogue record: the review of a change request and a moderator's direct edit.
 * Labels and help live in the template (English-only admin).
 *
 * @extends AbstractType<PuzzleRecordFormData>
 */
final class PuzzleRecordFormType extends AbstractType
{
    public function __construct(
        private readonly GetManufacturers $getManufacturers,
    ) {
    }

    /**
     * @param array{has_proposed_image: bool} $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Every brand, approved or not - the puzzle's own brand may be unapproved.
        // Keyed by id: two brands of the same name and count must not swallow each other as label keys
        $manufacturerLabels = [];
        foreach ($this->getManufacturers->allIncludingUnapproved() as $manufacturer) {
            $manufacturerLabels[$manufacturer->manufacturerId] = "{$manufacturer->manufacturerName} ({$manufacturer->puzzlesCount})"
                . ($manufacturer->manufacturerApproved ? '' : ' - not approved');
        }

        self::addRecordFields($builder);

        $builder->add('manufacturerId', ChoiceType::class, [
            'autocomplete' => true,
            'choices' => array_keys($manufacturerLabels),
            'choice_label' => static fn (string $manufacturerId): string => $manufacturerLabels[$manufacturerId],
        ]);

        // Without a proposed image there is nothing to choose - the current one stays unless a photo is uploaded
        if ($options['has_proposed_image']) {
            $builder->add('image', EnumType::class, [
                'class' => PuzzleImageChoice::class,
                'choices' => [PuzzleImageChoice::Keep, PuzzleImageChoice::Proposed],
                'choice_label' => static fn (PuzzleImageChoice $choice): string => match ($choice) {
                    PuzzleImageChoice::Proposed => 'Proposed by the player',
                    default => 'Keep current',
                },
                'expanded' => true,
            ]);
        }
    }

    /**
     * The record's fields every moderator form shares - name, alternative name, pieces, codes, a new photo and the
     * note. The brand differs: the record form picks any brand, the approval settles a new one (ApprovePuzzleFormType).
     *
     * @template TData
     *
     * @param FormBuilderInterface<TData> $builder
     */
    public static function addRecordFields(FormBuilderInterface $builder): void
    {
        $builder
            ->add('name', TextType::class)
            ->add('alternativeName', TextType::class, [
                'required' => false,
            ])
            ->add('piecesCount', IntegerType::class, [
                'attr' => [
                    'min' => 1,
                    'inputmode' => 'numeric',
                ],
            ])
            ->add('ean', TextType::class, [
                'required' => false,
                'attr' => [
                    'inputmode' => 'numeric',
                ],
            ])
            ->add('identificationNumber', TextType::class, [
                'required' => false,
            ])
            ->add('puzzlePhoto', FileType::class, [
                'required' => false,
                'constraints' => [PuzzleBoxPhoto::constraint()],
            ])
            ->add('note', TextareaType::class, [
                'required' => false,
                'attr' => [
                    'rows' => 2,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PuzzleRecordFormData::class,
            'translation_domain' => false,
            'has_proposed_image' => false,
        ]);

        $resolver->setAllowedTypes('has_proposed_image', 'bool');
    }
}
