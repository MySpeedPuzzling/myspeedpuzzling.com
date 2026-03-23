<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\FeatureRequestFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<FeatureRequestFormData>
 */
final class FeatureRequestFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'feature_requests.form.title_label',
                'required' => true,
                'attr' => [
                    'maxlength' => 255,
                    'placeholder' => 'feature_requests.form.title_placeholder',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'feature_requests.form.description_label',
                'required' => true,
                'help' => 'feature_requests.form.description_help',
                'attr' => [
                    'rows' => 6,
                    'maxlength' => 2000,
                    'placeholder' => 'feature_requests.form.description_placeholder',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FeatureRequestFormData::class,
        ]);
    }
}
