<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\FormData\FeedbackFormData;
use SpeedPuzzling\Web\FormType\FeedbackFormType;
use SpeedPuzzling\Web\Message\CollectUserFeedback;
use SpeedPuzzling\Web\Navigation\HelpPageContext;
use SpeedPuzzling\Web\Navigation\HelpPageContextFactory;
use SpeedPuzzling\Web\Navigation\HelpResponseBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Help sheet's feedback box posts here (docs/features/navigation README §4.5, implementation plan §7, HELP-01b);
 * the route is kept for old links and bookmarks.
 * - POST from the sheet (`Turbo-Frame: modal-frame`): the sheet again - with the errors (422), or in its sent state
 *   (200; frame answers are exempt from the full-page rule) - so the sheet never leaves the page.
 * - POST from the `help` page (no JS, the native app): Post/Redirect/Get - a flash and back to the page the feedback is
 *   about (else `help`); errors re-render the page with 422.
 * - GET: the Help sheet's frame for a frame request (today's top bar / footer Feedback links), else 302 to `help` with
 *   the same page.
 * The page URL in the e-mail is rebuilt from the validated context (absolute, on this host), never taken as sent; a page
 * whose URL holds a private token is named by its token-free path only (HELP review Q2).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class FeedbackController extends AbstractController
{
    /** An unmatched ?url= still tells the team something (a typo'd address, a removed page), marked as such */
    private const int UNVERIFIED_URL_MAX_LENGTH = 300;

    /** Sent from the sheet without a page (the bare help page): the e-mail says so (HELP-02) */
    public const string NO_PAGE = 'no page';

    /** After a private link page's token-free path in the e-mail (HELP review Q2) */
    public const string PRIVATE_LINK_NOTE = '(a private link - its token is not sent)';

    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private HelpPageContextFactory $helpPageContextFactory,
        readonly private HelpResponseBuilder $helpResponseBuilder,
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
    public function __invoke(Request $request): Response
    {
        if (!$request->isMethod(Request::METHOD_POST)) {
            $url = $request->query->all()['url'] ?? null;
            $context = $this->helpPageContextFactory->fromUrlParameter(is_string($url) ? $url : null, $request);

            if ($this->helpResponseBuilder->isFrameRequest($request)) {
                return $this->helpResponseBuilder->response($request, $context);
            }

            $attached = $context->attachedPath();

            return $this->redirectToRoute('help', $attached !== null ? ['url' => $attached] : []);
        }

        $data = new FeedbackFormData();
        $form = $this->createForm(FeedbackFormType::class, $data, [
            'action' => $this->generateUrl('feedback'),
        ]);
        $form->handleRequest($request);

        $context = $this->helpPageContextFactory->fromUrlParameter($data->url, $request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            // A full-page POST never answers 200 (CLAUDE.md, Turbo): the sheet or the page again with the errors
            return $this->helpResponseBuilder->response($request, $context, $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->messageBus->dispatch(
            new CollectUserFeedback($this->pageUrlForMail($context, $data->url, $request), $data->message),
        );

        if ($this->helpResponseBuilder->isFrameRequest($request)) {
            return $this->helpResponseBuilder->response($request, $context, sent: true);
        }

        $this->addFlash('success', $this->translator->trans('feedback.success_msg'));

        // HelpPageContext::$path is ReturnUrl-validated (a same-site path the router matched)
        return new RedirectResponse($context->path ?? $this->generateUrl('help'));
    }

    private function pageUrlForMail(HelpPageContext $context, string $sentUrl, Request $request): string
    {
        if ($context->path !== null) {
            return $request->getSchemeAndHttpHost() . $context->path;
        }

        if ($context->privateLinkPath !== null) {
            return $request->getSchemeAndHttpHost() . $context->privateLinkPath . ' ' . self::PRIVATE_LINK_NOTE;
        }

        $sentUrl = trim($sentUrl);

        if ($sentUrl === '') {
            return self::NO_PAGE;
        }

        return 'unverified: ' . mb_substr($sentUrl, 0, self::UNVERIFIED_URL_MAX_LENGTH);
    }
}
