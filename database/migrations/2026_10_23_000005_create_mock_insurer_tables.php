<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Mock insurer policy records table
        Schema::create('mock_insurer_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_reference')->unique('uniq_mock_policies_client_ref');
            $table->string('scenario', 64);
            $table->unsignedInteger('issue_call_count')->default(0);
            $table->string('status', 32);
            $table->string('provider_reference', 128)->nullable()->unique('uniq_mock_policies_provider_ref');
            $table->string('policy_number', 128)->nullable()->unique('uniq_mock_policies_policy_number');
            $table->timestamps();
        });

        // Mock insurer outbox events table
        Schema::create('mock_insurer_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 128)->unique('uniq_mock_events_event_id');
            $table->json('payload');
            $table->unsignedSmallInteger('deliveries_remaining')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mock_insurer_events');
        Schema::dropIfExists('mock_insurer_policies');
    }
};
