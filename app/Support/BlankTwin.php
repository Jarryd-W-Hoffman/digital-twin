<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The smallest twin that runs: a site at a given place with weather, a grid
 * connection and the main bus every twin needs, and no equipment yet. Models,
 * tilesets and assets are added to it from there.
 */
final readonly class BlankTwin
{
    /**
     * @return array<string, mixed>
     */
    public function definition(float $lat, float $lon, ?float $utcOffsetHours = null): array
    {
        $square = static fn (float $east, float $north, float $half): array => [
            [$east - $half, $north - $half],
            [$east + $half, $north - $half],
            [$east + $half, $north + $half],
            [$east - $half, $north + $half],
        ];

        return [
            'id' => 'blank',
            'name' => 'Blank site',
            'description' => '',
            'origin' => ['lon' => $lon, 'lat' => $lat],
            // Solar time is close enough until someone sets the real offset.
            'utcOffsetHours' => $utcOffsetHours ?? round($lon / 15),
            'climate' => ['meanC' => 15, 'swingC' => 5],
            'camera' => ['headingDeg' => 0, 'pitchDeg' => -35, 'rangeM' => 250],
            'tariff' => [
                'offPeakPerKwh' => 0.15,
                'peakPerKwh' => 0.30,
                'peakStartHour' => 17,
                'peakEndHour' => 21,
                'dieselPerLitre' => 2,
                'gridKgCo2PerKwh' => 0.4,
                'dieselKgCo2PerLitre' => 2.68,
            ],
            'zones' => [['id' => 'site', 'name' => 'Site', 'footprint' => $square(0, 0, 50)]],
            'assets' => [
                ['id' => 'site', 'name' => 'Site', 'kind' => 'site', 'zone' => 'site'],
                ['id' => 'wx', 'name' => 'Weather', 'kind' => 'weather', 'zone' => 'site', 'position' => [40, 40], 'height' => 6],
                ['id' => 'grid', 'name' => 'Grid connection', 'kind' => 'grid', 'zone' => 'site', 'height' => 4, 'footprint' => $square(40, -40, 4)],
                ['id' => 'board', 'name' => 'Main board', 'kind' => 'bus', 'zone' => 'site', 'height' => 3, 'footprint' => $square(28, -40, 4)],
            ],
            'routes' => [],
            'rules' => [],
            'scenarios' => [],
        ];
    }
}
