<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\FormData\CollectionFormData;
use SpeedPuzzling\Web\FormType\CollectionFormType;
use SpeedPuzzling\Web\Message\CreateCollection;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class CreateCollectionForm extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;
    use ComponentWithFormTrait;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @return FormInterface<CollectionFormData>
     */
    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(CollectionFormType::class, new CollectionFormData());
    }

    #[LiveAction]
    public function save(): void
    {
        $this->submitForm();

        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            $this->dispatchBrowserEvent('toast:show', [
                'message' => 'You must be logged in to add puzzles to collections.',
                'type' => 'error',
            ]);

            return;
        }

        /** @var CollectionFormData $formData */
        $formData = $this->getForm()->getData();

        $this->messageBus->dispatch(
            new CreateCollection(
                playerId: $player->playerId,
                name: $formData->name ?? '',
                description: $formData->description,
                visibility: $formData->visibility ?? CollectionVisibility::Private,
            ),
        );

        $this->dispatchBrowserEvent('toast:show', [
            'message' => 'Collection successfully created.',
            'type' => 'success',
        ]);

        $this->emit('collection:created', [
            'id' => '12345',
            'name' => $formData->name,
        ]);
    }
}
