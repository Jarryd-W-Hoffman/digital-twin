<?php

declare(strict_types=1);

use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('mqtt_key', 32)->nullable()->unique();
        });

        foreach (DB::table('projects')->whereNull('mqtt_key')->get(['id', 'name']) as $project) {
            /** @var object{id: string, name: string} $project */
            DB::table('projects')->where('id', $project->id)->update(['mqtt_key' => Project::newMqttKey($project->name)]);
        }

        Schema::table('projects', function (Blueprint $table): void {
            $table->string('mqtt_key', 32)->nullable(false)->change();
        });
    }
};
