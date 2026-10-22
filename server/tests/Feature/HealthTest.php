<?php

it('reports the API as healthy', function () {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonStructure(['status', 'app', 'time']);
});

it('answers unknown API paths with JSON 404', function () {
    $this->get('/api/nope')
        ->assertNotFound()
        ->assertJson(['message' => 'Not found.']);
});
