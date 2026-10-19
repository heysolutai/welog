<?php

return [

    'pages' => [

        /*
        |----------------------------------------------------------------------
        | Onde ficam os componentes de página
        |----------------------------------------------------------------------
        |
        | O padrão do inertia-laravel é `js/pages` (minúsculo), mas este projeto
        | usa `js/Pages` — o mesmo caminho do glob em resources/js/app.js.
        |
        | Precisa estar explícito: em filesystem case-insensitive (bind mount do
        | Docker no Windows) o padrão "funcionava" por acidente, e quebrava em
        | Linux — ou seja, passava no dev e falhava no CI e no servidor.
        |
        */

        'paths' => [
            resource_path('js/Pages'),
        ],

        'extensions' => [
            'vue',
        ],

    ],

];
