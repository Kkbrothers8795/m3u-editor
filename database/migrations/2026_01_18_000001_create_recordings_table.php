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
        Schema::create('recordings', function (Blueprint $table) {
            $table->id();
            $table->morphs('recordable'); // Channel, Episode, Series, VodChannel
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stream_profile_id')->nullable()->constrained();
            $table->string('title');
            $table->enum('type', ['once', 'series', 'daily', 'weekly'])->default('once');
            $table->enum('status', ['scheduled', 'recording', 'completed', 'failed', 'cancelled'])->default('scheduled');

            // Scheduling
            $table->timestamp('scheduled_start');
            $table->timestamp('scheduled_end');
            $table->integer('pre_padding_seconds')->default(0);
            $table->integer('post_padding_seconds')->default(0);

            // Recording details
            $table->timestamp('actual_start')->nullable();
            $table->timestamp('actual_end')->nullable();
            $table->text('output_path')->nullable();
            $table->bigInteger('file_size_bytes')->nullable();
            $table->integer('duration_seconds')->nullable();

            // Failure handling
            $table->integer('retry_count')->default(0);
            $table->integer('max_retries')->default(3);
            $table->text('last_error')->nullable();
            $table->timestamp('last_retry_at')->nullable();

            // Metadata
            $table->json('recording_metadata')->nullable(); // stream URL, profile vars, etc.
            $table->timestamps();
            $table->softDeletes(); // Keep history

            $table->index(['status', 'scheduled_start']);
            // Note: morphs() already creates an index on [recordable_type, recordable_id]
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recordings');
    }
};
