<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\FormData\OrganizationFormData;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\OrganizationKind;
use SpeedPuzzling\Web\Value\SocialLinks;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;

/**
 * The add/edit organization forms (docs/features/organizations/README.md "Forms"): name, short name, kind, country,
 * region, about, website, social links (one per line), logo, team (the event forms' player picker). The edit form adds
 * the "URL" field (`url_field`), the add form the secondary "Save as draft" submit (`draft_button`).
 *
 * @extends AbstractType<OrganizationFormData>
 */
final class OrganizationFormType extends AbstractType
{
    public const int MAX_MAINTAINERS = 10;

    public function __construct(
        private readonly Connection $database,
        private readonly ImageThumbnailTwigExtension $imageThumbnail,
        private readonly CountryChoices $countryChoices,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'organization_page.form.name',
            'attr' => ['maxlength' => 120],
        ]);

        $builder->add('shortName', TextType::class, [
            'label' => 'organization_page.form.short_name',
            'help' => 'organization_page.form.short_name_help',
            'required' => false,
            'attr' => ['maxlength' => 30],
        ]);

        // Edit forms only - a new organization's first URL comes from its name
        if ($options['url_field'] === true) {
            $builder->add('slug', TextType::class, [
                'label' => 'competition.form.slug',
                'help' => 'organization_page.form.slug_help',
                'required' => false,
                'attr' => [
                    'autocapitalize' => 'off',
                    'autocomplete' => 'off',
                    'spellcheck' => 'false',
                ],
            ]);
        }

        $builder->add('kind', EnumType::class, [
            'label' => 'organization_page.form.kind',
            'class' => OrganizationKind::class,
            'choice_label' => static fn (OrganizationKind $kind): string => $kind->translationKey(),
            'placeholder' => 'organization_page.form.kind_placeholder',
            'required' => false,
        ]);

        $builder->add('countryCode', ChoiceType::class, [
            'label' => 'organization_page.form.country',
            'choices' => $this->countryChoices->choices(),
            'required' => false,
            'placeholder' => 'competition.form.country_placeholder',
            'autocomplete' => true,
            'choice_translation_domain' => false,
            'choice_attr' => CountryChoices::choiceAttr(...),
        ]);

        $builder->add('region', TextType::class, [
            'label' => 'organization_page.form.region',
            'help' => 'organization_page.form.region_help',
            'required' => false,
            'attr' => ['maxlength' => 120],
        ]);

        $builder->add('about', TextareaType::class, [
            'label' => 'organization_page.form.about',
            'help' => 'organization_page.form.about_help',
            'required' => false,
            'attr' => ['rows' => 5, 'maxlength' => 5000],
        ]);

        $builder->add('website', TextType::class, [
            'label' => 'organization_page.form.website',
            'required' => false,
            'attr' => ['inputmode' => 'url', 'placeholder' => 'https://', 'autocomplete' => 'url'],
        ]);

        $builder->add('socialLinks', TextareaType::class, [
            'label' => 'organization_page.form.social_links',
            'help' => 'organization_page.form.social_links_help',
            'help_translation_parameters' => ['%limit%' => SocialLinks::MAX],
            'required' => false,
            'attr' => ['rows' => 5, 'spellcheck' => 'false', 'autocapitalize' => 'off', 'inputmode' => 'url', 'class' => 'ev-org-social-input'],
        ]);

        // One address per line in the textarea, a list in the form data - empty lines and repeats dropped, so the limit
        // counts every link once
        $builder->get('socialLinks')->addModelTransformer(new CallbackTransformer(
            static function (null|array $urls): string {
                return $urls === null ? '' : implode("\n", array_filter($urls, is_string(...)));
            },
            static function (null|string $text): array {
                return $text === null ? [] : SocialLinks::normalize($text);
            },
        ));

        $builder->add('logo', FileType::class, [
            'label' => 'organization_page.form.logo',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '5M',
                    mimeTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                ),
            ],
        ]);

        /** @var OrganizationFormData $data */
        $data = $builder->getData();

        $builder->add('maintainers', TextType::class, [
            'label' => 'organization_page.form.team',
            'help' => 'organization_page.form.team_help',
            'required' => false,
            'autocomplete' => true,
            'tom_select_options' => [
                'create' => false,
                'persist' => false,
                'maxItems' => self::MAX_MAINTAINERS,
                'options' => $this->maintainerChoices($data->maintainers),
                'closeAfterSelect' => true,
            ],
        ]);

        $builder->get('maintainers')->addModelTransformer(new CallbackTransformer(
            static function (null|array $value): string {
                return $value === null ? '' : implode(',', array_filter($value, is_string(...)));
            },
            static function (null|string $value): array {
                if ($value === null || $value === '') {
                    return [];
                }

                return array_slice(array_filter(explode(',', $value)), 0, self::MAX_MAINTAINERS);
            },
        ));

        if ($options['draft_button'] === true) {
            $builder->add('saveDraft', SubmitType::class, [
                'label' => 'organization_page.form.save_draft',
                'attr' => ['class' => 'btn btn-outline-secondary mt-3'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OrganizationFormData::class,
            'url_field' => false,
            'draft_button' => false,
        ]);

        $resolver->setAllowedTypes('url_field', 'bool');
        $resolver->setAllowedTypes('draft_button', 'bool');
    }

    /**
     * The chosen team members as TomSelect options (name, flag, avatar, #code) - the event forms' picker
     *
     * @param list<string> $playerIds
     *
     * @return list<array{value: string, text: string}>
     */
    private function maintainerChoices(array $playerIds): array
    {
        if ($playerIds === []) {
            return [];
        }

        $rows = $this->database->executeQuery(
            'SELECT id, name, code, country, avatar FROM player WHERE id IN (?)',
            [$playerIds],
            [ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $choices = [];

        foreach ($rows as $row) {
            /** @var array{id: string, name: null|string, code: string, country: null|string, avatar: null|string} $row */
            $choices[] = [
                'value' => $row['id'],
                'text' => $this->playerOptionHtml($row['name'] ?? $row['code'], $row['code'], $row['country'], $row['avatar']),
            ];
        }

        return $choices;
    }

    private function playerOptionHtml(string $name, string $code, null|string $country, null|string $avatar): string
    {
        $name = htmlspecialchars($name);
        $code = htmlspecialchars($code);

        if ($avatar !== null) {
            $avatarUrl = htmlspecialchars($this->imageThumbnail->thumbnailUrl($avatar, 'puzzle_small'));
            $avatarHtml = '<img alt="" class="rounded-circle me-2" style="width: 24px; height: 24px; object-fit: cover;" src="' . $avatarUrl . '">';
        } else {
            $avatarHtml = '<i class="ci-user me-2"></i>';
        }

        $countryCode = CountryCode::fromCode($country);
        $flagHtml = $countryCode !== null ? '<span class="shadow-custom fi fi-' . $countryCode->name . ' me-1"></span> ' : '';

        return '<div class="d-flex align-items-center">' . $avatarHtml . $flagHtml . '<span>' . $name . '</span><small class="text-muted ms-1">#' . $code . '</small></div>';
    }
}
