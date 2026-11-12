<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\AlertMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An alert raised or cleared, by email. Queued if sent on its own, but
 * notifications send it straight away from their own queued delivery, so
 * a failure reaches the delivery's retries.
 */
final class AlertMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly AlertMessage $alert) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->alert->subject());
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.alert');
    }
}
