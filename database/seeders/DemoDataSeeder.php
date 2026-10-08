<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentHistory;
use App\Models\PhaseStage;
use App\Models\Project;
use App\Models\ProjectCompletionPhoto;
use App\Models\ProjectPhase;
use App\Models\ProjectTechnician;
use App\Models\ProjectType;
use App\Models\Schedule;
use App\Models\ScheduleTechnician;
use App\Models\Skill;
use App\Models\Task;
use App\Models\TaskImage;
use App\Models\TechnicianReport;
use App\Models\TechnicianReportImage;
use App\Models\User;
use App\Services\ProjectCompletion;
use App\Services\ProjectTypeCatalog;
use App\Services\SystemContentService;
use App\Services\TargetDateChange;
use App\Services\UserAccountService;
use App\Support\BusinessTime;
use App\Support\CompanyBranding;
use App\Support\PasswordPolicy;
use App\Support\ReportPdf;
use App\Support\UploadStore;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Demonstration data: fifty accounts and fifty projects covering every status.
 *
 * Run on its own - it is deliberately not part of DatabaseSeeder:
 *
 *     php artisan db:seed --class=DemoDataSeeder --force
 *
 * What it writes is in DemoDataCatalog. How it writes it:
 *
 *   - Add-only. Nothing that is already there is changed or deleted. An
 *     account whose email address already exists is reused as it is, password
 *     included, and if any of the demo projects already exists no projects
 *     are added at all - so running it twice does no harm.
 *   - Every account shares the one password in DEMO_ACCOUNT_PASSWORD, held to
 *     the same policy as every other password, and none is asked to change it
 *     at first sign-in. Registered Users have the current Terms accepted.
 *   - Every date is counted from the office's today, so the statuses come out
 *     right whenever it is run and stay right for fourteen days. Awaiting
 *     Client Confirmation is the exception: if the nightly job runs, it
 *     completes those after the configured number of days (seven by default).
 *   - Teams are built from the seeded lead technicians and technicians only,
 *     each matched to the project's type, and nobody is ever booked on two
 *     projects on the same day.
 *   - Every project gets an assessment report and a quotation, and a
 *     commercial one a contract too: real PDFs on the letterhead. The site
 *     photos in database/seeders/demo-photos go on completed work, on the
 *     last task of each finished stage and on every technician report. Every
 *     file is kept under 50 KB.
 *   - Nothing is emailed or notified. Every step is written straight to the
 *     tables, the way the wizard and the lifecycle actions write them, with
 *     the audit trail to match.
 *   - One transaction. If anything fails, nothing is kept, and the document
 *     files already uploaded for it are deleted again.
 */
class DemoDataSeeder extends Seeder
{
    public const PASSWORD_VARIABLE = 'DEMO_ACCOUNT_PASSWORD';

    /**
     * The largest any uploaded file may be: every document and every photo
     * stays under 50 KB.
     */
    public const MAX_FILE_BYTES = 50_000;

    /**
     * The site photos the completion records, task records and technician
     * reports are illustrated with, relative to database_path().
     */
    public const PHOTO_DIRECTORY = 'seeders/demo-photos';

    /**
     * Whether to upload files: the documents every project carries and the
     * photos on completed work, finished tasks and technician reports.
     * Switched off by the tests that are not about the files, because
     * rendering 120 PDFs is most of the seeder's running time.
     */
    public bool $withFiles = true;

    /**
     * The site photos, by file name, read once.
     *
     * @var array<string, string>
     */
    private array $photos = [];

    private int $photoCounter = 0;

    private CarbonImmutable $today;

    private string $passwordHash;

    /**
     * Every seeded account, by the local part of its email address.
     *
     * @var array<string, User>
     */
    private array $accounts = [];

    /**
     * @var array<int, User>
     */
    private array $admins = [];

    /**
     * The lead technicians and technicians a team can be drawn from.
     *
     * @var array<int, array{user: User, technician_id: int, lead: bool, skills: array<int, string>, order: int}>
     */
    private array $crew = [];

    /**
     * The days each crew member is already booked, as inclusive [first, last]
     * date strings, by technician id.
     *
     * @var array<int, array<int, array{0: string, 1: string}>>
     */
    private array $bookings = [];

    /**
     * How many projects each crew member is on, so the work is spread out.
     *
     * @var array<int, int>
     */
    private array $load = [];

    /**
     * @var array<string, int>
     */
    private array $typeIds = [];

    /**
     * @var array<string, int>
     */
    private array $skillIds = [];

    /**
     * @var array<string, int>
     */
    private array $stageIds = [];

    /**
     * Activity log rows, written in date order once everything else is in.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $activity = [];

    /**
     * Files written to the uploads disk, so a failed run can take them away.
     *
     * @var array<int, string>
     */
    private array $uploaded = [];

    private int $taskCounter = 0;

    public function run(): void
    {
        $this->passwordHash = Hash::make($this->password());

        // Nothing here sends anything, but should a future change make it do
        // so, the fifty example.com addresses are not where it should go.
        config(['mail.default' => 'array']);

        $this->today = Schedule::businessToday();

        try {
            $added = DB::transaction(function (): int {
                $this->ensureProjectTypes();
                $this->seedAccounts();

                $added = 0;

                if ($this->projectsAlreadySeeded()) {
                    $this->command?->warn('Demo projects are already present, so no projects were added.');
                } else {
                    $this->stageIds = PhaseStage::query()->pluck('stage_id', 'name')->map(fn ($id): int => (int) $id)->all();

                    if ($this->withFiles) {
                        $this->loadPhotos();
                    }

                    $this->command?->info($this->withFiles
                        ? 'Adding the demo projects with their documents and photos. This takes a minute or two.'
                        : 'Adding the demo projects.');

                    foreach ($this->projectsInOrder() as $index => $spec) {
                        $this->seedProject($spec, $index);
                        $added++;
                    }
                }

                $this->writeActivity();

                return $added;
            });
        } catch (Throwable $exception) {
            foreach ($this->uploaded as $path) {
                UploadStore::remove($path);
            }

            throw $exception;
        }

        $this->command?->info(sprintf(
            'Demo data ready: %d accounts, %d projects added.',
            count($this->accounts),
            $added
        ));
    }

    /**
     * The local part of every seeded account's email address, with its role,
     * in the order DemoDataCatalog lists them - which is the order the
     * credentials file is written in.
     *
     * @return array<int, array{email: string, role: string, name: string}>
     */
    public static function accountList(): array
    {
        $employees = collect(DemoDataCatalog::employees())->map(fn (array $person): array => [
            'email' => $person['email'].'@'.DemoDataCatalog::EMAIL_DOMAIN,
            'role' => $person['role'],
            'name' => implode(' ', [$person['first_name'], $person['middle_name'], $person['last_name']]),
        ]);

        $clients = collect(DemoDataCatalog::clients())->map(fn (array $client, string $email): array => [
            'email' => $email.'@'.DemoDataCatalog::EMAIL_DOMAIN,
            'role' => User::ROLE_CLIENT,
            'name' => implode(' ', [$client['first_name'], $client['middle_name'], $client['last_name']])
                .($client['company_name'] ? ' ('.$client['company_name'].')' : ''),
        ]);

        return $employees->concat($clients->values())->values()->all();
    }

    // ------------------------------------------------------------------
    // Setup
    // ------------------------------------------------------------------

    /**
     * The shared password, refused outright when it is missing or weak - the
     * same stance SuperAdminSeeder takes, and for the same reason: nothing
     * would ever ask these accounts to replace it.
     */
    private function password(): string
    {
        $password = (string) env(self::PASSWORD_VARIABLE);

        if ($password === '') {
            throw new RuntimeException(self::PASSWORD_VARIABLE.' is not set. Set it to the password every demo account should use.');
        }

        $problem = PasswordPolicy::failureMessage($password);

        if ($problem !== null) {
            throw new RuntimeException(self::PASSWORD_VARIABLE.' does not meet the password policy. '.$problem);
        }

        return $password;
    }

    /**
     * The six project types and their matching specialties.
     *
     * One that is already there is reused, matched loosely enough that the
     * "Heating Ventillation" a site may already hold is taken as the same type
     * rather than added again beside it.
     */
    private function ensureProjectTypes(): void
    {
        $types = ProjectType::query()->get();
        $skills = Skill::query()->get();

        foreach (DemoDataCatalog::PROJECT_TYPES as $name) {
            $type = $types->first(fn (ProjectType $type): bool => $this->looseKey($type->type_name) === $this->looseKey($name))
                ?? app(ProjectTypeCatalog::class)->add($name);

            $skill = $skills->first(fn (Skill $skill): bool => $this->looseKey($skill->skill_name) === $this->looseKey($type->type_name))
                ?? Skill::query()->where('skill_name', $type->type_name)->first()
                ?? Skill::create(['skill_name' => $type->type_name]);

            $this->typeIds[$name] = (int) $type->type_id;
            $this->skillIds[$name] = (int) $skill->skill_id;
        }
    }

    private function looseKey(string $name): string
    {
        $letters = preg_replace('/[^a-z]/', '', mb_strtolower($name)) ?? '';

        return preg_replace('/(.)\1+/', '$1', $letters) ?? $letters;
    }

    private function seedAccounts(): void
    {
        $owner = User::query()->where('role', User::ROLE_SUPER_ADMIN)->orderBy('id')->first();
        $termsVersion = app(SystemContentService::class)->termsVersion();

        foreach (DemoDataCatalog::employees() as $index => $person) {
            $isAdmin = $person['role'] === User::ROLE_ADMIN;

            // Staff were all on the books before the oldest project.
            $createdAt = $this->moment(-420 + $index * 2, '09:30');

            $user = $this->account($person['email'], [
                'first_name' => $person['first_name'],
                'middle_name' => $person['middle_name'],
                'last_name' => $person['last_name'],
                'position' => $person['position'],
                'role' => $person['role'],
                'contact_number' => $this->contactNumber($index),
                'birthdate' => $this->birthdate($index, 24, 28),
            ], 'EMP', $createdAt, $isAdmin ? $owner : ($this->admins[0] ?? $owner), ActivityLog::EMPLOYEE_CREATED);

            if ($isAdmin) {
                $this->admins[] = $user;

                continue;
            }

            if (! $user->needsTechnicianRecord()) {
                continue;
            }

            $technician = $user->technicianRecord();

            if ($user->wasRecentlyCreated) {
                $technician->skills()->syncWithoutDetaching(
                    collect($person['skills'])->map(fn (string $skill): int => $this->skillIds[$skill])->all()
                );
            }

            $this->crew[] = [
                'user' => $user,
                'technician_id' => (int) $technician->technician_id,
                'lead' => $user->isLeadTechnician(),
                'skills' => $person['skills'],
                'order' => count($this->crew),
            ];
        }

        if ($this->admins === []) {
            throw new RuntimeException('No administrator account is available to act on the demo projects.');
        }

        $firstProjectOf = collect(DemoDataCatalog::projects())
            ->groupBy('client')
            ->map(fn (Collection $projects): int => (int) $projects->min('created'));

        $relations = $this->admins[8] ?? $this->admins[0];

        foreach (array_keys(DemoDataCatalog::clients()) as $index => $email) {
            $client = DemoDataCatalog::clients()[$email];
            $createdAt = $this->moment(($firstProjectOf[$email] ?? -30) - 3, '14:00');
            $commercial = $client['client_type'] === 'Commercial';

            $this->account($email, [
                'first_name' => $client['first_name'],
                'middle_name' => $client['middle_name'],
                'last_name' => $client['last_name'],
                'role' => User::ROLE_CLIENT,
                'contact_number' => $this->contactNumber(30 + $index),
                'birthdate' => $this->birthdate(30 + $index, 29, 34),
                'company_name' => $commercial ? $client['company_name'] : null,
                'company_address' => $commercial ? $client['address'] : null,
                'terms_accepted_version' => $termsVersion,
                'terms_accepted_at' => $createdAt->addDay(),
            ], 'CLI', $createdAt, $relations, ActivityLog::CLIENT_CREATED);
        }
    }

    /**
     * One account, or the one already holding the address.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function account(string $localPart, array $attributes, string $codePrefix, CarbonImmutable $createdAt, ?User $creator, string $action): User
    {
        $email = $localPart.'@'.DemoDataCatalog::EMAIL_DOMAIN;
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            return $this->accounts[$localPart] = $existing;
        }

        $user = new User;
        $user->forceFill($attributes + [
            'user_code' => app(UserAccountService::class)->nextUserCode($codePrefix),
            'name' => implode(' ', array_filter([$attributes['first_name'], $attributes['middle_name'], $attributes['last_name']])),
            'email' => $email,
            'status' => User::STATUS_ACTIVE,
            'is_archived' => false,
            'email_verified_at' => $createdAt,
            'must_change_password' => false,
            'created_by' => $creator?->id,
            'password' => $this->passwordHash,
            'last_login_at' => $this->moment(-1 - count($this->accounts) % 6, '08:'.str_pad((string) (count($this->accounts) * 7 % 60), 2, '0', STR_PAD_LEFT)),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        $this->log($action, $createdAt, $creator, sprintf('%s - %s (%s)', $action, $user->fullName(), $user->user_code), $user);

        return $this->accounts[$localPart] = $user;
    }

    private function contactNumber(int $index): string
    {
        $prefixes = ['0917', '0918', '0927', '0928', '0939', '0945', '0956', '0961', '0977', '0995'];

        return $prefixes[$index % count($prefixes)].str_pad((string) ((1357913 + $index * 104729) % 10000000), 7, '0', STR_PAD_LEFT);
    }

    private function birthdate(int $index, int $youngest, int $spread): string
    {
        return $this->today
            ->subYears($youngest + ($index * 7) % $spread)
            ->subDays(($index * 53) % 330)
            ->toDateString();
    }

    private function projectsAlreadySeeded(): bool
    {
        return Project::query()
            ->whereIn('name', array_column(DemoDataCatalog::projects(), 'name'))
            ->exists();
    }

    /**
     * Oldest work first, so each team is picked knowing who is already booked
     * on the days before it. Unscheduled work, which books nobody, goes last.
     *
     * @return array<int, array<string, mixed>>
     */
    private function projectsInOrder(): array
    {
        return collect(DemoDataCatalog::projects())
            ->sortBy(fn (array $spec): int => $spec['ranges'][0][0] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------
    // Projects
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $spec
     */
    private function seedProject(array $spec, int $index): void
    {
        $clientSpec = DemoDataCatalog::clients()[$spec['client']];
        $client = $this->accounts[$spec['client']];
        $admin = $this->admins[$index % count($this->admins)];
        $createdAt = $this->moment($spec['created'], sprintf('%02d:%02d', 8 + $index % 8, ($index * 13) % 60));
        $kind = DemoDataCatalog::kindOf($spec['types'][0]);
        $profile = DemoDataCatalog::workProfiles()[$kind];

        $project = new Project;
        $project->forceFill([
            'name' => $spec['name'],
            'status' => 'unscheduled',
            'quotation' => $spec['quotation'],
            'target_end_date' => $this->date($spec['target']),
            'address' => $spec['address'] ?? $clientSpec['address'],
            'description' => $spec['description'],
            'first_viewed_at' => ($spec['new'] ?? false) ? null : $createdAt->addMinutes(35),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        // Every later write states its own updated_at.
        $project->timestamps = false;

        $project->forceFill([
            'reference_no' => sprintf(
                'PRJ-%s-%s',
                BusinessTime::at($createdAt)->format('Ymd'),
                str_pad((string) $project->project_id, 5, '0', STR_PAD_LEFT)
            ),
        ])->save();

        Client::create([
            'project_id' => $project->project_id,
            'user_id' => $client->id,
            'client_type' => $clientSpec['client_type'],
            'company_name' => $clientSpec['company_name'],
            'surname' => $clientSpec['last_name'],
            'firstname' => $clientSpec['first_name'],
            'middlename' => $clientSpec['middle_name'],
            'fullname' => implode(' ', [$clientSpec['first_name'], $clientSpec['middle_name'], $clientSpec['last_name']]),
            'email_address' => $client->email,
            'contact_number' => $client->contact_number,
        ]);

        $project->projectTypes()->sync(collect($spec['types'])->map(fn (string $type): int => $this->typeIds[$type])->all());

        $schedules = collect([...$spec['ranges'], ...($spec['preserved'] ?? [])])
            ->map(fn (array $range): Schedule => Schedule::create([
                'project_id' => $project->project_id,
                'start_datetime' => $this->date($range[0]).' 00:00:00',
                'end_datetime' => $this->date($range[1]).' 23:59:59',
                'scheduling_mode' => Schedule::MODE_DATE_BASED,
                'status' => 'scheduled',
                'remarks' => 'Created from project wizard',
            ]));

        $team = $this->assignTeam($project, $spec, $schedules, $admin, $createdAt);
        $lead = $team[0]['user'];

        $this->recordCreation($project, $spec, $admin, $createdAt, count($team) - 1);

        if ($this->withFiles) {
            $this->attachDocuments($project, $spec, $clientSpec, $client, $profile, $admin, $lead, $createdAt);
        }

        $this->buildWorkPlan($project, $spec, $profile, $team, $createdAt);
        $this->fileReports($project, $spec, $profile, $lead, $team);
        $this->applyOutcome($project, $spec, $profile, $client, $admin, $lead, $createdAt);
    }

    /**
     * The lead and the supporting technicians, booked on every range.
     *
     * @param  array<string, mixed>  $spec
     * @param  Collection<int, Schedule>  $schedules
     * @return array<int, array{user: User, technician_id: int, lead: bool, skills: array<int, string>, order: int}>
     */
    private function assignTeam(Project $project, array $spec, Collection $schedules, User $admin, CarbonImmutable $createdAt): array
    {
        $occupied = $this->occupiedDays($spec);

        $team = [
            ...$this->pick(true, $spec['types'], $occupied, 1, $spec['name']),
            ...$this->pick(false, $spec['types'], $occupied, $spec['technicians'], $spec['name']),
        ];

        foreach ($team as $member) {
            $assignment = ProjectTechnician::create([
                'project_id' => $project->project_id,
                'technician_id' => $member['technician_id'],
                'team_role' => $member['user']->role,
                'joined_at' => $createdAt->addMinutes(5),
                'joined_by' => $admin->id,
            ]);

            foreach ($schedules as $schedule) {
                ScheduleTechnician::create([
                    'schedule_id' => $schedule->schedule_id,
                    'project_technician_id' => $assignment->project_technician_id,
                ]);
            }

            foreach ($occupied as $range) {
                $this->bookings[$member['technician_id']][] = $range;
            }

            $this->load[$member['technician_id']] = ($this->load[$member['technician_id']] ?? 0) + 1;
        }

        return $team;
    }

    /**
     * The days a project actually takes its crew away: every booked day,
     * except what a hold set aside and what a cancellation called off.
     *
     * @param  array<string, mixed>  $spec
     * @return array<int, array{0: string, 1: string}>
     */
    private function occupiedDays(array $spec): array
    {
        $cancelledOn = $spec['cancelled'] ?? null;

        return collect($spec['ranges'])
            ->map(fn (array $range): array => $cancelledOn === null ? $range : [$range[0], min($range[1], $cancelledOn - 1)])
            ->filter(fn (array $range): bool => $range[0] <= $range[1])
            ->map(fn (array $range): array => [$this->date($range[0]), $this->date($range[1])])
            ->values()
            ->all();
    }

    /**
     * Who joins: free on every occupied day, with the project's specialty,
     * the least busy first. Someone without the specialty is only taken when
     * nobody with it is free.
     *
     * @param  array<int, string>  $types
     * @param  array<int, array{0: string, 1: string}>  $occupied
     * @return array<int, array{user: User, technician_id: int, lead: bool, skills: array<int, string>, order: int}>
     */
    private function pick(bool $lead, array $types, array $occupied, int $wanted, string $project): array
    {
        $free = collect($this->crew)
            ->filter(fn (array $member): bool => $member['lead'] === $lead && $this->isFree($member['technician_id'], $occupied))
            ->sortBy(fn (array $member): int => ($this->load[$member['technician_id']] ?? 0) * 100 + $member['order']);

        $chosen = $free
            ->filter(fn (array $member): bool => array_intersect($types, $member['skills']) !== [])
            ->take($wanted);

        if ($chosen->isEmpty()) {
            $chosen = $free->take(1);
        }

        if ($chosen->isEmpty()) {
            throw new RuntimeException(sprintf(
                'No %s is free for "%s".',
                $lead ? 'lead technician' : 'technician',
                $project
            ));
        }

        return $chosen->values()->all();
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $occupied
     */
    private function isFree(int $technicianId, array $occupied): bool
    {
        foreach ($this->bookings[$technicianId] ?? [] as [$bookedFrom, $bookedTo]) {
            foreach ($occupied as [$from, $to]) {
                if ($from <= $bookedTo && $bookedFrom <= $to) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function recordCreation(Project $project, array $spec, User $admin, CarbonImmutable $createdAt, int $supporting): void
    {
        $targetDates = app(TargetDateChange::class);
        $change = $spec['target_change'] ?? null;

        $targetDates->recordInitial($project, $admin)
            ->forceFill(['created_at' => $createdAt])
            ->save();

        if ($change !== null) {
            $changedAt = $createdAt->addDays($change['days_after_created']);

            $targetDates->recordChange($project, $this->date($spec['target']), $this->date($change['offset']), $change['reason'], $admin)
                ->forceFill(['created_at' => $changedAt])
                ->save();

            $project->forceFill(['target_end_date' => $this->date($change['offset'])])->save();

            $this->log(
                ActivityLog::PROJECT_TARGET_DATE_CHANGED,
                $changedAt,
                $admin,
                $targetDates->describe($project, $this->date($spec['target']), $this->date($change['offset']), $change['reason']),
                record: $project
            );
        }

        $this->log(
            ActivityLog::PROJECT_CREATED,
            $createdAt,
            $admin,
            sprintf("Created project '%s' for %s.", $project->reference_no, $project->name),
            record: $project
        );

        $this->log(
            ActivityLog::LEAD_TECHNICIAN_ASSIGNED,
            $createdAt->addMinutes(1),
            $admin,
            sprintf("Assigned a lead technician and %d supporting technician(s) to '%s'.", $supporting, $project->reference_no),
            record: $project
        );
    }

    // ------------------------------------------------------------------
    // Documents and photos
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $clientSpec
     * @param  array<string, mixed>  $profile
     */
    private function attachDocuments(Project $project, array $spec, array $clientSpec, User $client, array $profile, User $admin, User $lead, CarbonImmutable $createdAt): void
    {
        $documents = ['assessment' => 'Assessment Report', 'quotation' => 'Quotation'];

        if ($clientSpec['client_type'] === 'Commercial') {
            $documents['contract'] = 'Contract';
        }

        // dompdf's working directories, the same ones the reports use.
        ReportPdf::prepareStorage();

        $prefixes = ['assessment' => 'ASR', 'quotation' => 'QTN', 'contract' => 'SVC'];
        $issuedOffsets = ['assessment' => 4, 'quotation' => 2, 'contract' => 0];

        foreach ($documents as $type => $label) {
            $issuedOn = BusinessTime::at($createdAt)->subDays($issuedOffsets[$type]);

            $pdf = $this->renderDocument([
                'company' => CompanyBranding::letterhead(),
                'logoData' => CompanyBranding::logoDataUri(),
                'kind' => $type,
                'title' => $label,
                'number' => sprintf('%s-%s-%05d', $prefixes[$type], $issuedOn->format('Y'), $project->project_id),
                'issuedOn' => $issuedOn->format(BusinessTime::DATE),
                'project' => [
                    'reference_no' => $project->reference_no,
                    'name' => $project->name,
                    'address' => $project->address,
                    'description' => $project->description,
                ],
                'client' => [
                    'name' => $client->fullName(),
                    'company' => $clientSpec['company_name'],
                    'address' => $clientSpec['address'],
                    'email' => $client->email,
                    'contact_number' => $client->contact_number,
                ],
                'types' => implode(', ', $spec['types']),
                'targetDate' => CarbonImmutable::parse($this->date($spec['target']))->format(BusinessTime::DATE),
                'items' => $this->costBreakdown($profile['quotation'], (float) $spec['quotation']),
                'total' => (float) $spec['quotation'],
                'findings' => $profile['findings'],
                'recommendation' => $profile['recommendation'],
                'duration' => $this->durationLabel($spec),
                'preparedBy' => $type === 'assessment'
                    ? ['name' => $lead->fullName(), 'position' => $lead->position ?: $lead->roleLabel()]
                    : ['name' => $admin->fullName(), 'position' => $admin->position ?: $admin->roleLabel()],
            ]);

            $path = UploadStore::folder('documents').'/'.Str::uuid()->toString().'.pdf';

            if (UploadStore::disk()->put($path, $pdf) === false) {
                throw new RuntimeException('A demo document could not be saved.');
            }

            $this->uploaded[] = $path;

            $document = Document::create([
                'project_id' => $project->project_id,
                'document_type' => $type,
                'document_name' => sprintf('%s - %s.pdf', $label, $project->reference_no),
                'document_path' => $path,
                'uploaded_at' => $createdAt,
            ]);

            DocumentHistory::create([
                'project_id' => $project->project_id,
                'document_id' => $document->document_id,
                'document_type' => $type,
                'document_name' => $document->document_name,
                'event' => DocumentHistory::EVENT_UPLOADED,
                'actor_id' => $admin->id,
                'actor_name' => $admin->fullName(),
                'actor_role' => $admin->role,
                'created_at' => $createdAt,
            ]);
        }
    }

    /**
     * One document as PDF bytes, always under MAX_FILE_BYTES.
     *
     * The letterhead JPEG keeps a document near 10 KB. Should a site only have
     * the much larger PNG logo, the document is drawn without it rather than
     * uploaded oversized, and one that is still too large stops the run.
     *
     * @param  array<string, mixed>  $data
     */
    private function renderDocument(array $data): string
    {
        $render = fn (array $data): string => Pdf::loadView('super-admin.project-document-pdf', $data)
            ->setPaper('a4', 'portrait')
            ->output();

        $pdf = $render($data);

        if (strlen($pdf) >= self::MAX_FILE_BYTES && $data['logoData'] !== null) {
            $pdf = $render(['logoData' => null] + $data);
        }

        if (strlen($pdf) >= self::MAX_FILE_BYTES) {
            throw new RuntimeException(sprintf(
                'The %s for %s came out at %d KB, over the %d KB limit.',
                $data['title'],
                $data['project']['reference_no'],
                intdiv(strlen($pdf), 1000),
                intdiv(self::MAX_FILE_BYTES, 1000)
            ));
        }

        return $pdf;
    }

    /**
     * Read the site photos once, refusing any that is not a JPEG or is not
     * under MAX_FILE_BYTES. Checked here rather than shrunk on the fly: the
     * server may well have no image library to shrink them with.
     */
    private function loadPhotos(): void
    {
        $files = glob(database_path(self::PHOTO_DIRECTORY).'/*.jpg') ?: [];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            if (! str_starts_with($contents, "\xFF\xD8\xFF")) {
                throw new RuntimeException(sprintf('%s is not a JPEG.', basename($file)));
            }

            if (strlen($contents) >= self::MAX_FILE_BYTES) {
                throw new RuntimeException(sprintf(
                    '%s is %d KB, over the %d KB limit.',
                    basename($file),
                    intdiv(strlen($contents), 1000),
                    intdiv(self::MAX_FILE_BYTES, 1000)
                ));
            }

            $this->photos[basename($file)] = $contents;
        }

        if ($this->photos === []) {
            throw new RuntimeException('No demo photos were found in database/'.self::PHOTO_DIRECTORY.'.');
        }
    }

    /**
     * Upload a copy of the next site photo, in the order this kind of job
     * prefers them, and return where it was put.
     *
     * Every record gets its own copy rather than sharing one file: removing a
     * photo from one report deletes the file, and must not take it away from
     * another.
     *
     * @param  array<string, mixed>  $profile
     */
    private function storePhoto(string $kind, array $profile): string
    {
        $preferred = array_values(array_filter(
            $profile['photos'],
            fn (string $name): bool => isset($this->photos[$name])
        )) ?: array_keys($this->photos);

        $name = $preferred[$this->photoCounter++ % count($preferred)];
        $path = UploadStore::folder($kind).'/'.Str::uuid()->toString().'.jpg';

        if (UploadStore::disk()->put($path, $this->photos[$name]) === false) {
            throw new RuntimeException('A demo photo could not be saved.');
        }

        $this->uploaded[] = $path;

        return $path;
    }

    /**
     * The quotation split into its lines, the last line taking the rounding
     * so the lines always add up to the quoted total.
     *
     * @param  array<int, array{0: string, 1: float}>  $lines
     * @return array<int, array{description: string, amount: float}>
     */
    private function costBreakdown(array $lines, float $total): array
    {
        $items = [];
        $remaining = $total;

        foreach ($lines as $index => [$description, $share]) {
            $amount = $index === array_key_last($lines) ? $remaining : round($total * $share, 2);
            $remaining = round($remaining - $amount, 2);
            $items[] = ['description' => $description, 'amount' => $amount];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function durationLabel(array $spec): string
    {
        $days = collect([...$spec['ranges'], ...($spec['preserved'] ?? [])])
            ->sum(fn (array $range): int => $range[1] - $range[0] + 1);

        if ($days === 0) {
            return 'To be confirmed once the site is ready.';
        }

        return sprintf('About %d working %s.', $days, $days === 1 ? 'day' : 'days');
    }

    // ------------------------------------------------------------------
    // Phases, tasks and reports
    // ------------------------------------------------------------------

    /**
     * The phase structure and its tasks, for work that has been planned.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $profile
     * @param  array<int, array{user: User, technician_id: int, lead: bool, skills: array<int, string>, order: int}>  $team
     */
    private function buildWorkPlan(Project $project, array $spec, array $profile, array $team, CarbonImmutable $createdAt): void
    {
        if (! $this->isPlanned($spec)) {
            return;
        }

        $lead = $team[0];
        $crew = count($team) > 1 ? array_slice($team, 1) : $team;
        $finalizedAt = $createdAt->addDay()->min($this->now());
        $days = $this->workDays($spec);
        $phases = $profile['phases'];
        $outcome = $spec['scenario'] === 'archived' ? $spec['archived_from'] : $spec['scenario'];
        $taskTotal = (int) collect($phases)->sum(fn (array $phase): int => count($phase['tasks']));
        $taskNumber = 0;

        foreach ($phases as $phaseIndex => $phaseSpec) {
            $phase = ProjectPhase::forceCreate([
                'project_id' => $project->project_id,
                'stage_id' => $this->stageIds[$phaseSpec['title']] ?? null,
                'sequence' => $phaseIndex + 1,
                'title' => $phaseSpec['title'],
                'description' => $phaseSpec['description'],
                'created_at' => $finalizedAt,
                'updated_at' => $finalizedAt,
            ]);

            [$phaseFrom, $phaseTo] = $this->slice(count($days), count($phases), $phaseIndex);
            $phaseDone = true;
            $phaseCompletedAt = null;

            foreach ($phaseSpec['tasks'] as $taskIndex => [$title, $description]) {
                [$from, $to] = $this->slice($phaseTo - $phaseFrom + 1, count($phaseSpec['tasks']), $taskIndex);
                $start = $days[$phaseFrom + $from];
                $due = $days[$phaseFrom + $to];

                // A task never straddles a gap between two booked ranges.
                if ($due['range'] !== $start['range']) {
                    $due = collect($days)->last(fn (array $day): bool => $day['range'] === $start['range']);
                }

                $isFinalTask = $phaseIndex === count($phases) - 1;
                $holder = $isFinalTask ? $lead : $crew[$this->taskCounter++ % count($crew)];
                $state = $this->taskState($spec, $outcome, $due['date'], $taskNumber, $taskTotal);
                $taskNumber++;

                if ($state === 'unassigned') {
                    $holder = null;
                }

                $completedAt = $state === 'completed' ? $this->moment($this->offsetOf($due['date']), '16:30') : null;

                $task = Task::forceCreate([
                    'project_id' => $project->project_id,
                    'phase_id' => $phase->phase_id,
                    'technician_id' => $holder['technician_id'] ?? null,
                    'task_title' => $title,
                    'task_description' => $description,
                    'start_date' => $start['date'],
                    'due_date' => $due['date'],
                    'status' => $state,
                    'completion_notes' => $completedAt ? $this->completionNote() : null,
                    'completed_at' => $completedAt,
                    'completed_by' => $completedAt ? $holder['user']->id : null,
                    'created_at' => $finalizedAt,
                    'updated_at' => $completedAt ?? $finalizedAt,
                ]);

                // The photo a technician attaches when closing off the last
                // task of a stage.
                if ($completedAt !== null && $taskIndex === array_key_last($phaseSpec['tasks']) && $this->withFiles) {
                    TaskImage::forceCreate([
                        'task_id' => $task->task_id,
                        'image_path' => $this->storePhoto('task_images', $profile),
                        'created_at' => $completedAt,
                        'updated_at' => $completedAt,
                    ]);
                }

                if ($state !== 'completed') {
                    $phaseDone = false;
                } else {
                    $phaseCompletedAt = $completedAt->max($phaseCompletedAt ?? $completedAt);
                }
            }

            if ($phaseDone && $phaseCompletedAt !== null) {
                $phase->forceFill([
                    'completed_at' => $phaseCompletedAt->addMinutes(20),
                    'completed_by' => $lead['user']->id,
                    'updated_at' => $phaseCompletedAt->addMinutes(20),
                ])->save();

                $this->log(
                    ActivityLog::PROJECT_PHASE_COMPLETED,
                    $phaseCompletedAt->addMinutes(20),
                    $lead['user'],
                    sprintf('Completed %s on %s.', $phase->label(), $project->reference_no),
                    record: $phase
                );
            }
        }

        $project->forceFill([
            'phase_setup_status' => Project::PHASE_SETUP_FINALIZED,
            'phase_count' => count($phases),
            'phase_setup_finalized_at' => $finalizedAt,
            'phase_setup_finalized_by' => $lead['user']->id,
        ])->save();

        $this->log(
            ActivityLog::PROJECT_PHASES_FINALIZED,
            $finalizedAt,
            $lead['user'],
            sprintf('Finalized the phase structure for %s: %d %s.', $project->reference_no, count($phases), count($phases) === 1 ? 'phase' : 'phases'),
            record: $project
        );
    }

    /**
     * Whether a project has its phases and tasks set out: anything that has
     * started, and the pending jobs the catalog says were planned ahead.
     *
     * @param  array<string, mixed>  $spec
     */
    private function isPlanned(array $spec): bool
    {
        if ($spec['ranges'] === []) {
            return false;
        }

        if ($spec['scenario'] === 'pending') {
            return (bool) ($spec['phases'] ?? false);
        }

        // Called off before the first day: nothing was ever planned out.
        return $this->occupiedDays($spec) !== [];
    }

    /**
     * Every booked day in order, with which range it belongs to.
     *
     * @param  array<string, mixed>  $spec
     * @return array<int, array{date: string, range: int}>
     */
    private function workDays(array $spec): array
    {
        $days = [];

        foreach ([...$spec['ranges'], ...($spec['preserved'] ?? [])] as $rangeIndex => [$from, $to]) {
            for ($offset = $from; $offset <= $to; $offset++) {
                $days[] = ['date' => $this->date($offset), 'range' => $rangeIndex];
            }
        }

        return $days;
    }

    /**
     * The share of $count items that part $part of $parts gets, as inclusive
     * [first, last] indexes. Parts may share an item when there are more parts
     * than items.
     *
     * @return array{0: int, 1: int}
     */
    private function slice(int $count, int $parts, int $part): array
    {
        $first = intdiv($part * $count, $parts);
        $last = max($first, intdiv(($part + 1) * $count, $parts) - 1);

        return [min($first, $count - 1), min($last, $count - 1)];
    }

    /**
     * Where one task stands, given how the project ended up.
     *
     * @param  array<string, mixed>  $spec
     */
    private function taskState(array $spec, string $outcome, string $due, int $taskNumber, int $taskTotal): string
    {
        $today = $this->today->toDateString();

        return match ($outcome) {
            'completed', 'awaiting' => 'completed',
            'ongoing' => $due < $today ? 'completed' : 'pending',
            // Late work: the first part got done, the rest ran out of dates.
            'overdue' => $taskNumber < (int) ceil($taskTotal * 0.6) ? 'completed' : 'pending',
            'on_hold' => $due <= $this->date($spec['held_on']) ? 'completed' : 'pending',
            'cancelled' => $due < $this->date($spec['cancelled']) ? 'completed' : 'cancelled',
            // One booked job in two still has its last task to hand out.
            'pending' => $taskNumber === $taskTotal - 1 && $spec['technicians'] > 1 ? 'unassigned' : 'pending',
            default => 'pending',
        };
    }

    private function completionNote(): string
    {
        $notes = [
            'Completed as planned.',
            'Done. Work area cleaned after.',
            'Completed and checked by the lead technician.',
            'Completed. Client was informed on site.',
        ];

        return $notes[$this->taskCounter % count($notes)];
    }

    /**
     * Progress reports from the lead on the days already worked, and an
     * incident report for whatever went wrong on site, held the job up or
     * paused it. Every report carries a photo.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $profile
     * @param  array<int, array{user: User, technician_id: int, lead: bool, skills: array<int, string>, order: int}>  $team
     */
    private function fileReports(Project $project, array $spec, array $profile, User $lead, array $team): void
    {
        $outcome = $spec['scenario'] === 'archived' ? $spec['archived_from'] : $spec['scenario'];

        if (! in_array($outcome, ['ongoing', 'overdue', 'on_hold', 'awaiting', 'completed'], true)) {
            return;
        }

        $today = $this->today->toDateString();
        $worked = collect($this->workDays(['ranges' => $spec['ranges']]))
            ->pluck('date')
            ->filter(fn (string $date): bool => $date < $today)
            ->values();

        if ($worked->isEmpty()) {
            return;
        }

        $technicianId = $team[0]['technician_id'];
        $reportCount = $outcome === 'completed' ? 1 : min(2, count($profile['progress']));

        for ($report = 0; $report < $reportCount; $report++) {
            $day = $worked[intdiv(($report + 1) * $worked->count(), $reportCount + 1)] ?? $worked->last();

            $this->report($project, $profile, $technicianId, $lead, 'progress', 'Progress Update', $profile['progress'][$report % count($profile['progress'])], $day);
        }

        if (isset($spec['incident'])) {
            $this->report($project, $profile, $technicianId, $lead, 'incident', $spec['incident']['title'], $spec['incident']['description'], $worked[intdiv($worked->count(), 2)]);
        }

        if (isset($spec['delay'])) {
            $this->report($project, $profile, $technicianId, $lead, 'incident', 'Schedule Delay', $spec['delay'], $worked->last());
        }

        if (isset($spec['hold_reason'])) {
            $this->report($project, $profile, $technicianId, $lead, 'incident', 'Work Paused', $spec['hold_reason'], $worked->last());
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function report(Project $project, array $profile, int $technicianId, User $lead, string $type, string $title, string $description, string $date): void
    {
        $at = $this->moment($this->offsetOf($date), '17:45');

        $report = TechnicianReport::forceCreate([
            'project_id' => $project->project_id,
            'technician_id' => $technicianId,
            'submitted_by' => $lead->id,
            'report_type' => $type,
            'report_title' => $title,
            'report_description' => $description,
            'report_date' => $date,
            'is_archived' => false,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        if ($this->withFiles) {
            TechnicianReportImage::forceCreate([
                'technician_report_id' => $report->id,
                'image_path' => $this->storePhoto('report_images', $profile),
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Where each project ends up
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $profile
     */
    private function applyOutcome(Project $project, array $spec, array $profile, User $client, User $admin, User $lead, CarbonImmutable $createdAt): void
    {
        $scenario = $spec['scenario'];
        $outcome = $scenario === 'archived' ? $spec['archived_from'] : $scenario;
        $touched = $createdAt;

        switch ($outcome) {
            case 'pending':
            case 'ongoing':
            case 'overdue':
                $project->forceFill(['status' => $outcome === 'pending' ? 'pending' : 'ongoing'])->save();
                break;

            case 'on_hold':
                $touched = $this->moment($spec['held_on'], '16:00');

                // A hold is Unscheduled underneath: that is what frees the
                // crew while it stands. See ProjectController::putOnHold().
                $project->forceFill([
                    'status' => 'unscheduled',
                    'on_hold' => true,
                    'held_on' => $this->date($spec['held_on']),
                ])->save();

                $this->log(
                    ActivityLog::PROJECT_PUT_ON_HOLD,
                    $touched,
                    $admin,
                    sprintf(
                        "Put project '%s' on hold as of %s. Its team was kept.",
                        $project->reference_no,
                        CarbonImmutable::parse($this->date($spec['held_on']))->format(BusinessTime::DATE)
                    ),
                    record: $project
                );
                break;

            case 'awaiting':
                $requestedAt = $this->now()->subMinutes(5 + $project->project_id % 40);
                $touched = $requestedAt;

                $project->forceFill($this->completionRequest($profile, $lead, $requestedAt) + [
                    'status' => Project::STATUS_AWAITING_CLIENT_CONFIRMATION,
                ])->save();

                $this->attachCompletionPhotos($project, $profile, $requestedAt);
                $this->logCompletionRequest($project, $lead, $requestedAt);
                break;

            case 'completed':
                $touched = $this->complete($project, $spec, $profile, $client, $admin, $lead);
                break;

            case 'cancelled':
                $touched = $this->moment($spec['cancelled'], '15:10');

                $project->forceFill([
                    'status' => 'cancelled',
                    'cancelled_at' => $this->date($spec['cancelled']).' 00:00:00',
                    'cancellation_reason' => $spec['reason'],
                    'cancellation_remarks' => $spec['remarks'],
                ])->save();

                $this->log(
                    ActivityLog::PROJECT_CANCELLED,
                    $touched,
                    $admin,
                    sprintf("Cancelled project '%s'.", $project->reference_no),
                    record: $project
                );
                break;

            default:
                break;
        }

        if ($scenario === 'archived') {
            $touched = $this->moment($spec['archived'], '11:20');

            $project->forceFill([
                'status' => 'archived',
                'is_archived' => true,
                'pre_archive_status' => $project->status,
                'archived_at' => $touched,
                'archived_by' => $admin->id,
            ])->save();

            $this->log(
                ActivityLog::PROJECT_ARCHIVED,
                $touched,
                $admin,
                sprintf("Archived project '%s'.", $project->reference_no),
                record: $project
            );
        }

        $project->forceFill(['updated_at' => $touched->max($createdAt)])->save();
    }

    /**
     * Close a project the way it actually closed: confirmed by the client on
     * the site, recorded by the office after a call or message, or completed
     * by the nightly job when nobody answered.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $profile
     */
    private function complete(Project $project, array $spec, array $profile, User $client, User $admin, User $lead): CarbonImmutable
    {
        $workEnded = end($spec['ranges'])[1];
        $requestedAt = $this->moment($workEnded, '17:30');
        $window = Project::completionConfirmationDays();

        $project->forceFill($this->completionRequest($profile, $lead, $requestedAt))->save();
        $this->attachCompletionPhotos($project, $profile, $requestedAt);
        $this->logCompletionRequest($project, $lead, $requestedAt);

        $reference = $project->reference_no;

        switch ($spec['method']) {
            case Project::METHOD_ADMIN_CONFIRMED:
                $confirmedAt = $requestedAt->addDays(2)->setTime(6, 0);

                $project->forceFill([
                    'client_confirmed_at' => $confirmedAt,
                    'client_confirmed_by' => null,
                    'completion_method' => Project::METHOD_ADMIN_CONFIRMED,
                    'client_confirmation_channel' => $spec['channel'],
                    'client_confirmation_note' => $spec['note'],
                    'client_confirmation_recorded_by' => $admin->id,
                    'client_confirmation_recorded_at' => $confirmedAt->addHour(),
                ]);

                $this->log(
                    ActivityLog::PROJECT_COMPLETION_RECORDED_BY_ADMIN,
                    $confirmedAt->addHour(),
                    $admin,
                    sprintf(
                        "Recorded the client's confirmation of project '%s', given %s on %s "
                            .'(Awaiting Client Confirmation -> Completed, confirmed by an administrator on the client\'s behalf). '
                            .'Reason given: %s',
                        $reference,
                        mb_strtolower(Project::CLIENT_CONFIRMATION_CHANNELS[$spec['channel']]),
                        BusinessTime::at($confirmedAt)->format(BusinessTime::DATE),
                        $spec['note']
                    ),
                    record: $project
                );
                break;

            case Project::METHOD_AUTO_COMPLETED:
                $confirmedAt = $requestedAt->addDays($window)->setTime(0, 5);

                $project->forceFill([
                    'completion_reminder_sent_at' => $requestedAt->addDays(max(1, $window - Project::COMPLETION_REMINDER_LEAD_DAYS))->setTime(0, 0),
                    'client_confirmed_at' => $confirmedAt,
                    'client_confirmed_by' => null,
                    'completion_method' => Project::METHOD_AUTO_COMPLETED,
                ]);

                $this->log(
                    ActivityLog::PROJECT_AUTO_COMPLETED,
                    $confirmedAt,
                    null,
                    sprintf(
                        "Completed project '%s' automatically after %d days without client confirmation "
                            .'(Awaiting Client Confirmation -> Completed).',
                        $reference,
                        $window
                    ),
                    record: $project
                );
                break;

            default:
                $confirmedAt = $requestedAt->addDays(1 + $project->project_id % 3)->setTime(2, 15);

                $project->forceFill([
                    'client_confirmed_at' => $confirmedAt,
                    'client_confirmed_by' => $client->id,
                    'completion_method' => Project::METHOD_CLIENT_CONFIRMED,
                ]);

                $this->log(
                    ActivityLog::PROJECT_COMPLETION_CONFIRMED,
                    $confirmedAt,
                    $client,
                    sprintf(
                        "The client confirmed completion of project '%s' "
                            .'(Awaiting Client Confirmation -> Completed, confirmed by the client).',
                        $reference
                    ),
                    record: $project
                );
                break;
        }

        $project->forceFill(['status' => 'completed'])->save();

        return $confirmedAt;
    }

    /**
     * The columns ProjectCompletion::requestCompletion() writes.
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function completionRequest(array $profile, User $lead, CarbonImmutable $requestedAt): array
    {
        return [
            'on_hold' => false,
            'held_on' => null,
            'completed_at' => BusinessTime::at($requestedAt)->toDateString().' 00:00:00',
            'completion_summary' => $profile['summary'],
            'completion_remarks' => $profile['remarks'],
            'completion_requested_at' => $requestedAt,
            'completion_requested_by' => $lead->id,
        ];
    }

    /**
     * The photos the lead sends with the completion request, which the
     * client looks at before confirming.
     *
     * @param  array<string, mixed>  $profile
     */
    private function attachCompletionPhotos(Project $project, array $profile, CarbonImmutable $requestedAt): void
    {
        if (! $this->withFiles) {
            return;
        }

        for ($photo = 0; $photo < 2; $photo++) {
            ProjectCompletionPhoto::create([
                'project_id' => $project->project_id,
                'photo_path' => $this->storePhoto('completion_photos', $profile),
                'uploaded_at' => $requestedAt,
            ]);
        }
    }

    private function logCompletionRequest(Project $project, User $lead, CarbonImmutable $requestedAt): void
    {
        $this->log(
            ActivityLog::PROJECT_COMPLETION_REQUESTED,
            $requestedAt,
            $lead,
            sprintf(
                "Marked project '%s' complete as of %s and sent it for client confirmation "
                    .'(Ongoing -> Awaiting Client Confirmation). Its schedule now holds %s.',
                $project->reference_no,
                BusinessTime::at($requestedAt)->format(BusinessTime::DATE),
                app(ProjectCompletion::class)->describeRemainingSchedule($project)
            ),
            record: $project
        );
    }

    // ------------------------------------------------------------------
    // Audit trail
    // ------------------------------------------------------------------

    private function log(string $action, CarbonImmutable $at, ?User $actor, string $description, ?User $subject = null, ?Model $record = null): void
    {
        $this->activity[] = [
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->fullName() ?? 'System',
            'actor_role' => $actor?->role,
            'action' => $action,
            'module' => ActivityLog::moduleFor($action),
            'description' => mb_substr($description, 0, 255),
            'subject_id' => $subject?->id,
            'subject_name' => $subject?->fullName(),
            'subject_role' => $subject?->role,
            'record_type' => $record ? class_basename($record) : null,
            'record_id' => $record?->getKey(),
            'ip_address' => null,
            'browser' => null,
            'operating_system' => null,
            'user_agent' => null,
            'created_at' => $at->min($this->now()),
            'updated_at' => $at->min($this->now()),
        ];
    }

    /**
     * Written oldest first, so the log's own ids run in the order things
     * happened.
     */
    private function writeActivity(): void
    {
        collect($this->activity)
            ->sortBy(fn (array $row): int => $row['created_at']->getTimestamp())
            ->map(fn (array $row): array => [
                ...$row,
                'created_at' => $row['created_at']->toDateTimeString(),
                'updated_at' => $row['updated_at']->toDateTimeString(),
            ])
            ->chunk(200)
            ->each(fn (Collection $rows) => ActivityLog::query()->insert($rows->values()->all()));

        $this->activity = [];
    }

    // ------------------------------------------------------------------
    // Dates
    // ------------------------------------------------------------------

    /**
     * The office's calendar day $offset days from today, as Y-m-d.
     */
    private function date(int $offset): string
    {
        return $this->today->addDays($offset)->toDateString();
    }

    private function offsetOf(string $date): int
    {
        return (int) $this->today->diffInDays(CarbonImmutable::parse($date, Schedule::BUSINESS_TIMEZONE)->startOfDay(), false);
    }

    /**
     * A moment on the office's calendar, in the application's timezone, and
     * never later than now - nothing seeded may claim to have happened yet.
     */
    private function moment(int $offset, string $time): CarbonImmutable
    {
        return $this->today
            ->addDays($offset)
            ->setTimeFromTimeString($time)
            ->setTimezone(config('app.timezone'))
            ->min($this->now());
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'));
    }
}
