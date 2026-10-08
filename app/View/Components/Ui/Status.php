<?php

namespace App\View\Components\Ui;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * <x-ui.status> — renders a matter, document, or draft status from the
 * canonical label+tone map (spec 002 03-contract.md).
 *
 * Statuses are never rendered as raw enum text — always through this
 * component. Fail-closed: unknown types/values render a neutral "Unknown"
 * chip, never raw enum text, never an exception.
 *
 * The map extends per feature; new values go through docs/UI_Standards.md first.
 */
class Status extends Component
{
    /**
     * Canonical status map: type => value => ['label' => ..., 'tone' => ...].
     * Tones are <x-ui.chip> tones.
     *
     * @var array<string, array<string, array{label: string, tone: string}>>
     */
    public const MAP = [
        // Spec 006 T-05: the 8 lifecycle states (Matter::LIFECYCLE_STATES,
        // 03-contract.md §Lifecycle transition table). Tones follow the
        // approved 05-ui-mockup.html badge palette, mapped onto the five
        // <x-ui.chip> tones — color is never the only signal, every badge
        // pairs its tone with a text label.
        'matter' => [
            'INTAKE' => ['label' => 'Intake', 'tone' => 'info'],
            'ACTIVE' => ['label' => 'Active', 'tone' => 'ok'],
            'DISCOVERY' => ['label' => 'Discovery', 'tone' => 'info'],
            'PRE_TRIAL' => ['label' => 'Pre-trial', 'tone' => 'warn'],
            'TRIAL_SETTLEMENT' => ['label' => 'Trial / settlement', 'tone' => 'warn'],
            'CLOSED' => ['label' => 'Closed', 'tone' => 'neutral'],
            'RETENTION_HOLD' => ['label' => 'Retention hold', 'tone' => 'warn'],
            'DISPOSITION' => ['label' => 'Disposition', 'tone' => 'bad'],
        ],
        'document' => [
            'draft' => ['label' => 'Draft', 'tone' => 'warn'],
            'final' => ['label' => 'Final', 'tone' => 'ok'],
            'superseded' => ['label' => 'Superseded', 'tone' => 'neutral'],
            // Spec 007 T-04: document statuses per the 007 contract
            // (02-schema-delta.md documents.status).
            'processing' => ['label' => 'Processing', 'tone' => 'info'],
            'ready' => ['label' => 'Ready', 'tone' => 'ok'],
            'quarantined' => ['label' => 'Quarantined', 'tone' => 'bad'],
            'trash' => ['label' => 'Trash', 'tone' => 'neutral'],
        ],
        // Spec 007 T-04: template statuses (document_templates.status).
        'template' => [
            'draft' => ['label' => 'Draft', 'tone' => 'warn'],
            'published' => ['label' => 'Published', 'tone' => 'ok'],
        ],
        'draft' => [
            'in_progress' => ['label' => 'In progress', 'tone' => 'info'],
            'in_review' => ['label' => 'In review', 'tone' => 'warn'],
            'approved' => ['label' => 'Approved', 'tone' => 'ok'],
            'filed' => ['label' => 'Filed', 'tone' => 'ok'],
        ],
        'invitation' => [
            'pending' => ['label' => 'Pending', 'tone' => 'info'],
            'accepted' => ['label' => 'Accepted', 'tone' => 'ok'],
            'revoked' => ['label' => 'Revoked', 'tone' => 'neutral'],
            'expired' => ['label' => 'Expired', 'tone' => 'warn'],
        ],
    ];

    public string $label;

    public string $tone;

    public function __construct(
        public string $type,
        public string $value,
    ) {
        $row = self::MAP[$type][$value] ?? null;

        // Fail-closed: unknown values render a neutral "Unknown" chip.
        $this->label = $row['label'] ?? 'Unknown';
        $this->tone = $row['tone'] ?? 'neutral';
    }

    public function render(): View
    {
        return view('components.ui.status');
    }
}
