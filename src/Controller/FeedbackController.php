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

final class FeedbackController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/feedback',
            'en' => '/en/feedback',
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

        if ($form->isSubmitted() && $form->isValid()) {
            $this->messageBus->dispatch(
                new CollectUserFeedback($data->url, $data->message),
            );

            return $this->render('_feedback_form_success.html.twig');
        }

        $isXmlHttpRequest = $request->headers->get('Turbo-Frame');
        $template = $isXmlHttpRequest ? '_feedback_form.html.twig' : 'feedback.html.twig';

        return $this->render($template, [
            'feedback_form' => $form,
        ]);
    }
}
