<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('media.table', 'media'), function (Blueprint $table): void {
            $table->id();

            $table->morphs('model');

            $table->uuid('uuid')->unique();

            $table->string('collection_name')->default('default');
            $table->string('name');
            $table->string('file_name');
            $table->string('path');

            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->string('mime_type', 127)->nullable();

            $table->unsignedBigInteger('size')->default(0);

            $table->json('manipulations')->nullable();
            $table->json('custom_properties')->nullable();
            $table->json('generated_conversions')->nullable();
            $table->json('responsive_images')->nullable();

            $table->unsignedInteger('order_column')->nullable();

            $table->timestamps();

            $table->index([
                'collection_name',
                'order_column',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('media.table', 'media'));
    }
};
