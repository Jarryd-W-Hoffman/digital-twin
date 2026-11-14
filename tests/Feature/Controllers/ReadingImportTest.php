<?php

declare(strict_types=1);

use App\Actions\ImportReadings;
use App\Actions\RecordReadings;
use App\Enums\AuditAction;
use App\Enums\OrganisationRole;
use App\Enums\ReadingImportStatus;
use App\Jobs\ImportReadingFile;
use App\Models\AuditEvent;
use App\Models\Reading;
use App\Models\ReadingImport;
use App\Models\Twin;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/**
 * Imports a CSV file into the twin as the queue would, and returns the
 * import as it ended. The depot is 12 hours ahead of UTC.
 *
 * @param  array<string, string|null>  $settings
 * @param  list<array{name: string, asset: string, metric: string}>  $series
 */
function importCsv(Twin $twin, string $csv, array $settings = [], array $series = [['name' => 'Store temperature', 'asset' => 'store', 'metric' => 'tempC']]): ReadingImport
{
    $import = ReadingImport::factory()->for($twin)->create([
        'path' => 'imports/readings.csv',
        'settings' => ['layout' => 'wide', 'delimiter' => ',', 'time' => 'Time', 'format' => 'iso', ...$settings],
        'series' => $series,
    ]);
    Storage::disk('local')->put('imports/readings.csv', $csv);

    new ImportReadingFile($import)->handle(resolve(ImportReadings::class));

    return $import->refresh();
}

/**
 * A twin's readings of a metric, as [UTC time, value].
 *
 * @return list<array{0: string, 1: float}>
 */
function readingsOf(Twin $twin, string $asset = 'store', string $metric = 'tempC'): array
{
    return Reading::query()->where('twin_id', $twin->id)->where('asset', $asset)->where('metric', $metric)
        ->oldest('recorded_at')->get()
        ->map(fn (Reading $r): array => [$r->recorded_at->utc()->format('Y-m-d H:i:s.v'), $r->value])->all();
}

beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo(now()->setDate(2026, 11, 14)->setTime(12, 0));
});

it('lets editors upload a CSV of readings, imported on the queue', function (): void {
    Queue::fake();
    [$user, $organisation] = member(OrganisationRole::Admin);
    $twin = twinIn($organisation);

    $this->actingAs($user)->post(route('twins.reading-imports.store', $twin), [
        'file' => UploadedFile::fake()->createWithContent('store.csv', "Time,Store temperature\n2026-11-10 06:00,1.5\n"),
        'layout' => 'wide',
        'delimiter' => ',',
        'time' => 'Time',
        'format' => 'iso',
        'series' => [['name' => 'Store temperature', 'asset' => 'store', 'metric' => 'tempC']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $import = $twin->readingImports()->sole();
    expect($import->file_name)->toBe('store.csv')
        ->and($import->status)->toBe(ReadingImportStatus::Importing)
        ->and($import->settings)->toBe(['layout' => 'wide', 'delimiter' => ',', 'time' => 'Time', 'format' => 'iso', 'name' => null, 'value' => null])
        ->and($import->created_by)->toBe($user->id)
        ->and(Storage::disk('local')->get((string) $import->path))->toStartWith('Time,Store temperature')
        ->and(AuditEvent::query()->sole()->action)->toBe(AuditAction::ReadingsImported);
    Queue::assertPushed(ImportReadingFile::class, fn (ImportReadingFile $job): bool => $job->import->is($import));
});

it('only lets editors import and remove readings', function (OrganisationRole $role): void {
    [$user, $organisation] = member($role);
    $twin = twinIn($organisation);
    $import = ReadingImport::factory()->for($twin)->imported()->create();

    $this->actingAs($user)->post(route('twins.reading-imports.store', $twin), [
        'file' => UploadedFile::fake()->createWithContent('store.csv', "Time,T\n"),
        'layout' => 'wide', 'delimiter' => ',', 'time' => 'Time', 'format' => 'iso',
        'series' => [['name' => 'T', 'asset' => 'store', 'metric' => 'tempC']],
    ])->assertForbidden();
    $this->actingAs($user)->delete(route('twins.reading-imports.destroy', [$twin, $import]))->assertForbidden();

    expect($twin->readingImports()->count())->toBe(1);
})->with([OrganisationRole::Operator, OrganisationRole::Viewer]);

it('refuses files and mappings it cannot import', function (array $fields, string $error): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);

    $this->actingAs($user)->post(route('twins.reading-imports.store', $twin), [
        'file' => UploadedFile::fake()->createWithContent('store.csv', "Time,T\n"),
        'layout' => 'wide', 'delimiter' => ',', 'time' => 'Time', 'format' => 'iso',
        'series' => [['name' => 'T', 'asset' => 'store', 'metric' => 'tempC']],
        ...$fields,
    ])->assertSessionHasErrors($error);

    expect($twin->readingImports()->count())->toBe(0);
})->with([
    'a spreadsheet' => [['file' => UploadedFile::fake()->create('store.xlsx', 10)], 'file'],
    'an unknown layout' => [['layout' => 'tall'], 'layout'],
    'an unknown delimiter' => [['delimiter' => '#'], 'delimiter'],
    'an unknown time format' => [['format' => 'excel'], 'format'],
    'long without a name column' => [['layout' => 'long', 'value' => 'Value'], 'name'],
    'long without a value column' => [['layout' => 'long', 'name' => 'Sensor'], 'value'],
    'no series' => [['series' => []], 'series'],
    'an asset not in the twin' => [['series' => [['name' => 'T', 'asset' => 'pump', 'metric' => 'tempC']]], 'series.0.asset'],
    'a metric that is not a key' => [['series' => [['name' => 'T', 'asset' => 'store', 'metric' => 'Temp C']]], 'series.0.metric'],
]);

it('imports a wide file, a series per column, in the site time unless the time has an offset', function (): void {
    $twin = twinIn(member()[1]);

    $import = importCsv($twin, "\u{FEFF}Time,Store temperature,Outside\n"
        ."2026-11-10 06:00,1.5,14\n"
        ."2026-11-10T06:30:15.25,1.75,\n"
        ."2026-11-10 07:00Z,2,15\n"
        ."\n"
        ."2026-11-10 07:30+13:00,2.25,16\n"
        ."2026-11-10 08:00 -05:30,2.5,17\n"
        ."2026-11-11,3,18\n", series: [
            ['name' => 'Store temperature', 'asset' => 'store', 'metric' => 'tempC'],
            ['name' => 'Outside', 'asset' => 'wx', 'metric' => 'tempC'],
            ['name' => 'Outside', 'asset' => 'wx', 'metric' => 'feelsC'],
        ]);

    expect($import->status)->toBe(ReadingImportStatus::Done)
        ->and($import->error)->toBeNull()
        ->and($import->path)->toBeNull()
        ->and($import->summary)->toBe(['rows' => 6, 'stored' => 16, 'skipped' => 0, 'problems' => [], 'unmapped' => []])
        ->and($import->first_at?->toIso8601String())->toBe('2026-11-09T18:00:00+00:00')
        ->and($import->last_at?->toIso8601String())->toBe('2026-11-10T13:30:00+00:00')
        ->and(readingsOf($twin))->toBe([
            ['2026-11-09 18:00:00.000', 1.5],
            ['2026-11-09 18:30:00.000', 2.25],
            ['2026-11-09 18:30:15.250', 1.75],
            ['2026-11-10 07:00:00.000', 2.0],
            ['2026-11-10 12:00:00.000', 3.0],
            ['2026-11-10 13:30:00.000', 2.5],
        ])
        ->and(readingsOf($twin, 'wx'))->toHaveCount(5)
        ->and(readingsOf($twin, 'wx', 'feelsC'))->toHaveCount(5);
    Storage::disk('local')->assertMissing('imports/readings.csv');
});

it('imports a long file, a series per name, and lists the names nothing was mapped to', function (): void {
    $twin = twinIn(member()[1]);

    $import = importCsv($twin, "Sensor;Reading;Taken\n"
        ."PZ-01;12,5;10/11/2026 6:00 am\n"
        ."PZ-02;3;10/11/2026 6:00 pm\n"
        ."PZ-09;1;10/11/2026 12:00 pm\n"
        ."PZ-09;1;10/11/2026 12:30 pm\n"
        ."PZ-08;1;10/11/26 12:00\n", [
            'layout' => 'long', 'delimiter' => ';', 'time' => 'Taken', 'format' => 'dmy', 'name' => 'Sensor', 'value' => 'Reading',
        ], [
            ['name' => 'PZ-01', 'asset' => 'store', 'metric' => 'tempC'],
            ['name' => 'PZ-02', 'asset' => 'plant', 'metric' => 'powerKw'],
        ]);

    expect($import->status)->toBe(ReadingImportStatus::Done)
        ->and($import->summary)->toBe(['rows' => 5, 'stored' => 2, 'skipped' => 3, 'problems' => [], 'unmapped' => ['PZ-09', 'PZ-08']])
        ->and(readingsOf($twin))->toBe([['2026-11-09 18:00:00.000', 12.5]])
        ->and(readingsOf($twin, 'plant', 'powerKw'))->toBe([['2026-11-10 06:00:00.000', 3.0]]);
});

it('reads month-first and Unix times, and tabs', function (): void {
    $twin = twinIn(member()[1]);

    importCsv($twin, "When\tT\n11/10/2026 12:00 am\t1\n11/10/2026 12:15:30 pm\t2\n", ['delimiter' => "\t", 'time' => 'When', 'format' => 'mdy'], [['name' => 'T', 'asset' => 'store', 'metric' => 'tempC']]);
    expect(readingsOf($twin))->toBe([['2026-11-09 12:00:00.000', 1.0], ['2026-11-10 00:15:30.000', 2.0]]);

    $twin = twinIn(member()[1]);
    $import = importCsv($twin, "Time|T\n1794333600|1\nsoon|2\n", ['delimiter' => '|', 'format' => 'unix'], [['name' => 'T', 'asset' => 'store', 'metric' => 'tempC']]);
    expect($import->summary['problems'])->toBe(["Row 3: “soon” isn't a time the importer can read."]);

    $import = importCsv($twin, "Time,T\n10/11/2026,1\nsoon,2\n", ['format' => 'mdy'], [['name' => 'T', 'asset' => 'store', 'metric' => 'tempC']]);
    expect($import->summary['problems'])->toBe(["Row 3: “soon” isn't a time the importer can read."]);

    $twin = twinIn(member()[1]);
    importCsv($twin, "Time|T\n1794333600|1\n1794333600500|2\n1794333660.25|3\n", ['delimiter' => '|', 'format' => 'unix'], [['name' => 'T', 'asset' => 'store', 'metric' => 'tempC']]);
    expect(readingsOf($twin))->toBe([['2026-11-10 18:00:00.000', 1.0], ['2026-11-10 18:00:00.500', 2.0], ['2026-11-10 18:01:00.250', 3.0]]);
});

it('skips what it cannot read and says why, the first ten times', function (): void {
    $twin = twinIn(member()[1]);
    $bad = implode('', array_map(fn (int $day): string => sprintf("2026-11-%02d 06:00,warm\n", $day), range(1, 11)));

    $import = importCsv($twin, "Time,Store temperature\n"
        ."2026-11-12 06:00,1\n"
        ."yesterday,2\n"
        .$bad
        ."2026-02-30 06:00,3\n"
        ."2026-11-12 24:00,3\n"
        ."13/13/2026 1:00 pm,3\n"
        ."2024-11-12 06:00,4\n"
        ."2026-11-16 06:00,5\n");

    expect($import->status)->toBe(ReadingImportStatus::Done)
        ->and($import->summary['stored'])->toBe(1)
        ->and($import->summary['skipped'])->toBe(17)
        ->and($import->summary['problems'])->toHaveCount(10)
        ->and($import->summary['problems'][0])->toBe("Row 3: “yesterday” isn't a time the importer can read.")
        ->and($import->summary['problems'][1])->toBe("Row 4: “warm” in Store temperature isn't a number.")
        ->and(readingsOf($twin))->toBe([['2026-11-11 18:00:00.000', 1.0]]);

    $import = importCsv($twin, "Time,Store temperature\n2026-11-12 06:30,1\n2024-11-12 06:00,4\n2026-11-16 06:00,5\n13/13/2026 1:00 pm,3\n");
    expect($import->summary['problems'])->toBe([
        'Row 3: 2024-11-11 18:00 UTC is older than the 365 days readings are kept.',
        'Row 4: 2026-11-15 18:00 UTC is in the future.',
        "Row 5: “13/13/2026 1:00 pm” isn't a time the importer can read.",
    ]);

    $import = importCsv($twin, "Time,Store temperature\n12/11/2026 06:45,1\n13/13/2026,4\n1/1/2026 13:00 pm,3\n0/1/2026 1:00 pm,3\n", ['format' => 'dmy']);
    expect($import->summary['stored'])->toBe(1)
        ->and($import->summary['problems'])->toHaveCount(3);
});

it('replaces what the twin had for each series over the file, so importing again does not double it', function (): void {
    $twin = twinIn(member()[1]);
    $at = fn (string $utc): int => CarbonImmutable::parse($utc, 'UTC')->getTimestampMs();
    resolve(RecordReadings::class)->handle($twin, [
        ['asset' => 'store', 'metric' => 'tempC', 'value' => 9.0, 'at' => $at('2026-11-09 17:00')],
        ['asset' => 'store', 'metric' => 'tempC', 'value' => 9.0, 'at' => $at('2026-11-09 18:15')],
        ['asset' => 'store', 'metric' => 'tempC', 'value' => 9.0, 'at' => $at('2026-11-09 18:30')],
        ['asset' => 'store', 'metric' => 'tempC', 'value' => 9.0, 'at' => $at('2026-11-09 19:00')],
        ['asset' => 'plant', 'metric' => 'tempC', 'value' => 9.0, 'at' => $at('2026-11-09 18:15')],
    ]);
    $csv = "Time,Store temperature\n2026-11-10 06:00,1\n2026-11-10 06:30,2\n";

    importCsv($twin, $csv);
    importCsv($twin, $csv);

    expect(readingsOf($twin))->toBe([
        ['2026-11-09 17:00:00.000', 9.0],
        ['2026-11-09 18:00:00.000', 1.0],
        ['2026-11-09 18:30:00.000', 2.0],
        ['2026-11-09 19:00:00.000', 9.0],
    ])->and(readingsOf($twin, 'plant'))->toHaveCount(1);
});

it('stores big files in batches', function (): void {
    $twin = twinIn(member()[1]);
    $rows = implode('', array_map(fn (int $minute): string => sprintf("2026-11-10 %02d:%02d,%d\n", intdiv($minute, 60), $minute % 60, $minute), range(0, 1199)));

    $import = importCsv($twin, "Time,Store temperature\n".$rows);

    expect($import->summary['stored'])->toBe(1200)
        ->and(readingsOf($twin))->toHaveCount(1200);
});

it('fails an import it cannot make, with the reason', function (string $csv, array $settings, string $error): void {
    $twin = twinIn(member()[1]);

    $import = importCsv($twin, $csv, $settings);

    expect($import->status)->toBe(ReadingImportStatus::Failed)
        ->and($import->error)->toBe($error)
        ->and($import->path)->toBeNull()
        ->and(readingsOf($twin))->toBe([]);
})->with([
    'empty' => ['', [], 'The file is empty.'],
    'no time column' => ["When,Store temperature\n", [], 'The file has no column “Time”.'],
    'no series column' => ["Time,Temp\n", [], 'The file has no column “Store temperature”.'],
    'no readings' => ["Time,Store temperature\n2026-11-10 06:00,\n", [], 'The file has no readings for the columns chosen.'],
    'nothing readable' => ["Time,Store temperature\n10/11/2026,1\n", [], "The file has no readings that could be imported. Row 2: “10/11/2026” isn't a time the importer can read."],
    'no name column' => ["Time,Value\n", ['layout' => 'long', 'name' => 'Sensor', 'value' => 'Value'], 'The file has no column “Sensor”.'],
]);

it('fails an import with too many readings, or whose file has gone', function (): void {
    config(['meridian.readings.import_max_readings' => 2]);
    $twin = twinIn(member()[1]);

    $import = importCsv($twin, "Time,Store temperature\n2026-11-10 06:00,1\n2026-11-10 06:30,2\n2026-11-10 07:00,3\n");
    expect($import->error)->toBe('The file has more than 2 readings. Split it into smaller files.');

    $import = ReadingImport::factory()->for($twin)->create(['path' => 'imports/gone.csv']);
    new ImportReadingFile($import)->handle(resolve(ImportReadings::class));
    expect($import->refresh()->error)->toBe('The file is no longer there. Upload it again.');

    $import = ReadingImport::factory()->for($twin)->create();
    new ImportReadingFile($import)->handle(resolve(ImportReadings::class));
    expect($import->refresh()->error)->toBe('The file is no longer there. Upload it again.');
});

it('says when importing stopped unexpectedly', function (): void {
    $import = ReadingImport::factory()->create();

    new ImportReadingFile($import)->failed(new RuntimeException('Killed'));

    expect($import->refresh()->status)->toBe(ReadingImportStatus::Failed)
        ->and($import->error)->toBe('Importing stopped unexpectedly. Upload the file again to retry.');
});

it('removes an import and the readings of its series over its span', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $import = importCsv($twin, "Time,Store temperature\n2026-11-10 06:00,1\n2026-11-10 06:30:00.5,2\n");
    $at = fn (string $utc): int => CarbonImmutable::parse($utc, 'UTC')->getTimestampMs();
    resolve(RecordReadings::class)->handle($twin, [
        ['asset' => 'store', 'metric' => 'tempC', 'value' => 9.0, 'at' => $at('2026-11-09 19:00')],
        ['asset' => 'plant', 'metric' => 'tempC', 'value' => 9.0, 'at' => $at('2026-11-09 18:15')],
    ]);
    $failed = ReadingImport::factory()->for($twin)->create(['status' => ReadingImportStatus::Failed, 'path' => 'imports/left.csv']);
    Storage::disk('local')->put('imports/left.csv', 'Time');

    $this->actingAs($user)->delete(route('twins.reading-imports.destroy', [$twin, $import]))->assertRedirect();
    $this->actingAs($user)->delete(route('twins.reading-imports.destroy', [$twin, $failed]))->assertRedirect();

    expect(readingsOf($twin))->toBe([['2026-11-09 19:00:00.000', 9.0]])
        ->and(readingsOf($twin, 'plant'))->toHaveCount(1)
        ->and($twin->readingImports()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', AuditAction::ReadingImportDeleted)->pluck('subject')->all())->toBe(['store.csv', 'store.csv']);
    Storage::disk('local')->assertMissing('imports/left.csv');
});

it('only removes imports of the twin in the address', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    $import = ReadingImport::factory()->for(twinIn($organisation))->create();

    $this->actingAs($user)->delete(route('twins.reading-imports.destroy', [$twin, $import]))->assertNotFound();
});

it('lists imports for editors only', function (): void {
    [$owner, $organisation] = member();
    $twin = twinIn($organisation);
    ReadingImport::factory()->for($twin)->imported()->create(['created_by' => $owner->id]);

    $this->actingAs($owner)->get(route('twins.show', $twin))->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->has('readingImports', 1)
        ->where('readingImports.0.fileName', 'store.csv')
        ->where('readingImports.0.status', 'done')
        ->where('readingImports.0.series', 1)
        ->where('readingImports.0.summary.stored', 48)
        ->where('readingImports.0.createdBy', $owner->name)
        ->where('readingImports.0.firstAt', '2026-11-12T00:00:00+00:00'));

    [$viewer, $elsewhere] = member(OrganisationRole::Viewer);
    $seen = twinIn($elsewhere);
    ReadingImport::factory()->for($seen)->imported()->create();
    $this->actingAs($viewer)->get(route('twins.show', $seen))->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('readingImports', []));
});
