<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('generator.sequences.connection');
    }

    public function up(): void
    {
        Schema::create(config('generator.sequences.table', 'generator_sequences'), function (Blueprint $table) {
            $table->id();
            $table->string('key', 191)->unique();
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('generator.sequences.table', 'generator_sequences'));
    }
};
