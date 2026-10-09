<?php

namespace Modules\Billing\Http;

use Core\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Billing\Models\GatewayCheckout;
use Modules\Billing\Models\GatewaySettlement;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Receipt;
use Modules\Billing\Services\BillingScope;
use Modules\Billing\Services\GatewayBilling;
use Modules\Community\Models\Area;
use Modules\Community\Models\Household;
use Modules\Community\Services\ScopeResolver;
use Modules\Payments\Enums\PaymentStatus;
use Modules\Payments\Exceptions\PaymentGatewayException;
use Modules\Payments\Models\Payment;

class GatewayController
{
    public function __construct(private GatewayBilling $gateway, private BillingScope $scope) {}

    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        $request->validate(['amount' => ['prohibited'], 'allocations' => ['prohibited'], 'metadata' => ['prohibited']]);
        $checkout = $this->gateway->checkout($request->user(), $invoice, $this->key($request));

        return ApiResponse::success($this->resource($checkout), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'status' => ['sometimes', 'in:reserved,completed,released,review']]);
        $areas = app(ScopeResolver::class)->constrain(Area::query(), $request->user(), 'payments.gateway.reconcile')->pluck('id');
        $query = GatewayCheckout::query()->whereIn('area_id', $areas);
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return ApiResponse::success($query->orderBy('public_id')->collectionPaginate($request->integer('per_page', 20))->through(fn ($checkout) => $this->resource($checkout)));
    }

    public function show(Request $request, GatewayCheckout $checkout): JsonResponse
    {
        $this->scope->household($request->user(), 'invoices.view', Household::query()->findOrFail($checkout->household_id));

        return ApiResponse::success($this->resource($checkout));
    }

    public function reconcile(Request $request, GatewayCheckout $checkout): JsonResponse
    {
        $this->key($request);
        $this->scope->household($request->user(), 'payments.gateway.create', Household::query()->findOrFail($checkout->household_id));
        try {
            $checkout = $this->gateway->reconcile($checkout);
        } catch (PaymentGatewayException) {
            return ApiResponse::error(__('payments::messages.checkout_unavailable'), 503, [], 'payment.gateway_unavailable');
        }

        return ApiResponse::success($this->resource($checkout));
    }

    public function settlement(Request $request, GatewayCheckout $checkout): JsonResponse
    {
        $data = $request->validate(['fee' => ['required', 'integer', 'min:0'], 'settled_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'statement_reference' => ['required', 'string', 'max:200']]);
        $data['fee'] = (int) $data['fee'];
        $this->gateway->settle($request->user(), $checkout, $this->key($request), $data);

        return ApiResponse::success($this->resource($checkout), 201);
    }

    private function resource(GatewayCheckout $checkout): array
    {
        $payment = Payment::query()->findOrFail($checkout->payment_id);
        $settlement = GatewaySettlement::query()->where('checkout_id', $checkout->id)->first();

        return ['public_id' => $checkout->public_id, 'invoice_id' => Invoice::query()->findOrFail($checkout->invoice_id)->public_id, 'payment_id' => $payment->public_id, 'amount' => $checkout->amount, 'currency' => 'IDR', 'status' => $checkout->status, 'payment_status' => $payment->status->value, 'checkout_url' => $checkout->status === 'reserved' && $payment->status === PaymentStatus::Pending ? $payment->checkout_url : null, 'expires_at' => $checkout->expires_at->toISOString(), 'paid_at' => $payment->paid_at?->toISOString(), 'receipt_id' => $checkout->receipt_id ? Receipt::query()->findOrFail($checkout->receipt_id)->public_id : null, 'review_reason' => $checkout->review_reason, 'settlement' => $settlement ? ['public_id' => $settlement->public_id, 'gross' => $settlement->gross, 'fee' => $settlement->fee, 'net' => $settlement->net, 'settled_on' => $settlement->settled_on->toDateString(), 'statement_reference' => $settlement->statement_reference] : null];
    }

    private function key(Request $request): string
    {
        return Validator::make(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/']])->validate()['key'];
    }
}
