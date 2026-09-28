<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('payments::redirect.'.$result.'.title') }} · {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #f5f7fb; color: #172033; }
        .card { width: min(100%, 520px); background: #fff; border: 1px solid #e6eaf0; border-radius: 24px; padding: 40px; box-shadow: 0 24px 70px rgba(30, 42, 70, .12); text-align: center; }
        .icon { width: 72px; height: 72px; margin: 0 auto 24px; display: grid; place-items: center; border-radius: 50%; font-size: 34px; font-weight: 700; }
        .finish .icon { color: #087f5b; background: #e6fcf5; }
        .unfinish .icon { color: #b26a00; background: #fff4d6; }
        .error .icon { color: #c92a2a; background: #fff0f0; }
        h1 { margin: 0 0 12px; font-size: clamp(26px, 6vw, 36px); letter-spacing: -.03em; }
        p { margin: 0; color: #667085; line-height: 1.65; }
        .reference { margin: 24px 0 0; padding: 14px 16px; background: #f8fafc; border-radius: 12px; font-family: ui-monospace, monospace; font-size: 13px; overflow-wrap: anywhere; }
        .notice { margin-top: 20px; font-size: 13px; }
        .button { display: inline-flex; margin-top: 28px; padding: 12px 20px; border-radius: 12px; color: #fff; background: #172033; text-decoration: none; font-weight: 650; }
        @media (max-width: 520px) { .card { padding: 30px 22px; border-radius: 18px; } }
    </style>
</head>
<body>
<main class="card {{ $result }}">
    <div class="icon" aria-hidden="true">{{ $result === 'finish' ? '✓' : ($result === 'unfinish' ? '…' : '×') }}</div>
    <h1>{{ __('payments::redirect.'.$result.'.title') }}</h1>
    <p>{{ __('payments::redirect.'.$result.'.message') }}</p>
    @if ($orderId !== '')
        <div class="reference">{{ __('payments::redirect.reference') }}: {{ $orderId }}</div>
    @endif
    <p class="notice">{{ __('payments::redirect.verification_notice') }}</p>
    <a class="button" href="{{ $returnUrl }}">{{ __('payments::redirect.return') }}</a>
</main>
</body>
</html>
