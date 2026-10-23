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
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('agent_id', 64);
            $table->string('client_request_id', 128);
            $table->char('request_fingerprint', 64);
            $table->string('customer_name', 255);
            $table->date('date_of_birth');
            $table->string('destination', 32);
            $table->date('trip_start');
            $table->date('trip_end');
            $table->string('plan_code', 32);
            $table->unsignedBigInteger('sum_insured');
            $table->unsignedInteger('travel_days');
            $table->unsignedBigInteger('premium_minor');
            $table->char('premium_currency', 3)->default('INR');
            $table->json('premium_breakdown');
            $table->string('status', 32);
            $table->string('provider_reference', 128)->nullable()->unique('uniq_policy_requests_provider_ref');
            $table->string('policy_number', 128)->nullable()->unique('uniq_policy_requests_policy_number');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->json('last_response')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->string('mock_scenario', 64)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            // Named unique compound index for agent idempotency
            $table->unique(['agent_id', 'client_request_id'], 'uniq_policy_requests_agent_client');

            // Compound index for querying by status and creation time
            $table->index(['status', 'created_at'], 'idx_policy_requests_status_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
