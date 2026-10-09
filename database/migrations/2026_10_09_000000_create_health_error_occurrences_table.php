<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('laravel-health.errors.connection');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('health_error_occurrences', function (Blueprint $table) {
            $table->id();
            $table->char('fingerprint', 40)->index();
            $table->string('level', 20);
            $table->string('class', 255)->nullable();
            $table->string('message', 500);
            $table->string('file', 500)->nullable();
            $table->unsignedInteger('line')->nullable();
            // UTC wall-clock time. A DATETIME, so MySQL never converts it
            // through the session time zone.
            $table->dateTime('occurred_at')->index();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('health_error_occurrences');
    }
};
