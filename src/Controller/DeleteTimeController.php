<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Message\DeletePuzzleSolvingTime;
use SpeedPuzzling\Web\Services\ReturnUrlValidator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class DeleteTimeController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private ReturnUrlValidator $returnUrlValidator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/smazat-cas/{timeId}',
            'en' => '/en/delete-time/{timeId}',
            'es' => '/es/eliminar-tiempo/{timeId}',
            'ja' => '/ja/時間削除/{timeId}',
            'fr' => '/fr/supprimer-temps/{timeId}',
            'de' => '/de/zeit-loeschen/{timeId}',
        ],
        name: 'delete_time',
    )]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $timeId): Response
    {
        $this->messageBus->dispatch(
            new DeletePuzzleSolvingTime($user->getUserIdentifier(), $timeId),
        );

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('delete-time_success_stream.html.twig', [
                'message' => $this->translator->trans('flashes.time_deleted'),
            ]);
        }

        $this->addFlash('success', $this->translator->trans('flashes.time_deleted'));

        $returnUrl = $this->returnUrlValidator->sanitize(
            $request->isMethod('POST')
                ? $request->request->getString('return_url')
                : $request->query->getString('return_url'),
            $request,
        );

        return $this->redirect($returnUrl ?? $this->generateUrl('my_profile'));
    }
}
