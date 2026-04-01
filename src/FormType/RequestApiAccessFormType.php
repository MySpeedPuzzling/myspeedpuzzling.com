<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\RequestApiAccessFormData;
use SpeedPuzzling\Web\Value\OAuth2ApplicationType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<RequestApiAccessFormData>
 */
final class RequestApiAccessFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('clientName', TextType::class, [
            'label' => 'request_api_access.client_name',
            'required' => true,
            'attr' => ['maxlength' => 100],
        ]);

        $builder->add('clientDescription', TextareaType::class, [
            'label' => 'request_api_access.client_description',
            'required' => true,
            'attr' => ['rows' => 3],
        ]);

        $builder->add('purpose', TextareaType::class, [
            'label' => 'request_api_access.purpose',
            'required' => true,
            'attr' => [
                'rows' => 3,
                'placeholder' => 'request_api_access.purpose_placeholder',
            ],
        ]);

        $builder->add('applicationType', EnumType::class, [
            'class' => OAuth2ApplicationType::class,
            'label' => 'request_api_access.application_type',
            'expanded' => true,
            'choice_label' => fn(OAuth2ApplicationType $type) => match ($type) {
                OAuth2ApplicationType::Confidential => 'request_api_access.type_confidential',
                OAuth2ApplicationType::Public => 'request_api_access.type_public',
            },
        ]);

        $builder->add('scopes', ChoiceType::class, [
            'label' => 'request_api_access.scopes_heading',
            'required' => false,
            'multiple' => true,
            'expanded' => true,
            'choices' => [
                'request_api_access.scope_profile' => 'profile:read',
                'request_api_access.scope_results' => 'results:read',
                'request_api_access.scope_statistics' => 'statistics:read',
                'request_api_access.scope_collections_read' => 'collections:read',
                'request_api_access.scope_solving_times' => 'solving-times:write',
                'request_api_access.scope_collections_write' => 'collections:write',
            ],
        ]);

        $builder->add('redirectUris', TextareaType::class, [
            'label' => 'request_api_access.redirect_uris_label',
            'required' => false,
            'attr' => [
                'rows' => 3,
                'placeholder' => 'request_api_access.redirect_uris_placeholder',
            ],
            'help' => 'request_api_access.redirect_uris_help',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RequestApiAccessFormData::class,
        ]);
    }
}
