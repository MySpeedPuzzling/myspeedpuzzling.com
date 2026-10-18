<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\EventSubscriber\InternalApiAuditSubscriber;
use SpeedPuzzling\Web\Message\RecordPuzzleDifficultyEstimateModel;
use SpeedPuzzling\Web\Query\GetPuzzleDifficultyEstimateModel;
use SpeedPuzzling\Web\Value\PuzzleDifficultyEstimateModelInput;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Records a run of the image pipeline's difficulty model with its evaluation - the new current model record
 * (docs/features/internal-api.md, "Puzzle difficulty labels and estimates"). Body `{model, modelVersion, trainingSize,
 * holdoutSpearman, evaluation, createdAt?}` → `201` with the record; invalid fields `422` with `errors`. The audit log
 * gets the model and its Spearman, never the evaluation.
 */
final class RecordPuzzleDifficultyEstimateModelController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetPuzzleDifficultyEstimateModel $getPuzzleDifficultyEstimateModel,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-difficulty-estimates/model',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $input = PuzzleDifficultyEstimateModelInput::fromBody(InternalApiJsonBody::parse($request));
        $recordId = Uuid::uuid7();

        $this->messageBus->dispatch(new RecordPuzzleDifficultyEstimateModel($recordId, $input));

        $record = $this->getPuzzleDifficultyEstimateModel->byId($recordId->toString());
        assert($record !== null);

        $request->attributes->set(InternalApiAuditSubscriber::CREATED_ID_ATTRIBUTE, $record->id);
        $request->attributes->set(InternalApiAuditSubscriber::SUMMARY_ATTRIBUTE, [
            'model' => $record->model,
            'modelVersion' => $record->modelVersion,
            'holdoutSpearman' => $record->holdoutSpearman,
            'trainingSize' => $record->trainingSize,
        ]);

        return new JsonResponse(PuzzleDifficultyEstimateModelJson::of($record), Response::HTTP_CREATED);
    }
}
