<?php

namespace Core\Http;

use Core\OpenApi\OpenApiDocument;
use Illuminate\Http\Response;

class ApiDocsController
{
    private const ASSETS = [
        'swagger-ui.css' => 'text/css',
        'swagger-ui-bundle.js' => 'application/javascript',
        'swagger-ui-standalone-preset.js' => 'application/javascript',
    ];

    public function index(): Response
    {
        $this->ensureEnabled();

        return response()->view('api-docs');
    }

    public function specification(OpenApiDocument $document): Response
    {
        $this->ensureEnabled();

        return response($document->json(), 200, ['Content-Type' => 'application/json']);
    }

    public function asset(string $asset): Response
    {
        $this->ensureEnabled();
        abort_unless(isset(self::ASSETS[$asset]), 404);

        $path = base_path('vendor/swagger-api/swagger-ui/dist/'.$asset);
        abort_unless(is_file($path), 404);

        return response(file_get_contents($path), 200, [
            'Content-Type' => self::ASSETS[$asset],
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('api-docs.enabled'), 404);
    }
}
