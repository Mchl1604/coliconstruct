<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The structured half of an incident report: what happened, how bad, where,
 * to whom, and what was done about it - every choice from a fixed list on
 * IncidentReport, so incidents can be counted and compared.
 *
 * One per incident report. The report row itself (tbl_technician_reports)
 * still carries the title, the description, the date and the photographs, so
 * every screen that lists reports keeps working; this hangs off it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_incident_reports', function (Blueprint $table): void {
            $table->id('incident_report_id');

            $table->foreignId('technician_report_id')
                ->unique()
                ->constrained('tbl_technician_reports')
                ->cascadeOnDelete();

            // IncidentReport::TYPES and ::SEVERITIES keys.
            $table->string('incident_type', 30);
            $table->string('severity', 20);
            $table->dateTime('occurred_at');

            // What was being worked on when it happened, if anything.
            $table->foreignId('phase_id')->nullable()->constrained('tbl_project_phases', 'phase_id')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tbl_tasks', 'task_id')->nullOnDelete();

            $table->string('location', 30);
            $table->string('location_details', 255)->nullable();

            // Injury - only for an injury.
            $table->string('injury_body_part', 20)->nullable();
            $table->string('injury_type', 30)->nullable();
            $table->string('injury_treatment', 20)->nullable();

            // Damage - what, whose, and roughly how much.
            $table->string('damage_description', 255)->nullable();
            $table->string('damage_owner', 20)->nullable();
            $table->decimal('damage_cost', 12, 2)->nullable();

            // A refrigerant release.
            $table->string('refrigerant_type', 20)->nullable();
            $table->decimal('refrigerant_amount', 8, 2)->nullable();

            $table->text('immediate_action')->nullable();
            $table->string('cause', 30)->nullable();
            $table->text('preventive_action')->nullable();
            $table->boolean('work_stopped')->default(false);
            $table->boolean('client_notified')->default(false);

            // The office's review: Open, Under Review, Closed.
            $table->string('status', 20)->default('open');
            $table->text('resolution_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'severity']);
        });

        Schema::create('tbl_incident_persons', function (Blueprint $table): void {
            $table->id('incident_person_id');

            $table->foreignId('incident_report_id')
                ->constrained('tbl_incident_reports', 'incident_report_id')
                ->cascadeOnDelete();

            // Somebody on the project's team, or else a name typed in.
            $table->foreignId('technician_id')->nullable()->constrained('tbl_technicians', 'technician_id')->nullOnDelete();
            $table->string('name', 150);
            // IncidentReport::PERSON_TYPES and ::INVOLVEMENTS keys.
            $table->string('person_type', 20);
            $table->string('involvement', 20);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_incident_persons');
        Schema::dropIfExists('tbl_incident_reports');
    }
};
