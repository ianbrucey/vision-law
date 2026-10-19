<?php

namespace App\Services\Calendaring;

use App\Exceptions\AccessDeniedException;
use App\Models\DeadlineTemplate;
use App\Models\DeadlineTemplateVersion;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Deadline template catalog service (009-D18, T-10).
 *
 * Governance law:
 * - Templates are org-owned; every read/write is scoped to the
 *   actor's org (cross-org resolves as not-found — no existence
 *   leak). No matter access is involved: this is an org catalog.
 * - Version lifecycle: draft → pending_review → published →
 *   retired, service-only. Publish is allowed ONLY from
 *   pending_review and ONLY by an actor other than the version's
 *   creator (maker-checker); violations are 422s.
 * - A published version is immutable: updateVersionRules() on a
 *   published (or retired) version forks a NEW draft version with
 *   the new rules; the original row is never touched.
 * - "Current published version" is derived: the published version
 *   with the highest version number. Publishing retires nothing
 *   automatically — an older published version keeps its status but
 *   stops being current once a newer one publishes.
 * - Rules are validated at save and at publish via
 *   DeadlineEngine::validate() (allowlisted ops/units only, acyclic
 *   from/anchor graph); invalid rules are a 422 with the errors.
 */
class TemplateService
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function createTemplate(User $actor, array $data): DeadlineTemplate
    {
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'jurisdiction_label' => ['nullable', 'string', 'max:255'],
            'owner_label' => ['nullable', 'string', 'max:500'],
            'demonstration' => ['nullable', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($actor, $validated): DeadlineTemplate {
            $template = DeadlineTemplate::create([
                'org_id' => (string) $actor->org_id,
                'name' => (string) $validated['name'],
                'jurisdiction_label' => $validated['jurisdiction_label'] ?? null,
                'demonstration' => isset($validated['demonstration']) ? (bool) $validated['demonstration'] : true,
                'owner_label' => $validated['owner_label'] ?? null,
                'created_by' => (string) $actor->getKey(),
            ]);

            AuditLogger::log('calendar.template.created', $actor, [
                'template_id' => (string) $template->getKey(),
                'name' => $template->name,
            ], explicitOrgId: (string) $actor->org_id);

            return $template;
        });
    }

    /**
     * Create the next draft version. Rules come from $data['rules']
     * (or $data['content']); when omitted and the template has a
     * published version, the new draft starts as a copy of the
     * current published version's rules.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function createVersion(User $actor, DeadlineTemplate $template, array $data): DeadlineTemplateVersion
    {
        self::assertSameOrg($actor, $template);

        $rules = $data['rules'] ?? $data['content'] ?? null;
        if ($rules === null) {
            $published = self::currentPublished($template);
            $rules = $published !== null ? $published->rules : [];
        }
        if (! is_array($rules)) {
            throw ValidationException::withMessages(['rules' => 'Rules must be a JSON object or list.']);
        }

        self::assertValidRules($rules);

        return DB::transaction(function () use ($actor, $template, $rules): DeadlineTemplateVersion {
            $next = (int) (DeadlineTemplateVersion::query()
                ->where('template_id', (string) $template->getKey())
                ->max('version') ?? 0) + 1;

            $version = DeadlineTemplateVersion::create([
                'template_id' => (string) $template->getKey(),
                'version' => $next,
                'status' => 'draft',
                'rules' => $rules,
                'created_by' => (string) $actor->getKey(),
            ]);

            AuditLogger::log('calendar.template.version_created', $actor, [
                'template_id' => (string) $template->getKey(),
                'version_id' => (string) $version->getKey(),
                'version' => $version->version,
            ], explicitOrgId: (string) $actor->org_id);

            return $version;
        });
    }

    /**
     * Edit a version's rules. Draft / pending_review versions update
     * in place; a published or retired version is immutable, so the
     * edit forks a new draft version carrying the new rules and the
     * original is returned untouched in the database (the FORK is
     * returned).
     *
     * @param  array<string, mixed>|list<array<string, mixed>>  $rules
     *
     * @throws ValidationException
     */
    public static function updateVersionRules(User $actor, DeadlineTemplateVersion $version, array $rules): DeadlineTemplateVersion
    {
        $template = $version->template;
        self::assertSameOrg($actor, $template);
        self::assertValidRules($rules);

        if (in_array($version->status, ['published', 'retired'], true)) {
            return self::createVersion($actor, $template, ['rules' => $rules]);
        }

        $version->update(['rules' => $rules]);

        return $version->fresh() ?? $version;
    }

    /**
     * draft → pending_review.
     *
     * @throws ValidationException
     */
    public static function submit(User $actor, DeadlineTemplateVersion $version): DeadlineTemplateVersion
    {
        $template = $version->template;
        self::assertSameOrg($actor, $template);

        if ($version->status !== 'draft') {
            throw ValidationException::withMessages([
                'version' => "Only a draft version can be submitted for review (current status: {$version->status}).",
            ]);
        }

        return DB::transaction(function () use ($actor, $version, $template): DeadlineTemplateVersion {
            $version->update(['status' => 'pending_review']);

            AuditLogger::log('calendar.template.submitted', $actor, [
                'template_id' => (string) $template->getKey(),
                'version_id' => (string) $version->getKey(),
                'version' => $version->version,
            ], explicitOrgId: (string) $actor->org_id);

            return $version->fresh() ?? $version;
        });
    }

    /**
     * pending_review → published, maker-checker enforced: the
     * publisher must differ from the version's creator.
     *
     * @throws ValidationException
     */
    public static function publish(User $actor, DeadlineTemplateVersion $version): DeadlineTemplateVersion
    {
        $template = $version->template;
        self::assertSameOrg($actor, $template);

        if ($version->status !== 'pending_review') {
            throw ValidationException::withMessages([
                'version' => "Only a version pending review can be published (current status: {$version->status}).",
            ]);
        }

        if ((string) $version->created_by === (string) $actor->getKey()) {
            throw ValidationException::withMessages([
                'version' => 'Maker-checker: the version creator cannot publish their own version; a second user must publish it.',
            ]);
        }

        self::assertValidRules($version->rules);

        return DB::transaction(function () use ($actor, $version, $template): DeadlineTemplateVersion {
            $version->update([
                'status' => 'published',
                'reviewed_by' => (string) $actor->getKey(),
                'published_at' => now(),
            ]);

            AuditLogger::log('calendar.template.published', $actor, [
                'template_id' => (string) $template->getKey(),
                'version_id' => (string) $version->getKey(),
                'version' => $version->version,
            ], explicitOrgId: (string) $actor->org_id);

            return $version->fresh() ?? $version;
        });
    }

    /**
     * published → retired.
     *
     * @throws ValidationException
     */
    public static function retire(User $actor, DeadlineTemplateVersion $version): DeadlineTemplateVersion
    {
        $template = $version->template;
        self::assertSameOrg($actor, $template);

        if ($version->status !== 'published') {
            throw ValidationException::withMessages([
                'version' => "Only a published version can be retired (current status: {$version->status}).",
            ]);
        }

        return DB::transaction(function () use ($actor, $version, $template): DeadlineTemplateVersion {
            $version->update(['status' => 'retired']);

            AuditLogger::log('calendar.template.retired', $actor, [
                'template_id' => (string) $template->getKey(),
                'version_id' => (string) $version->getKey(),
                'version' => $version->version,
            ], explicitOrgId: (string) $actor->org_id);

            return $version->fresh() ?? $version;
        });
    }

    /**
     * The current published version (derived): the published version
     * with the highest version number, or null.
     */
    public static function currentPublished(DeadlineTemplate $template): ?DeadlineTemplateVersion
    {
        return DeadlineTemplateVersion::query()
            ->where('template_id', (string) $template->getKey())
            ->where('status', 'published')
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $rules
     *
     * @throws ValidationException
     */
    private static function assertValidRules(array $rules): void
    {
        $errors = app(DeadlineEngine::class)->validate($rules);
        if ($errors !== []) {
            throw ValidationException::withMessages(['rules' => $errors]);
        }
    }

    private static function assertSameOrg(User $actor, DeadlineTemplate $template): void
    {
        if ((string) $actor->org_id !== (string) $template->org_id) {
            throw new AccessDeniedException(404);
        }
    }
}
