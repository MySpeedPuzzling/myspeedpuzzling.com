<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\FormData\CollectionPuzzleActionFormData;
use SpeedPuzzling\Web\FormType\CollectionPuzzleActionFormType;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * "Add to my collection" call to action for the recap pages shown after adding a time.
 *
 * Members get the collection picker modal, non-members a one-click form for the system
 * collection. Both go to `collection_add` with `context=recap`, which answers with a
 * Turbo Stream replacing the `recap-collection-cta` element - the page never reloads.
 */
#[AsTwigComponent]
final class AddToCollectionCta
{
    public const string ELEMENT_ID = 'recap-collection-cta';

    public string $puzzleId = '';

    public string $buttonClass = 'btn btn-sm btn-outline-primary';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private FormFactoryInterface $formFactory,
    ) {
    }

    /**
     * Hidden for puzzles the player already has - in any collection, lent out, or borrowed from someone else.
     */
    public function shouldShow(): bool
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return false;
        }

        $statuses = $this->getUserPuzzleStatuses->byPlayerId($player->playerId);

        return in_array($this->puzzleId, $statuses->collection, true) === false
            && in_array($this->puzzleId, $statuses->borrowed, true) === false
            && in_array($this->puzzleId, $statuses->lent, true) === false;
    }

    public function hasActiveMembership(): bool
    {
        return $this->retrieveLoggedUserProfile->getProfile()?->activeMembership === true;
    }

    public function getElementId(): string
    {
        return self::ELEMENT_ID;
    }

    public function getSystemCollectionId(): string
    {
        return Collection::SYSTEM_ID;
    }

    /**
     * Only the field names are taken from it, so the one-click form can never drift from the form type.
     */
    public function getFormView(): FormView
    {
        return $this->formFactory->create(CollectionPuzzleActionFormType::class, new CollectionPuzzleActionFormData())->createView();
    }
}
