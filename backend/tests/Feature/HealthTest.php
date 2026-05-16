<?php

it('returns ok on the health endpoint', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJson(['ok' => true])
        ->assertJsonStructure(['ok', 'version']);
});
