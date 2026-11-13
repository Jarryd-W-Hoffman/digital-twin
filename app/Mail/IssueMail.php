<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\IssueMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An issue raised, assigned or closed, by email: to a channel's addresses,
 * or to the person it was given to.
 */
final class IssueMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly IssueMessage $issue, public readonly bool $toAssignee = false) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->toAssignee
            ? __('Issue #:number is yours: :title (:twin)', ['number' => $this->issue->number, 'title' => $this->issue->title, 'twin' => $this->issue->twin])
            : $this->issue->subject());
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.issue');
    }
}
