# Revisão de UI/UX — WeLog (31/07/2026)

Revisão em 6 dimensões por agentes especializados, com **verificação adversarial**:
cada achado foi contestado por um segundo agente lendo o código, e só entrou aqui
o que sobreviveu. **51 achados confirmados**
(3 alta · 17 média · 31 baixa).

| Dimensão | Confirmados |
|---|---|
| Design system e consistência | 12 |
| Acessibilidade (WCAG 2.2 AA) | 7 |
| Portal do cliente final (mobile-first) | 6 |
| Formulários, validação e feedback | 7 |
| Telas operacionais densas | 11 |
| Autenticação e primeiro acesso | 8 |

---

# Revisão de UI/UX — WeLog (síntese para priorização)

## 1. Veredito geral

A interface está acima da média: o design system tem tokens nomeados por papel com contraste anotado (`resources/css/app.css:28-36`), um único motor de sobreposição para modal/drawer/menu (`resources/js/Components/UiModal.vue:19-150`), acessibilidade de formulário centralizada no `UiField` e zero jargão interno chegando ao cliente final. Os defeitos não estão na estética nem na estrutura — estão em **caminhos que recusam avançar sem dizer o motivo** e em **telas de exceção que não conseguem mostrar a exceção**. Dois problemas têm consequência fora do sistema: um loop de 403 que fecha a porta do cliente final recém-autenticado e um endereço desatualizado que passa pela conferência final do resgate.

## 2. Temas

**T1 — O sistema para e não diz por quê.** Padrão repetido: a condição de bloqueio é correta, mas o texto na tela não corresponde a ela. CEP com 7 dígitos deixa o botão morto com a frase "Informe o CEP e o número" (`Portal/Redeem.vue:68,299,302`); `advance()` abre o bloco manual sem foco, rolagem ou mensagem no caso "CEP de cidade inteira" (`Portal/Redeem.vue:123-127`); Salvar não produz efeito algum na aba Prévia do mobile (`Admin/EmailTemplates/Index.vue:359`); "Enviar de novo" não envia nos primeiros 60s e a tela não muda um pixel (`Auth/ForgotPassword.vue:40` + `PasswordController.php:35-38`); a página 403 oferece um botão que devolve o mesmo 403 (`errors/403.blade.php:23-28`).

**T2 — Telas de exceção sem o filtro que as justifica.** `Imports/Show` filtra bloqueadas só dentro da página de 50 (`Imports/Show.vue:56,70-80` × `AppnozImportController.php:42`), `Projects/Index` e `Redemptions/Index` não têm busca nem filtro nenhum (`ProjectController.php:23-27`, `RedemptionController.php:19-20`), e as listas paginadas descartam `total`/`per_page` (`ProjectController.php:30-42` e irmãos), então nem dá para conferir o número que o Dashboard acabou de anunciar. O Dashboard, por sua vez, nomeia os projetos errados (`Dashboard.vue:35`) e atribui um motivo de bloqueio que vale para parte das linhas contadas (`Dashboard.vue:70`).

**T3 — Dado digitado que se perde ou fica velho.** Corrigir o CEP com o bloco manual aberto mantém cidade/UF do endereço anterior até a tela de conferência (`Portal/Redeem.vue:86-96` + `lib/cep.js:99-105`); Escape ou clique fora apaga 14 campos do cadastro de empresa sem perguntar (`UiDrawer.vue:22-26`); toda recusa de senha limpa os dois campos (`Auth/SetPassword.vue:34`).

**T4 — Acessibilidade: percepção e anúncio.** A borda de todo campo tem 1,49:1 contra o fundo, falhando SC 1.4.11 (`app.css:32`); metade das tabelas perdeu o `th scope="row"` (`Dashboard.vue:168`, `Imports/Index.vue:92`, `Products/Index.vue:163`, `Users/Index.vue:256`); mudanças assíncronas não são anunciadas (busca de CEP em `CompanyForm.vue:159`, polling em `Imports/Index.vue:68`); e há ruído de anúncio (`aria-atomic` no `Toaster.vue:120`, `aria-current="page"` em aba no `UiTabs.vue:74`).

**T5 — Design system: receitas duplicadas e um token faltando.** O alvo de toque de 44px do portal é escrito de memória em 7 arquivos porque não existe `--spacing-control-touch` (`app.css:82-84`); `UiSearchInput.vue:65` e `UiPagination.vue:126` reescrevem por dentro `UiInput`/`UiButton`; o eyebrow tem duas medidas de tracking em 15 usos; falta `UiRadio`. Nada disso é visível hoje — é risco de deriva.

## 3. Corrigir primeiro

1. **Loop de 403 para quem já está autenticado no portal** — `bootstrap/app.php:22` só define `redirectGuestsTo`; sem `redirectUsersTo`, o middleware `guest` manda todo mundo para `/dashboard`, que é `role:operator`. Reabrir o link do convite (ou digitar só o domínio, por causa de `routes/web.php:15`) devolve 403, e o único botão da página aponta para `login`, que reproduz o erro. Adicionar `redirectUsersTo` usando `User::homeRouteName()` (`app/Models/User.php:63-70`, já usado em `LoginController.php:41`) e, em `errors/403.blade.php:23-28`, apontar o botão para a home do usuário logado. Esforço baixo, é a correção de maior retorno da lista.

2. **Portal: endereço antigo sobrevive à correção do CEP** — `Portal/Redeem.vue:86-96` começa com `if (manual.value) return;` e `applyCepToForm` não sobrescreve campo preenchido (`lib/cep.js:99-105`). Quem erra o CEP, abre "Não é esse endereço?" e corrige, chega à conferência final (`Redeem.vue:320-322`) com CEP novo e cidade velha. Não há rota de correção depois. Limpar os campos cujo valor ainda é idêntico ao da consulta anterior, inclusive com `manual` ligado.

3. **Portal: três paradas sem explicação no formulário de endereço** — (a) CEP com 7 dígitos: `canAdvance` exige 8 (`Redeem.vue:68`), o `lookup` faz `reset()` e não deixa erro no campo (`useCepLookup.js:98-101`), e o texto do rodapé diz que o CEP está vazio (`Redeem.vue:302`); exibir "faltam N dígitos" no erro do campo. (b) Caminho mudo do `advance()` (`Redeem.vue:123-127`): quando o CEP é de cidade inteira, a caixa verde some e quatro campos aparecem sem nenhuma mensagem — mover o foco para "Rua ou avenida" e escrever o motivo. (c) Número obrigatório com `inputmode="numeric"` (`Redeem.vue:283-286`) torna "s/n" impossível no iOS, embora o backend aceite (`PortalController.php:77`) — acrescentar `hint` autorizando "0" ou um checkbox "meu endereço não tem número".

4. **Filtro server-side nas duas listas que existem para achar exceção** — `Imports/Show` promete "12 de 500 bloqueadas" no alerta e o chip logo abaixo conta 2, porque `filtered` opera sobre `props.rows.data` (`Imports/Show.vue:56,70-80`); pior, o filtro reinicia a cada página, já que a paginação remonta o componente (`UiPagination.vue:92`). Aceitar `?status=bloqueadas` em `AppnozImportController.php:42` e enviar contagens do lote. Mesmo movimento em `ProjectController.php:23-27` (`?q=`, `?estagio=`) — sem ele, projetos "prontos para liberar" são os mais antigos e caem nas últimas páginas de uma lista `latest()` de 20 em 20. O padrão pronto está em `ProductController.php:22-31` + `Products/Index.vue:36-47`. No caminho: fazer `Dashboard.vue:62` e `Dashboard.vue:72` apontarem para a lista já filtrada e o `PipelineChart.vue:22-45` virar `<Link>`.

5. **Telas de senha: três consertos nos mesmos dois arquivos** — (a) `onFinish` em `Auth/SetPassword.vue:34` (e `ResetPassword.vue:32`) limpa os dois campos também no 422: trocar por `onSuccess`. (b) `form.errors.password` está ligado nos dois campos (`SetPassword.vue:59` e `:83`; `ResetPassword.vue:55` e `:79`), então "senha curta" acusa também "Confirmar senha" e o `role="alert"` anuncia em dobro — exibir a mensagem uma vez só. (c) Com link expirado, o formulário continua montado e o botão primário full-width só pode falhar (`SetPassword.vue:44-94`): esconder o form com `v-if="!erroDeLink"` e pôr "Receber um link novo" no slot `#actions` do alerta — os dois padrões já existem em `ForgotPassword.vue:46` e `Login.vue:45-49`.

6. **Contraste da borda dos campos (SC 1.4.11)** — `--color-line-strong: #cfd4dc` (`app.css:32`) dá 1,49:1 sobre `surface` e 1,39:1 sobre `canvas`, e o hover `border-ink-subtle` dá 2,58:1; o mínimo é 3:1 e a borda é a única coisa que delimita o controle. Criar `--color-field-line: #7d8798` (3,64:1) e usá-lo em `UiInput.vue:68`, `UiSelect.vue:52`, `UiTextarea.vue:56`, `UiSearchInput.vue:65`, `UiFileDrop.vue:137`, com hover em `ink-muted`. Pesa mais no portal: celular, sol, uso único.

7. **Drawer descarta o que foi digitado sem perguntar** — `requestClose` só consulta `dismissible` (`UiDrawer.vue:22-26`); Escape ou clique no backdrop apagam 14 campos do `CompanyForm`, e reabrir faz `defaults()` + `reset()` (`Admin/Companies/Index.vue:107-126`). Adicionar prop `dirty` ao `UiDrawer` e reaproveitar o `confirmDiscard()` já pronto em `Admin/EmailTemplates/Index.vue:217-224`. Aplicar nos quatro drawers (`Companies/Index.vue:312`, `Users/Index.vue:286`, `Projects/Show.vue:608`, `ShipControl.vue:160`) — o caso que justifica é o CompanyForm.

8. **Salvar sem efeito no editor de e-mail em telas < lg** — a coluna do editor recebe `hidden` na aba Prévia (`Admin/EmailTemplates/Index.vue:359`) contendo dois campos `required` nativos; o Chrome cancela o envio, loga "invalid form control is not focusable" e não mostra bolha. Voltar para a aba Editar dentro de `save()` (`:238`) antes de submeter.

## 4. Pode esperar

**Vocabulário e microcópia:** `confirmed: 'Confirmado'` (`lib/format.js:15`) conflita com "Aguardando expedição" na mesma linha da tabela (`ShipControl.vue:94`) — trocar exige revisar também `Projects/Show.vue:529,567` e `Redemptions/Index.vue:76`. Nome do projeto ausente no payload de Resgates (`RedemptionController.php:29`). "WeLog" não aparece no corpo de nenhuma tela de autenticação (`AuthCard.vue:12`).

**Contagem e navegação em listas:** totais de paginação descartados em três controllers (`ProjectController.php:30-42`, `RedemptionController.php:23-36`, `AppnozImportController.php:22-35`) — `Products/Index.vue:202-207` já faz certo. Menu "⋯" com um item só (`Projects/Show.vue:261`, `Redemptions/Index.vue:28`, `Companies/Index.vue:140`). `<details>` renderizado em todas as 50 linhas de `Imports/Show.vue:243`. Cabeçalho sticky de ~250px em `Projects/Show.vue:430` com `scroll-margin-top: 5rem` insuficiente (`app.css:112`).

**Anúncio para leitor de tela (ruído, não barreira):** `aria-atomic="true"` na região do Toaster (`Toaster.vue:120` — remoção de uma linha); `aria-current="page"` em `role="tab"` (`UiTabs.vue:74` — remoção de uma linha); busca de CEP sem `role="status"` no `CompanyForm.vue:159`; polling sem região viva em `Imports/Index.vue:68`; rótulo alternante junto de `aria-pressed` nos botões de ver senha (`Login.vue:78`, `SetPassword.vue:72`, `ResetPassword.vue:68`).

**Design system, dívida sem sintoma:** criar `--spacing-control-touch: 2.75rem` e substituir os 7 `min-h-11` do portal; padronizar `<th scope="row">` nas 5 tabelas que usam `<td>` + `font-medium` manual; unificar o eyebrow em `tracking-wider` (11 usos contra 4); extrair a string comum de `UiSearchInput.vue:65` e `UiPagination.vue:126`; `list-none` no `<summary>` de `CompanyForm.vue:231`; marcador visível (não só cor) no rádio de `ShipControl.vue:176`; `data-autofocus` no primeiro campo dos drawers; corrigir o comentário de `app.css:32/35` sobre `ink-subtle` em borda de hover; anel de foco das abas recortado pelo `overflow-x-auto` (`UiTabs.vue:64`); toast cobrindo a barra inferior do portal (`Toaster.vue:118` × `PortalLayout.vue:97`); `UiCode` fixo em 12px no rastreio do portal (`UiCode.vue:42`); hex cru em `UiSelect.vue:72` e `app.js:36`.

## 5. Não regredir

- **Tokens nomeados por papel com contraste e uso proibido anotados** (`app.css:28-36`), e a disciplina do `ink-subtle` cumprida em todas as 14 ocorrências, sempre com par `sr-only`.
- **Um único motor de sobreposição** para modal, drawer e menu (`UiModal.vue:19-150`): foco preso, devolução ao gatilho, trava de rolagem com contagem, `[data-autofocus]`.
- **`UiField` distribui acessibilidade por provide/inject** (`UiField.vue:40-47`) — nenhuma página repete `aria-describedby`, `aria-invalid` ou `required`.
- **`useConfirm` com foco inicial no Cancelar** e rótulo que diz a ação concreta ("Liberar e notificar 128 pessoas"), mantendo o diálogo aberto quando o backend recusa (`UiConfirm.vue:58`, `useConfirm.js:85-112`).
- **Foco visível global sem exceção** (`app.css:103-107`) — zero `outline:none` em todo o `resources/js`.
- **`prefers-reduced-motion` coberto no global e reforçado ponto a ponto** (`app.css:181-186`, `PipelineChart.vue:32`, `UiFileDrop.vue:167`).
- **Portal separa "CEP não encontrado" de "serviço fora do ar"** (`useCepLookup.js:16-19`) e a confirmação em dois passos mostra presente, destinatário e endereço antes da ação irreversível (`Redeem.vue:308-338`).
- **Ação desabilitada sempre traz o motivo escrito ao lado**, e célula de ação nunca fica vazia por permissão — o N2 lê o estado em vez de ver um branco (`ShipControl.vue:90-102`, `Imports/Show.vue:106-112`).
- **Componentes que validam o próprio contrato em DEV** (`UiTable.vue:102-114`, `UiButton.vue:39-41`) e `StatusBadge` traduzindo status por contexto, sem exceção em 19 páginas.

---

# Achados detalhados

## Severidade alta

### 1. Importações/Show: o filtro "Só bloqueadas" só enxerga as 50 linhas da página atual

**Arquivo:** `resources/js/Pages/Imports/Show.vue:70` · **Dimensão:** Telas operacionais densas

**Problema.** O alerta no topo (linha 142) diz o total do LOTE: "12 de 500 linhas bloqueadas". Mas `blockedOnPage` (linha 56), o contador do chip (linha 65) e o `filtered` (linhas 70-80) operam sobre `props.rows.data`, que é uma página de 50 linhas vinda de `$import->rows()->orderBy('row_number')->paginate(50)` (AppnozImportController.php:42) sem nenhum filtro de status. Não existe rota para "me mostre as linhas bloqueadas deste lote". O operador lê 12, ativa o chip, vê 2, e não tem como chegar às outras 10 a não ser abrindo as 10 páginas uma a uma e reativando o filtro em cada. Pior: quando a página aberta não tem bloqueio nenhum, `filter` inicializa em 'all' (linha 60) e o modo muda sozinho sem aviso.

**Impacto.** Destravar linha é, pelo comentário do próprio código (linha 59), "a única razão de a tela existir" — e é exatamente a tarefa que a tela não entrega. Cada linha bloqueada é um Pedido de Venda que não foi criado na Omie, ou seja, um brinde que não sai. Em lote grande o operador desiste ou perde linhas.

**Correção.** Mover o filtro para o servidor: aceitar `?status=bloqueadas` e aplicar `->when($request->status === 'bloqueadas', fn($q) => $q->whereIn('status', AppnozImportRow::BLOCKED_STATUSES))` antes do paginate; enviar as contagens do lote inteiro (não da página) para alimentar os chips. Trocar o texto do resultLabel (linha 170) de "desta página" para o total real. Enquanto o backend não muda, o texto tem que dizer a verdade em voz alta no chip: "Só bloqueadas (2 nesta página, 12 no lote)".

> **Verificação:** Tudo confere. AppnozImportController.php:42 é `$import->rows()->orderBy('row_number')->paginate(50)` sem filtro de status e não existe outra rota para linhas bloqueadas. Imports/Show.vue:56 (`blockedOnPage`), :64 (contador do chip) e :70-80 (`filtered`) operam sobre `props.rows.data`, enquanto o UiAlert de :139-142 usa `importItem.rows_blocked`/`rows_total`, que vêm do lote inteiro (controller :49-51). Logo o topo pode dizer '12 de 500' e o chip logo abaixo dizer '(2)'. Confirmo ainda a parte mais frágil do achado, que testei: o `filter` da linha 60 é `ref` inicializado no setup, e a paginação do UiPagination usa `<Link preserveScroll>` sem `preserveState` (UiPagination.vue:92) — o adapter Inertia troca a `key` da página nesse caso, o componente remonta e o filtro realmente reinicializa a cada página, inclusive caindo sozinho em 'all' quando a página não tem bloqueio. Mantenho alta: a tela existe para destravar linha, o contador contradiz o alerta e não há caminho para as demais linhas bloqueadas além de abrir página por página reativando o chip. Único reparo: o `result-label` da linha 170 e o estado vazio das linhas 296-299 já dizem 'desta página' — a mentira está no chip e no contraste com o alerta, não em toda a tela.


### 2. Projetos: lista de 200 linhas sem busca, sem filtro por estágio e sem filtro por liberação

**Arquivo:** `resources/js/Pages/Projects/Index.vue:59` · **Dimensão:** Telas operacionais densas

**Problema.** A página é PageHeader + UiTable + UiPagination, nada mais. Não há UiSearchInput, não há chip de estágio, não há chip de "pronto para liberar". O ProjectController.php:23-27 também não aceita filtro. O próprio código já reconhece a lacuna: o comentário em Dashboard.vue:34 diz "`Projetos` não tem filtro, então sem eles o operador cai numa lista de 200 linhas" — e a solução adotada foi listar 2 nomes no Dashboard como paliativo. Além disso, o PipelineChart do Dashboard (Dashboard.vue:150) mostra a contagem por estágio mas não é clicável: o operador vê "Produção 34" e não tem como abrir esses 34.

**Impacto.** Liberar projeto é a ação crítica do fluxo A. Sem filtro, chegar num projeto específico depende de decorar em que página ele está ou de paginar até achar. As barras do pipeline viram informação decorativa.

**Correção.** Aceitar `?q=`, `?estagio=` e `?situacao=` no ProjectController (o padrão já está pronto e funcionando em ProductController.php:30 + Products/Index.vue:36-47, com preserveState/replace). Na página, UiSearchInput por nome/empresa + chips de estágio com contagem. Transformar cada linha do PipelineChart em <Link> para `projects.index?estagio=<status>` — o dado da barra já é exatamente o filtro.

> **Verificação:** Confere integralmente. Projects/Index.vue:48-103 é só PageHeader + UiTable + UiPagination; ProjectController.php:23-27 só aplica o escopo de empresa do Admin Cliente e `->latest()->paginate(20)`. O comentário de Dashboard.vue:32-34 admite a lacuna com essas palavras. PipelineChart.vue:22-45 é um `<dl>` com `<div>`, sem nenhum `<Link>` — a contagem por estágio realmente não é clicável. O padrão server-side pronto existe mesmo em ProductController.php:22-31 + Products/Index.vue:36-47. MANTENHO alta, e por um motivo que o achado nem explora: `latest()` ordena por created_at desc e projeto 'Pronto para liberar' é justamente o antigo (passou pelo ciclo produtivo), então os projetos que o Dashboard manda o operador liberar caem nas ÚLTIMAS páginas da lista de 20 em 20, sem filtro e sem busca para chegar até eles.


### 3. Reabrir o link do e-mail já logado joga o cliente final num 403 do qual não há saída

**Arquivo:** `bootstrap/app.php:22` · **Dimensão:** Autenticação e primeiro acesso

**Problema.** Só `redirectGuestsTo('/login')` está configurado; `redirectUsersTo` não. Então o middleware `guest` (aplicado a todas as rotas de auth, routes/web.php:18) usa o padrão do Laravel e manda usuário autenticado para `/dashboard`, que exige `role:operator` (routes/web.php:50) — cliente final e admin cliente levam `abort(403)` em EnsureRole.php:41. Cenário concreto e comum: a pessoa define a senha, é autenticada automaticamente (PasswordController.php:106), vai ao portal, volta para a aba/app do e-mail e toca de novo no botão do convite — comportamento normal no celular. Resultado: 403. E a página de erro fecha o ciclo: resources/views/errors/403.blade.php:24 oferece um único botão, 'Entrar', apontando para `route('login')`, que passa outra vez pelo `guest`, redireciona para `/dashboard` e devolve o mesmo 403. Loop sem saída para quem já está logado; o único escape é digitar /portal na barra de endereço.

**Impacto.** A pessoa acabou de entrar com sucesso e, num toque, recebe 'Você não tem acesso a esta página' com um botão que só reapresenta o mesmo erro. Para um cliente final sem treino, isso lê como 'perdi o acesso ao meu presente'. E o 403 é a tela de pior custo possível para acontecer no minuto seguinte ao primeiro acesso.

**Correção.** Em bootstrap/app.php, ao lado da linha 22, adicionar `$middleware->redirectUsersTo(fn (Request $request) => route($request->user()->homeRouteName()));` — `homeRouteName()` já existe (User.php:63-70) e já é usado no pós-login (LoginController.php:41). Isso faz reabrir o link do e-mail cair no portal em vez de no 403. Complementarmente, em errors/403.blade.php:23-28, quando `auth()->check()`, apontar o botão para `route(auth()->user()->homeRouteName())` com o rótulo 'Ir para o meu painel' — hoje o botão é uma armadilha para usuário autenticado.

> **Verificação:** Confirmado e na verdade mais grave do que o descrito. bootstrap/app.php:22 só define `redirectGuestsTo`; sem `redirectUsersTo`, vale o padrão de vendor/laravel/framework/src/Illuminate/Auth/Middleware/RedirectIfAuthenticated.php:63-69, que retorna `route('dashboard')` porque a rota existe (routes/web.php:51). Essa rota é `role:operator` (routes/web.php:50) e EnsureRole.php:40-42 dá abort(403) para end_customer/admin_client. A página resources/views/errors/403.blade.php:23-28 oferece um único botão para `route('login')`, que está dentro do grupo `guest` (routes/web.php:18) — volta para /dashboard e devolve 403. Loop fechado, confirmado. Agravante não citado: routes/web.php:15 faz `Route::redirect('/', '/login')`, então QUALQUER usuário de portal já autenticado que digite só o domínio cai no mesmo 403 — não é preciso reabrir o e-mail. O próprio docs/REDESIGN-UI.md:2805-2811 previu o cenário e deixou `RedirectIfAuthenticated::redirectUsing` fora de escopo, o que explica a origem mas não refuta o defeito; e a mitigação escolhida (a página 403) fechou o ciclo em vez de abri-lo. `homeRouteName()` existe mesmo (User.php:63-70).


## Severidade media

### 4. A primeira coluna da tabela é `th scope=row` em metade das telas e `td` na outra metade

**Arquivo:** `resources/js/Pages/Dashboard.vue:168` · **Dimensão:** Design system e consistência

**Problema.** UiTable.vue:215-217 define `tbody th { font-weight: 500 }` — a regra do sistema para o nome da linha. Projects/Index.vue:61, Redemptions/Index.vue:44 e Projects/Show.vue:477/531/590/747 usam `<th scope="row">` e herdam isso. Já Dashboard.vue:168 e :209, Imports/Index.vue:92, Products/Index.vue:163 e Users/Index.vue:256 usam `<td>` — e recuperam o peso escrevendo `font-medium` à mão no conteúdo (Dashboard.vue:171, Imports/Index.vue:101, Products/Index.vue:164). O link em si também diverge: `font-medium text-ink hover:text-brand-700` em Dashboard/Imports, mas `text-ink hover:text-brand-700` SEM font-medium em Projects/Index.vue:64.

**Impacto.** O nome do projeto sai em peso 500 na lista de Importações e no Início, e em peso 400 na lista de Projetos — mesma coluna, mesma função, densidade visual diferente. E cinco tabelas perdem o cabeçalho de linha para o leitor de tela: o operador que navega por teclado não ouve "Projeto X" ao entrar numa célula.

**Correção.** Padronizar a primeira coluna como `<th scope="row">` nas cinco tabelas (Dashboard.vue:168 e :209, Imports/Index.vue:92, Products/Index.vue:163, Users/Index.vue:256) e remover os `font-medium` manuais, deixando UiTable.vue:215 responder. Adicionar `font-medium` ao link de Projects/Index.vue:64 ou removê-lo dos outros — uma decisão só.

> **Verificação:** Confirmado: `<th scope="row">` em Projects/Index.vue:61, Redemptions/Index.vue:44 e Projects/Show.vue:477/531/590/747; `<td>` com `font-medium` reescrito à mão em Dashboard.vue:168-171 e :209-213, Imports/Index.vue:92-101, Products/Index.vue:163-164 e Users/Index.vue:256-257. A regra está documentada no próprio sistema (UiTable.vue:213-217: 'th scope=row é o nome da linha'), então metade das tabelas ignora o padrão da casa e perde a associação de cabeçalho de linha para leitor de tela — é o achado com maior consequência real da lista, por isso média. Duas imprecisões: em Products/Index a primeira coluna é a :162 (UiCode do código Omie), a :163 é a segunda; e o link de Projects/Index.vue:64 NÃO diverge visualmente por não ter `font-medium` — ele está dentro do <th>, que já recebe font-weight:500 de UiTable.vue:215.


### 5. Borda de todo campo de formulário tem 1,49:1 — falha SC 1.4.11 (Contraste de não-texto)

**Arquivo:** `resources/css/app.css:32` · **Dimensão:** Acessibilidade (WCAG 2.2 AA)

**Problema.** `--color-line-strong: #cfd4dc` é a borda de todos os controles de entrada: UiInput.vue:68, UiSelect.vue:52, UiTextarea.vue:56, UiSearchInput.vue:65 e UiFileDrop.vue:137. Contra `--color-surface` (#ffffff) a razão é 1,49:1; contra `--color-canvas` (#f6f7f9) — onde ficam os campos do portal, que não estão dentro de cartão — cai para 1,39:1. O mínimo é 3:1. O estado de hover é ainda mais frágil: `hover:border-ink-subtle` (#98a2b3, UiInput.vue:68) alcança só 2,58:1. Como o campo é branco sobre fundo branco/quase branco e não tem preenchimento próprio, a borda é a ÚNICA informação visual que identifica onde começa e termina o controle — exatamente o caso citado no Understanding do SC 1.4.11.

**Impacto.** Quem tem baixa visão, catarata, presbiopia ou está no celular sob luz do sol não enxerga onde estão os campos. No portal isso cai sobre o colaborador que usa a tela UMA vez, sem treino, no celular, para digitar o endereço de entrega (Portal/Redeem.vue:187-296): ele não distingue o campo de CEP do campo de número, digita no lugar errado ou desiste — brinde não resgatado ou endereço errado, que é reentrega. Na área operacional atinge todos os 19 formulários, incluindo os drawers de expedição e de cadastro de empresa.

**Correção.** Escurecer o token para pelo menos L≤0,30. `--color-line-strong: #7d8798` dá 3,64:1 sobre surface e 3,39:1 sobre canvas, passando nos dois fundos. Se escurecer o token inteiro for indesejável (ele também é borda do botão secundário e da paginação, onde há texto visível e o mínimo não se aplica), criar um token dedicado — `--color-field-line: #7d8798` — e trocar `border-line-strong` por `border-field-line` em UiInput.vue:68, UiSelect.vue:52, UiTextarea.vue:56, UiSearchInput.vue:65 e UiFileDrop.vue:137. O hover `border-ink-subtle` precisa sair junto: usar `hover:border-ink-muted` (#667085, 4,97:1). Anotar a razão no comentário do token, como já é feito com ink e ink-muted.

> **Verificação:** Confere em todos os pontos citados: `--color-line-strong: #cfd4dc` (app.css:32) é a borda em UiInput.vue:68, UiSelect.vue:52, UiTextarea.vue:56, UiSearchInput.vue:65 e UiFileDrop.vue:137, sempre junto de `bg-surface`. Refiz o cálculo: L(#cfd4dc)=0,6556 → 1,49:1 contra #ffffff e 1,39:1 contra #f6f7f9; `hover:border-ink-subtle` (#98a2b3) dá 2,58:1; o #7d8798 proposto dá 3,63:1 sobre surface. O único outro sinal visual é `shadow-xs` (0 1px 2px rgb(16 24 40/0.05), app.css:76), que é imperceptível, e o fundo do campo é o mesmo do cartão — não há nada além da borda delimitando o controle. Rebaixo de alta para média: o rótulo do UiField (UiField.vue:57-65, texto ink 16,7:1) fica sempre visível acima do campo e o estado inválido troca para `border-danger` (#b42318), então ninguém fica impedido de achar ou concluir o formulário — é degradação de percepção para baixa visão, não bloqueio.


### 6. Busca de CEP no cadastro de empresa preenche 4 campos sem anunciar nada

**Arquivo:** `resources/js/Components/CompanyForm.vue:159` · **Dimensão:** Acessibilidade (WCAG 2.2 AA)

**Problema.** O `@blur="lookupCep()"` (linha 157) dispara a consulta e o `applyCepToForm` grava logradouro, bairro, cidade e UF (useCepLookup.js:76). O único retorno visual é o `cepHint` colocado no slot `#hint` (linhas 159-162), que o UiField renderiza como `<p :id="hintId">` sem `aria-live` (UiField.vue:69-71). Como o `hint` só entra em `aria-describedby` e o foco JÁ saiu do campo quando o resultado chega, nada é anunciado. É o mesmo fluxo que o portal trata corretamente em Portal/Redeem.vue:212-215, com um bloco `role="status"` — o operacional ficou de fora.

**Impacto.** O operador WeGift com leitor de tela sai do campo de CEP, quatro campos abaixo dele são preenchidos em silêncio e ele não tem como saber se a busca funcionou, se falhou ou se os dados que estão lá vieram do serviço ou do que ele digitou antes. Ele preenche endereço de novo por cima, ou salva a empresa com endereço de outro CEP — e é esse endereço que a expedição usa para faturar.

**Correção.** Envolver o par CEP+dica num container com `role="status"`, ou — mais simples e consistente com o portal — trocar o slot `#hint` por um bloco irmão anunciável logo abaixo do UiField do CEP: `<p v-if="cepHint" role="status" class="mt-1.5 text-xs text-ink-muted">{{ cepHint }}</p>`. O `cepError` já está coberto pelo `role="alert"` do UiField (UiField.vue:73), então só o desfecho positivo precisa da correção.

> **Verificação:** Confirmado linha a linha. `@blur="lookupCep()"` em CompanyForm.vue:157; `applyCepToForm` grava street, district, city e state em useCepLookup.js:76; o `cepHint` entra pelo slot `#hint` (CompanyForm.vue:159-162) e o UiField o renderiza como `<p :id="hintId">` sem aria-live (UiField.vue:69-71), servindo só de aria-describedby de um campo que já perdeu o foco. O contraste com Portal/Redeem.vue:212-215 (`role="status"`) é real e mostra que o próprio projeto já tem o padrão certo. Agrava: ao tabular para 'Logradouro' o leitor anuncia o campo vazio antes de a resposta chegar, e o Tab seleciona o conteúdo do input — quem digitar por cima sobrescreve só o logradouro e deixa bairro/cidade/UF da consulta, gerando endereço misturado. Mantenho média (é a área operacional, com revisão antes de salvar, não o portal de uso único).


### 7. Tocar em "Continuar" abre o bloco manual sem dizer nada — a pessoa fica presa no passo 1

**Arquivo:** `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redeem.vue:123` · **Dimensão:** Portal do cliente final (mobile-first)

**Problema.** Em `advance()` (linhas 102-130), quando a busca não completa o endereço o código faz `manual.value = true; return;` e nada mais. Não move foco, não rola a tela, não emite mensagem. Há três desfechos que caem aí: (a) CEP não encontrado — o erro vermelho aparece no topo, sob o campo de CEP (linha 196), longe do polegar que acabou de tocar no botão; (b) serviço fora do ar — o UiAlert de aviso também fica no topo (linha 236); (c) CEP de cidade sem logradouro, em que `cepState === 'found'` e portanto NÃO existe erro nem alerta nenhum: a caixa verde some (v-if="cepFound && !manual", linha 213), três campos novos brotam no meio do formulário e o botão desce. Com o teclado aberto num aparelho de 360×640 sobra cerca de 300px de altura visível: a pessoa toca em "Continuar", vê o spinner girar até 8s, o botão volta ao normal e a tela não vai para lugar nenhum.

**Impacto.** É o único ponto do fluxo em que o resgate pode simplesmente parar. A pessoa toca no botão de novo (agora `lookup` retorna cedo por causa de `lastQuery`, então nem o spinner aparece), conclui que o site está quebrado e fecha. Presente não resgatado, e ninguém fica sabendo — não há erro registrado, foi só uma desistência.

**Correção.** Em `advance()`, quando o retorno acontece por falta de endereço: 1) dar `ref` ao input de rua e chamar `nextTick(() => streetInput.value?.focus())`, o que já rola a tela até o bloco novo; 2) para o caso (c), sem erro nem alerta, exibir uma mensagem explícita acima do bloco manual, do tipo "Esse CEP atende a cidade inteira. Complete a rua e o bairro abaixo." — hoje esse caminho é 100% mudo.

> **Verificação:** O trecho existe como descrito: Redeem.vue:123-127 faz `manual.value = true; return;` sem foco, rolagem ou mensagem, e o único watch que move foco é o de `step` (Redeem.vue:100). O caminho (c) é realmente mudo: com `cepState === 'found'` e logradouro vazio, a caixa verde some (`v-if="cepFound && !manual"`, Redeem.vue:213) e os quatro campos aparecem sem nenhum texto explicando por quê — nem visual, nem via live region.

Mas o enquadramento está errado em duas partes: (1) os avisos NÃO ficam "no topo". O erro de CEP é renderizado dentro do próprio UiField do CEP (Redeem.vue:196 → UiField.vue:73), e o UiAlert de indisponível está em Redeem.vue:236 — os dois imediatamente acima do bloco manual que abre em Redeem.vue:257, ou seja, colados nele. (2) Ninguém fica "presa": os campos do bloco manual recebem `required` por injeção (UiField.vue:44 → UiInput.vue:59 e UiSelect.vue:43) e o `<form>` não tem `novalidate`, então o segundo toque em "Continuar" dispara a validação nativa antes do `@submit.prevent`, que foca, rola e exibe a mensagem do navegador em "Rua ou avenida". O defeito real é apenas a falta de mensagem no caminho (c) — custo de compreensão, não bloqueio. Por isso media, não alta.


### 8. Corrigir o CEP com o bloco manual aberto mantém cidade e UF do endereço anterior

**Arquivo:** `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redeem.vue:89` · **Dimensão:** Portal do cliente final (mobile-first)

**Problema.** O watch de `form.zip` (linhas 86-96) começa com `if (manual.value) return;` — com o bloco manual aberto, os campos de endereço não são limpos quando o CEP muda. E `applyCepToForm` (lib/cep.js:99-105) não sobrescreve campo preenchido. Resultado, num caminho concreto: a pessoa digita o CEP errado, o serviço acha um endereço válido de OUTRA cidade, a caixa verde mostra "São Paulo/SP — Centro", ela toca em "Não é esse endereço?" (linha 229) — o que liga `manual` e mantém os campos preenchidos com São Paulo — e então corrige o CEP. A nova consulta encontra Curitiba, mas não sobrescreve nada. O passo 2 exibe o CEP novo com a cidade velha (linha 322: `{{ form.city }}/{{ form.state }} — {{ form.zip }}`), e a conferência do passo 2 é justamente onde a pessoa confia que está tudo certo.

**Impacto.** Pacote despachado para a cidade errada com CEP certo. Não existe rota de correção de endereço no sistema (o próprio comentário da linha 35-39 diz isso) — vira reentrega, custo e um presente que chega semanas depois ou não chega.

**Correção.** Quando o CEP muda e a consulta anterior tinha preenchido os campos, limpar de qualquer jeito — inclusive com `manual` ligado. Guardar o último endereço aplicado (o composable já expõe `address`) e, no watch de `form.zip`, zerar `street/district/city/state` cujo valor ainda for idêntico ao que veio da consulta anterior. O que a pessoa digitou à mão fica; o que a busca trouxe some junto com o CEP que o trouxe.

> **Verificação:** Confere linha a linha. Redeem.vue:86-96 começa com `if (manual.value) return;`, então com o bloco manual aberto os campos não são limpos na troca de CEP; `applyCepToForm` (lib/cep.js:99-105) só escreve em campo vazio; e "Não é esse endereço?" (Redeem.vue:224-230) liga `manual` sem limpar nada. O watch interno do composable (useCepLookup.js:127-136) apenas zera o `state`, não o endereço já aplicado ao form.

Resultado verificável: novo CEP + cidade/UF/rua antigos chegando à conferência em Redeem.vue:320-322, que é a última barreira antes de uma ação sem rota de correção. Atenuante que impede severidade alta: com `manual` ligado os campos ficam visíveis e editáveis logo abaixo do CEP (Redeem.vue:257-281), então o dado velho está na tela, não escondido.


### 9. Campo "Número" é obrigatório e abre teclado só de dígitos — endereço sem número fica impossível no iPhone

**Arquivo:** `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redeem.vue:285` · **Dimensão:** Portal do cliente final (mobile-first)

**Problema.** `<UiInput v-model="form.number" size="lg" inputmode="numeric" />` dentro de um UiField `required` (o UiField injeta `required` no input, UiField.vue:44). No iOS, `inputmode="numeric"` mostra o teclado numérico sem acesso a letras: não há como digitar "s/n". E o `canAdvance` (linha 68) exige `!!form.number`, então o botão "Continuar" fica desabilitado até algo ser digitado. O backend aceita string de até 20 caracteres (PortalController.php:77), ou seja, a restrição é só da interface.

**Impacto.** Quem mora em endereço sem número (comum fora de capital) não consegue avançar, ou inventa "0"/"00" e o endereço chega ao transportador diferente do que deveria. Alvo de reentrega.

**Correção.** Manter `inputmode="numeric"` (é o certo para 99% dos casos) e dar a saída explícita: uma dica no campo — `hint="Sem número? escreva 0"` — ou um checkbox "Meu endereço não tem número" que preenche `s/n` e desabilita o campo. Se optar pelo checkbox, `canAdvance` precisa considerá-lo.

> **Verificação:** Confirmado exatamente como descrito: Redeem.vue:283-286 tem `<UiInput v-model="form.number" size="lg" inputmode="numeric" />` dentro de `<UiField label="Número" required>`, e o `required` é injetado no input (UiField.vue:44 → UiInput.vue:59). O `canAdvance` (Redeem.vue:68) exige `!!form.number`, então o botão fica desabilitado com a única explicação "Informe o CEP e o número para continuar" (Redeem.vue:302-304). O backend não impõe isso: `'number' => ['required','string','max:20']` (PortalController.php:77) aceita "s/n".

Com o teclado numérico do iOS/Gboard não há acesso a letras, então quem mora em endereço sem número não tem nenhuma entrada válida que represente o endereço real — precisa inventar ("0") sem que nada na tela autorize isso. Endereço sem número é comum no Brasil e o custo do erro aqui é reentrega, por isso media; não é alta porque digitar "0" destrava e o backend aceita.


### 10. Portal: CEP com 7 dígitos deixa o botão "Continuar" morto e nenhuma mensagem diz o porquê

**Arquivo:** `resources/js/Pages/Portal/Redeem.vue:68` · **Dimensão:** Formulários, validação e feedback

**Problema.** `canAdvance` (linha 68) exige `isCompleteCep(form.zip)`, que é exatamente 8 dígitos (resources/js/lib/cep.js:11-13). Quem digita 7 dígitos vê um campo aparentemente preenchido — `formatCep` ainda insere o hífen, "80010-0" —, o botão continua desabilitado (linha 299) e o único texto na tela é "Informe o CEP e o número para continuar" (linha 302), que dá a entender que o CEP está vazio. Não há erro sob o campo: no `blur`, `lookup()` chama `reset()` quando o CEP é incompleto (useCepLookup.js:98-101), então o estado volta a 'idle' e `cepFieldError` fica nulo. Resultado: campo preenchido, botão inerte, nenhuma explicação.

**Impacto.** É o público que usa o sistema uma única vez, sem treino, no celular, vindo de um link de e-mail. O caminho até aqui é o único caminho para o brinde ser entregue. Quem trava nessa tela abandona o resgate — e é o abandono mais caro possível, porque a empresa cliente já pagou o kit.

**Correção.** Criar `const cepIncompleto = computed(() => { const d = form.zip.replace(/\D/g, ''); return d.length > 0 && d.length < 8; })` e injetá-lo no erro do campo: `:error="form.errors.zip || cepFieldError || (cepIncompleto ? 'O CEP tem 8 dígitos — faltam ' + (8 - digitos) + '.' : null)"`. Trocar também o texto do rodapé (linha 302) por uma lista do que de fato falta, calculada dos campos vazios, em vez da frase fixa.

> **Verificação:** Confere no código. `canAdvance` (Portal/Redeem.vue:68) exige `isCompleteCep(form.zip)` = exatamente 8 dígitos (lib/cep.js:11-13). No `blur`, `useCepLookup.lookup()` formata o valor (useCepLookup.js:94 — 7 dígitos viram "80010-00", com hífen) e, por não estar completo, chama `reset()` e volta a 'idle' (useCepLookup.js:98-101), então `cepFieldError` (Redeem.vue:64) fica nulo e o UiField não renderiza erro nenhum (Redeem.vue:196). O botão fica desabilitado (Redeem.vue:299) e o único texto é a frase fixa da linha 302. Como o botão está desabilitado, a validação nativa também nunca dispara. Rebaixo de alta para média: existe uma explicação na tela (imprecisa, não ausente) e o placeholder "00000-000" (Redeem.vue:205) mostra o formato — a pessoa se recupera relendo o próprio CEP, não fica barrada.


### 11. Drawer de formulário fecha por Escape e clique fora e joga fora tudo o que foi digitado, sem avisar

**Arquivo:** `resources/js/Components/UiDrawer.vue:22` · **Dimensão:** Formulários, validação e feedback

**Problema.** `requestClose` (linhas 22-26) só olha para a prop `dismissible`, que é `true` por padrão. Escape (UiModal.vue:69-74) e clique no backdrop (UiDrawer.vue:45) fecham direto. Nenhum dos quatro drawers de formulário passa `dismissible` ou consulta `form.isDirty`: Admin/Companies/Index.vue:312 (14 campos do CompanyForm), Admin/Users/Index.vue:286, Projects/Show.vue:608, ShipControl.vue:160. Pior: como as páginas reabrem o drawer com `defaults()` + `reset()` (Companies/Index.vue:107-126), o que foi digitado some de vez, não fica "guardado". O botão "Cancelar" (Companies/Index.vue:327) também fecha sem perguntar nada.

**Impacto.** Um Escape errado no meio do cadastro de empresa apaga 14 campos, inclusive endereço completo que o operador acabou de conferir com o cliente por telefone. E a própria base já tem a solução pronta e testada: Admin/EmailTemplates/Index.vue:217-231 e 277-298 implementam guarda de saída com `isDirty` + `confirmDiscard()` + `beforeunload`. A proteção existe só na tela que tem menos campos.

**Correção.** Adicionar ao UiDrawer uma prop `dirty: Boolean` e, em `requestClose`, quando `dirty` for verdadeiro, chamar `useConfirm()` com título "Descartar o que você digitou?", `confirmLabel: 'Descartar'`, `cancelLabel: 'Continuar editando'`, `variant: 'danger'` — exatamente o `confirmDiscard()` de EmailTemplates/Index.vue:217. Depois passar `:dirty="companyForm.isDirty"`, `:dirty="form.isDirty"`, etc., nos quatro drawers.

> **Verificação:** Confere. `requestClose` (UiDrawer.vue:22-26) só consulta `dismissible`, que é `true` por padrão (UiDrawer.vue:13); Escape passa pelo motor comum (UiModal.vue:69-74) e o backdrop fecha no clique (UiDrawer.vue:45). Nenhum dos quatro drawers de formulário passa `dismissible` nem consulta `isDirty` (Admin/Companies/Index.vue:312, Admin/Users/Index.vue:286, Projects/Show.vue:608, ShipControl.vue:160). E a perda é real, não só visual: o `useForm` sobrevive ao fechamento, mas o único caminho de volta é `openCreate`/`openEdit`, que fazem `defaults()` + `reset()` (Companies/Index.vue:107-126), assim como `load()` (Users/Index.vue:129-134), `openDrawer()` (ShipControl.vue:104-110) e `openCustomerDrawer()` (Projects/Show.vue:341-347). O projeto já tem a peça pronta para o remédio (`confirmDiscard` em EmailTemplates/Index.vue:217-224, usado inclusive como guarda de saída na linha 277). Rebaixo para média: custa retrabalho de digitação, não impede concluir a tarefa — e nos casos de ShipControl (2 campos) e Users (3 campos) o prejuízo é pequeno; o caso forte é mesmo o CompanyForm de 14 campos.


### 12. SetPassword apaga as duas senhas a cada recusa e repete a mesma mensagem sob os dois campos

**Arquivo:** `resources/js/Pages/Auth/SetPassword.vue:34` · **Dimensão:** Formulários, validação e feedback

**Problema.** Dois defeitos no mesmo formulário. (1) `onFinish: () => form.reset('password', 'password_confirmation')` (linha 34) roda também quando a resposta é 422 — a pessoa digita uma senha de 7 caracteres, recebe "A senha deve ter pelo menos 8 caracteres." e encontra os dois campos vazios, tendo que redigitar tudo duas vezes. (2) A regra do backend é `['required', 'confirmed', PasswordRule::min(8)]` (PasswordController.php:80), então todos os erros vêm no bag `password`; a tela renderiza `:error="form.errors.password"` tanto na linha 59 quanto na linha 83. Em "Confirmar senha" aparece "A senha deve ter pelo menos 8 caracteres." — mensagem que não tem nada a ver com aquele campo — e em caso de divergência a mesma frase surge duas vezes seguidas, sem dizer qual dos dois corrigir.

**Impacto.** É a primeira tela do colaborador da empresa cliente, no celular, vindo do convite por e-mail. Redigitar duas senhas do zero depois de cada erro é a fricção que faz a pessoa desistir de criar acesso — e sem acesso ela não resgata o brinde.

**Correção.** Linha 34: `onSuccess` em vez de `onFinish`, ou `form.reset('password_confirmation')` apenas. Linha 83: não repetir a mensagem — deixar `:invalid="!!form.errors.password"` sem `:error`, ou mapear só a divergência (`form.errors.password?.includes('iguais') ? form.errors.password : null`) para o campo de confirmação.

> **Verificação:** Confere nas duas partes. (1) `onFinish: () => form.reset('password', 'password_confirmation')` (Auth/SetPassword.vue:34) roda também no 422, então toda recusa devolve os dois campos vazios — inclusive a de senha curta, com `hint` "Mínimo de 8 caracteres" (linha 59) sem o que foi digitado. (2) A regra é `['required', 'confirmed', PasswordRule::min(8)]` em PasswordController.php:80, ou seja, tudo cai no bag `password`, e a tela renderiza `form.errors.password` nos dois campos (linhas 59 e 83): "Confirmar senha" exibe a mensagem de tamanho mínimo, que não é sobre ele. Média e não alta: a pessoa consegue concluir redigitando; o custo é retrabalho num caminho de conta nova (todo usuário WeGift, Admin Cliente e cliente final passa por aqui).


### 13. EmailTemplates no celular: com a aba "Prévia" aberta, o clique em Salvar falha em silêncio

**Arquivo:** `resources/js/Pages/Admin/EmailTemplates/Index.vue:359` · **Dimensão:** Formulários, validação e feedback

**Problema.** Abaixo de `lg`, a coluna do editor recebe a classe `hidden` quando `view === 'previa'` (linha 359). Assunto (linha 361) e Mensagem (linha 392) são UiField `required`, e o UiField propaga isso para o atributo `required` nativo do controle (UiInput.vue:59, UiTextarea.vue:47). O botão Salvar (linha 475) fica fora dessa coluna e continua visível nas duas abas. Se qualquer um dos dois campos estiver vazio e a pessoa estiver na aba Prévia, o navegador não consegue focar um campo com `display:none`: o Chrome cancela o envio, registra "An invalid form control is not focusable" no console e não mostra bolha nenhuma. O clique não produz efeito visível algum.

**Impacto.** Botão que não faz absolutamente nada, sem mensagem, sem spinner, sem erro. Restrito a tablet/celular e a campo vazio, mas quando acontece não há nenhuma pista do que fazer.

**Correção.** Trocar `hidden` por uma técnica que mantenha os campos focáveis, ou — mais simples e alinhado ao resto — voltar para a aba Editar antes de validar: no `save()` (linha 238), `if (!form.subject || !form.body) { view.value = 'editar'; return; }`, e considerar remover o `required` nativo destes dois campos, deixando a validação do servidor (EmailTemplateController.php:40-41) mostrar a mensagem no lugar certo.

> **Verificação:** Confere. Abaixo de `lg` a coluna do editor recebe `hidden` quando `view === 'previa'` (EmailTemplates/Index.vue:359), e ela contém Assunto (linha 361, UiField `required` → `required` nativo em UiInput.vue:59) e Mensagem (linha 387, → UiTextarea.vue:47). O botão Salvar está fora dessa coluna e visível nas duas abas (linhas 475-481), e não há `novalidate` em nenhum formulário do projeto (busca em resources/ não retorna nada). Campo `required` com `display:none` não é dispensado da validação de restrições: o Chrome cancela o envio, loga "An invalid form control is not focusable" e não exibe bolha alguma — clique sem efeito visível. Mantenho média com a ressalva de que o gatilho é estreito: exige que a pessoa tenha esvaziado o assunto ou a mensagem, em viewport < lg, e esteja na aba Prévia.


### 14. Resgates — a fila diária de expedição não tem filtro nenhum

**Arquivo:** `resources/js/Pages/Redemptions/Index.vue:42` · **Dimensão:** Telas operacionais densas

**Problema.** A tela mais operacional do sistema é uma UiTable crua: sem busca, sem filtro por situação, sem ordenação. O RedemptionController (app/Http/Controllers/RedemptionController.php:19-20) faz `->latest()->paginate(25)` sem nenhum `when()` de filtro, então a lista mistura confirmed, shipped, delivered e canceled por data de criação. O Dashboard anuncia "N resgates aguardando expedição" e o botão "Expedir" (Dashboard.vue:62) leva exatamente para essa lista sem filtro: o operador clica em "Expedir" e cai numa página onde os resgates que ele acabou de contar estão espalhados entre entregues. Compare com Imports/Show.vue:60, que já nasce com o chip "Só bloqueadas" ligado — o padrão certo existe no código, só não foi aplicado aqui.

**Impacto.** Expedição é tarefa diária e repetitiva. Com 200 resgates no histórico e 12 pendentes, o operador varre 8 páginas para achar 12 botões "Expedir". Cada resgate esquecido vira atraso de entrega de brinde. O estado vazio (linha 76, "Nenhum resgate confirmado.") também mente: ele só aparece quando não há resgate NENHUM, nunca quando o filtro não achou nada — porque filtro não existe.

**Correção.** Adicionar filtro server-side no controller: `->when($request->situacao, fn($q,$s) => $q->where('status',$s))` e enviar as contagens por status. Na página, um <UiTabs variant="segmented"> com [Aguardando expedição (N) · Em transporte (N) · Entregues (N) · Todos], padrão em 'confirmed' quando N>0 — mesma mecânica de Imports/Show.vue:60-66. Fazer o Dashboard apontar para route('redemptions.index', ['situacao' => 'confirmed']). Somar UiSearchInput por nome do cliente final e cidade.

> **Verificação:** Confere no código: Redemptions/Index.vue:37-81 é PageHeader + UiTable + UiPagination, sem UiSearchInput, sem UiTabs, sem chip; RedemptionController.php:19-20 faz `Redemption::with(...)->latest()->paginate(25)` sem nenhum `when()`; Dashboard.vue:62 aponta `href: route('redemptions.index')` sem querystring. O contraste com Imports/Show.vue:60 (`filter = ref(blockedOnPage > 0 ? 'blocked' : 'all')`) também é real. REBAIXO de alta para media por dois motivos verificáveis: (a) `latest()` ordena por created_at desc e o resgate nasce `confirmed` — os pendentes ficam concentrados no topo da página 1, não 'espalhados entre entregues' como o achado afirma; um shipped só sobe acima de um confirmed quando foi criado depois; (b) a coluna Ação (Redemptions/Index.vue:68 → ShipControl.vue:141) só desenha o botão 'Expedir' em `status === 'confirmed'`, então a varredura visual da coluna 5 já isola a fila. Continua sendo lacuna real: não há busca por nome/cidade e não há como conferir o número anunciado no Dashboard — mas não impede concluir a expedição.


### 15. Projects/Show: cabeçalho sticky de ~250px come a metade útil da tela e esconde o foco de teclado

**Arquivo:** `resources/js/Pages/Projects/Show.vue:430` · **Dimensão:** Telas operacionais densas

**Problema.** O slot #meta empilha uma UiDescriptionList inline com até 6 itens (empresa, CNPJ, pedido Omie, quantidade, prazo, Trello) MAIS um UiPanel com grid de 3 colunas MAIS a fila de abas — tudo dentro do PageHeader, que é `lg:sticky lg:top-0 lg:z-20` (PageHeader.vue:27-28). Somando pt-6(24) + back link min-h-9(36) + h1(32) + meta(~102) + abas(40) + pb-4(16), o bloco grudado passa de 240px. Num notebook 1366×768 (viewport útil ~640px) sobram ~390px para a tabela: 8 linhas de 44px por vez, numa aba que pagina de 25 em 25. E o conteúdo permanentemente grudado é justamente o que NÃO decide nada durante a varredura — CNPJ, número do pedido Omie, link do Trello. Segundo problema, no mesmo lugar: app.css:112 define `scroll-margin-top: 5rem` com o comentário "Cabeçalho de contexto é sticky (64px)". Ele não é. Ao navegar com Shift+Tab subindo pela tabela, o elemento focado para a 80px do topo, atrás de um cabeçalho de 240px — violação de WCAG 2.2 SC 2.4.11 (focus not obscured).

**Impacto.** É a tela onde o operador passa mais tempo (clientes finais, resgates, kits do projeto) e é a que tem menos área de dados. Quem navega por teclado perde o foco de vista ao subir na lista e fica sem saber onde está.

**Correção.** Tirar o #meta do que gruda: manter no sticky apenas o h1 e a fila de abas, e deixar a faixa de identidade rolar para fora. Concretamente, mover o UiDescriptionList/UiPanel para fora do <PageHeader> (um bloco irmão logo abaixo) e envolver só as abas num `lg:sticky lg:top-0`. Isso derruba o sticky de ~240px para ~110px e recupera ~130px de tabela. Em paralelo, alinhar app.css:112 com a altura real ou, melhor, usar `scroll-margin-top` variável: `:root { --sticky-h: 4.5rem }` e sobrescrever para `15rem` no escopo da tela com meta+abas.

> **Verificação:** Confirmado nos dois pontos. Projects/Show.vue:430-444 põe dentro do slot #meta uma UiDescriptionList inline (até 6 itens: identity em :62-104) MAIS um UiPanel `mt-3` com UiDescriptionList grid de 2-3 colunas, e :446-448 acrescenta a fila de abas — tudo dentro do PageHeader, que é `lg:sticky lg:top-0 lg:z-20` (PageHeader.vue:28). Somando as classes reais (pt-6=24, back link min-h-9=36, h1 text-2xl=32, meta mt-2=8 + dl inline ~18 + mt-3=12 + UiPanel p-3=24 + grid ~40, abas mt-4/-mb-4 + h-10=40, pb-4=16) o bloco grudado fica em ~250px, e o sticky funciona mesmo (nenhum ancestral em AppLayout.vue:139-226 cria contexto de rolagem). O segundo ponto é o defeito duro: app.css:109-113 declara `scroll-margin-top: 5rem` com o comentário 'Cabeçalho de contexto é sticky (64px)' — 80px não cobre 250px, e ao subir com Shift+Tab pelos controles da tabela (UiMenu de :496, ShipControl de :561) o elemento focado para atrás do cabeçalho: falha de WCAG 2.2 SC 2.4.11. REBAIXO para media porque a navegação com Tab para frente ainda deixa o foco visível na parte de baixo do viewport e a perda de densidade é tradeoff, não bloqueio; a violação de foco é real mas parcial.


### 16. Listas paginadas pelo servidor nunca dizem quantos registros existem

**Arquivo:** `app/Http/Controllers/ProjectController.php:41` · **Dimensão:** Telas operacionais densas

**Problema.** ProjectController.php:30-42, RedemptionController.php:23-36 e AppnozImportController.php:22-35 montam a prop de paginação à mão com apenas `data` e `links`, descartando `total` e `per_page`. Sem `total`, UiPagination.vue:48-53 devolve `range = null` e o nav renderiza só "Página 3 de 8" — nenhum "Resgates 51–75 de 187". E como o nav inteiro está sob `v-if="lastPage > 1"` (UiPagination.vue:139), quando cabe tudo numa página não sobra contagem alguma na tela: uma lista de 14 projetos não informa em lugar nenhum que são 14. Products/Index.vue:202-207 faz certo (passa `:total` e `:per-page`), o que deixa a inconsistência ainda mais visível entre telas irmãs.

**Impacto.** O operador não consegue responder "quantos resgates existem?" nem conferir contra o número que o Dashboard acabou de mostrar. Perde a âncora de "onde estou dentro do todo" — o mesmo ciclo que o Projects/Show resolve bem com "mostrando X de Y" (Projects/Show.vue:205-210).

**Correção.** Adicionar `'total' => $paginator->total(), 'per_page' => $paginator->perPage()` nas três props e passar `:total`/`:per-page` para UiPagination em Projects/Index.vue:102, Redemptions/Index.vue:80 e Imports/Index.vue:128. Para o caso de página única, mover a contagem para fora do nav: um `<p class="text-xs text-ink-muted">` acima da tabela, sempre visível, como já se faz no resultLabel do UiSearchInput.

> **Verificação:** Confirmado nos seis pontos. ProjectController.php:30-42, RedemptionController.php:23-36 e AppnozImportController.php:22-35 montam a prop com `data` + `links` apenas, descartando total/per_page. UiPagination.vue:47-53 devolve `range = null` quando `total === null` (o default do prop é null, linha 11) e o `<nav>` inteiro está sob `v-if="lastPage > 1"` (linha 139) — em lista de página única não sobra contagem nenhuma. Projects/Index.vue:102, Redemptions/Index.vue:80 e Imports/Index.vue:128 passam só `:links`. E Products/Index.vue:202-207 de fato passa `:total`/`:per-page` porque ProductController.php:43-44 devolve o paginator inteiro. Media está certo: é informação faltando, não bloqueio.


### 17. Dashboard: "+ N outros" aparece sem nada listado antes, e os nomes mostrados são os menos urgentes

**Arquivo:** `resources/js/Pages/Dashboard.vue:35` · **Dimensão:** Telas operacionais densas

**Problema.** `readyToRelease` (linha 35) filtra `props.recentProjects`, que o DashboardController.php:27 limita aos 8 projetos MAIS RECENTES. Se nenhum dos 8 mais recentes estiver pronto para liberar — situação normal, porque projeto pronto e não liberado tende a ser antigo — `readyToRelease` fica vazio, `notListed` vira o total, e `details` (linha 47-50) renderiza um único item: "+ 5 outros". Outros que quem? Nada foi listado. O comentário da linha 32-34 diz que esses nomes existem para "fechar o ciclo li 3, cadê os 3?" — e o mecanismo falha exatamente quando importa, no caso do projeto esquecido. Além disso, os 2 nomes que aparecem quando aparecem são os 2 mais recentes, ou seja, os de prazo mais folgado; e `recentProjects` nem carrega `delivery_deadline` (DashboardController.php:28-35), então a tela não teria como ordenar por urgência.

**Impacto.** A seção se chama "Precisa de você" e é a única resposta do sistema para "o que é meu HOJE". Ela nomeia os projetos errados ou nomeia nenhum, empurrando o operador para uma lista de Projetos que também não tem filtro.

**Correção.** Criar uma prop dedicada no DashboardController: `Project::whereNotNull('stock_available_at')->whereNull('released_at')->orderBy('delivery_deadline')->limit(3)->get()` com nome, empresa e prazo. Usar essa lista nos `details` e ajustar o texto: quando não houver nome algum a mostrar, escrever "Ver os 5" em vez de "+ 5 outros". Mostrar o prazo ao lado do nome (`Kit Onboarding · Suvinil · atrasado 3 dias`), reaproveitando deadlineState de lib/format.js:101.

> **Verificação:** Confirmado. Dashboard.vue:35-37 filtra `props.recentProjects`, que DashboardController.php:27 limita aos 8 mais recentes, enquanto `stats.awaitingRelease` (DashboardController.php:22) conta todos os projetos com `stock_available_at` e sem `released_at`. Quando nenhum dos 8 mais recentes está pronto, `readyToRelease` é vazio, `notListed` (linha 39) vira o total e `details` (linhas 47-50) renderiza um item só: '+ 5 outros'. E o caso não é raro: projeto com estoque disponível e não liberado é, por definição do fluxo, projeto antigo. Confirmo também que `recentProjects` não carrega `delivery_deadline` (DashboardController.php:28-35), então não há como ordenar por urgência, e que `deadlineState` existe em lib/format.js:101 para reaproveitar. Media: copy enganosa na fila principal, com 'Ver todos' ainda funcionando.


### 18. Dashboard afirma um motivo de bloqueio que vale para só metade das linhas contadas

**Arquivo:** `resources/js/Pages/Dashboard.vue:70` · **Dimensão:** Telas operacionais densas

**Problema.** A fila "Linhas Suvinil bloqueadas" traz `details: ['Produto não cadastrado na Omie']` fixo no código. Mas `stats.blockedRows` vem de `AppnozImportRow::whereIn('status', AppnozImportRow::BLOCKED_STATUSES)` (DashboardController.php:24) e BLOCKED_STATUSES inclui `blocked_structural` além de `blocked_product` (app/Models/AppnozImportRow.php:29-32). Erro estrutural não se resolve cadastrando produto na Omie — o próprio backend recusa o reprocessamento e manda corrigir a planilha (AppnozImportController.php:114-115). O botão da fila ("Resolver") ainda leva para imports.index, a lista de LOTES, não para as linhas.

**Impacto.** O operador lê o motivo, vai em Produtos, importa o catálogo da Omie, volta, clica Reprocessar e leva um erro. Trabalho jogado fora e desconfiança no que o Dashboard afirma — justamente na tela cuja função é ser confiável sobre prioridades.

**Correção.** Quebrar a contagem em duas no DashboardController (`blockedProduct` e `blockedStructural`) e montar `details` a partir delas: "8 · produto não cadastrado na Omie" e "4 · erro na planilha (reimportar)". Cada uma com destino próprio, apontando para a listagem de linhas filtrada (ver achado nº 2), não para a lista de lotes.

> **Verificação:** Confirmado. Dashboard.vue:70 fixa `details: ['Produto não cadastrado na Omie']`, mas DashboardController.php:24 conta `whereIn('status', AppnozImportRow::BLOCKED_STATUSES)` e AppnozImportRow.php:29-32 inclui `blocked_structural` junto de `blocked_product`. Bloqueio estrutural não sai cadastrando produto: ImportAppnozFile.php:88-109 marca structural em CPF/CEP/endereço/quantidade inválidos, e AppnozImportController.php:113-116 recusa o reprocessamento mandando corrigir a planilha. Ou seja, a fila manda o operador para o caminho errado numa fatia das linhas contadas. Só não acompanho o achado no destino: `href: route('imports.index')` (Dashboard.vue:72) leva à lista de lotes, que tem coluna 'Bloqueadas' por lote (Imports/Index.vue:115) — é um salto a mais, não um beco. Media.


### 19. Link de convite expirado: o formulário continua ativo e a saída é um link de rodapé abaixo do botão primário

**Arquivo:** `resources/js/Pages/Auth/SetPassword.vue:43` · **Dimensão:** Autenticação e primeiro acesso

**Problema.** Quando o token expira (60 min, config/auth.php:99), `erroDeLink` recebe 'Este link expirou. Peça um novo abaixo — leva 1 minuto.' e aparece como UiAlert `danger` SEM slot #actions. Todo o formulário (linhas 51-94) segue visível e habilitado, incluindo o botão primário full-width 'Criar senha e entrar' — que vai falhar sempre, porque o token é o mesmo. O 'abaixo' da mensagem aponta para um link de 14px no rodapé (AuthCard.vue:33-38), depois de dois campos de senha e de um botão de marca ocupando a largura toda. ResetPassword.vue:41-47 repete o mesmo desenho. O projeto JÁ tem o padrão certo em dois lugares: Login.vue:42-50 coloca a ação de recuperação dentro do alerta via #actions, e ForgotPassword.vue:46 remove o formulário com `v-else` quando ele não serve mais. A única tela onde isso é decisivo — convite expirado, cliente final, celular, primeira e única visita — é justamente a que não usa nenhum dos dois.

**Impacto.** O elemento mais chamativo da tela leva ao erro; a saída correta é o elemento menos chamativo, depois dele. Quem chega por link expirado tende a preencher a senha, apertar o botão grande, falhar, tentar de novo com outra senha (achando que o problema é a senha) e só então desistir. Esse é o cenário de maior volume, porque o link vale 60 minutos e e-mail corporativo raramente é lido em 60 minutos. Cada desistência é um brinde não resgatado ou um chamado.

**Correção.** Em SetPassword.vue e ResetPassword.vue, quando `erroDeLink` estiver preenchido: (1) esconder o formulário com `v-if="!erroDeLink"` no `<form>` (mesma decisão do ForgotPassword.vue:46); (2) mover a recuperação para dentro do alerta com o slot #actions e um UiButton real — `<template #actions><UiButton variant="secondary" size="lg" :href="route('password.request')">Receber um link novo</UiButton></template>` —, copiando Login.vue:45-49; (3) ajustar lang/pt_BR/passwords.php:18 tirando o 'abaixo', que deixa de ser verdade: 'Este link expirou — eles valem 1 hora. Peça um novo, leva 1 minuto.'

> **Verificação:** Confirmado linha a linha: lang/pt_BR/passwords.php:18 entrega 'Este link expirou. Peça um novo abaixo — leva 1 minuto.'; PasswordController.php:99-103 joga isso no bag `email`; SetPassword.vue:28 transforma em `erroDeLink`; SetPassword.vue:44 renderiza UiAlert danger SEM slot #actions e o `<form>` das linhas 51-94 continua montado e habilitado, com o botão block 'Criar senha e entrar' (linha 93) que só pode falhar de novo — o token é o mesmo. ResetPassword.vue:41-47 e 49-94 repetem. Também confere que o projeto já tem os dois padrões melhores (Login.vue:45-49 com #actions; ForgotPassword.vue:46 com `v-else` removendo o formulário). Correção de severidade para media: não é beco sem saída — o próprio texto do alerta manda pedir 'abaixo' e o rodapé existe (SetPassword.vue:96-101) com alvo de toque de 44px garantido pelo AuthCard.vue:35 (`[&_a]:min-h-11`), detalhe que o achado subestima ao descrever 'um link de 14px'. O defeito real que sobra é a ação primária que nunca funciona e o retrabalho de digitar duas senhas à toa (o `onFinish` da linha 34 ainda limpa os dois campos a cada tentativa).


### 20. 'Enviar de novo' comprovadamente não envia nada nos primeiros 60 segundos, e a tela não muda em nada

**Arquivo:** `resources/js/Pages/Auth/ForgotPassword.vue:40` · **Dimensão:** Autenticação e primeiro acesso

**Problema.** O broker tem throttle de 60s (config/auth.php:100). PasswordController.php:35-38 descarta o retorno de `Password::sendResetLink()` e sempre devolve o mesmo flash neutro. Consequência: dentro da janela de 60s o clique em 'Enviar de novo' não dispara e-mail nenhum, e a tela renderiza exatamente os mesmos bytes de antes — mesmo texto, mesma lista, sem carimbo de horário, sem confirmação. O spinner do UiButton pisca e acabou. Acima de 5 tentativas por minuto entra o limitador de rota (AppServiceProvider.php:29-31, `throttle:auth`) e a resposta 429 não é Inertia: cai no modal de erro cru do Inertia, em cima da própria tela de recuperação.

**Impacto.** O botão foi posto ali justamente para quem não recebeu o e-mail — e é exatamente essa pessoa que clica três, quatro, cinco vezes em sequência. As primeiras não enviam nada, a tela não avisa, e a última entrega um modal de erro em inglês na única página que existe para destravar o acesso. O usuário conclui que a plataforma está fora do ar.

**Correção.** Desabilitar o botão por 60s após cada envio com contagem visível ('Enviar de novo em 43s'), usando `:disabled` do UiButton e um `setInterval` local — o número vem de config/auth.php:100 e é conhecido no front. Reexibir a confirmação com horário ('Enviado às 14:32') para que o reenvio produza mudança visível na tela. E, em PasswordController::sendResetLink, tratar o retorno `Password::RESET_THROTTLED` devolvendo o flash de lang/pt_BR/passwords.php:17 ('Aguarde alguns instantes antes de tentar de novo.') — hoje essa string existe traduzida e nunca chega à tela.

> **Verificação:** Confirmado: config/auth.php:100 define `throttle => 60` e PasswordController.php:35 chama `Password::sendResetLink()` descartando o retorno, devolvendo sempre o mesmo flash da linha 38 — RESET_THROTTLED nunca chega à tela, e a string traduzida lang/pt_BR/passwords.php:17 é código morto hoje. Em ForgotPassword.vue:31-44 o UiAlert é montado por `v-if="status"` e o conteúdo é estático; como o `back()` devolve o mesmo `status`, o componente nem remonta: não há mudança visual e, como UiAlert.vue:22 dá role="status" (não assertivo) com texto idêntico, também não há anúncio novo para leitor de tela. O botão da linha 40 só pisca o spinner. A escalada para 429 também procede: throttle:auth é 5/min por e-mail+IP (AppServiceProvider.php:29-31) e não há errors/429 nem tratamento em bootstrap/app.php:42-44, então a resposta cai no modal cru do Inertia (resources/js/app.js não registra handler). Media e não alta porque o primeiro envio já ocorreu — o prejuízo é confusão e cliques repetidos, não bloqueio.


## Severidade baixa

### 21. Receita do botão primário copiada à mão em 2 arquivos — e já divergiu

**Arquivo:** `resources/js/Pages/Projects/Show.vue:708` · **Dimensão:** Design system e consistência

**Problema.** UiButton só renderiza <button> ou <Link> do Inertia (UiButton.vue:63). Quando a página precisa de um <a> de verdade (download, carga completa), ela recopia a receita: Projects/Show.vue:708 escreve `h-control-md ... rounded-md bg-brand-600 px-4 text-sm font-medium text-ink-inverse hover:bg-brand-700` e resources/views/errors/403.blade.php:25 escreve `h-control-lg ... bg-brand-600 px-4 text-sm font-semibold`. A segunda cópia JÁ está diferente do original: UiButton size=lg é `px-5 text-base font-medium` (UiButton.vue:33 + :23), o 403 usa `px-4 text-sm font-semibold`.

**Impacto.** Quem cai no 403 (cliente final que abriu link da área errada — o público sem treino) vê um botão "Entrar" que não é o botão do sistema: texto menor e mais pesado. E qualquer mudança em --color-brand-600/700 ou na altura dos controles deixa esses dois botões para trás, sem nenhum aviso de build.

**Correção.** Adicionar prop `external: Boolean` ao UiButton: quando true, `:is` resolve para 'a' em vez de Link e repassa href/download/target. Trocar Projects/Show.vue:706-712 por `<UiButton external :href="..." icon="download">`. No 403.blade.php, como Blade não tem acesso ao componente Vue, no mínimo alinhar a string com UiButton lg (`h-control-lg px-5 text-base font-medium`).

> **Verificação:** Fato confere em parte. UiButton.vue:63 realmente só resolve para `Link` ou `button`, e existem duas cópias da receita. MAS a cópia Vue (Projects/Show.vue:708) é idêntica ao primary/md do componente (UiButton.vue:23 + :32 + :44), não divergiu, e tem comentário explicando a escolha (Projects/Show.vue:704-705). A divergência real está só em resources/views/errors/403.blade.php:25 (`h-control-lg px-4 text-sm font-semibold` contra `px-5 text-base font-medium`) — arquivo Blade que, como a própria correção admite, não consome componente Vue e continuará duplicado de qualquer jeito. Impacto: um botão de página de erro estática com 14px/semibold em vez de 16px/medium. Longe de 'alta'.


### 22. Duas cores de token congeladas como literal hexadecimal

**Arquivo:** `resources/js/Components/UiSelect.vue:72` · **Dimensão:** Design system e consistência

**Problema.** UiSelect.vue:72 embute a seta como data-URI com `stroke='%23667085'` — o valor cru de --color-ink-muted (app.css:34). É o único hex do frontend Vue além de resources/js/app.js:36, onde a barra de progresso do Inertia usa `color: '#0f172a'` — que não é cor nenhuma do sistema (o ink é #101828, o brand-600 é #4f46e5; #0f172a é o slate-900 do Tailwind, resto do tema pré-redesign).

**Impacto.** Trocar --color-ink-muted (que é o PISO de contraste, 4,9:1 — é o token com mais chance de ser ajustado) muda o rótulo, o placeholder e o hint de todos os campos, mas não muda a seta de nenhum select: a interface fica com dois cinzas. E a barra de carregamento, que aparece em toda navegação das duas áreas, é a única coisa na tela pintada com uma cor que não existe na paleta.

**Correção.** No UiSelect, trocar o background-image por `stroke='currentColor'` não funciona em data-URI; usar `mask-image` com a mesma URI e `background-color: var(--color-ink-muted)` resolve e passa a seguir o token. Em app.js:36, usar o brand-600 (#4f46e5) ou ler a variável: `getComputedStyle(document.documentElement).getPropertyValue('--color-brand-600')`.

> **Verificação:** Verificado: UiSelect.vue:72 embute `stroke='%23667085'`, que é o valor cru de --color-ink-muted (app.css:34), e app.js:36 usa `color: '#0f172a'`, que não é token nenhum (ink é #101828, brand-600 é #4f46e5). Ressalvas que derrubam a severidade: o hex do UiSelect é limitação técnica de data-URI (`var()` não interpola dentro de `url()`), está anotado no comentário da linha 70 e casa exatamente com o token — sobra risco de manutenção, não defeito visível. O de app.js pinta a barra de progresso do Inertia (2px no topo) de navy em vez de indigo. Nenhum dos dois atrapalha ninguém.


### 23. O mesmo filtro de lista existe em duas linguagens visuais diferentes

**Arquivo:** `resources/js/Pages/Admin/Users/Index.vue:230` · **Dimensão:** Design system e consistência

**Problema.** "Filtrar a lista por situação, com contagem" é resolvido de dois jeitos: com UiTabs variant="segmented" em Admin/Companies/Index.vue:203 e Imports/Show.vue:164 (fundo surface-muted, pílula interna, role=tab/tabpanel), e com chips redondos escritos à mão em Admin/Users/Index.vue:230-245 (`h-control-sm rounded-full border`, `aria-pressed`, borda brand-600 quando ativo). Os dois mostram rótulo + contagem e fazem exatamente a mesma coisa.

**Impacto.** É o mesmo operador Admin WeGift alternando entre Usuários, Empresas e Importações no mesmo dia. Ele aprende o controle segmentado em duas telas e encontra um controle diferente na terceira — sem que a diferença signifique nada. Além disso o chip de Users não tem o comportamento de teclado das abas (←/→, Home/End) que UiTabs.vue:30-42 implementa.

**Correção.** Trocar o bloco Users/Index.vue:229-246 por `<UiTabs v-model="situation" :tabs="filters" variant="segmented" panel-prefix="usuarios" />` (o UiTabs já aceita `count` em cada aba, UiTabs.vue:86) e envolver a tabela na `<section role="tabpanel">` como Companies/Index.vue:212-218 já faz. Se o padrão desejado for o chip, então ele vira um componente e as outras duas telas migram — mas não os dois convivendo.

> **Verificação:** Confere: Admin/Companies/Index.vue:203-209 e Imports/Show.vue:164 usam UiTabs variant=segmented (fundo surface-muted, pílula, role=tab + <section role=tabpanel> em Companies/Index.vue:212-218), enquanto Admin/Users/Index.vue:229-246 escreve chips `h-control-sm rounded-full border` com aria-pressed. Os três mostram rótulo + contagem e filtram uma lista. Inconsistência real de linguagem visual. Ressalva: o chip com role=group + aria-pressed é padrão ARIA válido (e discutivelmente mais correto que 'aba' para filtro de tabela), então o defeito é a coexistência, não o chip; e nada impede o operador de concluir a tarefa — é incômodo de aprendizado, não bloqueio.


### 24. Altura de controle: 3 tokens existem, mas 4 valores crus circulam pelo código

**Arquivo:** `resources/css/app.css:82` · **Dimensão:** Design system e consistência

**Problema.** app.css:82-84 define control-sm/md/lg (36/40/48px) e botão, input, select, paginação e chips usam `h-control-*`. Mas a mesma decisão é escrita à mão em: UiTabs.vue:48 e :54 (`h-10`), UiCheckbox.vue:15 (`min-h-10`), UiMenu.vue:179 (`min-h-10`), PageHeader.vue:42 (`min-h-9` / `min-h-12`), ShipControl.vue:194 e AppLayout.vue:246 (`min-h-12`), Imports/Index.vue:156 e Imports/Show.vue:260 (`min-h-9`). Pior: o alvo de 44px do portal — PortalLayout.vue:67, AuthCard.vue:35, Dashboard.vue:115, Portal/Redemptions.vue:144, Portal/Redeem.vue:226, :247 e :326 — usa `min-h-11`, um valor que NÃO tem token: a escala pula de 40 para 48.

**Impacto.** O valor que mais importa (44px, o mínimo de toque que o portal precisa garantir no celular do cliente final) é justamente o que ninguém pode verificar: não existe `--spacing-control-touch`, então cada tela repete `min-h-11` de memória e um esquecimento não quebra nada visível em desktop. Ajustar a densidade da área operacional também exige caçar `h-10`/`min-h-9` em 9 arquivos.

**Correção.** Adicionar `--spacing-control-touch: 2.75rem;` ao bloco de densidade (app.css:82-85) e substituir os 7 `min-h-11` por `min-h-control-touch`. Trocar `h-10` por `h-control-md` em UiTabs.vue:48,:54, `min-h-10` por `min-h-control-md` em UiCheckbox.vue:15 e UiMenu.vue:179, e `min-h-9`/`min-h-12` por `min-h-control-sm`/`min-h-control-lg` em PageHeader.vue:42, ShipControl.vue:194, AppLayout.vue:246, Imports/Index.vue:156, Imports/Show.vue:260.

> **Verificação:** Conferi linha por linha e todas batem: app.css:82-84 define control-sm/md/lg (2.25/2.5/3rem) e o valor cru equivalente aparece em UiTabs.vue:48 e :54 (h-10), UiCheckbox.vue:15 e UiMenu.vue:179 (min-h-10), PageHeader.vue:42 (min-h-9/min-h-12), ShipControl.vue:194, AppLayout.vue:246, Imports/Index.vue:156 e Imports/Show.vue:260. E `min-h-11` (44px, o alvo de toque do portal) aparece exatamente nos 7 lugares citados — PortalLayout.vue:67, AuthCard.vue:35, Dashboard.vue:115, Portal/Redemptions.vue:144, Portal/Redeem.vue:226, :247 e :326 — sem token correspondente: a escala pula de 40 para 48. Como os valores crus são numericamente iguais aos tokens, hoje não há divergência visual; o risco é de manutenção (mexer no token não alcança 9 lugares). Daí baixa, não média.


### 25. UiSectionHeading é usado por uma página só; o resto escreve o título à mão

**Arquivo:** `resources/js/Components/UiSectionHeading.vue:12` · **Dimensão:** Design system e consistência

**Problema.** UiSectionHeading.vue só é importado pelo Dashboard.vue:11. Existem hoje TRÊS tratamentos de título de seção sem componente que os reúna: (a) `text-base font-semibold text-ink` do UiSectionHeading; (b) `text-sm font-semibold text-ink` de UiCard.vue:26, UiModal.vue:228, UiDrawer.vue:61, UiAlert.vue:37, Companies/Index.vue:228, Projects/Show.vue:700/728/766; (c) o "eyebrow" `text-xs font-semibold uppercase text-ink-muted`, que aparece em 11 lugares COM DUAS tracking diferentes: `tracking-wide` em UiFormSection.vue:23, AppLayout.vue:162 e :236, Companies/Index.vue:266 — e `tracking-wider` em UiTable.vue:144, UiRowErrors.vue:39/45, Portal/Redeem.vue:311/318, Portal/Redemptions.vue:129/155, Portal/Catalog.vue:90, Projects/Show.vue:807, Auth/ResetPassword.vue:51, Auth/SetPassword.vue:53.

**Impacto.** O eyebrow é o rótulo que organiza o portal ("Presente", "Vai para", "Código de rastreio") — o cliente final lê esses quatro rótulos e nada mais. Eles saem com espacejamento diferente do eyebrow do cabeçalho de tabela e do UiFormSection na mesma sessão. E não existe um lugar para mudar isso.

**Correção.** Criar `UiEyebrow.vue` (ou uma prop `size="eyebrow"` no UiSectionHeading) com a versão única — escolher `tracking-wider`, que é maioria (11 usos contra 4) — e substituir os 15 casos. Fazer UiFormSection.vue:23 e UiTable.vue:144 consumirem o mesmo componente, e usar UiSectionHeading nas seções de Projects/Show.vue:700/728/766 e Companies/Index.vue:228.

> **Verificação:** Confirmo apenas a parte do eyebrow, que é verificável e a contagem bate: `tracking-wide` em 4 lugares (UiFormSection.vue:23, AppLayout.vue:162 e :236, Companies/Index.vue:266) contra `tracking-wider` em 11 (UiTable.vue:144, UiRowErrors.vue:39 e :45, Portal/Redeem.vue:311 e :318, Portal/Redemptions.vue:129 e :155, Portal/Catalog.vue:90, Projects/Show.vue:807, Auth/ResetPassword.vue:51, Auth/SetPassword.vue:53). Duas medidas para o mesmo rótulo repetido 15 vezes — vale unificar em tracking-wider. O resto do achado não se sustenta: os títulos de Projects/Show.vue:700/728/766 são <h3> de passo dentro de uma <ol> numerada e Companies/Index.vue:228 é o <h2> do cartão da empresa; nenhum é cabeçalho de seção, e trocar por UiSectionHeading (que impõe `mt-8 mb-3` e text-base, UiSectionHeading.vue:10-12) quebraria os dois layouts. O grupo (b) é cabeçalho de componente (UiCard.vue:26, UiModal, UiDrawer.vue:61), outro nível hierárquico, não um terceiro tratamento do mesmo elemento.


### 26. Rádio não existe no design system e foi escrito à mão de duas formas

**Arquivo:** `resources/js/Components/ShipControl.vue:176` · **Dimensão:** Design system e consistência

**Problema.** Existem UiCheckbox.vue e UiCheckboxGroup.vue, mas nenhum UiRadio. As duas telas que precisam de rádio inventaram cada uma a sua: ShipControl.vue:176-204 usa `input.peer.sr-only` + caixa desenhada com `min-h-12 border-brand-600 bg-brand-50` e anel de foco por `peer-focus-visible`; Admin/Users/Index.vue:320-344 usa o input nativo visível (`size-4 accent-brand-600`, o mesmo de UiCheckbox.vue:23) dentro de um cartão `rounded-md border p-3`. Marcador, altura, foco e comportamento de seleção são diferentes nos dois.

**Impacto.** São as duas escolhas mais caras do sistema — transportadora do resgate (errar = brinde sem rastreio) e perfil de acesso do usuário. Uma delas tem o ponto do rádio visível, a outra não tem marcador nenhum além da cor de fundo (ShipControl depende só de borda+fundo brand para indicar seleção, o que também é frágil para quem não distingue bem cor).

**Correção.** Criar `UiRadioGroup.vue` espelhando a API do UiCheckboxGroup (`options`, `columns`, integração com `inject('uiField')`) com duas variantes: `card` (a de Users, com input nativo visível) e `segmented` (a de ShipControl). Migrar ShipControl.vue:175-205 e Users/Index.vue:313-346 para ele — e no caminho, garantir marcador visível na variante segmented, não só a cor.

> **Verificação:** Confere: não há UiRadio, e as duas telas resolveram diferente — ShipControl.vue:177-204 usa `input.peer.sr-only` com caixa `min-h-12` e foco via peer-focus-visible; Admin/Users/Index.vue:320-344 usa o input nativo visível (`size-4 accent-brand-600`, o mesmo de UiCheckbox.vue:23) dentro de cartão `rounded-md border p-3`. Ressalvas: são dois casos de uso legitimamente distintos (3 opções curtas em grade × opção com descrição longa), e sistemas maduros mantêm 'radio card' e 'segmented' como componentes separados — a proposta de um só componente com duas variantes é razoável, mas não é um defeito hoje. O ponto que realmente merece correção é menor e específico: na variante do ShipControl a seleção é sinalizada só por cor (border-brand-600 + bg-brand-50 + text-brand-700), sem marcador.


### 27. Quatro `<details>` escritos à mão, quatro comportamentos

**Arquivo:** `resources/js/Components/CompanyForm.vue:231` · **Dimensão:** Design system e consistência

**Problema.** O sistema tem 4 divulgações progressivas, nenhuma componentizada: Imports/Index.vue:155-164 e Imports/Show.vue:259-268 são idênticas (`min-h-9`, chevron-right à ESQUERDA girando 90°, texto ink-muted, marcador nativo escondido); Portal/Redemptions.vue:143-148 usa `min-h-11`, chevron-down à DIREITA girando 180°, texto brand-700; CompanyForm.vue:231-234 não esconde o marcador nativo, não tem chevron nenhum e não tem altura mínima — mostra o triângulo do navegador.

**Impacto.** O operador vê o triângulo cru do navegador no formulário de empresa e um chevron desenhado nas telas de importação, sem que a diferença signifique nada. O `summary` do CompanyForm também não tem alvo de toque garantido (só `py-1`, ~28px de altura).

**Correção.** Criar `UiDisclosure.vue` com props `label` e `size` (sm para a área operacional, lg para o portal), padronizando chevron, giro, `list-none`, `[&::-webkit-details-marker]:hidden` e altura mínima por token. Substituir os 4 usos. Se a divergência portal/operacional for intencional, ela vira prop — não texto solto em cada arquivo.

> **Verificação:** Confirmado com ressalva no número. São 4 usos e 3 tratamentos: Imports/Index.vue:154-164 e Imports/Show.vue:258-268 são idênticos (o próprio achado admite), e a diferença do Portal/Redemptions.vue:142-148 (min-h-11, chevron-down à direita, brand-700) é coerente com o padrão do portal — alvo maior e texto base, como PortalLayout.vue:67 e Redeem.vue:226. O outlier real é CompanyForm.vue:231-234: o <summary> não tem `list-none` nem `[&::-webkit-details-marker]:hidden`, então mostra o triângulo nativo do navegador enquanto os outros três mostram chevron do UiIcon; também não tem altura mínima (py-1 + text-sm ≈ 28px). Um componente resolveria, mas o defeito concreto é uma tela, não quatro.


### 28. UiSearchInput e UiPagination reescrevem por dentro as receitas de UiInput e UiButton

**Arquivo:** `resources/js/Components/UiSearchInput.vue:65` · **Dimensão:** Design system e consistência

**Problema.** UiSearchInput.vue:65 escreve o campo inteiro à mão — `h-control-md block w-full rounded-md border border-line-strong bg-surface ... shadow-xs transition-colors placeholder:text-ink-muted hover:border-ink-subtle` — que é a string de UiInput.vue:62-69 menos os estados `disabled:` e `invalid`. UiPagination.vue:126 usa `'border border-line-strong bg-surface text-ink shadow-xs hover:bg-surface-muted'`, idêntico caractere por caractere ao variant `secondary` de UiButton.vue:24, e a linha 133 repete o `primary` (`bg-brand-600 text-ink-inverse`).

**Impacto.** São 3 componentes do próprio design system reimplementando outros 2 componentes do design system. Mexer na borda dos campos ou na cor do botão secundário exige lembrar desses dois arquivos — e o campo de busca, que está em 6 telas (Products, Benefits, Users, Companies, Imports/Show, Projects/Show), é o único input do sistema sem estado desabilitado e sem estado inválido.

**Correção.** UiSearchInput passa a compor: `<UiInput type="search" :prefix-icon="'search'" v-model=...>` (exige adicionar suporte a ícone no prefix do UiInput, hoje só texto — UiInput.vue:48-53) e repassar `disabled`. UiPagination usa `<UiButton variant="secondary" size="md">` nos controles anterior/próxima e `variant="primary"` na página corrente, em vez das strings duplicadas de :126 e :133.

> **Verificação:** Duplicação verificada caractere por caractere: UiSearchInput.vue:65 é a string de UiInput.vue:62-69 menos `disabled:` e `invalid`; UiPagination.vue:126 é idêntico ao variant secondary de UiButton.vue:24. Ressalva importante: a correção proposta para a paginação não se aplica como está — UiPagination precisa renderizar `<span aria-disabled>` nos extremos inalcançáveis (UiPagination.vue:86-89) e `<Link preserveScroll>` no modo servidor (:92), e a página corrente é `bg-brand-600` sem hover de propósito; UiButton só resolve para button/Link (UiButton.vue:63) e o primary traz `hover:bg-brand-700` (UiButton.vue:23). O caminho viável é extrair a string comum para um lugar só, não trocar por <UiButton>. Impacto hoje: nenhum visual — é risco de deriva futura.


### 29. Duas tabelas e um alerta construídos à mão fora dos componentes que já existem

**Arquivo:** `resources/js/Components/UiRowErrors.vue:31` · **Dimensão:** Design system e consistência

**Problema.** UiRowErrors.vue:31-63 monta `<table>`, `<thead>`, `<th>` e `<tbody class="divide-y">` na mão, com o thead repetindo a classe de UiTable.vue:144. Consequência: não tem `bg-surface` no container (só borda), não tem o comportamento empilhado abaixo de 48rem que UiTable.vue:236-260 implementa, e não tem a sombra de borda de rolagem. E Portal/Redeem.vue:212-231 reconstrói o UiAlert de sucesso à mão (`rounded-lg border border-success-line bg-success-soft p-3` + ícone check + role=status), que é exatamente o que UiAlert.vue:33 + :16 produz com `variant="success"`.

**Impacto.** A tabela de erros de importação é lida quando algo deu errado com um lote de brindes — e é a única do sistema que não reflui no celular. O bloco verde de "CEP encontrado" é a confirmação mais importante do portal, e é o único aviso do sistema que não passa pelo UiAlert: se o alerta ganhar um ajuste (ícone, espaçamento, papel ARIA), ele fica para trás.

**Correção.** UiRowErrors passa a usar `<UiTable :columns="[{key:'line',label:'Linha',width:'5rem'},{key:'reason',label:'Motivo'}]" density="compact" caption="Linhas que falharam na importação">` e mantém só a lógica de parse (linhas 13-25). Em Redeem.vue:212-231, trocar por `<UiAlert variant="success">` com o texto do CEP e do logradouro dentro, e o botão "Não é esse endereço?" no slot `#actions`.

> **Verificação:** Duplicação existe: UiRowErrors.vue:31-63 monta table/thead/tbody na mão repetindo a classe de cabeçalho de UiTable.vue:144, e Portal/Redeem.vue:212-231 reconstrói o UiAlert success (`rounded-lg border border-success-line bg-success-soft p-3` + ícone check + role=status) no MESMO arquivo que usa UiAlert de verdade nas linhas 184, 236 e 334 — essa inconsistência interna é o ponto mais forte do achado. Mas duas justificativas são falsas: UiTable só empilha abaixo de 48rem quando `columns.length > 5` (UiTable.vue:31), então uma tabela de 2 colunas não ganharia empilhamento nenhum ao migrar; e a ausência de `bg-surface` no UiRowErrors é coerente com o contexto — ele vive dentro de um UiAlert colorido (Projects/Show.vue:811). Também não são 'duas tabelas': só há uma.


### 30. Estados de interação presentes em uns componentes e ausentes nos irmãos

**Arquivo:** `resources/js/Components/UiMenu.vue:179` · **Dimensão:** Design system e consistência

**Problema.** Casos concretos: (a) o item de menu em UiMenu.vue:179 tem `hover:bg-surface-muted` mas não tem `transition-colors`, enquanto praticamente todo hover do sistema tem (UiButton.vue:44, UiTabs.vue:78, AppLayout.vue:172); (b) a mesma navegação tem transição na sidebar do desktop (AppLayout.vue:172) e não tem no drawer do celular (AppLayout.vue:246); (c) UiInput tem `loading` (UiInput.vue:13) e UiSelect não, embora ambos sejam preenchidos pela busca de CEP (CompanyForm.vue:147-227 usa loading no CEP e nada no UF); (d) UiSearchInput não aceita `disabled` nem `invalid`, únicos entre os campos.

**Impacto.** O menu "⋯", que é o ponto de ação de toda linha de tabela, pisca a cor em vez de transicionar — o único controle do sistema que faz isso. E telas que precisam desabilitar a busca durante uma operação longa (Products/Index.vue:152, durante a importação da Omie) simplesmente não conseguem.

**Correção.** Acrescentar `transition-colors` a UiMenu.vue:179 e a AppLayout.vue:246. Adicionar as props `disabled` e `invalid` ao UiSearchInput (repassando ao input da linha 65 as classes `disabled:` de UiInput.vue:65) e a prop `loading` ao UiSelect, espelhando UiInput.vue:73-78.

> **Verificação:** Metade procede, metade não. Procede: UiMenu.vue:179 tem `hover:bg-surface-muted` sem `transition-colors`, e AppLayout.vue:246 (drawer do celular) também não tem, enquanto o irmão AppLayout.vue:172 e o resto do sistema têm (UiButton.vue:44, UiTabs.vue:78) — são duas classes faltando, efeito puramente cosmético. Não procede: (c) no fluxo do CEP quem sinaliza carregamento é o campo do CEP (CompanyForm.vue:155), e um segundo spinner no UF seria ruído — além de o UiSelect renderizar um `<select>` pelado (UiSelect.vue:40, inheritAttrs:false), sem wrapper onde pendurar o indicador; (d) nenhum chamador do UiSearchInput precisa de `disabled`/`invalid`, então acrescentá-los criaria exatamente a API morta que o achado 4 condena em UiCard.vue:8.


### 31. Token de texto usado como cor de borda, contrariando o próprio comentário do token

**Arquivo:** `resources/css/app.css:32` · **Dimensão:** Design system e consistência

**Problema.** app.css:32 documenta `--color-line-strong` como "borda de campo, hover de borda" e app.css:35 documenta `--color-ink-subtle` como "SÓ ícone decorativo, separador, travessão". Mas o hover de borda dos campos usa ink-subtle: UiInput.vue:68, UiSelect.vue:52 e UiSearchInput.vue:65 fazem `border-line-strong hover:border-ink-subtle`. UiCard.vue:15 e UiStat.vue:21, ao contrário, seguem o comentário e fazem `hover:border-line-strong`.

**Impacto.** A regra que torna o sistema de neutros verificável (nome = papel) deixa de valer no lugar mais repetido do sistema. Quem for auditar "ink-subtle só aparece em ícone e travessão?" encontra três campos e conclui que a regra não é seguida — ou ajusta ink-subtle e move a borda de todos os campos sem querer.

**Correção.** Ou promover um token novo (`--color-line-hover`) e usá-lo nos três campos, ou corrigir o comentário de app.css:32/35 para registrar que ink-subtle também é a borda de hover de campo. A segunda é mais barata; a primeira é a que mantém a regra P3 verificável.

> **Verificação:** Contradição real e verificável: app.css:32 documenta line-strong como 'borda de campo, hover de borda' e app.css:35 documenta ink-subtle como 'SÓ ícone decorativo, separador, travessão', mas o hover de borda dos três campos usa ink-subtle (UiInput.vue:68, UiSelect.vue:52, UiSearchInput.vue:65), enquanto UiCard.vue:15 e UiStat.vue:21 seguem o comentário. Como o comentário é o que torna a regra auditável, a inconsistência importa — mas só no papel: visualmente ink-subtle (#98a2b3) é mais escuro que line-strong (#cfd4dc), então o hover escurece a borda como se espera. Correção de uma linha de comentário (ou um token novo).


### 32. Polling reescreve a tabela a cada 10s sem mensagem de estado (SC 4.1.3)

**Arquivo:** `resources/js/Pages/Imports/Index.vue:68` · **Dimensão:** Acessibilidade (WCAG 2.2 AA)

**Problema.** Enquanto houver lote em `processing`, um `setInterval` chama `router.reload({ only: ['imports'] })` a cada 10 segundos (linhas 68-70) e a `<tbody>` inteira é substituída. O único sinal de que algo está em curso é o ponto `animate-pulse` da linha 96, que é `aria-hidden="true"`. Quando o lote termina, o StatusBadge muda de 'Processando' para 'Concluída' e nada avisa. Mesmo padrão em Products/Index.vue:78-92: o `UiAlert` de 'Buscando produtos na Omie' tem `role="status"` na entrada (UiAlert.vue:22), mas ele DESMONTA quando o polling para — e remoção de elemento não é anunciada, então a conclusão é muda.

**Impacto.** O operador que acabou de subir uma planilha de resgates e usa leitor de tela fica sem saber quando os pedidos foram criados na Omie. Ele não tem sinal nenhum além de reler a tabela de tempos em tempos, o que na prática significa esperar em branco ou recarregar a página à toa — e essa importação é a ação com efeito irreversível fora do sistema.

**Correção.** Em Imports/Index.vue, adicionar uma região viva permanente logo acima da UiTable, alimentada por um computed do estado do polling: `<p aria-live="polite" class="sr-only">{{ anyProcessing ? `${processingCount} importação em processamento` : 'Nenhuma importação em processamento' }}</p>`. Como o container existe desde o primeiro render, a troca de texto é anunciada. Em Products/Index.vue, não desmontar o aviso na conclusão: trocar o conteúdo do `UiAlert` para 'Catálogo atualizado' com `variant="success"` e deixar o operador fechá-lo, em vez de fazer o bloco sumir.

> **Verificação:** A metade principal procede: Imports/Index.vue:62-73 mantém `setInterval` de 10s com `router.reload({ only: ['imports'] })` enquanto houver `processing`, a tbody inteira é substituída (linhas 91-117), o único sinal é o ponto `animate-pulse` com `aria-hidden="true"` (linha 96) e não existe região viva na página — a virada de StatusBadge 'Processando'→'Concluída' é muda. Mas a metade sobre Products/Index.vue está errada: o `UiSearchInput` recebe `:result-label="resultLabel"` (Products/Index.vue:155), que é `${plural(summary.total,...)} · ${plural(summary.kits,...)}` (linhas 50-53) e é renderizado dentro de um `<span aria-live="polite">` (UiSearchInput.vue:80-86). Como o polling só para quando `summary.total` muda (Products/Index.vue:100-105), a conclusão bem-sucedida JÁ é anunciada pela contagem nova — não é muda. Rebaixo para baixa: informação de progresso não anunciada em lista passiva; o operador recupera o estado relendo a linha a qualquer momento, nada se perde.


### 33. Anel de foco das abas é recortado pelo overflow do container

**Arquivo:** `resources/js/Components/UiTabs.vue:64` · **Dimensão:** Acessibilidade (WCAG 2.2 AA)

**Problema.** O invólucro é `<div class="scrollbar-slim overflow-x-auto">` (linha 64) e, na variante `underline` (padrão), o `role="tablist"` interno é `'flex gap-1 border-b border-line'` (linha 55) — sem padding nenhum. O foco global é `outline: 2px` com `outline-offset: 2px` (app.css:103-107), ou seja, ocupa 4px FORA do botão. Como `overflow-x: auto` faz o eixo Y computar para `auto` também, o anel é cortado em cima, embaixo e na lateral esquerda da primeira aba. A variante `segmented` escapa por pouco, com `p-1` (4px) na linha 47.

**Impacto.** Quem navega por teclado nas abas de Projeto, Importação, Empresas e Modelos de e-mail vê o indicador de foco pela metade ou quase nada nas abas das pontas — que são justamente as mais usadas. Como as abas trocam o conteúdo principal da tela, perder de vista onde o foco está custa uma navegação inteira às cegas.

**Correção.** Dar folga ao invólucro para o anel caber: em UiTabs.vue:64, trocar por `class="scrollbar-slim -mx-1 overflow-x-auto px-1 py-1"` e compensar o deslocamento do `border-b` movendo a divisória para o próprio invólucro. Alternativa sem mexer no layout: reduzir o offset só aqui, com `[&_[role=tab]]:focus-visible:outline-offset-0` no invólucro — o anel encosta no botão mas fica inteiro.

> **Verificação:** Confirmado: UiTabs.vue:64 é `overflow-x-auto` e, por CSS Overflow, `overflow-y: visible` computa para `auto` quando o outro eixo não é visible — o invólucro vira container de rolagem e recorta na padding box; outline não entra na região de rolagem, então o anel some. Na variante underline o tablist é `flex gap-1 border-b border-line` sem padding (linha 53) e o botão é `h-10 -mb-px` (linha 54), colado no topo e na base do invólucro; com `outline: 2px` + `outline-offset: 2px` (app.css:103-107) são 4px fora da borda, recortados em cima, embaixo e à esquerda da primeira aba. A leitura da variante `segmented` também está certa: `p-1` = exatos 4px (linha 47), cabe raspando. Rebaixo para baixa: os segmentos laterais do anel continuam visíveis (SC 2.4.7 não falha; aparência do foco é AAA em 2.2) — é degradação estética do indicador, não perda dele.


### 34. Região de notificações com aria-atomic relê todos os avisos a cada aviso novo

**Arquivo:** `resources/js/Components/Toaster.vue:120` · **Dimensão:** Acessibilidade (WCAG 2.2 AA)

**Problema.** `<div aria-live="polite" aria-atomic="true">` envolve a lista inteira de toasts, e a fila mantém até 3 simultâneos (useToast.js:11, `MAX_VISIBLE = 3`). Com `aria-atomic="true"` no container, qualquer inserção faz o leitor de tela reanunciar TODO o conteúdo da região, não só o item novo. Somado a isso, o container é `polite` mesmo para a variante `error` (Toaster.vue:17), que deveria interromper.

**Impacto.** O operador que salva três coisas em sequência ouve a primeira mensagem três vezes, a segunda duas e a terceira uma — 6 anúncios para 3 eventos, sobrepondo o que ele estivesse fazendo. E um erro enfileirado atrás de dois sucessos pode nunca chegar a ser ouvido antes dos 6 segundos de expiração (Toaster.vue:8).

**Correção.** Tirar o `aria-atomic="true"` da linha 120 e colocá-lo em cada toast (no `<div v-for>` da linha 121), que é a unidade que deve ser lida por inteiro. Para a urgência, separar em duas regiões: manter esta como `polite` e adicionar uma irmã com `aria-live="assertive"` que receba só `toasts.filter(t => t.variant === 'error')`.

> **Verificação:** A parte principal é tecnicamente correta: `<div aria-live="polite" aria-atomic="true">` envolve o `v-for` inteiro (Toaster.vue:120-121) e a fila guarda até 3 (useToast.js:11/23), então cada inserção reanuncia toda a região. A correção proposta (mover o aria-atomic para o item do v-for) é a certa. Duas ressalvas que reduzem o peso: como `aria-relevant` tem default 'additions text', a expiração automática dos 6s (Toaster.vue:24-27) não gera reanúncio; e a segunda metade do achado é letra morta — a variante `error` não é usada em lugar nenhum do código (classifyStatus só devolve 'warning'|'success', useToast.js:37, e as duas chamadas diretas são `toast.warning`, Admin/Users/Index.vue:187 e Projects/Show.vue:257), logo não há nada a promover para assertive. Baixa mantida.


### 35. aria-current="page" em aba faz o leitor anunciar "página atual" onde não há navegação

**Arquivo:** `resources/js/Components/UiTabs.vue:74` · **Dimensão:** Acessibilidade (WCAG 2.2 AA)

**Problema.** O botão de aba já declara `:aria-selected` corretamente na linha 73, que é o estado do padrão `tab`. A linha 74 adiciona `:aria-current="tab.key === modelValue ? 'page' : null"`. O valor `page` significa 'esta é a página atual dentro de um conjunto de páginas' — abas não navegam, elas trocam painel na mesma página. Os dois atributos juntos fazem o leitor anunciar duas vezes o mesmo estado, sendo que uma delas com a palavra errada.

**Impacto.** Em Projetos, Importações, Empresas e Modelos de e-mail o operador com leitor de tela ouve 'Clientes finais, guia, selecionada, página atual' e fica sem saber se acabou de mudar de tela ou só de aba. Confunde a construção do modelo mental da navegação.

**Correção.** Apagar a linha 74 do UiTabs.vue. O `aria-selected` da linha 73 já cumpre o papel, e o `:tabindex` da linha 76 já implementa a tabulação correta do padrão (só a aba ativa é focável).

> **Verificação:** Confirmado: UiTabs.vue:73 já declara `aria-selected` e a linha 74 acrescenta `aria-current="page"` no mesmo botão `role="tab"` (linha 71), que não navega — só troca painel via `emit('update:modelValue')` (linha 26). NVDA/JAWS verbalizam aria-current, então o estado sai duplicado e com o termo errado. Verifiquei também que remover a linha não quebra nada: o estilo ativo vem da comparação com `modelValue` (UiTabs.vue:80), não de seletor `[aria-current]`, e não há nenhum CSS no projeto usando esse atributo; os usos legítimos de aria-current='page' seguem intactos em AppLayout.vue:170/244, PortalLayout.vue:65/106 e UiPagination.vue:108. Impacto é verbosidade, não barreira — baixa.


### 36. Botão "Continuar" do portal nasce desabilitado e a explicação não está ligada a ele

**Arquivo:** `resources/js/Pages/Portal/Redeem.vue:299` · **Dimensão:** Acessibilidade (WCAG 2.2 AA)

**Problema.** `<UiButton type="submit" size="lg" block :disabled="!canAdvance">` — e `canAdvance` só fica verdadeiro com CEP completo E número preenchido (linha 68). Botão com `disabled` sai da ordem de tabulação (UiButton.vue:57). O texto que explica o motivo, `<p v-if="!canAdvance">Informe o CEP e o número para continuar.</p>` (linhas 302-304), é um parágrafo solto: não está em `aria-describedby` do botão nem em região viva, e aparece/some sem anúncio.

**Impacto.** O colaborador que navega por teclado ou leitor de tela tabula pelo formulário, chega ao último campo (Complemento) e o próximo Tab pula direto para a navegação inferior — o botão de avançar simplesmente não existe para ele. Sem treino e usando a tela uma única vez, a conclusão natural é que a página quebrou. É a única ação da jornada de resgate; perder o botão aqui é perder o brinde.

**Correção.** Manter o botão sempre habilitado e mover a checagem para o `advance()` (linha 102), que já retorna cedo quando `!canAdvance` — basta acrescentar a marcação do erro nos campos faltantes (`form.setError('zip', ...)`), que o UiField já anuncia por `role="alert"` (UiField.vue:73). Se a preferência for manter o `disabled`, no mínimo dar `:id` ao parágrafo das linhas 302-304, referenciá-lo com `aria-describedby` no botão e envolvê-lo em `role="status"` para que a mudança seja anunciada.

> **Verificação:** Os fatos batem: Portal/Redeem.vue:299 usa `:disabled="!canAdvance"`, o UiButton repassa para o atributo nativo `disabled` (UiButton.vue:57) e `canAdvance` exige CEP completo + número (linha 68); o parágrafo das linhas 302-304 não tem id, não está em aria-describedby e não está em região viva, então a troca de estado não é anunciada. Efeito colateral que o revisor não citou e é real: com o único submit desabilitado, o Enter dentro dos campos não submete o formulário. Rebaixo para baixa porque os atenuantes são fortes: o parágrafo é irmão imediato do botão na ordem do DOM, então em modo de navegação (NVDA/JAWS) e por varredura no VoiceOver/TalkBack o botão sai como 'indisponível/esmaecido' seguido da explicação; e `canAdvance` só depende dos dois campos obrigatórios que a pessoa está preenchendo de qualquer jeito, então ninguém fica preso. É falta de anúncio da mudança, não bloqueio, e nenhum critério AA de 2.2 é descumprido.


### 37. Código de rastreio em 12px e sem link para rastrear

**Arquivo:** `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redemptions.vue:133` · **Dimensão:** Portal do cliente final (mobile-first)

**Problema.** O `<UiCode :value="item.tracking_code" copyable />` renderiza `font-mono text-xs` (UiCode.vue:42), ou seja 12px, dentro de um layout que declarou `text-base` justamente para o portal (PortalLayout.vue:92). Ao lado aparece só o nome da transportadora em `text-sm`. Não há link para lugar nenhum: a pessoa recebe "AA123456789BR" e precisa descobrir sozinha onde colar isso. O botão "Copiar" ajuda quem já sabe para onde ir; ninguém que usa isso uma vez na vida sabe.

**Impacto.** O dado mais importante da tela de acompanhamento é o menor e o menos acionável. Cada pessoa que não consegue rastrear sozinha vira uma pergunta para o RH da empresa cliente — que repassa para a WeLog.

**Correção.** 1) No portal, exibir o código em `text-base` (adicionar uma prop `size` ao UiCode em vez de fixar `text-xs`). 2) Criar em lib/format.js, ao lado de `carrierLabels`, um `carrierTrackingUrls` e renderizar um UiButton secundário `size="lg"` com `icon-trailing="external"` e rótulo "Rastrear nos Correios" (ou na transportadora correspondente), abrindo em nova aba. O ícone `external` já existe no catálogo.

> **Verificação:** A parte tipográfica confere: UiCode.vue:42 fixa `font-mono text-xs` (12px) e é usado sem alternativa em Redemptions.vue:133, dentro de um `main` que declara `text-base` justamente para o portal (PortalLayout.vue:92) — o dado mais importante do cartão é o menor texto dele.

A segunda metade é pedido de funcionalidade e só é parcialmente viável: `carrierLabels` (lib/format.js:44-48) tem `correios`, `mrb` e `ddm`; URL pública de rastreio existe para Correios, mas MRB e DDM Log deixariam o botão inconsistente ou morto para 2 de 3 transportadoras. E o código já é copiável com o valor completo exposto a leitor de tela (UiCode.vue:45-51). Por isso baixa, escopo = tamanho do texto.


### 38. A mesma mensagem de erro aparece embaixo dos dois campos de senha

**Arquivo:** `D:\Projetos\welog-staging\resources\js\Pages\Auth\SetPassword.vue:83` · **Dimensão:** Portal do cliente final (mobile-first)

**Problema.** Os dois UiField usam `:error="form.errors.password"` (linhas 59 e 83) e `:invalid="!!form.errors.password"` nos dois inputs. Como o Laravel devolve tanto "mínimo 8 caracteres" quanto "a confirmação não confere" na chave `password`, um erro de tamanho pinta de vermelho e acusa também o campo "Confirmar senha", que não tem defeito nenhum. E como o UiField renderiza a mensagem com `role="alert"` (UiField.vue:73), o leitor de tela anuncia o mesmo texto duas vezes.

**Impacto.** Numa tela onde a pessoa já está insegura, dois campos vermelhos com a mesma frase sugerem dois problemas. Ela apaga tudo e recomeça.

**Correção.** Deixar `form.errors.password` só no primeiro campo e usar `:error="form.errors.password_confirmation"` no segundo. Para o caso da regra `confirmed`, que só popula `password`, exibir a mensagem uma vez, sob o campo "Confirmar senha" — que é onde a correção acontece.

> **Verificação:** Confirmado nos dois lados. Backend: `'password' => ['required', 'confirmed', PasswordRule::min(8)]` (PasswordController.php:80) — tanto tamanho quanto divergência caem na chave `password`, e `password_confirmation` nunca recebe erro. Frontend: SetPassword.vue:59 e :83 usam `:error="form.errors.password"` e :66/:88 usam `:invalid` do mesmo erro, então uma senha curta pinta de vermelho o campo "Confirmar senha", que não tem defeito, e o UiField renderiza a mensagem duas vezes com `role="alert"` (UiField.vue:73) — leitor de tela anuncia em duplicidade.

Ressalva à correção proposta: usar `form.errors.password_confirmation` no segundo campo é inerte com as regras atuais; o que resolve é exibir a mensagem uma única vez.


### 39. Aviso flutuante cobre a barra de navegação inferior no celular

**Arquivo:** `D:\Projetos\welog-staging\resources\js\Components\Toaster.vue:118` · **Dimensão:** Portal do cliente final (mobile-first)

**Problema.** O contêiner do Toaster é `fixed inset-x-0 bottom-4` até o breakpoint `lg`, e o cartão do aviso tem `pointer-events-auto`. No portal, a barra de navegação fica em `bottom-0` com 56px + safe area (PortalLayout.vue:97-99). Ou seja: qualquer toast em tela estreita fica exatamente em cima de "Meu presente" e "Entregas" e intercepta o toque por até 6 segundos. Hoje isso quase não aparece porque a Redemptions cancela o toast do resgate no `onMounted` (linhas 37-44), mas qualquer flash novo no portal cai nesse buraco.

**Impacto.** Toque perdido na navegação logo depois de uma ação — a pessoa acha que o botão não funciona e toca de novo.

**Correção.** No contêiner do Toaster, subir a base em telas estreitas: `bottom-[calc(5rem+env(safe-area-inset-bottom))] sm:bottom-4 lg:bottom-auto`. Acima de `sm` a barra do portal já não existe, então nada muda para a área operacional.

> **Verificação:** Geometria confirmada: o contêiner do Toaster é `fixed inset-x-0 bottom-4 z-60` até `lg` (Toaster.vue:118) e o cartão tem `pointer-events-auto` (Toaster.vue:125); a barra do portal é `fixed inset-x-0 bottom-0 z-30` com itens de `h-14` (PortalLayout.vue:97-108). Um toast de ~56px começando a 16px do fim cobre a maior parte da faixa de 56px da barra e, com z-index maior e ponteiro ativo, intercepta o toque em "Meu presente"/"Entregas" pelos 6s do `DURATION` (Toaster.vue:8).

Severidade baixa porque hoje o único flash do portal é o do resgate, dispensado no `onMounted` da Redemptions (Redemptions.vue:37-44) — é dívida latente, não incidente atual.


### 40. Três dos quatro drawers de formulário abrem com o foco no botão "Fechar", não no primeiro campo

**Arquivo:** `resources/js/Components/UiModal.vue:61` · **Dimensão:** Formulários, validação e feedback

**Problema.** `focusFirst()` (linhas 61-64) prefere `[data-autofocus]` e, na falta dele, pega o primeiro elemento focável do painel. No UiDrawer o primeiro focável é sempre o botão X do cabeçalho (UiDrawer.vue:67-76), porque o cabeçalho vem antes do corpo. Só Admin/Users/Index.vue:293 usa `data-autofocus`. Companies/Index.vue:319 (CompanyForm), Projects/Show.vue:623 (cadastro individual) e ShipControl.vue:174 (expedição) abrem com o foco no X.

**Impacto.** Quem cadastra empresas em série tem que ir ao mouse ou dar um Tab extra a cada abertura. Para teclado e leitor de tela é pior: a primeira coisa anunciada ao abrir "Nova empresa" é "Fechar, botão", e um Enter reflexo fecha o painel que acabou de abrir.

**Correção.** Marcar o primeiro campo de cada drawer com `data-autofocus` (`<UiInput v-model="form.name" data-autofocus />` em CompanyForm.vue:81 e Projects/Show.vue:631; o primeiro rádio de transportadora em ShipControl.vue:185). Alternativa central: em `focusFirst()`, procurar primeiro `input, select, textarea` e só cair no `focusables()[0]` quando não houver campo.

> **Verificação:** Confere. `focusFirst()` (UiModal.vue:61-64) prefere `[data-autofocus]` e, sem ele, pega `focusables()[0]`, que no UiDrawer é sempre o botão X do cabeçalho (UiDrawer.vue:67-76), renderizado antes do corpo (linha 79). Só Admin/Users/Index.vue:293 usa `data-autofocus`; CompanyForm.vue:80, Projects/Show.vue:631 e os rádios de ShipControl.vue:185 não usam. Rebaixo de média para baixa: o APG admite foco inicial no primeiro focável do diálogo, o custo é um Tab, e o risco de fechar sem querer com Enter é inócuo porque o formulário acabou de abrir vazio. O próprio projeto já tem o mecanismo pronto — é só replicar o `data-autofocus`.


### 41. Projects/Show: depois de cadastrar um cliente final o foco fica no rodapé, e o próximo cadastro exige mouse

**Arquivo:** `resources/js/Pages/Projects/Show.vue:355` · **Dimensão:** Formulários, validação e feedback

**Problema.** O `onSuccess` (linha 355) limpa nome, e-mail, CPF e telefone e mantém o kit — a decisão está certa e o comentário acima diz explicitamente que "cadastrar várias pessoas seguidas é o caso comum". Só que o clique veio do botão do rodapé (linha 839), fora do `<form>`, e nada devolve o foco ao campo Nome. Além disso, a única confirmação do que aconteceu é o toast "Cliente final cadastrado.", que aparece fora do drawer (no canto superior direito no desktop) e some em 6 segundos.

**Impacto.** Cadastrar 20 pessoas vira 20 idas ao mouse. E como os campos ficam idênticos aos de antes do envio (vazios), sem olhar para o canto da tela não dá para distinguir "salvou e limpou" de "não fez nada".

**Correção.** Guardar uma ref no primeiro UiInput e, no `onSuccess`, `nextTick(() => nomeRef.value?.$el?.querySelector('input')?.focus())`. E ancorar a confirmação dentro do painel, como já é feito no lote: um contador `role="status"` no rodapé do formulário do tipo "3 cadastrados neste painel — último: Maria Silva".

> **Verificação:** Parcialmente confere. O `onSuccess` (Projects/Show.vue:355) limpa nome, e-mail, CPF e telefone e mantém o kit, e o clique veio do botão do rodapé (linha 839), que está fora do `<form>` — nada devolve o foco ao campo Nome (linha 631), e um Enter seguido reenvia o formulário agora vazio. Mas a segunda metade do achado está errada: o Toaster é `fixed ... lg:top-4 lg:right-6` com `z-60` (Toaster.vue:118) e o drawer é `z-40` (UiDrawer.vue:43) — no desktop o aviso aparece POR CIMA do painel, no celular por cima do rodapé, e é anunciado pela região viva (Toaster.vue:120), além de pausar no hover/foco (linhas 128-131). Sobra só a devolução do foco: baixa, é ganho de eficiência num fluxo repetitivo, não bloqueio.


### 42. Toaster: aria-atomic="true" faz o leitor de tela reler todos os avisos a cada aviso novo

**Arquivo:** `resources/js/Components/Toaster.vue:120` · **Dimensão:** Formulários, validação e feedback

**Problema.** A região viva é `<div aria-live="polite" aria-atomic="true">` envolvendo o `v-for` de até 3 toasts (useToast.js:11). `aria-atomic="true"` manda o leitor apresentar a região INTEIRA a cada mudança, não só o que entrou. Com dois avisos na tela, o terceiro faz reler os três.

**Impacto.** No cadastro em série (Companies, Users, clientes finais), onde três confirmações se acumulam facilmente, o usuário de leitor de tela ouve a fila inteira repetida a cada salvamento, e a mensagem nova — a única que importa — fica no fim de uma leitura longa que ele não pode interromper sem perder o contexto.

**Correção.** Remover `aria-atomic="true"` da linha 120. A região viva já anuncia apenas os nós inseridos, que é o comportamento correto para uma fila de notificações.

> **Verificação:** Confere. `<div aria-live="polite" aria-atomic="true">` (Toaster.vue:120) envolve o `v-for` de até 3 avisos (useToast.js:11 e 23). Com `aria-atomic="true"` o leitor apresenta a região inteira a cada mudança, então o terceiro aviso faz reler os três — e também relê os que sobram quando um expira. Remover o atributo é a correção certa (a região já anuncia os nós inseridos). Severidade baixa: é verbosidade para quem usa leitor de tela, nada é perdido nem bloqueado.


### 43. Três nomes diferentes para o mesmo estado do resgate, dois deles na mesma linha da tabela

**Arquivo:** `resources/js/lib/format.js:15` · **Dimensão:** Telas operacionais densas

**Problema.** `redemptionStatusLabels.confirmed = 'Confirmado'`. Na linha da tabela de Resgates, a coluna Situação mostra o badge "Confirmado" (Redemptions/Index.vue:53) e a coluna Ação, a dois centímetros dali, mostra "Aguardando expedição" para o operador N2 (ShipControl.vue:94) — mesmo registro, mesma linha, dois nomes. O Dashboard chama de "Resgates aguardando expedição" (Dashboard.vue:61). "Confirmado" ainda sugere desfecho concluído, quando é justamente o estado que exige ação. O mapa `redemption` do StatusBadge é usado só nas duas telas operacionais (Redemptions/Index.vue:53 e Projects/Show.vue:541) — não há efeito colateral no portal do cliente final.

**Impacto.** Numa coluna de Situação que existe para responder "isto precisa de mim?", a palavra empurra para a resposta errada. E força o operador a ler duas colunas para ter certeza de uma coisa só.

**Correção.** Trocar em lib/format.js:15 para `confirmed: 'Aguardando expedição'`. Fica idêntico ao texto do ShipControl e ao vocabulário do Dashboard, e a variante `warning` do StatusBadge.vue:38 passa a fazer sentido com a palavra.

> **Verificação:** Os fatos conferem: lib/format.js:15 é `confirmed: 'Confirmado'`; Redemptions/Index.vue:53 desenha o badge e a célula ao lado (linha 68 → ShipControl.vue:93-94) escreve 'Aguardando expedição' quando `canShip` é falso; Dashboard.vue:61 usa 'Resgates aguardando expedição'. Confirmei também o escopo: `kind="redemption"` só aparece em Redemptions/Index.vue:53 e Projects/Show.vue:541, nada no portal. Duas ressalvas que rebaixam para baixa: (a) o choque na mesma linha só existe para o N2 — para o N1 a célula traz o botão 'Expedir' (ShipControl.vue:141); (b) a correção proposta é incompleta: 'confirmado' também está na caption de Projects/Show.vue:529, no vazio de :567 e no vazio de Redemptions/Index.vue:76, que passariam a não casar com nenhum rótulo da tela. É inconsistência de vocabulário, ninguém erra a tarefa por causa dela.


### 44. Menu "⋯" escondendo um único item — em um dos casos, quase sempre desabilitado

**Arquivo:** `resources/js/Pages/Projects/Show.vue:261` · **Dimensão:** Telas operacionais densas

**Problema.** `customerMenu` devolve um array de UM item, "Reenviar convite", com `disabled: !entitlement.access_pending`. Como a maioria dos clientes finais já criou senha, na maioria das linhas o operador clica no ⋯ (Projects/Show.vue:496), espera o overlay posicionar e recebe um menu com um item morto. O mesmo padrão de menu-de-um-item aparece em Redemptions/Index.vue:28-34 ("Abrir projeto") e Admin/Companies/Index.vue:140-142 ("Editar cadastro"). Em Resgates o custo é maior: esse ⋯ ocupa a 6ª coluna, e é justamente ela que empurra a tabela para além do limiar `columns.length > 5` (UiTable.vue:31), forçando o modo empilhado abaixo de 768px.

**Impacto.** Dois cliques e um overlay para chegar a um link, ou a nada. Numa lista onde a ação é frequente, esconder custa; numa linha onde ela não existe, o ⋯ mente sobre haver opção.

**Correção.** Em Projects/Show, renderizar a célula de ação só quando `entitlement.access_pending` for verdadeiro, e como <UiButton variant="secondary" size="sm">Reenviar convite</UiButton> inline — a coluna Situação já marca "Acesso pendente", então as duas informações se reforçam. Em Redemptions/Index, eliminar a coluna do ⋯ e transformar o valor da coluna Empresa num <Link> para `projects.show?aba=resgates`: some uma coluna, a tabela cai para 5 e sai do modo empilhado. Em Admin/Companies, trocar o ⋯ por um botão "Editar" no cabeçalho do card.

> **Verificação:** Os três casos existem: Projects/Show.vue:261-268 (um item, `disabled: !entitlement.access_pending`), Redemptions/Index.vue:28-34 ('Abrir projeto') e Admin/Companies/Index.vue:140-142 ('Editar cadastro'). O custo é real — UiMenu.vue:139-193 é botão + Teleport + medição de posição para entregar um item, às vezes morto. Rebaixo para baixa e derrubo o agravante citado: as 6 colunas de Redemptions/Index.vue:18-25 acionam mesmo o `stacked` de UiTable.vue:31, mas o modo empilhado abaixo de 768px é o comportamento ESPECIFICADO no §9.4 de docs/REDESIGN-UI.md:1433-1434 ('nunca rolagem em dois eixos'), não um dano colateral — e a área operacional é desktop. Sobra o atrito de um clique a mais por linha. Vale notar que em Projects/Show a coluna Situação já escreve 'Acesso pendente' (linha 485 via `situation()`), então o operador sabe antes de abrir o menu se há ação.


### 45. Resgates não mostra a que projeto o resgate pertence

**Arquivo:** `app/Http/Controllers/RedemptionController.php:29` · **Dimensão:** Telas operacionais densas

**Problema.** O controller envia `'project' => ['id' => ..., 'company' => ...]` — o nome do projeto fica de fora. A tabela mostra Empresa (Redemptions/Index.vue:51) e o menu oferece "Abrir projeto" sem dizer qual. Uma empresa como a Suvinil roda vários projetos ao mesmo tempo; duas linhas com "Suvinil" podem ser de campanhas diferentes, com kits, prazos e responsáveis diferentes.

**Impacto.** Ao expedir em lote, o operador não consegue agrupar por projeto nem conferir se está mexendo na campanha certa. Erro aqui manda o kit errado — o que na prática significa reentrega.

**Correção.** Incluir `'name' => $r->project->name` no payload e mostrar o nome do projeto como texto secundário na célula da Empresa, com <Link> para `projects.show?aba=resgates` (o que já resolve o achado do ⋯ de um item só).

> **Verificação:** Confere: RedemptionController.php:29 monta `'project' => ['id' => ..., 'company' => ...]` e o nome fica de fora; Redemptions/Index.vue:50 mostra só a empresa e o menu de :30 diz 'Abrir projeto' sem qualificar qual. Rebaixo para baixa porque nada na tarefa da tela depende disso: para expedir, o operador precisa de pessoa, kit e destino, e as três já estão na linha (colunas 1 e 2, linhas 44-49); ShipControl.vue:163-172 ainda repete pessoa/destino/kit dentro do drawer antes de confirmar. O nome do projeto é contexto útil (e o payload já carrega o relacionamento `project.company`, então o custo de incluir é zero), não risco de erro operacional.


### 46. Imports/Show: um <details> permanente por linha dobra a altura da tabela mais densa do sistema

**Arquivo:** `resources/js/Pages/Imports/Show.vue:243` · **Dimensão:** Telas operacionais densas

**Problema.** Cada linha da planilha gera dois <tr>: a linha de dados e um segundo <tr colspan=5> com o summary "detalhes da linha N" (min-h-9 = 36px), renderizado sempre, para todas as 50 linhas da página, mesmo quando a linha está OK e os campos técnicos ali dentro (Pedido Omie, chave da NF, rastreio Flash) não interessam a ninguém naquele momento. O resultado é uma página com 100 <tr> onde 50 bastariam, e ~100px de altura por linha lógica.

**Impacto.** Numa tela cuja tarefa é achar 12 linhas bloqueadas no meio de 500, o disclosure repetido é ruído puro: dobra a rolagem e afasta as linhas umas das outras, atrapalhando a varredura vertical que a tabela deveria favorecer.

**Correção.** Renderizar o <tr> de detalhes só quando houver o que dizer naquela linha: `v-if="isBlocked(row) || error(row.id) || stillBlocked[row.id] || expanded.has(row.id)"`. Para as demais, tornar a célula do número da linha (linha 185) o gatilho de expansão — o alvo já existe e a linha fica com uma altura só.

> **Verificação:** Confere: Imports/Show.vue:183-292 emite dois `<tr>` por linha da planilha dentro do mesmo `v-for`, e o segundo (:243-291) com `<details>`/`<summary class="... min-h-9 ...">` é renderizado incondicionalmente, inclusive para linha `order_created` sem erro nenhum. Com paginate(50) (AppnozImportController.php:42) são 100 `<tr>` para 50 linhas lógicas. Uma correção ao texto do achado: o acréscimo por linha é o summary de 36px mais o padding da célula (~12px, já que o `pt-0` mata o de cima), ou seja ~48px — 'dobra a altura' é exagero, a linha de dados sozinha já tem 2-3 linhas de texto. Baixa está certo.


### 47. Conta inativa vira loop infinito: a pessoa redefine a senha com sucesso e mesmo assim não entra

**Arquivo:** `app/Http/Controllers/Auth/LoginController.php:26` · **Dimensão:** Autenticação e primeiro acesso

**Problema.** `Auth::attempt($credentials + ['active' => true])` faz a conta inativa falhar exatamente como senha errada. O bloco seguinte (linhas 28-36) só diferencia o caso `password_set_at = null`; quem já definiu a senha e foi desativado cai em 'Credenciais inválidas.'. A pessoa conclui que esqueceu a senha e vai para 'Esqueci minha senha' — que FUNCIONA, porque o broker do Laravel não olha `active`. Ela define a senha nova, recebe 'Senha definida! Bem-vindo à WeLog.' (PasswordController.php:68) ou 'Senha redefinida com sucesso.' (PasswordController.php:53), volta ao login e leva 'Credenciais inválidas.' de novo. Pior: em PasswordController.php:105 o auto-login é descartado silenciosamente quando `active` é falso — o único sinal que existiria é justamente o que a tela não mostra. Login.vue não tem nenhum ramo para esse estado.

**Impacto.** Loop fechado sem saída, com uma mensagem de sucesso no meio dizendo que deu certo. Toda conta desativada (operador desligado, admin cliente de projeto encerrado, cliente final bloqueado) gera pelo menos um chamado, e o chamado chega errado: 'não consigo entrar mesmo trocando a senha' vira investigação de bug em vez de 'sua conta está desativada'. É o único achado desta dimensão em que o sistema afirma sucesso e entrega falha.

**Correção.** Em LoginController::login, depois do `attempt` falhar, consultar o usuário uma vez e tratar três estados distintos: (a) inativo → devolver `withErrors(['email' => 'Esta conta está desativada. Fale com o RH da sua empresa ou com o suporte WeLog.'])`; (b) `password_set_at` nulo → mensagem atual; (c) resto → 'Credenciais inválidas.'. Em PasswordController::completePassword, no `if` da linha 105, tratar o else de conta inativa redirecionando com um flash próprio ('Senha salva, mas esta conta está desativada — fale com o suporte') em vez de 'Bem-vindo à WeLog'. No Login.vue, renderizar o estado inativo como UiAlert `danger` no slot #alert (mesmo padrão da linha 42), não como erro de campo — não é algo que se corrige digitando.

> **Verificação:** Mecanismo confirmado no código: LoginController.php:26 usa `Auth::attempt($credentials + ['active' => true])` e o único ramo alternativo (linhas 28-30) testa apenas `whereNull('password_set_at')`, então conta inativa com senha definida recebe 'Credenciais inválidas.'. O broker realmente não olha `active` (config/auth.php:96-101 aponta para App\Models\User, que não tem global scope), e PasswordController.php:105 descarta o auto-login em silêncio, caindo na linha 112 com o flash 'Senha definida! Bem-vindo à WeLog.' — mensagem factualmente falsa. O loop existe. MAS a severidade não é alta: a coluna tem `default(true)` (database/migrations/2026_07_13_000011_add_welog_fields_to_users_table.php:22), RegisterEndCustomer.php:35-43 e CompanyController.php:157 criam usuários ativos, e a ÚNICA forma de desativar alguém no produto é Admin\UserController::toggleActive (linha 87), protegida por `abort_unless(in_array($user->role, self::ASSIGNABLE_ROLES))` (linha 89) — ASSIGNABLE_ROLES são só admin_wegift/wegift_n1/wegift_n2 (linhas 22-26). Ou seja: cliente final e admin_client NUNCA ficam inativos; o cenário atinge apenas equipe interna desativada de propósito, treinada e com acesso direto a um admin. Ressalva à correção proposta: o item (a) contradiz o achado 6 — anunciar 'conta desativada' após qualquer senha errada cria justamente o vazamento de enumeração que o outro achado pede para eliminar; se for implementado, só revelar depois de conferir a senha correta.


### 48. O erro de senha é impresso duas vezes, um deles num campo que não é o culpado, e a dica some justamente aí

**Arquivo:** `resources/js/Pages/Auth/SetPassword.vue:83` · **Dimensão:** Autenticação e primeiro acesso

**Problema.** Os dois UiField ligam o MESMO bag: linha 59 (`Sua senha`) e linha 83 (`Confirmar senha`) usam `:error="form.errors.password"` e `:invalid="!!form.errors.password"`. ResetPassword.vue:55 e :79 fazem igual. Como as regras de PasswordController.php:80 são `confirmed` + `min(8)`, tudo aterrissa na chave `password`. Efeitos verificáveis: (a) a mesma frase aparece em vermelho embaixo dos dois campos, com os dois em borda de erro, sem indicar qual corrigir; (b) UiField.vue:73 renderiza `role="alert"` em cada uma, então o leitor de tela anuncia a mensagem duas vezes; (c) um erro de tamanho ('no mínimo 8 caracteres') acusa também 'Confirmar senha', que pode estar perfeito; (d) UiField.vue:32 (`hasHint = !hasError && ...`) apaga a dica 'Mínimo de 8 caracteres' assim que há erro — a regra desaparece da tela no exato momento em que a pessoa precisa relê-la.

**Impacto.** Numa tela cuja única tarefa é digitar uma senha duas vezes, o feedback aponta para dois lugares ao mesmo tempo e sem hierarquia. Em celular, com o teclado cobrindo metade da tela, isso vira tentativa e erro. É a última etapa antes do resgate do brinde — atrito aqui é abandono com o convite já consumido.

**Correção.** Deixar o erro só no primeiro campo e marcar o segundo apenas visualmente: na linha 83, trocar por `:error="null"` mantendo `:invalid="!!form.errors.password"` (o UiInput já aceita `invalid` como prop própria, UiInput.vue:23). Para o caso específico de divergência, validar no backend com uma regra dedicada em `password_confirmation` e ligá-la ao segundo campo, aí sim com mensagem própria ('As duas senhas não são iguais'). E manter a dica visível: passar a regra como texto fixo fora do UiField, ou não zerar `hasHint` quando o erro for de confirmação.

> **Verificação:** Os fatos conferem: SetPassword.vue:59 e :83 (e ResetPassword.vue:55 e :79) ligam o mesmo `form.errors.password`, a validação em PasswordController.php:80 é `['required','confirmed', PasswordRule::min(8)]` (tudo na chave `password`) e UiField.vue:73 emite um `role="alert"` por campo — logo, mensagem duplicada e dois campos em borda vermelha, inclusive quando o erro é só de tamanho. Duas correções ao achado, que puxam a severidade para baixa: (i) o argumento 'sem indicar qual corrigir' perde força porque `onFinish` limpa OS DOIS campos a cada falha (SetPassword.vue:34, ResetPassword.vue:32) — não há o que corrigir seletivamente, a pessoa redigita ambos de qualquer jeito; (ii) o binding duplicado não é descuido, é decisão registrada em docs/REDESIGN-UI.md:2713-2716 ('se for mantido, ligar :error=form.errors.password nele também'), e o sumiço da dica é regra explícita do design system (UiField.vue:31-32, 'uma mensagem, um lugar'), ou seja o item (d) é discussão de padrão, não defeito da tela. Sobra ruído real (anúncio em dobro e campo de confirmação acusado por erro de tamanho), sem bloquear ninguém.


### 49. A mensagem de 'ainda não definiu sua senha' revela quais e-mails corporativos foram convidados

**Arquivo:** `app/Http/Controllers/Auth/LoginController.php:28` · **Dimensão:** Autenticação e primeiro acesso

**Problema.** Depois de qualquer falha de `attempt`, o controller consulta `User::where('email', ...)->whereNull('password_set_at')->exists()` e devolve uma mensagem diferente conforme o resultado. Não é preciso acertar a senha: qualquer string basta. Logo, o formulário responde de forma distinta para 'e-mail convidado e ainda não ativado' e para 'e-mail desconhecido'. O resto do sistema é cuidadoso com isso — PasswordController.php:37-38 e lang/pt_BR/passwords.php:16 mantêm resposta neutra no 'esqueci minha senha' de propósito —, então o login é o único ponto que vaza.

**Impacto.** Dá para testar uma lista de e-mails de uma empresa cliente e descobrir quem foi convidado para a WeLog, ou seja, quem está recebendo brinde. É informação do RH do cliente exposta num formulário público. O limitador de 5/min por e-mail+IP (AppServiceProvider.php:29-31) atrasa, mas não impede: a chave inclui o e-mail, então cada endereço testado tem sua própria cota.

**Correção.** Não é preciso perder a ajuda para ganhar o sigilo: manter 'Credenciais inválidas.' para todos os casos e oferecer SEMPRE, no mesmo alerta, a ação de recuperação — reaproveitando o UiAlert já pronto em Login.vue:42-50, mas exibido a partir da primeira falha, independente do estado da conta. O texto neutro cobre os dois públicos: 'Não conseguimos entrar com esses dados. Se você ainda não criou sua senha ou não lembra dela, receba um link por e-mail.' + botão 'Receber link de acesso'. Isso permite remover o `exists()` da linha 28-30 inteiro.

> **Verificação:** Fato confirmado: LoginController.php:28-36 consulta `exists()` depois de qualquer falha de attempt e devolve texto diferente sem exigir senha correta, enquanto PasswordController.php:37-38 e lang/pt_BR/passwords.php:16 mantêm neutralidade no fluxo de recuperação — o login é mesmo o único ponto assimétrico. Severidade rebaixada para baixa: a diferença só existe para contas convidadas e ainda não ativadas (quem já tem senha recebe o mesmo 'Credenciais inválidas.' de um e-mail inexistente), a janela se fecha sozinha no primeiro acesso, e AppServiceProvider.php:29-31 limita a 5 tentativas/min por e-mail+IP, o que inviabiliza varredura. Além disso o comportamento é troca consciente documentada (comentário em LoginController.php:27, Login.vue:17-20 e docs/REDESIGN-UI.md:2654-2659) em favor de orientar o cliente final de primeiro acesso. A correção proposta é boa porque preserva a ajuda sem o vazamento — mas note que ela conflita com o achado 1, que pede uma terceira mensagem ('conta desativada') e reabriria o mesmo vazamento em outro estado.


### 50. A tela não diz em lugar nenhum visível de quem ela é: só um quadrado com 'W' marcado como aria-hidden

**Arquivo:** `resources/js/Components/AuthCard.vue:12` · **Dimensão:** Autenticação e primeiro acesso

**Problema.** A única marca do cartão é um `<span aria-hidden="true">` com a letra 'W' sobre `bg-brand-600` (#4f46e5). A palavra 'WeLog' não aparece no corpo de nenhuma das quatro telas de auth — existe apenas no `<title>` montado em app.js:13, que o navegador de celular não mostra. Para leitor de tela, o `aria-hidden` remove até esse resquício: a página começa direto no h1 'Criar sua senha', sem identificação. Some-se que o cliente final entra por SetPassword (o convite aponta para `password.set.form`, AccountInvitationMail.php:36), nunca pelo Login — então o subtítulo de contexto 'Acesse com o e-mail que recebeu o convite' (Login.vue:37) é lido por quem menos precisa dele, enquanto SetPassword.vue:42 traz só 'Só isso e você já entra.', que não diz nem a plataforma nem o motivo.

**Impacto.** A pessoa vem de um e-mail do RH sobre um brinde, toca num link e aterrissa num quadrado indigo com 'W' e um pedido de senha. Não há confirmação de que é o lugar certo, e o desenho é indistinguível de milhares de logins SaaS (indigo #4f46e5 + Inter, carregado em app.blade.php:16). Pedir senha sem se identificar é exatamente o formato que se ensina a desconfiar — parte das pessoas fecha a aba, e essa desistência é invisível: não gera chamado, gera brinde não resgatado.

**Correção.** Em AuthCard.vue, transformar o bloco de marca em logotipo com texto: manter o quadrado como `aria-hidden` decorativo e colocar 'WeLog' ao lado, em `text-base font-semibold text-ink`, dentro de um wrapper `flex items-center justify-center gap-2` — assim leitor de tela e olho recebem o nome. E dar contexto próprio ao primeiro acesso: em SetPassword.vue:42, trocar o subtítulo por algo que amarre e-mail e destino, ex.: 'Sua empresa criou seu acesso na WeLog. Defina uma senha para escolher o seu presente.'

> **Verificação:** A parte verificável procede: AuthCard.vue:12-17 é um `<span aria-hidden="true">` com a letra 'W' e a palavra 'WeLog' não aparece no corpo de nenhuma das quatro telas (busca no repositório só encontra a marca em <Head>/título e nos e-mails). Para um cliente final que nunca viu a marca, um 'W' isolado não carrega reconhecimento algum — e ele está digitando uma senha, situação em que identificar o destinatário importa (anti-phishing). Duas correções ao achado: (a) a alegação de acessibilidade está errada — resources/js/app.js:13 monta o título 'Criar sua senha — WeLog' e leitores de tela anunciam o document title na carga da página, então o nome não está ausente para tecnologia assistiva; o que falta é identificação visual/textual; (b) a segunda sugestão ('Defina uma senha para escolher o seu presente') contraria padrão explícito do projeto: docs/REDESIGN-UI.md:2719-2721 e :2869 fixaram 'Só isso e você já entra.' justamente porque a MESMA tela serve ao convite de operador WeGift, que não vai 'escolher presente' nenhum. Baixa: é confiança/contexto, não bloqueio.


### 51. O botão de ver a senha combina rótulo variável com aria-pressed, e o anúncio fica contraditório

**Arquivo:** `resources/js/Pages/Auth/Login.vue:78` · **Dimensão:** Autenticação e primeiro acesso

**Problema.** O UiButton tem `:aria-pressed="senhaVisivel"` e, ao mesmo tempo, o texto alterna entre 'Mostrar' e 'Ocultar' (linha 84). Repetido em SetPassword.vue:72-79 e ResetPassword.vue:68-76. Com a senha visível, o leitor de tela anuncia 'Ocultar, botão de alternância, pressionado' — o rótulo descreve a próxima ação e o estado descreve a situação atual, e os dois se contradizem. O padrão do ARIA Authoring Practices é escolher um dos dois mecanismos, não os dois.

**Impacto.** Atinge só usuário de leitor de tela, e o botão continua operável — por isso é baixa. Mas é o controle que permite conferir a senha antes de enviar, exatamente o que evita o erro de digitação numa tela sem segunda chance.

**Correção.** Fixar o nome acessível e deixar o estado por conta do `aria-pressed`: rótulo constante 'Mostrar senha' com `:aria-pressed="senhaVisivel"`. Se preferir manter o texto alternando por ser mais legível para quem enxerga, remover o `aria-pressed` — as duas soluções são corretas, a combinação não.

> **Verificação:** Confirmado nos três arquivos: Login.vue:78-85, SetPassword.vue:72-79 e ResetPassword.vue:68-76 têm `:aria-pressed="senhaVisivel"` junto de rótulo alternando 'Mostrar'/'Ocultar'. Como UiButton.vue não desliga `inheritAttrs`, o aria-pressed cai mesmo no `<button>` (UiButton.vue:62-69, `type` default 'button' na linha 10 — não há risco de submit acidental). A combinação contraria a orientação do ARIA Authoring Practices para toggle button (nome acessível não deve mudar junto do estado; se o rótulo muda, não use aria-pressed), e o resultado é 'Ocultar, pressionado'. Reforça a confirmação o fato de a própria especificação do projeto (docs/REDESIGN-UI.md:2662) pedir botão 'Mostrar senha' com aria-pressed — o rótulo alternante foi acrescentado por cima. Baixa: ninguém fica impedido, é ruído de anúncio.

