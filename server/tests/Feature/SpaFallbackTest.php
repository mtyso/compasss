<?php

beforeEach(function () {
    $this->index = public_path('index.html');
    $this->hadIndex = is_file($this->index);
    if (! $this->hadIndex) {
        file_put_contents($this->index, '<!doctype html><title>Kompas</title><div id="app"></div>');
    }
});

afterEach(function () {
    if (! $this->hadIndex) {
        unlink($this->index);
    }
});

it('serves the app shell for client-side routes', function (string $path) {
    $response = $this->get($path)->assertOk();

    expect($response->baseResponse->getFile()->getPathname())->toBe($this->index);
})->with(['/', '/money', '/tasks/42']);
