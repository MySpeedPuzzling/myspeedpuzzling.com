<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\ReviewPuzzleChangeRequestFormData;
use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Value\PuzzleBoxPhoto;
use SpeedPuzzling\Web\Value\PuzzleChangeRequestImageChoice;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Admin review of a puzzle change request - labels and help live in the template (English-only admin).
 *
 * @extends AbstractType<ReviewPuzzleChangeRequestFormData>
 */
final class ReviewPuzzleChangeRequestFormType extends AbstractType
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

        $imageChoices = $options['has_proposed_image']
            ? PuzzleChangeRequestImageChoice::cases()
            : [PuzzleChangeRequestImageChoice::Keep, PuzzleChangeRequestImageChoice::Upload];

        $builder
            ->add('name', TextType::class)
            ->add('alternativeName', TextType::class, [
                'required' => false,
            ])
            ->add('manufacturerId', ChoiceType::class, [
                'autocomplete' => true,
                'choices' => array_keys($manufacturerLabels),
                'choice_label' => static fn (string $manufacturerId): string => $manufacturerLabels[$manufacturerId],
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
            ->add('image', EnumType::class, [
                'class' => PuzzleChangeRequestImageChoice::class,
                'choices' => $imageChoices,
                'choice_label' => static fn (PuzzleChangeRequestImageChoice $choice): string => match ($choice) {
                    PuzzleChangeRequestImageChoice::Keep => 'Keep current',
                    PuzzleChangeRequestImageChoice::Proposed => 'Proposed by the player',
                    PuzzleChangeRequestImageChoice::Upload => 'Upload a new one',
                },
                'expanded' => true,
            ])
            ->add('photo', FileType::class, [
                'required' => false,
                'constraints' => [PuzzleBoxPhoto::constraint()],
                'attr' => [
                    'accept' => 'image/*',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ReviewPuzzleChangeRequestFormData::class,
            'translation_domain' => false,
            'has_proposed_image' => false,
        ]);

        $resolver->setAllowedTypes('has_proposed_image', 'bool');
    }
}
