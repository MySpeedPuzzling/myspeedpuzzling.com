<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\Entity\CompetitionStanding;
use SpeedPuzzling\Web\FormData\CompetitionStandingFormData;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingOrder;
use SpeedPuzzling\Web\Value\StandingRules;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Only the event's own rounds are offered (option `rounds`: id => label) - any other id is an invalid choice. The
 * category of each round is shown in its label; the handler refuses a round of another category.
 *
 * @extends AbstractType<CompetitionStandingFormData>
 */
final class CompetitionStandingFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $roundLabels */
        $roundLabels = $options['rounds'];
        $roundIds = array_map('strval', array_keys($roundLabels));

        $builder->add('name', TextType::class, [
            'label' => 'competition_standing.form.name',
            'help' => 'competition_standing.form.name_help',
            'attr' => ['maxlength' => CompetitionStanding::NAME_MAX_LENGTH],
        ]);

        $builder->add('category', EnumType::class, [
            'class' => RoundCategory::class,
            'expanded' => true,
            'label' => 'competition_standing.form.category',
            'choice_label' => static fn (RoundCategory $category): string => 'competition.round.category.' . $category->value,
        ]);

        $builder->add('roundIds', ChoiceType::class, [
            'choices' => array_combine($roundIds, $roundIds),
            'choice_label' => static fn (string $roundId): string => $roundLabels[$roundId] ?? $roundId,
            'choice_translation_domain' => false,
            'multiple' => true,
            'expanded' => true,
            'label' => 'competition_standing.form.rounds',
            'help' => 'competition_standing.form.rounds_help',
        ]);

        $builder->add('penaltySecondsPerPiece', IntegerType::class, [
            'label' => 'competition_standing.form.penalty',
            'help' => 'competition_standing.form.penalty_help',
            'attr' => ['min' => 0, 'max' => StandingRules::PENALTY_MAX, 'inputmode' => 'numeric'],
        ]);

        $builder->add('penaliseFinishedMissing', CheckboxType::class, [
            'required' => false,
            'label' => 'finished_missing.standing_form.penalise',
            'help' => 'finished_missing.standing_form.penalise_help',
        ]);

        $builder->add('orderRule', EnumType::class, [
            'class' => StandingOrder::class,
            'expanded' => true,
            'label' => 'competition_standing.form.order',
            'choice_label' => static fn (StandingOrder $order): string => 'competition_standing.order.' . $order->value,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CompetitionStandingFormData::class,
        ]);
        $resolver->setRequired('rounds');
        $resolver->setAllowedTypes('rounds', 'array');
    }
}
