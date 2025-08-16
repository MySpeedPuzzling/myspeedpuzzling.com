<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\PuzzleSolvingTimeFormData;
use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\CheckFirstTryAvailability;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
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
 * @extends AbstractType<PuzzleSolvingTimeFormData>
 */
final class PuzzleSolvingTimeFormType extends AbstractType
{
    public function __construct(
        readonly private GetManufacturers $getManufacturers,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        readonly private UrlGeneratorInterface $urlGenerator,
        readonly private CheckFirstTryAvailability $checkFirstTryAvailability,
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

        $builder->add('time', TextType::class, [
            'label' => 'time',
            'required' => true,
            'attr' => [
                'placeholder' => 'forms.time_format_placeholder',
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

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($userProfile): void {
            $form = $event->getForm();
            $data = $event->getData();
            assert($data instanceof PuzzleSolvingTimeFormData);

            $this->applyDynamicRules($form, $data, $userProfile);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PuzzleSolvingTimeFormData::class,
            'active_puzzle' => null,
        ]);
    }

    /**
     * @param FormInterface<PuzzleSolvingTimeFormData> $form
     */
    private function applyDynamicRules(
        FormInterface $form,
        PuzzleSolvingTimeFormData $data,
        PlayerProfile $playerProfile,
    ): void {
        // TODO: Should check if the puzzle exists in database as well
        if (is_string($data->puzzle) && Uuid::isValid($data->puzzle) === false) {
            if ($data->puzzlePiecesCount === null) {
                $form->get('puzzlePiecesCount')->addError(new FormError($this->translator->trans('forms.required_field')));
            }

            if ($data->puzzlePhoto === null && $data->finishedPuzzlesPhoto === null) {
                $form->get('puzzlePhoto')->addError(new FormError($this->translator->trans('forms.puzzle_photo_is_required')));
            }
        }

        if (is_string($data->puzzle) && Uuid::isValid($data->puzzle) === true && $data->firstAttempt === true) {
            $firstTry = $this->checkFirstTryAvailability->forPlayer($playerProfile->playerId, $data->puzzle);

            if ($firstTry === false) {
                $form->get('firstAttempt')->addError(new FormError($this->translator->trans('Píčo tvůj first attempt už byl tady: ...')));
                $form->addError(new FormError('ASdfda fasdf asdf asdf asdf as'));
            }
        }
    }
}
