<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Twin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * A CSV file of readings for a twin's history: which column holds the time
 * and how it's written, and how the file's series map onto the twin's
 * assets' metrics. A wide file's series are its columns; a long file names
 * the series in one column and holds the value in another.
 */
final class StoreReadingImportRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Twin $twin */
        $twin = $this->route('twin');
        $definition = $twin->latestVersion()->firstOrFail()->definition;
        $assets = array_column(is_array($definition['assets'] ?? null) ? $definition['assets'] : [], 'id');

        return [
            'file' => ['required', 'file', 'extensions:csv,tsv,txt', 'max:'.(config()->integer('meridian.readings.import_max_mb') * 1024)],
            'layout' => ['required', Rule::in(['wide', 'long'])],
            'delimiter' => ['required', 'string', Rule::in([',', ';', "\t", '|'])],
            'time' => ['required', 'string', 'max:255'],
            'format' => ['required', Rule::in(['iso', 'dmy', 'mdy', 'unix'])],
            'name' => ['required_if:layout,long', 'nullable', 'string', 'max:255'],
            'value' => ['required_if:layout,long', 'nullable', 'string', 'max:255'],
            'series' => ['required', 'array', 'min:1', 'max:500'],
            'series.*.name' => ['required', 'string', 'max:255'],
            'series.*.asset' => ['required', 'string', Rule::in($assets)],
            'series.*.metric' => ['required', 'string', 'regex:/^[a-z][A-Za-z0-9_]{0,63}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.extensions' => __('Choose a CSV file (.csv, .tsv or .txt).'),
            'series.required' => __('Choose a metric for at least one series.'),
            'series.*.asset.in' => __('Choose an asset in this twin.'),
            'name.required_if' => __('Choose the column that names each series.'),
            'value.required_if' => __('Choose the column that holds the values.'),
        ];
    }

    public function upload(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }

    /**
     * @return array{layout: 'wide'|'long', delimiter: string, time: string, format: 'iso'|'dmy'|'mdy'|'unix', name: string|null, value: string|null}
     */
    public function settings(): array
    {
        /** @var 'wide'|'long' $layout */
        $layout = $this->string('layout')->value();
        /** @var 'iso'|'dmy'|'mdy'|'unix' $format */
        $format = $this->string('format')->value();
        $long = $layout === 'long';

        return [
            'layout' => $layout,
            'delimiter' => $this->string('delimiter')->value(),
            'time' => $this->string('time')->value(),
            'format' => $format,
            'name' => $long ? $this->string('name')->value() : null,
            'value' => $long ? $this->string('value')->value() : null,
        ];
    }

    /**
     * @return list<array{name: string, asset: string, metric: string}>
     */
    public function series(): array
    {
        /** @var list<array{name: string, asset: string, metric: string}> $series */
        $series = $this->validated('series');

        return array_map(fn (array $s): array => ['name' => $s['name'], 'asset' => $s['asset'], 'metric' => $s['metric']], $series);
    }
}
