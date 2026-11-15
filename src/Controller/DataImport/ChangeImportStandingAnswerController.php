<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Message\ChangeImportStandingAnswer;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Change" beside an answer that stands for the whole file ("Unusual times: added as they are · Change") - on
 * "Check and add". POST + CSRF, always a redirect back to the review: the question is asked again from now on.
 * Another player's import, or a question no answer stands for, is a 404 (the handler checks it).
 */
final class ChangeImportStandingAnswerController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/add/standing-answer',
        name: 'data_import_standing_answer_change',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId): Response
    {
        if ($this->isCsrfTokenValid(ImportsHomeController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $code = $request->request->getString('code');
        $this->messageBus->dispatch(new ChangeImportStandingAnswer($importId, $player->playerId, $code));
        $this->addFlash('success', $this->translator->trans('data_import.once.changed'));

        return $this->redirectToRoute('data_import_add', [
            'importId' => $importId,
            '_fragment' => $code === ImportRowRefusal::UnusualTime->value ? 'unusual-times' : 'already-on',
        ]);
    }
}
