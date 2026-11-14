<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\ReportMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A project's report by email: the totals, then a table per twin.
 */
final class ReportMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ReportMessage $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->report->subject());
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.report');
    }
}
