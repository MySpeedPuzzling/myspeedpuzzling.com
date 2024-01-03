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
            'label' => 'Jméno/přezdívka:',
            'required' => false,
            'help' => 'Pod tímto jménem budete skládat puzzle a uvidí ho všichni členové komunity.',
        ]);

        $builder->add('email', EmailType::class, [
            'label' => 'E-mail',
            'required' => false,
            'help' => 'Váš e-mail je neveřejný, nikde se nezobrazuje a slouží pouze pro případ, aby vás mohl kontaktovat administrátor.',
        ]);

        $builder->add('city', TextType::class, [
            'label' => 'Město',
            'required' => false,
            'help' => 'Město, které hrdě reprezentujete (nepovinné).',
        ]);

        $builder->add('country', TextType::class, [
            'label' => 'Stát',
            'required' => false,
        ]);

        $builder->add('facebook', TextType::class, [
            'label' => 'Facebook',
            'required' => false,
        ]);

        $builder->add('instagram', TextType::class, [
            'label' => 'Instagram',
            'required' => false,
        ]);

        $builder->add('bio', TextareaType::class, [
            'label' => 'O mně (bio)',
            'required' => false,
        ]);

        $builder->add('avatar', FileType::class, [
            'label' => 'Avatar/foto',
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
