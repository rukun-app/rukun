<?php
namespace Modules\Engagement\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class Participant extends Model
{
    use HasUuids;
    protected $connection = 'rukun';
    protected $table = 'engagement_participants';
    protected $guarded = ['id'];
    protected $attributes = ['status'=>'active','attendance'=>'pending','leave_status'=>'none','version'=>1,'waived'=>false];
    public function uniqueIds(): array { return ['public_id']; }
    protected function casts(): array { return ['version'=>'integer','waived'=>'boolean','attendance_at'=>'immutable_datetime']; }
}
