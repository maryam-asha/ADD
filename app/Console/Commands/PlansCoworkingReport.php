<?php

namespace App\Console\Commands;

use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Support\CoworkingPlanClassifier;
use Illuminate\Console\Command;

/**
 * Read-only: what the coworking-terms conversion will make of every plan,
 * for the product owner to review before it runs. Writes nothing.
 */
class PlansCoworkingReport extends Command
{
    protected $signature = 'plans:coworking-report';

    protected $description = 'Show how each plan will be classified into the coworking terms (seat, hours, flags to review). Read-only.';

    public function handle(CoworkingPlanClassifier $classifier): int
    {
        $rows = Plan::query()->with('planModule')->orderBy('id')->get()->map(function (Plan $plan) use ($classifier) {
            $result = $classifier->classify($plan);

            return [
                $plan->id,
                $plan->translate('name'),
                $plan->planModule?->slug ?? '-',
                $plan->hasAccessWindow() ? "{$plan->access_window_start}-{$plan->access_window_end}" : 'all day',
                $result['seat']->value,
                $result['hours'] ?? ($result['seat']->value === 'none' ? '-' : 'unlimited'),
                implode(', ', $result['flags']),
            ];
        });

        $this->table(['id', 'plan', 'module', 'window', 'seat', 'hours', 'review'], $rows->all());

        return self::SUCCESS;
    }
}
