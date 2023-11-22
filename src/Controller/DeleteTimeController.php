<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Message\DeletePuzzleSolvingTime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class DeleteTimeController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(path: '/smazat-cas/{timeId}', name: 'delete_time', methods: ['GET'])]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $timeId): Response
    {
        $this->messageBus->dispatch(
            new DeletePuzzleSolvingTime($user->getUserIdentifier(), $timeId)
        );

        $this->addFlash('success','Smazali jsme veškeré důkazy. Nikdo se nikdy nedozví, co se právě stalo!');

        return $this->redirectToRoute('my_profile');
    }
}
