<?php

namespace Core\Console;

use Core\OpenApi\OpenApiDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateApiDocsCommand extends Command
{
    protected $signature = 'api-docs:generate {--output= : Output path relative to the application root}';

    protected $description = 'Validate and generate the OpenAPI JSON document';

    public function handle(OpenApiDocument $document): int
    {
        $path = $this->option('output') ?: 'storage/app/api-docs/openapi.json';
        $absolutePath = base_path($path);

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, $document->json().PHP_EOL);
        $this->components->info("OpenAPI document generated at {$path}.");

        return self::SUCCESS;
    }
}
