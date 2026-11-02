<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\SyncPlanSwitchPortalConfiguration;
use SpeedPuzzling\Web\Results\PlanSwitchPortalConfigurationSync;
use SpeedPuzzling\Web\Value\MembershipPlan;
use Stripe\BillingPortal\Configuration;
use Stripe\Price;
use Stripe\StripeClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The second Stripe portal configuration (docs/features/stripe.md "Switching plans"): everything the default
 * one allows - copied from it on every run, so the two never drift apart - plus the switch between exactly the
 * regular monthly and yearly price. Up to yearly: prorated and charged at once. Down to monthly: at the end of
 * the paid year, so nobody is owed a credit. Only our "Switch plan" button opens it, so the early adopter price
 * (another product) never sees a switch. Found again by its metadata - a second run updates, never duplicates.
 */
#[AsMessageHandler]
readonly final class SyncPlanSwitchPortalConfigurationHandler
{
    public const string METADATA_KEY = 'msp_purpose';
    public const string METADATA_VALUE = 'plan_switch';

    public function __construct(
        private StripeClient $stripeClient,
    ) {
    }

    public function __invoke(SyncPlanSwitchPortalConfiguration $message): PlanSwitchPortalConfigurationSync
    {
        $configurations = $this->stripeClient->billingPortal->configurations->all(['limit' => 100])->data;

        $default = null;
        $existing = null;

        foreach ($configurations as $configuration) {
            if ($configuration->is_default) {
                $default = $configuration;
            }

            if (($configuration->metadata[self::METADATA_KEY] ?? null) === self::METADATA_VALUE && $configuration->active) {
                $existing = $configuration;
            }
        }

        if ($default === null) {
            throw new \RuntimeException('Stripe has no default portal configuration to copy from.');
        }

        $payload = $this->payload($default);

        if ($message->dryRun) {
            return new PlanSwitchPortalConfigurationSync($existing?->id, $existing === null, true, $payload);
        }

        if ($existing !== null) {
            $this->stripeClient->billingPortal->configurations->update($existing->id, $payload);

            return new PlanSwitchPortalConfigurationSync($existing->id, false, false, $payload);
        }

        $created = $this->stripeClient->billingPortal->configurations->create($payload);

        return new PlanSwitchPortalConfigurationSync($created->id, true, false, $payload);
    }

    /**
     * @return array{
     *     name: string,
     *     metadata: array<string, string>,
     *     default_return_url: null|string,
     *     login_page: array{enabled: bool},
     *     features: array{
     *         customer_update: array{enabled: bool, allowed_updates: array<string>},
     *         invoice_history: array{enabled: bool},
     *         payment_method_update: array{enabled: bool},
     *         subscription_cancel: array{enabled: bool, mode: string, proration_behavior: string, cancellation_reason?: array{enabled: bool, options: array<string>}},
     *         subscription_update: array{enabled: bool, default_allowed_updates: array<string>, products: array<array{product: string, prices: array<string>}>, proration_behavior: string, schedule_at_period_end: array{conditions: array<array{type: string}>}},
     *     },
     *     business_profile?: array{headline?: string, privacy_policy_url?: string, terms_of_service_url?: string},
     * }
     */
    private function payload(Configuration $default): array
    {
        $monthly = $this->price(MembershipPlan::Monthly);
        $yearly = $this->price(MembershipPlan::Yearly);

        $product = is_string($monthly->product) ? $monthly->product : $monthly->product->id;
        $yearlyProduct = is_string($yearly->product) ? $yearly->product : $yearly->product->id;

        if ($product !== $yearlyProduct) {
            // Stripe books a downgrade for the period end only between prices of one product
            throw new \RuntimeException('The monthly and the yearly price must belong to the same product.');
        }

        $features = $default->features;
        $cancel = $features->subscription_cancel;

        $subscriptionCancel = [
            'enabled' => $cancel->enabled,
            'mode' => $cancel->mode,
            'proration_behavior' => $cancel->proration_behavior,
        ];

        if ($cancel->cancellation_reason->enabled) {
            $subscriptionCancel['cancellation_reason'] = [
                'enabled' => true,
                'options' => $cancel->cancellation_reason->options,
            ];
        }

        $payload = [
            'name' => 'MySpeedPuzzling - plan switch',
            'metadata' => [self::METADATA_KEY => self::METADATA_VALUE],
            'default_return_url' => $default->default_return_url,
            'login_page' => ['enabled' => false],
            'features' => [
                'customer_update' => [
                    'enabled' => $features->customer_update->enabled,
                    'allowed_updates' => $features->customer_update->allowed_updates,
                ],
                'invoice_history' => ['enabled' => $features->invoice_history->enabled],
                'payment_method_update' => ['enabled' => $features->payment_method_update->enabled],
                'subscription_cancel' => $subscriptionCancel,
                'subscription_update' => [
                    'enabled' => true,
                    'default_allowed_updates' => ['price'],
                    'products' => [[
                        'product' => $product,
                        'prices' => [$monthly->id, $yearly->id],
                    ]],
                    'proration_behavior' => 'always_invoice',
                    'schedule_at_period_end' => [
                        'conditions' => [['type' => 'shortening_interval']],
                    ],
                ],
            ],
        ];

        $businessProfile = [];
        $profile = $default->business_profile;

        if (is_string($profile->headline ?? null)) {
            $businessProfile['headline'] = $profile->headline;
        }

        if (is_string($profile->privacy_policy_url ?? null)) {
            $businessProfile['privacy_policy_url'] = $profile->privacy_policy_url;
        }

        if (is_string($profile->terms_of_service_url ?? null)) {
            $businessProfile['terms_of_service_url'] = $profile->terms_of_service_url;
        }

        if ($businessProfile !== []) {
            $payload['business_profile'] = $businessProfile;
        }

        return $payload;
    }

    private function price(MembershipPlan $plan): Price
    {
        $price = $this->stripeClient->prices->all([
            'lookup_keys' => [$plan->value],
            'active' => true,
            'limit' => 1,
        ])->data[0] ?? null;

        if (!$price instanceof Price) {
            throw new \RuntimeException(sprintf('No active Stripe price with the lookup key "%s".', $plan->value));
        }

        return $price;
    }
}
