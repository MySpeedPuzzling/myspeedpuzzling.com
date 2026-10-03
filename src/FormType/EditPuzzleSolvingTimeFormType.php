<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\EditPuzzleSolvingTimeFormData;
use SpeedPuzzling\Web\Services\BrandChoicesBuilder;
use SpeedPuzzling\Web\Services\CompetitionChoicesBuilder;
use SpeedPuzzling\Web\Value\CompetitionChoices;
use SpeedPuzzling\Web\Value\PuzzleAddMode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<EditPuzzleSolvingTimeFormData>
 */
final class EditPuzzleSolvingTimeFormType extends AbstractType
{
    public function __construct(
        readonly private BrandChoicesBuilder $brandChoicesBuilder,
        readonly private TranslatorInterface $translator,
        readonly private UrlGeneratorInterface $urlGenerator,
        readonly private CompetitionChoicesBuilder $competitionChoicesBuilder,
    ) {
    }

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $brandChoices = $this->brandChoicesBuilder->build();

        // The competition this time is linked to is always offered, even when it is not publicly
        // visible (any more) — otherwise the control renders empty and a re-save detaches the time
        /** @var null|string $currentCompetitionId */
        $currentCompetitionId = $options['current_competition_id'] ?? null;
        $competitionChoices = $this->competitionChoicesBuilder->build($currentCompetitionId);

        // Mode field (hidden, controlled by JS) - only Speed and Relax modes for editing
        $builder->add('mode', EnumType::class, [
            'class' => PuzzleAddMode::class,
            'label' => false,
            'choice_filter' => fn (PuzzleAddMode $mode): bool => $mode !== PuzzleAddMode::Collection,
            'attr' => [
                'class' => 'd-none',
            ],
        ]);

        // Brand + puzzle: only whoever tracked the result may move it to another puzzle, and only to one that exists
        // (docs/features/duplicate-results.md, Layer 4). For everybody else both stay disabled - a disabled field
        // keeps the result's own puzzle whatever is submitted
        /** @var bool $canChangePuzzle */
        $canChangePuzzle = $options['can_change_puzzle'];

        $builder->add('brand', TextType::class, [
            'label' => 'forms.brand',
            'required' => true,
            'disabled' => $canChangePuzzle === false,
            'autocomplete' => true,
            'options_as_html' => true,
            'empty_data' => '',
            'tom_select_options' => [
                'create' => false,
                'persist' => false,
                'maxItems' => 1,
                'options' => $brandChoices,
                'closeAfterSelect' => true,
                'createOnBlur' => false,
                // Never `text`: that one is markup, so typing "img" or a puzzle count matched brands
                'searchField' => ['name', 'eanPrefix'],
            ],
            'attr' => [
                'data-fetch-url' => $this->urlGenerator->generate('puzzle_by_brand_autocomplete'),
            ],
        ]);

        $builder->add('competition', TextType::class, [
            'label' => 'forms.competition',
            'help' => 'forms.competition_help',
            'required' => false,
            'autocomplete' => true,
            'options_as_html' => true,
            'tom_select_options' => [
                'create' => false,
                'persist' => false,
                'maxItems' => 1,
                'options' => $competitionChoices->options,
                'optgroups' => $competitionChoices->optgroups,
                'searchField' => ['text', 'keywords'],
                'closeAfterSelect' => true,
                'createOnBlur' => false,
            ],
        ]);

        $builder->add('firstAttempt', CheckboxType::class, [
            'label' => 'forms.first_attempt',
            'required' => false,
            'help' => 'forms.first_attempt_help',
        ]);

        $builder->add('unboxed', CheckboxType::class, [
            'label' => 'forms.unboxed',
            'required' => false,
            'help' => 'forms.unboxed_help',
        ]);

        $builder->add('puzzle', TextType::class, [
            'label' => 'forms.puzzle',
            'help' => 'edit_time_puzzle.picker_help',
            'required' => true,
            'disabled' => $canChangePuzzle === false,
            'autocomplete' => true,
            'options_as_html' => true,
            'tom_select_options' => [
                'create' => false,
                'persist' => false,
                'maxItems' => 1,
                'closeAfterSelect' => true,
                'createOnBlur' => false,
                'searchField' => ['search'],
            ],
            'attr' => [
                'data-choose-brand-placeholder' => $this->translator->trans('forms.puzzle_choose_brand_placeholder'),
                'data-choose-puzzle-placeholder' => $this->translator->trans('forms.puzzle_choose_placeholder'),
            ],
        ]);

        // Time as separate inputs
        $builder->add('timeHours', NumberType::class, [
            'label' => 'forms.time_hours',
            'required' => false,
            'html5' => true,
            'empty_data' => '0',
            'attr' => [
                'min' => 0,
                'max' => 99,
                'class' => 'form-control text-center time-input',
                'inputmode' => 'numeric',
                'onfocus' => 'setTimeout(() => this.select(), 100)',
            ],
        ]);

        $builder->add('timeMinutes', NumberType::class, [
            'label' => 'forms.time_minutes',
            'required' => false,
            'html5' => true,
            'empty_data' => '0',
            'attr' => [
                'min' => 0,
                'max' => 59,
                'class' => 'form-control text-center time-input',
                'inputmode' => 'numeric',
                'onfocus' => 'setTimeout(() => this.select(), 100)',
            ],
        ]);

        $builder->add('timeSeconds', NumberType::class, [
            'label' => 'forms.time_seconds',
            'required' => false,
            'html5' => true,
            'empty_data' => '0',
            'attr' => [
                'min' => 0,
                'max' => 59,
                'class' => 'form-control text-center time-input',
                'inputmode' => 'numeric',
                'onfocus' => 'setTimeout(() => this.select(), 100)',
            ],
        ]);

        $builder->add('comment', TextareaType::class, [
            'label' => 'forms.comment',
            'required' => false,
        ]);

        $builder->add('finishedPuzzlesPhoto', FileType::class, [
            'label' => 'forms.finished_puzzle_photo',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '10m',
                    mimeTypes: [
                        'image/jpeg',
                        'image/png',
                        'image/gif',
                        'image/webp',
                        'image/heic',
                        'image/heif',
                        'image/avif',
                    ],
                    mimeTypesMessage: 'image_invalid_mime_type'
                ),
            ],
        ]);

        $builder->add('finishedAt', DateType::class, [
            'label' => 'forms.date_finished',
            'required' => false,
            'widget' => 'single_text',
            'format' => 'dd.MM.yyyy',
            'html5' => false,
            'input' => 'datetime_immutable',
            'input_format' => 'd.m.Y',
        ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($competitionChoices): void {
            $form = $event->getForm();
            $data = $event->getData();
            assert($data instanceof EditPuzzleSolvingTimeFormData);

            $this->applyDynamicRules($form, $data, $competitionChoices);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditPuzzleSolvingTimeFormData::class,
            // The competition the edited time is currently linked to (server-derived by the controller,
            // never from the request) — the picker always offers it, see CompetitionChoicesBuilder
            'current_competition_id' => null,
            // Only whoever tracked the result (EditTimeController)
            'can_change_puzzle' => false,
        ]);

        $resolver->setAllowedTypes('current_competition_id', ['null', 'string']);
        $resolver->setAllowedTypes('can_change_puzzle', 'bool');
    }

    /**
     * @param FormInterface<EditPuzzleSolvingTimeFormData> $form
     */
    private function applyDynamicRules(
        FormInterface $form,
        EditPuzzleSolvingTimeFormData $data,
        CompetitionChoices $competitionChoices,
    ): void {
        // Time is required only for Speed Puzzling mode
        if ($data->mode === PuzzleAddMode::SpeedPuzzling && $data->hasTime() === false) {
            $form->get('timeMinutes')->addError(new FormError($this->translator->trans('forms.time_required')));
        }

        // Existing puzzles only - no new puzzle from the edit form (EditTimeController checks that it exists)
        if ($data->puzzle === null || Uuid::isValid($data->puzzle) === false) {
            $form->get('puzzle')->addError(new FormError($this->translator->trans('edit_time_puzzle.choose_from_list')));
        }

        // Competition: only an id the picker offered — selectable OR the currently linked one
        if ($data->competition !== null && $competitionChoices->contains($data->competition) === false) {
            $form->get('competition')->addError(new FormError($this->translator->trans('forms.competition_not_selectable')));
        }
    }
}
