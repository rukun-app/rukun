<?php

namespace Modules\Wifi\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Billing\Models\PaymentType;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Models\Vendor;
use Modules\Wifi\Models\WifiCustomer;
use Modules\Wifi\Models\WifiPackage;

class WifiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = ['public_id' => $this->public_id, 'area_id' => Area::query()->findOrFail($this->area_id)->public_id, 'vendor_id' => Vendor::query()->findOrFail($this->vendor_id)->public_id];
        if ($this->resource instanceof WifiCustomer) {
            $household = Household::query()->findOrFail($this->household_id);

            return [...$data, 'package_id' => WifiPackage::query()->findOrFail($this->package_id)->public_id, 'household_id' => $household->public_id, 'household_reference' => $household->reference, 'starts_on' => $this->starts_on->toDateString(), 'ends_on' => $this->ends_on?->toDateString(), 'status' => $this->ends_on?->lessThanOrEqualTo(today()) ? 'ended' : ($this->starts_on->greaterThan(today()) ? 'scheduled' : 'active')];
        }

        return [...$data, 'name' => $this->name, 'payment_type_id' => PaymentType::query()->findOrFail($this->payment_type_id)->public_id, 'due_day' => $this->due_day, 'settle_day' => $this->settle_day, 'remit_day' => $this->remit_day, 'allow_advance' => $this->allow_advance, 'gallon_quota' => $this->gallon_quota, 'claim_days' => $this->claim_days];
    }
}
