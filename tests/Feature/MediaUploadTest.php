<?php

declare(strict_types=1);

namespace ItmoSoft\Media\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ItmoSoft\Media\Models\Media;
use ItmoSoft\Media\Tests\Fixtures\TestProduct;
use ItmoSoft\Media\Tests\TestCase;

class MediaUploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(
            dirname(__DIR__, 2).'/database/migrations'
        );

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->run();

        Schema::create('test_products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function test_it_uploads_a_file_and_creates_a_media_record(): void
    {
        Storage::fake('local');

        config()->set('media.disk', 'local');
        config()->set('media.max_file_size', 10240);
        config()->set('media.allowed_mime_types', [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'application/pdf',
        ]);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $file = UploadedFile::fake()->create(
            'document.pdf',
            100,
            'application/pdf'
        );

        $media = $product->addMedia($file)
            ->toCollection('products');

        $this->assertInstanceOf(Media::class, $media);
        $this->assertSame('products', $media->collection_name);
        $this->assertSame($product->id, $media->model_id);
        $this->assertSame(TestProduct::class, $media->model_type);
        $this->assertNotEmpty($media->uuid);
        $this->assertSame('local', $media->disk);
        $this->assertDatabaseHas('media', [
            'id' => $media->id,
            'collection_name' => 'products',
            'model_id' => $product->id,
        ]);

        Storage::disk('local')->assertExists($media->path);

        $this->assertSame(
            $media->id,
            $product->getFirstMedia('products')?->id
        );
    }

    public function test_it_rejects_an_unsupported_mime_type(): void
    {
        Storage::fake('local');

        config()->set('media.allowed_mime_types', [
            'image/jpeg',
            'image/png',
            'application/pdf',
        ]);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $file = UploadedFile::fake()->create(
            'script.txt',
            10,
            'text/plain'
        );

        try {
            $product->addMedia($file)->toCollection('products');

            $this->fail('Expected unsupported MIME type to be rejected.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertSame(
                'The uploaded file MIME type is not allowed.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount('media', 0);
    }

    public function test_it_rejects_an_oversized_file(): void
    {
        Storage::fake('local');

        config()->set('media.max_file_size', 1);
        config()->set('media.allowed_mime_types', ['application/pdf']);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $file = UploadedFile::fake()->create(
            'document.pdf',
            2,
            'application/pdf'
        );

        try {
            $product->addMedia($file)->toCollection('products');

            $this->fail('Expected oversized file to be rejected.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertSame(
                'The uploaded file exceeds the configured size limit.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount('media', 0);
    }

    public function test_it_rejects_an_unsaved_model(): void
    {
        Storage::fake('local');

        $product = new TestProduct([
            'name' => 'Unsaved product',
        ]);

        $file = UploadedFile::fake()->create(
            'document.pdf',
            10,
            'application/pdf'
        );

        try {
            $product->addMedia($file)->toCollection('products');

            $this->fail('Expected an unsaved model to be rejected.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertSame(
                'The model must be saved before attaching media.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount('media', 0);
    }

    public function test_it_deletes_the_file_when_database_insertion_fails(): void
    {
        Storage::fake('local');

        config()->set('media.disk', 'local');
        config()->set('media.allowed_mime_types', ['application/pdf']);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        // Force media insertion to fail after the file is stored.
        Event::listen(
            'eloquent.creating: '.Media::class,
            function (Media $media): void {
                throw new \RuntimeException('Simulated database failure.');
            }
        );

        $file = UploadedFile::fake()->create(
            'document.pdf',
            10,
            'application/pdf'
        );

        try {
            $product->addMedia($file)->toCollection('products');
            $this->fail('Expected media creation to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Simulated database failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount('media', 0);
        $this->assertSame(
            [],
            Storage::disk('local')->allFiles('media')
        );
    }

    public function test_deleting_media_removes_its_stored_file(): void
    {
        Storage::fake('local');

        config()->set('media.disk', 'local');
        config()->set('media.allowed_mime_types', ['application/pdf']);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $media = $product->addMedia(
            UploadedFile::fake()->create(
                'document.pdf',
                10,
                'application/pdf'
            )
        )->toCollection('products');

        Storage::disk('local')->assertExists($media->path);

        $path = $media->path;

        $media->delete();

        $this->assertDatabaseMissing('media', [
            'id' => $media->id,
        ]);

        Storage::disk('local')->assertMissing($path);
    }

    public function test_deleting_parent_model_removes_its_media_files(): void
    {
        Storage::fake('local');

        config()->set('media.disk', 'local');
        config()->set('media.allowed_mime_types', ['application/pdf']);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $media = $product->addMedia(
            UploadedFile::fake()->create(
                'document.pdf',
                10,
                'application/pdf'
            )
        )->toCollection('products');

        $path = $media->path;

        Storage::disk('local')->assertExists($path);

        $product->delete();

        $this->assertDatabaseMissing('media', [
            'id' => $media->id,
        ]);

        Storage::disk('local')->assertMissing($path);
    }

    public function test_deleting_media_removes_file_when_parent_cleanup_is_disabled(): void
    {
        Storage::fake('local');

        config()->set('media.disk', 'local');
        config()->set('media.allowed_mime_types', ['application/pdf']);
        config()->set('media.delete_files_on_model_delete', false);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $media = $product->addMedia(
            UploadedFile::fake()->create(
                'document.pdf',
                10,
                'application/pdf'
            )
        )->toCollection('products');

        $path = $media->path;

        $media->delete();

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_deleting_parent_model_preserves_media_when_cleanup_is_disabled(): void
    {
        Storage::fake('local');

        config()->set('media.disk', 'local');
        config()->set('media.allowed_mime_types', ['application/pdf']);
        config()->set('media.delete_files_on_model_delete', false);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $media = $product->addMedia(
            UploadedFile::fake()->create(
                'document.pdf',
                10,
                'application/pdf'
            )
        )->toCollection('products');

        $path = $media->path;

        $product->delete();

        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }
}
