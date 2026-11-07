<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Twins used to own their uploads. Each organisation's twins move into one
 * "General" project, their uploads into its library (the files stay where
 * they are), and every saved version's layer URLs follow the new file route.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (DB::table('twins')->whereNull('project_id')->distinct()->pluck('organisation_id') as $organisationId) {
            $projectId = (string) Str::uuid7();

            DB::table('projects')->insert([
                'id' => $projectId,
                'organisation_id' => $organisationId,
                'name' => 'General',
                'description' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('twins')->where('organisation_id', $organisationId)->whereNull('project_id')->update(['project_id' => $projectId]);
        }

        DB::table('twin_assets')
            ->join('twins', 'twins.id', '=', 'twin_assets.twin_id')
            ->select('twin_assets.*', 'twins.project_id')
            ->orderBy('twin_assets.id')
            ->each(function (object $asset): void {
                /** @var object{id: string, twin_id: string, project_id: string, kind: string, name: string, original_name: string, entry: string, bytes: int, files: int, created_at: string, updated_at: string} $asset */
                DB::table('project_assets')->insert([
                    'id' => $asset->id,
                    'project_id' => $asset->project_id,
                    'kind' => $asset->kind,
                    'name' => $asset->name,
                    'original_name' => $asset->original_name,
                    'directory' => 'twins/'.$asset->twin_id.'/assets/'.$asset->id,
                    'entry' => $asset->entry,
                    'bytes' => $asset->bytes,
                    'files' => $asset->files,
                    'created_at' => $asset->created_at,
                    'updated_at' => $asset->updated_at,
                ]);
            });

        DB::table('twin_versions')->select('id', 'definition')->orderBy('id')->chunkById(200, function ($versions): void {
            foreach ($versions as $version) {
                /** @var object{id: string, definition: string} $version */
                $definition = json_decode($version->definition, true);

                if (! is_array($definition)) {
                    continue;
                }

                $changed = false;

                foreach (['tilesets', 'models'] as $field) {
                    if (! is_array($definition[$field] ?? null)) {
                        continue;
                    }

                    $layers = [];

                    foreach ($definition[$field] as $layer) {
                        $url = is_array($layer) ? ($layer['url'] ?? null) : null;

                        if (is_array($layer) && is_string($url)) {
                            $rewritten = (string) preg_replace('#^/twins/[^/]+/assets/([^/]+)/files/#', '/assets/$1/files/', $url);
                            $changed = $changed || $rewritten !== $url;
                            $layer['url'] = $rewritten;
                        }

                        $layers[] = $layer;
                    }

                    $definition[$field] = $layers;
                }

                if ($changed) {
                    DB::table('twin_versions')->where('id', $version->id)->update(['definition' => json_encode($definition, JSON_THROW_ON_ERROR)]);
                }
            }
        });
    }
};
