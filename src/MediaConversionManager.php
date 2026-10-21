<?php

declare(strict_types=1);

namespace ItmoSoft\Media;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use ItmoSoft\Media\Models\Media;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class MediaConversionManager
{
    /**
     * Generate configured image conversions for a media record.
     */
    public function generate(Media $media): void
    {
        $conversions = $this->validateConversions(
            config('media.conversions', [])
        );

        if ($conversions === []) {
            return;
        }

        $disk = Storage::disk($media->disk);

        if (! $disk->exists($media->path)) {
            throw new RuntimeException(
                'The original media file does not exist.'
            );
        }

        $contents = $disk->get($media->path);

        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException(
                'Unable to read the original media file.'
            );
        }

        $manager = new ImageManager(new Driver);
        try {
            $image = $manager->read($contents);
        } catch (Throwable $exception) {
            throw new UnexpectedValueException(
                'The media file could not be decoded as an image.',
                previous: $exception,
            );
        }

        /*
         * Encode every conversion before writing any files.
         * This prevents invalid image data or encoding errors from
         * leaving a partially generated set of conversions.
         */
        $outputs = [];

        foreach ($conversions as $name => $options) {
            $conversion = clone $image;

            $conversion->cover(
                $options['width'],
                $options['height'],
            );

            $encoded = match ($options['format']) {
                'jpg', 'jpeg' => $conversion->toJpeg($options['quality']),
                'png' => $conversion->toPng(),
                'webp' => $conversion->toWebp($options['quality']),
                'gif' => $conversion->toGif(),
            };

            $extension = $options['format'] === 'jpeg'
                ? 'jpg'
                : $options['format'];

            $path = dirname($media->path)
                .'/conversions/'.$name.'.'.$extension;

            $outputs[$path] = (string) $encoded;
        }

        /*
         * Preserve existing conversion files so a failed regeneration
         * can restore them rather than deleting previously valid files.
         */
        $previousFiles = [];
        $writtenPaths = [];

        foreach (array_keys($outputs) as $path) {
            $previousFiles[$path] = $disk->exists($path)
                ? $disk->get($path)
                : null;
        }

        $originalGeneratedConversions = $media->generated_conversions;
        try {
            foreach ($outputs as $path => $contents) {
                if (! $disk->put($path, $contents)) {
                    throw new RuntimeException(
                        "Unable to store media conversion at '{$path}'."
                    );
                }

                $writtenPaths[] = $path;
            }

            $generated = $media->generated_conversions ?? [];

            if (! is_array($generated)) {
                $generated = [];
            }

            foreach ($conversions as $name => $options) {
                $generated[$name] = true;
            }

            $media->generated_conversions = $generated;
            $media->save();
        } catch (Throwable $exception) {
            $media->generated_conversions = $originalGeneratedConversions;
            foreach (array_reverse($writtenPaths) as $path) {
                try {
                    $previous = $previousFiles[$path];

                    if (is_string($previous)) {
                        $disk->put($path, $previous);
                    } else {
                        $disk->delete($path);
                    }
                } catch (Throwable) {
                    // Preserve the original exception.
                }
            }

            throw $exception;
        }
    }

    /**
     * Validate all conversion definitions before processing the image.
     *
     * @return array<string, array{
     *     width: int,
     *     height: int,
     *     format: string,
     *     quality: int
     * }>
     */
    private function validateConversions(mixed $conversions): array
    {
        if (! is_array($conversions)) {
            throw new UnexpectedValueException(
                'Media conversions must be configured as an array.'
            );
        }

        $validated = [];

        foreach ($conversions as $name => $options) {
            if (
                ! is_string($name)
                || trim($name) === ''
                || $name !== trim($name)
                || ! preg_match('/\A[a-zA-Z0-9_-]+\z/', $name)
                || ! is_array($options)
            ) {
                throw new UnexpectedValueException(
                    'Each media conversion must have a valid name and an options array.'
                );
            }

            $width = $options['width'] ?? null;
            $height = $options['height'] ?? null;
            $format = $options['format'] ?? 'webp';
            $quality = $options['quality'] ?? 85;

            if (
                ! is_int($width) || $width < 1
                || ! is_int($height) || $height < 1
            ) {
                throw new UnexpectedValueException(
                    "Conversion '{$name}' requires positive integer width and height."
                );
            }

            if (
                ! is_string($format)
                || ! in_array($format, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)
            ) {
                throw new UnexpectedValueException(
                    "Conversion '{$name}' uses an unsupported output format."
                );
            }

            if (
                ! is_int($quality)
                || $quality < 1
                || $quality > 100
            ) {
                throw new UnexpectedValueException(
                    "Conversion '{$name}' quality must be between 1 and 100."
                );
            }

            $validated[$name] = [
                'width' => $width,
                'height' => $height,
                'format' => $format,
                'quality' => $quality,
            ];
        }

        return $validated;
    }
}
