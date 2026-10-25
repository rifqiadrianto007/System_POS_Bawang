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
