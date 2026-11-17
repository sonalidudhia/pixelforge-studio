<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per image a user asked for. Money columns are bigint nanodollars
 * (1 USD = 1,000,000,000) so every amount is exact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Sent as Idempotency-Key, so a retried submit never creates a second generation.
            $table->uuid('idempotency_key')->unique();
            // Null until Pixelforge accepts the request.
            $table->string('pixelforge_id')->nullable()->unique();

            $table->string('status')->index();

            // What the user asked for.
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // The price range shown before submitting (user price, markup included).
            $table->unsignedBigInteger('estimated_min_cost');
            $table->unsignedBigInteger('estimated_max_cost');

            // The rates Pixelforge fixed for this generation, and the token usage it reported.
            $table->json('rates')->nullable();
            $table->unsignedInteger('input_text_tokens')->nullable();
            $table->unsignedInteger('input_image_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();

            // The actual cost, set once, when the generation finishes. Zero when it failed.
            $table->unsignedBigInteger('provider_cost')->nullable();
            $table->unsignedBigInteger('user_cost')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->unsignedTinyInteger('submit_attempts')->default(0);

            // Pixelforge's own timestamps. Pixelforge bills on the UTC day of completed_at.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
