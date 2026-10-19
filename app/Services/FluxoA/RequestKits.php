<?php

namespace App\Services\FluxoA;

use App\Exceptions\DomainException;
use App\Models\Project;
use App\Models\Redemption;
use App\Models\User;
use App\Support\Values\Cpf;
use Illuminate\Support\Facades\DB;

/**
 * Solicitação de kits pelo admin (inclusão UAT).
 *
 * O Admin Cliente/Admin Projeto não resgata como Usuário do portal — ele
 * SOLICITA: N unidades de um item do pedido, com destinatário e endereço, sem
 * o limite de "um por pessoa". A solicitação vira UMA Redemption com
 * `quantity` e `requested_by` preenchidos, e entra na mesma esteira de
 * expedição dos resgates individuais.
 *
 * Quem pode: só a equipe WeGift crítica, operando em nome do cliente quando o
 * pedido chega por telefone ou e-mail. O Admin Cliente e o Admin Projeto já
 * puderam solicitar direto; a operação decidiu deixar a solicitação no
 * controle da WeGift.
 *
 * Limite de quantidade: o saldo DESTE Projeto, debitado na mesma transação e
 * com a linha travada. O limite já foi o saldo espelhado da Omie, que é do
 * produto inteiro: dois projetos com o mesmo kit liam a soma dos dois e cada
 * um se achava no direito de solicitar tudo.
 */
class RequestKits
{
    public function __construct(private readonly ProjectStockLedger $ledger) {}

    /**
     * Quando a solicitação pode sair sem CPF: sempre para a WeGift, e para os
     * admins do Cliente quando ele não coleta CPF (Customizações). Mora aqui
     * para o controller e o serviço não discordarem.
     */
    public static function cpfOpcional(Project $project, User $actor): bool
    {
        return $actor->canPerformCriticalActions() || ! ($project->company?->pedeCpf() ?? true);
    }

    /**
     * @param  array{product_id: int, quantity: int, recipient_name: string, cpf?: ?string,
     *               zip: string, street: string, number: string, complement?: ?string,
     *               district: string, city: string, state: string, notes?: ?string}  $data
     */
    public function handle(Project $project, array $data, User $actor): Redemption
    {
        if (! $actor->canPerformCriticalActions()) {
            throw new DomainException('Sem permissão para solicitar kits neste Projeto.');
        }

        // Mesma dupla trava do resgate individual: liberado E com kit físico.
        if (! $project->isReleased()) {
            throw new DomainException('Este Projeto ainda não foi liberado para resgate.');
        }

        if (! $project->isInStock()) {
            throw new DomainException(
                'Este kit saiu do estoque e a solicitação está suspensa. Fale com a WeGift.',
            );
        }

        // Freio de mão: vale para a solicitação do admin tanto quanto para o
        // resgate do Usuário — bloquear tem de parar a fila inteira.
        if ($project->isRedemptionBlocked()) {
            throw new DomainException(
                'Os resgates deste Projeto estão bloqueados. Só o Admin WeGift desbloqueia.',
            );
        }

        // Só itens do pedido — mesma regra das autorizações de resgate.
        if (! $project->products()->where('products.id', $data['product_id'])->exists()) {
            throw new DomainException('O item selecionado não faz parte deste Projeto. Só itens do pedido podem ser solicitados.');
        }

        /*
         * CPF em branco só para a WeGift.
         *
         * Ela abre a solicitação com o pedido que chegou por telefone ou
         * e-mail, e o CPF costuma vir depois, junto da nota. Exigir na hora
         * fazia a operação inventar um número só para o formulário passar — e
         * um CPF inventado vai para a NF. Em branco é uma pendência visível na
         * lista de Resgates, que é onde ela se completa.
         *
         * O Admin Cliente e o Admin de Projeto têm o dado do próprio
         * colaborador na mão — a não ser que o Cliente não colete CPF.
         */
        $cpfInformado = trim((string) ($data['cpf'] ?? ''));

        $cpf = $cpfInformado === '' && self::cpfOpcional($project, $actor)
            ? null
            : Cpf::tentar($cpfInformado) ?? throw new DomainException(Cpf::mensagemDeErro());

        $quantity = (int) $data['quantity'];

        $redemption = DB::transaction(function () use ($project, $data, $actor, $cpf, $quantity) {
            /*
             * O teto é o saldo DESTE Projeto, não o do produto na Omie.
             *
             * O número da Omie é do produto inteiro, somado entre os projetos
             * que usam o mesmo kit: dois projetos com 100 unidades cada liam
             * 200 e cada um se achava no direito de solicitar tudo. O débito
             * trava a linha do pivô e falha alto quando não há saldo.
             */
            $this->ledger->debitar($project, (int) $data['product_id'], $quantity);

            return Redemption::create([
                'project_id' => $project->id,
                // A linha pertence a quem solicitou: não existe Usuário do
                // portal numa solicitação em massa. `requested_by` repetido é
                // o marcador de "isto é solicitação, não resgate".
                'user_id' => $actor->id,
                'requested_by' => $actor->id,
                'product_id' => (int) $data['product_id'],
                'quantity' => $quantity,
                'status' => Redemption::STATUS_CONFIRMED,
                'recipient_name' => $data['recipient_name'],
                'cpf' => $cpf?->digitos,
                'zip' => preg_replace('/\D/', '', $data['zip']),
                'street' => $data['street'],
                'number' => $data['number'],
                'complement' => $data['complement'] ?? null,
                'district' => $data['district'],
                'city' => $data['city'],
                'state' => $data['state'],
                'notes' => $data['notes'] ?? null,
            ]);
        });

        // A Omie não recebe a baixa: o saldo debitado acima é o do Projeto, na
        // WeLog, e é ele que a operação acompanha.

        return $redemption;
    }
}
