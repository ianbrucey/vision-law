<?php

namespace App\Http\Controllers\Calendaring;

use App\Http\Controllers\Controller;
use App\Mail\CalendarInviteMail;
use App\Models\CalendarFeed;
use App\Models\CalendarItem;
use App\Models\CalendarShare;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\AuditLogger;
use App\Services\Calendaring\IcsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * External coordination, Phase A only (009 T-14, 009-D22).
 *
 * Surfaces: auth ICS export, per-user subscription feed issue /
 * revoke / public fetch, per-item guest shares + public guest
 * view, and single-item invite mail with an .ics attachment.
 * Plain tokens are shown once at creation; only their sha256
 * hash is stored. Every content surface re-scopes through
 * AgendaQuery / matter authorization at request time, and audit
 * payloads reference feeds and shares by id only — never token
 * values (AuditLogger's blocklist forbids credential-shaped
 * keys regardless).
 */
class CalendarExternalController extends Controller
{
    public function export(Request $request): Response
    {
        $actor = $this->actor($request);

        $items = IcsService::authorizedItems($actor);

        AuditLogger::log('calendar.export.performed', $actor, [
            'item_count' => $items->count(),
        ], explicitOrgId: (string) $actor->org_id);

        return response(IcsService::calendar($items), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="vision-law-calendar.ics"',
        ]);
    }

    public function storeFeed(Request $request): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);

        $plain = Str::random(48);

        $feed = CalendarFeed::create([
            'user_id' => (string) $actor->getKey(),
            'token_hash' => hash('sha256', $plain),
        ]);

        AuditLogger::log('calendar.feed.created', $actor, [
            'feed_id' => (string) $feed->getKey(),
        ], explicitOrgId: (string) $actor->org_id);
        AuditLogger::log('calendar.feed.issued', $actor, [
            'feed_id' => (string) $feed->getKey(),
        ], explicitOrgId: (string) $actor->org_id);

        if ($request->wantsJson()) {
            return response()->json([
                'id' => (string) $feed->getKey(),
                'token' => $plain,
                'url' => url('/feeds/calendar/'.$plain.'.ics'),
            ], 201);
        }

        return redirect()->route('calendar.index')
            ->with('toast', ['tone' => 'ok', 'message' => 'Calendar feed created. Copy the URL now — it is shown only once: '.url('/feeds/calendar/'.$plain.'.ics')]);
    }

    public function destroyFeed(Request $request, string $feed): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);

        $model = Str::isUuid($feed)
            ? CalendarFeed::query()->whereKey($feed)->where('user_id', $actor->getKey())->first()
            : null;
        if ($model === null) {
            abort(404);
        }

        if ($model->revoked_at === null) {
            $model->forceFill(['revoked_at' => now()])->save();

            AuditLogger::log('calendar.feed.revoked', $actor, [
                'feed_id' => (string) $model->getKey(),
            ], explicitOrgId: (string) $actor->org_id);
        }

        if ($request->wantsJson()) {
            return response()->json(['id' => (string) $model->getKey(), 'revoked' => true]);
        }

        return redirect()->route('calendar.index')
            ->with('toast', ['tone' => 'ok', 'message' => 'Calendar feed revoked.']);
    }

    public function feed(string $token): Response
    {
        $feed = CalendarFeed::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($feed === null || $feed->revoked_at !== null) {
            abort(404);
        }

        $owner = $feed->user;
        if ($owner === null || $owner->deactivated_at !== null) {
            abort(404);
        }

        AuditLogger::log('calendar.feed.accessed', null, [
            'feed_id' => (string) $feed->getKey(),
        ], explicitOrgId: (string) $owner->org_id);

        return response(IcsService::feedForUser($owner), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
        ]);
    }

    public function storeShare(Request $request, string $item): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $model = $this->resolveItem($item);
        AccessControl::authorize($actor, 'edit', $model->matter);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string', 'in:'.implode(',', CalendarShare::ALLOWED_FIELDS)],
            'expires_at' => ['nullable', 'date'],
        ]);

        $fields = isset($validated['fields']) && is_array($validated['fields']) && $validated['fields'] !== []
            ? array_values($validated['fields'])
            : ['title', 'date'];

        $plain = Str::random(48);

        $share = CalendarShare::create([
            'item_id' => (string) $model->getKey(),
            'guest_email' => (string) $validated['email'],
            'fields' => $fields,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $validated['expires_at'] ?? null,
            'created_by' => (string) $actor->getKey(),
        ]);

        AuditLogger::log('calendar.share.created', $actor, [
            'share_id' => (string) $share->getKey(),
            'item_id' => (string) $model->getKey(),
            'fields' => $fields,
        ], $model->matter);
        AuditLogger::log('calendar.guest.invited', $actor, [
            'share_id' => (string) $share->getKey(),
            'item_id' => (string) $model->getKey(),
            'fields' => $fields,
        ], $model->matter);

        if ($request->wantsJson()) {
            return response()->json([
                'id' => (string) $share->getKey(),
                'token' => $plain,
                'url' => url('/guest/calendar/'.$plain),
                'fields' => $fields,
            ], 201);
        }

        return redirect()->route('calendar.items.show', $model)
            ->with('toast', ['tone' => 'ok', 'message' => 'Guest share created. Copy the link now — it is shown only once: '.url('/guest/calendar/'.$plain)]);
    }

    public function revokeShare(Request $request, string $share): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);

        $model = Str::isUuid($share)
            ? CalendarShare::query()->with('item.matter')->whereKey($share)->first()
            : null;
        if ($model === null || $model->item === null) {
            abort(404);
        }

        AccessControl::authorize($actor, 'edit', $model->item->matter);

        if ($model->revoked_at === null) {
            $model->forceFill(['revoked_at' => now()])->save();

            AuditLogger::log('calendar.share.revoked', $actor, [
                'share_id' => (string) $model->getKey(),
                'item_id' => (string) $model->item_id,
            ], $model->item->matter);
            AuditLogger::log('calendar.guest.revoked', $actor, [
                'share_id' => (string) $model->getKey(),
                'item_id' => (string) $model->item_id,
            ], $model->item->matter);
        }

        if ($request->wantsJson()) {
            return response()->json(['id' => (string) $model->getKey(), 'revoked' => true]);
        }

        return back()->with('toast', ['tone' => 'ok', 'message' => 'Guest share revoked.']);
    }

    public function guest(string $token): View
    {
        $share = CalendarShare::query()
            ->where('token_hash', hash('sha256', $token))
            ->with('item')
            ->first();

        if ($share === null || ! $share->isActive() || $share->item === null) {
            abort(404);
        }

        return view('calendaring.guest.show', [
            'share' => $share,
            'item' => $share->item,
            'fields' => $share->fields,
        ]);
    }

    public function invite(Request $request, string $item): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $model = $this->resolveItem($item);
        AccessControl::authorize($actor, 'view', $model->matter);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $method = $model->status === 'canceled' ? 'CANCEL' : 'REQUEST';
        $ics = IcsService::invite($model, $method, (int) $model->version);

        Mail::to((string) $validated['email'])->send(new CalendarInviteMail($model, $ics, $method));

        if ($request->wantsJson()) {
            return response()->json([
                'sent' => true,
                'method' => $method,
                'sequence' => (int) $model->version,
            ]);
        }

        return back()->with('toast', ['tone' => 'ok', 'message' => 'Invitation sent.']);
    }

    private function resolveItem(string $id): CalendarItem
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $item = CalendarItem::query()->with('matter')->whereKey($id)->first();
        if ($item === null) {
            abort(404);
        }

        return $item;
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
