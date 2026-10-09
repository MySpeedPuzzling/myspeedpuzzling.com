<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EditionFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Add an edition to a series. docs/features/organizations/README.md "Forms": "Who can enter" (empty = the series') and
 * "Save as draft" next to the form's own button.
 *
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

        $builder->add('description', TextareaType::class, [
            'label' => 'edition.form.description',
            'help' => 'edition.form.description_help',
            'required' => false,
            'attr' => ['rows' => 4],
        ]);

        $builder->add('link', UrlType::class, [
            'label' => 'edition.form.link',
            'help' => 'edition.form.link_help',
            'required' => false,
        ]);

        $builder->add('registrationLink', UrlType::class, [
            'label' => 'edition.form.registration_link',
            'required' => false,
        ]);

        $builder->add('resultsLink', UrlType::class, [
            'label' => 'edition.form.results_link',
            'required' => false,
        ]);

        $builder->add('eligibility', TextType::class, [
            'label' => 'organizer_tools.form.eligibility',
            'help' => 'organizer_tools.form.eligibility_edition_help',
            'required' => false,
            'attr' => ['maxlength' => 120],
        ]);

        $builder->add('saveDraft', SubmitType::class, [
            'label' => 'organizer_tools.form.save_draft',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditionFormData::class,
        ]);
    }
}
