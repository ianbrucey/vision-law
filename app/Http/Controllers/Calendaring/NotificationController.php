<?php

namespace App\Http\Controllers\Calendaring;

use App\Http\Controllers\Controller;
use App\Models\ReminderDelivery;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\AuditLogger;
use App\Services\Calendaring\ReminderPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $deliveries = ReminderDelivery::query()
            ->where('recipient_user_id', $user->getKey())
            ->where('channel', 'in_app')
            ->whereIn('status', ['sent', 'acknowledged', 'snoozed'])
            ->with(['item', 'matter'])
            ->orderByDesc('sent_at')
            ->orderByDesc('created_at')
            ->get();

        return view('calendaring.notifications.index', [
            'deliveries' => $deliveries,
            'notifications' => $user->notifications,
        ]);
    }

    /**
     * Acknowledge a delivery (009-D17, T-08). Recipient only;
     * acknowledged deliveries never escalate.
     */
    public function acknowledge(Request $request, ReminderDelivery $delivery): JsonResponse|RedirectResponse
    {
        $user = $this->recipientOrFail($request, $delivery);

        if ($delivery->status !== 'acknowledged') {
            $delivery->forceFill([
                'status' => 'acknowledged',
                'acknowledged_at' => now(),
                'acknowledged_by' => (string) $user->getKey(),
            ])->save();

            AuditLogger::log('calendar.reminder.acknowledged', $user, [
                'item_id' => (string) $delivery->item_id,
                'delivery_id' => (string) $delivery->getKey(),
                'before' => [],
                'after' => ['status' => 'acknowledged'],
            ], $delivery->matter);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'id' => (string) $delivery->getKey(),
                'status' => 'acknowledged',
                'acknowledged_at' => $delivery->acknowledged_at?->toIso8601String(),
            ]);
        }

        return back();
    }

    /**
     * Snooze a delivery (009-D17, T-08). Snoozing past the item's
     * due/base instant requires matter `edit`; within the due
     * instant any recipient may snooze.
     *
     * @throws ValidationException
     */
    public function snooze(Request $request, ReminderDelivery $delivery): JsonResponse|RedirectResponse
    {
        $user = $this->recipientOrFail($request, $delivery);

        $validated = $request->validate([
            'until' => ['required', 'date'],
        ]);

        $until = Carbon::parse((string) $validated['until']);
        $item = $delivery->item;
        $base = $item !== null ? ReminderPlanner::baseInstant($item) : null;

        if ($base !== null && $until->greaterThan($base)) {
            $matter = $delivery->matter;
            $role = $matter !== null ? AccessControl::effectiveMatterRole($user, $matter) : null;
            if (! AccessControl::roleSatisfies($role, 'editor')) {
                throw ValidationException::withMessages([
                    'until' => 'Snoozing past the due instant requires matter edit authority.',
                ]);
            }
        }

        $delivery->forceFill([
            'status' => 'snoozed',
            'snoozed_until' => $until,
        ])->save();

        AuditLogger::log('calendar.reminder.snoozed', $user, [
            'item_id' => (string) $delivery->item_id,
            'delivery_id' => (string) $delivery->getKey(),
            'snoozed_until' => $until->toIso8601String(),
            'before' => [],
            'after' => ['status' => 'snoozed', 'snoozed_until' => $until->toIso8601String()],
        ], $delivery->matter);

        if ($request->wantsJson()) {
            return response()->json([
                'id' => (string) $delivery->getKey(),
                'status' => 'snoozed',
                'snoozed_until' => $until->toIso8601String(),
            ]);
        }

        return back();
    }

    private function recipientOrFail(Request $request, ReminderDelivery $delivery): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        // Recipient only — anyone else gets no existence signal.
        if ((string) $user->getKey() !== (string) $delivery->recipient_user_id) {
            abort(404);
        }

        return $user;
    }
}
