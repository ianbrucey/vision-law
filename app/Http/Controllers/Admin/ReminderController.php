<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReminderDelivery;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Reminder ops view (009 T-09).
 *
 * GET  /admin/reminders                    org-scoped ledger list,
 *                                          filterable by status
 * POST /admin/reminders/{delivery}/replay dead / failed /
 *                                          failed_unconfigured rows
 *                                          return to pending (the
 *                                          attempts count is kept
 *                                          for the record), audited
 *                                          as calendar.reminder.replayed.
 *
 * Both routes sit behind auth + RequireOrgAdmin; every query is
 * scoped to the admin's org.
 */
class ReminderController extends Controller
{
    /** @var list<string> */
    private const REPLAYABLE = ['dead', 'failed', 'failed_unconfigured'];

    public function index(Request $request): JsonResponse|View
    {
        $admin = $this->admin($request);
        $status = $request->query('status');
        $status = is_string($status) && $status !== '' ? $status : null;

        $deliveries = ReminderDelivery::query()
            ->where('org_id', $admin->org_id)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->with(['item', 'matter', 'recipient'])
            ->orderByDesc('created_at')
            ->get();

        if ($request->wantsJson()) {
            return response()->json([
                'status' => $status,
                'deliveries' => $deliveries->map(fn (ReminderDelivery $d) => $this->payload($d))->values(),
            ]);
        }

        return view('admin.reminders.index', [
            'deliveries' => $deliveries,
            'status' => $status,
            'statuses' => ReminderDelivery::STATUSES,
            'replayable' => self::REPLAYABLE,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function replay(Request $request, string $delivery): JsonResponse|RedirectResponse
    {
        $admin = $this->admin($request);

        // String id + manual lookup, like the other admin controllers:
        // implicit model binding would 404 on an unknown id before
        // the setup-mode middleware can redirect a restricted
        // session (005 C-01 probes every admin.* route with a random
        // UUID and expects the enrollment redirect).
        $delivery = ReminderDelivery::query()->whereKey($delivery)->first();
        if ($delivery === null) {
            abort(404);
        }

        // Same org only — a cross-org row is not visible here.
        if ((string) $delivery->org_id !== (string) $admin->org_id) {
            abort(404);
        }

        if (! in_array($delivery->status, self::REPLAYABLE, true)) {
            throw ValidationException::withMessages([
                'delivery' => 'Only dead, failed, or failed_unconfigured deliveries can be replayed.',
            ]);
        }

        $before = $delivery->status;
        $delivery->forceFill([
            'status' => 'pending',
            'next_attempt_at' => null,
        ])->save();

        AuditLogger::log('calendar.reminder.replayed', $admin, [
            'item_id' => (string) $delivery->item_id,
            'delivery_id' => (string) $delivery->getKey(),
            'before' => ['status' => $before],
            'after' => ['status' => 'pending'],
        ], $delivery->matter);

        if ($request->wantsJson()) {
            return response()->json([
                'id' => (string) $delivery->getKey(),
                'status' => 'pending',
                'attempts' => (int) $delivery->attempts,
            ]);
        }

        return back();
    }

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        /** @var User $user */
        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ReminderDelivery $delivery): array
    {
        return [
            'id' => (string) $delivery->getKey(),
            'item_id' => (string) $delivery->item_id,
            'item_title' => $delivery->payload['item_title'] ?? $delivery->item?->title,
            'matter_id' => (string) $delivery->matter_id,
            'recipient_user_id' => (string) $delivery->recipient_user_id,
            'recipient_email' => $delivery->recipient_email ?? $delivery->recipient?->email,
            'channel' => $delivery->channel,
            'kind' => $delivery->kind,
            'status' => $delivery->status,
            'attempts' => (int) $delivery->attempts,
            'last_error' => $delivery->last_error,
            'scheduled_for' => $delivery->scheduled_for->toIso8601String(),
            'next_attempt_at' => $delivery->next_attempt_at?->toIso8601String(),
            'sent_at' => $delivery->sent_at?->toIso8601String(),
            'created_at' => $delivery->created_at->toIso8601String(),
            'updated_at' => $delivery->updated_at->toIso8601String(),
        ];
    }
}
