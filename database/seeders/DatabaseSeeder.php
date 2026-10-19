<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use App\Models\FiscalBenefit;
use App\Models\Project;
use App\Models\TrelloListMapping;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Usuários WeGift iniciais (trocar senhas no primeiro acesso em produção)
        User::updateOrCreate(
            ['email' => 'admin@welog.local'],
            ['name' => 'Admin WeGift', 'password' => 'password', 'role' => User::ROLE_ADMIN_WEGIFT, 'password_set_at' => now()],
        );
        User::updateOrCreate(
            ['email' => 'operador@welog.local'],
            ['name' => 'Operador WeGift N1', 'password' => 'password', 'role' => User::ROLE_WEGIFT_N1, 'password_set_at' => now()],
        );
        User::updateOrCreate(
            ['email' => 'consulta@welog.local'],
            ['name' => 'Operador WeGift N2', 'password' => 'password', 'role' => User::ROLE_WEGIFT_N2, 'password_set_at' => now()],
        );

        // Mapeamento lista Trello → etapa do pipeline (board fake de dev).
        // São TRÊS movimentações no quadro: Design, Produção e Montagem. O que
        // vem depois não é card arrastado — "Em Estoque" nasce do sinal da
        // Omie, "Envio" da expedição e "Concluído" do fim das entregas.
        $mappings = [
            ['list-design', 'Design', Project::STATUS_DESIGN],
            ['list-production', 'Produção', Project::STATUS_PRODUCTION],
            ['list-assembly', 'Montagem', Project::STATUS_ASSEMBLY],
        ];

        foreach ($mappings as [$listId, $listName, $status]) {
            TrelloListMapping::updateOrCreate(
                ['trello_list_id' => $listId],
                ['trello_list_name' => $listName, 'pipeline_status' => $status],
            );
        }

        // Benefícios fiscais de exemplo para a linha Suvinil (Fluxo B)
        foreach (['SUV-PINCEL-01', 'SUV-ROLO-23', 'SUV-BANDEJA-01'] as $code) {
            FiscalBenefit::updateOrCreate(
                ['product_code' => $code],
                ['benefit_code' => 'PR830015', 'description' => 'Benefício fiscal PR: brindes do programa Pintou Parceria'],
            );
        }

        // Modelos de e-mail parametrizáveis (Módulo 11) — a partir das definições.
        // Só CRIA o que falta: assunto e corpo de um registro existente são do
        // Admin WeGift (editáveis na tela), e rodar o seed de novo (deploy,
        // template novo) não pode desfazer a personalização. Era updateOrCreate
        // e sobrescrevia tudo. Padrão novo chega a um modelo já existente pelo
        // botão "Restaurar padrão" da tela de modelos.
        foreach (EmailTemplate::definitions() as $key => $def) {
            // Só o nível PADRÃO (sem empresa e sem Projeto). Buscar pela chave
            // sozinha encontraria a personalização de um cliente e concluiria
            // que o padrão já existe — deixando sem texto base justamente o
            // nível que serve de piso para todos os outros.
            EmailTemplate::firstOrCreate(
                ['key' => $key, 'company_id' => null, 'project_id' => null],
                ['name' => $def['name'], 'subject' => $def['subject'], 'title' => $def['title'] ?? null, 'body' => $def['body']],
            );

            // O nome do modelo não é editável na tela: mantém sincronizado, e
            // nos três alcances, porque a tela do cliente lê o nome do padrão.
            EmailTemplate::where('key', $key)->where('name', '!=', $def['name'])->update(['name' => $def['name']]);
        }

        $this->command?->info('Seed concluído. Login: admin@welog.local / password');
    }
}
