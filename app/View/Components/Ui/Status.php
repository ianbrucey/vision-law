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
        'matter' => [
            'open' => ['label' => 'Open', 'tone' => 'info'],
            'active' => ['label' => 'Active', 'tone' => 'ok'],
            'on_hold' => ['label' => 'On hold', 'tone' => 'warn'],
            'closed' => ['label' => 'Closed', 'tone' => 'neutral'],
            'archived' => ['label' => 'Archived', 'tone' => 'neutral'],
        ],
        'document' => [
            'draft' => ['label' => 'Draft', 'tone' => 'warn'],
            'final' => ['label' => 'Final', 'tone' => 'ok'],
            'superseded' => ['label' => 'Superseded', 'tone' => 'neutral'],
        ],
        'draft' => [
            'in_progress' => ['label' => 'In progress', 'tone' => 'info'],
            'in_review' => ['label' => 'In review', 'tone' => 'warn'],
            'approved' => ['label' => 'Approved', 'tone' => 'ok'],
            'filed' => ['label' => 'Filed', 'tone' => 'ok'],
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
