<?php
namespace Modules\Engagement\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class CommunityEvent extends Model
{
    use HasUuids;
    protected $connection = 'rukun';
    protected $table = 'engagement_events';
    protected $guarded = ['id'];
    protected $attributes = ['status'=>'scheduled','fee_amount'=>0,'version'=>1];
    public function uniqueIds(): array { return ['public_id']; }
    protected function casts(): array { return ['starts_at'=>'immutable_datetime','ends_at'=>'immutable_datetime','fee_amount'=>'integer','version'=>'integer']; }
}
