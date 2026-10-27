<?php

declare(strict_types=1);

namespace ItmoSoft\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ItmoSoft\Media\Models\Media;
use InvalidArgumentException;
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

        // Resolve the collection as an array key so dots in literal collection
        // names are not interpreted as nested configuration separators.
        $collections = config('media.collections', []);
        if (! is_array($collections)) {
            throw new InvalidArgumentException(
                'The media collections configuration must be an array.'
            );
        }

        $collectionConfig = $collections[$collection] ?? [];
        if (! is_array($collectionConfig)) {
            throw new InvalidArgumentException(
                "Configuration for media collection '{$collection}' must be an array."
            );
        }

        $maxFiles = $collectionConfig['max_files'] ?? null;
        $onLimit = $collectionConfig['on_limit'] ?? 'reject';

        if ($maxFiles !== null && (! is_int($maxFiles) || $maxFiles < 1)) {
            throw new InvalidArgumentException(
                'The collection max_files setting must be a positive integer or null.'
            );
        }

        if (! in_array($onLimit, ['reject', 'replace'], true)) {
            throw new InvalidArgumentException(
                'The collection on_limit setting must be reject or replace.'
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

        $maxSize = config('media.max_file_size', 10240);
        if (! is_int($maxSize) || $maxSize < 1) {
            throw new InvalidArgumentException(
                'The media max_file_size setting must be a positive integer in KB.'
            );
        }

        if ($size > $maxSize * 1024) {
            throw new UnexpectedValueException(
                'The uploaded file exceeds the configured size limit.'
            );
        }

        $mimeType = $file->getMimeType();
        $allowedMimeTypes = config('media.allowed_mime_types', []);
        if (
            ! is_string($mimeType)
            || ! is_array($allowedMimeTypes)
            || ! in_array($mimeType, $allowedMimeTypes, true)
        ) {
            throw new UnexpectedValueException(
                'The uploaded file MIME type is not allowed.'
            );
        }

        $extension = $file->guessExtension();
        $extensionsByMimeType = config('media.allowed_extensions', []);
        $allowedExtensions = is_array($extensionsByMimeType)
            ? ($extensionsByMimeType[$mimeType] ?? [])
            : [];

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
        $diskName = config('media.disk', 'local');
        $uuid = (string) Str::uuid();
        $fileName = Str::uuid().'.'.$extension;
        $directory = 'media/'.$uuid;
        $path = $directory.'/'.$fileName;
        $storage = Storage::disk($diskName);
        $storedPath = null;
        $storageAttempted = false;

        try {
            return DB::transaction(function () use (
                $model,
                $file,
                $collection,
                $maxFiles,
                $onLimit,
                $size,
                $mimeType,
                $uuid,
                $fileName,
                $directory,
                $path,
                $diskName,
                $storage,
                &$storedPath,
                &$storageAttempted
            ): Media {
                // Lock the owning row while checking the limit and inserting.
                // Concurrent uploads for the same owner therefore serialize on
                // databases that support SELECT ... FOR UPDATE.
                $lockedModel = $model->newQuery()
                    ->whereKey($model->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($lockedModel === null) {
                    throw new UnexpectedValueException(
                        'The model no longer exists and cannot receive media.'
                    );
                }

                $currentMedia = $model->media()
                    ->where('collection_name', $collection)
                    ->orderBy('order_column')
                    ->orderBy('id')
                    ->lockForUpdate()
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

                $storageAttempted = true;
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
                    'disk' => $diskName,
                    'mime_type' => $mimeType,
                    'size' => $size,
                    'order_column' => $shouldReplace
                        ? 1
                        : (int) $currentMedia->max('order_column') + 1,
                ]);

                if (
                    str_starts_with($mimeType, 'image/')
                    && config('media.auto_generate_conversions', false)
                ) {
                    app(MediaConversionManager::class)->generate($newMedia);
                }

                if ($shouldReplace) {
                    $mediaToReplace = $currentMedia
                        ->take($currentCount - $maxFiles + 1);

                    // Media rows are deleted in this transaction; filesystem
                    // cleanup is scheduled after commit by the Media model.
                    $mediaToReplace->each(
                        fn (Media $media) => $media->delete()
                    );
                }

                return $newMedia;
            });
        } catch (Throwable $exception) {
            // The database transaction has rolled back at this point. Clean up
            // any files written for the failed upload without masking the
            // original exception if storage cleanup also fails.
            if ($storageAttempted) {
                $this->cleanupFailedUpload(
                    $storage,
                    is_string($storedPath) ? $storedPath : $path,
                    $directory,
                    $uuid,
                );
            }

            throw $exception;
        }
    }

    private function cleanupFailedUpload(
        mixed $storage,
        string $originalPath,
        string $directory,
        string $uuid,
    ): void {
        try {
            $conversionDirectory = $directory.'/conversions';
            foreach ($storage->files($conversionDirectory) as $conversionPath) {
                $this->deleteOrLog($storage, $conversionPath, $uuid, 'conversion');
            }
        } catch (Throwable $exception) {
            Log::warning('Failed to enumerate conversions while cleaning up an unsuccessful media upload.', [
                'media_uuid' => $uuid,
                'directory' => $directory.'/conversions',
                'exception' => $exception->getMessage(),
            ]);
        }

        $this->deleteOrLog($storage, $originalPath, $uuid, 'original');
    }

    private function deleteOrLog(
        mixed $storage,
        string $path,
        string $uuid,
        string $kind,
    ): void {
        try {
            $deleted = $storage->delete($path);
            if (! $deleted && $storage->exists($path)) {
                Log::warning('Failed to clean up a file from an unsuccessful media upload.', [
                    'media_uuid' => $uuid,
                    'path' => $path,
                    'file_type' => $kind,
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('An exception occurred while cleaning up an unsuccessful media upload.', [
                'media_uuid' => $uuid,
                'path' => $path,
                'file_type' => $kind,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
