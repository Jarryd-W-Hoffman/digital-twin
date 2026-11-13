<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Mail\Mailable;

/**
 * Something a notification channel carries, whatever it's about: a line to
 * lead with, a line of detail, a link, and the same as data for webhooks.
 */
interface ChannelMessage
{
    /**
     * One line, for a subject or a chat message's first line.
     */
    public function subject(): string;

    /**
     * The line under it: where and when.
     */
    public function detail(): string;

    /**
     * Where to follow it up.
     */
    public function link(): string;

    public function linkLabel(): string;

    /**
     * The webhook's event name, such as `alert.raised`.
     */
    public function event(): string;

    /**
     * The Slack emoji to lead with.
     */
    public function mark(): string;

    /**
     * The Adaptive Card colour of its first line.
     */
    public function tone(): string;

    /**
     * The webhook body: what happened, as data.
     *
     * @return array<string, mixed>
     */
    public function payload(): array;

    /**
     * The same, by email.
     */
    public function mail(): Mailable;
}
