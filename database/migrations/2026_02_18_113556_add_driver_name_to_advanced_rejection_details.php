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
        Schema::table('advanced_rejection_details', function (Blueprint $table) {
            $table->string('driver_name')->nullable()->after('advanced_block_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('advanced_rejection_details', function (Blueprint $table) {
            $table->dropColumn('driver_name');
        });
    }
};