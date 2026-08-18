<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\ChangePasswordFormData;
use SpeedPuzzling\Web\Validator\StrongPassword;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ChangePasswordFormData>
 */
final class ChangePasswordFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'edit_profile.change_password_current',
                'attr' => [
                    'autocomplete' => 'current-password',
                ],
            ])
            ->add('newPassword', PasswordType::class, [
                'label' => 'edit_profile.change_password_new',
                'help' => 'auth.set_password.password_hint',
                'help_translation_parameters' => [
                    '%minimum%' => StrongPassword::MINIMUM_LENGTH,
                ],
                'attr' => [
                    'autocomplete' => 'new-password',
                    'data-password-suggestion-target' => 'field',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ChangePasswordFormData::class,
        ]);
    }
}
