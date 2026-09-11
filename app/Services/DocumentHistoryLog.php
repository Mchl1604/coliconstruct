<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentHistory;
use App\Models\User;

/**
 * The single way a file change reaches a project's document history.
 *
 * Called by everything that adds, replaces or removes a project document -
 * the create wizard, the edit dialog, the remove button - always inside the
 * transaction that makes the change, so the entry and the change commit or
 * roll back together.
 */
class DocumentHistoryLog
{
    public function record(Document $document, string $event, ?User $actor): DocumentHistory
    {
        return DocumentHistory::create([
            'project_id' => $document->project_id,
            'document_id' => $document->document_id,
            'document_type' => $document->document_type,
            'document_name' => $document->document_name,
            'event' => $event,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->fullName() ?? 'System',
            'actor_role' => $actor?->role,
            'created_at' => now(),
        ]);
    }
}
