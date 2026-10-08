<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\MoveRoundFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<MoveRoundFormData>
 */
final class MoveRoundFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('competitionId', ChoiceType::class, [
            'label' => 'restructure.move_round.competition',
            'choices' => $options['competition_choices'],
            'placeholder' => 'restructure.move_round.competition_placeholder',
            'choice_translation_domain' => false,
            // Admins choose among every event - searchable
            'autocomplete' => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MoveRoundFormData::class,
            'competition_choices' => [],
        ]);
        $resolver->setAllowedTypes('competition_choices', 'array');
    }
}
