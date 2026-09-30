<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MorphMapServiceProvider aliases Booking and WalkinSession, the coworking-
 * session records end requests and resource consumptions point at, and
 * spatie's performedOn() writes that alias to `activity_log.subject_type`
 * from now on. Rows written before still hold the class name. Both resolve,
 * but a filter on one form would miss the other, so the old rows are
 * rewritten to match.
 *
 * Aliases and class names are literals, as of the day this ran.
 */
return new class extends Migration
{
    private const ALIASES = [
        'booking' => 'App\\Domain\\Booking\\Models\\Booking',
        'walkin_session' => 'App\\Domain\\Booking\\Models\\WalkinSession',
    ];

    public function up(): void
    {
        $this->rewrite(fn (string $alias, string $class) => [$class, $alias]);
    }

    public function down(): void
    {
        $this->rewrite(fn (string $alias, string $class) => [$alias, $class]);
    }

    /**
     * @param  callable(string, string): array{0: string, 1: string}  $direction  [from, to]
     */
    private function rewrite(callable $direction): void
    {
        $table = config('activitylog.table_name', 'activity_log');

        if (! Schema::hasTable($table)) {
            return;
        }

        foreach (self::ALIASES as $alias => $class) {
            [$from, $to] = $direction($alias, $class);

            DB::table($table)->where('subject_type', $from)->update(['subject_type' => $to]);
        }
    }
};
