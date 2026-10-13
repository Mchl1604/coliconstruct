<?php

use App\Services\ClientRecordMerge;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One client row per person, pointed at by every project they have.
 *
 * tbl_clients used to hold one row per project, so a client with three
 * projects was three copies of the same person that could drift apart. Now
 * the project names its client (tbl_projects.client_id) and the person is
 * written down once. Which rows were the same person, and which row's details
 * win, is ClientRecordMerge's - see there.
 *
 * Addresses are stored trimmed and lower-cased from here on, which is what
 * lets the address carry a unique index: two spellings of one mailbox are one
 * client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_projects', function (Blueprint $table): void {
            $table->unsignedBigInteger('client_id')->nullable()->after('project_id');
        });

        app(ClientRecordMerge::class)->merge();

        Schema::table('tbl_projects', function (Blueprint $table): void {
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
            $table->index('client_id');

            // Restrict: a client cannot be deleted out from under their work.
            $table->foreign('client_id')
                ->references('client_id')
                ->on('tbl_clients')
                ->restrictOnDelete();
        });

        Schema::table('tbl_clients', function (Blueprint $table): void {
            $table->dropForeign(['project_id']);
        });

        Schema::table('tbl_clients', function (Blueprint $table): void {
            $table->dropColumn('project_id');
        });

        Schema::table('tbl_clients', function (Blueprint $table): void {
            $table->unique('user_id');
            $table->unique('email_address');
        });

        // The unique index now covers user_id, foreign key included.
        Schema::table('tbl_clients', function (Blueprint $table): void {
            $table->dropIndex(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tbl_clients', function (Blueprint $table): void {
            $table->index('user_id');
        });

        Schema::table('tbl_clients', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
            $table->dropUnique(['email_address']);
        });

        Schema::table('tbl_clients', function (Blueprint $table): void {
            $table->unsignedBigInteger('project_id')->nullable()->after('client_id');
        });

        app(ClientRecordMerge::class)->split();

        Schema::table('tbl_projects', function (Blueprint $table): void {
            $table->dropForeign(['client_id']);
            $table->dropIndex(['client_id']);
        });

        Schema::table('tbl_projects', function (Blueprint $table): void {
            $table->dropColumn('client_id');
        });

        Schema::table('tbl_clients', function (Blueprint $table): void {
            $table->unsignedBigInteger('project_id')->nullable(false)->change();

            $table->foreign('project_id')
                ->references('project_id')
                ->on('tbl_projects')
                ->cascadeOnDelete();
        });
    }
};
