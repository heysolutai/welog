<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Grava a trilha de auditoria — "quem fez o quê, quando e de onde".
 *
 *   Auditoria::registrar(AuditLog::PROJECT_RELEASED, "Liberou o projeto {$p->name}", $p);
 *
 * O ator é o usuário logado (ou o informado, nos eventos de login). IP e
 * navegador vêm da requisição; em comando de console ficam vazios e o ator
 * aparece como "sistema".
 *
 * Falhar ao auditar NUNCA derruba a ação: a pessoa que liberou o projeto não
 * pode ver erro porque a gravação do registro falhou. A falha vai para o log
 * (e daí para o Sentry), que é onde alguém olha.
 */
class Auditoria
{
    /**
     * @param  array<string, mixed>  $dados  contexto extra — nunca senha nem token
     */
    public static function registrar(
        string $acao,
        string $descricao,
        ?Model $alvo = null,
        array $dados = [],
        ?User $ator = null,
    ): void {
        try {
            $ator ??= auth()->user();
            // Em comando de console não há visitante: o request existe, mas
            // sem REMOTE_ADDR, e IP/navegador ficam vazios sozinhos.
            $request = request();

            AuditLog::create([
                'user_id' => $ator?->id,
                'user_name' => $ator?->name,
                'user_email' => $ator?->email,
                'user_role' => $ator?->role,
                'action' => $acao,
                'description' => Str::limit($descricao, 497),
                'subject_type' => $alvo ? class_basename($alvo) : null,
                'subject_id' => $alvo?->getKey(),
                'properties' => $dados ?: null,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent() ? Str::limit($request->userAgent(), 252) : null,
            ]);
        } catch (Throwable $e) {
            Log::error('Falha ao gravar registro de auditoria.', [
                'acao' => $acao,
                'descricao' => $descricao,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
