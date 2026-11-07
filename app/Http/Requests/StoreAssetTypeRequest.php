<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\ValidAssetTypes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

final class StoreAssetTypeRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['bail', 'required', 'file', 'max:1024', 'extensions:json', new ValidAssetTypes()],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function types(): array
    {
        $file = $this->file('file');
        assert($file instanceof UploadedFile);

        /** @var list<array<string, mixed>> */
        return ValidAssetTypes::from(json_decode((string) $file->get(), true, flags: JSON_THROW_ON_ERROR));
    }
}
