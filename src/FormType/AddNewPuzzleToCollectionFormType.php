<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\AddNewPuzzleToCollectionFormData;
use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Component\Form\AbstractType;
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
 * @extends AbstractType<AddNewPuzzleToCollectionFormData>
 */
final class AddNewPuzzleToCollectionFormType extends AbstractType
{
    public function __construct(
        readonly private GetManufacturers $getManufacturers,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        readonly private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $userProfile = $this->retrieveLoggedUserProfile->getProfile();
        assert($userProfile !== null);

        $brandChoices = [];
        foreach ($this->getManufacturers->onlyApprovedOrAddedByPlayer($userProfile->playerId, null) as $manufacturer) {
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

        /** @var array<string, null|string> $collections */
        $collections = $options['collections'] ?? [];

        $collectionChoices = [];
        foreach ($collections as $name => $collectionId) {
            $collectionChoices[] = [
                'value' => $collectionId,
                'text' => $name,
            ];
        }

        /** @var bool $allowCreateCollection */
        $allowCreateCollection = $options['allow_create_collection'] ?? true;

        $builder->add('collection', TextType::class, [
            'label' => 'forms.add_puzzle_to_collection.collection',
            'help' => 'forms.add_puzzle_to_collection.collection_help',
            'required' => true,
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
            'required' => true,
            'choice_label' => fn(CollectionVisibility $visibility) => match ($visibility) {
                CollectionVisibility::Private => 'form.visibility_private',
                CollectionVisibility::Public => 'form.visibility_public',
            },
        ]);

        $builder->add('comment', TextareaType::class, [
            'label' => 'forms.add_puzzle_to_collection.comment',
            'required' => false,
            'help' => 'forms.max_characters',
            'attr' => [
                'rows' => 3,
                'maxlength' => 500,
                'placeholder' => 'forms.add_puzzle_to_collection.comment_placeholder',
            ],
        ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $data = $event->getData();
            assert($data instanceof AddNewPuzzleToCollectionFormData);

            $this->applyDynamicRules($form, $data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AddNewPuzzleToCollectionFormData::class,
            'collections' => [],
            'allow_create_collection' => true,
        ]);

        $resolver->setAllowedTypes('collections', 'array');
        $resolver->setAllowedTypes('allow_create_collection', 'bool');
    }

    /**
     * @param FormInterface<AddNewPuzzleToCollectionFormData> $form
     */
    private function applyDynamicRules(
        FormInterface $form,
        AddNewPuzzleToCollectionFormData $data,
    ): void {
        if (is_string($data->puzzle) && Uuid::isValid($data->puzzle) === false) {
            if ($data->puzzlePiecesCount === null) {
                $form->get('puzzlePiecesCount')->addError(new FormError($this->translator->trans('forms.required_field')));
            }

            if ($data->puzzlePhoto === null) {
                $form->get('puzzlePhoto')->addError(new FormError($this->translator->trans('forms.puzzle_photo_is_required')));
            }
        }
    }
}
