<?php

namespace App\Billing;

use App\Enums\PlanInterval;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One billing period of a subscription. Periods are anchored at the start day of the subscription
 * (monthly from 31 January: 31.1.–27.2., 28.2.–30.3., 31.3.–29.4. …), billed in advance when they
 * start; a one-time plan has a single period without end. Dates are inclusive, in the app's timezone.
 */
final readonly class BillingPeriod
{
    /**
     * Upper limit of periods billed per subscription in one run (e.g. after a long pause).
     */
    public const MAX_PER_RUN = 36;

    public function __construct(
        public int $index,
        public CarbonImmutable $start,
        public ?CarbonImmutable $end,
    ) {}

    /**
     * The period with the given index (0 = the first), or null for a plan without interval or a
     * one-time plan after its only period.
     */
    public static function of(Subscription $subscription, int $index): ?self
    {
        $interval = $subscription->plan->interval;
        $anchor = CarbonImmutable::parse($subscription->starts_at)->setTimezone(config('app.timezone'))->startOfDay();

        return match ($interval) {
            PlanInterval::Month => new self($index, $anchor->addMonthsNoOverflow($index), $anchor->addMonthsNoOverflow($index + 1)->subDay()),
            PlanInterval::Year => new self($index, $anchor->addYearsNoOverflow($index), $anchor->addYearsNoOverflow($index + 1)->subDay()),
            PlanInterval::Once => $index === 0 ? new self(0, $anchor, null) : null,
            null => null,
        };
    }

    /**
     * Periods not billed yet that have started by the given day and start before the end of the
     * subscription (the last one is billed in full, there is no proration).
     *
     * @return list<self>
     */
    public static function due(Subscription $subscription, CarbonInterface $today): array
    {
        $plan = $subscription->plan;

        if ($plan->price_cents === null || $plan->price_cents <= 0) {
            return [];
        }

        $today = CarbonImmutable::parse($today)->setTimezone(config('app.timezone'))->startOfDay();
        $periods = [];

        for ($index = $subscription->billed_periods; count($periods) < self::MAX_PER_RUN; $index++) {
            $period = self::of($subscription, $index);

            if ($period === null || $period->start->greaterThan($today)) {
                break;
            }

            if ($subscription->ends_at !== null && $period->start->greaterThanOrEqualTo($subscription->ends_at)) {
                break;
            }

            $periods[] = $period;
        }

        return $periods;
    }
}
