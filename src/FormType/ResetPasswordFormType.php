<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\ResetPasswordFormData;
use SpeedPuzzling\Web\Validator\StrongPassword;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ResetPasswordFormData>
 */
final class ResetPasswordFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', PasswordType::class, [
            'label' => 'auth.password_reset.new_password',
            'help' => 'auth.password_reset.password_hint',
            'help_translation_parameters' => [
                '%minimum%' => StrongPassword::MINIMUM_LENGTH,
            ],
            'attr' => [
                'autocomplete' => 'new-password',
                'data-password-suggestion-target' => 'field',
                'autofocus' => true,
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ResetPasswordFormData::class,
        ]);
    }
}
