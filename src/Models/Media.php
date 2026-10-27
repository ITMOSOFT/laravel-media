<?php

declare(strict_types=1);

namespace ItmoSoft\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class Media extends Model
{
    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable(config('media.table', 'media'));
    }

    protected function casts(): array
    {
        return [
            'manipulations' => 'array',
            'custom_properties' => 'array',
            'generated_conversions' => 'array',
            'responsive_images' => 'array',
            'size' => 'integer',
            'order_column' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $media): void {
            $media->uuid ??= (string) Str::uuid();
        });

        static::deleted(function (self $media): void {
            // A filesystem and a database cannot participate in one atomic
            // transaction. Delete the database row first, then clean up files
            // after the surrounding transaction commits. A cleanup failure can
            // leave an orphaned file, but never a row pointing to a missing file.
            DB::afterCommit(function () use ($media): void {
                $media->cleanupStoredFiles();
            });
        });
    }

    private function cleanupStoredFiles(): void
    {
        try {
            $disk = Storage::disk($this->disk);
        } catch (Throwable $exception) {
            Log::warning('Unable to access the media disk during post-delete cleanup.', [
                'media_id' => $this->getKey(),
                'disk' => $this->disk,
                'exception' => $exception->getMessage(),
            ]);
            return;
        }

        $conversionDirectory = dirname($this->path).'/conversions';
        try {
            $conversionPaths = $disk->files($conversionDirectory);
        } catch (Throwable $exception) {
            $conversionPaths = [];
            Log::warning('Unable to enumerate media conversions during post-delete cleanup.', [
                'media_id' => $this->getKey(),
                'directory' => $conversionDirectory,
                'exception' => $exception->getMessage(),
            ]);
        }

        foreach ($conversionPaths as $path) {
            $this->deleteStoredFile($disk, $path, 'conversion');
        }

        $this->deleteStoredFile($disk, $this->path, 'original');
    }

    private function deleteStoredFile(mixed $disk, string $path, string $kind): void
    {
        try {
            if (! $disk->delete($path) && $disk->exists($path)) {
                Log::warning('A file could not be removed after its media record was deleted.', [
                    'media_id' => $this->getKey(),
                    'disk' => $this->disk,
                    'path' => $path,
                    'file_type' => $kind,
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('An exception occurred while removing a file after its media record was deleted.', [
                'media_id' => $this->getKey(),
                'disk' => $this->disk,
                'path' => $path,
                'file_type' => $kind,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the original media URL or a generated conversion URL.
     */
    public function getUrl(?string $conversion = null): string
    {
        if ($conversion === null) {
            return Storage::disk($this->disk)->url($this->path);
        }

        $generated = $this->generated_conversions ?? [];

        if (
            ! is_array($generated)
            || ($generated[$conversion] ?? false) !== true
        ) {
            throw new RuntimeException(
                "Media conversion '{$conversion}' has not been generated."
            );
        }

        $conversions = config('media.conversions', []);
        $options = is_array($conversions)
            ? ($conversions[$conversion] ?? null)
            : null;

        if (! is_array($options)) {
            throw new RuntimeException(
                "Configuration for media conversion '{$conversion}' was not found."
            );
        }

        $format = $options['format'] ?? 'webp';

        if (! is_string($format)) {
            throw new RuntimeException(
                "Invalid format for media conversion '{$conversion}'."
            );
        }

        $extension = $format === 'jpeg' ? 'jpg' : $format;

        $path = dirname($this->path)
            .'/conversions/'.$conversion.'.'.$extension;

        $disk = Storage::disk($this->disk);

        if (! $disk->exists($path)) {
            throw new RuntimeException(
                "The file for media conversion '{$conversion}' does not exist."
            );
        }

        return $disk->url($path);
    }

    public function getTemporaryUrl(
        \DateTimeInterface $expiration
    ): string {
        return Storage::disk($this->disk)->temporaryUrl(
            $this->path,
            $expiration
        );
    }
}
