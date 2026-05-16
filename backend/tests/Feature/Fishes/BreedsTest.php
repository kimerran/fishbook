<?php

it('returns the breeds catalog publicly', function () {
    $r = $this->getJson('/api/v1/fishes/breeds');
    $r->assertOk()
        ->assertJsonStructure(['data' => [['id', 'label', 'min_size', 'max_size', 'default_color', 'sprite_key']]]);
    expect(count($r->json('data')))->toBe(10);
});

it('marks otocinclus and cory_catfish as bottom-dwellers', function () {
    $r = $this->getJson('/api/v1/fishes/breeds');
    $rows = collect($r->json('data'))->keyBy('id');
    expect($rows['otocinclus']['vertical_band_preference'])->toBe('bottom');
    expect($rows['cory_catfish']['vertical_band_preference'])->toBe('bottom');
});

it('sets Cache-Control: public, max-age=3600 on /fishes/breeds', function () {
    $r = $this->getJson('/api/v1/fishes/breeds')->assertOk();
    $cc = $r->headers->get('Cache-Control');
    expect($cc)->toContain('public')->toContain('max-age=3600');
});
