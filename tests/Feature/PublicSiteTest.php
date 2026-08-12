<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Schedule;
use App\Models\SystemContent;
use App\Models\Technician;
use App\Models\TechnicianReport;
use App\Models\User;
use App\Services\SystemContentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The public website: what it shows, who it shows projects to, and who is
 * allowed to change any of it.
 */
class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    private static int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::$sequence = 0;
    }

    private function account(string $role, string $email): User
    {
        $sequence = ++self::$sequence;

        return User::create([
            'user_code' => 'EMP-9'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            'name' => ucfirst(str_replace('_', ' ', $role)).' '.$sequence,
            'first_name' => ucfirst(str_replace('_', ' ', $role)),
            'last_name' => 'Person'.$sequence,
            'email' => $email,
            'role' => $role,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'password' => 'correct-password',
        ]);
    }

    /**
     * A project with a client contact carrying the given address.
     */
    private function projectFor(string $email, array $overrides = []): Project
    {
        $project = Project::create(array_merge([
            'name' => 'Aircon Installation - Greenfield Offices',
            'reference_no' => 'COL-2026-'.str_pad((string) (++self::$sequence), 4, '0', STR_PAD_LEFT),
            'status' => 'ongoing',
            'address' => 'Bonifacio Global City',
            'description' => 'Installation of aircon units for new office branch expansion.',
        ], $overrides));

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Commercial',
            'firstname' => 'A',
            'surname' => 'Client',
            'fullname' => 'A Client',
            'email_address' => $email,
        ]);

        return $project;
    }

    private function content(): SystemContentService
    {
        return app(SystemContentService::class);
    }

    // ------------------------------------------------------------------
    // The public pages
    // ------------------------------------------------------------------

    public function test_every_public_page_opens_for_a_guest(): void
    {
        foreach (['landing.home', 'public.about', 'public.contact', 'public.projects'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    /**
     * A fresh installation has nothing stored, and must still render a
     * complete page rather than a skeleton of gaps.
     */
    public function test_the_pages_render_before_anything_is_written(): void
    {
        $this->assertSame(0, SystemContent::count());

        $this->get(route('landing.home'))
            ->assertOk()
            ->assertSee(SystemContent::DEFINITIONS['home.hero_heading']['default']);
    }

    public function test_stored_content_replaces_the_default(): void
    {
        $superAdmin = $this->account('super_admin', 'owner@example.test');

        $this->content()->saveText('home', [
            'home.hero_heading' => 'Cooling built to last',
        ], $superAdmin);

        $this->get(route('landing.home'))
            ->assertOk()
            ->assertSee('Cooling built to last')
            ->assertDontSee(SystemContent::DEFINITIONS['home.hero_heading']['default']);
    }

    public function test_the_navigation_carries_the_four_items(): void
    {
        $response = $this->get(route('landing.home'));

        $response->assertOk()
            ->assertSee('Home')
            ->assertSee('My Projects')
            ->assertSee('About')
            ->assertSee('Contact Us');
    }

    public function test_a_guest_is_offered_login_and_a_client_their_profile(): void
    {
        $this->get(route('landing.home'))
            ->assertOk()
            ->assertSee(route('auth.login'), escape: false);

        $client = $this->account('client', 'client@example.test');

        $this->actingAs($client)
            ->get(route('landing.home'))
            ->assertOk()
            // The bell replaces the Login button once somebody is signed in.
            ->assertSee('data-notification-bell', escape: false)
            ->assertSee($client->fullName());
    }

    // ------------------------------------------------------------------
    // My Projects
    // ------------------------------------------------------------------

    public function test_a_guest_sees_the_invitation_and_no_project_information(): void
    {
        $this->projectFor('client@example.test');

        $response = $this->get(route('public.projects'));

        $response->assertOk()
            ->assertSee('Please log in to view your projects.')
            ->assertSee(route('auth.login'), escape: false)
            // Not one detail of anybody's work reaches a guest.
            ->assertDontSee('Aircon Installation - Greenfield Offices')
            ->assertDontSee('Bonifacio Global City');
    }

    public function test_a_client_sees_only_their_own_projects(): void
    {
        $client = $this->account('client', 'mine@example.test');

        $this->projectFor('mine@example.test', ['name' => 'My Own Project']);
        $this->projectFor('someone.else@example.test', ['name' => 'Somebody Elses Project']);

        $response = $this->actingAs($client)->get(route('public.projects'));

        $response->assertOk()
            ->assertSee('My Own Project')
            ->assertDontSee('Somebody Elses Project');
    }

    /**
     * The link is on the address, so case and stray spaces must not break it.
     */
    public function test_the_email_match_ignores_case_and_padding(): void
    {
        $client = $this->account('client', 'juan.delacruz@example.test');

        $this->projectFor('  Juan.DelaCruz@Example.TEST ', ['name' => 'Matched Anyway']);

        $this->actingAs($client)
            ->get(route('public.projects'))
            ->assertOk()
            ->assertSee('Matched Anyway');
    }

    /**
     * A project created before its client registers becomes theirs the moment
     * the account exists - no manual linking step.
     */
    public function test_a_project_created_before_registration_appears_on_sign_up(): void
    {
        $this->projectFor('later@example.test', ['name' => 'Booked In Advance']);

        // The account is opened afterwards.
        $client = $this->account('client', 'later@example.test');

        $this->actingAs($client)
            ->get(route('public.projects'))
            ->assertOk()
            ->assertSee('Booked In Advance');
    }

    /**
     * A client has no portal of their own any more: signing in puts them on
     * the public website, with their work under My Projects.
     */
    public function test_signing_in_as_a_client_lands_on_the_homepage(): void
    {
        $client = $this->account('client', 'client@example.test');
        $this->projectFor('client@example.test', ['name' => 'Their Own Work']);

        $this->post(route('auth.login.attempt'), [
            'email' => $client->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('landing.home'));

        $this->get(route('landing.home'))->assertOk();

        $this->get(route('public.projects'))
            ->assertOk()
            ->assertSee('Their Own Work');
    }

    /**
     * The header offers Profile, not Notifications, and it goes somewhere.
     */
    public function test_the_header_menu_offers_a_working_profile_page(): void
    {
        $client = $this->account('client', 'client@example.test');

        $html = $this->actingAs($client)->get(route('landing.home'))->assertOk()->getContent();

        $this->assertStringContainsString(route('profile.edit'), $html);

        // The account menu offers Profile in place of Notifications. The bell
        // keeps its own "View All Notifications" link, which is not this.
        $this->assertStringNotContainsString(
            '<a class="dropdown-item" href="'.route('notifications.index').'">',
            $html
        );

        // A client's profile is their details, their password and their
        // picture. Specialties belong to the people running the work.
        $this->actingAs($client)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Personal Information')
            ->assertSee('Change Password')
            ->assertSee($client->email)
            ->assertSee('Upload Picture')
            ->assertDontSee('Specialties');
    }

    public function test_the_profile_page_needs_a_signed_in_account(): void
    {
        $this->get(route('profile.edit'))->assertRedirect(route('auth.login'));
    }

    public function test_the_client_portal_is_gone(): void
    {
        $this->assertFalse(
            app('router')->has('client.dashboard'),
            'The client portal route still exists.'
        );

        $this->get('/client/dashboard')->assertNotFound();
    }

    /**
     * A signed-in employee is not a client, so they get no project information
     * here - but telling them to log in would be nonsense.
     */
    public function test_a_signed_in_employee_is_pointed_at_their_own_portal(): void
    {
        $admin = $this->account('admin', 'admin@example.test');
        $this->projectFor('client@example.test');

        $this->actingAs($admin)
            ->get(route('public.projects'))
            ->assertOk()
            ->assertSee('This page is for client accounts.')
            ->assertDontSee('Please log in to view your projects.')
            ->assertDontSee('Aircon Installation - Greenfield Offices');
    }

    public function test_a_client_with_no_work_sees_an_empty_state(): void
    {
        $client = $this->account('client', 'nothing@example.test');

        $this->actingAs($client)
            ->get(route('public.projects'))
            ->assertOk()
            ->assertSee('No projects yet.');
    }

    /**
     * Active work first, then upcoming, then finished, then cancelled.
     */
    public function test_the_cards_are_grouped_by_state_then_newest_first(): void
    {
        $client = $this->account('client', 'client@example.test');

        $this->projectFor('client@example.test', ['name' => 'Cancelled Work', 'status' => 'cancelled']);
        $this->projectFor('client@example.test', ['name' => 'Finished Work', 'status' => 'completed']);
        $this->projectFor('client@example.test', ['name' => 'Upcoming Work', 'status' => 'pending']);
        $this->projectFor('client@example.test', ['name' => 'Older Active Work', 'status' => 'ongoing']);
        $this->projectFor('client@example.test', ['name' => 'Newer Active Work', 'status' => 'ongoing']);

        $response = $this->actingAs($client)->get(route('public.projects'));

        $order = collect($response->viewData('projects'))->pluck('name')->all();

        $this->assertSame([
            'Newer Active Work',
            'Older Active Work',
            'Upcoming Work',
            'Finished Work',
            'Cancelled Work',
        ], $order);
    }

    public function test_a_card_carries_the_facts_the_design_calls_for(): void
    {
        $client = $this->account('client', 'client@example.test');
        $project = $this->projectFor('client@example.test');

        $lead = $this->account('lead_technician', 'lead@example.test');
        $technician = Technician::create(['account_id' => $lead->id, 'role' => 'lead_technician']);

        ProjectTechnician::create([
            'project_id' => $project->project_id,
            'technician_id' => $technician->technician_id,
        ]);

        Schedule::create([
            'project_id' => $project->project_id,
            'start_datetime' => CarbonImmutable::today()->addDays(3)->toDateString().' 00:00:00',
            'end_datetime' => CarbonImmutable::today()->addDays(9)->toDateString().' 23:59:59',
            'status' => 'scheduled',
            'remarks' => 'Booking',
        ]);

        $response = $this->actingAs($client)->get(route('public.projects'));
        $card = collect($response->viewData('cards'))->first();

        $response->assertOk();
        $this->assertSame($project->reference_no, $card['reference_no']);
        $this->assertSame('Aircon Installation - Greenfield Offices', $card['name']);
        $this->assertSame('Bonifacio Global City', $card['location']);
        $this->assertSame('Ongoing', $card['status_label']);
        $this->assertSame($lead->name, $card['lead_technician']);
        $this->assertNotNull($card['start_date']);
        $this->assertNotNull($card['end_date']);
        $this->assertIsInt($card['progress']);
        $this->assertNotNull($card['updated_at']);
    }

    // ------------------------------------------------------------------
    // Project details
    // ------------------------------------------------------------------

    public function test_a_client_can_open_their_own_project(): void
    {
        $client = $this->account('client', 'client@example.test');
        $project = $this->projectFor('client@example.test');

        $this->actingAs($client)
            ->get(route('public.projects.show', $project->project_id))
            ->assertOk()
            ->assertSee('Aircon Installation - Greenfield Offices');
    }

    /**
     * The tracker leads the page, so the latest word from site has to be the
     * first thing a client reads.
     *
     * report_date carries no time, so reports filed on one day used to come
     * back in whatever order the database chose - which read as oldest first.
     */
    public function test_a_clients_reports_are_listed_newest_first(): void
    {
        $client = $this->account('client', 'client@example.test');
        $project = $this->projectFor('client@example.test');

        $technicianAccount = $this->account('technician', 'tech@example.test');
        $technician = Technician::create([
            'account_id' => $technicianAccount->id,
            'role' => 'technician',
        ]);

        // Deliberately created oldest first, and with two sharing a date so
        // the tie-break is exercised rather than assumed.
        foreach ([
            ['Week one progress', '2026-08-03'],
            ['Week two progress', '2026-08-10'],
            ['Same day follow-up', '2026-08-10'],
        ] as [$title, $date]) {
            TechnicianReport::create([
                'project_id' => $project->project_id,
                'technician_id' => $technician->technician_id,
                'submitted_by' => $technicianAccount->id,
                'report_type' => 'progress',
                'report_title' => $title,
                'report_description' => 'Description',
                'report_date' => $date,
            ]);
        }

        $response = $this->actingAs($client)
            ->get(route('public.projects.show', $project->project_id))
            ->assertOk();

        $this->assertSame(
            ['Same day follow-up', 'Week two progress', 'Week one progress'],
            $response->viewData('reports')->pluck('report_title')->all()
        );
    }

    /**
     * Another client's project must be indistinguishable from one that does
     * not exist.
     */
    public function test_one_client_cannot_open_anothers_project(): void
    {
        $client = $this->account('client', 'mine@example.test');
        $project = $this->projectFor('someone.else@example.test');

        $this->actingAs($client)
            ->get(route('public.projects.show', $project->project_id))
            ->assertNotFound();
    }

    public function test_a_guest_cannot_open_a_project(): void
    {
        $project = $this->projectFor('client@example.test');

        $this->get(route('public.projects.show', $project->project_id))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // System Contents
    // ------------------------------------------------------------------

    public function test_only_a_super_admin_reaches_the_content_editor(): void
    {
        $superAdmin = $this->account('super_admin', 'owner@example.test');
        $admin = $this->account('admin', 'admin@example.test');

        $this->actingAs($superAdmin)
            ->getJson(route('super-admin.configuration.contents.show', 'home'))
            ->assertOk();

        // An Admin runs the business but does not rewrite the shopfront.
        $this->actingAs($admin)
            ->getJson(route('super-admin.configuration.contents.show', 'home'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->putJson(route('super-admin.configuration.contents.update', 'home'), [
                'values' => ['home.hero_heading' => 'Not allowed'],
            ])
            ->assertForbidden();

        $this->assertSame(0, SystemContent::count());
    }

    public function test_the_tab_is_hidden_from_an_admin(): void
    {
        $superAdmin = $this->account('super_admin', 'owner@example.test');
        $admin = $this->account('admin', 'admin@example.test');

        $this->actingAs($superAdmin)
            ->get(route('super-admin.configuration.index'))
            ->assertOk()
            ->assertSee('System Contents');

        $this->actingAs($admin)
            ->get(route('super-admin.configuration.index'))
            ->assertOk()
            ->assertDontSee('System Contents');
    }

    public function test_saving_a_section_stores_only_its_own_fields(): void
    {
        $superAdmin = $this->account('super_admin', 'owner@example.test');

        $this->actingAs($superAdmin)
            ->putJson(route('super-admin.configuration.contents.update', 'home'), [
                'values' => [
                    'home.hero_heading' => 'A new heading',
                    // Belongs to another section, and must be ignored.
                    'contact.email' => 'sneaky@example.test',
                    // Not in the catalogue at all.
                    'home.invented_field' => 'nope',
                ],
            ])
            ->assertOk();

        $this->assertSame('A new heading', SystemContent::where('content_key', 'home.hero_heading')->value('content_value'));
        $this->assertNull(SystemContent::where('content_key', 'contact.email')->first());
        $this->assertNull(SystemContent::where('content_key', 'home.invented_field')->first());
    }

    public function test_saving_records_who_changed_it(): void
    {
        $superAdmin = $this->account('super_admin', 'owner@example.test');

        $this->content()->saveText('home', ['home.hero_heading' => 'Edited'], $superAdmin);

        $row = SystemContent::where('content_key', 'home.hero_heading')->first();

        $this->assertSame($superAdmin->id, (int) $row->updated_by);
        $this->assertSame(SystemContent::SECTION_HOME, $row->section);
        $this->assertNotNull($row->updated_at);
    }

    /**
     * The site reads through a cache, so a save that did not clear it would
     * leave visitors on yesterday's words.
     */
    public function test_saving_refreshes_the_public_site_immediately(): void
    {
        $superAdmin = $this->account('super_admin', 'owner@example.test');

        // Warm the cache with the default.
        $this->get(route('landing.home'))->assertSee(SystemContent::DEFINITIONS['home.hero_heading']['default']);

        $this->actingAs($superAdmin)
            ->putJson(route('super-admin.configuration.contents.update', 'home'), [
                'values' => ['home.hero_heading' => 'Changed just now'],
            ])
            ->assertOk();

        $this->get(route('landing.home'))->assertSee('Changed just now');
    }

    /**
     * The read-through cache has a race: a request that read the table before
     * a save can write its stale map back afterwards. Every write re-primes
     * the cache rather than only clearing it, so there is nothing for a slower
     * request to lose the race to.
     */
    public function test_a_save_leaves_the_fresh_map_in_the_cache(): void
    {
        $superAdmin = $this->account('super_admin', 'owner@example.test');

        $this->content()->saveText('home', ['home.hero_heading' => 'Primed'], $superAdmin);

        // Read straight out of the cache, without touching the table.
        $cached = Cache::get('system_contents.map');

        $this->assertIsArray($cached);
        $this->assertSame('Primed', $cached['home.hero_heading'] ?? null);
    }

    public function test_an_upload_reads_the_previous_path_from_the_table(): void
    {
        Storage::fake('public');

        $superAdmin = $this->account('super_admin', 'owner@example.test');
        $url = route('super-admin.configuration.contents.images.store', 'branding.logo');

        $this->actingAs($superAdmin)->post($url, ['image' => UploadedFile::fake()->image('first.png')]);
        $first = SystemContent::where('content_key', 'branding.logo')->value('content_value');

        // A stale cache must not be able to talk the service into deleting the
        // file the site is actually pointing at.
        Cache::put('system_contents.map', ['branding.logo' => 'system-contents/gone.png'], 60);

        $this->actingAs($superAdmin)->post($url, ['image' => UploadedFile::fake()->image('second.png')]);
        $second = SystemContent::where('content_key', 'branding.logo')->value('content_value');

        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);
        $this->assertNotSame($first, $second);
    }

    public function test_an_unknown_section_is_not_found(): void
    {
        $superAdmin = $this->account('super_admin', 'owner@example.test');

        $this->actingAs($superAdmin)
            ->getJson(route('super-admin.configuration.contents.show', 'invented'))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Images
    // ------------------------------------------------------------------

    public function test_an_image_is_stored_and_shown_on_the_site(): void
    {
        Storage::fake('public');

        $superAdmin = $this->account('super_admin', 'owner@example.test');

        $this->actingAs($superAdmin)
            ->post(route('super-admin.configuration.contents.images.store', 'branding.logo'), [
                'image' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertOk();

        $path = SystemContent::where('content_key', 'branding.logo')->value('content_value');

        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        $this->get(route('landing.home'))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url($path), escape: false);
    }

    public function test_replacing_an_image_deletes_the_one_it_replaced(): void
    {
        Storage::fake('public');

        $superAdmin = $this->account('super_admin', 'owner@example.test');
        $url = route('super-admin.configuration.contents.images.store', 'branding.logo');

        $this->actingAs($superAdmin)->post($url, ['image' => UploadedFile::fake()->image('first.png')]);
        $first = SystemContent::where('content_key', 'branding.logo')->value('content_value');

        $this->actingAs($superAdmin)->post($url, ['image' => UploadedFile::fake()->image('second.png')]);
        $second = SystemContent::where('content_key', 'branding.logo')->value('content_value');

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_removing_an_image_clears_the_field_and_the_file(): void
    {
        Storage::fake('public');

        $superAdmin = $this->account('super_admin', 'owner@example.test');

        $this->actingAs($superAdmin)->post(
            route('super-admin.configuration.contents.images.store', 'branding.logo'),
            ['image' => UploadedFile::fake()->image('logo.png')]
        );

        $path = SystemContent::where('content_key', 'branding.logo')->value('content_value');

        $this->actingAs($superAdmin)
            ->deleteJson(route('super-admin.configuration.contents.images.destroy', 'branding.logo'))
            ->assertOk();

        $this->assertNull(SystemContent::where('content_key', 'branding.logo')->value('content_value'));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_document_is_refused_where_an_image_belongs(): void
    {
        Storage::fake('public');

        $superAdmin = $this->account('super_admin', 'owner@example.test');

        $this->actingAs($superAdmin)
            ->postJson(route('super-admin.configuration.contents.images.store', 'branding.logo'), [
                'image' => UploadedFile::fake()->create('contract.pdf', 40, 'application/pdf'),
            ])
            ->assertStatus(422);

        $this->assertSame(0, SystemContent::count());
    }

    public function test_a_text_field_cannot_be_used_as_an_image_slot(): void
    {
        Storage::fake('public');

        $superAdmin = $this->account('super_admin', 'owner@example.test');

        $this->actingAs($superAdmin)
            ->postJson(route('super-admin.configuration.contents.images.store', 'home.hero_heading'), [
                'image' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // The catalogue itself
    // ------------------------------------------------------------------

    public function test_every_field_belongs_to_a_section_the_editor_offers(): void
    {
        foreach (SystemContent::DEFINITIONS as $key => $definition) {
            $this->assertArrayHasKey(
                $definition['section'],
                SystemContent::SECTIONS,
                $key.' is filed under a section the editor does not show.'
            );
        }
    }

    public function test_a_pipe_separated_field_reads_as_a_list(): void
    {
        $services = $this->content()->lines('home.services');

        $this->assertGreaterThan(0, $services->count());
        $this->assertSame('Aircon Installation', $services->first()['title']);
        $this->assertNotSame('', $services->first()['description']);
    }
}
