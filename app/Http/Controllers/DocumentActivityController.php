<?php

namespace App\Http\Controllers;

use App\Exceptions\AccessDeniedException;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\Matter;
use App\Models\User;
use App\Services\DocumentAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Per-document audit Activity tab + matter-level CSV export
 * (spec 007 T-10, C-14).
 *
 * Both are read models over audit_events (006-D04 — no new table):
 * - GET /matters/{matter}/documents/{document}/activity → documents.activity
 * - GET /matters/{matter}/documents/activity/export    → documents.activity.export
 *
 * Authorization: matter.access:view at the middleware, then
 * DocumentAccess::authorize :view on the document (T-08) inside the
 * controller — the same HTTP-layer rule as preview/download, so a
 * permission-denied actor gets nothing, never partial rows. Leak
 * sentinels hold: every row shown belongs to a matter/document the
 * actor may already see, and payloads never carry tokens or hashes
 * (AuditLogger's privileged-key blocklist).
 */
class DocumentActivityController extends Controller
{
    /**
     * Event namespaces whitelisted for the matter-level CSV export.
     * Document-namespaced events only — matter lifecycle events belong
     * to the 006 feed.
     *
     * @var list<string>
     */
    public const EXPORT_EVENT_PREFIXES = ['document.', 'template.', 'folder.'];

    public function index(Request $request, string $matter, string $document): mixed
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);
        $doc = $this->resolveDocument($matterModel, $document);

        if (! $doc instanceof Document) {
            abort(404);
        }

        try {
            DocumentAccess::authorize($actor, 'view', $doc);
        } catch (AccessDeniedException $e) {
            abort(response()->json(['code' => 'forbidden'], $e->httpStatus));
        }

        $events = AuditEvent::query()
            ->where('matter_id', $matterModel->getKey())
            ->whereJsonContains('payload->document_id', (string) $doc->getKey())
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return view('documents.activity', [
            'matter' => $matterModel,
            'document' => $doc,
            'events' => $events,
            'canShare' => DocumentAccess::can($actor, 'manage', $doc),
            'descriptions' => $events->getCollection()
                ->mapWithKeys(fn (AuditEvent $e): array => [(string) $e->getKey() => self::describe($e)])
                ->all(),
        ]);
    }

    public function export(Request $request, string $matter): StreamedResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $this->actor($request);

        $filename = 'matter-'.$matterModel->matter_number.'-document-activity.csv';

        return response()->streamDownload(function () use ($matterModel): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }

            fputcsv($out, [
                'timestamp', 'event', 'actor', 'actor_id',
                'document_id', 'version_number', 'ip', 'user_agent', 'details',
            ]);

            $query = AuditEvent::query()
                ->where('matter_id', $matterModel->getKey())
                ->where(function ($where): void {
                    foreach (self::EXPORT_EVENT_PREFIXES as $prefix) {
                        $where->orWhere('event', 'like', $prefix.'%');
                    }
                })
                ->with('actor:id,name')
                ->orderBy('created_at')
                ->orderBy('id');

            foreach ($query->cursor() as $event) {
                /** @var AuditEvent $event */
                /** @var array<string, mixed> $payload */
                $payload = $event->payload ?? [];
                // actor_id is nullable (system actions); gate on the FK —
                // when it is set the eager-loaded actor is present.
                $actorName = $event->actor_id !== null ? (string) $event->actor->name : '';

                fputcsv($out, [
                    $event->created_at?->toIso8601String(),
                    (string) $event->event,
                    $actorName,
                    (string) ($event->actor_id ?? ''),
                    (string) ($payload['document_id'] ?? ''),
                    (string) ($payload['version_number'] ?? ''),
                    (string) ($event->ip ?? ''),
                    (string) ($event->user_agent ?? ''),
                    self::describe($event),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /**
     * One-line human summary of an audit row for the Activity tab/CSV.
     * Only non-sensitive payload keys are surfaced (ids, version numbers,
     * reasons, share kinds — never tokens, hashes, or file bytes).
     */
    public static function describe(AuditEvent $event): string
    {
        /** @var array<string, mixed> $payload */
        $payload = $event->payload ?? [];

        $bits = [];

        if (isset($payload['version_number'])) {
            $bits[] = 'v'.$payload['version_number'];
        }
        if (isset($payload['restored_from_version_number'])) {
            $bits[] = 'from v'.$payload['restored_from_version_number'];
        }
        if (isset($payload['reason'])) {
            $bits[] = 'reason: '.mb_substr((string) $payload['reason'], 0, 80);
        }
        if (isset($payload['level'])) {
            $bits[] = 'level: '.(string) $payload['level'];
        }
        if (isset($payload['share_kind'])) {
            $bits[] = (string) $payload['share_kind'];
        }
        if (isset($payload['filename'])) {
            $bits[] = (string) $payload['filename'];
        }
        if (isset($payload['size'])) {
            $bits[] = number_format((int) $payload['size']).' bytes';
        }
        if (isset($payload['change'])) {
            $bits[] = (string) $payload['change'];
        }

        return implode(' · ', $bits);
    }

    private function resolveDocument(Matter $matter, string $raw): ?Document
    {
        if (! Str::isUuid($raw)) {
            return null;
        }

        return Document::query()
            ->where('matter_id', $matter->getKey())
            ->whereKey($raw)
            ->whereNull('deleted_at')
            ->first();
    }

    private function authorizedMatter(Request $request): Matter
    {
        $matter = $request->attributes->get('matter');

        if (! $matter instanceof Matter) {
            abort(response()->json(['code' => 'not_found'], 404));
        }

        return $matter;
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}
