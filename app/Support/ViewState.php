<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * How a twin looked to someone, as the viewer sends it under `state`: the
 * camera, what the scene is coloured by, the basemap and layers, any section
 * cut, the selected asset and the Site on date. The viewer checks the same
 * limits.
 */
final class ViewState
{
    public const array COLOR_BYS = ['status', 'temperature', 'power'];

    public const array BASEMAPS = ['offline', 'osm', 'custom'];

    public const array LAYERS = ['labels', 'flows', 'zones', 'context', 'shadows'];

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $required = true): array
    {
        return [
            'state' => [$required ? 'required' : 'sometimes', 'array:camera,colorBy,basemap,layers,section,selected,siteDate'],
            'state.camera' => ['required_with:state', 'array:lon,lat,height,heading,pitch,roll'],
            'state.camera.lon' => ['required_with:state', 'numeric', 'between:-180,180'],
            'state.camera.lat' => ['required_with:state', 'numeric', 'between:-90,90'],
            'state.camera.height' => ['required_with:state', 'numeric', 'between:-12000,50000000'],
            'state.camera.heading' => ['required_with:state', 'numeric', 'between:-360,360'],
            'state.camera.pitch' => ['required_with:state', 'numeric', 'between:-90,90'],
            'state.camera.roll' => ['required_with:state', 'numeric', 'between:-360,360'],
            'state.colorBy' => ['required_with:state', Rule::in(self::COLOR_BYS)],
            'state.basemap' => ['required_with:state', Rule::in(self::BASEMAPS)],
            'state.layers' => ['required_with:state', 'array:'.implode(',', self::LAYERS)],
            ...collect(self::LAYERS)->mapWithKeys(fn (string $layer): array => ["state.layers.$layer" => ['required_with:state', 'boolean']])->all(),
            'state.section' => ['nullable', 'array:above,across'],
            'state.section.above' => ['nullable', 'numeric', 'between:-1000,10000'],
            'state.section.across' => ['nullable', 'array:offset,heading'],
            'state.section.across.offset' => ['required_with:state.section.across', 'numeric', 'between:-100000,100000'],
            'state.section.across.heading' => ['required_with:state.section.across', 'numeric', 'between:-360,360'],
            'state.selected' => ['nullable', 'string', 'max:200'],
            'state.siteDate' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * The view, typed, with a section that cuts nothing as none.
     *
     * @return array{camera: array{lon: float, lat: float, height: float, heading: float, pitch: float, roll: float}, colorBy: string, basemap: string, layers: array<string, bool>, section: array{above: float|null, across: array{offset: float, heading: float}|null}|null, selected: string|null, siteDate: string|null}
     */
    public static function from(Request $request): array
    {
        $above = $request->filled('state.section.above') ? $request->float('state.section.above') : null;
        $across = $request->filled('state.section.across') ? [
            'offset' => $request->float('state.section.across.offset'),
            'heading' => $request->float('state.section.across.heading'),
        ] : null;

        return [
            'camera' => [
                'lon' => $request->float('state.camera.lon'),
                'lat' => $request->float('state.camera.lat'),
                'height' => $request->float('state.camera.height'),
                'heading' => $request->float('state.camera.heading'),
                'pitch' => $request->float('state.camera.pitch'),
                'roll' => $request->float('state.camera.roll'),
            ],
            'colorBy' => $request->string('state.colorBy')->value(),
            'basemap' => $request->string('state.basemap')->value(),
            'layers' => collect(self::LAYERS)->mapWithKeys(fn (string $layer): array => [$layer => $request->boolean("state.layers.$layer")])->all(),
            'section' => $above === null && $across === null ? null : ['above' => $above, 'across' => $across],
            'selected' => $request->filled('state.selected') ? $request->string('state.selected')->value() : null,
            'siteDate' => $request->filled('state.siteDate') ? $request->string('state.siteDate')->value() : null,
        ];
    }
}
