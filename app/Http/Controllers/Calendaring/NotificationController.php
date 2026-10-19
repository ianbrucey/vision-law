<?php

namespace App\Http\Controllers\Calendaring;

use App\Http\Controllers\Controller;
use App\Models\ReminderDelivery;
use App\Models\User;
use Illuminate\Http\Request;
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
            ->whereIn('status', ['sent', 'acknowledged'])
            ->with(['item', 'matter'])
            ->orderByDesc('sent_at')
            ->orderByDesc('created_at')
            ->get();

        return view('calendaring.notifications.index', [
            'deliveries' => $deliveries,
            'notifications' => $user->notifications,
        ]);
    }
}
