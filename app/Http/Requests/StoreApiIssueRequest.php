<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Alert;
use App\Models\Twin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * A new issue from another system. Its camera is optional: without one it
 * opens looking north at the pin from 80 m south and 60 m above.
 */
final class StoreApiIssueRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $twin = $this->route('twin');
        assert($twin instanceof Twin);

        return [
            'alert' => ['nullable', 'uuid', Rule::exists('alerts', 'id')->where('twin_id', $twin->id)],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:5000'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'at' => ['required', 'array:lon,lat,height'],
            'at.lon' => ['required', 'numeric', 'between:-180,180'],
            'at.lat' => ['required', 'numeric', 'between:-90,90'],
            'at.height' => ['required', 'numeric', 'between:-12000,100000'],
            'view' => ['nullable', 'array:lon,lat,height,heading,pitch,roll'],
            'view.lon' => ['required_with:view', 'numeric', 'between:-180,180'],
            'view.lat' => ['required_with:view', 'numeric', 'between:-90,90'],
            'view.height' => ['required_with:view', 'numeric', 'between:-12000,50000000'],
            'view.heading' => ['required_with:view', 'numeric', 'between:-360,360'],
            'view.pitch' => ['required_with:view', 'numeric', 'between:-90,90'],
            'view.roll' => ['required_with:view', 'numeric', 'between:-360,360'],
        ];
    }

    /**
     * Where the issue is pinned, in degrees and metres above the ellipsoid.
     *
     * @return array{lon: float, lat: float, height: float}
     */
    public function at(): array
    {
        return [
            'lon' => $this->float('at.lon'),
            'lat' => $this->float('at.lat'),
            'height' => $this->float('at.height'),
        ];
    }

    /**
     * The camera to open it with: the one given, or one looking at the pin.
     *
     * @return array{lon: float, lat: float, height: float, heading: float, pitch: float, roll: float}
     */
    public function view(): array
    {
        if (! $this->filled('view')) {
            $at = $this->at();

            return [
                'lon' => $at['lon'],
                'lat' => max(-90.0, $at['lat'] - 80 / 111_320),
                'height' => $at['height'] + 60,
                'heading' => 0.0,
                'pitch' => -35.0,
                'roll' => 0.0,
            ];
        }

        return [
            'lon' => $this->float('view.lon'),
            'lat' => $this->float('view.lat'),
            'height' => $this->float('view.height'),
            'heading' => $this->float('view.heading'),
            'pitch' => $this->float('view.pitch'),
            'roll' => $this->float('view.roll'),
        ];
    }

    public function body(): ?string
    {
        return $this->filled('body') ? $this->string('body')->value() : null;
    }

    /**
     * The twin's alert the issue follows up, if any.
     */
    public function alert(): ?Alert
    {
        return $this->filled('alert') ? Alert::query()->findOrFail($this->string('alert')->value()) : null;
    }

    public function photo(): ?UploadedFile
    {
        $photo = $this->file('photo');

        return $photo instanceof UploadedFile ? $photo : null;
    }
}
