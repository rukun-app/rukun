<?php
namespace Modules\Engagement\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class Incident extends Model
{
    use HasUuids;
    protected $connection = 'rukun';
    protected $table = 'engagement_incidents';
    protected $guarded = ['id'];
    protected $attributes = [];
    public function uniqueIds(): array { return ['public_id']; }
    protected function casts(): array { return []; }
}
