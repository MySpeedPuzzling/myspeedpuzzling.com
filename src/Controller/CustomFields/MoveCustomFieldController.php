<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CustomFields;

use SpeedPuzzling\Web\Message\ReorderCustomFields;
use SpeedPuzzling\Web\Query\GetCustomFields;
use SpeedPuzzling\Web\Results\CustomFieldOverview;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The up / down buttons of the list: the field changes places with its neighbour - plain forms, no JavaScript.
 */
final class MoveCustomFieldController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetCustomFields $getCustomFields,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/{_locale}/custom-fields/{fieldId}/move',
        name: 'my_fields_move',
        requirements: ['_locale' => CustomFieldsController::LOCALES, 'fieldId' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $fieldId): Response
    {
        if ($this->isCsrfTokenValid(CustomFieldsController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        // The order the player sees: their fields in use (the archived ones keep their place behind them)
        $order = array_map(
            static fn (CustomFieldOverview $field): string => $field->fieldId,
            array_values(array_filter(
                $this->getCustomFields->ofPlayer($player->playerId),
                static fn (CustomFieldOverview $field): bool => $field->archived === false,
            )),
        );

        $index = array_search(strtolower($fieldId), $order, true);

        if ($index === false) {
            throw $this->createNotFoundException();
        }

        $neighbour = $request->request->getString('direction') === 'up' ? $index - 1 : $index + 1;

        if (isset($order[$neighbour])) {
            [$order[$index], $order[$neighbour]] = [$order[$neighbour], $order[$index]];

            $this->messageBus->dispatch(new ReorderCustomFields($player->playerId, array_values($order)));
        }

        return $this->redirectToRoute('my_fields', ['_fragment' => 'my-fields']);
    }
}
