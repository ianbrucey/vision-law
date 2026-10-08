<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentTextPage;
use App\Models\DocumentVersion;
use App\Models\Matter;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Extracted/OCR text-layer download (spec 007 T-06, DOC-16).
 *
 * GET /matters/{matter}/documents/{document}/text — the document's
 * extracted text (native or OCR) as a plain-text file, one section per
 * page. The route carries matter.access:view; missing matter access is
 * a 404 (no existence leak); quarantined documents 403.
 *
 * Every download is audited as document.downloaded (C-06 pattern) —
 * the text layer is document content, not metadata.
 */
class DocumentTextController extends Controller
{
    public function download(Request $request, string $matter, string $document): StreamedResponse
    {
        $matterModel = $this->authorizedMatter($request);
        $actor = $this->actor($request);

        $doc = Document::query()
            ->where('id', $document)
            ->where('matter_id', $matterModel->getKey())
            ->first();

        if (! $doc instanceof Document) {
            abort(404);
        }

        if ($doc->status === 'quarantined') {
            AuditLogger::log('document.download.denied', $actor, [
                'actor_id' => (string) $actor->getKey(),
                'document_id' => (string) $doc->getKey(),
                'reason' => 'quarantined',
            ], $matterModel);

            abort(response()->json(['code' => 'quarantined'], 403));
        }

        $version = $doc->currentVersion;
        if (! $version instanceof DocumentVersion) {
            abort(404);
        }

        $pages = DocumentTextPage::query()
            ->where('version_id', $version->getKey())
            ->orderBy('page_number')
            ->get(['page_number', 'text']);

        if ($pages->isEmpty()) {
            abort(404);
        }

        $text = $pages
            ->map(fn (DocumentTextPage $p): string => "─── Page {$p->page_number} ───\n{$p->text}")
            ->implode("\n\n");

        AuditLogger::log('document.downloaded', $actor, [
            'actor_id' => (string) $actor->getKey(),
            'document_id' => (string) $doc->getKey(),
            'version_id' => (string) $version->getKey(),
            'kind' => 'text_layer',
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ], $matterModel);

        $filename = $this->textFilename($doc->title);

        return response()->streamDownload(
            function () use ($text): void {
                echo $text;
            },
            $filename,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'X-Checksum-Sha256' => hash('sha256', $text),
            ]
        );
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

    private function textFilename(string $title): string
    {
        $base = trim($title) === '' ? 'document' : $title;
        $base = (string) preg_replace('/[^\p{L}\p{N} _-]+/u', '', $base);
        $base = trim($base) === '' ? 'document' : trim($base);

        return mb_substr($base, 0, 120).'.txt';
    }
}
