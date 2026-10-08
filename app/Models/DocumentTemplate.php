<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Template library (007, DOC-22/23, 007-D03). Org-level HTML stationery
 * with {{merge_field}} placeholders and typed field definitions.
 *
 * MERGE FIELDS ONLY — no drafting intelligence of any kind (007-D03):
 * generation fills values from a field form + the matter record, nothing
 * more. Templates are versioned like documents: every content edit bumps
 * `version`, and generated documents record the template version used.
 *
 * Publishing requires the template_editor role (403 otherwise).
 *
 * @property array<int, array<string, mixed>> $fieldDefinitions
 */
class DocumentTemplate extends Model
{
    use HasUuids;

    /**
     * Mirrors the document_templates_status_check constraint.
     *
     * @var list<string>
     */
    public const STATUSES = ['draft', 'published'];

    /**
     * Typed merge-field kinds (03-contract.md §Templates). No content
     * intelligence: matter_field pulls a named matter attribute, party
     * pulls a named matter party — nothing is generated or summarized.
     *
     * @var list<string>
     */
    public const FIELD_TYPES = ['text', 'date', 'number', 'party', 'matter_field'];

    /**
     * Allowlisted matter attributes a matter_field definition may pull.
     *
     * @var list<string>
     */
    public const MATTER_FIELD_SOURCES = [
        'title',
        'matter_number',
        'client_name',
        'matter_type',
        'description',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'org_id',
        'name',
        'body_html',
        'field_definitions',
        'version',
        'status',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'field_definitions' => 'array',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Field definitions as a list (jsonb casts to array; default to []).
     *
     * @return list<array<string, mixed>>
     */
    public function fields(): array
    {
        $defs = $this->field_definitions ?? [];

        if (! is_array($defs)) {
            $decoded = json_decode((string) $defs, true);
            $defs = is_array($decoded) ? $decoded : [];
        }

        return array_values($defs);
    }
}
