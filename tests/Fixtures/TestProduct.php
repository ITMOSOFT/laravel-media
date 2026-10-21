<?php

declare(strict_types=1);

namespace ItmoSoft\Media\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use ItmoSoft\Media\Concerns\HasMedia;

class TestProduct extends Model
{
    use HasMedia;

    protected $table = 'test_products';

    protected $guarded = [];
}
