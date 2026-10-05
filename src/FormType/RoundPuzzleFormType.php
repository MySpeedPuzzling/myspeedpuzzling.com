<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\RoundPuzzleFormData;
use SpeedPuzzling\Web\Services\BrandChoicesBuilder;
use SpeedPuzzling\Web\Services\PuzzleChoicesBuilder;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<RoundPuzzleFormData>
 */
final class RoundPuzzleFormType extends AbstractType
{
    public function __construct(
        private readonly BrandChoicesBuilder $brandChoicesBuilder,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $brandChoices = $this->brandChoicesBuilder->build();

        /** @var string $competitionId */
        $competitionId = $options['competition_id'];

        $builder->add('brand', TextType::class, [
            'label' => 'forms.brand',
            'help' => 'forms.brand_help',
            'required' => true,
            'autocomplete' => true,
            'empty_data' => '',
            'options_as_html' => true,
            'tom_select_options' => [
                'create' => true,
                'persist' => false,
                'maxItems' => 1,
                'options' => $brandChoices,
                'closeAfterSelect' => true,
                'createOnBlur' => true,
                // Never `text`: that one is markup, so typing "img" or a puzzle count matched brands
                'searchField' => ['name', 'eanPrefix'],
            ],
            'attr' => [
                // The competition's own secret puzzles are listed for its organiser (PuzzleByBrandAutocompleteController)
                'data-fetch-url' => $this->urlGenerator->generate('puzzle_by_brand_autocomplete', ['competition' => $competitionId]),
            ],
        ]);

        $builder->add('puzzle', TextType::class, [
            'label' => 'forms.puzzle',
            'help' => 'forms.puzzle_help',
            'required' => true,
            'autocomplete' => true,
            'options_as_html' => true,
            'tom_select_options' => [
                'create' => true,
                'persist' => false,
                'maxItems' => 1,
                'closeAfterSelect' => true,
                'createOnBlur' => true,
                'searchField' => PuzzleChoicesBuilder::SEARCH_FIELDS,
            ],
            'attr' => [
                'data-choose-brand-placeholder' => $this->translator->trans('forms.puzzle_choose_brand_placeholder'),
                'data-choose-puzzle-placeholder' => $this->translator->trans('forms.puzzle_choose_placeholder'),
            ],
        ]);

        $builder->add('piecesCount', NumberType::class, [
            'label' => 'forms.pieces_count',
            'required' => false,
        ]);

        $builder->add('puzzlePhoto', FileType::class, [
            'label' => 'forms.puzzle_photo',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '10M',
                    mimeTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                ),
            ],
        ]);

        // Labels in templates/add_puzzle_to_round.html.twig (puzzle/_code_inputs.html.twig)
        $builder->add('puzzleEans', CodeListType::class, [
            'numeric' => true,
        ]);

        $builder->add('puzzleBrandCodes', CodeListType::class);

        $builder->add('hideUntilRoundStarts', CheckboxType::class, [
            'label' => 'competition.round_puzzle.form.hide_until_round_starts',
            'required' => false,
        ]);

        $builder->add('hideMode', EnumType::class, [
            'class' => PuzzleHideMode::class,
            'label' => false,
            'expanded' => true,
            'choice_label' => fn (PuzzleHideMode $mode) => match ($mode) {
                PuzzleHideMode::ImageOnly => 'competition.round_puzzle.form.hide_mode_image_only',
                PuzzleHideMode::Entirely => 'competition.round_puzzle.form.hide_mode_entirely',
            },
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RoundPuzzleFormData::class,
        ]);

        $resolver->setRequired('competition_id');
        $resolver->setAllowedTypes('competition_id', 'string');
    }
}
