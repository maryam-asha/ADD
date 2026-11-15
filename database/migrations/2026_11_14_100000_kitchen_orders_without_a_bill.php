<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An order may stand without a bill: paid at send, or waiting for
     * reception. So the bill is optional, and the order records where it was
     * routed, how the member chose to pay, and when it reached the kitchen
     * (for the accept window). Orders sent before this reached it when
     * sent — except one still pending, which starts its window now: from
     * created_at, the job's first minute after the deploy would cancel, and
     * tell the member about, every pending order older than the window.
     */
    public function up(): void
    {
        Schema::table('kitchen_orders', function (Blueprint $table) {
            $table->string('billable_type', 40)->nullable()->change();
            $table->unsignedBigInteger('billable_id')->nullable()->change();

            $table->string('route', 20)->default('kitchen');
            $table->string('payment_choice', 20)->default('bill');
            $table->timestamp('payment_due_at')->nullable();
            $table->timestamp('reached_kitchen_at')->nullable();
            $table->foreignId('reception_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reception_decided_at')->nullable();
            $table->string('cash_return', 30)->nullable();
            $table->foreignId('cash_return_resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cash_return_resolved_at')->nullable();

            $table->index(['status', 'reached_kitchen_at']);
            $table->index(['branch_id', 'status']);
        });

        DB::table('kitchen_orders')->where('status', 'pending')->update(['reached_kitchen_at' => now()]);
        DB::table('kitchen_orders')->where('status', '!=', 'pending')->update(['reached_kitchen_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // `billable_type` and `billable_id` stay nullable: orders without a
        // bill may exist by now, and making the columns required again would
        // fail on those rows.
        Schema::table('kitchen_orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'reached_kitchen_at']);
            $table->dropIndex(['branch_id', 'status']);
            $table->dropConstrainedForeignId('reception_decided_by');
            $table->dropConstrainedForeignId('cash_return_resolved_by');
            $table->dropColumn([
                'route', 'payment_choice', 'payment_due_at', 'reached_kitchen_at',
                'reception_decided_at', 'cash_return', 'cash_return_resolved_at',
            ]);
        });
    }
};
