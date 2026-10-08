<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\CreateOrganizationFromSeriesFormData;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\OrganizationKind;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<CreateOrganizationFromSeriesFormData>
 */
final class CreateOrganizationFromSeriesFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'restructure.to_organization.name',
        ]);

        $builder->add('shortName', TextType::class, [
            'label' => 'restructure.to_organization.short_name',
            'help' => 'restructure.to_organization.short_name_help',
            'required' => false,
        ]);

        $builder->add('kind', EnumType::class, [
            'label' => 'restructure.to_organization.kind',
            'class' => OrganizationKind::class,
            'choice_label' => static fn (OrganizationKind $kind): string => $kind->translationKey(),
            'placeholder' => 'restructure.to_organization.kind_placeholder',
            'required' => false,
        ]);

        $countries = [];

        foreach (CountryCode::cases() as $country) {
            $countries[$country->value] = $country->name;
        }

        $builder->add('countryCode', ChoiceType::class, [
            'label' => 'restructure.to_organization.country',
            'choices' => $countries,
            'placeholder' => 'restructure.to_organization.country_placeholder',
            'required' => false,
            'autocomplete' => true,
            'choice_translation_domain' => false,
        ]);

        $builder->add('region', TextType::class, [
            'label' => 'restructure.to_organization.region',
            'help' => 'restructure.to_organization.region_help',
            'required' => false,
        ]);

        $builder->add('slug', TextType::class, [
            'label' => 'restructure.to_organization.slug',
            'help' => 'restructure.to_organization.slug_help',
            'required' => false,
        ]);

        $builder->add('newSeriesName', TextType::class, [
            'label' => 'restructure.to_organization.new_series_name',
            'help' => 'restructure.to_organization.new_series_name_help',
            'required' => false,
        ]);

        $builder->add('newSeriesSlug', TextType::class, [
            'label' => 'restructure.to_organization.new_series_slug',
            'help' => 'restructure.to_organization.new_series_slug_help',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CreateOrganizationFromSeriesFormData::class,
        ]);
    }
}
