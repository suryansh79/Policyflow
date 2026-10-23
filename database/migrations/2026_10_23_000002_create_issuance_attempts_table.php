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
        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('policy_request_id')
                ->constrained('policy_requests')
                ->restrictOnDelete();
            $table->string('type', 32); // ISSUE or RECONCILE
            $table->unsignedSmallInteger('sequence');
            $table->string('outcome', 64);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->json('response')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            // Named unique compound index per request, attempt type, and sequence
            $table->unique(['policy_request_id', 'type', 'sequence'], 'uniq_issuance_attempts_req_type_seq');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('issuance_attempts');
    }
};
