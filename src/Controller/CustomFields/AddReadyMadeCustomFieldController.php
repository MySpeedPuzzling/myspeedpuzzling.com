<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CustomFields;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddCustomField;
use SpeedPuzzling\Web\Services\CustomFields\CustomFieldRefusalMessage;
use SpeedPuzzling\Web\Services\CustomFields\ViewerResultFields;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\StandardCustomField;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One tap on a ready-made field (docs/features/custom-fields.md "Ready-made fields").
 */
final class AddReadyMadeCustomFieldController extends AbstractController
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
        path: '/{_locale}/custom-fields/ready-made/{key}',
        name: 'my_fields_add_ready_made',
        requirements: ['_locale' => CustomFieldsController::LOCALES, 'key' => '[a-z_]+'],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $key): Response
    {
        if ($this->isCsrfTokenValid(CustomFieldsController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $standard = StandardCustomField::tryFrom($key) ?? throw $this->createNotFoundException();

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        try {
            $this->messageBus->dispatch(AddCustomField::readyMade(Uuid::uuid7()->toString(), $player->playerId, $standard));

            $this->viewerResultFields->fieldsChanged($request);
            $this->addFlash('success', $this->translator->trans('my_fields.flash.added'));
        } catch (HandlerFailedException $exception) {
            $this->addFlash('warning', $this->refusalMessage->of($exception));
        }

        return $this->redirectToRoute('my_fields');
    }
}
