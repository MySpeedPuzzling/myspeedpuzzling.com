<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\DuplicateCompetitionFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Duplicate" (DuplicateCompetitionController): the new date (a native date input - works without JavaScript and opens
 * the phone's own picker) and the name. Its CSRF token is the stateless `competition_duplicate`, checked by the
 * controller as before the form existed (the field `_token`), so the form's own protection is off.
 *
 * @extends AbstractType<DuplicateCompetitionFormData>
 */
final class DuplicateCompetitionFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('dateFrom', DateType::class, [
            'label' => 'event_duplicate.form.date',
            'required' => true,
            'widget' => 'single_text',
            'input' => 'datetime_immutable',
        ]);

        $builder->add('name', TextType::class, [
            'label' => 'event_duplicate.form.name',
            'required' => true,
            'attr' => ['maxlength' => 250],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DuplicateCompetitionFormData::class,
            'csrf_protection' => false,
            // The `_token` field of the controller's own check
            'allow_extra_fields' => true,
        ]);
    }
}
