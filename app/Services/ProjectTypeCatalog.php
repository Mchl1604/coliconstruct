<?php

namespace App\Services;

use App\Models\ProjectType;
use App\Models\Skill;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The catalogue of work the company does, which is one list wearing two hats.
 *
 * A project type says what a project is; a specialty says what a technician is
 * qualified to do. They are the same vocabulary - "Aircon Installation" is both
 * - but they live in two tables, because a type is mapped to projects and a
 * specialty to technicians. Keeping them in one table would mean migrating
 * every existing mapping onto new ids for no behaviour anybody would see.
 *
 * So they are kept in step instead, and this is the only place that writes
 * either of them: add a project type here and the matching specialty appears
 * for technicians. The list is fixed on screen - see ProjectTypeController.
 */
class ProjectTypeCatalog
{
    /**
     * Every type, with how much is using it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function all(): Collection
    {
        $technicianCounts = Skill::query()
            ->withCount('technicians')
            ->get()
            ->mapWithKeys(fn (Skill $skill): array => [
                $this->key($skill->skill_name) => $skill->technicians_count,
            ]);

        return ProjectType::query()
            ->with('detailForm')
            ->withCount('projects')
            ->orderBy('type_name')
            ->get()
            ->map(fn (ProjectType $type): array => [
                'type_id' => $type->type_id,
                'type_name' => $type->type_name,
                // Which detail form the type asks for - null is "None".
                'form_id' => $type->form_id,
                'form_name' => $type->detailForm?->name,
                'project_count' => $type->projects_count,
                'technician_count' => $technicianCounts[$this->key($type->type_name)] ?? 0,
            ])
            ->values();
    }

    /**
     * Add a type, and the specialty that goes with it.
     */
    public function add(string $name): ProjectType
    {
        $name = $this->clean($name);

        $this->assertNameIsFree($name);

        return DB::transaction(function () use ($name): ProjectType {
            $type = ProjectType::create(['type_name' => $name]);

            $this->ensureSkill($name);

            return $type;
        });
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The specialty carrying a given name, whatever its casing.
     */
    private function skillNamed(string $name): ?Skill
    {
        return Skill::query()
            ->whereRaw('LOWER(skill_name) = ?', [$this->key($name)])
            ->first();
    }

    private function ensureSkill(string $name): void
    {
        if ($this->skillNamed($name)) {
            return;
        }

        Skill::create(['skill_name' => $name]);
    }

    /**
     * @throws RuntimeException
     */
    private function assertNameIsFree(string $name, ?ProjectType $ignoring = null): void
    {
        $exists = ProjectType::query()
            ->whereRaw('LOWER(type_name) = ?', [$this->key($name)])
            ->when($ignoring, fn ($query) => $query->where('type_id', '!=', $ignoring->type_id))
            ->exists();

        if ($exists) {
            throw new RuntimeException(sprintf("'%s' is already a project type.", $name));
        }
    }

    private function clean(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    /**
     * Names are compared without regard to case, so "Aircon Repair" and
     * "aircon repair" are one entry rather than two.
     */
    private function key(string $name): string
    {
        return mb_strtolower($this->clean($name));
    }
}
