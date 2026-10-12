<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use SpeedPuzzling\Web\FormData\FeedbackFormData;
use SpeedPuzzling\Web\FormType\FeedbackFormType;
use SpeedPuzzling\Web\Services\PlatformDetector;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Help & feedback, one URL two bodies (docs/features/navigation README §4.5, implementation plan §7) - the answer of
 * `help` (HelpController) and of the feedback box's endpoint `feedback` (FeedbackController):
 * - a `Turbo-Frame: modal-frame` request on the web gets only the sheet's frame (help/_frame.html.twig), never shared-
 *   cached; anything else - no JS, a new tab, the native app - the full page (help/page.html.twig), which stays
 *   shared-cacheable for guests (no explicit cache-control, AnonymousCacheHeadersSubscriber decides). Both carry
 *   `Vary: Turbo-Frame`.
 * - Signed-in viewers get the feedback box: the posted form (its errors) or a fresh one carrying the page's validated path.
 *   `sent` is the sheet's sent state (HELP-01b): confirmation in place, an empty form behind "Send another". Guests get
 *   "Sign in to send feedback" instead - no form, no CSRF token, no session (HELP-03).
 * - The items in their order come from HelpSheetBuilder::items() (HELP-02).
 * No state, no statement of its own (the viewer's profile row is RetrieveLoggedUserProfile's memo). A *Builder, so the
 * Navigation services' glob registers it (config/services.php).
 */
final readonly class HelpResponseBuilder
{
    public const string FRAME = 'modal-frame';

    public function __construct(
        private HelpSheetBuilder $helpSheetBuilder,
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private PlatformDetector $platformDetector,
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urlGenerator,
        private Environment $twig,
    ) {
    }

    /**
     * Inside the app every layer is a screen of its own: always the full page (README §4.14 (a))
     */
    public function isFrameRequest(Request $request): bool
    {
        return $request->headers->get('Turbo-Frame') === self::FRAME && !$this->platformDetector->isNativeApp();
    }

    /**
     * @param null|FormInterface<FeedbackFormData> $feedbackForm the posted feedback form (re-rendered with its errors)
     */
    public function response(
        Request $request,
        HelpPageContext $context,
        null|FormInterface $feedbackForm = null,
        bool $sent = false,
        int $status = Response::HTTP_OK,
    ): Response {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($viewer !== null && $feedbackForm === null) {
            $feedbackForm = $this->feedbackForm($context);
        }

        $parameters = [
            'context' => $context,
            // The sheet in its order (HELP-02): the feedback box or "Sign in to send feedback", the puzzle correction, the
            // registry links, Language, Reload
            'items' => $this->helpSheetBuilder->items($viewer, $context),
            'feedback_form' => $viewer !== null ? $feedbackForm?->createView() : null,
            'sent' => $sent,
        ];

        if ($this->isFrameRequest($request)) {
            $response = new Response($this->twig->render('help/_frame.html.twig', $parameters), $status);
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');
        } else {
            $response = new Response($this->twig->render('help/page.html.twig', $parameters), $status);
        }

        // Same URL, two bodies: a cache must never hand the frame to a full-page visit or the other way round
        $response->setVary('Turbo-Frame', false);

        return $response;
    }

    /**
     * @return FormInterface<FeedbackFormData>
     */
    public function feedbackForm(HelpPageContext $context): FormInterface
    {
        $data = new FeedbackFormData();
        $data->url = $context->path ?? '';

        return $this->formFactory->create(FeedbackFormType::class, $data, [
            'action' => $this->urlGenerator->generate('feedback'),
        ]);
    }
}
