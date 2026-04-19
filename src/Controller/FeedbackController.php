<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\FormData\FeedbackFormData;
use SpeedPuzzling\Web\FormType\FeedbackFormType;
use SpeedPuzzling\Web\Message\CollectUserFeedback;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class FeedbackController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/feedback',
            'en' => '/en/feedback',
            'es' => '/es/comentarios',
            'ja' => '/ja/フィードバック',
            'fr' => '/fr/commentaires',
            'de' => '/de/feedback',
        ],
        name: 'feedback',
    )]
    public function __invoke(Request $request, #[CurrentUser] UserInterface $user): Response
    {
        $data = new FeedbackFormData();
        $url = $request->query->get('url');

        if (is_string($url)) {
            $data->url = $url;
        }

        $form = $this->createForm(FeedbackFormType::class, $data);
        $form->handleRequest($request);

        $isModalRequest = $request->headers->get('Turbo-Frame') === 'modal-frame';

        if ($form->isSubmitted() && $form->isValid()) {
            $this->messageBus->dispatch(
                new CollectUserFeedback($data->url, $data->message),
            );

            if ($isModalRequest) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                return $this->render('_feedback_form_success_stream.html.twig');
            }

            $this->addFlash('success', $this->translator->trans('feedback.success_msg'));

            return $this->redirectToRoute('homepage');
        }

        if ($isModalRequest) {
            return $this->render('_feedback_form_modal.html.twig', [
                'feedback_form' => $form,
            ]);
        }

        return $this->render('feedback.html.twig', [
            'feedback_form' => $form,
        ]);
    }
}
