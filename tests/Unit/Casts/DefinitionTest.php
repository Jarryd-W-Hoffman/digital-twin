<?php

declare(strict_types=1);

use App\Casts\Definition;
use App\Models\TwinVersion;
use Illuminate\Support\Facades\DB;

it('reads a version saved in an older format in the current one, and saves in the current one', function (): void {
    [, $organisation] = member();
    $version = twinIn($organisation)->latestVersion()->sole();
    $old = [...$version->definition, 'terrain' => ['name' => 'Ground', 'url' => '/a/layer.json']];
    unset($old['format'], $old['terrains']);
    DB::table('twin_versions')->where('id', $version->id)->update(['definition' => json_encode($old)]);

    $read = TwinVersion::query()->findOrFail($version->id)->definition;

    expect($read['format'])->toBe(2)
        ->and($read['terrains'])->toBe([['name' => 'Ground', 'url' => '/a/layer.json']])
        ->and($read)->not->toHaveKey('terrain');

    $version->refresh()->update(['definition' => $old]);
    $stored = json_decode((string) DB::table('twin_versions')->where('id', $version->id)->value('definition'), true);

    expect($stored['format'])->toBe(2)
        ->and($stored)->not->toHaveKey('terrain')
        ->and(json_decode((string) DB::table('twin_versions')->where('id', $version->id)->value('definition'), true))->toBe($read);
});

it('reads a missing definition as an empty one', function (): void {
    [, $organisation] = member();
    $version = twinIn($organisation)->latestVersion()->sole();

    expect(new Definition()->get($version, 'definition', null, []))->toBe(['format' => 2]);
});
