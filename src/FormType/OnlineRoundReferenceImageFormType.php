<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\OnlineRoundReferenceImageFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The reference image of an online round's completeness check (online_round_settings, docs/features/online-competitions/
 * README.md §5.8) - its own form on the settings page, next to the settings: an upload is not a setting to save with
 * the others.
 *
 * @extends AbstractType<OnlineRoundReferenceImageFormData>
 */
final class OnlineRoundReferenceImageFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('image', FileType::class, [
            'label' => 'online_rounds.settings.reference.field',
            'help' => 'online_rounds.settings.reference.help',
            'required' => true,
            // HEIC as a type and by its extensions: desktop browsers know no type for it
            'attr' => ['accept' => 'image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OnlineRoundReferenceImageFormData::class,
        ]);
    }
}
