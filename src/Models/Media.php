<?php

declare(strict_types=1);

namespace ItmoSoft\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

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

        static::deleting(function (self $media): void {
            $disk = Storage::disk($media->disk);

            if (! $disk->delete($media->path)) {
                throw new RuntimeException(
                    "Failed to delete media file [{$media->path}]."
                );
            }

            $conversionDirectory = dirname($media->path).'/conversions';

            foreach ($disk->files($conversionDirectory) as $path) {
                if (! $disk->delete($path)) {
                    throw new RuntimeException(
                        "Failed to delete media conversion [{$path}]."
                    );
                }
            }
        });
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
