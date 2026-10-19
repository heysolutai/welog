<?php

namespace App\Services\Branding;

use App\Models\Company;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Logo do Cliente ou da campanha, enviada pelo Admin WeGift em Customizações.
 *
 * Os dois guardam a imagem do mesmo jeito (`logo_path` no disco público), por
 * isso um serviço só. Mesmo desenho da galeria de produto: grava primeiro,
 * apaga a anterior depois. Se o disco falhar no meio, fica a logo antiga em
 * vez de nenhuma.
 */
class StoreLogo
{
    private const DISK = 'public';

    private const DIRECTORY = 'logos';

    public function handle(Company|Project $dono, UploadedFile $file): Company|Project
    {
        $anterior = $dono->logo_path;

        $path = $file->store(self::DIRECTORY, self::DISK);

        $dono->update(['logo_path' => $path]);

        if ($anterior && $anterior !== $path) {
            Storage::disk(self::DISK)->delete($anterior);
        }

        return $dono->refresh();
    }

    public function remove(Company|Project $dono): Company|Project
    {
        if ($dono->logo_path) {
            Storage::disk(self::DISK)->delete($dono->logo_path);
            $dono->update(['logo_path' => null]);
        }

        return $dono->refresh();
    }

    /**
     * As regras de upload, iguais para os dois: a logo é exibida pequena, e
     * um arquivo de 4 MB ali é peso gasto à toa. `dimensions` barra o SVG,
     * que aceita script embutido.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public static function regras(): array
    {
        return [
            ['logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=64,min_height=64']],
            [
                'logo.max' => 'A logo deve ter no máximo 2 MB.',
                'logo.dimensions' => 'A logo deve ter pelo menos 64×64 pixels.',
                'logo.mimes' => 'Use JPG, PNG ou WEBP.',
            ],
        ];
    }
}
