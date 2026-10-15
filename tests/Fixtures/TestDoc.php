<?php

namespace Tests\Fixtures;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A throwaway attachable for the attachment tests; its table lives only inside a test. */
class TestDoc extends Model
{
    use HasAttachments;

    protected $table = 'test_docs';

    protected $guarded = [];

    public static function createTable(): void
    {
        Schema::create('test_docs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function attachmentModule(): string
    {
        return 'settings';
    }
}
