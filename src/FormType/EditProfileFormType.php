<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EditProfileFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

/**
 * @extends AbstractType<EditProfileFormData>
 */
final class EditProfileFormType extends AbstractType
{
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
            'label' => 'forms.city',
            'required' => false,
            'help' => 'forms.city_help',
        ]);

        $builder->add('country', TextType::class, [
            'label' => 'forms.country',
            'required' => false,
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditProfileFormData::class,
        ]);
    }
}
