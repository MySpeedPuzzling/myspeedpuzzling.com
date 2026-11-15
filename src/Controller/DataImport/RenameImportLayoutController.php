<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Message\RenameImportLayout;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Rename one of the player's saved layouts. POST + CSRF, then back to the list. Another player's layout is a 404
 * (the handler's).
 */
final class RenameImportLayoutController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/layouts/{layoutId}/rename',
        name: 'data_import_layout_rename',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'layoutId' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $layoutId): Response
    {
        if ($this->isCsrfTokenValid(ImportsHomeController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $name = trim($request->request->getString('name'));

        if ($name === '') {
            $this->addFlash('warning', $this->translator->trans('data_import.layouts.flash.name_needed'));

            return $this->redirectToRoute('data_import_layouts');
        }

        $this->messageBus->dispatch(new RenameImportLayout($layoutId, $player->playerId, $name));
        $this->addFlash('success', $this->translator->trans('data_import.layouts.flash.renamed'));

        return $this->redirectToRoute('data_import_layouts');
    }
}
