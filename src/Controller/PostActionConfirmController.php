<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\PostAction;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The confirmation view of a one-tap action (docs/features/navigation GEST-15, decision 99; PostAction): one button that
 * posts the action's form to its route - what a one-tap link of a browse page opens without JavaScript or in a new tab
 * (post_action_controller.js POSTs straight away everywhere else, the app's web view included), and the confirmation of
 * the action that asks first (Block), opened in the modal frame (a screen of its own inside the app). An allow-listed
 * action with values of their shapes only;
 * anything else is a 404. `noindex, nofollow`; signed-in players only (every action needs one).
 */
final class PostActionConfirmController extends AbstractController
{
    public const string FRAME = 'modal-frame';

    #[Route(
        path: [
            'cs' => '/akce/{action}',
            'en' => '/en/action/{action}',
            'es' => '/es/accion/{action}',
            'ja' => '/ja/アクション/{action}',
            'fr' => '/fr/action/{action}',
            'de' => '/de/aktion/{action}',
        ],
        name: 'post_action_confirm',
        requirements: ['action' => '[a-z_]+'],
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $action): Response
    {
        $postAction = PostAction::tryFrom($action) ?? throw new NotFoundHttpException();
        $query = $request->query->all();
        $routeParameters = $postAction->routeParametersFrom($query) ?? throw new NotFoundHttpException();
        $fields = $postAction->fieldsFrom($query) ?? throw new NotFoundHttpException();
        $back = ReturnUrl::tryFrom(is_string($query['back'] ?? null) ? $query['back'] : null);

        $inFrame = $request->headers->get('Turbo-Frame') === self::FRAME;

        $response = $this->render($inFrame ? 'post_action/frame.html.twig' : 'post_action/page.html.twig', [
            'post_action' => $postAction,
            'target_url' => $this->generateUrl($postAction->route(), $routeParameters),
            'fields' => $fields,
            'csrf_token_id' => $postAction->csrfTokenId($routeParameters),
            'back' => $back?->path,
            // The country the nudge's form picked, named on the view (the route refuses an unknown one)
            'detail' => $postAction === PostAction::SetCountry ? CountryCode::fromCode($fields['country'] ?? '')?->value : null,
        ]);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->setVary('Turbo-Frame', false);

        return $response;
    }
}
