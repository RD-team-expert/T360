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
        Schema::create('advanced_rejection_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rejection_id')
                ->constrained('rejections')
                ->onDelete('cascade')
                ->comment('Foreign key to rejections table');
            
            $table->string('advanced_block_id')
                ->unique()
                ->comment('Unique advanced block identifier per company');
            
            $table->dateTime('week_start_at')
                ->comment('Week start date and time');
            
            $table->dateTime('week_end_at')
                ->comment('Week end date and time');
            
            $table->integer('impacted_blocks')
                ->comment('Number of impacted blocks');
            
            $table->text('reason')
                ->comment('Rejection reason description');
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index('advanced_block_id');
            $table->index('week_start_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('advanced_rejection_details');
    }
};
