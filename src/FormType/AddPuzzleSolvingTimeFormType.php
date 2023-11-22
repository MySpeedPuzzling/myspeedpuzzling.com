<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\AddPuzzleSolvingTimeFormData;
use SpeedPuzzling\Web\Query\GetManufacturers;
use SpeedPuzzling\Web\Query\GetPuzzlesOverview;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Image;

/**
 * @extends AbstractType<AddPuzzleSolvingTimeFormData>
 */
final class AddPuzzleSolvingTimeFormType extends AbstractType
{
    public function __construct(
        readonly private GetPuzzlesOverview $getPuzzlesOverview,
        readonly private GetManufacturers $getManufacturers,
    ) {
    }

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $puzzleChoices = [];
        foreach ($this->getPuzzlesOverview->all() as $puzzle) {
            $puzzleChoices[$puzzle->puzzleName] = $puzzle->puzzleId;
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

        $builder->add('playersCount', ChoiceType::class, [
            'label' => 'Počet skládájících lidí',
            'required' => true,
            'choices' => [
                '1 - Jen já' => 1,
                '2 - Pár' => 2,
                '3 a více - Pořádná skupinka puzzlařů!' => 3,
            ],
        ]);

        $builder->add('comment', TextareaType::class, [
            'label' => 'Doplňující info',
            'required' => false,
            'help' => 'Pokud skládáte ve více lidech, prosím uveďte jména',
        ]);

        $builder->add('solvedPuzzlesPhoto', FileType::class, [
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
            'required' => false,
        ]);

        $manufacturerChoices = ['-' => ''];
        foreach ($this->getManufacturers->onlyApproved() as $manufacturer) {
            $manufacturerChoices[$manufacturer->manufacturerName] = $manufacturer->manufacturerId;
        }

        $builder->add('puzzleManufacturerId', ChoiceType::class, [
            'label' => 'Výrobce',
            'required' => false,
            'expanded' => false,
            'multiple' => false,
            'choices' => $manufacturerChoices,
        ]);

        $builder->add('puzzleManufacturerName', TextType::class, [
            'label' => 'Výrobce (pokud není na seznamu)',
            'required' => false,
            'help' => 'Prosím vyplňte pouze v případě, že jste výrobce nenašli v již existujícím seznamu',
        ]);

        $builder->add('puzzlePiecesCount', TextType::class, [
            'label' => 'Počet dílků',
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
