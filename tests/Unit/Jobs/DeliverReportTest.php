<?php

declare(strict_types=1);

use App\Actions\BuildProjectReport;
use App\Enums\ReportFrequency;
use App\Jobs\DeliverReport;
use App\Mail\ReportMail;
use App\Models\NotificationChannel;
use App\Support\ReportMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

function reportFor(NotificationChannel $channel): ReportMessage
{
    return resolve(BuildProjectReport::class)->handle($channel->project, ReportFrequency::Daily, CarbonImmutable::now()->subDay(), CarbonImmutable::now(), 'UTC');
}

it('delivers, and records it on the channel', function (): void {
    $this->freezeSecond();
    Mail::fake();
    $channel = NotificationChannel::factory()->reports()->create(['last_error' => 'old']);

    dispatch_sync(new DeliverReport($channel, reportFor($channel)));

    Mail::assertSent(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo('ops@example.com'));
    expect($channel->refresh()->last_sent_at?->equalTo(now()))->toBeTrue()
        ->and($channel->last_error)->toBeNull();
});

it('records why it gave up, and goes on the notifications connection', function (): void {
    $channel = NotificationChannel::factory()->create();
    $job = new DeliverReport($channel, reportFor($channel));

    $job->failed(new RuntimeException('down'));
    expect($channel->refresh()->last_error)->toBe('down')
        ->and($job->connection)->toBeNull();

    config(['meridian.notifications.connection' => 'redis']);
    expect(new DeliverReport($channel, reportFor($channel))->connection)->toBe('redis');
});
