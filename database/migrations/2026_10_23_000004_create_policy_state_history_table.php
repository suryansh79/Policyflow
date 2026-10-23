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
        Schema::create('policy_state_history', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('policy_request_id')
                ->constrained('policy_requests')
                ->restrictOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('reason', 255);
            $table->string('source', 32); // API, SUBMIT, CALLBACK, RECONCILE, SYSTEM
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Named index for audit trail lookups ordered by creation
            $table->index(['policy_request_id', 'id'], 'idx_policy_state_history_req_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy_state_history');
    }
};
