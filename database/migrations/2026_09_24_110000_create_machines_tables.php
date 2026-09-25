<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // Set once the machine is registered as an ordinary server; the server's own deletion
            // leaves the machine row for its lifecycle to finish.
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('size_key');
            $table->unsignedInteger('vcpu');
            $table->unsignedInteger('memory_mb');
            $table->unsignedInteger('disk_gb');
            $table->string('incus_project');
            $table->string('incus_name')->unique();
            $table->string('ipv4_private')->nullable();
            $table->string('ipv6_public')->nullable();
            // One of creating, running, stopped, deleting, deleted, failed.
            $table->string('state')->default('creating');
            $table->text('failure')->nullable();
            // One of customer, payment, plan-ended; why the machine was stopped.
            $table->string('stopped_reason')->nullable();
            $table->timestamp('stopped_at')->nullable();
            // When a stopped machine is due for deletion.
            $table->timestamp('delete_after')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['team_id', 'name']);
        });

        Schema::create('machine_usage_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sampled_at');
            // A reading that failed is stored as missing, never as zero: the value columns stay null
            // and the failure columns say why.
            $table->unsignedInteger('memory_mb')->nullable();
            $table->unsignedInteger('disk_mb')->nullable();
            $table->unsignedBigInteger('tx_bytes_total')->nullable();
            // One of transport, timeout, malformed, missing.
            $table->string('failure_kind')->nullable();
            $table->text('failure_detail')->nullable();
        });

        Schema::create('machine_overage_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7); // Y-m
            $table->unsignedInteger('memory_cents');
            $table->unsignedInteger('disk_cents');
            $table->unsignedInteger('transfer_cents');
            $table->unsignedInteger('total_cents');
            // Either stripe or credits.
            $table->string('provider');
            // Idempotent reference, team-{id}-overage-{Y-m}, so a charge can never be taken twice.
            $table->string('charge_ref')->unique();
            $table->string('status');
            $table->string('provider_reference')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_overage_charges');
        Schema::dropIfExists('machine_usage_samples');
        Schema::dropIfExists('machines');
    }
};
