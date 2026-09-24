<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sui_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('sui_address', 66)->unique();
            $table->string('public_key', 64);
            $table->string('sui_ns_name')->nullable();
            $table->timestamp('verified_at');
            // Set when the owning team releases the binding. The row stays: a soul-bound address
            // binds to one team ever, but the same team may re-bind after rotating its key.
            $table->timestamp('unbound_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sui_identities');
    }
};
