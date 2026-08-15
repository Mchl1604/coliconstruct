<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every project completed before the confirmation workflow existed was
     * closed by somebody at the company and never went past a client.
     *
     * Left null, those rows would read as "completed, method unknown" on the
     * client's page and fall out of every report that groups by method. The
     * honest description of what actually happened is that the completion was
     * confirmed at the point it was recorded, so that is what is written -
     * client_confirmed_at follows completed_at rather than inventing a moment
     * of its own, and client_confirmed_by stays null because nobody clicked.
     */
    public function up(): void
    {
        DB::table('tbl_projects')
            ->where('status', 'completed')
            ->whereNull('completion_method')
            ->update([
                'completion_method' => 'client_confirmed',
                'client_confirmed_at' => DB::raw('completed_at'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('tbl_projects')
            ->where('status', 'completed')
            ->where('completion_method', 'client_confirmed')
            ->whereNull('client_confirmed_by')
            ->update([
                'completion_method' => null,
                'client_confirmed_at' => null,
            ]);
    }
};
