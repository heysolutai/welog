<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Contato de suporte da WeGift
    |--------------------------------------------------------------------------
    |
    | Por onde o Usuário pede correção de dados (endereço errado, nome trocado)
    | depois do resgate. Aparece nos e-mails parametrizáveis (variável
    | {{ contato }}) e no portal (prop Inertia `support`).
    |
    | Ambos são opcionais: sem nenhum dos dois, os e-mails degradam para
    | "responda este e-mail" e o portal esconde o bloco de contato.
    | A formatação (máscara do WhatsApp, frase pronta) fica em
    | App\Support\SupportContact — consuma por lá, não daqui.
    |
    */

    'support' => [
        'email' => env('WELOG_SUPPORT_EMAIL'),
        'whatsapp' => env('WELOG_SUPPORT_WHATSAPP'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Teto de tentativas de login/senha por IP
    |--------------------------------------------------------------------------
    |
    | A trava contra força bruta é a de CINCO por minuto por conta, que não sai
    | daqui. Este é o teto de origem, e existe contra a varredura que tenta mil
    | e-mails diferentes — não contra o escritório do cliente.
    |
    | Ele precisa ser alto porque milhares de colaboradores da mesma empresa
    | saem por um punhado de IPs: com o teto baixo, o disparo de uma campanha
    | atende as primeiras dezenas e devolve 429 para o resto.
    |
    | Suba no dia do disparo se o volume por cliente for maior que o previsto;
    | é uma variável de ambiente justamente para não precisar de deploy.
    |
    */

    'auth_ip_limit' => (int) env('PORTAL_AUTH_IP_LIMIT', 600),

    /*
    |--------------------------------------------------------------------------
    | Trilha de auditoria
    |--------------------------------------------------------------------------
    |
    | Por quanto tempo o registro de "quem fez o quê" é guardado. Dois anos
    | cobrem a janela que os questionários de segurança dos clientes perguntam
    | ("nos últimos 24 meses"). Ver App\Models\AuditLog e docs/SEGURANCA.md.
    |
    | `access_review_idle_days`: conta com acesso privilegiado parada há mais
    | que isso aparece como "revisar" no welog:acessos:revisao.
    |
    */

    'audit' => [
        'retention_days' => (int) env('WELOG_AUDIT_RETENTION_DAYS', 730),
        'access_review_idle_days' => (int) env('WELOG_ACCESS_REVIEW_IDLE_DAYS', 90),
    ],

];
