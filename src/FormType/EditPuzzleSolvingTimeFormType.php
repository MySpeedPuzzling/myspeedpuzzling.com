<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\EditPuzzleSolvingTimeFormData;
use SpeedPuzzling\Web\Services\BrandChoicesBuilder;
use SpeedPuzzling\Web\Services\CompetitionChoicesBuilder;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
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
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
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
        $userProfile = $this->retrieveLoggedUserProfile->getProfile();
        // Must not be null - solving time is allowed only to logged-in users
        assert($userProfile !== null);

        /** @var null|PuzzleOverview $activePuzzle */
        $activePuzzle = $options['active_puzzle'];

        $extraManufacturerId = $activePuzzle?->manufacturerId;

        $brandChoices = $this->brandChoicesBuilder->build($userProfile->playerId, $extraManufacturerId);

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

        $builder->add('brand', TextType::class, [
            'label' => 'forms.brand',
            'help' => 'forms.brand_help',
            'required' => true,
            'autocomplete' => true,
            'options_as_html' => true,
            'empty_data' => '',
            'tom_select_options' => [
                'create' => true,
                'persist' => false,
                'maxItems' => 1,
                'options' => $brandChoices,
                'closeAfterSelect' => true,
                'createOnBlur' => true,
                'searchField' => ['text', 'eanPrefix'],
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

        $builder->add('puzzlePiecesCount', NumberType::class, [
            'label' => 'forms.pieces_count',
            'label_attr' => ['class' => 'required'],
            'required' => false,
        ]);

        $builder->add('puzzlePhoto', FileType::class, [
            'label' => 'forms.puzzle_box_photo',
            'required' => false,
            'label_attr' => ['class' => 'required'],
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

        $builder->add('puzzleEan', TextType::class, [
            'label' => 'forms.ean',
            'required' => false,
        ]);

        $builder->add('puzzleIdentificationNumber', TextType::class, [
            'label' => 'forms.puzzle_identification_number',
            'required' => false,
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
            'active_puzzle' => null,
            // The competition the edited time is currently linked to (server-derived by the controller,
            // never from the request) — the picker always offers it, see CompetitionChoicesBuilder
            'current_competition_id' => null,
        ]);

        $resolver->setAllowedTypes('current_competition_id', ['null', 'string']);
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

        // TODO: Should check if the puzzle exists in database as well
        if (is_string($data->puzzle) && Uuid::isValid($data->puzzle) === false) {
            if ($data->puzzlePiecesCount === null) {
                $form->get('puzzlePiecesCount')->addError(new FormError($this->translator->trans('forms.required_field')));
            }

            if ($data->puzzlePhoto === null && $data->finishedPuzzlesPhoto === null) {
                $form->get('puzzlePhoto')->addError(new FormError($this->translator->trans('forms.puzzle_photo_is_required')));
            }
        }

        // Competition: only an id the picker offered — selectable OR the currently linked one
        if ($data->competition !== null && $competitionChoices->contains($data->competition) === false) {
            $form->get('competition')->addError(new FormError($this->translator->trans('forms.competition_not_selectable')));
        }
    }
}
