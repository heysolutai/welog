<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Transportadora cadastrada — a tabela de parametrização da expedição.
 *
 * Cada uma diz como entrega o rastreio: por CÓDIGO, que a WeLog transforma em
 * link pelo molde, ou por LINK, que já vem pronto e não tem código para
 * digitar. É essa escolha que o drawer de expedição lê para saber qual campo
 * pedir ao operador.
 */
class Carrier extends Model
{
    /** O operador digita o código de rastreio. */
    public const FIELD_CODE = 'code';

    /** O operador cola o link de rastreio já pronto. */
    public const FIELD_LINK = 'link';

    public const FIELDS = [self::FIELD_CODE, self::FIELD_LINK];

    /**
     * Correios é a única com integração: a prepostagem gera o código sozinha e
     * o robô de rastreio consulta a API. O slug é reservado — o cadastro não
     * cria nem apaga esta.
     */
    public const SLUG_CORREIOS = 'correios';

    /** A lista que vai para o front em toda página (HandleInertiaRequests). */
    public const SHARED_CACHE_KEY = 'carriers:shared';

    protected $fillable = ['name', 'slug', 'tracking_field', 'tracking_url_template', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * Gravou, o cache cai — no próprio modelo, e não em cada controller que
     * escreve. Cadastrar a transportadora e ela não aparecer na expedição
     * pelos próximos cinco minutos seria um bug sem sintoma: a tela de
     * cadastro mostra tudo certo.
     */
    protected static function booted(): void
    {
        $limpar = fn () => Cache::forget(self::SHARED_CACHE_KEY);

        static::saved($limpar);
        static::deleted($limpar);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function usesLink(): bool
    {
        return $this->tracking_field === self::FIELD_LINK;
    }

    public function isCorreios(): bool
    {
        return $this->slug === self::SLUG_CORREIOS;
    }

    /**
     * O link de acompanhamento de um código, quando existe molde para ele.
     *
     * Transportadora de código sem molde devolve `null`: a tela mostra o
     * código como texto, e não um link que não leva a lugar nenhum.
     */
    public function trackingUrlFor(?string $code): ?string
    {
        if ($code === null || $code === '' || ! $this->tracking_url_template) {
            return null;
        }

        return str_replace('{codigo}', rawurlencode($code), $this->tracking_url_template);
    }

    /**
     * Slug a partir do nome, estável e sem colidir com o de outra.
     *
     * Nasce uma vez e não muda mais: é o que os resgates guardam, e reescrevê-lo
     * apagaria a transportadora do histórico de quem já recebeu.
     */
    public static function slugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'transportadora';
        $slug = $base;
        $n = 2;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
