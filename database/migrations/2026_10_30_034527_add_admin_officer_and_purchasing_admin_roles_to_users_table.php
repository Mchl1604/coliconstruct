<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Two new office roles beside Super Admin and Admin (now shown as HR
     * Admin): a Purchasing Admin, who works the inventory and nothing else,
     * and an Admin Officer, who works both scheduling and inventory. Which
     * pages each one reaches is App\Support\RoleAccess's; this only lets the
     * column hold them.
     *
     * `admin` keeps its stored value - every account, activity log entry and
     * test already carries it - and only its label changed.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('role', [
                'client',
                'admin',
                'admin_officer',
                'purchasing_admin',
                'super_admin',
                'technician',
                'lead_technician',
            ])->default('client')->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * The two roles go back to `admin`, the nearest office role the old column
     * accepts, rather than leaving accounts holding a value it would refuse.
     */
    public function down(): void
    {
        DB::table('users')
            ->whereIn('role', ['admin_officer', 'purchasing_admin'])
            ->update(['role' => 'admin']);

        Schema::table('users', function (Blueprint $table): void {
            $table->enum('role', [
                'client',
                'admin',
                'super_admin',
                'technician',
                'lead_technician',
            ])->default('client')->change();
        });
    }
};
