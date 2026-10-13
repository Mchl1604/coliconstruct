<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Put back the list of database backups when a restore left it out.
 *
 * The system's own restore never touches tbl_database_backups (see
 * DatabaseBackupService::EXCLUDED_TABLES). A restore done outside it - a
 * database dump imported through the hosting panel or phpMyAdmin, taken
 * before the table existed - can leave the table gone while the migrations
 * table still says it was made, and then `php artisan migrate` will not make
 * it again. Configuration -> System Settings -> Backups stops working.
 *
 *     php artisan db:seed --class=BackupTableSeeder --force
 *
 * Built by the table's own migration rather than a copy of its columns, and
 * recorded as run, so this and `migrate` always agree. Does nothing when the
 * table is there. DemoDataSeeder runs it first.
 */
class BackupTableSeeder extends Seeder
{
    public const TABLE = 'tbl_database_backups';

    public const MIGRATION = '2026_10_12_035616_create_tbl_database_backups_table';

    public function run(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        $migration->up();

        if (! DB::table('migrations')->where('migration', self::MIGRATION)->exists()) {
            DB::table('migrations')->insert([
                'migration' => self::MIGRATION,
                'batch' => (int) DB::table('migrations')->max('batch') + 1,
            ]);
        }

        $this->command?->info('Recreated '.self::TABLE.'. Backups work again.');
    }
}
