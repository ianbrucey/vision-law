<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentRendition;
use App\Models\DocumentTemplate;
use App\Models\DocumentVersion;
use App\Models\Matter;
use App\Models\MatterParty;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Authored documents + template stationery (007 T-04, DOC-09/10, DOC-22/23).
 *
 * The editor is a typing surface and the template library is merge-field
 * stationery — NO drafting intelligence of any kind (007-D03). This
 * service publishes content versions (immutable, gapless) and fills
 * {{merge_field}} placeholders from a field form + the matter record.
 */
class AuthoredDocumentService
{
    public function __construct(
        private readonly DocumentStore $store,
        private readonly PdfRenditionService $renditions,
    ) {}

    /**
     * Publish a new immutable version of an authored/generated document.
     *
     * Stores the structured HTML, bumps the gapless version_number under a
     * row lock, points the document at the new current version, clears the
     * ephemeral draft, and attaches the generated PDF rendition. Uploaded
     * binaries are rejected here — they version via "upload new version"
     * (T-02/T-07), never through the editor.
     *
     * @throws \LogicException when the document is not authored/generated.
     */
    public function publishVersion(
        Document $document,
        string $html,
        ?string $changeNote,
        User $actor,
    ): DocumentVersion {
        if (! in_array($document->kind, ['authored', 'generated'], true)) {
            throw new \LogicException('Only authored or generated documents can be published from the editor.');
        }

        return DB::transaction(function () use ($document, $html, $changeNote, $actor): DocumentVersion {
            // Gapless version_number: lock the latest version row (FOR UPDATE
            // with ORDER BY + LIMIT is legal in Postgres; FOR UPDATE with an
            // aggregate is not) so concurrent publishers serialize.
            $max = DocumentVersion::query()
                ->where('document_id', $document->getKey())
                ->lockForUpdate()
                ->orderByDesc('version_number')
                ->value('version_number');

            $blob = $this->store->put($html, ['mime' => 'text/html']);

            $version = DocumentVersion::create([
                'document_id' => $document->getKey(),
                'version_number' => ((int) $max) + 1,
                'blob_id' => $blob->getKey(),
                'change_note' => $changeNote,
                'processing_status' => 'ready',
                'created_by' => $actor->getKey(),
            ]);

            $pdfBlob = $this->renditions->fromHtml($html);

            DocumentRendition::create([
                'version_id' => $version->getKey(),
                'kind' => 'pdf',
                'blob_id' => $pdfBlob->getKey(),
            ]);

            $document->update([
                'current_version_id' => $version->getKey(),
                'status' => 'ready',
                'draft_content' => null,
                'draft_updated_at' => null,
            ]);

            return $version;
        });
    }

    /**
     * Validate + normalize template field definitions from the request.
     * Each definition: {name, type, required?, default?, label?, source?}.
     * matter_field definitions must name an allowlisted matter attribute;
     * party definitions must name a MatterParty::TYPES value.
     *
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function normalizeFieldDefinitions(mixed $input): array
    {
        if (! is_array($input)) {
            throw ValidationException::withMessages([
                'field_definitions' => ['Field definitions must be a list.'],
            ]);
        }

        $defs = [];
        $seen = [];

        foreach (array_values($input) as $i => $def) {
            if (! is_array($def)) {
                throw ValidationException::withMessages([
                    "field_definitions.{$i}" => ['Each field definition must be an object.'],
                ]);
            }

            $name = $def['name'] ?? null;
            $type = $def['type'] ?? null;

            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
                throw ValidationException::withMessages([
                    "field_definitions.{$i}.name" => ['Field name must be snake_case, starting with a letter.'],
                ]);
            }

            if (isset($seen[$name])) {
                throw ValidationException::withMessages([
                    "field_definitions.{$i}.name" => ['Duplicate field name.'],
                ]);
            }
            $seen[$name] = true;

            if (! in_array($type, DocumentTemplate::FIELD_TYPES, true)) {
                throw ValidationException::withMessages([
                    "field_definitions.{$i}.type" => ['Unknown field type. Allowed: '.implode(', ', DocumentTemplate::FIELD_TYPES)],
                ]);
            }

            $source = $def['source'] ?? null;

            if ($type === 'matter_field') {
                if (! in_array($source, DocumentTemplate::MATTER_FIELD_SOURCES, true)) {
                    throw ValidationException::withMessages([
                        "field_definitions.{$i}.source" => ['matter_field source must be one of: '.implode(', ', DocumentTemplate::MATTER_FIELD_SOURCES)],
                    ]);
                }
            }

            if ($type === 'party') {
                if (! in_array($source, MatterParty::TYPES, true)) {
                    throw ValidationException::withMessages([
                        "field_definitions.{$i}.source" => ['party source must be a party type: '.implode(', ', MatterParty::TYPES)],
                    ]);
                }
            }

            $defs[] = [
                'name' => $name,
                'label' => is_string($def['label'] ?? null) ? $def['label'] : $name,
                'type' => $type,
                'required' => (bool) ($def['required'] ?? false),
                'default' => $def['default'] ?? null,
                'source' => $source,
            ];
        }

        return $defs;
    }

    /**
     * Fill {{merge_field}} placeholders in template HTML with HTML-escaped
     * values. Value precedence: provided form value → matter pre-fill
     * (matter_field / party types) → definition default. Required fields
     * with no value raise 422 `field_required:{name}` (contract §Templates).
     *
     * This is pure substitution — no summarization, no clause extraction,
     * no agent fill (007-D03).
     *
     * @param  list<array<string, mixed>>  $definitions
     * @param  array<string, mixed>  $values  form-provided values
     *
     * @throws ValidationException on missing required fields
     */
    public function mergeFields(string $bodyHtml, array $definitions, Matter $matter, array $values): string
    {
        $resolved = [];

        foreach ($definitions as $def) {
            $name = (string) $def['name'];
            $value = $values[$name] ?? null;

            if ($value === null || $value === '') {
                $value = $this->prefillFromMatter($def, $matter) ?? $def['default'] ?? null;
            }

            if (($def['required'] ?? false) && ($value === null || $value === '')) {
                throw ValidationException::withMessages([
                    "fields.{$name}" => ["Field '{$name}' is required (field_required:{$name})."],
                ]);
            }

            $resolved[$name] = $value === null ? '' : (string) $value;
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z][a-z0-9_]{0,63})\s*\}\}/',
            fn (array $m): string => array_key_exists($m[1], $resolved)
                ? e($resolved[$m[1]])
                : $m[0],
            $bodyHtml
        );
    }

    /**
     * Pre-fill a value from the matter record for matter_field / party
     * field types. Everything else returns null (manual form input).
     *
     * @param  array<string, mixed>  $def
     */
    public function prefillFromMatter(array $def, Matter $matter): ?string
    {
        $type = $def['type'] ?? null;

        if ($type === 'matter_field') {
            $source = (string) ($def['source'] ?? '');

            if (in_array($source, DocumentTemplate::MATTER_FIELD_SOURCES, true)) {
                $value = $matter->getAttribute($source);

                return $value === null ? null : (string) $value;
            }

            return null;
        }

        if ($type === 'party') {
            $source = (string) ($def['source'] ?? '');

            if (in_array($source, MatterParty::TYPES, true)) {
                $party = $matter->parties()->where('party_type', $source)->orderBy('created_at')->first();

                return $party?->name;
            }
        }

        return null;
    }
}
