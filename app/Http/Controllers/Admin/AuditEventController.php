<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin audit viewer (03-contract.md §Routes; 05-ui.md screen 9; C-13).
 * Backend only — no Blade per 001-D06; JSON endpoints.
 *
 * - GET  /admin/audit-events        index with filters + pagination
 * - GET  /admin/audit-events/export watermarked CSV export (password.confirm)
 *
 * Both routes sit behind auth + RequireOrgAdmin (403 otherwise, audited as
 * audit.viewer.denied per the contract's error catalog). Every query is
 * scoped to the admin's org_id — 001-D13: system-org events are out of
 * scope for the org viewer and can never appear here.
 *
 * Filters:
 * - actor:       actor_id UUID, or a free-text match on the actor's name/email
 * - object_type: derived from the event name prefix (auth, user, invitation,
 *                matter, session, team) — the only object discriminator this
 *                feature populates; auditable_type is honored when set
 * - action:      exact audit event name (e.g. auth.login.failed)
 * - from / to:   dates (Y-m-d) bounding created_at, inclusive
 * - q:           free text matched against event name, payload JSON, and
 *                actor name/email
 * - per_page:    1–100, default 25
 */
class AuditEventController extends Controller
{
    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var User $user */
        return $user;
    }

    /**
     * @return array{actor?: string|null, object_type?: string|null, action?: string|null, from?: string|null, to?: string|null, q?: string|null, per_page: int}
     */
    private function validatedFilters(Request $request): array
    {
        /** @var array{actor?: string, object_type?: string, action?: string, from?: string, to?: string, q?: string, per_page?: int} $validated */
        $validated = $request->validate([
            'actor' => ['nullable', 'string', 'max:255'],
            'object_type' => ['nullable', 'string', 'max:50'],
            'action' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $validated['per_page'] = $validated['per_page'] ?? 25;

        return $validated;
    }

    /**
     * @param  array{actor?: string|null, object_type?: string|null, action?: string|null, from?: string|null, to?: string|null, q?: string|null, per_page: int}  $filters
     * @return Builder<AuditEvent>
     */
    private function filteredQuery(User $admin, array $filters): Builder
    {
        // 001-D13: org-scoped. The system org's events never match this
        // predicate, so they are out of scope for the org viewer by
        // construction.
        $query = AuditEvent::query()
            ->where('org_id', $admin->org_id)
            ->with(['actor' => fn ($q) => $q->select('id', 'name', 'email')])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($filters['actor'])) {
            $actor = $filters['actor'];
            if (Str::isUuid($actor)) {
                $query->where('actor_id', $actor);
            } else {
                $query->whereHas('actor', fn (Builder $q) => $q
                    ->where('email', 'ilike', "%{$actor}%")
                    ->orWhere('name', 'ilike', "%{$actor}%"));
            }
        }

        if (! empty($filters['object_type'])) {
            $query->where('event', 'like', $filters['object_type'].'.%');
        }

        if (! empty($filters['action'])) {
            $query->where('event', $filters['action']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59.999999');
        }

        if (! empty($filters['q'])) {
            $term = $filters['q'];
            $query->where(function (Builder $q) use ($term): void {
                $q->where('event', 'ilike', "%{$term}%")
                    ->orWhereRaw('payload::text ilike ?', ["%{$term}%"])
                    ->orWhereHas('actor', fn (Builder $aq) => $aq
                        ->where('email', 'ilike', "%{$term}%")
                        ->orWhere('name', 'ilike', "%{$term}%"));
            });
        }

        return $query;
    }

    /**
     * The object type of an event: auditable_type when populated, otherwise
     * the event-name prefix (auth.login → auth, matter.grant.created →
     * matter). This is the discriminator the object_type filter matches.
     */
    private function objectType(AuditEvent $event): string
    {
        if ($event->auditable_type !== null) {
            return strtolower(class_basename($event->auditable_type));
        }

        return (string) Str::before($event->event, '.');
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(AuditEvent $event): array
    {
        /** @var CarbonInterface $createdAt */
        $createdAt = $event->created_at;
        $actor = $event->actor;

        return [
            'id' => (string) $event->getKey(),
            'created_at' => $createdAt->toIso8601String(),
            'event' => $event->event,
            'actor' => $actor instanceof User ? [
                'id' => (string) $actor->getKey(),
                'name' => $actor->name,
                'email' => $actor->email,
            ] : null,
            'object_type' => $this->objectType($event),
            'object_id' => $event->auditable_id ? (string) $event->auditable_id : null,
            'matter_id' => $event->matter_id ? (string) $event->matter_id : null,
            'ip' => $event->ip,
            'payload' => $event->payload,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $admin = $this->admin($request);
        $filters = $this->validatedFilters($request);

        $events = $this->filteredQuery($admin, $filters)->paginate($filters['per_page']);
        $events->getCollection()->transform(fn (AuditEvent $e) => $this->eventPayload($e));

        return response()->json($events);
    }

    /**
     * Watermarked CSV export. Sits behind password.confirm at the route
     * level. The export itself is audited as audit.exported (allow event);
     * the audit row is written BEFORE streaming, so the export reflects the
     * log as the admin saw it when they confirmed.
     *
     * The watermark is a `#`-comment header naming who exported, when, for
     * which org, and with which filters — anyone holding the file can
     * attribute it.
     */
    public function export(Request $request): StreamedResponse
    {
        $admin = $this->admin($request);
        $filters = $this->validatedFilters($request);

        $events = $this->filteredQuery($admin, $filters)->get();
        $rows = $events->map(fn (AuditEvent $e) => $this->eventPayload($e))->all();

        $exportedAt = now();
        $org = $admin->organization;
        $watermark = "exported-by {$admin->email} at {$exportedAt->toIso8601String()} for org {$org->name}";

        AuditLogger::log('audit.exported', $admin, [
            'actor_id' => (string) $admin->getKey(),
            'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            'watermark' => $watermark,
            'row_count' => count($rows),
        ]);

        $filename = 'audit-events-'.Str::slug($org->name).'-'.$exportedAt->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($admin, $org, $exportedAt, $filters, $rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "# vision-law audit log export\n");
            fwrite($out, '# exported-by: '.$admin->email.' ('.$admin->getKey().")\n");
            fwrite($out, '# exported-at: '.$exportedAt->toIso8601String()."\n");
            fwrite($out, '# org: '.$org->name.' ('.$org->getKey().")\n");
            fwrite($out, '# filters: '.json_encode(
                array_filter($filters, fn ($v) => $v !== null && $v !== ''),
                JSON_THROW_ON_ERROR
            )."\n");
            fwrite($out, "#\n");

            fputcsv($out, [
                'id', 'created_at', 'event', 'actor_id', 'actor_email',
                'object_type', 'object_id', 'matter_id', 'ip', 'payload',
            ]);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['id'],
                    $row['created_at'],
                    $row['event'],
                    $row['actor']['id'] ?? '',
                    $row['actor']['email'] ?? '',
                    $row['object_type'],
                    $row['object_id'] ?? '',
                    $row['matter_id'] ?? '',
                    $row['ip'] ?? '',
                    json_encode($row['payload'], JSON_THROW_ON_ERROR),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
