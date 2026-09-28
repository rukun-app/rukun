<?php

namespace Modules\Community\Http;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;

class PopulationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $common = ['public_id' => $this->public_id, 'reference' => $this->reference, 'area_id' => Area::query()->findOrFail($this->area_id)->public_id, 'status' => $this->status];
        if ($this->resource instanceof Household) {
            return [...$common, 'address' => $this->address, 'block' => $this->block, 'house_number' => $this->house_number, 'occupancy_status' => $this->occupancy_status];
        }

        return [...$common, 'name' => $this->name, 'birth_date' => $this->birth_date?->format('Y-m-d'), 'phone' => $this->phone, 'household_id' => $this->household_id ? Household::query()->findOrFail($this->household_id)->public_id : null, 'user_id' => $this->user_id ? User::query()->findOrFail($this->user_id)->public_id : null];
    }
}
