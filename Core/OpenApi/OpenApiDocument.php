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

        return $openApi->toJson();
    }
}
