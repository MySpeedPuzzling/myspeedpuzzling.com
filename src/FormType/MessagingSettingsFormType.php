<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\MessagingSettingsFormData;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;
use SpeedPuzzling\Web\Value\EmailNotificationFrequency;
use SpeedPuzzling\Web\Value\FeatureRequestEmails;
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

        $builder->add('resultEmailsEnabled', CheckboxType::class, [
            'label' => 'edit_profile.result_emails_enabled',
            'help' => 'edit_profile.result_emails_help',
            'required' => false,
        ]);

        // docs/features/partner-finder.md D6 - e-mails about "I'd like to puzzle with you" on the player's listings
        $builder->add('partnerFinderEmails', CheckboxType::class, [
            'label' => 'partner_finder.settings.emails_label',
            'help' => 'partner_finder.settings.emails_help',
            'required' => false,
        ]);

        $builder->add('featureRequestEmails', EnumType::class, [
            'class' => FeatureRequestEmails::class,
            'label' => 'edit_profile.feature_request_emails',
            'help' => 'edit_profile.feature_request_emails_help',
            'expanded' => true,
            'choice_label' => static fn (FeatureRequestEmails $choice): string => $choice->translationKey(),
            // The list page links here (#feature-request-emails)
            'row_attr' => ['id' => 'feature-request-emails'],
        ]);

        // The activity digest e-mail - shown once the XP launch switch is on (XpFeatureGate), admins before
        if ($options['show_content_digest'] === true) {
            $builder->add('contentDigestFrequency', EnumType::class, [
                'class' => ContentDigestFrequency::class,
                'label' => 'activity_digest.settings.label',
                'help' => 'activity_digest.settings.help',
                'choice_label' => static fn (ContentDigestFrequency $frequency): string => $frequency->translationKey(),
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MessagingSettingsFormData::class,
            'show_content_digest' => false,
        ]);

        $resolver->setAllowedTypes('show_content_digest', 'bool');
    }
}
