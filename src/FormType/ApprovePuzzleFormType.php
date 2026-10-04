<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\ApprovePuzzleFormData;
use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Results\BrandSuggestion;
use SpeedPuzzling\Web\Value\PuzzleApprovalBrandChoice;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The approval of a newly added puzzle: the record's fields shared with the other moderator forms, plus the brand -
 * an approved brand to keep or move to, or for a new brand the decision what happens to it. Labels live in the
 * template (English-only admin).
 *
 * @extends AbstractType<ApprovePuzzleFormData>
 */
final class ApprovePuzzleFormType extends AbstractType
{
    public function __construct(
        private readonly GetManufacturers $getManufacturers,
    ) {
    }

    /**
     * @param array{new_brand: bool, brand_suggestions: list<BrandSuggestion>} $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        PuzzleRecordFormType::addRecordFields($builder);

        // Only approved brands can take the puzzle (ApprovePuzzleHandler) - the likely ones first.
        // Keyed by id: two brands of the same name must not swallow each other as label keys
        $labels = [];
        $suggested = [];

        foreach ($options['brand_suggestions'] as $suggestion) {
            $labels[$suggestion->manufacturerId] = "{$suggestion->manufacturerName} ({$suggestion->puzzlesCount})"
                . ($suggestion->eanPrefixMatches ? ' - EAN prefix matches' : '');
            $suggested[] = $suggestion->manufacturerId;
        }

        $approved = [];

        foreach ($this->getManufacturers->onlyApprovedOrAddedByPlayer() as $manufacturer) {
            if (in_array($manufacturer->manufacturerId, $suggested, true)) {
                continue;
            }

            $labels[$manufacturer->manufacturerId] = "{$manufacturer->manufacturerName} ({$manufacturer->puzzlesCount})";
            $approved[] = $manufacturer->manufacturerId;
        }

        $builder->add('targetManufacturerId', ChoiceType::class, [
            'autocomplete' => true,
            'required' => false,
            'placeholder' => 'Select a brand',
            'choices' => $suggested !== []
                ? ['Suggested' => $suggested, 'All approved brands' => $approved]
                : $approved,
            'choice_label' => static fn (string $manufacturerId): string => $labels[$manufacturerId] ?? $manufacturerId,
        ]);

        if ($options['new_brand']) {
            $builder->add('brandChoice', EnumType::class, [
                'class' => PuzzleApprovalBrandChoice::class,
                'choices' => [
                    PuzzleApprovalBrandChoice::MergeInto,
                    PuzzleApprovalBrandChoice::UseExisting,
                    PuzzleApprovalBrandChoice::Approve,
                ],
                'expanded' => true,
                'required' => false,
                'placeholder' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ApprovePuzzleFormData::class,
            'translation_domain' => false,
            'new_brand' => false,
            'brand_suggestions' => [],
        ]);

        $resolver->setAllowedTypes('new_brand', 'bool');
        $resolver->setAllowedTypes('brand_suggestions', 'array');
    }
}
