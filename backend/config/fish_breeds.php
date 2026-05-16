<?php

/**
 * Static catalog of fish breeds. See SPEC §4.
 * Served (public) via GET /api/v1/fishes/breeds.
 * Used (server-side) by BreedCatalog for size validation.
 *
 * vertical_band_preference: 'bottom' constrains target.y > viewport.h * 0.6 client-side.
 */
return [
    ['id' => 'guppy',              'label' => 'Guppy',                'min_size' => 8,  'max_size' => 18, 'default_color' => '#FF6B9D', 'sprite_key' => 'guppy'],
    ['id' => 'molly',              'label' => 'Molly',                'min_size' => 12, 'max_size' => 22, 'default_color' => '#1F2937', 'sprite_key' => 'molly'],
    ['id' => 'neon_tetra',         'label' => 'Neon Tetra',           'min_size' => 6,  'max_size' => 12, 'default_color' => '#3B82F6', 'sprite_key' => 'neon_tetra'],
    ['id' => 'zebra_danio',        'label' => 'Zebra Danio',          'min_size' => 8,  'max_size' => 14, 'default_color' => '#9CA3AF', 'sprite_key' => 'zebra_danio'],
    ['id' => 'platy',              'label' => 'Platy',                'min_size' => 10, 'max_size' => 18, 'default_color' => '#F59E0B', 'sprite_key' => 'platy'],
    ['id' => 'endler',             'label' => "Endler's Livebearer",  'min_size' => 5,  'max_size' => 10, 'default_color' => '#10B981', 'sprite_key' => 'endler'],
    ['id' => 'cherry_barb',        'label' => 'Cherry Barb',          'min_size' => 10, 'max_size' => 16, 'default_color' => '#DC2626', 'sprite_key' => 'cherry_barb'],
    ['id' => 'white_cloud_minnow', 'label' => 'White Cloud Minnow',   'min_size' => 7,  'max_size' => 13, 'default_color' => '#E5E7EB', 'sprite_key' => 'white_cloud_minnow'],
    ['id' => 'otocinclus',         'label' => 'Otocinclus',           'min_size' => 6,  'max_size' => 10, 'default_color' => '#6B7280', 'sprite_key' => 'otocinclus',   'vertical_band_preference' => 'bottom'],
    ['id' => 'cory_catfish',       'label' => 'Cory Catfish',         'min_size' => 12, 'max_size' => 20, 'default_color' => '#78716C', 'sprite_key' => 'cory_catfish', 'vertical_band_preference' => 'bottom'],
];
