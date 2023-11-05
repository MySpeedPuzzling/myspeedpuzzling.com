<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\FormData\AddPuzzleSolvingTimeFormData;
use SpeedPuzzling\Web\FormData\EditProfileFormData;
use SpeedPuzzling\Web\FormType\AddPuzzleSolvingTimeFormType;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\EditProfile;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AddTimeController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(path: '/pridat-cas', name: 'add_time', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $addPuzzleSolvingTimeForm = $this->createForm(AddPuzzleSolvingTimeFormType::class);
        $addPuzzleSolvingTimeForm->handleRequest($request);

        if ($addPuzzleSolvingTimeForm->isSubmitted() && $addPuzzleSolvingTimeForm->isValid()) {
            $data = $addPuzzleSolvingTimeForm->getData();
            assert($data instanceof AddPuzzleSolvingTimeFormData);
            $userId = $user->getUserIdentifier();

            $this->messageBus->dispatch(
                AddPuzzleSolvingTime::fromFormData($userId, $data),
            );

            $this->addFlash(
                'success',
                'Skvělá práce! Skládání jsme zaznamenali.'
            );

            return $this->redirectToRoute('my_profile');
        }

        return $this->render('add-time.html.twig', [
            'add_puzzle_solving_time_form' => $addPuzzleSolvingTimeForm,
        ]);
    }
}
