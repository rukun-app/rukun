<?php

namespace Modules\Civic\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use HasUuids;

    protected $connection = 'rukun';

    protected $table = 'civic_announcements';

    protected $guarded = ['id'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['publish_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime'];
    }
}
