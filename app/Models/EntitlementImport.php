<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma importação de Colaboradores por planilha, do upload ao fim.
 *
 * Existe porque o cadastro saiu da requisição e foi para a fila: sem um
 * registro, ninguém saberia dizer quanto já entrou, quanto falta e quais
 * linhas foram recusadas. Antes isso vivia numa mensagem de sessão, que o
 * primeiro F5 apagava.
 */
class EntitlementImport extends Model
{
    /**
     * Mensagens guardadas em `errors`, para a tela. As linhas recusadas, todas
     * e com os dados, ficam em `failures`.
     */
    public const MAX_ERROS = 50;

    /**
     * Até aqui a planilha é cadastrada na própria requisição.
     *
     * Cada linha custa uns 60ms (o bcrypt do token de convite), então duzentas
     * levam uns doze segundos: folgado dentro do limite do nginx, e melhor do
     * que uma barra de progresso para um trabalho que já acabou. Acima disso,
     * fila — e é só essa que a tela acompanha.
     */
    public const LIMITE_SINCRONO = 200;

    protected $fillable = [
        'project_id', 'uploaded_by', 'filename', 'total',
        'processed', 'created', 'admins', 'ignored', 'failed', 'errors', 'finished_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function failures(): HasMany
    {
        return $this->hasMany(EntitlementImportFailure::class);
    }

    /**
     * As importações em fila que `$user` subiu e que ainda interessam: as que
     * correm e as que terminaram na última hora. É o que o aviso flutuante
     * acompanha, em qualquer tela.
     */
    public function scopeAcompanhadasPor(Builder $query, User $user): Builder
    {
        return $query
            ->where('uploaded_by', $user->id)
            ->queInteressam()
            ->latest('id');
    }

    /**
     * As importações da fila que a tela ainda mostra: as que correm e as que
     * terminaram INTEIRAS na última hora.
     *
     * Cancelada ou encerrada no meio não aparece: "Importação concluída · 0 de
     * 8.815" não diz nada a ninguém, e o resultado de uma planilha que foi
     * parada não é o resultado da planilha.
     */
    public function scopeQueInteressam(Builder $query): Builder
    {
        return $query
            ->where('total', '>', self::LIMITE_SINCRONO)
            ->whereNull('cancelled_at')
            ->where(fn ($q) => $q
                ->whereNull('finished_at')
                ->orWhere(fn ($q) => $q
                    ->where('finished_at', '>', now()->subHour())
                    ->whereColumn('processed', '>=', 'total')));
    }

    public function isRunning(): bool
    {
        return $this->finished_at === null;
    }

    /**
     * O que as telas mostram: a do Projeto, o aviso flutuante e a consulta de
     * acompanhamento. Um formato só, para os três contarem a mesma história.
     *
     * @return array<string, mixed>
     */
    public function resumo(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project_name' => $this->project?->name,
            'project_url' => route('projects.show', $this->project_id),
            'filename' => $this->filename,
            'total' => $this->total,
            'processed' => $this->processed,
            'created' => $this->created,
            'admins' => $this->admins,
            'ignored' => $this->ignored,
            'failed' => $this->failed,
            'percent' => $this->percent(),
            'running' => $this->isRunning(),
            'errors' => $this->errors ?? [],
            'failures_url' => $this->failed > 0
                ? route('projects.imports.failures', [$this->project_id, $this->id])
                : null,
        ];
    }

    /**
     * Quanto já passou, em porcentagem inteira.
     *
     * Planilha vazia devolve 100: zero de zero está feito, e 0% deixaria a
     * barra parada para sempre num trabalho que já acabou.
     */
    public function percent(): int
    {
        return $this->total > 0 ? (int) floor($this->processed / $this->total * 100) : 100;
    }
}
