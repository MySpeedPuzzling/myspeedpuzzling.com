<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EditionFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<EditionFormData>
 */
final class EditionFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'edition.form.name',
            'help' => 'edition.form.name_help',
        ]);

        $builder->add('dateFrom', DateType::class, [
            'label' => 'competition.form.date_from',
            'widget' => 'single_text',
            'format' => 'dd.MM.yyyy',
            'html5' => false,
            'input' => 'datetime_immutable',
            'input_format' => 'd.m.Y',
        ]);

        $builder->add('dateTo', DateType::class, [
            'label' => 'competition.form.date_to',
            'widget' => 'single_text',
            'format' => 'dd.MM.yyyy',
            'html5' => false,
            'input' => 'datetime_immutable',
            'input_format' => 'd.m.Y',
        ]);

        $builder->add('registrationLink', UrlType::class, [
            'label' => 'edition.form.registration_link',
            'required' => false,
        ]);

        $builder->add('resultsLink', UrlType::class, [
            'label' => 'edition.form.results_link',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditionFormData::class,
        ]);
    }
}
