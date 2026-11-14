<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a scan, survey, image or model was captured, so a twin can show
     * the site as it was on a date.
     */
    public function up(): void
    {
        Schema::table('project_assets', function (Blueprint $table): void {
            $table->date('captured_on')->nullable();
        });
    }
};
