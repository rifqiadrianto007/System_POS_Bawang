<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('contains a valid Postman collection and local environment', function () {
    $collectionPath = base_path('docs/postman/POS Bawang API v1.postman_collection.json');
    $environmentPath = base_path('docs/postman/POS Bawang API v1.postman_environment.json');

    expect(is_file($collectionPath))->toBeTrue()
        ->and(is_file($environmentPath))->toBeTrue();

    $collection = json_decode(file_get_contents($collectionPath), true, flags: JSON_THROW_ON_ERROR);
    $environment = json_decode(file_get_contents($environmentPath), true, flags: JSON_THROW_ON_ERROR);

    expect($collection['info']['schema'])->toBe('https://schema.getpostman.com/json/collection/v2.1.0/collection.json')
        ->and($collection['item'])->toBeArray()
        ->and($environment['name'])->toBe('POS Bawang - Local')
        ->and($environment['values'])->toContainEqual([
            'key' => 'base_url',
            'value' => 'http://127.0.0.1:8000',
            'type' => 'default',
            'enabled' => true,
        ]);
});

it('covers every active v1 route with a Postman request', function () {
    $collection = json_decode(
        file_get_contents(base_path('docs/postman/POS Bawang API v1.postman_collection.json')),
        true,
        flags: JSON_THROW_ON_ERROR
    );
    $postmanRoutes = [];
    $collectRequests = function (array $items) use (&$collectRequests, &$postmanRoutes): void {
        foreach ($items as $item) {
            if (isset($item['request'])) {
                $segments = $item['request']['url']['path'] ?? [];
                $path = implode('/', array_map(
                    fn (string $segment): string => preg_match('/^\{\{.+\}\}$/', $segment) === 1 || ctype_digit($segment)
                        ? '*'
                        : $segment,
                    $segments
                ));
                $postmanRoutes[] = strtoupper($item['request']['method']).' /'.$path;
            }

            if (isset($item['item'])) {
                $collectRequests($item['item']);
            }
        }
    };
    $collectRequests($collection['item']);

    $missing = [];
    foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        $path = preg_replace('/\{[^}]+\}/', '*', substr($route->uri(), strlen('api/v1/')));
        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }

            $signature = $method.' /'.$path;
            if (! in_array($signature, $postmanRoutes, true)) {
                $missing[] = $signature;
            }
        }
    }

    expect($missing)->toBe([]);
});
