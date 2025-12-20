<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\ReportDuplicatePuzzleFormData;
use SpeedPuzzling\Web\Query\GetManufacturers;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ReportDuplicatePuzzleFormData>
 */
final class ReportDuplicatePuzzleFormType extends AbstractType
{
    public function __construct(
        private readonly GetManufacturers $getManufacturers,
    ) {
    }

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $manufacturerChoices = [];
        foreach ($this->getManufacturers->onlyApprovedOrAddedByPlayer() as $manufacturer) {
            $manufacturerChoices["{$manufacturer->manufacturerName} ({$manufacturer->puzzlesCount})"] = $manufacturer->manufacturerId;
        }

        $builder
            ->add('duplicatePuzzleUrls', TextareaType::class, [
                'label' => 'puzzle_report.form.duplicate_urls',
                'required' => false,
                'help' => 'puzzle_report.form.duplicate_urls_help',
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'puzzle_report.form.duplicate_urls_placeholder',
                ],
            ])
            ->add('selectedManufacturerId', ChoiceType::class, [
                'label' => 'puzzle_report.form.search_manufacturer',
                'required' => false,
                'autocomplete' => true,
                'choices' => $manufacturerChoices,
                'placeholder' => 'puzzle_report.form.search_manufacturer_placeholder',
                'choice_translation_domain' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ReportDuplicatePuzzleFormData::class,
        ]);
    }
}
