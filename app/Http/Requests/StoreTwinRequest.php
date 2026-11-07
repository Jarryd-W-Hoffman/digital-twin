<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\User;
use App\Rules\ValidTwinDefinition;
use App\Support\BlankTwin;
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
        $user = $this->user();
        assert($user instanceof User);

        return [
            'project' => ['required', 'uuid', Rule::exists(Project::class, 'id')->where('organisation_id', $user->organisation()->id)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'source' => ['required', Rule::in(['blank', 'sample', 'file'])],
            'lat' => ['exclude_unless:source,blank', 'required', 'numeric', 'between:-90,90'],
            'lon' => ['exclude_unless:source,blank', 'required', 'numeric', 'between:-180,180'],
            'utc_offset' => ['exclude_unless:source,blank', 'nullable', 'numeric', 'between:-12,14'],
            'sample' => ['exclude_unless:source,sample', 'required', 'string', Rule::in(array_keys(resolve(SampleTwins::class)->all()))],
            'definition' => ['exclude_unless:source,file', 'bail', 'required', 'file', 'max:4096', 'extensions:json', new ValidTwinDefinition()],
        ];
    }

    public function project(): Project
    {
        return Project::query()->findOrFail($this->string('project')->value());
    }

    /**
     * The starting definition: a blank site at a place, a sample, or a file.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        if ($this->string('source')->value() === 'blank') {
            return resolve(BlankTwin::class)->definition(
                $this->float('lat'),
                $this->float('lon'),
                $this->filled('utc_offset') ? $this->float('utc_offset') : null,
            );
        }

        $file = $this->file('definition');

        if ($file instanceof UploadedFile) {
            /** @var array<string, mixed> */
            return json_decode((string) $file->get(), true, flags: JSON_THROW_ON_ERROR);
        }

        return resolve(SampleTwins::class)->definition($this->string('sample')->value());
    }
}
