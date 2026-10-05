<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\ProposePuzzleChangesFormData;
use SpeedPuzzling\Web\Services\BrandChoicesBuilder;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ProposePuzzleChangesFormData>
 */
final class ProposePuzzleChangesFormType extends AbstractType
{
    public function __construct(
        private readonly BrandChoicesBuilder $brandChoicesBuilder,
    ) {
    }

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('names', PuzzleNamesType::class)
            ->add('recordVersion', HiddenType::class)
            // A brand id, or a typed name - the right spelling of a misspelled brand becomes a new brand
            // (ManufacturerResolver). Every brand, approved or not: the puzzle's own brand may be unapproved, and a
            // brand left out here gets typed again as a duplicate (docs/features/brand-duplicates.md)
            ->add('brand', TextType::class, [
                'label' => 'puzzle_report.form.brand',
                'help' => 'puzzle_report.form.brand_help',
                'required' => false,
                'autocomplete' => true,
                'empty_data' => '',
                'options_as_html' => true,
                'tom_select_options' => [
                    'create' => true,
                    'persist' => false,
                    'maxItems' => 1,
                    'options' => $this->brandChoicesBuilder->build(),
                    'closeAfterSelect' => true,
                    'createOnBlur' => true,
                    // Never `text`: that one is markup, so typing "img" or a puzzle count matched brands
                    'searchField' => ['name', 'eanPrefix'],
                ],
                // brand_picker_controller.js on the field's wrapper (templates/puzzle-report/_propose_changes_form.html.twig)
                'attr' => [
                    'placeholder' => 'puzzle_report.form.brand_placeholder',
                ],
            ])
            ->add('piecesCount', IntegerType::class, [
                'label' => 'puzzle_report.form.pieces_count',
                'attr' => [
                    'min' => 10,
                    'max' => 25000,
                ],
            ])
            // Labels and help in templates/puzzle-report/_propose_changes_form.html.twig (puzzle/_code_inputs.html.twig)
            ->add('eans', CodeListType::class, [
                'numeric' => true,
                'entry_options' => ['attr' => ['placeholder' => 'puzzle_report.form.ean_placeholder']],
            ])
            ->add('brandCodes', CodeListType::class, [
                'entry_options' => ['attr' => ['placeholder' => 'puzzle_report.form.identification_number_placeholder']],
            ])
            ->add('photo', FileType::class, [
                'label' => 'puzzle_report.form.photo',
                'required' => false,
                'attr' => [
                    'accept' => 'image/jpeg,image/png,image/webp',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProposePuzzleChangesFormData::class,
        ]);
    }
}
