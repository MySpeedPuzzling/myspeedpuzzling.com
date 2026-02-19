<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\MessagingSettingsFormData;
use SpeedPuzzling\Web\Value\EmailNotificationFrequency;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<MessagingSettingsFormData>
 */
final class MessagingSettingsFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('allowDirectMessages', CheckboxType::class, [
            'label' => 'edit_profile.allow_direct_messages',
            'required' => false,
        ]);

        $builder->add('emailNotificationsEnabled', CheckboxType::class, [
            'label' => 'edit_profile.email_notifications',
            'required' => false,
        ]);

        $builder->add('emailNotificationFrequency', EnumType::class, [
            'class' => EmailNotificationFrequency::class,
            'label' => 'edit_profile.email_notification_frequency',
            'help' => 'edit_profile.email_frequency_help',
            'choice_label' => static fn (EmailNotificationFrequency $frequency): string => match ($frequency) {
                EmailNotificationFrequency::SixHours => 'edit_profile.frequency_6_hours',
                EmailNotificationFrequency::TwelveHours => 'edit_profile.frequency_12_hours',
                EmailNotificationFrequency::TwentyFourHours => 'edit_profile.frequency_24_hours',
                EmailNotificationFrequency::FortyEightHours => 'edit_profile.frequency_48_hours',
                EmailNotificationFrequency::OneWeek => 'edit_profile.frequency_1_week',
            },
        ]);

        $builder->add('newsletterEnabled', CheckboxType::class, [
            'label' => 'edit_profile.newsletter_enabled',
            'help' => 'edit_profile.newsletter_help',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MessagingSettingsFormData::class,
        ]);
    }
}
