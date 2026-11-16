<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * An optional feature, which an organisation's owners and admins turn on for
 * it. Some need something the server runs, and can't be turned on where it
 * doesn't.
 */
enum Feature: string
{
    case PhotoProcessing = 'photo-processing';

    public function label(): string
    {
        return match ($this) {
            self::PhotoProcessing => 'Photo processing',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PhotoProcessing => 'Turn drone photos into a survey: a textured mesh, an orthophoto, surface and bare-ground models and a point cloud, added to the library as they would be from an OpenDroneMap export. Processing runs on this server and takes a while: tens of minutes for a hundred photos.',
        };
    }

    /**
     * Why this server can't offer it, or null when it can.
     */
    public function unavailable(): ?string
    {
        return match ($this) {
            self::PhotoProcessing => filled(config('meridian.photos.nodeodm_url'))
                ? null
                : (string) __('This server has no photo processor. Its administrator runs NodeODM and sets MERIDIAN_NODEODM_URL to offer it.'),
        };
    }
}
