<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\AddPuzzleSolvingTimeFormData;
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
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

/**
 * @extends AbstractType<AddPuzzleSolvingTimeFormData>
 */
final class AddPuzzleSolvingTimeFormType extends AbstractType
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
            'label' => 'Zadat puzzle ručně - neznám výrobce nebo nejsou v seznamu',
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
        ]);

        $builder->add('puzzleManufacturerName', TextType::class, [
            'label' => 'Výrobce (pokud není na seznamu)',
            'label_attr' => ['class' => 'required'],
            'required' => false,
            'help' => 'Prosím vyplňte pouze v případě, že jste výrobce nenašli v již existujícím seznamu',
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AddPuzzleSolvingTimeFormData::class,
        ]);
    }
}
