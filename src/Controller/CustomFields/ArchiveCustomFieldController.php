<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CustomFields;

use SpeedPuzzling\Web\Message\ArchiveCustomField;
use SpeedPuzzling\Web\Services\CustomFields\CustomFieldRefusalMessage;
use SpeedPuzzling\Web\Services\CustomFields\ViewerResultFields;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Archive a field (hidden, its values kept) or bring it back - `restore=1`.
 */
final class ArchiveCustomFieldController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private ViewerResultFields $viewerResultFields,
        readonly private CustomFieldRefusalMessage $refusalMessage,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/custom-fields/{fieldId}/archive',
        name: 'my_fields_archive',
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

        $restore = $request->request->getString('restore') === '1';

        try {
            // Another player's field is "not found" - the handler's 404 goes on as it is
            $this->messageBus->dispatch(new ArchiveCustomField($fieldId, $player->playerId, archived: $restore === false));

            $this->viewerResultFields->fieldsChanged($request);
            $this->addFlash('success', $this->translator->trans($restore ? 'my_fields.flash.restored' : 'my_fields.flash.archived'));
        } catch (HandlerFailedException $exception) {
            $this->addFlash('warning', $this->refusalMessage->of($exception));
        }

        return $this->redirectToRoute('my_fields');
    }
}
