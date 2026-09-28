<?php

namespace Core\OpenApi;

use OpenApi\Builder;

class OpenApiDocument
{
    public function json(): string
    {
        $result = (new Builder)
            ->setVersion('3.1.0')
            ->addSource(base_path('Core'))
            ->addSource(base_path('Modules'))
            ->build();
        $openApi = $result->openApi();

        if ($openApi === null) {
            throw new \RuntimeException('Unable to generate the OpenAPI document.');
        }

        $openApi->info->title = config('api-docs.title');
        $openApi->info->version = config('api-docs.version');

        $document = json_decode($openApi->toJson(), true, flags: JSON_THROW_ON_ERROR);

        foreach ($document['paths'] as &$pathItem) {
            foreach ($pathItem as &$operation) {
                if (! is_array($operation) || ! isset($operation['responses'])) {
                    continue;
                }

                foreach ($operation['responses'] as &$response) {
                    $response['headers']['X-Request-ID'] = [
                        'description' => 'UUID correlation identifier. A valid client supplied value is echoed; otherwise the server generates one.',
                        'schema' => ['type' => 'string', 'format' => 'uuid'],
                        'example' => '0199a4e7-3f20-7b31-a592-1ed567ba84f1',
                    ];
                }
            }
        }

        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
