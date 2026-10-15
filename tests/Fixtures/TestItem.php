<?php

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A throwaway importable for the import/export tests; its table lives only inside a test. */
class TestItem extends Model
{
    protected $table = 'test_items';

    protected $guarded = [];

    public static function createTable(): void
    {
        Schema::create('test_items', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->integer('pages')->nullable();
            $table->timestamps();
        });
    }
}
