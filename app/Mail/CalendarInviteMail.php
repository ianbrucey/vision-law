<?php

namespace App\Mail;

use App\Models\CalendarItem;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Single-item calendar invitation (009 T-14, 009-D22): the mail
 * carries the item title and its times only — never the item
 * description or other matter facts — plus the .ics attachment
 * produced by IcsService (stable UID, rising SEQUENCE).
 */
class CalendarInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CalendarItem $item,
        public string $ics,
        public string $method = 'REQUEST',
    ) {}

    public function envelope(): Envelope
    {
        $verb = $this->method === 'CANCEL' ? 'Canceled: ' : 'Invitation: ';

        return new Envelope(
            subject: $verb.$this->item->title,
        );
    }

    public function content(): Content
    {
        $when = 'Date to be confirmed';
        if ($this->item->starts_at !== null) {
            $tz = $this->item->timezone !== '' ? $this->item->timezone : 'UTC';
            $when = Carbon::instance($this->item->starts_at)->setTimezone($tz)->format('Y-m-d H:i').' '.$tz;
        } elseif ($this->item->due_date !== null) {
            $when = $this->item->due_date->toDateString();
        }

        $html = '<p>'.e($this->item->title).'</p>'
            .'<p>'.e($when).'</p>'
            .'<p>See the attached calendar file for details.</p>';

        return new Content(htmlString: $html);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->ics, 'invite.ics')
                ->withMime('text/calendar'),
        ];
    }
}
