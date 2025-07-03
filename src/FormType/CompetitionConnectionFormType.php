<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\FormData\CompetitionConnectionFormData;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<CompetitionConnectionFormData>
 */
final class CompetitionConnectionFormType extends AbstractType
{
    public function __construct(
        readonly private GetCompetitionParticipants $getCompetitionParticipants,
    ) {
    }

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var null|Competition $competition */
        $competition = $options['competition'];

        $participantChoices = [];

        if ($competition !== null) {
            foreach ($this->getCompetitionParticipants->mappingForPairing($competition->id->toString()) as $name => $id) {
                $participantChoices[] = [
                    'value' => $id,
                    'text' => $name,
                ];
            }
        }

        $builder->add('participant', TextType::class, [
            'label' => 'forms.competition_participant',
            'required' => false,
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
            'data_class' => CompetitionConnectionFormData::class,
            'competition' => null,
        ]);
    }
}
