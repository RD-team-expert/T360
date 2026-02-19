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
        Schema::create('block_rejection_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rejection_id')
                ->constrained('rejections')
                ->onDelete('cascade')
                ->comment('Foreign key to rejections table');
            
            $table->string('block_id')
                ->unique()
                ->comment('Unique block identifier per company');
            
            $table->string('driver_name', 100)
                ->nullable()
                ->comment('Driver name (optional)');
            
            $table->dateTime('block_start_at')
                ->comment('Block start date and time');
            
            $table->dateTime('block_end_at')
                ->comment('Block end date and time');
            
            $table->dateTime('rejected_at')
                ->comment('When the block was rejected');
            
            $table->text('rejection_reason')
                ->nullable()
                ->comment('Rejection reason (null = accepted)');
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index('block_id');
            $table->index('driver_name');
            $table->index('block_start_at');
            $table->index('rejected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('block_rejection_details');
    }
};
