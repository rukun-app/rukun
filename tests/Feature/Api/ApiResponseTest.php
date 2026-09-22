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

it('returns a clear unauthenticated API response', function () {
    $this->getJson('/api/auth/me')->assertUnauthorized()
        ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.', 'errors' => []]);

    $this->get('/api/auth/me')->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.', 'errors' => []]);
});
