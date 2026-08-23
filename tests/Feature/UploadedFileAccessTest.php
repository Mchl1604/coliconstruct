<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\Project;
use App\Models\ProjectTechnician;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Who may read an uploaded file.
 *
 * This used to have no answer. Documents and photographs were written under
 * public/ or behind the storage symlink and served by the web server, so the
 * URL was the whole of the access control: permanent, unauthenticated, and
 * valid for anybody it was ever forwarded to. A client's contract was one
 * entry in a browser history away from the wrong person.
 *
 * Every read now goes through UploadedFileController, and a file inherits the
 * audience of the project it belongs to. These are the cases that must stay
 * true, because none of them is visible from the interface: nothing on screen
 * looks different when a URL is guessable.
 */
class UploadedFileAccessTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $clientEmail): Project
    {
        $project = Project::create([
            'name' => 'Access Project '.uniqid(),
            'reference_no' => 'REF-'.strtoupper(uniqid()),
            'status' => 'ongoing',
            'address' => 'Address',
            'description' => 'Description',
            'quotation' => 1000,
        ]);

        Client::create([
            'project_id' => $project->project_id,
            'client_type' => 'Commercial',
            'company_name' => 'Access Holdings',
            'firstname' => 'Owner',
            'surname' => 'Person',
            'fullname' => 'Owner Person',
            'email_address' => $clientEmail,
            'contact_number' => '09123456789',
        ]);

        return $project->fresh();
    }

    private function document(Project $project): Document
    {
        Storage::disk('uploads')->put('documents/contract.pdf', 'the contract');

        return Document::create([
            'project_id' => $project->project_id,
            'document_type' => 'contract',
            'document_name' => 'contract.pdf',
            'document_path' => 'documents/contract.pdf',
            'uploaded_at' => now(),
        ]);
    }

    private function client(string $email): User
    {
        return User::create([
            'user_code' => 'CLI-'.mb_substr(uniqid(), -4),
            'name' => 'A Client',
            'first_name' => 'A',
            'last_name' => 'Client',
            'email' => $email,
            'role' => User::ROLE_CLIENT,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'password' => 'a-password',
        ]);
    }

    private function technician(string $email, ?Project $assignedTo = null): User
    {
        $user = User::create([
            'user_code' => 'EMP-'.mb_substr(uniqid(), -4),
            'name' => 'A Technician',
            'first_name' => 'A',
            'last_name' => 'Technician',
            'email' => $email,
            'role' => 'technician',
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'password' => 'a-password',
        ]);

        $technician = Technician::create(['account_id' => $user->id, 'role' => 'technician']);

        if ($assignedTo) {
            ProjectTechnician::create([
                'project_id' => $assignedTo->project_id,
                'technician_id' => $technician->technician_id,
            ]);
        }

        return $user->fresh();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('uploads');
    }

    public function test_a_signed_out_visitor_gets_nothing(): void
    {
        $project = $this->project('owner@example.test');
        $document = $this->document($project);

        $this->get($document->url())->assertRedirect(route('auth.login'));
    }

    public function test_the_owning_client_may_read_their_own_document(): void
    {
        $project = $this->project('owner@example.test');
        $document = $this->document($project);

        $this->actingAs($this->client('owner@example.test'))
            ->get($document->url())
            ->assertOk();
    }

    /**
     * The case the old arrangement could not refuse at all.
     */
    public function test_another_client_may_not_read_it(): void
    {
        $project = $this->project('owner@example.test');
        $document = $this->document($project);

        $this->actingAs($this->client('somebody.else@example.test'))
            ->get($document->url())
            ->assertForbidden();
    }

    public function test_a_technician_who_is_not_on_the_project_may_not_read_it(): void
    {
        $project = $this->project('owner@example.test');
        $document = $this->document($project);

        $this->actingAs($this->technician('stranger@example.test'))
            ->get($document->url())
            ->assertForbidden();
    }

    public function test_a_technician_on_the_project_may(): void
    {
        $project = $this->project('owner@example.test');
        $document = $this->document($project);

        $this->actingAs($this->technician('assigned@example.test', $project))
            ->get($document->url())
            ->assertOk();
    }

    public function test_the_office_may_read_anything(): void
    {
        $project = $this->project('owner@example.test');
        $document = $this->document($project);

        $this->actingAsSuperAdmin();

        $this->get($document->url())->assertOk();
    }

    /**
     * Rows outlive their files - everything uploaded before this moved to
     * durable storage is simply gone - so a missing file is a broken image,
     * not a server error.
     */
    public function test_a_row_whose_file_has_gone_is_a_404(): void
    {
        $project = $this->project('owner@example.test');
        $document = $this->document($project);

        Storage::disk('uploads')->delete('documents/contract.pdf');

        $this->actingAsSuperAdmin();

        $this->get($document->url())->assertNotFound();
    }

    /**
     * Website imagery is the deliberate exception: it has to load for people
     * who have not signed in, because that is what it is for.
     */
    public function test_website_imagery_is_served_without_signing_in(): void
    {
        Storage::disk('uploads')->put('system-contents/logo.png', 'a logo');

        \App\Models\SystemContent::create([
            'content_key' => 'branding.logo',
            'content_value' => 'system-contents/logo.png',
            'content_type' => 'image',
            'section' => 'branding',
        ]);

        $this->get(route('media.system', ['key' => 'branding.logo']))->assertOk();
    }
}
