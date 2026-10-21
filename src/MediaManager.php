<?php

declare(strict_types=1);

namespace ItmoSoft\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ItmoSoft\Media\Models\Media;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class MediaManager
{
    public function upload(
        Model $model,
        UploadedFile $file,
        string $collection = 'default'
    ): Media {
        if (! $model->exists) {
            throw new UnexpectedValueException(
                'The model must be saved before attaching media.'
            );
        }

        if (trim($collection) === '') {
            throw new UnexpectedValueException(
                'The media collection name cannot be empty.'
            );
        }

        $collectionConfig = config(
            "media.collections.{$collection}",
            []
        );

        $maxFiles = $collectionConfig['max_files'] ?? null;
        $onLimit = $collectionConfig['on_limit'] ?? 'reject';

        if ($maxFiles !== null && (! is_int($maxFiles) || $maxFiles < 1)) {
            throw new \InvalidArgumentException(
                'The collection max_files setting must be a positive integer or null.'
            );
        }

        if (! in_array($onLimit, ['reject', 'replace'], true)) {
            throw new \InvalidArgumentException(
                'The collection on_limit setting must be reject or replace.'
            );
        }

        $currentCount = $model->media()
            ->where('collection_name', $collection)
            ->count();

        if (! $file->isValid()) {
            throw new UnexpectedValueException(
                'The uploaded file is invalid.'
            );
        }

        $size = $file->getSize();

        if ($size === false || $size <= 0) {
            throw new UnexpectedValueException(
                'The uploaded file is empty or its size is unavailable.'
            );
        }

        $maxSize = (int) config('media.max_file_size', 10240);

        if ($size > $maxSize * 1024) {
            throw new UnexpectedValueException(
                'The uploaded file exceeds the configured size limit.'
            );
        }

        $mimeType = $file->getMimeType();

        $allowedMimeTypes = config('media.allowed_mime_types', []);

        if (
            ! is_string($mimeType)
            || ! in_array($mimeType, $allowedMimeTypes, true)
        ) {
            throw new UnexpectedValueException(
                'The uploaded file MIME type is not allowed.'
            );
        }

        $extension = $file->guessExtension();

        $extensionsByMimeType = config('media.allowed_extensions', []);
        $allowedExtensions = $extensionsByMimeType[$mimeType] ?? [];

        if (
            ! is_string($extension)
            || ! is_array($allowedExtensions)
            || ! in_array(strtolower($extension), $allowedExtensions, true)
        ) {
            throw new UnexpectedValueException(
                'The uploaded file extension does not match its MIME type.'
            );
        }

        $currentMedia = $model->media()
            ->where('collection_name', $collection)
            ->get();

        $currentCount = $currentMedia->count();

        $shouldReplace = $maxFiles !== null
            && $currentCount >= $maxFiles
            && $onLimit === 'replace';

        if (
            $maxFiles !== null
            && $currentCount >= $maxFiles
            && ! $shouldReplace
        ) {
            throw new RuntimeException(
                "The media collection '{$collection}' has reached its maximum file limit."
            );
        }

        $extension = strtolower($extension);

        $disk = config('media.disk', 'local');
        $uuid = (string) Str::uuid();
        $fileName = Str::uuid().'.'.$extension;
        $directory = 'media/'.$uuid;
        $path = $directory.'/'.$fileName;

        $storage = Storage::disk($disk);

        $storedPath = null;

        try {
            $storedPath = $storage->putFileAs(
                $directory,
                $file,
                $fileName
            );

            if ($storedPath === false) {
                throw new RuntimeException(
                    'Unable to store the uploaded media file.'
                );
            }

            $newMedia = $model->media()->create([
                'uuid' => $uuid,
                'collection_name' => $collection,
                'name' => pathinfo(
                    basename($file->getClientOriginalName()),
                    PATHINFO_FILENAME
                ),
                'file_name' => $fileName,
                'path' => $storedPath,
                'disk' => $disk,
                'mime_type' => $mimeType,
                'size' => $size,
                'order_column' => $shouldReplace
                    ? 1
                    : (int) $model->media()
                        ->where('collection_name', $collection)
                        ->max('order_column') + 1,
            ]);

            if ($shouldReplace) {
                $currentMedia->each(
                    fn (Media $media) => $media->delete()
                );
            }

            return $newMedia;
        } catch (Throwable $exception) {
            try {
                $storage->delete($storedPath ?: $path);
            } catch (Throwable) {
                // Preserve the original exception.
            }

            throw $exception;
        }
    }
}
