<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // STEP 1: Expand rejection_type enum to include both old and new values
        DB::statement("ALTER TABLE rejections MODIFY COLUMN rejection_type ENUM('block', 'load', 'blocks', 'loads', 'advanced') NULL");
        
        // STEP 2: Update rejection_type data from plural to singular
        DB::table('rejections')
            ->where('rejection_type', 'loads')
            ->update(['rejection_type' => 'load']);
        
        DB::table('rejections')
            ->where('rejection_type', 'blocks')
            ->update(['rejection_type' => 'block']);
        
        // STEP 3: Remove old plural values from rejection_type enum
        DB::statement("ALTER TABLE rejections MODIFY COLUMN rejection_type ENUM('block', 'load', 'advanced') NULL");
        
        // STEP 4: Expand rejection_category to include ALL current and new values
        DB::statement("ALTER TABLE rejections MODIFY COLUMN rejection_category ENUM(
            'more_than_6', 
            'within_6', 
            'after_start', 
            'within_24', 
            'more_than_24', 
            'advanced_rejection',
            'Rejected after start time',
            'Rejected 0–6 hours before start',
            'Rejected 6+ hours before start',
            'Less than 24 hours before start',
            '24+ hours before start'
        ) NULL");
        
        // STEP 5: Map old descriptive names to new short codes
        DB::statement("UPDATE rejections SET rejection_category = 'after_start' WHERE rejection_category = 'Rejected after start time'");
        DB::statement("UPDATE rejections SET rejection_category = 'within_6' WHERE rejection_category = 'Rejected 0–6 hours before start'");
        DB::statement("UPDATE rejections SET rejection_category = 'more_than_6' WHERE rejection_category = 'Rejected 6+ hours before start'");
        DB::statement("UPDATE rejections SET rejection_category = 'within_24' WHERE rejection_category = 'Less than 24 hours before start'");
        DB::statement("UPDATE rejections SET rejection_category = 'more_than_24' WHERE rejection_category = '24+ hours before start'");
        
        // Handle NULL values
        DB::statement("UPDATE rejections SET rejection_category = 'more_than_6' WHERE rejection_category IS NULL");
        
        // STEP 6: Now clean up enum to only new values
        DB::statement("ALTER TABLE rejections MODIFY COLUMN rejection_category ENUM('more_than_6', 'within_6', 'after_start', 'within_24', 'more_than_24', 'advanced_rejection') NULL");
        
        // STEP 7: Make other columns nullable
        DB::statement('ALTER TABLE rejections MODIFY COLUMN date DATE NULL');
        DB::statement('ALTER TABLE rejections MODIFY COLUMN driver_name VARCHAR(75) NULL');
        
        // STEP 8: Handle foreign key
        try {
            Schema::table('rejections', function (Blueprint $table) {
                $table->dropForeign(['reason_code_id']);
            });
        } catch (\Exception $e) {
            // Continue if doesn't exist
        }
        
        DB::statement('ALTER TABLE rejections MODIFY COLUMN reason_code_id BIGINT UNSIGNED NULL');
        
        Schema::table('rejections', function (Blueprint $table) {
            $table->foreign('reason_code_id')
                ->references('id')
                ->on('rejection_reason_codes')
                ->onDelete('cascade');
        });
        
        // STEP 9: Make boolean columns nullable
        Schema::table('rejections', function (Blueprint $table) {
            $table->boolean('disputed')->nullable()->change();
            $table->boolean('driver_controllable')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert (simplified for now)
    }
};
