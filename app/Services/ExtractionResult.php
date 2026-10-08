<?php

namespace App\Services;

/**
 * Immutable outcome of MetadataExtractor (spec 007 T-03, DOC-04).
 *
 * status "ok" → documents.metadata_status = ok; "partial" → partial
 * (document stays usable). needsOcr routes image-only content to the
 * T-06 OCR pipeline. toArray() is the shape stored in
 * documents.metadata (jsonb).
 */
class ExtractionResult
{
    /**
     * @param  array<string, mixed>  $properties  document properties (title, author, created, …)
     * @param  list<string>  $notes  human-readable extraction notes
     */
    public function __construct(
        public readonly ?int $pageCount,
        public readonly array $properties,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?float $dpi,
        public readonly bool $needsOcr,
        public readonly string $status,
        public readonly array $notes,
    ) {}

    /**
     * @param  list<string>  $notes
     */
    public static function partial(array $notes): self
    {
        return new self(
            pageCount: null,
            properties: [],
            width: null,
            height: null,
            dpi: null,
            needsOcr: false,
            status: 'partial',
            notes: $notes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'page_count' => $this->pageCount,
            'properties' => $this->properties,
            'width' => $this->width,
            'height' => $this->height,
            'dpi' => $this->dpi,
            'needs_ocr' => $this->needsOcr,
            'status' => $this->status,
            'notes' => $this->notes,
        ];
    }
}
