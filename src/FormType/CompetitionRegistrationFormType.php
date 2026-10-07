<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\CompetitionRegistrationFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<CompetitionRegistrationFormData>
 */
final class CompetitionRegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('registrationManaged', CheckboxType::class, [
            'label' => 'competition_registration.settings.managed',
            'help' => 'competition_registration.settings.managed_help',
            'required' => false,
        ]);

        $builder->add('capacity', IntegerType::class, [
            'label' => 'competition_registration.settings.capacity',
            'help' => 'competition_registration.settings.capacity_help',
            'required' => false,
            'attr' => ['min' => 1],
        ]);

        foreach (['opensAt' => 'opens_at', 'closesAt' => 'closes_at'] as $field => $key) {
            // Typed like a round's start: day and time in the zone below (CompetitionRoundFormType)
            $builder->add($field, DateTimeType::class, [
                'label' => 'competition_registration.settings.' . $key,
                'help' => 'competition_registration.settings.' . $key . '_help',
                'required' => false,
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd.MM.yyyy HH:mm',
                'input' => 'datetime_immutable',
                'input_format' => 'd.m.Y H:i',
            ]);
        }

        $builder->add('timezone', TimezoneType::class, [
            'label' => 'competition_registration.settings.timezone',
            'help' => 'competition_registration.settings.timezone_help',
            'autocomplete' => true,
        ]);

        $builder->add('entryFeeText', TextType::class, [
            'label' => 'competition_registration.settings.entry_fee',
            'help' => 'competition_registration.settings.entry_fee_help',
            'required' => false,
        ]);

        $builder->add('paymentInstructions', TextareaType::class, [
            'label' => 'competition_registration.settings.payment_instructions',
            'help' => 'competition_registration.settings.payment_instructions_help',
            'required' => false,
            'attr' => ['rows' => 4],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CompetitionRegistrationFormData::class,
        ]);
    }
}
