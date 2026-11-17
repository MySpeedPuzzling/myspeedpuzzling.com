<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use SpeedPuzzling\Web\FormData\CompetitionDisplaySettingsFormData;
use SpeedPuzzling\Web\Value\DisplayFollowMode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Only the display's own event's rounds are offered (option `rounds`: id => name) - any other id is refused as an
 * invalid choice; the handler checks it once more against the database. Two rounds may share a name, so the choices are
 * the ids, labelled by name.
 *
 * @extends AbstractType<CompetitionDisplaySettingsFormData>
 */
final class CompetitionDisplaySettingsFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $roundNames */
        $roundNames = $options['rounds'];
        $roundIds = array_map('strval', array_keys($roundNames));
        $choices = array_combine($roundIds, $roundIds);
        $roundLabel = static fn (string $roundId): string => $roundNames[$roundId] ?? $roundId;

        $builder->add('name', TextType::class, [
            'label' => 'competition_display.form.name',
            'help' => 'competition_display.form.name_help',
            'attr' => ['maxlength' => CompetitionDisplay::NAME_MAX_LENGTH],
        ]);

        $builder->add('locale', DisplayLocaleType::class, [
            'label' => 'competition_display.form.locale',
            'help' => 'competition_display.form.locale_help',
        ]);

        $builder->add('followMode', EnumType::class, [
            'class' => DisplayFollowMode::class,
            'expanded' => true,
            'label' => 'competition_display.form.follow',
            'choice_label' => static fn (DisplayFollowMode $mode): string => 'competition_display.follow.' . $mode->value,
        ]);

        $builder->add('roundIds', ChoiceType::class, [
            'choices' => $choices,
            'choice_label' => $roundLabel,
            'choice_translation_domain' => false,
            'multiple' => true,
            'expanded' => true,
            'required' => false,
            'label' => 'competition_display.form.round_subset',
            'help' => 'competition_display.form.round_subset_help',
        ]);

        $builder->add('pinnedRoundId', ChoiceType::class, [
            'choices' => $choices,
            'choice_label' => $roundLabel,
            'choice_translation_domain' => false,
            'required' => false,
            'placeholder' => 'competition_display.form.pinned_round_placeholder',
            'label' => 'competition_display.form.pinned_round',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CompetitionDisplaySettingsFormData::class,
        ]);
        $resolver->setRequired('rounds');
        $resolver->setAllowedTypes('rounds', 'array');
    }
}
