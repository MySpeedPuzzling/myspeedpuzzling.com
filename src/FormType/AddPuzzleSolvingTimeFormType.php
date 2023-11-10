<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\AddPuzzleSolvingTimeFormData;
use SpeedPuzzling\Web\Query\GetPuzzlesOverview;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use Symfony\Component\Form\AbstractType;
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
            'required' => true,
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
                    maxSize: '4096k',
                ),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AddPuzzleSolvingTimeFormData::class,
        ]);
    }
}
