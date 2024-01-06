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

/**
 * @extends AbstractType<PuzzleSolvingTimeFormData>
 */
final class PuzzleSolvingTimeFormType extends AbstractType
{
    public function __construct(
        readonly private GetPuzzlesOverview $getPuzzlesOverview,
        readonly private GetManufacturers $getManufacturers,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
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
        ]);

        $builder->add('time', TextType::class, [
            'label' => 'Čas',
            'required' => true,
            'attr' => [
                'placeholder' => 'HH:MM:SS',
            ],
        ]);

        $builder->add('comment', TextareaType::class, [
            'label' => 'Doplňující info',
            'required' => false,
        ]);

        $builder->add('finishedPuzzlesPhoto', FileType::class, [
            'label' => 'Foto poskládaných puzzlí',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '10m',
                ),
            ],
        ]);

        $builder->add('addPuzzle', CheckboxType::class, [
            'label' => 'Přidat nové puzzle - nejsou v seznamu',
            'required' => false,
        ]);

        $builder->add('puzzleName', TextType::class, [
            'label' => 'Název puzzlí',
            'label_attr' => ['class' => 'required'],
            'required' => false,
        ]);

        $manufacturerChoices = ['-' => ''];
        foreach ($this->getManufacturers->onlyApprovedOrAddedByPlayer($userProfile->playerId) as $manufacturer) {
            $manufacturerChoices[$manufacturer->manufacturerName] = $manufacturer->manufacturerId;
        }

        $builder->add('puzzleManufacturerId', ChoiceType::class, [
            'label' => 'Výrobce',
            'required' => false,
            'expanded' => false,
            'multiple' => false,
            'choices' => $manufacturerChoices,
            'attr' => ['data-manufacturerId' => true],
            'label_attr' => ['class' => 'required'],
        ]);

        $builder->add('addManufacturer', CheckboxType::class, [
            'label' => 'Přidat nového výrobce - není v seznamu',
            'required' => false,
        ]);

        $builder->add('puzzleManufacturerName', TextType::class, [
            'label' => 'Výrobce (není v seznamu)',
            'label_attr' => ['class' => 'required'],
            'required' => false,
        ]);

        $builder->add('puzzlePiecesCount', NumberType::class, [
            'label' => 'Počet dílků',
            'label_attr' => ['class' => 'required'],
            'required' => false,
        ]);

        $builder->add('puzzlePhoto', FileType::class, [
            'label' => 'Foto motivu nebo krabice od puzzlí',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '10m',
                ),
            ],
        ]);

        $builder->add('finishedAt', DateType::class, [
            'label' => 'Datum doskládání',
            'required' => false,
            'widget' => 'single_text',
            'format' => 'dd.MM.yyyy',
            'html5' => false,
            'input' => 'datetime_immutable',
            'input_format' => 'd.m.Y',
        ]);

        $builder->add('puzzleEan', TextType::class, [
            'label' => 'EAN kód',
            'required' => false,
        ]);

        $builder->add('puzzleIdentificationNumber', TextType::class, [
            'label' => 'Kód od výrobce',
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
            $form->get('puzzleId')->addError(new FormError('Pro přidání času vyberte puzzle ze seznamu nebo prosím vypište informace o puzzlích'));
        }

        if ($data->addPuzzle === true) {
            if ($data->puzzleName === null) {
                $form->get('puzzleName')->addError(new FormError('Toto pole je povinné.'));
            }

            if ($data->puzzlePiecesCount === null) {
                $form->get('puzzlePiecesCount')->addError(new FormError('Toto pole je povinné.'));
            }

            if ($data->addManufacturer === true) {
                if ($data->puzzleManufacturerName === null || $data->puzzleManufacturerName === '') {
                    $form->get('puzzleManufacturerName')->addError(new FormError('Zadejte výrobce nebo vyberte ze seznamu existujících.'));
                }
            } elseif ($data->puzzleManufacturerId === null || $data->puzzleManufacturerId === '') {
                $form->get('puzzleManufacturerId')->addError(new FormError('Vyberte výrobce ze seznamu nebo přidejte nového.'));
            }
        }
    }
}
