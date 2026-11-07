<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * With TimescaleDB loaded (the Docker stack's Postgres), readings become a
     * hypertable in day chunks, compressed after a week and dropped after the
     * retention period. Anywhere else this does nothing, and readings stay a
     * plain table that `model:prune` trims.
     */
    public function up(): void
    {
        if (! $this->timescaleLoaded()) {
            return;
        }

        $retention = max(1, config()->integer('meridian.readings.retention_days'));

        DB::statement('CREATE EXTENSION IF NOT EXISTS timescaledb');
        DB::statement("SELECT create_hypertable('readings', by_range('recorded_at', INTERVAL '1 day'), if_not_exists => true, migrate_data => true)");
        DB::statement("ALTER TABLE readings SET (timescaledb.compress, timescaledb.compress_segmentby = 'twin_id, asset, metric', timescaledb.compress_orderby = 'recorded_at DESC')");
        DB::statement("SELECT add_compression_policy('readings', INTERVAL '7 days', if_not_exists => true)");
        DB::statement("SELECT add_retention_policy('readings', INTERVAL '{$retention} days', if_not_exists => true)");
    }

    /**
     * The extension must be preloaded by the server, not just installed.
     */
    private function timescaleLoaded(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        $preload = DB::scalar("SELECT current_setting('shared_preload_libraries')");
        $available = DB::scalar("SELECT count(*) FROM pg_available_extensions WHERE name = 'timescaledb'");

        return is_string($preload) && str_contains($preload, 'timescaledb') && is_numeric($available) && (int) $available > 0;
    }
};
