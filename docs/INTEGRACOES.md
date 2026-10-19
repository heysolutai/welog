# Configuração das Integrações — WeLog

Cada integração tem um **driver**: `fake` (fixtures locais, sem rede — padrão em
dev/testes) ou `api` (chamadas reais). Troque em `config/integrations.php` via
variáveis de ambiente conforme as credenciais forem liberadas. Toda a aplicação
depende apenas dos **contratos** em `app/Contracts/Integrations`, então virar a
chave não exige mudança de código.

Resiliência: os drivers `api` aplicam timeout, retry com backoff exponencial em
erros transitórios (timeout/conexão/5xx/429) e log estruturado no canal
`integrations` (`storage/logs/integrations-*.log`).

## Omie (ERP — fonte da verdade)
```
OMIE_DRIVER=api
OMIE_APP_KEY=...
OMIE_APP_SECRET=...
OMIE_WEBHOOK_TOKEN=<token forte e único>
```
- **Webhook (gatilho primário do Fluxo A):** cadastre no painel da Omie a URL
  `https://<domínio>/webhooks/omie/<OMIE_WEBHOOK_TOKEN>` para os eventos de
  Pedido de Venda e movimentação de estoque.
- Reconciliação periódica (`ReconcileOmieOrders`) cobre webhooks perdidos.

## Trello (pipeline operacional)
```
TRELLO_DRIVER=api
TRELLO_API_KEY=...
TRELLO_API_TOKEN=...
TRELLO_API_SECRET=...           # verificação HMAC-SHA1 dos webhooks
TRELLO_BOARD_ID=...
TRELLO_WEBHOOK_CALLBACK_URL=https://<domínio>/webhooks/trello
```
- O mapeamento lista do Trello → status do pipeline é mantido na tabela
  `trello_list_mappings` (semeada em `DatabaseSeeder`; ajuste conforme o board real).
- O registro do webhook do board é feito uma vez via `TrelloGateway::registerWebhook()`
  (ex.: por tinker) apontando para `TRELLO_WEBHOOK_CALLBACK_URL`.

## Correios (Fluxo A)
```
CORREIOS_DRIVER=api
CORREIOS_USUARIO=...
CORREIOS_CODIGO_ACESSO=...
CORREIOS_CONTRATO=...
CORREIOS_PREPOSTAGEM_ENABLED=true   # se o contrato permitir prepostagem PPN
```
- Sem prepostagem, o código de rastreio é inserido manualmente (mesmo caminho de MRB/DDM).

## Suvinil — webhook de resgates (Importações Parceiros)

Segunda porta de entrada do mesmo material que hoje chega por diretório. O
relatório é varrido uma vez por dia; o webhook chega no instante do resgate.
As duas portas terminam no mesmo `IngestPartnerRows`, de propósito: uma linha
recusada por uma tem de ser recusada pela outra, pelo mesmo motivo.

```
SUVINIL_WEBHOOK_TOKEN=            # vazio = webhook desligado, a rota recusa tudo
SUVINIL_WEBHOOK_MAX_ROWS=200      # teto por chamada
```

Gere o token com `php artisan tinker --execute="echo bin2hex(random_bytes(24));"`.

### URL entregue à Suvinil

```
POST https://SEU-DOMINIO/webhooks/suvinil/{token}
```

O token vai **na URL**, como no Omie, porque a Suvinil não assina o corpo. Ele
é a credencial inteira: quem tem a URL, entra. Trate-a como senha, e nunca a
deixe cair em e-mail, ticket ou print.

`GET`/`HEAD` na mesma URL responde `{"status":"ok"}` — é o teste que o parceiro
faz antes de ligar o disparo.

### Corpo

```json
{
  "lote": "SUV-2026-09-24-001",
  "resgates": [
    {
      "ID Solicitação": "125635",
      "ID Produto": "SUV-PINCEL-01",
      "Nome do Produto": "Kit Pincéis",
      "Usuário solicitante": "Fulano de Tal",
      "CPF": "314.159.265-90",
      "Telefone": "(45)99999-1234",
      "Email": "cliente@example.com",
      "Rua": "Rua Exemplo",
      "Número": "43",
      "Complemento": "casa 2",
      "Bairro": "Morumbi",
      "CEP": "80000-000",
      "Cidade": "Cascavel",
      "Estado": "Paraná"
    }
  ]
}
```

Os nomes são **os mesmos do relatório que a appnoz exporta**, para a Suvinil não
ter de manter duas traduções do mesmo dado. Acentos, maiúsculas e espaços não
importam: `ID Solicitação`, `id_solicitacao` e `ID SOLICITAÇÃO` são a mesma
coisa.

| Campo (appnoz) | Obrigatório | Vira |
|---|---|---|
| `ID Solicitação` | sim | trava contra duplicata |
| `ID Produto` | sim | código do produto na Omie |
| `Nome do Produto` | sim | descrição |
| `Usuário solicitante` | sim | nome do destinatário |
| `CPF` | sim | com ou sem pontuação |
| `Telefone` | sim | — |
| `Email` | sim | — |
| `Rua`, `Número`, `Bairro`, `CEP`, `Cidade`, `Estado` | sim | endereço de entrega |
| `Complemento` | não | complemento do endereço |
| `Valor do Produto`, `Status`, `Atividade`, `Data Nascimento`, `Tags` | não | ignorados hoje |

O template próprio (`cpf`, `nome`, `endereco`, `uf`, `quantidade`…) continua
valendo: as duas grafias convivem, e a tradução está num lugar só
(`PartnerRowVocabulary`).

Três armadilhas do export real, já tratadas:

- **`Estado` vem por extenso** ("Paraná"), e a Omie quer a sigla. Sem a
  conversão, toda linha era bloqueada por "UF deve ter 2 caracteres".
- **`Complemento` aparece duas vezes** no cabeçalho: a da atividade (quase
  sempre vazia) e a do endereço. Vale a do endereço.
- **Não existe coluna de quantidade**: uma solicitação, um brinde.

### `ID Solicitação` é a trava por resgate

Webhook de parceiro reenvia — por timeout, por retry automático, por engano
humano. E o relatório repete: o da manhã traz a solicitação ainda "solicitado",
o da tarde a traz de novo junto com as novas. Cada repetição não travada é
outro Pedido de Venda na Omie, ou seja, outro brinde despachado de verdade.

São duas travas, em camadas:

- **`lote`** — o mesmo lote reenviado responde `duplicate: true` e nada é
  reprocessado, mesmo que o conteúdo tenha mudado.
- **`ID Solicitação`** — vale entre lotes e entre portas. Resgate que já entrou
  por um relatório não entra de novo pelo webhook, e vice-versa. A linha
  repetida é descartada em silêncio: não é um problema que alguém precise
  resolver.

Sem `lote`, cai no hash do corpo, que só resolve o reenvio byte a byte idêntico.
Sem `ID Solicitação`, resta só a trava do lote.

### Respostas

| Situação | HTTP | Corpo |
|---|---|---|
| Aceito | 200 | `{"received":true,"lote":"...","resgates":N}` |
| Reenvio do mesmo lote | 200 | `{"received":true,"duplicate":true,"lote":"..."}` |
| Token errado ou não configurado | 403 | — |
| Corpo não reconhecido | 200 | `{"received":true,"resgates":0,"aviso":"...","campos_recebidos":[...]}` |
| Lote acima de `SUVINIL_WEBHOOK_MAX_ROWS` | 422 | `{"received":false,"error":"..."}` |

Um 200 significa **recebido**, não processado: cada linha cadastra cliente na
Omie e abre Pedido de Venda, o que leva segundos e não cabe na requisição. O
lote vira uma Importação Parceiros comum, com a mesma tela de acompanhamento e
os mesmos bloqueios do relatório. O evento cru fica em `/admin/webhooks`, com
reprocessamento manual.

### Corpo não reconhecido não é recusado

Qualquer corpo é aceito e gravado, reconhecido ou não — inclusive o que nem
JSON é, que fica em `corpoCru`. Enquanto o formato do parceiro não está
fechado, um 422 é o pior desfecho possível: eles mandam, levam erro, e deste
lado não fica nada para olhar. A conversa vira "mandamos" contra "não chegou".

Guardando tudo, o payload cru aparece em `/admin/webhooks` com status
`ignored` — explícito de que a decisão foi nossa e não uma falha — e o formato
se descobre olhando. A resposta ainda devolve `campos_recebidos` com os nomes
do topo, para quem mandou ver na hora o que chegou, sem depender de alguém
aqui abrir a tela e responder.

Quando o formato fechar, é só o mapeamento em `PartnerRowVocabulary` que muda.

## Flash (Fluxo B — Suvinil)
```
FLASH_DRIVER=api
FLASH_BASE_URL=https://bridgelink.com.br
FLASH_LOGIN=...
FLASH_SENHA=...
FLASH_KEY=                       # opcional: HMAC-SHA256(chave=login, msg=senha)
FLASH_CLIENTE_ID=...
FLASH_CTT_IDS=7185,7200          # contratos, separados por vírgula
FLASH_TRACKING_REFERENCE=nf_number  # WEBOX + nº da NF; ou nf_key / omie_order_number
FLASH_TRACKING_PREFIX=WEBOX
```

Com a NF emitida, o resgate vira **encomenda na Flash** pela importação da
Bridge Link (`POST /FlashPegasus/rest/padrao/importacao`, HTTP Basic, em lote).
O `num_enc_cli` é o mesmo número que o rastreio consulta (`WEBOX` + nº da NF),
e reenviar é seguro: número já importado é ignorado do lado de lá.

```
pedido criado ─(webhook NFe.NotaAutorizada / botão / SyncOmieInvoices)─▶ NF emitida
NF emitida ─(SendPendingFlashShipments: importação na Bridge Link)─▶ enviado à Flash
enviado ─(1º evento de transporte na Flash)─▶ Em transporte
Em transporte ─(evento 4300)─▶ Entregue
```

O envio sai quando a nota chega e, de reserva, a cada 10 minutos. A recusa fica
no resgate (`flash_error`), e a página dele tem o botão "Enviar à Flash". Nota
simulada nunca vai à Flash real.

```
FLASH_IMPORT_ENABLED=true          # false desliga o envio automático (o botão segue)
FLASH_IMPORT_USUARIO=              # vazio = FLASH_LOGIN
FLASH_IMPORT_SENHA=                # vazio = FLASH_SENHA
FLASH_IMPORT_CCUSTO_ID=            # só com mais de um centro de custo no contrato
FLASH_IMPORT_REMETENTE=WeGift
```

O `PollFlashTracking` roda a cada 2h; `php artisan welog:flash:rastrear` roda
na hora, mostrando antes e depois de cada pedido.

### Rastreio
API REST compatível com o Web Service da Flash Courier (FlashPegasus), servida
hoje pela **Bridge Link** (doc: "API de Rastreio Bridge Link", v2.0 de
14/07/2026). Durante a migração ela consulta a Flash e completa na Interlog,
numa resposta só.

- `POST /FlashPegasus/rest/api/v1/token` — header `Authorization` = Key; corpo
  `{login, senha}`; devolve JWT de 24h. Guardado em cache por 23h (o serviço
  aceita 10 chamadas/min por IP).
- `POST /FlashPegasus/rest/padrao/v2/consulta` — header `Authorization` = o
  token **sem `Bearer`**; corpo `{clienteId, cttId: [..], numEncCli: [..]}`.
  201 com `hawbs`, 204 se nada localizado, 401 se o token venceu (renovado
  sozinho uma vez). Consultas em lotes de 50 números (limite: 120/min).
- Eventos (`eventoId`) → `TrackingStatus::fromFlashEvento`: 1400 postado, 2000
  em trânsito, 4100 saiu para entrega, **4300 entregue (o único que fecha o
  pedido)**; o resto, inclusive 1100 "aguardando postagem", é evento genérico.
- `numEncCli` segue `FLASH_TRACKING_REFERENCE` e precisa ser o formato
  parametrizado na Bridge Link. O combinado é `FLASH_TRACKING_PREFIX` + número
  da NF sem os zeros à esquerda (ex.: `WEBOX44883`); o número sai da chave da
  NF (posições 26 a 34), sem consulta extra à Omie.

Testar com as credenciais reais, sem gravar nada:
```bash
php artisan welog:flash:testar                 # só autentica
php artisan welog:flash:testar <numero> [...]  # autentica e consulta
```

### Teste do fluxo em homologação (sem nota oficial)
Com `WELOG_SIMULATED_INVOICES=true` e `FLASH_DRIVER=fake`:

1. Pedido: POST no webhook da Suvinil (`/webhooks/suvinil/{token}`) → pedido
   criado na Omie.
2. Nota: `php artisan welog:nf:simular --pedido=<número>` → NF emitida.
3. Rastreio: `php artisan welog:flash:rastrear` três vezes → em transporte →
   em trânsito → entregue.

A Flash real não conhece a nota simulada: com `FLASH_DRIVER=api` o pedido
simplesmente fica em "NF emitida" (consulta só de leitura, nada quebra).
