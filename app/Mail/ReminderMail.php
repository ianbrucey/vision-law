<?php

namespace App\Mail;

use App\Models\ReminderDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Reminder email (009-D17 redaction): NEUTRAL subject and body —
 * no matter title, no item title, no facts. Only a generic notice
 * plus a secure link to the item page (behind auth).
 */
class ReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $itemUrl;

    public function __construct(
        public ReminderDelivery $delivery,
    ) {
        $this->itemUrl = route('calendar.items.show', (string) $delivery->item_id);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You have a new deadline notification in Vision Law',
        );
    }

    public function content(): Content
    {
        $html = '<p>You have a new deadline notification in Vision Law.</p>'
            .'<p><a href="'.e($this->itemUrl).'">Sign in to view it</a></p>'
            .'<p>If you did not expect this notification, you can safely ignore it.</p>';

        return new Content(htmlString: $html);
    }
}
