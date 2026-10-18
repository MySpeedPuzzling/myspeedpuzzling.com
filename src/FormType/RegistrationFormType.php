<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\RegistrationFormData;
use SpeedPuzzling\Web\Validator\StrongPassword;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @extends AbstractType<RegistrationFormData>
 */
final class RegistrationFormType extends AbstractType
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Required fields first, the optional name last - easy to skip, and the
        // password manager still pairs the password with the email right above
        // it as the username (auth UX redesign §4.2)
        $builder
            ->add('email', EmailType::class, [
                // An empty field must reach the validator as '' (the form has novalidate), never null into a string property
                'empty_data' => '',
                'label' => 'auth.register.email',
                'attr' => [
                    'autocomplete' => 'username',
                    'autocapitalize' => 'none',
                    'autocorrect' => 'off',
                    'spellcheck' => 'false',
                    'enterkeyhint' => 'next',
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                'empty_data' => '',
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
                    'minlength' => StrongPassword::MINIMUM_LENGTH,
                    'enterkeyhint' => 'next',
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'auth.register.name_optional',
                'help' => 'auth.register.name_hint',
                'required' => false,
                'attr' => [
                    'autocomplete' => 'nickname',
                    'enterkeyhint' => 'done',
                ],
            ])
            // Required; the links open in a new tab so the filled-in form stays put
            ->add('termsAccepted', CheckboxType::class, [
                'label' => 'auth.register.terms',
                'label_html' => true,
                'label_translation_parameters' => [
                    '%terms_url%' => $this->urlGenerator->generate('terms_of_service'),
                    '%privacy_url%' => $this->urlGenerator->generate('privacy_policy'),
                ],
            ])
            ->add('newsletter', CheckboxType::class, [
                'label' => 'auth.register.newsletter',
                'required' => false,
            ])
            // The browser's time zone (docs/features/player-timezone.md) - filled by timezone_detect_controller.js
            ->add('timezone', HiddenType::class, [
                'required' => false,
                'attr' => [
                    'data-controller' => 'timezone-detect',
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
