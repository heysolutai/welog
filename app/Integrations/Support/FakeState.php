<?php

namespace App\Integrations\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Estado persistido dos gateways fake (storage/app/private/fake/*.json).
 * Permite que o "mundo externo" simulado sobreviva entre requests e seja
 * inspecionado/zerado facilmente em dev (apague o arquivo para resetar).
 */
class FakeState
{
    public function __construct(private readonly string $name) {}

    private function path(): string
    {
        return "fake/{$this->name}.json";
    }

    public function get(): array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($this->path())) {
            return [];
        }

        return json_decode($disk->get($this->path()), true) ?: [];
    }

    public function put(array $state): void
    {
        Storage::disk('local')->put(
            $this->path(),
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
    }

    /** Lê, transforma e grava o estado numa operação. */
    public function update(callable $callback): mixed
    {
        $state = $this->get();
        $result = $callback($state);
        $this->put($state);

        return $result;
    }
}
