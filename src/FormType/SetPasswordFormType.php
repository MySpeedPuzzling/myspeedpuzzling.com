<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\SetPasswordFormData;
use SpeedPuzzling\Web\Validator\StrongPassword;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<SetPasswordFormData>
 */
final class SetPasswordFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', PasswordType::class, [
            'label' => 'auth.set_password.new_password',
            'help' => 'auth.set_password.password_hint',
            'help_translation_parameters' => [
                '%minimum%' => StrongPassword::MINIMUM_LENGTH,
            ],
            'attr' => [
                // Lets the browser/password manager offer its own generator and,
                // more importantly, offer to save the result under our domain -
                // the entire point of this prompt (UX funnel §5)
                'autocomplete' => 'new-password',
                'data-password-suggestion-target' => 'field',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SetPasswordFormData::class,
        ]);
    }
}
