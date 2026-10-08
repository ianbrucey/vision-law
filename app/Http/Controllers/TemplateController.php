<?php

namespace App\Http\Controllers;

use App\Exceptions\AccessDeniedException;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Matter;
use App\Models\User;
use App\Services\AccessControl;
use App\Services\AuditLogger;
use App\Services\AuthoredDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Template library (spec 007 T-04, DOC-22/23, 007-D03).
 *
 * Org-level HTML stationery with {{merge_field}} placeholders and typed
 * field definitions. MERGE FIELDS ONLY — no summarization, no clause
 * extraction, no agent fill, no content intelligence (007-D03).
 *
 * - Anyone in the org may list, view, create drafts, and generate from
 *   published templates.
 * - Publishing (draft → published) requires the template_editor role —
 *   403 otherwise.
 * - Templates are versioned like documents: every content edit bumps
 *   `version`; generated documents link back to (template_id,
 *   template_version).
 */
class TemplateController extends Controller
{
    public function __construct(private readonly AuthoredDocumentService $authored) {}

    /**
     * Org template library.
     */
    public function index(Request $request): View
    {
        $actor = $this->actor($request);

        $templates = DocumentTemplate::query()
            ->where('org_id', $actor->org_id)
            ->orderBy('name')
            ->get();

        return view('templates.index', [
            'templates' => $templates,
            'canPublish' => $this->canPublish($actor),
        ]);
    }

    /**
     * New template (draft).
     */
    public function create(Request $request): View
    {
        $this->actor($request);

        return view('templates.create');
    }

    /**
     * Store a draft template. Audit: template.created.
     */
    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor($request);

        /** @var array{name: string, body_html: string, field_definitions: mixed} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string', 'max:1048576'],
            'field_definitions' => ['nullable'],
        ]);

        $definitions = $this->authored->normalizeFieldDefinitions(
            $this->decodeFieldDefinitions($validated['field_definitions'] ?? null)
        );

        $template = DocumentTemplate::create([
            'org_id' => $actor->org_id,
            'name' => $validated['name'],
            'body_html' => $validated['body_html'],
            'field_definitions' => $definitions,
            'version' => 1,
            'status' => 'draft',
            'created_by' => $actor->getKey(),
        ]);

        AuditLogger::log('template.created', $actor, [
            'actor_id' => (string) $actor->getKey(),
            'template_id' => (string) $template->getKey(),
            'name' => $template->name,
            'version' => 1,
        ], explicitOrgId: (string) $actor->org_id);

        return redirect()
            ->route('templates.show', $template)
            ->with('toast', ['tone' => 'ok', 'message' => 'Template draft created. Publish it to make it available for generation.']);
    }

    /**
     * Template detail: body preview, field definitions, version/status.
     */
    public function show(Request $request, string $template): View
    {
        $actor = $this->actor($request);
        $templateModel = $this->resolveTemplate($actor, $template);

        return view('templates.show', [
            'template' => $templateModel,
            'canPublish' => $this->canPublish($actor),
        ]);
    }

    /**
     * Edit a template (draft or published). Editing bumps the version.
     */
    public function edit(Request $request, string $template): View
    {
        $actor = $this->actor($request);
        $templateModel = $this->resolveTemplate($actor, $template);

        return view('templates.edit', ['template' => $templateModel]);
    }

    /**
     * Update a template: every content edit creates a new template
     * version (versioned like documents).
     */
    public function update(Request $request, string $template): RedirectResponse
    {
        $actor = $this->actor($request);
        $templateModel = $this->resolveTemplate($actor, $template);

        /** @var array{name: string, body_html: string, field_definitions: mixed} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string', 'max:1048576'],
            'field_definitions' => ['nullable'],
        ]);

        $definitions = $this->authored->normalizeFieldDefinitions(
            $this->decodeFieldDefinitions($validated['field_definitions'] ?? null)
        );

        $templateModel->update([
            'name' => $validated['name'],
            'body_html' => $validated['body_html'],
            'field_definitions' => $definitions,
            'version' => $templateModel->version + 1,
        ]);

        return redirect()
            ->route('templates.show', $templateModel)
            ->with('toast', ['tone' => 'ok', 'message' => "Template updated — now v{$templateModel->version}."]);
    }

    /**
     * Publish a draft template. Requires the template_editor role —
     * 403 otherwise (contract §Templates; verdict test_template_generate).
     * Audit: template.published.
     */
    public function publish(Request $request, string $template): RedirectResponse
    {
        $actor = $this->actor($request);
        $templateModel = $this->resolveTemplate($actor, $template);

        if (! $this->canPublish($actor)) {
            abort(403, 'Publishing templates requires the template_editor role.');
        }

        if ($templateModel->status === 'published') {
            return redirect()
                ->route('templates.show', $templateModel)
                ->with('toast', ['tone' => 'info', 'message' => 'Template is already published.']);
        }

        $templateModel->update(['status' => 'published']);

        AuditLogger::log('template.published', $actor, [
            'actor_id' => (string) $actor->getKey(),
            'template_id' => (string) $templateModel->getKey(),
            'name' => $templateModel->name,
            'version' => $templateModel->version,
        ], explicitOrgId: (string) $actor->org_id);

        return redirect()
            ->route('templates.show', $templateModel)
            ->with('toast', ['tone' => 'ok', 'message' => 'Template published — available for generation.']);
    }

    /**
     * Generate flow, step 1: field form rendered from the template's
     * field definitions. matter_field / party fields are pre-filled from
     * the target matter. Requires :edit on the target matter.
     */
    public function generateForm(Request $request, string $template): View
    {
        $actor = $this->actor($request);
        $templateModel = $this->resolveTemplate($actor, $template);
        $matter = $this->resolveTargetMatter($request, $actor);

        $prefilled = [];

        foreach ($templateModel->fields() as $def) {
            $prefilled[$def['name']] = $this->authored->prefillFromMatter($def, $matter)
                ?? $def['default']
                ?? null;
        }

        return view('templates.generate', [
            'template' => $templateModel,
            'matter' => $matter,
            'prefilled' => $prefilled,
            // Precomputed here: complex expressions (quotes, ternaries,
            // array access) inside Blade bound :attributes silently corrupt
            // the whole template's compilation — every bound attribute in
            // the view is a plain variable.
            'defaultTitle' => $templateModel->name.' — '.now()->toDateString(),
            'genTitle' => 'Generate document',
            'genSubtitle' => $templateModel->name.' v'.$templateModel->version.' → '.$matter->matter_number.' · '.$matter->title,
        ]);
    }

    /**
     * Generate flow, step 2: validate required fields (422
     * `field_required:{name}`), merge values into the body HTML, and
     * create an authored Document v1 linked to the source template
     * version. Audit: template.used (+ document.version.created).
     */
    public function generate(Request $request, string $template): RedirectResponse
    {
        $actor = $this->actor($request);
        $templateModel = $this->resolveTemplate($actor, $template);
        $matter = $this->resolveTargetMatter($request, $actor);

        if ($templateModel->status !== 'published') {
            throw ValidationException::withMessages([
                'template' => ['Only published templates can generate documents.'],
            ]);
        }

        /** @var array{title: string, fields?: array<string, mixed>} $validated */
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'fields' => ['nullable', 'array'],
        ]);

        $html = $this->authored->mergeFields(
            $templateModel->body_html,
            $templateModel->fields(),
            $matter,
            $validated['fields'] ?? []
        );

        $document = Document::create([
            'org_id' => $matter->org_id,
            'matter_id' => $matter->getKey(),
            'title' => $validated['title'],
            'kind' => 'generated',
            'status' => 'processing',
            'template_id' => $templateModel->getKey(),
            'template_version' => $templateModel->version,
            'created_by' => $actor->getKey(),
        ]);

        $version = $this->authored->publishVersion($document, $html, null, $actor);

        AuditLogger::log('template.used', $actor, [
            'actor_id' => (string) $actor->getKey(),
            'matter_id' => (string) $matter->getKey(),
            'template_id' => (string) $templateModel->getKey(),
            'template_version' => $templateModel->version,
            'document_id' => (string) $document->getKey(),
            'version_id' => (string) $version->getKey(),
        ], $matter);

        AuditLogger::log('document.version.created', $actor, [
            'actor_id' => (string) $actor->getKey(),
            'matter_id' => (string) $matter->getKey(),
            'document_id' => (string) $document->getKey(),
            'version_id' => (string) $version->getKey(),
            'version_number' => $version->version_number,
        ], $matter);

        return redirect()
            ->route('documents.editor.edit', [$matter, $document])
            ->with('toast', ['tone' => 'ok', 'message' => 'Document generated from template — v1 published with PDF rendition.']);
    }

    /**
     * Field definitions arrive as a JSON string (hidden form field) or an
     * already-decoded array.
     */
    private function decodeFieldDefinitions(mixed $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            if (! is_array($decoded)) {
                throw ValidationException::withMessages([
                    'field_definitions' => ['Field definitions must be valid JSON.'],
                ]);
            }

            return $decoded;
        }

        return $raw;
    }

    /**
     * Resolve a template inside the actor's org. Cross-org template
     * ids 404 — titles never leak (leak sentinels, 00-brief.md).
     *
     * @throws AccessDeniedException 404 when the template is not in this org
     */
    private function resolveTemplate(User $actor, string $id): DocumentTemplate
    {
        if (! Str::isUuid($id)) {
            throw new AccessDeniedException(404);
        }

        $template = DocumentTemplate::query()
            ->whereKey($id)
            ->where('org_id', $actor->org_id)
            ->first();

        if ($template === null) {
            throw new AccessDeniedException(404);
        }

        return $template;
    }

    /**
     * The generate target matter: resolved from `matter_id` and
     * authorized at :edit (contract §Templates). 404-not-403 for
     * existence (006 convention); 403 for insufficient role.
     *
     * @throws AccessDeniedException
     */
    private function resolveTargetMatter(Request $request, User $actor): Matter
    {
        $raw = $request->input('matter_id');

        $matter = is_string($raw) && Str::isUuid($raw)
            ? Matter::query()->whereKey($raw)->first()
            : null;

        // AccessControl::authorize throws AccessDeniedException (404 for
        // no-access/cross-org/missing, 403 for insufficient role); the
        // bootstrap exception handler renders the {code} JSON bodies.
        AccessControl::authorize($actor, 'edit', $matter);

        \assert($matter instanceof Matter);

        return $matter;
    }

    private function canPublish(User $actor): bool
    {
        return $actor->hasRole('template_editor');
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
