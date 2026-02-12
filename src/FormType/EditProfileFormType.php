<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EditProfileFormData;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<EditProfileFormData>
 */
final class EditProfileFormType extends AbstractType
{
    public function __construct(
        readonly private TranslatorInterface $translator
    ) {
    }

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'my_profile.name',
            'required' => false,
            'help' => 'forms.name_help',
        ]);

        $builder->add('email', EmailType::class, [
            'label' => 'email',
            'required' => false,
            'help' => 'forms.email_help',
        ]);

        $builder->add('city', TextType::class, [
            'label' => 'forms.location',
            'required' => false,
            'help' => 'forms.location_help',
        ]);

        $allCountries = [];

        foreach (CountryCode::cases() as $country) {
            $allCountries[$country->value] = $country->name;
        }

        $countries = [
            $this->translator->trans('forms.country_most_common') => [
                CountryCode::cz->value => CountryCode::cz->name,
                CountryCode::sk->value => CountryCode::sk->name,
                CountryCode::pl->value => CountryCode::pl->name,
                CountryCode::de->value => CountryCode::de->name,
                CountryCode::at->value => CountryCode::at->name,
                CountryCode::no->value => CountryCode::no->name,
                CountryCode::fi->value => CountryCode::fi->name,
                CountryCode::us->value => CountryCode::us->name,
                CountryCode::ca->value => CountryCode::ca->name,
                CountryCode::fr->value => CountryCode::fr->name,
                CountryCode::nz->value => CountryCode::nz->name,
                CountryCode::es->value => CountryCode::es->name,
                CountryCode::nl->value => CountryCode::nl->name,
                CountryCode::pt->value => CountryCode::pt->name,
                CountryCode::gb->value => CountryCode::gb->name,
            ],
            $this->translator->trans('forms.country_all') => $allCountries,
        ];

        $builder->add('country', ChoiceType::class, [
            'label' => 'forms.nationality',
            'required' => false,
            'expanded' => false,
            'multiple' => false,
            'choices' => $countries,
            'choice_translation_domain' => false,
        ]);

        $builder->add('facebook', TextType::class, [
            'label' => 'facebook',
            'required' => false,
        ]);

        $builder->add('instagram', TextType::class, [
            'label' => 'instagram',
            'required' => false,
        ]);

        $builder->add('bio', TextareaType::class, [
            'label' => 'forms.about_me',
            'required' => false,
        ]);

        $builder->add('avatar', FileType::class, [
            'label' => 'forms.avatar',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '2m',
                ),
            ],
        ]);

        $builder->add('allowDirectMessages', CheckboxType::class, [
            'label' => 'Allow other users to message me directly',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditProfileFormData::class,
        ]);
    }
}
