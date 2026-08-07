<?php

use App\Models\SpecialtyRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A technician's proposed set of specialties, awaiting an administrator.
 *
 * The requested set is stored whole rather than as a list of additions and
 * removals: what the technician is asking for is "these are my specialties",
 * and an administrator approving it should get exactly what they were shown,
 * even if the approved set moved underneath in the meantime.
 *
 * Rows are kept after approval or rejection - the audit trail says who decided
 * and when, and the technician's own page can show what they last asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_specialty_requests', function (Blueprint $table): void {
            $table->id('specialty_request_id');

            $table->unsignedBigInteger('technician_id');
            $table->foreign('technician_id')
                ->references('technician_id')
                ->on('tbl_technicians')
                ->cascadeOnDelete();

            /** @see SpecialtyRequest::STATUSES */
            $table->string('status', 20)->default('pending');

            // The whole proposed set, and the approved set at the time of
            // asking, so the reviewer can be shown the difference without
            // recomputing it from a pivot that may since have changed.
            $table->json('requested_skill_ids');
            $table->json('current_skill_ids');

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            // "Does this technician already have one pending?" is asked on
            // every profile load and before every submission.
            $table->index(['technician_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_specialty_requests');
    }
};
