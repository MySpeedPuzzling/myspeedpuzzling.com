<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\ProposePuzzleChangesFormData;
use SpeedPuzzling\Web\Query\GetManufacturers;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
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
            ->add('name', TextType::class, [
                'label' => 'puzzle_report.form.name',
                'help' => 'puzzle_report.form.name_help',
                'attr' => [
                    'placeholder' => 'puzzle_report.form.name_placeholder',
                ],
            ])
            ->add('manufacturerId', ChoiceType::class, [
                'label' => 'puzzle_report.form.manufacturer',
                'required' => false,
                'autocomplete' => true,
                'choices' => $manufacturerChoices,
                'placeholder' => 'puzzle_report.form.manufacturer_placeholder',
                'choice_translation_domain' => false,
            ])
            ->add('piecesCount', IntegerType::class, [
                'label' => 'puzzle_report.form.pieces_count',
                'attr' => [
                    'min' => 10,
                    'max' => 25000,
                ],
            ])
            ->add('ean', TextType::class, [
                'label' => 'puzzle_report.form.ean',
                'required' => false,
                'help' => 'puzzle_report.form.ean_help',
                'attr' => [
                    'placeholder' => 'puzzle_report.form.ean_placeholder',
                ],
            ])
            ->add('identificationNumber', TextType::class, [
                'label' => 'puzzle_report.form.identification_number',
                'required' => false,
                'help' => 'puzzle_report.form.identification_number_help',
                'attr' => [
                    'placeholder' => 'puzzle_report.form.identification_number_placeholder',
                ],
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
