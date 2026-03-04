<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\GenerateTableLayoutFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<GenerateTableLayoutFormData>
 */
final class GenerateTableLayoutFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('numberOfRows', NumberType::class, [
            'label' => 'competition.tables.form.number_of_rows',
        ]);

        $builder->add('tablesPerRow', NumberType::class, [
            'label' => 'competition.tables.form.tables_per_row',
        ]);

        $builder->add('spotsPerTable', NumberType::class, [
            'label' => 'competition.tables.form.spots_per_table',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GenerateTableLayoutFormData::class,
        ]);
    }
}
