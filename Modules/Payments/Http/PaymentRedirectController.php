<?php

namespace Modules\Payments\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PaymentRedirectController
{
    public function finish(Request $request): View
    {
        return $this->render($request, 'finish');
    }

    public function unfinish(Request $request): View
    {
        return $this->render($request, 'unfinish');
    }

    public function error(Request $request): View
    {
        return $this->render($request, 'error');
    }

    private function render(Request $request, string $result): View
    {
        app()->setLocale($request->getPreferredLanguage(['en', 'id']) ?? config('app.locale'));

        return view('payments::redirect', [
            'result' => $result,
            'orderId' => mb_substr((string) $request->query('order_id', ''), 0, 100),
            'returnUrl' => config('identity.frontend_url'),
        ]);
    }
}
