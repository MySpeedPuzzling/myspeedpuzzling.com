<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\Entity\CompetitionAdvancementStep;
use SpeedPuzzling\Web\FormData\AdvancementStepFormData;
use SpeedPuzzling\Web\Value\AdvanceDistribution;
use SpeedPuzzling\Web\Value\AdvancementQualifyScope;
use SpeedPuzzling\Web\Value\AdvancementStepSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Only the event's own rounds are offered (option `rounds`: id => label) - any other id is an invalid choice. The
 * by-source map has one row per round of the event; rows of rounds that are not sources of the step are ignored.
 *
 * @extends AbstractType<AdvancementStepFormData>
 */
final class AdvancementStepFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $roundLabels */
        $roundLabels = $options['rounds'];
        $roundIds = array_map('strval', array_keys($roundLabels));
        $roundChoices = [
            'choices' => array_combine($roundIds, $roundIds),
            'choice_label' => static fn (string $roundId): string => $roundLabels[$roundId] ?? $roundId,
            'choice_translation_domain' => false,
        ];

        $builder->add('name', TextType::class, [
            'required' => false,
            'label' => 'advancement_step.form.name',
            'help' => 'advancement_step.form.name_help',
            'attr' => ['maxlength' => CompetitionAdvancementStep::NAME_MAX_LENGTH],
        ]);

        $builder->add('sourceRoundIds', ChoiceType::class, $roundChoices + [
            'multiple' => true,
            'expanded' => true,
            'label' => 'advancement_step.form.sources',
        ]);

        $builder->add('targetRoundIds', ChoiceType::class, $roundChoices + [
            'multiple' => true,
            'expanded' => true,
            'label' => 'advancement_step.form.targets',
        ]);

        $builder->add('qualifyCount', IntegerType::class, [
            'required' => false,
            'label' => 'advancement_step.form.qualify_count',
            'help' => 'advancement_step.form.qualify_count_help',
            'attr' => ['min' => 1, 'max' => AdvancementStepSettings::QUALIFY_MAX, 'inputmode' => 'numeric'],
        ]);

        $builder->add('qualifyScope', EnumType::class, [
            'class' => AdvancementQualifyScope::class,
            'expanded' => true,
            'label' => 'advancement_step.form.qualify_scope',
            'choice_label' => static fn (AdvancementQualifyScope $scope): string => 'advancement_step.scope.' . $scope->value,
        ]);

        $builder->add('bestOfEachCountry', IntegerType::class, [
            'required' => false,
            'label' => 'advancement_step.form.country',
            'help' => 'advancement_step.form.country_help',
            'attr' => ['min' => 1, 'max' => AdvancementStepSettings::COUNTRY_MAX, 'inputmode' => 'numeric'],
        ]);

        $builder->add('distribution', EnumType::class, [
            'class' => AdvanceDistribution::class,
            'expanded' => true,
            'label' => 'advancement_step.form.distribution',
            'choice_label' => static fn (AdvanceDistribution $distribution): string => 'results_desk.advance.distribution.' . $distribution->value,
        ]);

        $map = $builder->create('targetBySource', FormType::class, [
            'label' => 'advancement_step.form.map',
            'help' => 'advancement_step.form.map_help',
            'required' => false,
        ]);

        foreach ($roundIds as $roundId) {
            $map->add($roundId, ChoiceType::class, $roundChoices + [
                'required' => false,
                'label' => $roundLabels[$roundId],
                'translation_domain' => false,
                // Translated by the caller (AdvancementStepForms) - the row's label is a round name, never translated
                'placeholder' => $options['map_placeholder'],
            ]);
        }

        $builder->add($map);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdvancementStepFormData::class,
        ]);
        $resolver->setRequired(['rounds', 'map_placeholder']);
        $resolver->setAllowedTypes('rounds', 'array');
        $resolver->setAllowedTypes('map_placeholder', 'string');
    }
}
