<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'WeLog') }}</title>

    {{-- A fonte entra por <link>, não por @import no CSS: o @import só é
         descoberto depois que app.css baixa e é lido, encadeando
         HTML → CSS → googleapis → gstatic antes do primeiro texto. Com o link
         aqui, a busca começa junto com a do CSS; o preconnect abre TLS com os
         dois domínios em paralelo. `wght@400..700` traz o arquivo variável
         (um só) no lugar de quatro instâncias estáticas. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400..700&display=swap">

    @routes
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
{{-- Cor vem dos tokens (canvas/ink). `bg-slate-50 text-slate-900` era o par
     pré-redesign e, por ser utilitário, vencia a regra de `@layer base`: o
     token de fundo da aplicação nunca chegava a valer. --}}
<body class="h-full bg-canvas text-ink antialiased">
    @inertia
</body>
</html>
