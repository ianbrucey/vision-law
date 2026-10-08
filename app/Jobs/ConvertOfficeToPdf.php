<?php

namespace App\Jobs;

use App\Models\DocumentVersion;
use App\Services\OfficePreviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued Office/TIFF → PDF conversion (spec 007 T-03).
 *
 * The preview controller converts eagerly on first view for
 * determinism; this job exists for the async processing pipeline
 * (T-06): dispatch it after ingest/scan so converted previews are warm
 * before anyone opens them. Idempotent per version — the rendition
 * cache (UNIQUE version_id + kind) absorbs duplicate dispatches, and a
 * conversion failure is a graceful state, never a poison pill.
 */
class ConvertOfficeToPdf implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $versionId,
    ) {}

    public function handle(OfficePreviewService $service): void
    {
        $version = DocumentVersion::query()->whereKey($this->versionId)->first();

        if (! $version instanceof DocumentVersion) {
            return;
        }

        $service->ensurePdf($version);
    }
}
