<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\PuzzleAddFormData;
use SpeedPuzzling\Web\Services\BrandChoicesBuilder;
use SpeedPuzzling\Web\Services\CompetitionChoicesBuilder;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CollectionVisibility;
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
 * @extends AbstractType<PuzzleAddFormData>
 */
final class PuzzleAddFormType extends AbstractType
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

        /** @var null|string $currentCompetitionId */
        $currentCompetitionId = $options['current_competition_id'] ?? null;
        $competitionChoices = $this->competitionChoicesBuilder->build($currentCompetitionId);

        // Mode field (hidden, controlled by JS)
        $builder->add('mode', EnumType::class, [
            'class' => PuzzleAddMode::class,
            'label' => false,
            'attr' => [
                'class' => 'd-none',
            ],
        ]);

        // Puzzle selection fields (all modes)
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
                'searchField' => ['text', 'eanPrefix'],
            ],
            'attr' => [
                'data-fetch-url' => $this->urlGenerator->generate('puzzle_by_brand_autocomplete'),
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
            ],
            'attr' => [
                'data-choose-brand-placeholder' => $this->translator->trans('forms.puzzle_choose_brand_placeholder'),
                'data-choose-puzzle-placeholder' => $this->translator->trans('forms.puzzle_choose_placeholder'),
            ],
        ]);

        // New puzzle fields
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

        $builder->add('puzzleEan', TextType::class, [
            'label' => 'forms.ean',
            'required' => false,
        ]);

        $builder->add('puzzleIdentificationNumber', TextType::class, [
            'label' => 'forms.puzzle_identification_number',
            'required' => false,
        ]);

        // Speed puzzling specific fields - time as separate inputs
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

        // Common fields (Speed & Relax)
        $builder->add('finishedAt', DateType::class, [
            'label' => 'forms.date_finished',
            'required' => false,
            'widget' => 'single_text',
            'format' => 'dd.MM.yyyy',
            'html5' => false,
            'input' => 'datetime_immutable',
            'input_format' => 'd.m.Y',
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

        // Collection specific fields
        /** @var array<string, string> $collections */
        $collections = $options['collections'] ?? [];

        /** @var bool $hasActiveMembership */
        $hasActiveMembership = $options['has_active_membership'] ?? true;

        $collectionChoices = [];
        foreach ($collections as $name => $collectionId) {
            $collectionChoices[] = [
                'value' => $collectionId,
                'text' => $name,
            ];
        }

        // Non-members cannot create new collections
        $allowCreateCollection = $hasActiveMembership;

        $builder->add('collection', TextType::class, [
            'label' => 'forms.add_puzzle_to_collection.collection',
            'help' => 'forms.add_puzzle_to_collection.collection_help',
            'required' => false,
            'autocomplete' => true,
            'tom_select_options' => [
                'create' => $allowCreateCollection,
                'persist' => false,
                'maxItems' => 1,
                'options' => $collectionChoices,
                'closeAfterSelect' => true,
                'createOnBlur' => $allowCreateCollection,
            ],
            'attr' => [
                'class' => 'form-control',
                'maxlength' => 100,
            ],
        ]);

        $builder->add('collectionDescription', TextareaType::class, [
            'label' => 'collections.form.description',
            'required' => false,
            'help' => 'forms.max_characters',
            'attr' => [
                'class' => 'form-control',
                'rows' => 2,
            ],
        ]);

        $builder->add('collectionVisibility', EnumType::class, [
            'class' => CollectionVisibility::class,
            'label' => 'form.visibility',
            'required' => false,
            'empty_data' => CollectionVisibility::Private->value,
            'choice_label' => fn(CollectionVisibility $visibility) => match ($visibility) {
                CollectionVisibility::Private => 'form.visibility_private',
                CollectionVisibility::Public => 'form.visibility_public',
            },
        ]);

        $builder->add('collectionComment', TextareaType::class, [
            'label' => 'forms.add_puzzle_to_collection.comment',
            'required' => false,
            'help' => 'forms.max_characters',
            'attr' => [
                'rows' => 3,
                'maxlength' => 500,
                'placeholder' => 'forms.add_puzzle_to_collection.comment_placeholder',
            ],
        ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($competitionChoices): void {
            $form = $event->getForm();
            $data = $event->getData();
            assert($data instanceof PuzzleAddFormData);

            $this->applyDynamicRules($form, $data, $competitionChoices);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PuzzleAddFormData::class,
            'active_puzzle' => null,
            'collections' => [],
            'has_active_membership' => true,
            // The competition the edited time is linked to — always offered by the picker (edit form);
            // symmetric with EditPuzzleSolvingTimeFormType, unused by the add form so far
            'current_competition_id' => null,
        ]);

        $resolver->setAllowedTypes('collections', 'array');
        $resolver->setAllowedTypes('has_active_membership', 'bool');
        $resolver->setAllowedTypes('current_competition_id', ['null', 'string']);
    }

    /**
     * @param FormInterface<PuzzleAddFormData> $form
     */
    private function applyDynamicRules(
        FormInterface $form,
        PuzzleAddFormData $data,
        CompetitionChoices $competitionChoices,
    ): void {
        $mode = $data->mode;

        // Puzzle is required in all modes - either an existing puzzle (UUID) or a new puzzle name.
        // The field can be missing from the request entirely (e.g. disabled input on the client),
        // so this must be validated server-side to avoid dispatching a message with null puzzle.
        $puzzleIsBlank = $data->puzzle === null || trim($data->puzzle) === '';
        if ($puzzleIsBlank) {
            $form->get('puzzle')->addError(new FormError($this->translator->trans('forms.required_field')));
        }

        // New puzzle validation (all modes) - skipped for blank puzzle so the required
        // error above is not drowned in pieces-count/photo errors for a puzzle never entered
        if ($puzzleIsBlank === false && Uuid::isValid($data->puzzle) === false) {
            if ($data->puzzlePiecesCount === null) {
                $form->get('puzzlePiecesCount')->addError(new FormError($this->translator->trans('forms.required_field')));
            }

            // Collection: only puzzlePhoto required (no fallback to finishedPuzzlesPhoto)
            // Speed/Relax: puzzlePhoto OR finishedPuzzlesPhoto
            if ($mode === PuzzleAddMode::Collection) {
                if ($data->puzzlePhoto === null) {
                    $form->get('puzzlePhoto')->addError(new FormError($this->translator->trans('forms.puzzle_photo_is_required')));
                }
            } else {
                if ($data->puzzlePhoto === null && $data->finishedPuzzlesPhoto === null) {
                    $form->get('puzzlePhoto')->addError(new FormError($this->translator->trans('forms.puzzle_photo_is_required')));
                }
            }
        }

        // Speed Puzzling: time required
        if ($mode === PuzzleAddMode::SpeedPuzzling && $data->hasTime() === false) {
            $form->get('timeMinutes')->addError(new FormError($this->translator->trans('forms.time_required')));
        }

        // Collection: collection required
        if ($mode === PuzzleAddMode::Collection && empty($data->collection)) {
            $form->get('collection')->addError(new FormError($this->translator->trans('forms.required_field')));
        }

        // Competition: only an id the picker offered (TomSelect can't create, but the value is user-controlled)
        if ($data->competition !== null && $competitionChoices->contains($data->competition) === false) {
            $form->get('competition')->addError(new FormError($this->translator->trans('forms.competition_not_selectable')));
        }
    }
}
