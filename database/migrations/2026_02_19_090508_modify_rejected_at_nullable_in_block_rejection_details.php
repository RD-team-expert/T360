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
        Schema::table('block_rejection_details', function (Blueprint $table) {
            // Make rejected_at nullable
            $table->datetime('rejected_at')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('block_rejection_details', function (Blueprint $table) {
            // WARNING: This will fail if there are null values in the column
            // You need to handle null values first before rolling back
            $table->datetime('rejected_at')->nullable(false)->change();
        });
    }
};