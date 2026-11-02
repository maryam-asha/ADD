<?php

use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Support\CoworkingPlanClassifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Gives every existing plan its coworking terms (CoworkingPlanClassifier
     * — review `plans:coworking-report` first), moves the coworking hours
     * already spent from the space-hours resource grant into the per-person
     * ledger (plan_hour_usages), then drops those consumptions and grants.
     *
     * ONE-WAY in practice: down() only resets the new columns. Take a
     * database backup before deploying — it is the only way back.
     */
    public function up(): void
    {
        $classifier = app(CoworkingPlanClassifier::class);

        DB::transaction(function () use ($classifier) {
            Plan::query()->with('planModule')->orderBy('id')->each(function (Plan $plan) use ($classifier) {
                $result = $classifier->classify($plan);
                $grant = $classifier->hoursGrant($plan);

                DB::table('plans')->where('id', $plan->id)->update([
                    'coworking_seat' => $result['seat']->value,
                    'coworking_hours' => $result['hours'],
                ]);

                if ($grant === null) {
                    return;
                }

                $this->moveConsumptions($grant->id);

                DB::table('plan_resource_grants')->where('id', $grant->id)->delete();
            });
        });
    }

    private function moveConsumptions(int $grantId): void
    {
        $rows = DB::table('resource_consumptions')
            ->join('memberships', 'memberships.id', '=', 'resource_consumptions.membership_id')
            ->where('resource_consumptions.plan_resource_grant_id', $grantId)
            ->select([
                'resource_consumptions.*',
                'memberships.owner_type',
                'memberships.owner_id',
                'memberships.current_period_start',
            ])
            ->get();

        foreach ($rows as $row) {
            $userId = $this->userFor($row);

            if ($userId === null) {
                Log::warning('[Plans] coworking hours with no person to charge were dropped in the conversion', [
                    'resource_consumption_id' => $row->id,
                    'membership_id' => $row->membership_id,
                    'quantity' => $row->quantity,
                ]);

                continue;
            }

            DB::table('plan_hour_usages')->insert([
                'membership_id' => $row->membership_id,
                'user_id' => $userId,
                'plan_space_hour_id' => null,
                'usable_type' => $row->sessionable_type,
                'usable_id' => $row->sessionable_id,
                'cycle_start' => $row->current_period_start,
                'seconds' => (int) round((float) $row->quantity * 3600),
                'created_at' => $row->created_at,
                'updated_at' => now(),
            ]);
        }

        DB::table('resource_consumptions')->where('plan_resource_grant_id', $grantId)->delete();
    }

    /** The session's person; else the subscription's own holder when it is a person's. */
    private function userFor(object $row): ?int
    {
        if ($row->sessionable_type !== null && $row->sessionable_id !== null) {
            $table = match ($row->sessionable_type) {
                'walkin_session' => 'walkin_sessions',
                'booking' => 'bookings',
                default => null,
            };

            $userId = $table === null ? null : DB::table($table)->where('id', $row->sessionable_id)->value('user_id');

            if ($userId !== null) {
                return (int) $userId;
            }
        }

        return $row->owner_type === 'user' ? (int) $row->owner_id : null;
    }

    public function down(): void
    {
        DB::table('plans')->update(['coworking_seat' => 'none', 'coworking_hours' => null]);
    }
};
