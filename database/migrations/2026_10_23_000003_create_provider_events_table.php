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
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64)->default('mock');
            $table->string('provider_event_id', 128);
            $table->foreignUuid('policy_request_id')
                ->nullable()
                ->constrained('policy_requests')
                ->restrictOnDelete();
            $table->string('event_type', 64);
            $table->json('payload');
            $table->unsignedInteger('received_count')->default(1);
            $table->string('processing_result', 64)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Named unique compound index to deduplicate external provider events
            $table->unique(['provider', 'provider_event_id'], 'uniq_provider_events_provider_event');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
