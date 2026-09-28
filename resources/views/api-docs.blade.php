<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('api-docs.title') }}</title>
    <link rel="stylesheet" href="{{ route('api-docs.asset', ['asset' => 'swagger-ui.css'], absolute: false) }}">
</head>
<body>
<div id="swagger-ui"></div>
<script src="{{ route('api-docs.asset', ['asset' => 'swagger-ui-bundle.js'], absolute: false) }}"></script>
<script src="{{ route('api-docs.asset', ['asset' => 'swagger-ui-standalone-preset.js'], absolute: false) }}"></script>
<script>
    window.onload = () => SwaggerUIBundle({
        url: @json(route('api-docs.specification', absolute: false)),
        dom_id: '#swagger-ui',
        deepLinking: true,
        displayRequestDuration: true,
        persistAuthorization: true,
        presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
        layout: 'StandaloneLayout',
    });
</script>
</body>
</html>
