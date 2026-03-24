<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\FeatureRequest;

use SpeedPuzzling\Web\FormData\FeatureRequestCommentFormData;
use SpeedPuzzling\Web\FormType\FeatureRequestCommentFormType;
use SpeedPuzzling\Web\Message\AddFeatureRequestComment;
use SpeedPuzzling\Web\Query\GetFeatureRequestComments;
use SpeedPuzzling\Web\Query\GetFeatureRequestDetail;
use SpeedPuzzling\Web\Query\GetPlayerVoteCountThisMonth;
use SpeedPuzzling\Web\Query\HasFeatureRequestExternalVotes;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class FeatureRequestDetailController extends AbstractController
{
    public function __construct(
        readonly private GetFeatureRequestDetail $getFeatureRequestDetail,
        readonly private GetFeatureRequestComments $getFeatureRequestComments,
        readonly private GetPlayerVoteCountThisMonth $getPlayerVoteCountThisMonth,
        readonly private HasFeatureRequestExternalVotes $hasFeatureRequestExternalVotes,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/navrhy-funkci/{featureRequestId}',
            'en' => '/en/feature-requests/{featureRequestId}',
            'es' => '/es/feature-requests/{featureRequestId}',
            'ja' => '/ja/feature-requests/{featureRequestId}',
            'fr' => '/fr/feature-requests/{featureRequestId}',
            'de' => '/de/feature-requests/{featureRequestId}',
        ],
        name: 'feature_request_detail',
    )]
    public function __invoke(string $featureRequestId, Request $request): Response
    {
        $featureRequest = $this->getFeatureRequestDetail->byId($featureRequestId);
        $comments = $this->getFeatureRequestComments->forFeatureRequest($featureRequestId);

        $votesUsedThisMonth = 0;
        $commentForm = null;

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        if ($loggedPlayer !== null) {
            $votesUsedThisMonth = ($this->getPlayerVoteCountThisMonth)($loggedPlayer->playerId);

            if ($loggedPlayer->activeMembership) {
                $commentFormData = new FeatureRequestCommentFormData();
                $form = $this->createForm(FeatureRequestCommentFormType::class, $commentFormData);
                $form->handleRequest($request);

                if ($form->isSubmitted() && $form->isValid()) {
                    $this->messageBus->dispatch(new AddFeatureRequestComment(
                        authorId: $loggedPlayer->playerId,
                        featureRequestId: $featureRequestId,
                        content: $commentFormData->content,
                    ));

                    $this->addFlash('success', $this->translator->trans('feature_requests.comment_added'));

                    return $this->redirectToRoute('feature_request_detail', ['featureRequestId' => $featureRequestId]);
                }

                $commentForm = $form->createView();
            }
        }

        $hasExternalVotes = ($this->hasFeatureRequestExternalVotes)($featureRequestId);

        return $this->render('feature_request/detail.html.twig', [
            'feature_request' => $featureRequest,
            'comments' => $comments,
            'votes_used_this_month' => $votesUsedThisMonth,
            'comment_form' => $commentForm,
            'has_external_votes' => $hasExternalVotes,
        ]);
    }
}
