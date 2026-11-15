<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Query\GetDataImports;
use SpeedPuzzling\Web\Query\GetImportAddScreen;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One group answer of the signed-in player's import (docs/features/data-import/group-answers.md): the live line
 * while its rows are gone through ("Going through your rows… 200 of 416"), then - when it started an "Add" - on to
 * that batch's page; otherwise what came of it and the way on. Another player's answer is a 404. Two statements.
 */
final class ImportGroupAnswerController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetDataImports $getDataImports,
        readonly private GetImportAddScreen $getImportAddScreen,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/group-answers/{answerId}',
        name: 'data_import_group_answer',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID, 'answerId' => Requirement::UUID],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $importId, string $answerId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $answer = $this->getImportAddScreen->groupAnswer($importId, $player->playerId, $answerId);

        // Through, and the rows are being added: that "Add" has the page that says how far it is and what came of it
        if ($answer['running'] === false && $answer['batchId'] !== null) {
            return $this->redirectToRoute('data_import_batch', ['importId' => $importId, 'batchId' => $answer['batchId']]);
        }

        return $this->render('data_import/group_answer.html.twig', [
            'import' => $this->getDataImports->byIdOfPlayer($importId, $player->playerId),
            'answer' => $answer,
        ]);
    }
}
