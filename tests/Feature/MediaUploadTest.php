<?php

declare(strict_types=1);

namespace ItmoSoft\Media\Tests\Feature;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use ItmoSoft\Media\MediaConversionManager;
use ItmoSoft\Media\MediaManager;
use ItmoSoft\Media\Models\Media;
use ItmoSoft\Media\Tests\Fixtures\TestProduct;
use ItmoSoft\Media\Tests\TestCase;
use UnexpectedValueException;

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

    public function test_it_generates_a_webp_thumbnail_and_preserves_the_original(): void
    {
        Storage::fake('local');

        config()->set('media.disk', 'local');
        config()->set('media.conversions', [
            'thumb' => [
                'width' => 150,
                'height' => 150,
                'format' => 'webp',
                'quality' => 80,
            ],
        ]);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        // Create a valid PNG image using the installed GD driver.
        $image = (new ImageManager(new Driver))
            ->create(400, 300)
            ->fill('#336699');

        $file = UploadedFile::fake()->createWithContent(
            'photo.png',
            $image->toPng()->toString()
        );

        $media = $product->addMedia($file)
            ->toCollection('gallery');

        $originalPath = $media->path;

        app(MediaConversionManager::class)->generate($media);

        $media->refresh();

        $conversionPath = dirname($originalPath)
            .'/conversions/thumb.webp';

        Storage::disk('local')->assertExists($originalPath);
        Storage::disk('local')->assertExists($conversionPath);

        $this->assertSame(
            true,
            $media->generated_conversions['thumb'] ?? false
        );

        $this->assertSame(
            400,
            $image->width()
        );

        $this->assertSame(
            300,
            $image->height()
        );
    }

    public function test_it_does_not_create_a_media_record_when_storage_returns_false(): void
    {
        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $file = UploadedFile::fake()->create(
            'document.pdf',
            10,
            'application/pdf'
        );

        config()->set('media.disk', 'local');
        config()->set('media.allowed_mime_types', ['application/pdf']);

        $filesystem = \Mockery::mock(Filesystem::class);

        $filesystem->shouldReceive('putFileAs')
            ->once()
            ->andReturn(false);

        $filesystem->shouldReceive('delete')
            ->once()
            ->with(\Mockery::type('string'))
            ->andReturn(true);

        Storage::shouldReceive('disk')
            ->once()
            ->with('local')
            ->andReturn($filesystem);

        try {
            app(MediaManager::class)->upload(
                $product,
                $file,
                'documents'
            );

            $this->fail('Expected storage failure to be thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Unable to store the uploaded media file.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount('media', 0);
    }

    public function test_it_does_not_create_a_media_record_when_storage_fails(): void
    {
        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $file = UploadedFile::fake()->create(
            'document.pdf',
            10,
            'application/pdf'
        );

        config()->set('media.disk', 'local');
        config()->set('media.allowed_mime_types', ['application/pdf']);

        $filesystem = \Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('putFileAs')
            ->once()
            ->andThrow(new \RuntimeException('Simulated storage failure.'));

        Storage::shouldReceive('disk')
            ->once()
            ->with('local')
            ->andReturn($filesystem);

        try {
            app(MediaManager::class)->upload(
                $product,
                $file,
                'documents'
            );

            $this->fail('Expected storage failure to be propagated.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Simulated storage failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount('media', 0);
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
        } catch (UnexpectedValueException $exception) {
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
        } catch (UnexpectedValueException $exception) {
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
        } catch (UnexpectedValueException $exception) {
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

    public function test_it_rejects_an_empty_collection_name(): void
    {
        Storage::fake('local');

        config()->set('media.disk', 'local');
        config()->set('media.allowed_mime_types', ['application/pdf']);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $file = UploadedFile::fake()->create(
            'document.pdf',
            10,
            'application/pdf'
        );

        try {
            app(MediaManager::class)->upload($product, $file, '');

            $this->fail('Expected an empty collection name to be rejected.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame(
                'The media collection name cannot be empty.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount('media', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('media'));
    }

    public function test_it_rejects_a_whitespace_only_collection_name(): void
    {
        Storage::fake('local');

        config()->set('media.disk', 'local');
        config()->set('media.allowed_mime_types', ['application/pdf']);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $file = UploadedFile::fake()->create(
            'document.pdf',
            10,
            'application/pdf'
        );

        try {
            app(MediaManager::class)->upload($product, $file, '   ');

            $this->fail(
                'Expected a whitespace-only collection name to be rejected.'
            );
        } catch (UnexpectedValueException $exception) {
            $this->assertSame(
                'The media collection name cannot be empty.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount('media', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('media'));
    }

    public function test_it_replaces_media_in_a_collection(): void
    {
        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $oldMedia = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'old-cover.jpg',
                'fake image content'
            )
        )->toCollection('cover');

        $oldPath = $oldMedia->path;

        $newMedia = $product->replaceMedia(
            UploadedFile::fake()->createWithContent(
                'new-cover.jpg',
                'fake image content'
            ),
            'cover'
        );

        $this->assertDatabaseCount('media', 1);
        $this->assertDatabaseHas('media', [
            'id' => $newMedia->id,
            'collection_name' => 'cover',
        ]);

        $this->assertNotSame($oldMedia->id, $newMedia->id);
        $this->assertFalse(
            Storage::disk($oldMedia->disk)
                ->exists($oldPath)
        );
        $this->assertTrue(
            Storage::disk($newMedia->disk)
                ->exists($newMedia->path)
        );
    }

    public function test_it_keeps_existing_media_when_replacement_upload_fails(): void
    {
        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $oldMedia = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'old-cover.jpg',
                'fake image content'
            )
        )->toCollection('cover');

        $oldPath = $oldMedia->path;

        config()->set('media.allowed_mime_types', ['application/pdf']);

        try {
            $product->replaceMedia(
                UploadedFile::fake()->createWithContent(
                    'new-cover.jpg',
                    'fake image content'
                ),
                'cover'
            );

            $this->fail('Expected the replacement upload to fail.');
        } catch (UnexpectedValueException) {
            // The old media should remain untouched.
        }

        $this->assertDatabaseCount('media', 1);
        $this->assertDatabaseHas('media', [
            'id' => $oldMedia->id,
            'collection_name' => 'cover',
        ]);
        $this->assertTrue(
            Storage::disk($oldMedia->disk)
                ->exists($oldPath)
        );
    }

    public function test_it_keeps_existing_media_when_replacement_storage_fails(): void
    {
        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $oldMedia = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'old-document.pdf',
                'old document content'
            )
        )->toCollection('documents');

        $oldPath = $oldMedia->path;

        $file = UploadedFile::fake()->createWithContent(
            'new-document.pdf',
            'new document content'
        );

        $filesystem = \Mockery::mock(
            Filesystem::class
        );

        $filesystem->shouldReceive('putFileAs')
            ->once()
            ->andThrow(new \RuntimeException('Simulated storage failure.'));

        $filesystem->shouldReceive('delete')
            ->once()
            ->with(\Mockery::type('string'))
            ->andReturn(true);

        Storage::shouldReceive('disk')
            ->once()
            ->with(config('media.disk', 'local'))
            ->andReturn($filesystem);

        try {
            $product->replaceMedia($file, 'documents');

            $this->fail('Expected storage failure to be propagated.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Simulated storage failure.',
                $exception->getMessage()
            );
        }

        $this->assertDatabaseCount('media', 1);
        $this->assertDatabaseHas('media', [
            'id' => $oldMedia->id,
            'path' => $oldPath,
        ]);
    }

    public function test_it_reorders_media_within_a_collection(): void
    {
        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $first = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'first.pdf',
                'first document'
            )
        )->toCollection('gallery');

        $second = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'second.pdf',
                'second document'
            )
        )->toCollection('gallery');

        $third = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'third.pdf',
                'third document'
            )
        )->toCollection('gallery');

        $product->reorderMedia([
            $third->id,
            $first->id,
            $second->id,
        ], 'gallery');

        $this->assertSame(
            [$third->id, $first->id, $second->id],
            $product->getMedia('gallery')->pluck('id')->all()
        );

        $this->assertSame(1, $third->fresh()->order_column);
        $this->assertSame(2, $first->fresh()->order_column);
        $this->assertSame(3, $second->fresh()->order_column);
    }

    public function test_it_rejects_media_from_another_collection_when_reordering(): void
    {
        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $galleryMedia = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'gallery.pdf',
                'gallery document'
            )
        )->toCollection('gallery');

        $coverMedia = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'cover.pdf',
                'cover document'
            )
        )->toCollection('cover');

        $this->expectException(\InvalidArgumentException::class);

        $product->reorderMedia([
            $galleryMedia->id,
            $coverMedia->id,
        ], 'gallery');
    }

    public function test_it_rejects_duplicate_media_ids_when_reordering(): void
    {
        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $media = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'document.pdf',
                'document content'
            )
        )->toCollection('gallery');

        $this->expectException(\InvalidArgumentException::class);

        $product->reorderMedia([
            $media->id,
            $media->id,
        ], 'gallery');
    }

    public function test_it_rejects_media_belonging_to_another_model_when_reordering(): void
    {
        $product = TestProduct::query()->create([
            'name' => 'Product A',
        ]);

        $anotherProduct = TestProduct::query()->create([
            'name' => 'Product B',
        ]);

        $productMedia = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'product-a.pdf',
                'product a'
            )
        )->toCollection('gallery');

        $anotherProductMedia = $anotherProduct->addMedia(
            UploadedFile::fake()->createWithContent(
                'product-b.pdf',
                'product b'
            )
        )->toCollection('gallery');

        $this->expectException(\InvalidArgumentException::class);

        $product->reorderMedia([
            $productMedia->id,
            $anotherProductMedia->id,
        ], 'gallery');
    }

    public function test_it_assigns_order_independently_per_collection(): void
    {
        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $firstGalleryMedia = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'gallery-one.pdf',
                'gallery one'
            )
        )->toCollection('gallery');

        $coverMedia = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'cover.pdf',
                'cover'
            )
        )->toCollection('cover');

        $secondGalleryMedia = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'gallery-two.pdf',
                'gallery two'
            )
        )->toCollection('gallery');

        $this->assertSame(1, $firstGalleryMedia->fresh()->order_column);
        $this->assertSame(1, $coverMedia->fresh()->order_column);
        $this->assertSame(2, $secondGalleryMedia->fresh()->order_column);
    }

    public function test_it_rejects_uploads_when_collection_limit_is_reached(): void
    {
        config()->set('media.collections.cover', [
            'max_files' => 1,
            'on_limit' => 'reject',
        ]);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'first.pdf',
                'first document'
            )
        )->toCollection('cover');

        $this->expectException(\RuntimeException::class);

        $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'second.pdf',
                'second document'
            )
        )->toCollection('cover');
    }

    public function test_it_replaces_existing_media_when_collection_limit_is_reached(): void
    {
        config()->set('media.collections.cover', [
            'max_files' => 1,
            'on_limit' => 'replace',
        ]);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $oldMedia = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'old.pdf',
                'old document'
            )
        )->toCollection('cover');

        $newMedia = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'new.pdf',
                'new document'
            )
        )->toCollection('cover');

        $this->assertDatabaseCount('media', 1);
        $this->assertDatabaseHas('media', [
            'id' => $newMedia->id,
            'collection_name' => 'cover',
        ]);
        $this->assertDatabaseMissing('media', [
            'id' => $oldMedia->id,
        ]);
    }

    public function test_it_allows_uploads_within_collection_limit(): void
    {
        config()->set('media.collections.gallery', [
            'max_files' => 3,
            'on_limit' => 'reject',
        ]);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $first = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'first.pdf',
                'first document'
            )
        )->toCollection('gallery');

        $second = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'second.pdf',
                'second document'
            )
        )->toCollection('gallery');

        $this->assertDatabaseCount('media', 2);
        $this->assertSame(1, $first->fresh()->order_column);
        $this->assertSame(2, $second->fresh()->order_column);
    }

    public function test_it_preserves_existing_media_when_collection_replacement_storage_fails(): void
    {
        config()->set('media.collections.cover', [
            'max_files' => 1,
            'on_limit' => 'replace',
        ]);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $oldMedia = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'old.pdf',
                'old document content'
            )
        )->toCollection('cover');

        $oldPath = $oldMedia->path;

        $filesystem = \Mockery::mock(Filesystem::class);

        $filesystem->shouldReceive('putFileAs')
            ->once()
            ->andThrow(new \RuntimeException('Simulated storage failure.'));

        $filesystem->shouldReceive('delete')
            ->once()
            ->with(\Mockery::type('string'))
            ->andReturn(true);

        Storage::shouldReceive('disk')
            ->once()
            ->with(config('media.disk', 'local'))
            ->andReturn($filesystem);

        try {
            $product->addMedia(
                UploadedFile::fake()->createWithContent(
                    'new.pdf',
                    'new document content'
                )
            )->toCollection('cover');

            $this->fail('Expected storage failure to be propagated.');
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() !== 'Simulated storage failure.') {
                throw $exception;
            }
        }

        $this->assertDatabaseCount('media', 1);

        $this->assertDatabaseHas('media', [
            'id' => $oldMedia->id,
            'path' => $oldPath,
        ]);
    }

    public function test_it_rejects_invalid_conversion_configuration_before_writing_files(): void
    {
        Storage::fake('local');

        config()->set('media.disk', 'local');
        config()->set('media.conversions', [
            'thumb' => [
                'width' => 150,
                'height' => 150,
                'format' => 'webp',
                'quality' => 80,
            ],
            'invalid' => [
                'width' => 0,
                'height' => 100,
                'format' => 'webp',
                'quality' => 80,
            ],
        ]);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $image = (new ImageManager(new Driver))
            ->create(400, 300)
            ->fill('#336699');

        $media = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'photo.png',
                $image->toPng()->toString(),
            )
        )->toCollection('gallery');

        $conversionDirectory = dirname($media->path).'/conversions';

        try {
            app(MediaConversionManager::class)->generate($media);

            $this->fail('Expected invalid conversion configuration to be rejected.');
        } catch (UnexpectedValueException $exception) {
            $this->assertStringContainsString(
                'positive integer width and height',
                $exception->getMessage(),
            );
        }

        Storage::disk('local')->assertMissing(
            $conversionDirectory.'/thumb.webp'
        );

        Storage::disk('local')->assertExists($media->path);
    }

    public function test_it_cleans_up_new_conversions_when_a_conversion_write_fails(): void
    {
        Storage::fake('local');

        config()->set('media.disk', 'local');
        config()->set('media.conversions', [
            'thumb' => [
                'width' => 150,
                'height' => 150,
                'format' => 'webp',
                'quality' => 80,
            ],
            'medium' => [
                'width' => 300,
                'height' => 300,
                'format' => 'webp',
                'quality' => 85,
            ],
        ]);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $image = (new ImageManager(new Driver))
            ->create(400, 300)
            ->fill('#336699');

        $media = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'photo.png',
                $image->toPng()->toString(),
            )
        )->toCollection('gallery');

        $directory = dirname($media->path).'/conversions';

        $disk = Storage::disk('local');

        // The first conversion succeeds; the second write fails.
        $filesystem = \Mockery::mock(Filesystem::class);

        $filesystem->shouldReceive('exists')
            ->andReturnUsing(fn (string $path): bool => $disk->exists($path));

        $filesystem->shouldReceive('get')
            ->andReturnUsing(fn (string $path): string => $disk->get($path));

        $filesystem->shouldReceive('put')
            ->once()
            ->with($directory.'/thumb.webp', \Mockery::type('string'))
            ->andReturnUsing(
                fn (string $path, string $contents): bool => $disk->put($path, $contents)
            );

        $filesystem->shouldReceive('put')
            ->once()
            ->with($directory.'/medium.webp', \Mockery::type('string'))
            ->andReturn(false);

        $filesystem->shouldReceive('delete')
            ->once()
            ->with($directory.'/thumb.webp')
            ->andReturnUsing(fn (string $path): bool => $disk->delete($path));

        Storage::shouldReceive('disk')
            ->with('local')
            ->andReturn($filesystem);

        try {
            app(MediaConversionManager::class)->generate($media);

            $this->fail('Expected conversion storage failure to be propagated.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'Unable to store media conversion',
                $exception->getMessage(),
            );
        }

        $disk->assertMissing($directory.'/thumb.webp');
        $disk->assertMissing($directory.'/medium.webp');
        $disk->assertExists($media->path);
    }

    public function test_deleting_media_removes_its_generated_conversion_files(): void
    {
        Storage::fake('local');

        config()->set('media.disk', 'local');
        config()->set('media.conversions', [
            'thumb' => [
                'width' => 150,
                'height' => 150,
                'format' => 'webp',
                'quality' => 80,
            ],
        ]);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $image = (new ImageManager(new Driver))
            ->create(400, 300)
            ->fill('#336699');

        $media = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'photo.png',
                $image->toPng()->toString(),
            )
        )->toCollection('gallery');

        app(MediaConversionManager::class)->generate($media);

        $originalPath = $media->path;
        $conversionPath = dirname($originalPath)
            .'/conversions/thumb.webp';

        Storage::disk('local')->assertExists($originalPath);
        Storage::disk('local')->assertExists($conversionPath);

        $media->delete();

        Storage::disk('local')->assertMissing($originalPath);
        Storage::disk('local')->assertMissing($conversionPath);
    }

    public function test_it_returns_original_and_generated_conversion_urls(): void
    {
        Storage::fake('local');

        config()->set('media.disk', 'local');
        config()->set('media.conversions', [
            'thumb' => [
                'width' => 150,
                'height' => 150,
                'format' => 'webp',
                'quality' => 80,
            ],
        ]);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $image = (new ImageManager(new Driver))
            ->create(400, 300)
            ->fill('#336699');

        $media = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'photo.png',
                $image->toPng()->toString(),
            )
        )->toCollection('gallery');

        $this->assertSame(
            Storage::disk('local')->url($media->path),
            $media->getUrl(),
        );

        app(MediaConversionManager::class)->generate($media);
        $media->refresh();

        $conversionPath = dirname($media->path)
            .'/conversions/thumb.webp';

        $this->assertSame(
            Storage::disk('local')->url($conversionPath),
            $media->getUrl('thumb'),
        );
    }

    public function test_it_rejects_a_conversion_url_when_conversion_has_not_been_generated(): void
    {
        Storage::fake('local');

        config()->set('media.disk', 'local');
        config()->set('media.conversions', [
            'thumb' => [
                'width' => 150,
                'height' => 150,
                'format' => 'webp',
                'quality' => 80,
            ],
        ]);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $media = $product->addMedia(
            UploadedFile::fake()->create('document.pdf', 10, 'application/pdf')
        )->toCollection('gallery');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            "Media conversion 'thumb' has not been generated."
        );

        $media->getUrl('thumb');
    }

    public function test_it_rejects_an_unknown_conversion_url(): void
    {
        Storage::fake('local');

        config()->set('media.disk', 'local');
        config()->set('media.conversions', []);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $media = $product->addMedia(
            UploadedFile::fake()->create('document.pdf', 10, 'application/pdf')
        )->toCollection('gallery');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            "Media conversion 'unknown' has not been generated."
        );

        $media->getUrl('unknown');
    }

    public function test_it_generates_configured_conversions_automatically_during_upload(): void
    {
        Storage::fake('local');
        config()->set('media.auto_generate_conversions', true);

        config()->set('media.disk', 'local');
        config()->set('media.allowed_mime_types', [
            'image/png',
        ]);
        config()->set('media.conversions', [
            'thumb' => [
                'width' => 150,
                'height' => 150,
                'format' => 'webp',
                'quality' => 80,
            ],
        ]);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $image = (new ImageManager(new Driver))
            ->create(400, 300)
            ->fill('#336699');

        $media = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'photo.png',
                $image->toPng()->toString(),
            )
        )->toCollection('gallery');

        $conversionPath = dirname($media->path)
            .'/conversions/thumb.webp';

        Storage::disk('local')->assertExists($media->path);
        Storage::disk('local')->assertExists($conversionPath);

        $this->assertTrue(
            $media->fresh()->generated_conversions['thumb'] ?? false
        );
    }

    public function test_it_cleans_up_the_upload_when_automatic_conversion_fails(): void
    {
        Storage::fake('local');

        config()->set('media.auto_generate_conversions', true);
        config()->set('media.disk', 'local');
        config()->set('media.allowed_mime_types', [
            'image/png',
        ]);
        config()->set('media.conversions', [
            'thumb' => [
                'width' => 150,
                'height' => 150,
                'format' => 'webp',
                'quality' => 80,
            ],
        ]);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'The media file could not be decoded as an image.'
        );

        try {
            $product->addMedia(
                UploadedFile::fake()->createWithContent(
                    'broken.png',
                    'this is not a valid PNG image',
                )
            )->toCollection('gallery');
        } finally {
            $this->assertDatabaseCount('media', 0);

            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_it_preserves_existing_media_when_replacement_conversion_fails(): void
    {
        Storage::fake('local');

        config()->set('media.auto_generate_conversions', true);
        config()->set('media.disk', 'local');
        config()->set('media.allowed_mime_types', ['image/png']);
        config()->set('media.conversions', [
            'thumb' => [
                'width' => 150,
                'height' => 150,
                'format' => 'webp',
                'quality' => 80,
            ],
        ]);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $validImage = (new ImageManager(new Driver))
            ->create(400, 300)
            ->fill('#336699')
            ->toPng()
            ->toString();

        $oldMedia = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'old-cover.png',
                $validImage,
            )
        )->toCollection('cover');

        $oldPath = $oldMedia->path;

        try {
            $product->replaceMedia(
                UploadedFile::fake()->createWithContent(
                    'broken-cover.png',
                    'this is not a valid PNG image',
                ),
                'cover',
            );

            $this->fail('Expected the replacement conversion to fail.');
        } catch (UnexpectedValueException $exception) {
            $this->assertSame(
                'The media file could not be decoded as an image.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('media', 1);

        $this->assertDatabaseHas('media', [
            'id' => $oldMedia->id,
            'path' => $oldPath,
            'collection_name' => 'cover',
        ]);

        Storage::disk('local')->assertExists($oldPath);

        $files = Storage::disk('local')->allFiles();

        $this->assertCount(2, $files);
        Storage::disk('local')->assertExists($oldPath);
        Storage::disk('local')->assertExists(
            dirname($oldPath).'/conversions/thumb.webp'
        );
        $this->assertStringNotContainsString('broken-cover', implode(' ', $files));
    }

    public function test_it_restores_conversion_metadata_when_saving_fails(): void
    {
        Storage::fake('local');

        config()->set('media.disk', 'local');
        config()->set('media.conversions', [
            'thumb' => [
                'width' => 150,
                'height' => 150,
                'format' => 'webp',
                'quality' => 80,
            ],
        ]);

        $product = TestProduct::query()->create([
            'name' => 'Test product',
        ]);

        $image = (new ImageManager(new Driver))
            ->create(400, 300)
            ->fill('#336699');

        $media = $product->addMedia(
            UploadedFile::fake()->createWithContent(
                'photo.png',
                $image->toPng()->toString(),
            )
        )->toCollection('gallery');

        $originalMetadata = $media->generated_conversions ?? [];

        $originalDispatcher = Media::getEventDispatcher();

        Media::setEventDispatcher(new Dispatcher(app()));

        Media::updating(function (Media $model) use ($media): void {
            if ($model->is($media)) {
                throw new \RuntimeException('Simulated metadata save failure.');
            }
        });

        try {
            app(MediaConversionManager::class)->generate($media);

            $this->fail('Expected metadata save failure to be propagated.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Simulated metadata save failure.',
                $exception->getMessage(),
            );
        } finally {
            Media::setEventDispatcher($originalDispatcher);
        }

        $this->assertSame($originalMetadata, $media->generated_conversions ?? []);

        Storage::disk('local')->assertExists($media->path);
        Storage::disk('local')->assertMissing(
            dirname($media->path).'/conversions/thumb.webp'
        );

        $this->assertSame(
            $originalMetadata,
            $media->fresh()->generated_conversions ?? [],
        );
    }
}
