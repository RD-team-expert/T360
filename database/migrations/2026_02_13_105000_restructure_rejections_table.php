<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('rejections', function (Blueprint $table) {
            // Add new columns
            $table->enum('type', ['advanced', 'block', 'load'])->after('tenant_id')->nullable();
            $table->enum('dispute_status', ['none', 'pending', 'won', 'lost'])->default('none')->after('penalty')->nullable();
            $table->boolean('carrier_controllable')->default(true)->after('dispute_status')->nullable();
            
            // We'll keep old columns for now to allow data migration
            // They'll be removed in a later migration after backfill is complete
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rejections', function (Blueprint $table) {
            $table->dropColumn(['type', 'dispute_status', 'carrier_controllable']);
        });
    }
};
