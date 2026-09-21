<?php

it('uses a consistent success response', function () {
    $this->getJson('/api/example')->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.module', 'Example');
});

it('uses a consistent error response', function () {
    $this->getJson('/api/missing')->assertNotFound()
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['message', 'errors']);
});
