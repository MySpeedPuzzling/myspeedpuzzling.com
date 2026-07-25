<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\RegistrationFormData;
use SpeedPuzzling\Web\Validator\StrongPassword;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<RegistrationFormData>
 */
final class RegistrationFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'auth.register.email',
                'attr' => [
                    'autocomplete' => 'username',
                    'autofocus' => true,
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'auth.register.password',
                'help' => 'auth.register.password_hint',
                'help_translation_parameters' => [
                    '%minimum%' => StrongPassword::MINIMUM_LENGTH,
                ],
                'attr' => [
                    // Lets the manager offer its generator and, above all, offer to save
                    // the result under myspeedpuzzling.com right away
                    'autocomplete' => 'new-password',
                    'data-password-suggestion-target' => 'field',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrationFormData::class,
        ]);
    }
}
