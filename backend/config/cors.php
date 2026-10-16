<?php

/*
| CORS: mengizinkan frontend (folder /frontend, origin berbeda) memanggil API ini.
| Isi FRONTEND_URL di .env dengan alamat frontend, pisahkan dengan koma bila lebih dari satu.
*/

$origins = array_values(array_filter(array_map('trim', explode(',', (string) env(
    'FRONTEND_URL',
    'http://localhost:5500,http://127.0.0.1:5500,http://localhost:3000,http://127.0.0.1:3000,http://localhost:5173,http://localhost:8080,http://127.0.0.1:8080'
)))));

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Content-Disposition', 'X-Server-Time'],
    'max_age' => 0,
    // Token Bearer dipakai, bukan cookie, jadi credentials tidak diperlukan.
    'supports_credentials' => false,
];
