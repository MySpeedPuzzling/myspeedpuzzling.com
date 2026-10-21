<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\FormData\FeedbackFormData;
use SpeedPuzzling\Web\FormType\FeedbackFormType;
use SpeedPuzzling\Web\Message\CollectUserFeedback;
use SpeedPuzzling\Web\Services\PhotoStash\FormPhotoStash;
use SpeedPuzzling\Web\Services\PhotoStash\PhotoStash;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\FeedbackScreenshotLimits;
use SpeedPuzzling\Web\Value\StashedPhoto;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Screenshots (docs/features/feedback-screenshots.md): the browser uploads them in the background into the player's
 * PhotoStash and the form sends `photo_stash[screenshots][]` tokens; one whose upload could not work comes as a file
 * and is kept in the stash here. The message carries tokens only - its handler attaches the pictures to the e-mail.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class FeedbackController extends AbstractController
{
    public const string STASH_FIELD = 'screenshots';

    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PhotoStash $photoStash,
        readonly private RateLimiterFactoryInterface $feedbackLimiter,
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
        $playerId = $this->retrieveLoggedUserProfile->getProfile()?->playerId;
        $tokens = $form->isSubmitted() && $playerId !== null ? $this->sentTokens($request) : [];

        // Counted only for a feedback about to be sent - a refused form costs nothing
        if ($form->isSubmitted() && $form->isValid() && $this->feedbackLimiter->create($playerId ?? $user->getUserIdentifier())->consume()->isAccepted() === false) {
            $form->get('message')->addError(new FormError($this->translator->trans('feedback.rate_limited')));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            [$tokens, $lost] = $this->keepFiles($data, $tokens, $playerId);

            $this->messageBus->dispatch(
                new CollectUserFeedback($data->url ?? '', $data->message, $tokens, $lost),
            );

            if ($isModalRequest) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                return $this->render('_feedback_form_success_stream.html.twig');
            }

            $this->addFlash('success', $this->translator->trans('feedback.success_msg'));

            return $this->redirectToRoute('homepage');
        }

        $keptScreenshots = $form->isSubmitted() ? $this->keptScreenshots($data, $tokens, $playerId) : [];

        if ($isModalRequest) {
            return $this->render('_feedback_form_modal.html.twig', [
                'feedback_form' => $form,
                'kept_screenshots' => $keptScreenshots,
            ]);
        }

        return $this->render('feedback.html.twig', [
            'feedback_form' => $form,
            'kept_screenshots' => $keptScreenshots,
        ]);
    }

    /**
     * @return list<string>
     */
    private function sentTokens(Request $request): array
    {
        $sent = $request->request->all(FormPhotoStash::REQUEST_KEY)[self::STASH_FIELD] ?? [];
        $tokens = [];

        foreach (is_array($sent) ? $sent : [] as $token) {
            if (is_string($token) && PhotoStash::isToken($token) && !in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        return array_slice($tokens, 0, FeedbackScreenshotLimits::MAX_SCREENSHOTS);
    }

    /**
     * Screenshots that came as files go into the stash too, so the handler reads every one the same way
     *
     * @param list<string> $tokens
     * @return array{list<string>, int}
     */
    private function keepFiles(FeedbackFormData $data, array $tokens, null|string $playerId): array
    {
        $lost = 0;
        $room = FeedbackScreenshotLimits::MAX_SCREENSHOTS - count($tokens);

        foreach (array_slice($data->screenshots, 0, max(0, $room)) as $file) {
            $kept = $playerId !== null ? $this->photoStash->keep($file, $playerId) : null;

            if ($kept === null) {
                $lost++;
            } else {
                $tokens[] = $kept->token;
            }
        }

        return [$tokens, $lost];
    }

    /**
     * A refused form shows the screenshots again, so nobody has to add them twice
     *
     * @param list<string> $tokens
     * @return list<StashedPhoto>
     */
    private function keptScreenshots(FeedbackFormData $data, array $tokens, null|string $playerId): array
    {
        if ($playerId === null) {
            return [];
        }

        [$tokens] = $this->keepFiles($data, $tokens, $playerId);
        $kept = [];

        foreach ($tokens as $token) {
            $file = $this->photoStash->restore($token, $playerId);
            $photo = $file !== null ? $this->photoStash->addPreview($token, $playerId, $file) : null;

            if ($photo !== null) {
                $kept[] = $photo;
            }
        }

        return $kept;
    }
}
