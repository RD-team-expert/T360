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
        Schema::create('load_rejection_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rejection_id')
                ->constrained('rejections')
                ->onDelete('cascade')
                ->comment('Foreign key to rejections table');
            
            $table->string('load_id')
                ->unique()
                ->comment('Unique load identifier per company');
            
            $table->string('driver_name', 100)
                ->nullable()
                ->comment('Driver name (optional)');
            
            $table->dateTime('origin_yard_arrival_at')
                ->comment('Origin yard arrival date/time in EST');
            
            $table->text('rejection_reason')
                ->nullable()
                ->comment('Rejection reason (null = accepted)');
            
            $table->string('rejection_bucket', 50)
                ->nullable()
                ->comment('Rejection bucket from CSV - NOT auto-calculated');
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index('load_id');
            $table->index('driver_name');
            $table->index('origin_yard_arrival_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('load_rejection_details');
    }
};
