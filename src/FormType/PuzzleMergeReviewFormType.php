<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\PuzzleMergeReviewFormData;
use SpeedPuzzling\Web\Query\GetManufacturers;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The review of a merge request (templates/admin/puzzle_merge_request_detail.html.twig): the survivor among the
 * reported puzzles, the merged record with every name in the names editor, and the record versions the page was
 * loaded with. Labels live in the template (English-only admin).
 *
 * @extends AbstractType<PuzzleMergeReviewFormData>
 */
final class PuzzleMergeReviewFormType extends AbstractType
{
    public function __construct(
        private readonly GetManufacturers $getManufacturers,
    ) {
    }

    /**
     * @param array{puzzle_ids: list<string>, image_puzzle_ids: list<string>} $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Every brand, approved or not - a reported puzzle's own brand may be unapproved.
        // Keyed by id: two brands of the same name must not swallow each other as label keys
        $manufacturerLabels = [];

        foreach ($this->getManufacturers->allIncludingUnapproved() as $manufacturer) {
            $manufacturerLabels[$manufacturer->manufacturerId] = "{$manufacturer->manufacturerName} ({$manufacturer->puzzlesCount})"
                . ($manufacturer->manufacturerApproved ? '' : ' - not approved');
        }

        $builder
            ->add('survivorPuzzleId', ChoiceType::class, [
                'choices' => $options['puzzle_ids'],
                'choice_label' => static fn (): string => 'Keep this one',
                'expanded' => true,
                'placeholder' => false,
            ])
            ->add('names', PuzzleNamesType::class)
            ->add('manufacturerId', ChoiceType::class, [
                'autocomplete' => true,
                'required' => false,
                'placeholder' => '-- Keep the brand of the puzzle that stays --',
                'choices' => array_keys($manufacturerLabels),
                'choice_label' => static fn (string $manufacturerId): string => $manufacturerLabels[$manufacturerId],
            ])
            ->add('piecesCount', IntegerType::class, [
                'attr' => ['min' => 1, 'inputmode' => 'numeric'],
            ])
            ->add('eans', CodeListType::class, [
                'numeric' => true,
            ])
            ->add('brandCodes', CodeListType::class)
            ->add('decisionNote', TextareaType::class, [
                'required' => false,
                'attr' => ['rows' => 2, 'placeholder' => 'Why - e.g. "same EAN, same box photo"'],
            ])
            ->add('recordVersions', CollectionType::class, [
                'entry_type' => HiddenType::class,
            ]);

        if (count($options['image_puzzle_ids']) > 1) {
            $builder->add('selectedImagePuzzleId', ChoiceType::class, [
                'choices' => $options['image_puzzle_ids'],
                'expanded' => true,
                'required' => false,
                'placeholder' => false,
            ]);
        }

        CodeListType::acceptLegacyFields($builder, ['ean' => 'eans', 'identificationNumber' => 'brandCodes']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PuzzleMergeReviewFormData::class,
            'translation_domain' => false,
        ]);

        $resolver->setRequired(['puzzle_ids', 'image_puzzle_ids']);
        $resolver->setAllowedTypes('puzzle_ids', 'string[]');
        $resolver->setAllowedTypes('image_puzzle_ids', 'string[]');
    }
}
