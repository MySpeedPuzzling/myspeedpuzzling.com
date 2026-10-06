<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetApiUsage;
use SpeedPuzzling\Web\Query\GetApiUsageCallers;
use SpeedPuzzling\Web\Results\ApiUsageCallerRow;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\ApiUsage\ApiUsageChartFactory;
use SpeedPuzzling\Web\Value\ApiCaller;
use SpeedPuzzling\Web\Value\ApiCallerKind;
use SpeedPuzzling\Web\Value\ApiUsageFilter;
use SpeedPuzzling\Web\Value\ApiUsageMonth;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every public API caller (docs/features/api/usage-statistics.md): `?month=`,
 * `?caller=` (an ApiCaller key), `?kind=` (pat|oauth|client), `?operation=`.
 */
#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class ApiUsageController extends AbstractController
{
    public function __construct(
        private readonly GetApiUsage $getApiUsage,
        private readonly GetApiUsageCallers $getApiUsageCallers,
        private readonly ApiUsageChartFactory $chartFactory,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/admin/api-usage', name: 'admin_api_usage', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $month = ApiUsageMonth::fromUserInput($request->query->get('month'), $this->clock->now());

        $caller = null;
        $callerKey = $request->query->get('caller');

        if (is_string($callerKey) && $callerKey !== '') {
            try {
                $caller = ApiCaller::fromKey($callerKey);
            } catch (InvalidArgumentException) {
                $caller = null;
            }
        }

        $kind = ApiCallerKind::tryFrom((string) $request->query->get('kind'));
        $operation = $request->query->get('operation');
        $operation = is_string($operation) && $operation !== '' ? $operation : null;

        $filter = new ApiUsageFilter(
            callerKind: $kind,
            callerKey: $caller?->key(),
            operation: $operation,
        );

        $callers = $this->getApiUsageCallers->forMonth($filter, $month);
        $labels = [];

        foreach ($callers as $row) {
            $labels[$row->caller->key()] = $this->label($row);
        }

        $chart = $caller !== null
            ? $this->chartFactory->byStatus($month, $this->getApiUsage->daily($filter, $month, 'status_class'))
            : $this->chartFactory->byGroup(
                $month,
                $this->getApiUsage->daily($filter, $month, 'caller_key'),
                $labels,
                $this->translator->trans('api_usage.other'),
            );

        return $this->render('admin/api_usage.html.twig', [
            'month' => $month,
            'filter' => $filter,
            'selected_caller' => $caller !== null ? ($labels[$caller->key()] ?? $caller->key()) : null,
            'kinds' => ApiCallerKind::cases(),
            'totals' => $this->getApiUsage->totals($filter, $month),
            'callers' => $callers,
            'labels' => $labels,
            'operations' => $this->getApiUsage->byOperation($filter->withOperation(null), $month),
            'statuses' => $this->getApiUsage->byStatusClass($filter, $month),
            'chart' => $chart,
        ]);
    }

    private function label(ApiUsageCallerRow $row): string
    {
        $client = $row->clientName ?? $row->caller->oauth2ClientIdentifier;
        $player = $row->playerName !== null
            ? $row->playerName . ($row->playerCode !== null ? ' #' . strtoupper($row->playerCode) : '')
            : null;

        return match ($row->caller->kind) {
            ApiCallerKind::PersonalAccessToken => sprintf('%s · %s', $player ?? '?', $row->tokenName ?? 'PAT'),
            ApiCallerKind::OAuth2User => sprintf('%s · %s', $client, $player ?? '?'),
            ApiCallerKind::OAuth2Client => (string) $client,
        };
    }
}
