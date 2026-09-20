<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\AllowPrivateProfileViewersFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Same player picker as the event form's "maintainers" field: a TomSelect text
 * input fed by player_search_autocomplete (the template wraps it in the
 * player-search-autocomplete Stimulus controller), submitted as comma-separated ids.
 *
 * @extends AbstractType<AllowPrivateProfileViewersFormData>
 */
final class AllowPrivateProfileViewersFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('players', TextType::class, [
            'label' => 'private_profile_viewers.add_label',
            'help' => 'private_profile_viewers.add_help',
            'required' => false,
            'autocomplete' => true,
            'tom_select_options' => [
                'create' => false,
                'persist' => false,
                'maxItems' => 10,
                'closeAfterSelect' => true,
            ],
        ]);

        $builder->get('players')->addModelTransformer(new CallbackTransformer(
            static function (null|array $value): string {
                /** @var null|array<string> $value */
                return implode(',', $value ?? []);
            },
            static function (null|string $value): array {
                if ($value === null || $value === '') {
                    return [];
                }

                return array_values(array_unique(array_filter(explode(',', $value))));
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AllowPrivateProfileViewersFormData::class,
        ]);
    }
}
