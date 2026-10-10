<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Composite indexes so paginated history (filter by owner + status, newest first) never scans all rides. */
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            if (!Schema::hasIndex('rides', 'rides_driver_status_updated_index')) {
                $table->index(['driver_id', 'status', 'updated_at'], 'rides_driver_status_updated_index');
            }
            if (!Schema::hasIndex('rides', 'rides_user_status_updated_index')) {
                $table->index(['user_id', 'status', 'updated_at'], 'rides_user_status_updated_index');
            }
        });

        Schema::table('ratings', function (Blueprint $table) {
            if (!Schema::hasIndex('ratings', 'ratings_rated_to_created_index')) {
                $table->index(['rated_to', 'created_at'], 'ratings_rated_to_created_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropIndex('rides_driver_status_updated_index');
            $table->dropIndex('rides_user_status_updated_index');
        });
        Schema::table('ratings', function (Blueprint $table) {
            $table->dropIndex('ratings_rated_to_created_index');
        });
    }
};
