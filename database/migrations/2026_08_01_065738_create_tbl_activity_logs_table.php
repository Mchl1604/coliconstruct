<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail behind every administrative action.
 *
 * Both user columns are nullOnDelete rather than cascade: a log entry has to
 * outlive the accounts it mentions, which is the whole point of an audit
 * trail. The denormalised name columns preserve who it was at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_activity_logs', function (Blueprint $table) {
            $table->id('activity_log_id');

            // The administrator who acted. Null until authentication exists,
            // and null again if that account is ever removed.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');

            $table->string('action', 100);
            $table->string('description');

            $table->foreignId('subject_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_name')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            // The log reads newest-first, filtered by subject or by action.
            $table->index(['created_at']);
            $table->index(['subject_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_activity_logs');
    }
};
