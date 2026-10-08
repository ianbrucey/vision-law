<?php

namespace App\Http\Controllers;

use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use App\Services\MatterService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Matter party endpoints (spec 006 T-03; 03-contract.md §Routes).
 *
 * - POST   /matters/{matter}/parties            → store   (matter.access:edit ≈ :update until T-06)
 * - DELETE /matters/{matter}/parties/{party}    → destroy (soft delete; matter.access:edit ≈ :update)
 *
 * Every mutation goes through MatterService (audit row in the same
 * transaction). The {party} segment is resolved scoped to the authorized
 * matter — never globally.
 */
class PartyController extends Controller
{
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $matter = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'party_type' => ['required', 'string', Rule::in(MatterParty::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'role_description' => ['nullable', 'string'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'user_id' => [
                'nullable',
                'uuid',
                Rule::exists('users', 'id')->where('org_id', $matter->org_id),
            ],
        ]);

        $party = MatterService::addParty($matter, $validated, $actor);

        // T-05: the overview tab's add-party form posts here as HTML.
        if (! $request->wantsJson()) {
            return redirect()->back()->with('toast', [
                'tone' => 'ok',
                'message' => "Party added to {$matter->matter_number}.",
            ]);
        }

        return response()->json($this->resource($party), 201);
    }

    public function destroy(Request $request, string $matter, string $party): JsonResponse|RedirectResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        $partyModel = $this->resolveParty($matterModel, $party);

        MatterService::removeParty($matterModel, $partyModel, $actor);

        // T-05: the overview tab's remove-party form posts here as HTML.
        if (! $request->wantsJson()) {
            return redirect()->back()->with('toast', [
                'tone' => 'ok',
                'message' => 'Party removed.',
            ]);
        }

        return response()->json([
            'id' => (string) $partyModel->getKey(),
            'deleted_at' => $partyModel->deleted_at?->toIso8601String(),
        ]);
    }

    /**
     * Resolve the {party} segment scoped to the authorized matter.
     * Unknown or foreign-matter ids render 404 (no existence leak); a
     * malformed UUID can never reach Postgres as a uuid bind.
     *
     * @throws ModelNotFoundException
     */
    private function resolveParty(Matter $matter, string $raw): MatterParty
    {
        if (! Str::isUuid($raw)) {
            throw new ModelNotFoundException;
        }

        return MatterParty::query()
            ->where('matter_id', $matter->getKey())
            ->whereKey($raw)
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
    private function resource(MatterParty $party): array
    {
        return [
            'id' => (string) $party->getKey(),
            'party_type' => $party->party_type,
            'name' => $party->name,
            'role_description' => $party->role_description,
            'email' => $party->email,
            'phone' => $party->phone,
            'address' => $party->address,
            'user_id' => $party->user_id !== null ? (string) $party->user_id : null,
            'created_at' => $party->created_at?->toIso8601String(),
        ];
    }
}
