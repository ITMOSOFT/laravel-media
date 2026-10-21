<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Media Database Table
    |--------------------------------------------------------------------------
    */

    'table' => 'media',

    /*
    |--------------------------------------------------------------------------
    | Default Storage Disk
    |--------------------------------------------------------------------------
    */

    'disk' => env('MEDIA_DISK', env('FILESYSTEM_DISK', 'local')),

    /*
    |--------------------------------------------------------------------------
    | Default Collection
    |--------------------------------------------------------------------------
    */

    'default_collection' => 'default',

    /*
    |--------------------------------------------------------------------------
    | File Upload Limits
    |--------------------------------------------------------------------------
    */

    'max_file_size' => 10240, // KB

    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/pdf',
    ],

    'allowed_extensions' => [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
        'application/pdf' => ['pdf'],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Deletion
    |--------------------------------------------------------------------------
    */

    'delete_files_on_model_delete' => true,

    'collections' => [
        // Example:
        // 'cover' => [
        //     'max_files' => 1,
        //     'on_limit' => 'reject',
        // ],
        //
        // 'gallery' => [
        //     'max_files' => null,
        //     'on_limit' => 'reject',
        // ],
    ],

    'conversions' => [
        'thumb' => [
            'width' => 150,
            'height' => 150,
            'format' => 'webp',
            'quality' => 80,
        ],

        'medium' => [
            'width' => 600,
            'height' => 600,
            'format' => 'webp',
            'quality' => 85,
        ],

        'large' => [
            'width' => 1200,
            'height' => 1200,
            'format' => 'webp',
            'quality' => 90,
        ],
    ],

    'auto_generate_conversions' => env(
        'MEDIA_AUTO_GENERATE_CONVERSIONS',
        false,
    ),
];
