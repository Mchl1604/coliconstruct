<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * The client portal.
 *
 * Client accounts and the client rows attached to a project are separate
 * things today: tbl_clients holds a project's contact details and requires a
 * project_id, so it cannot represent an account that has no work yet. Until
 * the two are linked, a client's projects are matched on their email address,
 * which is the one field both records share.
 */
class ClientPortalController extends Controller
{
    public function dashboard()
    {
        $user = request()->user();

        return view('client.dashboard', [
            'projects' => $this->projectsFor($user->email),
        ]);
    }

    /**
     * Projects whose client contact carries this email address.
     *
     * @return Collection<int, Project>
     */
    private function projectsFor(string $email): Collection
    {
        $projectIds = Client::query()
            ->where('email_address', $email)
            ->pluck('project_id');

        if ($projectIds->isEmpty()) {
            return collect();
        }

        return Project::query()
            ->with(['schedules', 'clients'])
            ->whereIn('project_id', $projectIds)
            ->where('is_archived', false)
            ->orderByDesc('project_id')
            ->get();
    }
}
