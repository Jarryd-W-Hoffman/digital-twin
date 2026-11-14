<?php

declare(strict_types=1);

use App\Actions\DateProjectAsset;
use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\ProjectAsset;

it('dates an upload, and records who did', function (): void {
    [$user, $organisation] = member();
    $this->actingAs($user);
    $asset = ProjectAsset::factory()->for(twinIn($organisation)->project)->create(['name' => 'March scan']);

    resolve(DateProjectAsset::class)->handle($asset, '2026-03-10');
    resolve(DateProjectAsset::class)->handle($asset, null);

    expect($asset->refresh()->captured_on)->toBeNull()
        ->and(AuditEvent::query()->oldest('id')->get()->map(fn (AuditEvent $event): array => [$event->action, $event->subject, $event->details])->all())->toBe([
            [AuditAction::UploadDated, 'March scan', ['capturedOn' => '2026-03-10']],
            [AuditAction::UploadDated, 'March scan', ['capturedOn' => null]],
        ])
        ->and(AuditAction::UploadDated->label())->toBe('Dated an upload')
        ->and(AuditAction::UploadDated->area())->toBe('projects');
});
