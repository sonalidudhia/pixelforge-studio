<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The credit ledger. A user's balance is the sum of their amounts.
 * Rows are only ever added, never changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('generation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            // Signed nanodollars: positive adds credit, negative spends it.
            $table->bigInteger('amount');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);

            // A generation can be held, charged and released at most once each.
            // Purchases have no generation, and NULLs never clash in a unique index.
            $table->unique(['generation_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
