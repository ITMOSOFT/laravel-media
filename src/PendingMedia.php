<?php

declare(strict_types=1);

namespace ItmoSoft\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use ItmoSoft\Media\Models\Media;
use UnexpectedValueException;

class PendingMedia
{
    public function __construct(
        protected Model $model,
        protected UploadedFile $file,
    ) {}

    public function toCollection(
        string $collection = 'default'
    ): Media {
        if (trim($collection) === '') {
            throw new UnexpectedValueException(
                'The collection name cannot be empty.'
            );
        }

        return app(MediaManager::class)->upload(
            $this->model,
            $this->file,
            $collection
        );
    }
}
