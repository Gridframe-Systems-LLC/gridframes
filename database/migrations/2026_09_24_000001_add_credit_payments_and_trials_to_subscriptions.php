<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan can now be paid two ways: by card through Stripe, as before, or from Xlaunch gateway credits.
 * A credit-paid plan has no processor telling us it is paid, so the platform keeps its own record: which
 * tier, which gateway account, and the moment it is paid through. Every charge taken is kept in its own
 * row, which is what the operator's finance page and the monthly settlement read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Null means Stripe, as every subscription before this migration was.
            $table->string('payment_provider')->nullable()->after('team_id');
            $table->string('plan_key')->nullable()->after('payment_provider');
            $table->unsignedBigInteger('gateway_customer_id')->nullable()->after('plan_key');
            $table->timestamp('paid_until')->nullable()->after('gateway_customer_id');
            $table->timestamp('trial_ends_at')->nullable()->after('paid_until');
        });

        Schema::create('subscription_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('plan_key');
            $table->unsignedInteger('amount_cents');
            // The platform's own name for the charge: team, tier and period start. The gateway refuses to
            // take the same reference twice, so a retried renewal cannot charge twice.
            $table->string('charge_ref')->unique();
            $table->unsignedBigInteger('gateway_charge_id')->nullable();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('status'); // paid | failed
            $table->string('failure')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_charges');
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['payment_provider', 'plan_key', 'gateway_customer_id', 'paid_until', 'trial_ends_at']);
        });
    }
};
