<?php

namespace App\Http\Controllers;

use App\Models\Matter;
use App\Models\MatterLink;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\MatterService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Related-matter link endpoints (spec 006 T-03; 03-contract.md §Routes,
 * MAT-17).
 *
 * - GET    /matters/{matter}/links            → index (matter.access:view)
 * - POST   /matters/{matter}/links            → store (matter.access:edit ≈ :update until T-06)
 * - DELETE /matters/{matter}/links/{link}     → destroy (matter.access:edit ≈ :update)
 *
 * Links are stored once in canonical order (matter_id < related_matter_id,
 * service-enforced) and read from either side. A related matter the actor
 * cannot access renders as {restricted: true} — no title, no metadata
 * (03-contract.md §Role-visible data; leak sentinel).
 */
class LinkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $matter = $this->authorizedMatter($request);
        $actor = $this->actor($request);
        $matterId = (string) $matter->getKey();

        $links = MatterLink::query()
            ->where(function ($query) use ($matterId): void {
                $query->where('matter_id', $matterId)
                    ->orWhere('related_matter_id', $matterId);
            })
            ->orderBy('created_at')
            ->get();

        // Batch-load the "other side" matters for the access check.
        $otherIds = $links
            ->map(fn (MatterLink $link): string => (string) $link->matter_id === $matterId
                ? (string) $link->related_matter_id
                : (string) $link->matter_id)
            ->unique()
            ->values();

        $others = Matter::query()
            ->whereKey($otherIds)
            ->get()
            ->keyBy(fn (Matter $m): string => (string) $m->getKey());

        $data = $links->map(function (MatterLink $link) use ($matterId, $actor, $others): array {
            $otherId = (string) $link->matter_id === $matterId
                ? (string) $link->related_matter_id
                : (string) $link->matter_id;

            /** @var Matter|null $other */
            $other = $others->get($otherId);

            $canView = $other instanceof Matter
                && AccessControl::effectiveMatterRole($actor, $other) !== null;

            return [
                'id' => (string) $link->getKey(),
                'link_type' => $link->link_type,
                'note' => $link->note,
                'direction' => (string) $link->matter_id === $matterId ? 'outgoing' : 'incoming',
                'related_matter' => $canView
                    ? [
                        'id' => $otherId,
                        'matter_number' => $other->matter_number,
                        'title' => $other->title,
                        'lifecycle_state' => $other->lifecycle_state,
                    ]
                    : ['restricted' => true],
            ];
        })->values()->all();

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $matter = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        /** @var array{related_matter_id: string, link_type: string, note?: string|null} $validated */
        $validated = $request->validate([
            'related_matter_id' => ['required', 'uuid'],
            'link_type' => ['required', 'string', Rule::in(MatterLink::TYPES)],
            'note' => ['nullable', 'string'],
        ]);

        $link = MatterService::addLink($matter, $validated, $actor);

        return response()->json($this->resource($link, (string) $matter->getKey()), $link->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, string $matter, string $link): JsonResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        $linkModel = $this->resolveLink($matterModel, $link);

        MatterService::removeLink($matterModel, $linkModel, $actor);

        return response()->json(['id' => (string) $linkModel->getKey()]);
    }

    /**
     * Resolve the {link} segment to a link involving the authorized matter
     * (either canonical side). Unknown ids render 404.
     *
     * @throws ModelNotFoundException
     */
    private function resolveLink(Matter $matter, string $raw): MatterLink
    {
        if (! Str::isUuid($raw)) {
            throw new ModelNotFoundException;
        }

        $matterId = (string) $matter->getKey();

        return MatterLink::query()
            ->whereKey($raw)
            ->where(function ($query) use ($matterId): void {
                $query->where('matter_id', $matterId)
                    ->orWhere('related_matter_id', $matterId);
            })
            ->firstOrFail();
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

    /**
     * @return array<string, mixed>
     */
    private function resource(MatterLink $link, string $matterId): array
    {
        $otherId = (string) $link->matter_id === $matterId
            ? (string) $link->related_matter_id
            : (string) $link->matter_id;

        return [
            'id' => (string) $link->getKey(),
            'link_type' => $link->link_type,
            'note' => $link->note,
            'related_matter_id' => $otherId,
        ];
    }
}
