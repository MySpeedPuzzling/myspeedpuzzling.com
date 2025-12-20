<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestNotFound;
use SpeedPuzzling\Web\Query\GetPuzzleChangeRequests;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class PuzzleChangeRequestDetailController extends AbstractController
{
    public function __construct(
        private readonly GetPuzzleChangeRequests $getPuzzleChangeRequests,
    ) {
    }

    #[Route(
        path: '/admin/puzzle-change-requests/{id}',
        name: 'admin_puzzle_change_request_detail',
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(
        #[CurrentUser] User $user,
        string $id,
    ): Response {
        $request = $this->getPuzzleChangeRequests->byId($id);

        if ($request === null) {
            throw new PuzzleChangeRequestNotFound();
        }

        return $this->render('admin/puzzle_change_request_detail.html.twig', [
            'request' => $request,
        ]);
    }
}
