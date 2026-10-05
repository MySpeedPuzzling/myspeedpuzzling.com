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
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
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

        self::addNamesEditor($builder);
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
     * Every name of the puzzle (templates/puzzle/_names_editor.html.twig, in the record form's names card
     * templates/admin/_puzzle_record_names.html.twig) and the version of the record the form was loaded with - the
     * handler refuses a save over a newer one (PuzzleRecordVersion).
     *
     * @template TData
     *
     * @param FormBuilderInterface<TData> $builder
     */
    public static function addNamesEditor(FormBuilderInterface $builder): void
    {
        $builder
            ->add('names', PuzzleNamesType::class)
            ->add('recordVersion', HiddenType::class);
    }

    /**
     * The record's fields every moderator form shares - pieces, codes, a new photo and the note. The names come
     * from addNamesEditor(); the brand differs: the record form picks any brand, the approval settles a new one
     * (ApprovePuzzleFormType).
     *
     * @template TData
     *
     * @param FormBuilderInterface<TData> $builder
     */
    public static function addRecordFields(FormBuilderInterface $builder): void
    {
        $builder
            ->add('piecesCount', IntegerType::class, [
                'attr' => [
                    'min' => 1,
                    'inputmode' => 'numeric',
                ],
            ])
            ->add('eans', CodeListType::class, [
                'numeric' => true,
            ])
            ->add('brandCodes', CodeListType::class)
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
