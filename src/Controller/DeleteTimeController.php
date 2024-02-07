<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Message\DeletePuzzleSolvingTime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DeleteTimeController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/smazat-cas/{timeId}',
            'en' => '/en/delete-time/{timeId}',
        ],
        name: 'delete_time',
        methods: ['GET'],
    )]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $timeId): Response
    {
        $this->messageBus->dispatch(
            new DeletePuzzleSolvingTime($user->getUserIdentifier(), $timeId)
        );

        $this->addFlash('success', $this->translator->trans('flashes.time_deleted'));

        return $this->redirectToRoute('my_profile');
    }
}
