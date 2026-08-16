<?php

namespace App\Services\Documents;

use App\Models\ActivityLog;
use App\Models\BusinessDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One chronological feed for a document: created, filed, versioned, and
 * commented — §30 and §35 of the master spec.
 *
 * No new table. `activity_log` already records created/updated/deleted for a
 * BusinessDocument (see AppServiceProvider's AuditObserver registration);
 * versions already record every content change; comments already record
 * every remark. This stitches the three together rather than duplicating any
 * of them into a fourth "timeline" table that would just as quickly drift out
 * of sync with the records it was describing.
 */
class DocumentActivity
{
    /** @return Collection<int, array{type: string, at: Carbon, actor: ?string, summary: string}> */
    public function timeline(BusinessDocument $document): Collection
    {
        $log = ActivityLog::query()
            ->where('subject_type', BusinessDocument::class)
            ->where('subject_id', $document->id)
            ->with('user')
            ->get()
            ->map(fn (ActivityLog $entry) => [
                'type' => $entry->event,
                'at' => $entry->created_at,
                'actor' => $entry->user?->name,
                'summary' => $this->summariseLogEntry($entry),
            ]);

        $versions = $document->versions()
            ->with('creator')
            ->get()
            ->map(fn ($version) => [
                'type' => 'version_created',
                'at' => $version->created_at,
                'actor' => $version->creator?->name,
                'summary' => 'Version '.$version->version_number.' saved',
            ]);

        $comments = $document->comments()
            ->with('author')
            ->get()
            ->map(fn ($comment) => [
                'type' => $comment->isReply() ? 'replied' : 'commented',
                'at' => $comment->created_at,
                'actor' => $comment->author?->name,
                'summary' => $comment->isReply() ? 'Replied to a comment' : 'Left a comment',
            ]);

        return $log->concat($versions)->concat($comments)
            ->sortByDesc('at')
            ->values();
    }

    protected function summariseLogEntry(ActivityLog $entry): string
    {
        if ($entry->event === 'created') {
            return 'Document created';
        }

        if ($entry->event === 'deleted') {
            return 'Document deleted';
        }

        // 'updated': name what changed from the recorded diff, falling back
        // to a plain label if the properties were stripped for any reason.
        $after = $entry->properties['after'] ?? [];
        $fields = array_keys($after);

        if ($fields === []) {
            return 'Document updated';
        }

        if (in_array('status', $fields, true) && ($after['status'] ?? null) === 'issued') {
            return 'Document issued';
        }

        if (in_array('status', $fields, true) && ($after['status'] ?? null) === 'void') {
            return 'Document voided';
        }

        return 'Updated: '.implode(', ', $fields);
    }
}
