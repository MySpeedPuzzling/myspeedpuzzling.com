<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\AcceptModeratorInvitationFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The pledge at the end of the moderator guide - English like the guide and the review pages
 *
 * @extends AbstractType<AcceptModeratorInvitationFormData>
 */
final class AcceptModeratorInvitationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('pledge', CheckboxType::class, [
            'label' => 'I have read the guide. I will do my best to look after the puzzle catalogue with care, ask when I am not sure, and leave it better than I found it.',
            'translation_domain' => false,
            'required' => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AcceptModeratorInvitationFormData::class,
        ]);
    }
}
