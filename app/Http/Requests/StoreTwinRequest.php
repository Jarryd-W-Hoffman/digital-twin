<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\ValidTwinDefinition;
use App\Support\SampleTwins;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

final class StoreTwinRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sample' => ['required_without:definition', 'nullable', 'string', Rule::in(array_keys(resolve(SampleTwins::class)->all()))],
            'definition' => ['bail', 'required_without:sample', 'nullable', 'file', 'max:4096', 'extensions:json', new ValidTwinDefinition()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $file = $this->file('definition');

        if ($file instanceof UploadedFile) {
            /** @var array<string, mixed> */
            return json_decode((string) $file->get(), true, flags: JSON_THROW_ON_ERROR);
        }

        return resolve(SampleTwins::class)->definition($this->string('sample')->value());
    }
}
