<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Exceptions\InternalApiInvalidInput;
use SpeedPuzzling\Web\Query\GetPuzzleDifficultyEstimateModel;
use SpeedPuzzling\Web\Value\PuzzleDifficultyEstimateInput;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The current model record (docs/features/internal-api.md, "Puzzle difficulty labels and estimates") - the latest
 * recorded run, of one model name with `?model=`. `{"current": {…}}`, `{"current": null}` while none was recorded:
 * the Mac's push guard compares its new holdout Spearman with it.
 */
final class GetPuzzleDifficultyEstimateModelController extends AbstractController
{
    private const array KNOWN_PARAMETERS = ['model'];

    public function __construct(
        private readonly GetPuzzleDifficultyEstimateModel $getPuzzleDifficultyEstimateModel,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-difficulty-estimates/model',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $errors = [];
        $query = $request->query->all();

        foreach (array_keys($query) as $parameter) {
            if (in_array($parameter, self::KNOWN_PARAMETERS, true) === false) {
                $errors[(string) $parameter] = sprintf('is not a parameter of this endpoint (known: %s).', implode(', ', self::KNOWN_PARAMETERS));
            }
        }

        $model = $query['model'] ?? null;

        if ($model !== null && (is_string($model) === false || trim($model) === '' || mb_strlen($model) > PuzzleDifficultyEstimateInput::MAX_NAME_LENGTH || str_contains($model, "\0"))) {
            $errors['model'] = sprintf('must be a model name of at most %d characters.', PuzzleDifficultyEstimateInput::MAX_NAME_LENGTH);
        }

        if ($errors !== []) {
            throw new InternalApiInvalidInput($errors);
        }

        $current = $this->getPuzzleDifficultyEstimateModel->current(is_string($model) ? trim($model) : null);

        return new JsonResponse([
            'current' => $current !== null ? PuzzleDifficultyEstimateModelJson::of($current) : null,
        ]);
    }
}
