<?php

namespace Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
