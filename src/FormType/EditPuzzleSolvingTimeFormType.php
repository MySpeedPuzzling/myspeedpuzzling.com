<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EditPuzzleSolvingTimeFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<EditPuzzleSolvingTimeFormData>
 */
final class EditPuzzleSolvingTimeFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditPuzzleSolvingTimeFormData::class,
        ]);
    }
}
