<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\FeedbackFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The feedback box of the Help sheet and the `help` page (docs/features/navigation README §4.5, HELP-01b). The markup
 * around the field (placeholder, rows, the help sheet's Stimulus target) is the template's - help/_content.html.twig.
 *
 * @extends AbstractType<FeedbackFormData>
 */
final class FeedbackFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('message', TextareaType::class, [
            'label' => 'help.feedback.label',
            // A string property: an empty field stays '' (NotBlank answers it), never null
            'empty_data' => '',
        ]);

        $builder->add('url', HiddenType::class, [
            'empty_data' => '',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FeedbackFormData::class,
        ]);
    }
}
