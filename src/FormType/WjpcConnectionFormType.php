<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\WjpcConnectionFormData;
use SpeedPuzzling\Web\Query\GetWjpcParticipants;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<WjpcConnectionFormData>
 */
final class WjpcConnectionFormType extends AbstractType
{
    public function __construct(
        readonly private GetWjpcParticipants $getParticipants,
    ) {
    }

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $participantChoices = [];
        foreach ($this->getParticipants->mappingForPairing() as $name => $id) {
            $participantChoices[] = [
                'value' => $id,
                'text' => $name,
            ];
        }

        $builder->add('participant', TextType::class, [
            'label' => 'forms.wjpc_participant',
            'required' => true,
            'autocomplete' => true,
            'tom_select_options' => [
                'create' => false,
                'persist' => false,
                'maxItems' => 1,
                'options' => $participantChoices,
                'closeAfterSelect' => true,
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WjpcConnectionFormData::class,
        ]);
    }
}
