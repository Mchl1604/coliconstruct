<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The migration that turns tbl_clients from one row per project into one row
 * per person - see ClientRecordMerge.
 *
 * Every test rolls that one migration back, writes rows in the old shape, and
 * runs it again: the journey the live database takes. DatabaseMigrations
 * rather than RefreshDatabase, because the schema itself changes and SQLite
 * will not rebuild tables with foreign keys inside the test's transaction.
 */
class ClientRecordMergeTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRATION = 'database/migrations/2026_10_13_123005_make_tbl_clients_one_row_per_person.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful()->run();
    }

    private function migrate(): void
    {
        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful()->run();
    }

    /**
     * A project as the old schema held it. Older projects get earlier
     * timestamps, which is what decides whose details win.
     */
    private function oldProject(string $name, string $createdAt): int
    {
        return DB::table('tbl_projects')->insertGetId([
            'name' => $name,
            'status' => 'ongoing',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ], 'project_id');
    }

    /**
     * A client row as the old schema held it: one per project.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function oldClient(int $projectId, array $attributes = []): int
    {
        return DB::table('tbl_clients')->insertGetId($attributes + [
            'project_id' => $projectId,
            'client_type' => 'Residential',
            'firstname' => 'Juan',
            'surname' => 'Dela Cruz',
            'fullname' => 'Juan Dela Cruz',
            'email_address' => null,
            'contact_number' => '09123456789',
        ], 'client_id');
    }

    private function clientOf(int $projectId): object
    {
        $clientId = DB::table('tbl_projects')->where('project_id', $projectId)->value('client_id');

        return DB::table('tbl_clients')->where('client_id', $clientId)->first();
    }

    public function test_one_address_written_differently_becomes_one_client(): void
    {
        $older = $this->oldProject('Older', '2026-01-10 08:00:00');
        $newer = $this->oldProject('Newer', '2026-03-10 08:00:00');

        $this->oldClient($older, ['email_address' => ' Juan@Example.COM ']);
        $this->oldClient($newer, ['email_address' => 'juan@example.com']);

        $this->migrate();

        $this->assertSame(1, DB::table('tbl_clients')->count());
        $this->assertSame($this->clientOf($older)->client_id, $this->clientOf($newer)->client_id);
        // Stored the way it is compared from now on.
        $this->assertSame('juan@example.com', $this->clientOf($older)->email_address);
    }

    public function test_the_most_recent_projects_details_win(): void
    {
        $older = $this->oldProject('Older', '2026-01-10 08:00:00');
        $newer = $this->oldProject('Newer', '2026-03-10 08:00:00');

        $this->oldClient($older, [
            'email_address' => 'maria@example.com',
            'fullname' => 'Maria Old Name',
            'contact_number' => '09110000000',
        ]);
        $kept = $this->oldClient($newer, [
            'email_address' => 'MARIA@example.com',
            'fullname' => 'Maria New Name',
            'contact_number' => '09220000000',
        ]);

        $this->migrate();

        $client = $this->clientOf($older);

        $this->assertSame($kept, (int) $client->client_id);
        $this->assertSame('Maria New Name', $client->fullname);
        $this->assertSame('09220000000', $client->contact_number);
    }

    public function test_rows_linked_by_account_or_address_are_one_person(): void
    {
        $account = User::factory()->create();

        // First and second share an address; first and third share an
        // account. So all three are one person, though second and third
        // share nothing directly.
        $first = $this->oldProject('First', '2026-01-10 08:00:00');
        $second = $this->oldProject('Second', '2026-02-10 08:00:00');
        $third = $this->oldProject('Third', '2026-03-10 08:00:00');

        $this->oldClient($first, ['email_address' => 'old@example.com', 'user_id' => $account->id]);
        $this->oldClient($second, ['email_address' => 'old@example.com']);
        $this->oldClient($third, ['email_address' => 'new@example.com', 'user_id' => $account->id]);

        $this->migrate();

        $this->assertSame(1, DB::table('tbl_clients')->count());

        $client = $this->clientOf($first);

        $this->assertSame($client->client_id, $this->clientOf($second)->client_id);
        $this->assertSame($client->client_id, $this->clientOf($third)->client_id);
        $this->assertSame('new@example.com', $client->email_address);
        $this->assertSame($account->id, (int) $client->user_id);
    }

    public function test_an_account_on_any_row_is_kept(): void
    {
        $account = User::factory()->create();

        $older = $this->oldProject('Older', '2026-01-10 08:00:00');
        $newer = $this->oldProject('Newer', '2026-03-10 08:00:00');

        $this->oldClient($older, ['email_address' => 'ana@example.com', 'user_id' => $account->id]);
        $this->oldClient($newer, [
            'email_address' => 'ana@example.com',
            'user_unlinked_at' => '2026-03-11 08:00:00',
        ]);

        $this->migrate();

        $client = $this->clientOf($newer);

        $this->assertSame($account->id, (int) $client->user_id);
        $this->assertNull($client->user_unlinked_at);
    }

    public function test_different_people_stay_apart(): void
    {
        $one = $this->oldProject('One', '2026-01-10 08:00:00');
        $two = $this->oldProject('Two', '2026-02-10 08:00:00');
        $three = $this->oldProject('Three', '2026-03-10 08:00:00');
        $four = $this->oldProject('Four', '2026-04-10 08:00:00');

        // The same name is not the same person.
        $this->oldClient($one, ['email_address' => 'juan.one@example.com']);
        $this->oldClient($two, ['email_address' => 'juan.two@example.com']);
        // Neither an address nor an account: each is a person of their own.
        $this->oldClient($three, ['email_address' => null]);
        $this->oldClient($four, ['email_address' => '   ']);

        $this->migrate();

        $this->assertSame(4, DB::table('tbl_clients')->count());
        $this->assertNull($this->clientOf($four)->email_address);
    }

    public function test_one_person_holding_two_accounts_is_logged(): void
    {
        Log::spy();

        $first = User::factory()->create();
        $second = User::factory()->create();

        $older = $this->oldProject('Older', '2026-01-10 08:00:00');
        $newer = $this->oldProject('Newer', '2026-03-10 08:00:00');

        $this->oldClient($older, ['email_address' => 'shared@example.com', 'user_id' => $first->id]);
        $this->oldClient($newer, ['email_address' => 'shared@example.com', 'user_id' => $second->id]);

        $this->migrate();

        $this->assertSame(1, DB::table('tbl_clients')->count());
        // The newest row's account is the one kept.
        $this->assertSame($second->id, (int) $this->clientOf($older)->user_id);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'more than one account'))
            ->once();
    }

    public function test_a_project_with_no_client_is_given_one(): void
    {
        $orphan = $this->oldProject('Orphan Project', '2026-01-10 08:00:00');

        $this->migrate();

        $client = $this->clientOf($orphan);

        $this->assertNotNull($client);
        $this->assertSame('Orphan Project', $client->fullname);
    }

    public function test_the_new_schema_points_projects_at_clients(): void
    {
        $this->oldClient($this->oldProject('Only', '2026-01-10 08:00:00'), ['email_address' => 'only@example.com']);

        $this->migrate();

        $this->assertFalse(Schema::hasColumn('tbl_clients', 'project_id'));
        $this->assertTrue(Schema::hasColumn('tbl_projects', 'client_id'));
        $this->assertSame(0, DB::table('tbl_projects')->whereNull('client_id')->count());
    }

    public function test_rolling_back_gives_every_project_its_own_row_again(): void
    {
        $older = $this->oldProject('Older', '2026-01-10 08:00:00');
        $newer = $this->oldProject('Newer', '2026-03-10 08:00:00');

        $this->oldClient($older, ['email_address' => 'pedro@example.com']);
        $this->oldClient($newer, ['email_address' => 'pedro@example.com', 'fullname' => 'Pedro Penduko']);

        $this->migrate();
        $this->assertSame(1, DB::table('tbl_clients')->count());

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful()->run();

        $this->assertFalse(Schema::hasColumn('tbl_projects', 'client_id'));
        $this->assertSame(2, DB::table('tbl_clients')->count());

        foreach ([$older, $newer] as $projectId) {
            $this->assertDatabaseHas('tbl_clients', [
                'project_id' => $projectId,
                'fullname' => 'Pedro Penduko',
                'email_address' => 'pedro@example.com',
            ]);
        }

        // And forward again, which merges the copies back into one. This also
        // leaves the teardown's rollback to undo only this migration.
        $this->migrate();

        $this->assertSame(1, DB::table('tbl_clients')->count());
    }
}
