<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\AddCompetitionTeamsFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AddCompetitionTeamsFormData>
 */
final class AddCompetitionTeamsFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('teamNames', TextareaType::class, [
            'label' => 'competition.teams.team_names',
            'help' => 'competition.teams.team_names_help',
            'required' => false,
            'attr' => [
                'rows' => 4,
                'placeholder' => 'competition.teams.team_names_placeholder',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AddCompetitionTeamsFormData::class,
        ]);
    }
}
