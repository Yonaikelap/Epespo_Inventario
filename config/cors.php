<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

   'allowed_origins' => [
    'http://localhost:3000',
    'https://epespo-inventario-react-ldu4.vercel.app', // <--- tu frontend
],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Authorization'], // si quieres exponer el token

    'max_age' => 0,

    'supports_credentials' => false, // no necesitas cookies para JWT
];
