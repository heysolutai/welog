<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sem acesso · {{ config('app.name', 'WeLog') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="h-full bg-canvas text-ink antialiased">
    {{--
        Esta página precisa distinguir dois visitantes muito diferentes.

        Para quem JÁ ESTÁ LOGADO, o botão tem de levar à área DELE. Antes ele
        apontava para o login, que redirecionava de volta para cá — um beco sem
        saída, e o texto ainda falava de "abrir o seu presente" para um Admin
        Cliente, que não tem presente nenhum.
    --}}
    @php
        $usuario = auth()->user();
        $destino = $usuario ? route($usuario->homeRouteName()) : route('login');
    @endphp

    <main class="bg-mesh flex min-h-screen items-center justify-center px-4 py-10">
        <div class="w-full max-w-md">
            <div class="mb-8 text-center">
                <p class="mb-4 text-xl font-bold tracking-tight text-ink">
                    We<span class="text-brand-600">Log</span>
                </p>
                <h1 class="text-title font-bold text-ink">
                    Esta página é de outra área do sistema.
                </h1>
                <p class="mt-2 text-sm text-ink-muted">
                    @if ($usuario)
                        Seu acesso continua válido. O endereço é que não pertence ao seu perfil.
                    @else
                        Entre para continuar.
                    @endif
                </p>
            </div>

            <div class="rounded-lg border border-line bg-surface p-6 sm:p-8">
                <p class="text-sm text-ink">
                    @if ($usuario)
                        Você está conectado como <strong>{{ $usuario->name }}</strong>. Volte para a sua área
                        de trabalho pelo botão abaixo.
                    @else
                        Entre com o e-mail que recebeu o convite.
                    @endif
                </p>

                <a
                    href="{{ $destino }}"
                    class="mt-6 flex h-control-lg w-full items-center justify-center rounded-md bg-brand-600 px-5 text-base font-medium text-ink-inverse hover:bg-brand-700"
                >
                    {{ $usuario ? 'Ir para a minha área' : 'Entrar' }}
                </a>

                @if ($usuario)
                    <form method="POST" action="{{ route('logout') }}" class="mt-3">
                        @csrf
                        <button
                            type="submit"
                            class="flex h-control-md w-full items-center justify-center rounded-md border border-line px-4 text-sm font-medium text-ink-muted hover:border-line-strong hover:text-ink"
                        >
                            Sair e entrar com outra conta
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </main>
</body>
</html>
