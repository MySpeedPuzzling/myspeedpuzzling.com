<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetApiUsage;
use SpeedPuzzling\Web\Query\GetOAuth2ClientRequests;
use SpeedPuzzling\Web\Query\GetPlayerOAuth2Consents;
use SpeedPuzzling\Web\Query\GetPlayerPersonalAccessTokens;
use SpeedPuzzling\Web\Services\ApiUsage\ApiUsageChartFactory;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ApiCaller;
use SpeedPuzzling\Web\Value\ApiUsageFilter;
use SpeedPuzzling\Web\Value\ApiUsageMonth;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A player's own API usage (docs/features/api/usage-statistics.md): their personal
 * access tokens, the apps they connected (their own requests through each) and the
 * apps they registered (summed over every user of the app, never per user).
 * `?show=` picks one of them, `?month=` the month.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ApiUsageController extends AbstractController
{
    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly GetPlayerPersonalAccessTokens $getPlayerPersonalAccessTokens,
        private readonly GetPlayerOAuth2Consents $getPlayerOAuth2Consents,
        private readonly GetOAuth2ClientRequests $getOAuth2ClientRequests,
        private readonly GetApiUsage $getApiUsage,
        private readonly ApiUsageChartFactory $chartFactory,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/ucet/vyuziti-api',
            'en' => '/en/account/api-usage',
            'es' => '/es/cuenta/uso-api',
            'ja' => '/ja/アカウント/API利用状況',
            'fr' => '/fr/compte/utilisation-api',
            'de' => '/de/konto/api-nutzung',
        ],
        name: 'api_usage',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $playerId = $player->playerId;
        $month = ApiUsageMonth::fromUserInput($request->query->get('month'), $this->clock->now());
        $mine = new ApiUsageFilter(playerId: $playerId);
        $requestsByCaller = $this->getApiUsage->requestsByCaller($mine, $month);

        /** @var list<array{key: string, group: string, label: string, detail: null|string, filter: ApiUsageFilter, requests: int}> $options */
        $options = [];

        foreach ($this->getPlayerPersonalAccessTokens->byPlayerId($playerId) as $token) {
            $callerKey = ApiCaller::personalAccessToken($token->id, $playerId)->key();
            $options[] = [
                'key' => 'pat-' . $token->id,
                'group' => 'tokens',
                'label' => $token->name,
                'detail' => $token->tokenPrefix . '…',
                'filter' => new ApiUsageFilter(callerKey: $callerKey),
                'requests' => $requestsByCaller[$callerKey] ?? 0,
            ];
        }

        // A revoked token is listed only for a month it was used in
        foreach ($this->getPlayerPersonalAccessTokens->revokedByPlayerId($playerId) as $token) {
            $callerKey = ApiCaller::personalAccessToken($token->id, $playerId)->key();

            if (!isset($requestsByCaller[$callerKey])) {
                continue;
            }

            $options[] = [
                'key' => 'pat-' . $token->id,
                'group' => 'tokens',
                'label' => $token->name,
                'detail' => $this->translator->trans('api_usage.revoked'),
                'filter' => new ApiUsageFilter(callerKey: $callerKey),
                'requests' => $requestsByCaller[$callerKey],
            ];
        }

        foreach ($this->getPlayerOAuth2Consents->byPlayerId($playerId) as $consent) {
            $callerKey = ApiCaller::oauth2User($consent->clientIdentifier, $playerId)->key();
            $options[] = [
                'key' => 'app-' . $consent->clientIdentifier,
                'group' => 'connected',
                'label' => $consent->clientName,
                'detail' => null,
                'filter' => new ApiUsageFilter(callerKey: $callerKey),
                'requests' => $requestsByCaller[$callerKey] ?? 0,
            ];
        }

        foreach ($this->getOAuth2ClientRequests->byPlayerId($playerId) as $application) {
            if ($application->clientIdentifier === null) {
                continue;
            }

            $filter = ApiUsageFilter::forApp($application->clientIdentifier);
            $options[] = [
                'key' => 'own-' . $application->clientIdentifier,
                'group' => 'own',
                'label' => $application->clientName,
                'detail' => $application->clientIdentifier,
                'filter' => $filter,
                'requests' => array_sum($this->getApiUsage->requestsByCaller($filter, $month)),
            ];
        }

        $selected = null;

        foreach ($options as $option) {
            if ($option['key'] === $request->query->get('show')) {
                $selected = $option;
            }
        }

        $filter = $selected['filter'] ?? $mine;

        if ($selected === null) {
            // All of the player's own usage, one stack per token / connected app
            $labels = [];

            foreach ($options as $option) {
                if ($option['filter']->callerKey !== null) {
                    $labels[$option['filter']->callerKey] = $option['label'];
                }
            }

            $chart = $this->chartFactory->byGroup(
                $month,
                $this->getApiUsage->daily($filter, $month, 'caller_key'),
                $labels,
                $this->translator->trans('api_usage.other'),
            );
        } else {
            $chart = $this->chartFactory->byStatus($month, $this->getApiUsage->daily($filter, $month, 'status_class'));
        }

        return $this->render('api_usage.html.twig', [
            'month' => $month,
            'options' => $options,
            'selected' => $selected,
            'totals' => $this->getApiUsage->totals($filter, $month),
            'operations' => $this->getApiUsage->byOperation($filter, $month),
            'chart' => $chart,
        ]);
    }
}
