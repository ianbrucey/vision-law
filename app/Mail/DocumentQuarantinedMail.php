<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Admin alert: an upload was flagged by ClamAV and quarantined (C-03).
 * Metadata only — never bytes, paths, or hashes.
 */
class DocumentQuarantinedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $orgName,
        public readonly string $documentTitle,
        public readonly string $matterNumber,
        public readonly string $uploaderName,
        public readonly string $signature,
        public readonly string $quarantinedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Vision Law] Malicious file quarantined — '.$this->documentTitle,
        );
    }

    public function content(): Content
    {
        $html = '<p>ClamAV flagged an uploaded file as malware and it has been'
            .' quarantined. It cannot be previewed or downloaded.</p>'
            .'<dl>'
            .'<dt>Document</dt><dd>'.e($this->documentTitle).'</dd>'
            .'<dt>Matter</dt><dd>'.e($this->matterNumber).'</dd>'
            .'<dt>Uploaded by</dt><dd>'.e($this->uploaderName).'</dd>'
            .'<dt>Threat</dt><dd>'.e($this->signature).'</dd>'
            .'<dt>Quarantined at</dt><dd>'.e($this->quarantinedAt).'</dd>'
            .'</dl>'
            .'<p>Organization: '.e($this->orgName).'</p>';

        return new Content(htmlString: $html);
    }
}
