<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeProjectClientRequest;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Project;
use App\Services\ActivityLogger;
use App\Services\ClientProjects;
use App\Services\ClientRecords;
use App\Services\NotificationService;
use App\Services\ProjectEmails;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The clients on record, as the New Project wizard, Project Details and
 * Configuration ask about them.
 *
 * Two small JSON reads: the client pickers search here one page at a time
 * rather than loading every client into the page, and the New client step
 * asks here whether the address typed in already belongs to a client before
 * it lets the person move on. The save behind each form refuses the same
 * duplicate, so neither is the only line of defence. And one write: moving a
 * project to another client.
 */
class ProjectClientController extends Controller
{
    /**
     * How many matches the picker shows at once.
     */
    public const PAGE_SIZE = 8;

    public function __construct(
        private readonly ClientProjects $clientProjects,
        private readonly ClientRecords $clientRecords,
        private readonly ActivityLogger $activityLogger,
        private readonly NotificationService $notifications,
        private readonly ProjectEmails $clientEmails,
    ) {}

    /**
     * Clients matching a name or an address, best known first.
     *
     * Every word typed must match somewhere - "dela cruz" finds Juan Dela
     * Cruz - and an empty search offers the most recently added clients, so
     * the list is never blank when the box is first opened.
     *
     * `without_account` narrows to clients who could still be given a client
     * account (an address, and no account yet), for Configuration. `exclude`
     * leaves one client out, for Change client.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'without_account' => ['nullable', 'boolean'],
            'exclude' => ['nullable', 'integer'],
        ]);

        $words = collect(preg_split('/\s+/', trim((string) ($validated['q'] ?? ''))))
            ->filter(fn (string $word): bool => $word !== '')
            ->take(5);

        $clients = Client::query()
            ->withCount('projects')
            ->when($words->isNotEmpty(), function (Builder $query) use ($words): void {
                foreach ($words as $word) {
                    $like = '%'.$word.'%';

                    $query->where(fn (Builder $matching) => $matching
                        ->where('fullname', 'like', $like)
                        ->orWhere('firstname', 'like', $like)
                        ->orWhere('surname', 'like', $like)
                        ->orWhere('company_name', 'like', $like)
                        ->orWhere('email_address', 'like', $like));
                }
            })
            ->when($request->boolean('without_account'), fn (Builder $query) => $query
                ->whereNull('user_id')
                ->whereNotNull('email_address'))
            ->when(filled($validated['exclude'] ?? null), fn (Builder $query) => $query
                ->whereKeyNot((int) $validated['exclude']))
            ->orderByDesc('client_id')
            ->limit(self::PAGE_SIZE)
            ->get();

        return response()->json([
            'results' => $clients->map(fn (Client $client): array => $client->toPickerArray())->values()->all(),
        ]);
    }

    /**
     * Whether a client is already on record under this address.
     *
     * Compared normalised - trimmed and lower-cased - the same way the
     * address is stored, so "Juan@Example.com " finds juan@example.com.
     */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'max:255'],
        ]);

        $client = $this->clientProjects->clientWithEmail($validated['email']);

        if ($client === null) {
            return response()->json(['exists' => false]);
        }

        $client->loadCount('projects');

        return response()->json([
            'exists' => true,
            'message' => 'Client already exists.',
            'client' => $client->toPickerArray(),
        ]);
    }

    /**
     * Move a project to another client - the only way to make a project
     * someone else's.
     *
     * The previous client keeps their record and their other projects; only
     * this project points elsewhere. A project still named after its previous
     * client, as the wizard names every project, takes the new client's name
     * with it.
     */
    public function change(ChangeProjectClientRequest $request, int $id): RedirectResponse
    {
        $project = $request->project();
        $previous = $project->client;

        $client = DB::transaction(function () use ($request, $project, $previous): Client {
            $client = $request->usesExistingClient()
                ? $request->chosenClient()
                : $this->clientRecords->create($request->validated());

            $project->forceFill([
                'client_id' => $client->client_id,
                ...($previous && $project->isNamedFor($previous) ? ['name' => $project->standardNameFor($client)] : []),
            ])->save();

            return $client;
        });

        $project->setRelation('client', $client);

        $this->announceChange($project, $previous, $client);

        return redirect()
            ->route('super-admin.projects.show', $id)
            ->with('success', 'Client changed to '.$client->primaryName().'.');
    }

    /**
     * The trail, the bells, and the new client's welcome. None of it may turn
     * a change that was saved into one reported as failed.
     */
    private function announceChange(Project $project, ?Client $previous, Client $client): void
    {
        try {
            $this->activityLogger->record(
                ActivityLog::PROJECT_CLIENT_CHANGED,
                $client->account,
                sprintf(
                    "Changed the client on project '%s' from %s to %s.",
                    $project->reference_no ?? $project->name,
                    $previous?->primaryName() ?? 'none',
                    $client->primaryName()
                ),
                $project
            );

            $this->notifications->projectUpdated($project);

            // Told the way a client is told about a project booked for them.
            $this->clientEmails->projectCreated($project);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
