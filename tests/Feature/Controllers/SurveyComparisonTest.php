<?php

declare(strict_types=1);

use App\Actions\MeasureSurveys;
use App\Actions\SaveSurveyComparison;
use App\Enums\AuditAction;
use App\Enums\OrganisationRole;
use App\Enums\ProjectAssetStatus;
use App\Enums\SurveyComparisonStatus;
use App\Jobs\MeasureSurveyComparison;
use App\Models\AuditEvent;
use App\Models\ProjectAsset;
use App\Models\SurveyComparison;
use App\Models\Twin;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/**
 * A lot near the sample depot, in longitude and latitude.
 */
const STOCKPILE = [[174.785, -36.8398], [174.7856, -36.8398], [174.7856, -36.8392], [174.785, -36.8392]];

/**
 * Three surveys of the ground in the twin's project, oldest first, with
 * heights a fake meridian-volume can tell apart.
 *
 * @return list<ProjectAsset>
 */
function groundSurveys(Twin $twin): array
{
    return array_map(function (string $month) use ($twin): ProjectAsset {
        $survey = ProjectAsset::factory()->for($twin->project)->survey($month.' survey')->create();
        Storage::disk('local')->put($survey->directory.'/surface.tif', $month.' heights');

        return $survey;
    }, ['March', 'April', 'May']);
}

beforeEach(fn () => Storage::fake('local'));

it('lets editors compare surveys inside an area, measured on the conversions queue', function (): void {
    Queue::fake();
    [$user, $organisation] = member(OrganisationRole::Admin);
    $twin = twinIn($organisation);
    [$march, $april] = groundSurveys($twin);

    $this->actingAs($user)->post(route('twins.survey-comparisons.store', $twin), [
        'name' => 'North stockpile',
        'area' => [['174.78500001234', '-36.8398'], [174.7856, -36.8398], [174.7856, -36.8392]],
        'surveys' => [$march->id, $april->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $comparison = $twin->surveyComparisons()->sole();
    expect($comparison->name)->toBe('North stockpile')
        ->and($comparison->area)->toBe([[174.78500001, -36.8398], [174.7856, -36.8398], [174.7856, -36.8392]])
        ->and($comparison->surveys)->toBe([$march->id, $april->id])
        ->and($comparison->status)->toBe(SurveyComparisonStatus::Measuring)
        ->and($comparison->created_by)->toBe($user->id)
        ->and(AuditEvent::query()->sole()->action)->toBe(AuditAction::SurveyComparisonSaved);
    Queue::assertPushed(MeasureSurveyComparison::class, fn (MeasureSurveyComparison $job): bool => $job->comparison->is($comparison)
        && $job->connection === 'conversions' && $job->queue === 'conversions');
});

it('only lets editors compare, change and delete', function (OrganisationRole $role): void {
    [$user, $organisation] = member($role);
    $twin = twinIn($organisation);
    [$march, $april] = groundSurveys($twin);
    $comparison = SurveyComparison::factory()->for($twin)->measured([$march->id, $april->id])->create();
    $fields = ['name' => 'Pit', 'area' => STOCKPILE, 'surveys' => [$march->id, $april->id]];

    $this->actingAs($user)->post(route('twins.survey-comparisons.store', $twin), $fields)->assertForbidden();
    $this->actingAs($user)->put(route('twins.survey-comparisons.update', [$twin, $comparison]), $fields)->assertForbidden();
    $this->actingAs($user)->delete(route('twins.survey-comparisons.destroy', [$twin, $comparison]))->assertForbidden();
})->with([OrganisationRole::Operator, OrganisationRole::Viewer]);

it('refuses areas and surveys it cannot measure', function (array $fields, string $error): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    [$march, $april] = groundSurveys($twin);
    $elsewhere = ProjectAsset::factory()->survey()->create();
    $model = ProjectAsset::factory()->for($twin->project)->create();
    $converting = ProjectAsset::factory()->for($twin->project)->survey()->create(['status' => ProjectAssetStatus::Processing]);
    $fields = array_map(fn (mixed $value): mixed => is_array($value) ? array_map(fn (mixed $id): mixed => match ($id) {
        'march' => $march->id, 'april' => $april->id, 'elsewhere' => $elsewhere->id, 'model' => $model->id, 'converting' => $converting->id,
        default => $id,
    }, $value) : $value, $fields);

    $this->actingAs($user)->post(route('twins.survey-comparisons.store', $twin), [
        'name' => 'Pit', 'area' => STOCKPILE, 'surveys' => ['march', 'april'], ...$fields,
    ])->assertSessionHasErrors($error);

    expect($twin->surveyComparisons()->count())->toBe(0);
})->with([
    'one survey' => [['surveys' => ['march']], 'surveys'],
    'the same survey twice' => [['surveys' => ['march', 'march']], 'surveys.0'],
    "another project's survey" => [['surveys' => ['march', 'elsewhere']], 'surveys.1'],
    'a model' => [['surveys' => ['march', 'model']], 'surveys.1'],
    'a survey still converting' => [['surveys' => ['march', 'converting']], 'surveys.1'],
    'two points' => [['area' => array_slice(STOCKPILE, 0, 2)], 'area'],
    'off the globe' => [['area' => [[190, 0], [0, 1], [1, 1]]], 'area.0.0'],
    'no name' => [['name' => ''], 'name'],
    'no area' => [['area' => []], 'area'],
    'no surveys' => [['surveys' => []], 'surveys'],
]);

it('measures again only when the area or the surveys change', function (): void {
    Queue::fake();
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    [$march, $april, $may] = groundSurveys($twin);
    // Stored as JSON, a whole degree comes back as an integer; it's the same area.
    $area = [[175, -36.8398], ...array_slice(STOCKPILE, 1)];
    $comparison = SurveyComparison::factory()->for($twin)->measured([$march->id, $april->id])->create(['area' => $area]);

    $this->actingAs($user)->put(route('twins.survey-comparisons.update', [$twin, $comparison]), [
        'name' => 'North stockpile, east half', 'area' => $area, 'surveys' => [$march->id, $april->id],
    ])->assertRedirect();

    expect($comparison->refresh()->name)->toBe('North stockpile, east half')
        ->and($comparison->status)->toBe(SurveyComparisonStatus::Ready);
    Queue::assertNothingPushed();

    $this->actingAs($user)->put(route('twins.survey-comparisons.update', [$twin, $comparison]), [
        'name' => 'North stockpile', 'area' => STOCKPILE, 'surveys' => [$march->id, $april->id, $may->id],
    ])->assertRedirect();

    expect($comparison->refresh()->status)->toBe(SurveyComparisonStatus::Measuring)
        ->and($comparison->results)->toBeNull()
        ->and($comparison->surveys)->toBe([$march->id, $april->id, $may->id]);
    Queue::assertPushed(MeasureSurveyComparison::class);
});

it('refuses surveys from outside the project when called directly', function (array $surveys): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    [$march] = groundSurveys($twin);
    $elsewhere = ProjectAsset::factory()->survey()->create();

    expect(fn () => resolve(SaveSurveyComparison::class)->handle($twin, $user, null, 'Pit', STOCKPILE, array_map(fn (string $id): string => $id === 'elsewhere' ? $elsewhere->id : $march->id, $surveys)))
        ->toThrow(InvalidArgumentException::class);
})->with([[['march']], [['march', 'elsewhere']]]);

it('deletes a comparison and its images, keeping the surveys', function (): void {
    [$user, $organisation] = member();
    $twin = twinIn($organisation);
    [$march, $april] = groundSurveys($twin);
    $comparison = SurveyComparison::factory()->for($twin)->measured([$march->id, $april->id])->create();
    Storage::disk('local')->put($comparison->imageKey(1), 'png');

    $this->actingAs($user)->delete(route('twins.survey-comparisons.destroy', [$twin, $comparison]))->assertRedirect();

    expect(SurveyComparison::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($comparison->imageKey(1)))->toBeFalse()
        ->and($march->fresh())->not->toBeNull()
        ->and(AuditEvent::query()->sole()->action)->toBe(AuditAction::SurveyComparisonDeleted);
});

it('shows the image of the change to everyone who can see the twin, and no one else', function (): void {
    [$viewer, $organisation] = member(OrganisationRole::Viewer);
    [$outsider] = member();
    $twin = twinIn($organisation);
    $comparison = SurveyComparison::factory()->for($twin)->create();
    $other = SurveyComparison::factory()->for(twinIn($organisation))->create();
    Storage::disk('local')->put($comparison->imageKey(1), "\x89PNG change");

    $this->actingAs($viewer)->get(route('twins.survey-comparisons.images.show', [$twin, $comparison, 1]))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($viewer)->get(route('twins.survey-comparisons.images.show', [$twin, $comparison, 2]))->assertNotFound();
    $this->actingAs($viewer)->get(route('twins.survey-comparisons.images.show', [$twin, $other, 1]))->assertNotFound();
    $this->actingAs($outsider)->get(route('twins.survey-comparisons.images.show', [$twin, $comparison, 1]))->assertForbidden();
});

it('gives the twin page its comparisons, with their images, and which uploads are surveys', function (): void {
    [$user, $organisation] = member(OrganisationRole::Viewer);
    $twin = twinIn($organisation);
    [$march, $april] = groundSurveys($twin);
    ProjectAsset::factory()->for($twin->project)->create(['name' => 'Pump skid']);
    $comparison = SurveyComparison::factory()->for($twin)->measured([$march->id, $april->id])->create();

    $this->actingAs($user)->get(route('twins.show', $twin))->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('comparisons.0.id', $comparison->id)
        ->where('comparisons.0.status', 'ready')
        ->where('comparisons.0.surveys', [$march->id, $april->id])
        ->where('comparisons.0.results.0.before', $march->id)
        ->where('comparisons.0.results.0.cut', 120)
        ->where('comparisons.0.results.0.image', route('twins.survey-comparisons.images.show', [$twin, $comparison, 1]))
        ->where('assets', fn ($assets): bool => collect($assets)->mapWithKeys(fn (array $asset): array => [$asset['name'] => $asset['survey']])->all() === [
            'March survey' => true, 'April survey' => true, 'May survey' => true, 'Pump skid' => false,
        ]));
});

it('measures each survey against the one before, keeping the volumes and images', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    [$march, $april, $may] = groundSurveys($twin);
    $comparison = SurveyComparison::factory()->for($twin)->create(['area' => STOCKPILE, 'surveys' => [$march->id, $april->id, $may->id]]);
    $runs = new ArrayObject;
    fakeVolumeTool(runs: $runs);

    new MeasureSurveyComparison($comparison)->handle(resolve(MeasureSurveys::class));

    $comparison->refresh();
    expect($comparison->status)->toBe(SurveyComparisonStatus::Ready)
        ->and($comparison->measured_at)->not->toBeNull()
        ->and($runs->getArrayCopy())->toBe([['March heights', 'April heights'], ['April heights', 'May heights']])
        ->and(array_column($comparison->results ?? [], 'before'))->toBe([$march->id, $april->id])
        ->and(array_column($comparison->results ?? [], 'after'))->toBe([$april->id, $may->id])
        ->and($comparison->results[0]['cut'] ?? null)->toBe(120.5)
        ->and($comparison->results[0]['fill'] ?? null)->toBe(450.25)
        ->and(Storage::disk('local')->get($comparison->imageKey(2)))->toBe("\x89PNG change")
        ->and(is_dir(storage_path('app/comparisons/'.$comparison->id)))->toBeFalse();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command[0] === 'meridian-volume'
        && array_slice((array) $process->command, 4) === ['--area', (string) json_encode(STOCKPILE), '--cell', '0.5']);
});

it('fails a comparison with the reason, and keeps no images', function (?string $fail, bool $draws, string $summary, string $error): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    [$march, $april] = groundSurveys($twin);
    $comparison = SurveyComparison::factory()->for($twin)->create(['surveys' => [$march->id, $april->id]]);
    Storage::disk('local')->put($comparison->imageKey(1), 'old png');
    fakeVolumeTool(fail: $fail, draws: $draws, summary: $summary);

    new MeasureSurveyComparison($comparison)->handle(resolve(MeasureSurveys::class));

    expect($comparison->refresh()->status)->toBe(SurveyComparisonStatus::Failed)
        ->and($comparison->error)->toBe($error)
        ->and(Storage::disk('local')->exists($comparison->imageKey(1)))->toBeFalse();
})->with([
    'no overlap' => ["Warning 1: ignore\nThe two surveys don't both cover any of the area.", true, '{}', "March survey to April survey could not be measured:\nWarning 1: ignore\nThe two surveys don't both cover any of the area."],
    'no image' => [null, false, '{}', 'meridian-volume drew no image.'],
    'no summary' => [null, true, 'progress only', 'meridian-volume gave no summary.'],
]);

it('fails a comparison whose survey has left the library', function (): void {
    [, $organisation] = member();
    $twin = twinIn($organisation);
    [$march, $april] = groundSurveys($twin);
    $comparison = SurveyComparison::factory()->for($twin)->create(['surveys' => [$march->id, $april->id]]);
    $april->delete();
    fakeVolumeTool();

    new MeasureSurveyComparison($comparison)->handle(resolve(MeasureSurveys::class));

    expect($comparison->refresh()->status)->toBe(SurveyComparisonStatus::Failed)
        ->and($comparison->error)->toBe('A survey in the comparison is no longer in the library.');
    Process::assertNothingRan();
});

it('says so when measuring stops unexpectedly', function (): void {
    $comparison = SurveyComparison::factory()->create();

    new MeasureSurveyComparison($comparison)->failed(new RuntimeException('Killed'));

    expect($comparison->refresh()->status)->toBe(SurveyComparisonStatus::Failed)
        ->and($comparison->error)->toBe('Measuring stopped unexpectedly. Save the comparison again to retry.');
});
