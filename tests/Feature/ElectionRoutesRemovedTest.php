<?php

it('returns 404 for the removed election routes', function (string $url) {
    $this->get($url)->assertNotFound();
})->with([
    'index' => '/association/election',
    'show' => '/association/election/2024',
    'admin' => '/association/election/admin/2024',
]);

it('no longer registers any election route', function () {
    $names = collect(app('router')->getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->filter(fn (string $name) => str_contains($name, 'election'));

    expect($names)->toBeEmpty();
});
