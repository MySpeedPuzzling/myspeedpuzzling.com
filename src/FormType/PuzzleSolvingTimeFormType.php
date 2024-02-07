<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\PuzzleSolvingTimeFormData;
use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Query\GetPuzzlesOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<PuzzleSolvingTimeFormData>
 */
final class PuzzleSolvingTimeFormType extends AbstractType
{
    public function __construct(
        readonly private GetPuzzlesOverview $getPuzzlesOverview,
        readonly private GetManufacturers $getManufacturers,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $puzzleChoices = [];

        $userProfile = $this->retrieveLoggedUserProfile->getProfile();
        // Must not be null - solving time is allowed only to logged-in users
        assert($userProfile !== null);

        foreach ($this->getPuzzlesOverview->allApprovedOrAddedByPlayer($userProfile->playerId) as $puzzle) {
            $puzzleChoices[$puzzle->puzzleId] = $puzzle->puzzleId;
        }

        $builder->add('puzzleId', ChoiceType::class, [
            'label' => 'Puzzle',
            'required' => false,
            'expanded' => true,
            'multiple' => false,
            'choices' => $puzzleChoices,
            'choice_translation_domain' => false,
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
                ),
            ],
        ]);

        $builder->add('addPuzzle', CheckboxType::class, [
            'label' => 'forms.add_new_puzzle',
            'required' => false,
        ]);

        $builder->add('puzzleName', TextType::class, [
            'label' => 'forms.puzzle_name',
            'label_attr' => ['class' => 'required'],
            'required' => false,
        ]);

        $manufacturerChoices = ['-' => ''];
        foreach ($this->getManufacturers->onlyApprovedOrAddedByPlayer($userProfile->playerId) as $manufacturer) {
            $manufacturerChoices[$manufacturer->manufacturerName] = $manufacturer->manufacturerId;
        }

        $builder->add('puzzleManufacturerId', ChoiceType::class, [
            'label' => 'forms.manufacturer',
            'required' => false,
            'expanded' => false,
            'multiple' => false,
            'choices' => $manufacturerChoices,
            'attr' => ['data-manufacturerId' => true],
            'label_attr' => ['class' => 'required'],
            'choice_translation_domain' => false,
        ]);

        $builder->add('addManufacturer', CheckboxType::class, [
            'label' => 'forms.add_new_manufacturer',
            'required' => false,
        ]);

        $builder->add('puzzleManufacturerName', TextType::class, [
            'label' => 'forms.manufacturer_name',
            'label_attr' => ['class' => 'required'],
            'required' => false,
        ]);

        $builder->add('puzzlePiecesCount', NumberType::class, [
            'label' => 'forms.pieces_count',
            'label_attr' => ['class' => 'required'],
            'required' => false,
        ]);

        $builder->add('puzzlePhoto', FileType::class, [
            'label' => 'forms.puzzle_box_photo',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '10m',
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
            assert($data instanceof PuzzleSolvingTimeFormData);

            $this->applyDynamicRules($form, $data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PuzzleSolvingTimeFormData::class,
        ]);
    }

    /**
     * @param FormInterface<PuzzleSolvingTimeFormData> $form
     */
    private function applyDynamicRules(
        FormInterface $form,
        PuzzleSolvingTimeFormData $data,
    ): void {

        if ($data->addPuzzle === false && $data->puzzleId === null) {
            $form->get('puzzleId')->addError(new FormError($this->translator->trans('forms.choose_or_add_puzzle')));
        }

        if ($data->addPuzzle === true) {
            if ($data->puzzleName === null) {
                $form->get('puzzleName')->addError(new FormError($this->translator->trans('forms.required_field')));
            }

            if ($data->puzzlePiecesCount === null) {
                $form->get('puzzlePiecesCount')->addError(new FormError($this->translator->trans('forms.required_field')));
            }

            if ($data->addManufacturer === true) {
                if ($data->puzzleManufacturerName === null || $data->puzzleManufacturerName === '') {
                    $form->get('puzzleManufacturerName')->addError(new FormError($this->translator->trans('forms.add_or_choose_manufacturer')));
                }
            } elseif ($data->puzzleManufacturerId === null || $data->puzzleManufacturerId === '') {
                $form->get('puzzleManufacturerId')->addError(new FormError($this->translator->trans('forms.choose_or_add_manufacturer')));
            }
        }
    }
}
