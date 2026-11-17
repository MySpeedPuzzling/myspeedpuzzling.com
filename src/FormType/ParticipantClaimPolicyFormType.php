<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\ParticipantClaimPolicyFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ParticipantClaimPolicyFormData>
 */
final class ParticipantClaimPolicyFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('policy', ChoiceType::class, [
            'expanded' => true,
            'label' => 'participant_linking.hub.policy_label',
            'choices' => [
                'participant_linking.hub.policy.automatic' => ParticipantClaimPolicyFormData::AUTOMATIC,
                'participant_linking.hub.policy.open' => 'open',
                'participant_linking.hub.policy.approval' => 'approval',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ParticipantClaimPolicyFormData::class,
        ]);
    }
}
