<?php

use App\Console\Commands\CampusInstall;

test('demo data is refused outside local and testing, before any write', function () {
    foreach (['production', 'staging', 'demo'] as $environment) {
        expect(CampusInstall::refusal($environment, demo: true, force: true))->toContain('sólo en local o testing');
    }
    expect(CampusInstall::refusal('local', demo: true, force: false))->toBeNull()
        ->and(CampusInstall::refusal('testing', demo: true, force: false))->toBeNull();
});

test('production installs require --force; other environments do not', function () {
    expect(CampusInstall::refusal('production', demo: false, force: false))->toContain('--force')
        ->and(CampusInstall::refusal('production', demo: false, force: true))->toBeNull()
        ->and(CampusInstall::refusal('local', demo: false, force: false))->toBeNull();
});
