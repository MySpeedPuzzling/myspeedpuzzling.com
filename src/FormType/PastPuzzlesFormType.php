<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\PastPuzzlesFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The backfill path's "What did you solve there?" (creation-wizard.md "Backfill") - PastPuzzlesFormData::ROWS rows of
 * category + W1's puzzle picker
 *
 * @extends AbstractType<PastPuzzlesFormData>
 */
final class PastPuzzlesFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('rows', CollectionType::class, [
            'entry_type' => PastPuzzleRowFormType::class,
            'entry_options' => ['competition_id' => $options['competition_id']],
            'label' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PastPuzzlesFormData::class,
        ]);
        $resolver->setRequired('competition_id');
        $resolver->setAllowedTypes('competition_id', 'string');
    }
}
