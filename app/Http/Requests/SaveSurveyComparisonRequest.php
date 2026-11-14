<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Twin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveSurveyComparisonRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Twin $twin */
        $twin = $this->route('twin');

        return [
            'name' => ['required', 'string', 'max:100'],
            'area' => ['required', 'array', 'min:3', 'max:500'],
            'area.*' => ['required', 'array', 'size:2'],
            'area.*.0' => ['required', 'numeric', 'between:-180,180'],
            'area.*.1' => ['required', 'numeric', 'between:-90,90'],
            'surveys' => ['required', 'array', 'min:2', 'max:'.config()->integer('meridian.surveys.max_surveys')],
            'surveys.*' => ['required', 'uuid', 'distinct', Rule::in($twin->project->surveys()->pluck('id')->all())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'area.required' => __('Draw the area with three points or more.'),
            'area.min' => __('Draw the area with three points or more.'),
            'surveys.required' => __('Choose two surveys or more.'),
            'surveys.min' => __('Choose two surveys or more.'),
            'surveys.*.in' => __('Choose terrain or point clouds from this project that kept their heights.'),
        ];
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    public function area(): array
    {
        /** @var list<array{0: float|int|string, 1: float|int|string}> $area */
        $area = $this->input('area');

        return array_map(fn (array $point): array => [round((float) $point[0], 8), round((float) $point[1], 8)], $area);
    }

    /**
     * @return list<string>
     */
    public function surveys(): array
    {
        /** @var array<int, string> $surveys */
        $surveys = $this->input('surveys');

        return array_values($surveys);
    }
}
