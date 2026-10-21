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

            return $model->media()->create([
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
            ]);
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
