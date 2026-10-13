<?php

namespace Tests\Feature;

use Database\Seeders\BackupTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A database restored from an older dump, outside the system, can come back
 * without tbl_database_backups - and with the migrations table still saying
 * it was made, so `migrate` will not make it again. The seeder puts it back.
 */
class BackupTableSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_backups_table_is_recreated(): void
    {
        Schema::drop(BackupTableSeeder::TABLE);

        $this->seed(BackupTableSeeder::class);

        $this->assertTrue(Schema::hasTable(BackupTableSeeder::TABLE));
        $this->assertTrue(Schema::hasColumns(BackupTableSeeder::TABLE, ['backup_id', 'file_name', 'path', 'kind', 'created_by_name']));
        $this->assertSame(1, DB::table('migrations')->where('migration', BackupTableSeeder::MIGRATION)->count());
    }

    public function test_the_migration_is_recorded_when_the_dump_had_no_record_of_it(): void
    {
        Schema::drop(BackupTableSeeder::TABLE);
        DB::table('migrations')->where('migration', BackupTableSeeder::MIGRATION)->delete();

        $this->seed(BackupTableSeeder::class);

        $this->assertTrue(Schema::hasTable(BackupTableSeeder::TABLE));
        $this->assertSame(1, DB::table('migrations')->where('migration', BackupTableSeeder::MIGRATION)->count());
    }

    public function test_a_table_that_is_there_is_left_alone(): void
    {
        DB::table(BackupTableSeeder::TABLE)->insert([
            'file_name' => 'kept.tar.gz',
            'path' => 'kept.tar.gz',
            'kind' => 'manual',
        ]);

        $this->seed(BackupTableSeeder::class);

        $this->assertSame(1, DB::table(BackupTableSeeder::TABLE)->count());
    }

    public function test_the_backups_page_works_again_afterwards(): void
    {
        Schema::drop(BackupTableSeeder::TABLE);

        $this->seed(BackupTableSeeder::class);

        $this->actingAsSuperAdmin();

        $this->getJson(route('super-admin.configuration.backups.index'))->assertOk();
    }
}
