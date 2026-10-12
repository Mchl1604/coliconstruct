<?php

namespace App\Models;

use App\Support\DisplayCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TechnicianReport extends Model
{
    protected $table = 'tbl_technician_reports';

    protected $primaryKey = 'id';

    protected $fillable = [
        'project_id',
        'technician_id',
        'submitted_by',
        'report_title',
        'report_description',
        'report_date',
        'report_type',
        'is_archived',
        'archived_at',
        'archived_by',
    ];

    protected $casts = [
        'report_date' => 'date',
        'is_archived' => 'boolean',
        'archived_at' => 'datetime',
    ];

    /**
     * Report types the table's enum accepts, with their display labels.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'progress' => 'Progress Report',
        'incident' => 'Incident Report',
    ];

    /**
     * How the report's key is printed, e.g. RPT-0007.
     */
    public function displayCode(): string
    {
        return DisplayCode::format(DisplayCode::REPORT, $this->id);
    }

    /**
     * Reports that belong on an active list: never the archived ones.
     *
     * Every query that answers "what has been reported" reads through this, so
     * an archived report cannot leak back into a count, a table or a project's
     * own report list by somebody forgetting the condition.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    /**
     * The other half: what the Archived Reports view lists.
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('is_archived', true);
    }

    /**
     * The places one word of a report table's search box may match, as
     * `orWhere`s for App\Support\ServerTable::paginate() to group.
     *
     * The columns those tables print: the code (RPT-0007), the title and its
     * text, the project's reference and name, its client, the report type as
     * it reads, and whoever filed it - the account that pressed Submit, or for
     * older reports the technician it is filed under.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMatchingListingWord(Builder $query, string $word): Builder
    {
        $like = '%'.$word.'%';
        $reportId = DisplayCode::toId(DisplayCode::REPORT, $word);

        $types = collect(self::TYPES)
            ->filter(fn (string $label): bool => str_contains(mb_strtolower($label), mb_strtolower($word)))
            ->keys()
            ->all();

        $named = fn (Builder $users) => $users
            ->where('name', 'like', $like)
            ->orWhere('first_name', 'like', $like)
            ->orWhere('last_name', 'like', $like);

        return $query
            ->where('report_title', 'like', $like)
            ->orWhere('report_description', 'like', $like)
            ->orWhereHas('project', fn (Builder $projects) => $projects
                ->where('reference_no', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhereHas('clients', fn (Builder $clients) => $clients
                    ->where('fullname', 'like', $like)
                    ->orWhere('company_name', 'like', $like)))
            ->orWhereHas('submitter', $named)
            ->orWhereHas('technician.account', $named)
            ->when($types !== [], fn (Builder $query) => $query->orWhereIn('report_type', $types))
            ->when($reportId !== null, fn (Builder $query) => $query->orWhere($this->getQualifiedKeyName(), $reportId));
    }

    /**
     * Sort by the reference number of the report's project.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrderByProjectReference(Builder $query, string $direction = 'asc'): Builder
    {
        return $query->orderBy(
            Project::query()
                ->select('reference_no')
                ->whereColumn('tbl_projects.project_id', 'tbl_technician_reports.project_id')
                ->limit(1),
            $direction
        );
    }

    /**
     * Sort by the project's client, the company where there is one - the
     * name the report tables print.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrderByClientName(Builder $query, string $direction = 'asc'): Builder
    {
        return $query->orderBy(
            Client::query()
                ->selectRaw("case when company_name is not null and company_name <> '' then company_name else fullname end")
                ->whereColumn('tbl_clients.project_id', 'tbl_technician_reports.project_id')
                ->orderBy('client_id')
                ->limit(1),
            $direction
        );
    }

    /**
     * Sort by who filed the report: the submitting account, or for older
     * reports the technician it is filed under - see submitterName().
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrderBySubmitter(Builder $query, string $direction = 'asc'): Builder
    {
        $submitter = User::query()
            ->selectRaw('coalesce(first_name, name)')
            ->whereColumn('users.id', 'tbl_technician_reports.submitted_by')
            ->limit(1);

        $technician = User::query()
            ->selectRaw('coalesce(users.first_name, users.name)')
            ->join('tbl_technicians', 'tbl_technicians.account_id', '=', 'users.id')
            ->whereColumn('tbl_technicians.technician_id', 'tbl_technician_reports.technician_id')
            ->limit(1);

        return $query->orderByRaw(
            'coalesce(('.$submitter->toSql().'), ('.$technician->toSql().')) '.($direction === 'desc' ? 'desc' : 'asc'),
            array_merge($submitter->getBindings(), $technician->getBindings())
        );
    }

    /**
     * Sort by when the report was archived, with reports archived before the
     * timestamp existed last whichever way round - which is what a null
     * would otherwise not be on some engines.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrderByArchivedAt(Builder $query, string $direction = 'desc'): Builder
    {
        return $query->orderByRaw('archived_at is null')->orderBy('archived_at', $direction);
    }

    public function isArchived(): bool
    {
        return (bool) $this->is_archived;
    }

    public function images(): HasMany
    {
        return $this->hasMany(TechnicianReportImage::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'technician_id', 'technician_id');
    }

    /**
     * The account that filed the report, whatever their role.
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by', 'id');
    }

    /**
     * Who to credit the report to.
     *
     * The submitting account when there is one; otherwise the technician the
     * report is about, which is who filed every report written before that
     * column existed.
     */
    public function submitterName(): string
    {
        return $this->submitter?->fullName()
            ?? $this->technician?->name
            ?? 'Unknown';
    }

    /**
     * The technician record behind submitterName(), when it names one.
     *
     * Null when the report was filed by an administrator, whose name has no
     * technician's details to open. A report from before `submitted_by`
     * existed was filed by the technician it is about.
     */
    public function submitterTechnician(): ?Technician
    {
        if (! $this->technician) {
            return null;
        }

        if ($this->submitter && (int) $this->submitter->id !== (int) $this->technician->account_id) {
            return null;
        }

        return $this->technician;
    }

    /**
     * The picture to show beside submitterName(), from the same account.
     *
     * Null only when nobody can be identified; a report is always filed by
     * somebody internal, so in practice this is their picture or the default
     * avatar.
     */
    public function submitterAvatarUrl(): ?string
    {
        return ($this->submitter ?? $this->technician?->account)?->avatarUrl();
    }

    /**
     * The account that filed this report away. Null while it is active, and
     * for anything archived before the column existed.
     */
    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by', 'id');
    }

    /**
     * Whether this account is the one that filed the report.
     *
     * `submitted_by` is the answer wherever there is one. Reports written
     * before that column existed carry no account at all, and for those the
     * technician the report is filed under is who wrote it - the same fallback
     * submitterName() prints and the same one the technician portal's own
     * report log narrows by. It is never "the technician assigned to the
     * project now": a report whose submitter is recorded is judged on that
     * alone.
     */
    public function wasSubmittedBy(User $user): bool
    {
        if ($this->submitted_by !== null) {
            return (int) $this->submitted_by === (int) $user->id;
        }

        $technicianId = $user->technicianId();

        return $technicianId !== null && (int) $this->technician_id === $technicianId;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->report_type] ?? ucfirst((string) $this->report_type);
    }

    public function typeBadgeClass(): string
    {
        return $this->report_type === 'incident' ? 'bg-danger' : 'bg-primary';
    }

    /**
     * The colour the report viewer is tinted with, so the type is readable at
     * a glance before a word is read.
     */
    public function typeAccentClass(): string
    {
        return $this->report_type === 'incident'
            ? 'report-accent-incident'
            : 'report-accent-progress';
    }
}
