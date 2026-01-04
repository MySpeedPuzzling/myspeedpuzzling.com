<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\ReportDuplicatePuzzleFormData;
use SpeedPuzzling\Web\Query\GetManufacturers;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<ReportDuplicatePuzzleFormData>
 */
final class ReportDuplicatePuzzleFormType extends AbstractType
{
    public function __construct(
        private readonly GetManufacturers $getManufacturers,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
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
            ->add('duplicatePuzzleUrl', TextType::class, [
                'label' => 'puzzle_report.form.duplicate_url',
                'required' => false,
                'help' => 'puzzle_report.form.duplicate_url_help',
                'attr' => [
                    'placeholder' => 'puzzle_report.form.duplicate_url_placeholder',
                ],
            ])
            ->add('selectedManufacturerId', ChoiceType::class, [
                'label' => 'puzzle_report.form.search_manufacturer',
                'required' => false,
                'autocomplete' => true,
                'choices' => $manufacturerChoices,
                'placeholder' => 'puzzle_report.form.search_manufacturer_placeholder',
                'choice_translation_domain' => false,
                'attr' => [
                    'data-fetch-url' => $this->urlGenerator->generate('puzzle_by_brand_autocomplete'),
                    'data-report-duplicate-form-target' => 'manufacturer',
                ],
            ])
            ->add('selectedPuzzleId', TextType::class, [
                'label' => 'puzzle_report.form.select_puzzle',
                'required' => false,
                'autocomplete' => true,
                'options_as_html' => true,
                'tom_select_options' => [
                    'create' => false,
                    'persist' => false,
                    'maxItems' => 1,
                    'closeAfterSelect' => true,
                ],
                'attr' => [
                    'data-report-duplicate-form-target' => 'puzzle',
                    'data-choose-manufacturer-placeholder' => $this->translator->trans('puzzle_report.form.select_manufacturer_first'),
                    'data-choose-puzzle-placeholder' => $this->translator->trans('puzzle_report.form.select_puzzle_placeholder'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ReportDuplicatePuzzleFormData::class,
        ]);
    }
}
