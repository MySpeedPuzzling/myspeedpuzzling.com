<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PuzzleAutocompleteController extends AbstractController
{
    #[Route(
        path: '/{_locale}/puzzle-autocomplete/',
        name: 'puzzle_autocomplete',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        return new JsonResponse([
            'results' => [
                'options' => [
                    ['value'=> '1', 'text'=> 'Pizza', 'group_by'=> ['food']],
                    ['value'=> '2', 'text'=> 'Banana', 'group_by'=> ['food']],
                ],
                'optgroups' => [
                    ['value' => 'food', 'label' => 'food'],
                ],
            ],
        ]);
    }
}
