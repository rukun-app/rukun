<?php

declare(strict_types=1);

$path = $argv[1] ?? null;
if ($path === null || ! is_file($path)) {
    fwrite(STDERR, "Usage: php scripts/ci/validate-openapi.php <openapi.json>\n");
    exit(1);
}

try {
    $document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, "Invalid OpenAPI JSON: {$exception->getMessage()}\n");
    exit(1);
}

$errors = [];
if (! str_starts_with((string) ($document['openapi'] ?? ''), '3.1.')) {
    $errors[] = 'The document must use OpenAPI 3.1.';
}
if (($document['info']['title'] ?? '') === '' || ($document['info']['version'] ?? '') === '') {
    $errors[] = 'Info title and version are required.';
}
if (($document['paths'] ?? []) === []) {
    $errors[] = 'At least one path is required.';
}

$operationIds = [];
$methods = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];
foreach ($document['paths'] ?? [] as $route => $pathItem) {
    foreach ($methods as $method) {
        if (! isset($pathItem[$method])) {
            continue;
        }

        $operation = $pathItem[$method];
        $label = strtoupper($method).' '.$route;
        $operationId = $operation['operationId'] ?? null;
        if (! is_string($operationId) || $operationId === '') {
            $errors[] = "{$label} has no operationId.";
        } elseif (isset($operationIds[$operationId])) {
            $errors[] = "Duplicate operationId {$operationId}.";
        } else {
            $operationIds[$operationId] = true;
        }

        if (($operation['responses'] ?? []) === []) {
            $errors[] = "{$label} has no responses.";
        }
    }
}

$schemas = $document['components']['schemas'] ?? [];
$walk = function (mixed $value, string $location = '$') use (&$walk, &$errors, $schemas): void {
    if (! is_array($value)) {
        return;
    }

    if (isset($value['$ref']) && str_starts_with($value['$ref'], '#/components/schemas/')) {
        $name = substr($value['$ref'], strlen('#/components/schemas/'));
        if (! array_key_exists($name, $schemas)) {
            $errors[] = "Unresolved schema reference {$value['$ref']} at {$location}.";
        }
    }

    foreach ($value as $key => $child) {
        $walk($child, $location.'.'.$key);
    }
};
$walk($document);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "- {$error}\n");
    }
    exit(1);
}

echo 'OpenAPI validation passed: '.count($document['paths']).' paths, '.count($operationIds)." operations.\n";
