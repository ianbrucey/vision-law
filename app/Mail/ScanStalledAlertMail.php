<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Ops alert: a document has been waiting for a malware scan longer than
 * the configured threshold because the ClamAV engine is unreachable
 * (C-03). The document stays `scanning` — it is never silently marked
 * clean — until the engine recovers and the sweeper re-scans it.
 */
class ScanStalledAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $documentTitle,
        public readonly string $matterNumber,
        public readonly string $versionId,
        public readonly int $stuckMinutes,
        public readonly string $socketPath,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Vision Law] Document scan stalled — ClamAV unreachable',
        );
    }

    public function content(): Content
    {
        $html = '<p>A document upload has been waiting for its malware scan for'
            .' '.$this->stuckMinutes.' minutes because the ClamAV engine is'
            .' unreachable. The document remains in <code>scanning</code> and'
            .' is not available.</p>'
            .'<dl>'
            .'<dt>Document</dt><dd>'.e($this->documentTitle).'</dd>'
            .'<dt>Matter</dt><dd>'.e($this->matterNumber).'</dd>'
            .'<dt>Version id</dt><dd>'.e($this->versionId).'</dd>'
            .'<dt>Engine socket</dt><dd>'.e($this->socketPath).'</dd>'
            .'</dl>'
            .'<p>Check the clamav-daemon service on the document host.</p>';

        return new Content(htmlString: $html);
    }
}
