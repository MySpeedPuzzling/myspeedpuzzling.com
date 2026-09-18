<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\FormData\AddModeratorsFormData;
use SpeedPuzzling\Web\FormType\AddModeratorsFormType;
use SpeedPuzzling\Web\Message\GrantModeratorRole;
use SpeedPuzzling\Web\Query\GetModerators;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admins only - a moderator must never be able to appoint another one.
 */
#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class ModeratorsController extends AbstractController
{
    public function __construct(
        private readonly GetModerators $getModerators,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/admin/moderators', name: 'admin_moderators', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $data = new AddModeratorsFormData();
        $form = $this->createForm(AddModeratorsFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($data->players as $playerId) {
                $this->messageBus->dispatch(new GrantModeratorRole($playerId));
            }

            $this->addFlash('success', $this->translator->trans('admin.moderators.granted', ['%count%' => count($data->players)]));

            return $this->redirectToRoute('admin_moderators');
        }

        return $this->render('admin/moderators.html.twig', [
            'moderators' => $this->getModerators->all(),
            'form' => $form,
        ]);
    }
}
