<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invitation email (C-05). Carries the plaintext token — the invitee is the
 * authorized actor for their own token — inside the accept URL. Only the
 * SHA-256 hash is ever stored (see InvitationService).
 */
class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $inviteeEmail,
        public readonly string $orgName,
        public readonly string $role,
        public readonly string $acceptUrl,
        public readonly string $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You have been invited to join '.$this->orgName.' on Vision Law',
        );
    }

    public function content(): Content
    {
        $html = '<p>You have been invited to join <strong>'.e($this->orgName).'</strong>'
            .' on Vision Law with the role <strong>'.e($this->role).'</strong>.</p>'
            .'<p><a href="'.e($this->acceptUrl).'">Accept your invitation</a></p>'
            .'<p>This invitation expires on '.e($this->expiresAt).'. If you did not expect'
            .' this invitation, you can safely ignore it.</p>';

        return new Content(htmlString: $html);
    }
}
