<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\MessagingSettingsFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MessagingSettingsFormData::class,
        ]);
    }
}
