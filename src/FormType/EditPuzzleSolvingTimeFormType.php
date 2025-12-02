<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\EditPuzzleSolvingTimeFormData;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
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
        readonly private GetManufacturers $getManufacturers,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        readonly private UrlGeneratorInterface $urlGenerator,
        readonly private GetCompetitionEvents $getCompetitionEvents,
        readonly private CacheManager $cacheManager,
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

        $brandChoices = [];
        foreach ($this->getManufacturers->onlyApprovedOrAddedByPlayer($userProfile->playerId, $extraManufacturerId) as $manufacturer) {
            $brandChoices[] = [
                'value' => $manufacturer->manufacturerId,
                'text' => "{$manufacturer->manufacturerName} ({$manufacturer->puzzlesCount})",
            ];
        }

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
            'empty_data' => '',
            'tom_select_options' => [
                'create' => true,
                'persist' => false,
                'maxItems' => 1,
                'options' => $brandChoices,
                'closeAfterSelect' => true,
                'createOnBlur' => true,
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
                'options' => $this->getCompetitionsAutocompleteData(),
                'closeAfterSelect' => true,
                'createOnBlur' => false,
            ],
        ]);

        $builder->add('firstAttempt', CheckboxType::class, [
            'label' => 'forms.first_attempt',
            'required' => false,
            'help' => 'forms.first_attempt_help',
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

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $data = $event->getData();
            assert($data instanceof EditPuzzleSolvingTimeFormData);

            $this->applyDynamicRules($form, $data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditPuzzleSolvingTimeFormData::class,
            'active_puzzle' => null,
        ]);
    }

    /**
     * @param FormInterface<EditPuzzleSolvingTimeFormData> $form
     */
    private function applyDynamicRules(
        FormInterface $form,
        EditPuzzleSolvingTimeFormData $data,
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
    }

    /**
     * @return array<array{value: string, text: string}>
     */
    public function getCompetitionsAutocompleteData(): array
    {
        $events = [];
        $results = [];

        array_push($events, ...$this->getCompetitionEvents->allLive());
        array_push($events, ...$this->getCompetitionEvents->allPast());

        foreach ($events as $competition) {
            $img = '';

            if ($competition->logo !== null) {
                $img = <<<HTML
<img alt="Logo image" class="img-fluid rounded-2"
    style="max-width: 60px; max-height: 60px;"
    src="{$this->cacheManager->getBrowserPath($competition->logo, 'puzzle_small')}"
/>
HTML;
            }

            $date = $competition->dateFrom->format('d.m.Y');

            if ($competition->dateTo !== null) {
                $date .= ' - ' . $competition->dateTo->format('d.m.Y');
            }

            $location = '';

            if ($competition->locationCountryCode !== null) {
                $location = '<span class="shadow-custom fi fi-' . $competition->locationCountryCode->name . ' me-2"></span>';
            }

            $location .= $competition->location;

            $html = <<<HTML
<div class="py-1 d-flex low-line-height">
    <div class="icon me-2">{$img}</div>
    <div class="pe-1">
        <div class="mb-1">
            <span class="h6">{$competition->name}</span>
            <small class="text-muted">{$date}</small>
        </div>
        <div class="description"><small>{$location}</small></div>
    </div>
</div>
HTML;

            $results[] = [
                'value' => $competition->id,
                'text' => $html,
            ];
        }

        return $results;
    }
}
