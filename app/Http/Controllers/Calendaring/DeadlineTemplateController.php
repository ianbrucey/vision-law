<?php

namespace App\Http\Controllers\Calendaring;

use App\Http\Controllers\Controller;
use App\Models\DeadlineTemplate;
use App\Models\DeadlineTemplateVersion;
use App\Models\User;
use App\Services\Calendaring\TemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Deadline template catalog (009 T-10, 009-D18). Org-scoped: any
 * authenticated member of the template's org may browse the catalog;
 * no matter access is involved. Lifecycle transitions delegate to
 * TemplateService (maker-checker publish lives there). Route names
 * templates.deadlines.* — deliberately distinct from 007's
 * templates.* document-stationery routes.
 */
class DeadlineTemplateController extends Controller
{
    public function index(Request $request): JsonResponse|View
    {
        $actor = $this->actor($request);

        $query = DeadlineTemplate::query()
            ->where('org_id', (string) $actor->org_id)
            ->with(['versions', 'currentPublishedVersion'])
            ->orderBy('name');

        $search = $request->query('q');
        if (is_string($search) && $search !== '') {
            $query->where('name', 'ilike', '%'.$search.'%');
        }
        $jurisdiction = $request->query('jurisdiction');
        if (is_string($jurisdiction) && $jurisdiction !== '') {
            $query->where('jurisdiction_label', 'ilike', '%'.$jurisdiction.'%');
        }

        $templates = $query->get();

        if ($request->wantsJson()) {
            return response()->json([
                'templates' => $templates->map(fn (DeadlineTemplate $template) => $this->resource($template))->values(),
            ]);
        }

        return view('calendaring.templates.index', [
            'templates' => $templates,
            'q' => is_string($search) ? $search : '',
            'jurisdiction' => is_string($jurisdiction) ? $jurisdiction : '',
        ]);
    }

    public function show(Request $request, string $template): JsonResponse|View
    {
        $actor = $this->actor($request);
        $model = $this->resolveTemplate($actor, $template);
        $model->load(['versions.creator', 'versions.reviewer', 'currentPublishedVersion', 'creator']);

        if ($request->wantsJson()) {
            return response()->json($this->resource($model, withVersions: true));
        }

        return view('calendaring.templates.show', [
            'template' => $model,
            'currentPublished' => $model->currentPublishedVersion,
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);

        $template = TemplateService::createTemplate($actor, $request->only([
            'name', 'jurisdiction_label', 'owner_label', 'demonstration',
        ]));

        if ($request->wantsJson()) {
            return response()->json($this->resource($template), 201);
        }

        return redirect()
            ->route('templates.deadlines.show', $template)
            ->with('toast', ['tone' => 'ok', 'message' => 'Deadline template created. Add a draft version to define its rules.']);
    }

    public function storeVersion(Request $request, string $template): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $model = $this->resolveTemplate($actor, $template);

        $version = TemplateService::createVersion($actor, $model, [
            'rules' => $this->decodeRules($request),
        ]);

        if ($request->wantsJson()) {
            return response()->json($this->versionResource($version), 201);
        }

        return redirect()
            ->route('templates.deadlines.show', $model)
            ->with('toast', ['tone' => 'ok', 'message' => "Draft version {$version->version} created."]);
    }

    public function submit(Request $request, string $template, string $version): JsonResponse|RedirectResponse
    {
        return $this->transition($request, $template, $version, 'submit');
    }

    public function publish(Request $request, string $template, string $version): JsonResponse|RedirectResponse
    {
        return $this->transition($request, $template, $version, 'publish');
    }

    public function retire(Request $request, string $template, string $version): JsonResponse|RedirectResponse
    {
        return $this->transition($request, $template, $version, 'retire');
    }

    private function transition(Request $request, string $template, string $version, string $action): JsonResponse|RedirectResponse
    {
        $actor = $this->actor($request);
        $model = $this->resolveTemplate($actor, $template);
        $versionModel = $this->resolveVersion($model, $version);

        $updated = match ($action) {
            'submit' => TemplateService::submit($actor, $versionModel),
            'publish' => TemplateService::publish($actor, $versionModel),
            default => TemplateService::retire($actor, $versionModel),
        };

        if ($request->wantsJson()) {
            return response()->json($this->versionResource($updated));
        }

        return redirect()
            ->route('templates.deadlines.show', $model)
            ->with('toast', ['tone' => 'ok', 'message' => "Version {$updated->version} ".str_replace('submit', 'submitted', $action).(str_ends_with($action, 'e') ? 'd.' : 'ed.')]);
    }

    /**
     * Rules arrive either as a decoded JSON payload (API) or as a
     * JSON string in the `rules` field (the Blade textarea). An
     * absent/empty value means "copy the current published rules"
     * (TemplateService decides).
     *
     * @throws ValidationException
     */
    private function decodeRules(Request $request): mixed
    {
        if (! $request->has('rules')) {
            return null;
        }

        $rules = $request->input('rules');
        if (is_string($rules)) {
            if (trim($rules) === '') {
                return null;
            }
            $decoded = json_decode($rules, true);
            if (! is_array($decoded)) {
                throw ValidationException::withMessages(['rules' => 'Rules must be valid JSON.']);
            }

            return $decoded;
        }

        return $rules;
    }

    private function resolveTemplate(User $actor, string $id): DeadlineTemplate
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }
        $template = DeadlineTemplate::query()->whereKey($id)->first();
        if ($template === null || (string) $template->org_id !== (string) $actor->org_id) {
            abort(404);
        }

        return $template;
    }

    private function resolveVersion(DeadlineTemplate $template, string $id): DeadlineTemplateVersion
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }
        $version = DeadlineTemplateVersion::query()
            ->where('template_id', (string) $template->getKey())
            ->whereKey($id)
            ->first();
        if ($version === null) {
            abort(404);
        }

        return $version;
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
    private function resource(DeadlineTemplate $template, bool $withVersions = false): array
    {
        $current = $template->relationLoaded('currentPublishedVersion')
            ? $template->currentPublishedVersion
            : TemplateService::currentPublished($template);

        $resource = [
            'id' => (string) $template->getKey(),
            'name' => $template->name,
            'jurisdiction_label' => $template->jurisdiction_label,
            'demonstration' => (bool) $template->demonstration,
            'owner_label' => $template->owner_label,
            'created_by' => $template->created_by,
            'current_published_version' => $current !== null ? $current->version : null,
        ];

        if ($withVersions) {
            $resource['versions'] = $template->versions
                ->map(fn (DeadlineTemplateVersion $version) => $this->versionResource($version))
                ->values()
                ->all();
        }

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    private function versionResource(DeadlineTemplateVersion $version): array
    {
        return [
            'id' => (string) $version->getKey(),
            'template_id' => (string) $version->template_id,
            'version' => $version->version,
            'status' => $version->status,
            'rules' => $version->rules,
            'created_by' => $version->created_by,
            'reviewed_by' => $version->reviewed_by,
            'published_at' => $version->published_at?->toIso8601String(),
        ];
    }
}
