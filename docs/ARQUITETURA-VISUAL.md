# Arquitetura visual — diagnóstico e especificação (31/07/2026)

Diagnóstico em 6 dimensões + crítica de completude. **97 achados**.

| Dimensão | Achados |
|---|---|
| Arquitetura de tokens | 19 |
| Cor que carrega significado na operação | 17 |
| Portal: onde falta vida | 19 |
| Autenticação: primeira impressão | 13 |
| Valores fora do token (o que quebra na troca) | 14 |
| Tipografia, espaço e ritmo | 15 |

## Resumo por dimensão

### Arquitetura de tokens

A arquitetura de tokens está bem-intencionada e bem comentada, mas é de UMA camada só: 29 tokens de cor, todos literais, consumidos direto por 23 arquivos — não existe nível de intenção entre a rampa e o componente, então `bg-brand-600` significa nove coisas diferentes conforme o arquivo, e trocar a paleta obriga a reabrir cada um para adivinhar qual. As intenções que deveriam ser código estão em comentário: `info` "é a própria marca" mas são 3 hexes duplicados byte a byte (mais `focus` = `brand-500`), então mudar a marca vai deixar `info` e o anel de foco para trás com o índigo antigo — justamente nos dois lugares mais repetidos da tela. Faltam papéis inteiros: texto-sobre-a-marca (11 pontos assumem branco, e um acento quente reprova AA em todos), overlay, superfície elevada, seleção de texto, borda de campo inválido, raio, e sobretudo o acento de celebração — que é a razão técnica do "sem vida", já que o portal conta a jornada do presente com `success` e `info`, vocabulário de painel administrativo. Quatro tintas escapam de qualquer `var()` e sobreviverão intactas à troca: as sombras (que congelam o RGB do `ink` em decimal), o `.bg-mesh` da tela de login (que colou um indigo-400 cru porque o degrau não existe na rampa), o chevron do `<select>` e a barra do Inertia. E não há gancho nenhum de escopo — PortalLayout e AppLayout têm a raiz idêntica caractere por caractere —, que é exatamente o que impede a mesma base de servir necessidades opostas: com uma classe de tema na raiz do portal, a diferenciação vira override de papéis em vez de prop a prop.

### Cor que carrega significado na operação

A operação comunica 8 eixos de estado por cor, mas o sistema só tem 4 tintas semânticas — e uma delas, `info`, é byte-a-byte a própria marca (`--color-info: #4338ca` em app.css:65 é o mesmo valor de `--color-brand-700` em app.css:46; `info-soft` e `info-line` idem). O resultado é que o índigo acumula nove trabalhos distintos só no Dashboard (identidade, item ativo da navegação, link, botão primário, ponto de fila de rotina, barra do gráfico, "pronto para liberar" = aja agora, "processando" = espere, "em transporte" = espere), e dois deles são opostos entre si. Ao mesmo tempo, o eixo mais importante da operação — o estágio do pipeline — não tem cor nenhuma: o mapa `pipeline` do StatusBadge (StatusBadge.vue:28-36) é código morto, nenhuma página o usa, enquanto taxonomia ("Kit · 3 itens") e nota de rodapé ("cadastrado automaticamente") ganham pílula colorida. Há três colisões duras que quebram comunicação hoje, antes de qualquer troca de paleta: dois bloqueios com resoluções opostas usam o mesmo vermelho, o mesmo resgate é âmbar para o operador e índigo para o cliente final, e o preenchimento de TODAS as pílulas de status desaparece na linha sob o cursor (1,00:1 a 1,07:1 contra `surface-muted`), deixando a cor do texto como único portador exatamente onde o olho está. A troca de marca é segura para vermelho/verde/âmbar (significados aprendidos e universais, devem sobreviver intactos) e é uma oportunidade para o `info`, que precisa deixar de ser marca e virar um papel próprio — mas o acento QUENTE pedido pelo cliente cai na vizinhança de `--color-warning` (#b54708/#fedf89) e precisa ser proibido no vocabulário de status da operação.

### Portal: onde falta vida

O portal não falha por falta de capricho — falha porque herda inteira a caixa de ferramentas da área operacional e não tem nenhum registro próprio além da altura de controle (`--spacing-control-lg`, app.css:84 é o ÚNICO token que reconhece o portal). Em toda a jornada existem só quatro famílias cromáticas — índigo (marca e `info` são a mesma tinta), verde, âmbar e vermelho — e três delas são alarme ou validação: não há nenhum papel de cor cuja função seja dizer "isso é bom". Concretamente: o `<h1>` do "Olá, Yago" é byte-idêntico ao de "Importações" (PageHeader.vue:51 — `tone="portal"` não toca no título); a frase "A {empresa} preparou um presente para você" sai em cinza secundário #667085; o único verde do Redeem.vue comemora um CEP encontrado, não o presente; o presente some da tela justamente no passo irreversível (Redeem.vue:308-332, sem imagem); e a recompensa da ação mais importante do produto é uma tira dispensável de 12px de padding sobre o título "Entregas". A tela mais decorada do sistema é o formulário de login (`.bg-mesh` em AuthCard.vue:9); a tela do presente é #f6f7f9 chapado. A estrutura que falta não são matizes: são papéis — celebração, acolhimento, revelação, antecipação — e um registro de densidade/escala do portal que hoje simplesmente não existe.

### Autenticação: primeira impressão

A tela de entrada é a porta da frente de todo mundo — `Route::redirect('/', '/login')` em routes/web.php:15 — e hoje ela é a única superfície do produto onde o nome da marca não está escrito: AuthCard mostra um quadrado indigo com a letra "W" marcada como `aria-hidden`, enquanto AppLayout e PortalLayout escrevem "WeLog" ao lado do mesmo quadrado. O que uma pessoa vê ao abrir é um retângulo cinza-azulado, um cartão branco de 448px centralizado, três controles de 48px empilhados com o mesmo espaçamento entre si e o botão, e um halo indigo no topo — indigo cravado em hexadecimal dentro do CSS, não em token, sendo que um dos dois matizes (#818cf8) nem existe na rampa. A ruptura mais cara é de tom: o e-mail que traz o cliente final diz "A {empresa} preparou um presente para você" e o botão diz "Resgatar meu presente"; o destino desse clique diz "Entrar — Acesse com o e-mail que recebeu o convite", e a frase quente só reaparece uma tela depois, no Catalog. A boa notícia estrutural é que a casca é uma só (AuthCard serve as quatro páginas), então dar a ela um slot de marca e um parâmetro de tom resolve as duas superfícies opostas de uma vez; a má notícia é que o mesmo placeholder de marca está copiado literalmente em seis lugares e em três tamanhos, um deles em Blade, fora do alcance de qualquer componente Vue.

### Valores fora do token (o que quebra na troca)

O código é muito mais disciplinado do que o diagnóstico sugeria: varri resources/js, resources/css e resources/views inteiros e existem apenas TRÊS valores de cor escritos à mão fora do bloco @theme (app.js:36, UiSelect.vue:72 e o .bg-mesh em app.css:140-141) — nenhuma classe da paleta padrão do Tailwind sobreviveu no markup. O que impede a troca limpa da paleta não é sujeira espalhada, é a ESTRUTURA do próprio @theme: a família `info` inteira e o `--color-focus` são apelidos numéricos da rampa brand (3 pares de hex idênticos em app.css:41-69), a rampa curta já forçou o único rgb literal do CSS (o mesh precisou de um brand-400 que não existe), e uma única tinta carrega oito papéis diferentes — marca, navegação ativa, progresso, gráfico, avatar, numeração, paginação e evento recente. O risco real da migração está em três pontos cegos: o e-mail (porta de entrada do portal) roda no tema default do Laravel, com ~35 hex zinc/verde/vermelho fora de qualquer token; o "W" provisório está copiado em 6 lugares com 3 tamanhos e 2 raios, sem componente; e o @theme não fecha a paleta (`--color-*: initial` ausente), então `bg-indigo-600` volta a compilar em silêncio no dia seguinte à troca. A boa notícia é que o gancho para separar as duas superfícies já existe e está inutilizado: `PageHeader` tem prop `tone: app|portal` que hoje só altera espaçamento, nunca cor.

### Tipografia, espaço e ritmo

A interface não parece "sem vida" só por causa da cor: ela parece sem vida porque 98% do texto do produto (215 de 219 ocorrências de tamanho) vive num intervalo de 4px, entre 12 e 16px, e porque o único valor de espaço vertical acima de 24px em todo o app é um `mt-8` que aparece uma vez, em UiSectionHeading.vue:10. Sem contraste de tamanho e sem variação de distância entre blocos, não existe ponto focal nem agrupamento por proximidade — a tela é um campo uniforme e o olho não tem onde pousar. A Fase 10 fez uma limpeza correta (removeu blur, blobs e decoração gratuita, ver app.css:136-137) mas removeu junto o feedback de interação da Fase 5 e não repôs nada: hoje há ZERO `active:`, zero elevação em hover, zero animação de entrada de conteúdo — só `transition-colors` (32 ocorrências) e quatro keyframes presos a overlay e carregamento. A forma é impecavelmente consistente e por isso mesmo genérica: dois raios, duas sombras, uma espessura de traço, todos escritos como classe utilitária em 73+14 lugares, sem token — o que significa que a identidade da WeGift, quando chegar, não tem onde encostar. E o produto inteiro renderiza exatamente UM `<img>` (Catalog.vue:70), condicional; `public/` tem só o favicon.


---

# Arquitetura visual do WeLog — especificação

Estado: pronta para execução. Cores da WeGift ainda não chegaram; todo ponto onde um hex da marca entra está marcado com `{{MARCA}}`.

---

## 1. Diagnóstico em uma página

O produto não parece sem vida por falta de cor. Parece por seis defeitos estruturais mensuráveis:

1. **A cor da marca nunca foi escolhida.** `--color-brand-600: #4f46e5` (`resources/css/app.css:45`) é o indigo-600 padrão do Tailwind. Idem `--color-info` (`app.css:65`), que é byte a byte `--color-brand-700` (`app.css:46`), e `--color-focus` (`app.css:69`), que é `--color-brand-500` (`app.css:44`). Quatro tokens declaram valor e nenhum declara decisão.

2. **Uma tinta faz nove trabalhos.** `brand-*` aparece em 45 linhas de 23 arquivos como ação primária (`UiButton.vue:23`), item ativo da sidebar (`AppLayout.vue:181`), barra de upload (`UiFileDrop.vue:167`), série do gráfico (`PipelineChart.vue:32`), passo do portal (`Redeem.vue:178`), evento recente (`UiTimeline.vue:25`), página atual (`UiPagination.vue:133`), avatar (`AppLayout.vue:120`) e 5 dos 7 estágios do pipeline (`StatusBadge.vue:30-34`). Quando nove coisas usam a mesma tinta, nenhuma é destaque — é essa a mecânica do "só tem branco e azul".

3. **90% dos pixels são neutros frios.** Desvio azul (B−R) medido: `canvas` +3, `line` +8, `line-strong` +13, `ink` +24, `ink-muted` +31 (`app.css:28-35`). São 8 literais isolados, sem eixo de matiz ou croma. Trocar só a marca não muda a temperatura da tela.

4. **A escala tipográfica cabe em 4px.** 215 de 219 ocorrências de tamanho estão entre 12 e 16px. O maior elemento do produto é o `<h1>` de 24px (`PageHeader.vue:51`) — que na maioria das telas é o nome da própria tela. Amplitude de 2:1 não produz ponto focal, e nenhuma paleta corrige isso.

5. **Não existe papel de cor que signifique "isto é bom para você".** O portal conta a jornada do presente com `success` (o mesmo verde que valida um CEP em `Redeem.vue:215`) e `info` (que é a marca). A confirmação do resgate é um `UiAlert` dispensável de 12px de padding, posicionado acima do `<h1>` "Entregas" (`Portal/Redemptions.vue:102-106`).

6. **As duas superfícies partem da mesma string.** `PortalLayout.vue:40` e `AppLayout.vue:95` são `<div class="min-h-dvh bg-canvas">`, idêntico. O único token que reconhece o portal é `--spacing-control-lg` (`app.css:84`). O `tone="portal"` do `PageHeader` altera espaçamento e tamanho de subtítulo e nunca toca em cor nem no título (`PageHeader.vue:26-54`) — e isso está especificado assim em `docs/REDESIGN-UI.md:331`.

Corolário: **entregar a paleta nova sobre esta estrutura produz um sistema com cores novas e a mesma temperatura emocional.** A ordem correta é estrutura primeiro, cor depois.

---

## 2. Arquitetura de tokens

### 2.1 Mecanismo (decisão travada — leia antes de escrever qualquer token)

Duas camadas conceituais, **um único nível de indireção**:

```css
/* Camada 1 — PRIMITIVOS. :root normal, FORA do @theme.
   Não viram utilitário. Ninguém escreve `bg-brand-600` no markup. */
:root {
    --brand-600: {{MARCA}};
    --neutral-700: oklch(...);
    /* ... */
}

/* Camada 2 — PAPÉIS. Dentro do @theme. Viram utilitário. */
@theme {
    --color-action-primary: var(--brand-600);
}
```

**Regra dura, e o motivo:** em Tailwind v4 sem `inline`, a utilitária gera `background-color: var(--color-action-primary)` e o `@theme` emite `--color-action-primary: var(--brand-600)` no `:root`. A substituição do `var()` acontece no `:root`; o valor **já resolvido** é o que desce por herança. Portanto:

- Sobrescrever um **papel** num escopo funciona: `.theme-portal { --color-surface-gift: … }`.
- Sobrescrever um **primitivo** num escopo **não** propaga para os papéis.
- Usar `@theme inline` inverte exatamente isso e quebra o escopo.

Logo: **escopo de superfície sobrescreve papel, nunca primitivo.** Está escrito aqui porque as duas coisas foram propostas juntas na análise e são mutuamente exclusivas.

Consequências práticas: trocar a paleta = editar o bloco `:root` de primitivos, um arquivo, uma vez. Diferenciar o portal = sobrescrever uma lista fechada de papéis num seletor.

**Verificação obrigatória:** conferir no CSS compilado, dentro do Docker, que `--color-*` com `var()` gerou as utilitárias esperadas e que `bg-x/40` (modificador de opacidade) continua produzindo `color-mix()` válido. É o mesmo método usado na Fase 11 (`docs/PLANO.md:383-385`).

### 2.2 Primitivos

**Rampa da marca — 10 degraus.** Hoje são 6 (`app.css:41-46`) e a rampa curta **já** custou um valor fora do sistema: `.bg-mesh` (`app.css:141`) escreveu `rgb(129 140 248)` — o indigo-400 que não existe como token.

| Primitivo | Papel na rampa | Valor |
|---|---|---|
| `--brand-50` | fundo de item selecionado | `{{MARCA}}` |
| `--brand-100` | chip, avatar, `::selection` | `{{MARCA}}` |
| `--brand-200` | borda de destaque | `{{MARCA}}` |
| `--brand-300` | atmosfera clara, halo | `{{MARCA}}` |
| `--brand-400` | atmosfera, gradiente (destino do `.bg-mesh`) | `{{MARCA}}` |
| `--brand-500` | — | `{{MARCA}}` |
| `--brand-600` | ação primária | `{{MARCA}}` |
| `--brand-700` | hover/active, texto sobre soft | `{{MARCA}}` |
| `--brand-800` | superfície institucional profunda | `{{MARCA}}` |
| `--brand-900` | faixa de marca, painel de autenticação | `{{MARCA}}` |

800/900 não são luxo: a direção "base institucional profunda" não tem onde morar hoje — o mais escuro disponível é `brand-700`, que já é a tinta de texto sobre fundo soft.

**Acento de celebração — família própria, 4 degraus.** Não é um degrau da rampa da marca. Ver §3 para o escopo.

| Primitivo | Papel | Valor |
|---|---|---|
| `--accent-500` | acento sólido | `{{MARCA}}` |
| `--accent-soft` | fundo celebratório | `{{MARCA}}` |
| `--accent-line` | borda de superfície celebratória | `{{MARCA}}` |
| `--accent-ink` | texto legível sobre `--accent-soft` | `{{MARCA}}` |

**Neutros — eixo declarado, não 8 literais.** Trocar 8 hexes à mão não garante monotonia de luminância; há risco real de inverter contraste entre `line` e `line-strong` sem ninguém perceber.

```css
:root {
    --neutral-hue: {{MARCA}};      /* matiz quente, derivado da marca */
    --neutral-chroma: {{MARCA}};   /* croma baixo, 0.004–0.010 */

    --neutral-25:  oklch(98.4% var(--neutral-chroma) var(--neutral-hue));
    --neutral-50:  oklch(97.6% var(--neutral-chroma) var(--neutral-hue));
    --neutral-100: oklch(96.0% var(--neutral-chroma) var(--neutral-hue));
    --neutral-200: oklch(91.5% var(--neutral-chroma) var(--neutral-hue));
    --neutral-300: oklch(84.5% var(--neutral-chroma) var(--neutral-hue));
    --neutral-400: oklch(72.0% var(--neutral-chroma) var(--neutral-hue));
    --neutral-500: oklch(56.0% var(--neutral-chroma) var(--neutral-hue));
    --neutral-900: oklch(19.5% var(--neutral-chroma) var(--neutral-hue));
}
```

**Regra de aquecimento:** aquecer é mudar **matiz e croma com luminância constante**. As luminâncias acima são as dos valores atuais e são o piso. `--color-ink-muted` está anotado no próprio arquivo como PISO de 4,9:1 (`app.css:34`) e **não pode subir de luminância**.

**Famílias semânticas — nomes mantidos, `info` ganha tinta própria.** Renomear `success/warning/danger/info` custaria refactor em 19 páginas com falha silenciosa (§7). O que muda é o **valor** de `info` e o **significado documentado**:

| Primitivo | Papel | Restrição |
|---|---|---|
| `--success-*` | fim de linha, validado | mantém valores atuais até §6 etapa F |
| `--warning-*` | pede ação humana | mantém valores; ver colisão com o acento em §7 |
| `--danger-*` | travado, não avança sozinho | mantém valores |
| `--info-*` | **em andamento, nada a fazer** | frio, dessaturado, **proibido compartilhar valor com qualquer `--brand-*`** |

### 2.3 Papéis por intenção

Tudo abaixo entra no `@theme`. **Nenhum componente pode consumir `--brand-*`, `--accent-*` ou `--neutral-*` diretamente.**

**Ação**

| Papel | Origem | Consumidores |
|---|---|---|
| `--color-action-primary` | `--brand-600` | `UiButton.vue:23`, `UiPagination.vue:133`, `Projects/Show.vue:708`, `errors/403.blade.php:25` |
| `--color-action-primary-hover` | `--brand-700` | idem |
| `--color-on-action` | `{{MARCA}}` | substitui `text-ink-inverse` em `UiButton.vue:23`, `UiPagination.vue:133`, `Projects/Show.vue:708`, `errors/403.blade.php:25` |
| `--color-action-danger` | `--danger-solid` | `UiButton.vue:26` |
| `--color-on-action-danger` | `{{MARCA}}` | idem |

`--color-on-action` é o token que quebra primeiro na troca. Hoje o par `bg-brand-600 text-ink-inverse` funciona por aritmética (6,3:1) e está escrito à mão em 10 lugares. **Não é branco por definição** — é o que o contraste exigir. Se o acento for claro, recebe tinta escura.

**Identidade**

| Papel | Origem | Consumidores |
|---|---|---|
| `--color-brand-surface` | `--brand-900` | `BrandMark` (novo), faixa superior do portal, painel de autenticação |
| `--color-on-brand-surface` | `{{MARCA}}` | idem |

**Seleção e navegação**

| Papel | Origem | Consumidores |
|---|---|---|
| `--color-selected-bg` | `--brand-50` | `AppLayout.vue:174`, `UiTabs.vue:55`, `ShipControl.vue:197`, `UiFileDrop.vue:131`, `Admin/EmailTemplates/Index.vue:377,412` |
| `--color-selected-fg` | `--brand-700` | idem |
| `--color-selected-marker` | `--brand-600` | `AppLayout.vue:181`, sublinhado da aba ativa `UiTabs.vue:54` |
| `--color-control-selected` | `{{MARCA}}` | `UiCheckbox.vue:23` — ver §7, `accent-color` |

**Foco — desacoplado da marca**

`--color-focus` (`app.css:69,104`) recebe tinta própria. Requisito funcional, não estético: **≥3:1 contra `--color-surface` E contra `--color-canvas` E contra `--color-action-primary`** (SC 1.4.11). Hoje `#6366f1` dá 4,45:1 sobre branco; um acento quente cai para ~2,2:1 e o anel de foco some da interface inteira.

**Medidor, dado e link**

| Papel | Origem | Consumidores |
|---|---|---|
| `--color-meter-track` | `--neutral-100` | `UiFileDrop.vue:167`, `Redeem.vue:178-179` |
| `--color-meter-fill` | `--brand-600` | idem |
| `--color-data-bar` | `--neutral-900` a 14% sobre `surface-sunken` | `PipelineChart.vue:32` |
| `--color-data-bar-peak` | `--brand-600` | barra do estágio mais cheio |
| `--color-link` | `--brand-700` | `UiDescriptionList.vue:45,51`, links de tabela |

Nomes escolhidos para não colidirem: `--color-info` é **status**, `--color-meter-fill` é **medidor de tarefa**, `--color-data-*` é **gráfico**. Três coisas, três nomes que não se confundem sob pressa.

Sete barras sólidas de marca empilhadas em ~200px (`Dashboard.vue:150`) são hoje a maior massa de cor da tela inicial — e a única que não é clicável nem é status. Com acento quente, viram o centro de gravidade da tela de trabalho. Daí `--color-data-bar` sair da marca.

**Superfícies e estados**

| Papel | Origem | Consumidores | Nota |
|---|---|---|---|
| `--color-canvas` | `--neutral-50` | `body` (`app.css:121`), layouts | |
| `--color-surface` | `--neutral-25` | `UiCard.vue:14`, `UiTable.vue:122`, `UiPanel.vue:16` | |
| `--color-surface-raised` | `--neutral-25` | `UiModal.vue:222`, `UiDrawer.vue:55`, `UiMenu.vue:162`, `Toaster.vue:125` | pode coincidir com `surface` no claro; o valor é ter o gancho |
| `--color-surface-sunken` | `--neutral-50` | `UiTable.vue:144` (thead), `UiTabs.vue:47`, `UiCode.vue:42`, `UiEmpty.vue:15` | |
| `--color-state-hover` | `--neutral-100` | `UiTable.vue:210`, `UiButton.vue:24-25` | ver §7, badge invisível no hover |
| `--color-state-disabled` | `--neutral-50` | `UiInput.vue:65`, `UiSelect.vue:48`, `UiTextarea.vue:53` | |
| `--color-ink-disabled` | `--neutral-500` | substitui `opacity-60` em `UiButton.vue:48`, `UiPagination.vue:127`, `UiFileDrop.vue:207`, `UiCheckbox.vue:16`, `Redeem.vue:247` | `opacity-60` sobre `ink-muted` dá ~2,4:1 e reprova AA hoje |

Hoje `--color-surface-muted` (`app.css:30`) acumula sete papéis num hex. Escurecer o `<thead>` para dar estrutura à tabela densa escurece junto o campo desabilitado e o hover de linha. Quebrar isso é a mudança que mais melhora a leitura da operação por menos esforço.

**Véu, sombra e seleção de texto**

| Papel | Origem | Consumidores |
|---|---|---|
| `--color-overlay` | neutro escuro, **não derivado de `ink`** | `UiModal.vue:212`, `UiDrawer.vue:45` |
| `--color-scrim` | neutro escuro | `UiTable.vue:178,183` |
| `--color-shadow` | `--brand-900` (pode ser levemente quente) | `--shadow-xs`, `--shadow-overlay` (`app.css:76-77`) |
| `--color-selection-bg` | `--brand-100` | `app.css:126` |
| `--color-selection-fg` | `--brand-700` | `app.css:127` — trava de 4,5:1 entre si |

`--color-shadow` e `--color-overlay` são papéis distintos de propósito: sombra é profundidade e pode esquentar junto com a paleta; véu é neutro e não deve virar amarelado porque alguém mexeu na tinta do parágrafo. Hoje as sombras escrevem `rgb(16 24 40 / …)` — o hex do `ink` transcrito em decimal, fora do alcance de qualquer `var()`.

```css
--shadow-xs: 0 1px 2px 0 color-mix(in oklab, var(--color-shadow) 5%, transparent);
--shadow-overlay:
    0 12px 32px -8px color-mix(in oklab, var(--color-shadow) 16%, transparent),
    0 4px 8px -4px  color-mix(in oklab, var(--color-shadow) 8%, transparent);
```

**Campo de formulário**

Absorve o item 6 de `docs/REVISAO-UI-UX.md:49` (`--color-field-line`), que resolve SC 1.4.11 nos mesmos cinco componentes. **Mesma correção, um nome só.**

| Papel | Origem | Consumidores |
|---|---|---|
| `--color-field-bg` | `--color-surface` | `UiInput`, `UiSelect`, `UiTextarea`, `UiSearchInput`, `UiFileDrop` |
| `--color-field-border` | `{{MARCA}}` — **≥3:1 contra `surface` E `canvas`** | `UiInput.vue:68`, `UiSelect.vue:52`, `UiTextarea.vue:56`, `UiSearchInput.vue:65`, `UiFileDrop.vue:137` |
| `--color-field-border-hover` | `--neutral-500` | idem (hoje `border-ink-subtle`, 2,58:1) |
| `--color-field-border-invalid` | `--danger-solid` | idem (hoje `border-danger`, que é token de **texto**) |

**Texto**

| Papel | Restrição |
|---|---|
| `--color-ink` | ≥12:1 sobre `surface` |
| `--color-ink-lead` | **novo.** Texto de acolhimento do portal (`Catalog.vue:37`). Peso de mensagem principal, não de legenda |
| `--color-ink-muted` | ≥4,5:1. **Piso, sem folga** |
| `--color-ink-subtle` | proibido em qualquer elemento que carregue significado. `UiSearchInput.vue:56` viola hoje (lupa é affordance, 2,58:1) → migrar para `ink-muted` |
| `--color-ink-inverse` | mantido, mas **não é mais o texto sobre a marca** — isso é `--color-on-action` |

**Celebração — papel fechado**

| Papel | Origem |
|---|---|
| `--color-accent` | `--accent-500` |
| `--color-accent-soft` | `--accent-soft` |
| `--color-accent-line` | `--accent-line` |
| `--color-on-accent` | `--accent-ink` |
| `--color-surface-gift` | `--accent-soft` — fundo do cartão do presente e fallback quando não há foto |

Escopo definido em §3.2. Não remapeia `--color-action-primary`: "Continuar" do formulário de endereço (`Redeem.vue:299`) e "Tentar buscar de novo" (`Redeem.vue:247`) não são celebração.

**Forma**

| Papel | Valor atual (etapa B) | Consumidores |
|---|---|---|
| `--radius-sunken` | `0.125rem` | `UiCode.vue:42`, `Imports/Index.vue:170` |
| `--radius-control` | `0.375rem` | botão, campo, item de menu, `:focus-visible` (`app.css:106`, hoje literal) |
| `--radius-surface` | `0.5rem` | cartão, tabela, painel, modal, drawer |
| `--radius-pill` | `9999px` | badge, avatar, ponto |

São 73 `rounded-*` hoje sem nenhum token. Raio é a alavanca mais barata que existe para diferenciar as duas superfícies, e hoje não há onde girar esse botão.

**Ritmo**

| Papel | Valor | Nota |
|---|---|---|
| `--spacing-section` | `4rem` | abre assunto novo |
| `--spacing-block` | `1.5rem` | continua o mesmo assunto |
| `--spacing-action` | `2rem` | entre o último campo e a ação primária (`Login.vue:54,89`) |
| `--spacing-card-pad` | `1rem` (app) | sobrescrito no escopo do portal |
| `--spacing-control-touch` | `2.75rem` | absorve `docs/REVISAO-UI-UX.md:63` — os 7 `min-h-11` escritos de memória |

Hoje o maior valor de espaço vertical do produto é `mt-8`, com **uma** ocorrência (`UiSectionHeading.vue:10`). Os cinco blocos do Dashboard são separados pelo mesmo espaço (`Dashboard.vue:107,148,153,194`), então nada diz "esta fila primeiro". Razão mínima de 2:1 entre seção e bloco.

### 2.4 Escopo por superfície

```html
<!-- AppLayout.vue:95 -->
<div class="min-h-dvh bg-canvas" data-surface="app">
<!-- AppLayout.vue:103 (shell is_client_admin) -->
<div class="min-h-dvh bg-canvas" data-surface="client">
<!-- PortalLayout.vue:40 -->
<div class="min-h-dvh bg-canvas" data-surface="portal">
```

```css
[data-surface='portal'] {
    --color-canvas: {{MARCA}};          /* fundo de acolhimento */
    --color-ink-lead: var(--color-ink);
    --radius-surface: 1rem;
    --radius-control: 0.625rem;
    --spacing-card-pad: 1.5rem;
    --spacing-block: 2rem;
}
```

Lista fechada. **Nada além de canvas, raio, densidade e a família `accent` pode ser sobrescrito no portal.** Campo, foco, contraste e semântica de status são herdados da operação sem exceção — é isso que impede o portal de virar um segundo sistema.

`data-surface="client"` herda tudo de `app` hoje; existe declarado para que a terceira superfície (o cliente pagante em `AppLayout.vue:103-136`) não seja improvisada depois.

Com o escopo no ar, `tone="portal"` do `PageHeader` (`PageHeader.vue:10`) fica reduzido a decisão tipográfica (§4) e a prop pode ser aposentada nos componentes que só a usavam para densidade.

### 2.5 Estrutura para tema escuro (não implementar agora)

Duas ações independentes:

**Agora, uma linha, corrige defeito real:**

```css
@layer base { html { color-scheme: light; } }
```

Sem isso, num SO em modo escuro o navegador renderiza em escuro os controles nativos que o sistema não estiliza — a lista aberta do `<select>` (`UiSelect.vue`), a barra de rolagem e o preenchimento automático. Texto escuro sobre fundo escuro nos campos de endereço do portal, no celular, sem que ninguém veja em dev.

**Estrutural, sem implementar:** o tema escuro será `[data-theme='dark']` sobrescrevendo **papéis** (nunca primitivos — §2.1). A lista mínima a sobrescrever, para que ninguém precise redescobri-la:

`canvas`, `surface`, `surface-raised` (mais claro que `surface` no escuro — a sombra não funciona lá), `surface-sunken`, `state-hover`, `state-disabled`, `line`, `line-strong`, `ink`, `ink-muted`, `ink-subtle`, `ink-disabled`, `on-action`, `field-bg`, `field-border`, `overlay`, `shadow`, `focus`, e os quatro `*-soft` semânticos (que hoje são tintas quase brancas calibradas contra branco).

Não é preciso implementar. É preciso que a estrutura não o impeça — e §2.1 garante isso.

### 2.6 Regras invioláveis

1. **Nenhum componente consome primitivo.** `brand-*`, `accent-*`, `neutral-*` só aparecem dentro do `@theme`. Verificado por teste (§6).
2. **Dois tokens nunca compartilham valor.** Se compartilham, um deles não existe. Verificado por teste.
3. **Nenhum token de status compartilha valor com nenhum token de marca.**
4. **A paleta é fechada:** `--color-*: initial;` como primeira linha do `@theme`, redeclarando `transparent` e `current` (usados em `UiButton.vue:72`, `UiBadge.vue:33`, `UiTabs.vue:56`, `UiTable.vue:178,183`). Sem isso, `bg-indigo-600` volta a compilar em silêncio — o comentário em `resources/views/app.blade.php:22-24` registra que essa deriva já aconteceu uma vez.
5. **Escopo sobrescreve papel, nunca primitivo** (§2.1).
6. **`@source '../views/**/*.blade.php';`** em `app.css:5`. Hoje só há `**/*.vue` — o 403 sobrevive por acaso, porque todas as suas classes também existem em `.vue`. As páginas de erro novas não terão essa sorte.

---

## 3. As duas superfícies

Mesmos primitivos, mesmos componentes, mesma semântica. O que muda é **o que cada papel recebe** e **o que é permitido**.

### 3.1 Área operacional (`data-surface="app"`)

Operador WeGift, 8 horas, tela densa. **Cor é informação.** "Bonito" aqui é calmo.

**Permitido**
- Cor a serviço de estado: `success`, `warning`, `danger`, `info` nas pílulas e alertas.
- `--color-action-primary` em ação e navegação ativa. Nada mais.
- `--color-data-bar` neutro no gráfico, com `--color-data-bar-peak` no estágio mais cheio.
- Sombra só no que flutua (regra vigente de `app.css:73-74`, mantida).
- Elevação em hover apenas nas linhas da fila do Dashboard (`Dashboard.vue:110-145`), que são links.

**Proibido**
- `--color-accent*` e `--color-surface-gift`: **zero ocorrências**. Verificável por grep em `resources/js/Pages/{Dashboard,Projects,Redemptions,Imports,Products,Benefits,Admin}` e nos componentes que só a operação usa.
- Raio, padding e ritmo do escopo do portal. `--spacing-control-sm` e `--spacing-control-md` (`app.css:82-83`) não mudam de valor.
- Animação de entrada de conteúdo. Um operador que navega 200×/dia paga 200× o atraso por nada.
- Cor como único portador (§7).
- Tamanho de display fora do indicador numérico.

**Regras de vocabulário de status a corrigir** (independem da paleta):

| Onde | Hoje | Passa a ser | Motivo |
|---|---|---|---|
| `StatusBadge.vue:61` `ready` | `info` (marca) | atenção | "Pronto para liberar" é a fila do operador, não "espere" |
| `StatusBadge.vue:62` `producing` | `neutral` | `info` | "em produção" é literalmente em andamento |
| `StatusBadge.vue:46` `blocked_product` | `danger` | atenção | é recuperável em 3 cliques (`Imports/Show.vue:223`); `blocked_structural` não é |
| `Dashboard.vue:78-83` `tones` | 3 tons para 3 filas | 1 tom (atenção) | são três instâncias de "precisa de você"; a contagem carrega a urgência |
| `Dashboard.vue:218` | número em `text-danger` | `font-medium text-ink` | o badge ao lado (`:219`) já diz a mesma coisa, em âmbar |
| `Products/Index.vue:168` "Kit · N" | pílula colorida | texto + contador | taxonomia não gasta cor |
| `Products/Index.vue:173` estoque 0 | `text-ink-muted` | `text-ink` + rótulo "sem estoque" | é a condição que trava a liberação, e é o texto mais apagado da tela |
| `Projects/Show.vue:485-487` situação | prosa sem cor | `StatusBadge` | é a única coluna de situação sem badge, e é a que habilita "Reenviar convite" |
| `Admin/Users/Index.vue:58` `active` | `success` | sem badge | verde gasto no caso normal é verde que não significa nada em "Entregue" |
| `StatusBadge.vue:28-35` mapa `pipeline` | código morto | remover | nenhuma página renderiza `kind="pipeline"`; 5 dos 7 estágios teriam a mesma tinta |
| `UiStat.vue` | componente órfão | remover ou usar | nenhuma página o importa |

Uma decisão a mais, verificável: **fixar 3 pesos visuais e proibir o resto.** Pílula = estado de registro; alerta = mensagem sobre a tela toda; texto tinto = qualificador inline. Converter a placa escrita à mão em `Imports/Show.vue:214-216` e a prosa âmbar em `Admin/Companies/Index.vue:291-295`. Remover o `dot` do `UiBadge` (`UiBadge.vue:33`), que repete o que o fundo já disse, e o ponto pulsante de `Imports/Index.vue:94-98`, que duplica o badge "Processando" da mesma linha.

### 3.2 Portal (`data-surface="portal"`)

Alguém que ganhou um presente. Uma vez, no celular, sem treino, vindo de e-mail. **Cor é celebração.** "Bonito" aqui é acolhedor.

**Permitido**
- `--color-accent*` em exatamente três lugares:
  1. **Revelação do presente** — cartão em `Catalog.vue:64-130`, incluindo `--color-surface-gift` como fallback quando `image_url` é nulo (`Catalog.vue:70`).
  2. **Confirmação do resgate** — o estado de tela que substitui o `UiAlert` de `Portal/Redemptions.vue:102`.
  3. **Marco "entregue"** na jornada de entrega (`Portal/Redemptions.vue:74`).
- `--color-brand-surface` na faixa superior (`PortalLayout.vue:48-86`).
- `--color-ink-lead` no texto de acolhimento.
- Raio, padding e ritmo generosos, vindos do escopo.
- Entrada escalonada de conteúdo na revelação e na confirmação, dentro do bloco `prefers-reduced-motion` que já existe (`app.css:181-186`).
- Escala de display no `<h1>` (§4).

**Proibido**
- `uppercase` + `tracking-wider`. Hoje `text-xs font-semibold tracking-wider uppercase` rotula o cabeçalho de tabela (`UiTable.vue:144`), a tabela de linhas recusadas da importação (`UiRowErrors.vue:39`) **e** "O QUE VEM DENTRO" no cartão do presente (`Catalog.vue:90`), "PRESENTE"/"VAI PARA" (`Redeem.vue:311,318`) e "CÓDIGO DE RASTREIO" (`Redemptions.vue:129`). É a prova mais direta de que o portal está pintado como formulário administrativo — e é tipografia, não cor.
- `border-dashed`. Significa "faltando" (`UiEmpty.vue:12`). "Ainda não" no portal é promessa.
- Texto abaixo da escala de corpo definida pelo layout (16px, `PortalLayout.vue:92`). Viola hoje: `UiCode` em 12px no rastreio (`UiCode.vue:42`), `UiEmpty` em 14px (`UiEmpty.vue:21-22`).
- `--color-action-primary` remapeado para o acento. O botão primário do portal continua sendo a marca; a generosidade vem de raio, escala e espaço.
- `role="alert"` no fecho da jornada (`Redeem.vue:334`).

**O que o escopo NÃO resolve e precisa de decisão explícita:** `success-soft #ecfdf3`, `warning-soft #fffaeb`, `danger-soft #fef3f2` e `info-soft` (`app.css:52-67`) são tintas quase brancas calibradas contra `surface #ffffff`. Contra um canvas quente elas somem. Ou o canvas do portal fica claro o bastante para preservá-las, ou as quatro são reajustadas dentro do escopo. **Medir os quatro pares depois de escolher o canvas, não antes.**

### 3.3 Cliente pagante (`data-surface="client"`)

`AppLayout.vue:103-136` é um shell separado para `is_client_admin`, e `Projects/Index.vue:21` reaproveita a lista operacional com `ownCompanyOnly`. Não é operador de 8 horas nem presenteado. É superfície de relação.

Regra: **densidade operacional, presença de marca no cromo.** Herda todos os papéis de `app`; a única diferença permitida é o tratamento da faixa superior (`--color-brand-surface` no cabeçalho de 56px). Nada de acento, nada de raio generoso.

---

## 4. Tipografia e ritmo

### 4.1 Escala — três patamares com salto real

Hoje: `text-xs` 82×, `text-sm` 105×, `text-base` 28× — 98% em 4px. Acima disso, 5 ocorrências no produto inteiro. `--text-stat` (`app.css:20`) é o maior tamanho do sistema e tem **um** consumidor vivo (`Dashboard.vue:121`).

| Token | Valor | Papel | Onde |
|---|---|---|---|
| `--text-display` | `2.5rem` / lh `2.75rem` | ponto focal da tela | `PageHeader` com `tone="portal"`, `AuthCard.vue:18` |
| `--text-stat` | `1.75rem` (mantido) | número de indicador | `Dashboard.vue:121` |
| `--text-section` | `1.125rem` | título de seção | `UiSectionHeading.vue:12` (hoje 16px, colado no corpo) |
| corpo app | `text-sm` | tabela, formulário | mantido |
| corpo portal | `text-base` | telas `Portal/*` | mantido |

**Regra "uma tela, um protagonista":** cada tela nomeia UM elemento que recebe o patamar de display, e ele **nunca é o `<h1>` quando existe algo mais informativo**. Dashboard: o número da fila (já correto). Portal: o presente. `Projects/Show`: o estágio, não o nome do projeto.

**Revogação necessária:** `docs/REDESIGN-UI.md:331` manda o título do portal usar `text-2xl font-semibold tracking-tight text-ink` — o mesmo do operacional. Sem revogar explicitamente, o próximo revisor rejeita o código pela regra antiga. Ver §6.

### 4.2 Pesos

`font-bold` (700) tem 5 ocorrências e **todas as 5 são o quadrado com a letra "W"**. Nenhum texto de conteúdo passa de 600, e 600 marca sete papéis diferentes (h1, h2, h3, cabeçalho de coluna, micro-rótulo, número do Dashboard, título de cartão).

Regras:
- **Tamanho é o eixo primário de hierarquia; peso é o secundário.**
- 600 limitado a dois papéis: título de tela e título de seção. Cabeçalho de tabela e micro-rótulo caem para 500.
- Rótulo de campo (`UiField.vue:61`, hoje `text-sm font-medium text-ink`) desce um degrau de tamanho e assume `--color-ink-muted`. O valor digitado fica sozinho no tamanho e na tinta primária. Isso inverte a hierarquia para o correto (o dado importa mais que o nome do dado) e não custa espaço.

### 4.3 Par tipográfico — recomendação e custo honesto

**Recomendo:** criar `--font-display` **agora**, apontando para o mesmo valor de `--font-sans`. Custo zero, risco zero, e a estrutura passa a existir. Consumidores — cinco nós, nomeados:

1. `AuthCard.vue:18` — o `<h1>` de "Entrar", o primeiro frame do produto.
2. `PageHeader.vue:51` quando `tone="portal"` — "Olá, {nome}" (`Catalog.vue:55`) e "Onde você quer receber?" (`Redeem.vue:171`).
3. `Catalog.vue:84` — o nome do presente.
4. `Redemptions.vue:117` — o nome do presente na entrega.
5. `Dashboard.vue:121` — o número da fila.

**Regra de contenção:** display proibido abaixo de 18px e proibido dentro de `UiTable`, `UiField`, `UiBadge`, `UiButton`, `UiDescriptionList` e da sidebar. Nesses lugares seria enfeite e custaria legibilidade em varredura.

**Sobre trocar a família — o custo, sem enfeite.** `docs/REDESIGN-UI.md:111` (decisão C2) recusou a troca por custo sem defeito auditado. O custo continua real e agora é mensurável:

- Hoje é uma fonte variável do Google com `preconnect` (`app.blade.php:14-16`), faixa `400..700`. O teto de 700 é do `<link>`, não do CSS: qualquer tentativa de peso extremo produz negrito sintético.
- O LCP do portal é a foto do presente, em 4G (comentário em `Catalog.vue:67-69`). Uma segunda família com bloqueio de render entra na frente disso.
- `font-feature-settings: 'cv11', 'ss01'` está cravado no `body` (`app.css:119`) e são features específicas do arquivo da Inter. Com outra família, ou não existem (inertes) ou acionam alternativas diferentes.

**Se a WeGift trouxer fonte institucional:** auto-hospedar (`woff2`), subset latino, **só os pesos efetivamente usados**, `font-display: swap`, e carregar **apenas** nas rotas de portal e autenticação. `--font-sans` continua Inter na área operacional — uma face de display em célula de tabela lida 8 horas por dia é o pior lugar possível para ela.

**Se não trouxer:** a alavanca é escala + peso, e `--font-display` continua apontando para `--font-sans`. Nesse caso é preciso ampliar a faixa do `<link>` para `400..800`, senão o contraste de peso não está disponível.

Junto disso, dois consertos: remover `tracking-tight` das duas chamadas de `<h1>` (`PageHeader.vue:51`, `AuthCard.vue:18`) — `-0.025em` do Tailwind vence a regra de `app.css:130-132`, então o valor do arquivo de tokens nunca vale; e mover `font-feature-settings` para uma variável ligada à família ativa.

### 4.4 Movimento

Existem quatro keyframes (`app.css:154-179`) e os quatro servem cromo. Zero `active:`, zero `hover:shadow`, zero animação de conteúdo. `docs/PLANO.md:68` registra que a Fase 5.4 entregou feedback de pressão e a Fase 10 removeu junto com a decoração, sem repor.

Repor exatamente três, nenhum decorativo:

1. **Pressão no botão** (`UiButton.vue:44`) — redução de escala de ~100ms. É o laço de retorno do toque no celular, onde não existe hover. Sem isso, "Quero receber" (`Catalog.vue:121`) e "Confirmar e finalizar" (`Redeem.vue:336`) não dão sinal de que o toque acertou.
2. **Entrada escalonada, só no portal** (`Catalog.vue:64-129`, `Redemptions.vue:114-160`) — a diferença entre "uma página carregou" e "algo foi apresentado a você".
3. **Elevação em hover** nas linhas da fila do Dashboard (`Dashboard.vue:110-145`), que são links.

Todos os keyframes novos entram no bloco `prefers-reduced-motion` de `app.css:181-186`.

---

## 5. Momentos de vida no portal

Em ordem de retorno.

### 5.1 A revelação do presente — `Portal/Catalog.vue:64-130`

**Hoje:** cartão com `padding="none"` + `p-5` escrito à mão (`:83`) porque o `UiCard` tem teto de 16px (`UiCard.vue:11`). Foto em `aspect-[4/3]` (`:79`) dentro de `sm:grid-cols-2` (`:64`) — com dois presentes, ~336×252px; com um, painel lateral. Quando `image_url` é nulo (`:70`), **nada é renderizado**: o presente vira "2× Caneca" numa `list-disc` com marcadores em `ink-subtle` (`:93`). O `description` do produto existe em `Product.php:11` e nunca é enviado (`PortalController.php:33-40`).

**Passa a existir:** superfície de presente com proporção fixa, `object-fit` e `--color-surface-gift` por trás (a foto vem da Omie sem recorte garantido); fallback obrigatório com ícone `gift` em escala grande ocupando a mesma área; com um presente — o caso dominante — a imagem é o maior elemento da primeira dobra, largura total antes do conteúdo; componentes como itens legíveis, sem `×` (notação de nota fiscal) e sem `list-disc`; descrição do produto na tela, em escala de leitura.

### 5.2 A chegada — `PageHeader.vue:51` + `Catalog.vue:33-39`

**Hoje:** "Olá, Yago" é `text-2xl font-semibold tracking-tight text-ink` — string idêntica à de "Importações". "A {empresa} preparou um presente para você" é `text-base text-ink-muted` (`PageHeader.vue:54`), a mesma tinta do rótulo "CEP". E some quando há presentes de mais de uma empresa (`Catalog.vue:34`).

**Passa a existir:** `tone="portal"` troca o registro do `<h1>` para `--text-display` + `--font-display`; a linha de quem presenteou vira `--color-ink-lead` e **nunca desaparece** — com múltiplas empresas ela se pluraliza, não some.

### 5.3 A confirmação — `Redeem.vue:308-338` e `Portal/Redemptions.vue:102-106`

**Hoje:** o `redeemForm()` não envia `image_url` (`PortalController.php:56-62`), então no passo irreversível o presente é um rótulo de 12px maiúsculo, o nome em 16px e os componentes achatados numa string com " · " (`Redeem.vue:71-73`). A última coisa antes do botão é um `UiAlert variant="warning"` com `role="alert"` (`:334`). A recompensa é um `UiAlert` dispensável de 12px de padding (`Redemptions.vue:102` → `UiAlert.vue:33`), renderizado **antes** do `PageHeader`, de modo que o título logo abaixo do maior momento da jornada é a palavra "Entregas". O texto (`Redemptions.vue:26-30`) fala de e-mail, não do presente.

**Passa a existir:** imagem no passo 2 (o presente é o herói, o endereço é o detalhe); aviso de irreversibilidade rebaixado a nota ancorada no próprio bloco de endereço, onde a correção é acionável, liberando o espaço antes do botão; e o sucesso vira **estado da tela**, não alerta — quando se chega vindo da confirmação, a página assume `--color-accent`, mostra o presente, o nome do produto e a empresa.

### 5.4 A espera — `Portal/Redemptions.vue:47-92, 120-126, 154-158`

**Hoje:** `confirmed` e `shipped` devolvem o mesmo par visual (`dot: 'bg-info'`, `text: 'text-ink'`) — "Em preparação" e "A caminho" são pixel a pixel idênticos. A representação é um ponto de 10px. A única data olha para trás ("Pedido feito em"), enquanto `delivery_deadline` existe (`Project.php:34`) e vai só para o operador (`ProjectController.php:36`).

**Passa a existir:** progresso de três marcos, reaproveitando os segmentos já construídos em `Redeem.vue:177-180`; data prevista de chegada como o elemento de maior peso do cartão; marco final "entregue" como o único que acende `--color-accent`. Contagem regressiva é o que transforma espera em expectativa.

### 5.5 O rastreio — `Portal/Redemptions.vue:128-138`

**Hoje:** `UiCode` em `font-mono text-xs` (`UiCode.vue:42`) — 12px numa tela cujo corpo é 16px, para o dado que a pessoa abriu a página para copiar, dígito a dígito, no celular. O componente foi dimensionado para chave de NF em célula de tabela.

**Passa a existir:** `UiCode` com registro de portal — sobe para a escala de corpo e ganha superfície de destaque; compacto na operação.

### 5.6 O "ainda não" — `UiEmpty.vue:12` + `Catalog.vue:57-62` + `Redemptions.vue:108`

**Hoje:** `border-dashed`, círculo cinza sobre cinza, título 14px (menor que o corpo do portal) e — para "Você ainda não pediu nenhum presente" — o ícone de um **caminhão de carga**.

**Passa a existir:** variante de portal sem tracejado, escala ≥ corpo, superfície de acolhimento e ícone que fale de presente. Na operação, mantido exatamente como está.

### 5.7 A chegada antes do login — `AuthCard.vue`, `Login.vue:37`, e-mail

**Hoje:** o e-mail diz "A {companyName} preparou um presente para você" e o botão "Resgatar meu presente" (`EmailTemplate.php:36-45`); o destino é `/login`, que diz "Entrar — Acesse com o e-mail que recebeu o convite". A frase quente só reaparece uma tela depois. A tela de entrada é a única do produto que **não escreve o nome da marca** — `AuthCard.vue:12-17` tem um quadrado com "W" e `aria-hidden`. O único enfeite do sistema (`.bg-mesh`, `app.css:138-142`) está na tela de login, não na do presente.

**Passa a existir:** `BrandMark` com símbolo + wordmark em texto real; linha de contexto herdada do convite ("A {empresa} preparou algo para você. Crie sua senha para abrir."), ausente quando não há contexto; e a atmosfera derivada de tokens, com sentido diferente por superfície.

### 5.8 O movimento

Ver §4.4. É o recurso mais barato de vida disponível: o easing (`--ease-out-soft`), a convenção de nomes e o bloco `prefers-reduced-motion` já existem.

---

## 6. Plano de migração

Duas etapas obrigatórias, com o precedente já usado no projeto (`docs/REDESIGN-UI.md:122`, decisão C13): **primeiro adicionar papéis com os valores atuais e migrar consumidor por consumidor; só na última etapa trocar os valores.**

Motivo: em Tailwind v4, `bg-brand-600` sem `--color-brand-600` **não gera CSS** — sem erro de build, sem aviso, elemento sem fundo. E não há rede: `package.json:9-22` não tem eslint nem stylelint, e os testes usam `withoutVite()` e nunca renderizam Vue (`docs/CI-CD.md:157-159`, `docs/PLANO.md:306-307`).

### Etapa A — Rede de segurança (não depende da marca)

- [ ] Teste PHPUnit que lê `resources/css/app.css`, extrai os `--color-*`/`--radius-*`/`--spacing-*` e falha se: (a) dois tokens têm o mesmo valor; (b) existe classe `bg-|text-|border-|ring-|from-|to-|fill-|stroke-|outline-|accent-|decoration-` em `resources/js` ou `resources/views` sem token correspondente; (c) `brand-|accent-|neutral-` aparece fora do `@theme`. É o único guardião possível com esta suíte, e é barato.
- [ ] `@source '../views/**/*.blade.php';` em `app.css:5`.
- [ ] `color-scheme: light` no `@layer base` de `html`.
- [ ] Revogar por escrito, em `docs/REDESIGN-UI.md`, com motivo item a item: §4.3 regra 1 (lista fechada de famílias — `accent`, `data`, `meter`, `selected`, `overlay`, `on-action`, `field-*` são ilegais hoje); §4.3 regra 2 (raios permitidos — o raio macio do portal é ilegal hoje); §4.2 linha 331 (título do portal idêntico ao operacional); C2/C12 (fonte e escala). Sem a tabela de revogações, a porta de revisão rejeita o código novo pela regra antiga.
- [ ] Absorver de `docs/REVISAO-UI-UX.md` os itens que são token e declarar que saem da lista de lá: item 6 (`--color-field-line` → vira `--color-field-border`), e de `:63` `--spacing-control-touch`, lupa em `ink-subtle`, hex cru de `UiSelect.vue:72` e `app.js:36`, `UiCode` fixo em 12px. Duas frentes editando `app.css` em paralelo é retrabalho garantido.

### Etapa B — Papéis com os valores de hoje (invisível)

- [ ] Declarar todos os papéis de §2.3 apontando para os valores atuais.
- [ ] Declarar `--radius-*`, `--spacing-section/block/action/card-pad/control-touch`, `--text-display`, `--text-section`, `--font-display` (= `--font-sans`).
- [ ] Completar a rampa em 300/400/800/900 e a família `accent` — ainda com valores provisórios derivados do índigo.
- **Critério de aceite:** o CSS compilado não muda nenhuma cor renderizada. Diff visual zero.

### Etapa C — Migrar consumidores (invisível)

- [ ] Substituir `brand-*` por papel nos 23 arquivos: `UiButton.vue:23`, `UiTabs.vue:55`, `UiTimeline.vue:25`, `UiInput.vue:75`, `UiCheckbox.vue:23`, `UiFileDrop.vue:131,133,167`, `UiDescriptionList.vue:45,51`, `UiPagination.vue:133`, `PipelineChart.vue:32`, `ShipControl.vue:197`, `AppLayout.vue:120,174,181,186,198`, `Portal/Redeem.vue:178`, `Admin/EmailTemplates/Index.vue:377,412`, `Projects/Show.vue:695,708,723,761`, `errors/403.blade.php:25`, `app.css:126-127`.
- [ ] Substituir `text-ink-inverse` por `--color-on-action` nos 10 pontos listados em §2.3.
- [ ] `rounded-*` → `--radius-*` nos componentes-base; `:focus-visible` (`app.css:106`) aponta para `var(--radius-control)`.
- [ ] `--color-focus: var(--brand-500)` vira token independente.
- [ ] `--color-info-*` deixa de duplicar `brand-*` (ainda com valor provisório distinto).

### Etapa D — Inventário do que está fora do token

Cada item abaixo é uma tarefa. **Nenhum depende da marca.**

- [ ] `app.css:76-77` — sombras com `rgb(16 24 40 / …)` (o hex do `ink` em decimal) → `color-mix(in oklab, var(--color-shadow) N%, transparent)`.
- [ ] `app.css:140-141` — `.bg-mesh` com `rgb(99 102 241)` e `rgb(129 140 248)` (indigo-400, degrau inexistente) → `color-mix(in oklab, var(--color-brand-atmosphere) 10%, transparent)`.
- [ ] `UiSelect.vue:72` — chevron em data-URI com `stroke='%23667085'` → `mask-image` + `background-color: var(--color-ink-muted)`, ou `<UiIcon>` posicionado.
- [ ] `app.js:36` — `progress.color: '#0f172a'` (slate-900, que não é token nenhum) → ler `--color-action-primary` em runtime via `getComputedStyle`. É a animação mais frequente do produto.
- [ ] `UiModal.vue:212`, `UiDrawer.vue:45` — `bg-ink/40` → `--color-overlay`.
- [ ] `UiTable.vue:178,183` — `from-ink/10` → `--color-scrim`.
- [ ] `opacity-60` em `UiButton.vue:48`, `UiPagination.vue:127`, `UiFileDrop.vue:207`, `UiCheckbox.vue:16`, `Redeem.vue:247` → `--color-ink-disabled`. **Reprova AA hoje** (~2,4:1 sobre `ink-muted`).
- [ ] `Projects/Show.vue:708` — botão primário reconstruído à mão → prop `external`/`as="a"` no `UiButton`.
- [ ] `errors/403.blade.php:25` — idem, em Blade → classe `.btn-primary` em `@layer components`, consumida pelos dois mundos.
- [ ] `errors/403.blade.php:10` — `min-h-screen` contra `min-h-dvh` de `AuthCard.vue:9`.
- [ ] O "W" copiado 6× em 3 tamanhos e 2 raios (`AuthCard.vue:12`, `AppLayout.vue:108,144,214`, `PortalLayout.vue:51`, `errors/403.blade.php:13`) → componente `BrandMark` + partial Blade equivalente.
- [ ] Nome da aplicação em 3 fontes de verdade (`app.blade.php:6` via `config('app.name')`, `app.js:10` via `VITE_APP_NAME`, e 5 strings à mão) → uma só, vinda das props do Inertia.
- [ ] `app.blade.php` sem `<link rel="icon">` (existe `public/favicon.ico` não declarado), sem `apple-touch-icon`, sem `theme-color`, sem `description`, sem `og:image`, sem `<noscript>`, sem shell de marca antes do Vue montar.
- [ ] Cor decidida na página: `Dashboard.vue:78-83` e `Portal/Redemptions.vue:47-92` devolvem **classes**; passam a devolver **papel**, como `StatusBadge.vue:27` já faz. Com isso, a lista de arquivos a auditar cai de "todas as páginas" para "os componentes Ui".
- [ ] Escopo `data-surface` nos três layouts.
- [ ] Correções de vocabulário de status da tabela em §3.1.
- [ ] Os 5 pontos onde cor é o único portador: `ShipControl.vue:190,196-198` (rádio `sr-only`, estado só cromático → check + `font-semibold`), `Admin/Users/Index.vue:236-238` (chip pressionado), `Products/Index.vue:173` (estoque zero → rótulo), `Dashboard.vue:120-121` (três pontos idênticos de cores diferentes → ícone por fila), `Dashboard.vue:218`.
- [ ] Badge invisível no hover de linha: `UiTable.vue:209-211` pinta a linha com `surface-muted`, e os fundos das pílulas dão 1,00:1 a 1,07:1 contra ela. Trocar o hover de fundo por borda esquerda de 2px, **e** elevar `ring-*-line` para ≥3:1 contra `surface` (hoje 1,3–1,5:1).
- [ ] `UiField.vue:63` — asterisco de obrigatório em `text-danger` **antes** de qualquer erro → `ink-muted` (o `sr-only` de `:64` já carrega o significado). Num formulário de 14 campos obrigatórios, o vermelho do erro real não se destaca.
- [ ] `UiAlert.vue:22` — `role` colapsa info+success em `status` e warning+danger em `alert`; adicionar palavra de papel ou `sr-only` com o nome da variante.
- [ ] `UiCheckbox.vue:23` — `accent-brand-600` entrega a cor do "✓" ao navegador. Desenhar com SVG.
- [ ] Estado de carregamento: `UiTable.vue:13` tem a prop `loading`, renderiza `UiSkeleton` (`:154-158`) e anuncia em `role="status"` (`:187`) — e **nenhuma página passa `:loading`**. Ligar na operação. `Products/Index.vue:79-91` faz polling com `router.reload`, então a barra pisca em intervalo fixo sem que nada diga "ainda processando".
- [ ] Páginas de erro: existe só `403.blade.php`. `docs/DEPLOY.md:74` roda `php artisan down --render=errors::503` — **todo deploy mostra a página padrão do framework**. Criar 404, 419, 500, 503 sobre a casca pública Blade extraída.
- [ ] Remover código morto que seria repintado por engano: mapa `pipeline` de `StatusBadge.vue:28-35`, `UiStat.vue`, prop `interactive` de `UiCard.vue:8`.

### Etapa E — Fechar a paleta

- [ ] `--color-*: initial;` como primeira linha do `@theme`, redeclarando `transparent` e `current`. Conferir o CSS compilado.

### Etapa F — Depende das cores da marca

- [ ] Substituir os valores do bloco `:root` de primitivos. **Um arquivo, uma vez.**
- [ ] Medir a lista de contraste de §7.1 **antes** de qualquer hex entrar no arquivo.
- [ ] Reajustar (ou não) os quatro `*-soft` contra o canvas do portal — decisão pendente de §3.2.
- [ ] E-mail: `vendor:publish --tag=laravel-mail` e reescrever `default.css` com hex literais gerados dos mesmos papéis. Hoje valem ~35 hexes do tema `default` (botão primário `#18181b`, fundo `#fafafa`) e é **a primeira tela do portal**.
- [ ] E-mail, copy: reescrever os três `body` de `EmailTemplate::definitions()` (`app/Models/EmailTemplate.php:26-88`) e `resources/views/mail/account-invitation.blade.php:4` ("plataforma WeLog"). **Verificar antes quais linhas de `email_templates` já estão editadas em produção** — o que vence é a linha do banco (`render()`, `:99-103`), e o admin tem "restaurar padrão".
- [ ] Prévia do editor de e-mail (`Admin/EmailTemplates/Index.vue:141-143`) renderiza `#` com tokens do app; o e-mail real sai com outra escala. Passar a consumir o CSS de e-mail real (iframe).
- [ ] `APP_NAME` / `MAIL_FROM_NAME` (`.env:1`, `.env.example:77`): decidir o contrato de nomes. Hoje "WeGift" aparece em **um** lugar da interface (`AppLayout.vue:152`, sidebar do operador) — a única tela que o presenteado nunca vê.
- [ ] Substituir o `BrandMark` pelo SVG; favicon SVG+PNG, apple-touch-icon, `theme-color` por superfície, `og:image`.

---

## 7. Riscos

### 7.1 Contraste — o risco principal, e o único com critério objetivo

As razões estão anotadas nos próprios tokens (`app.css:33-35`) e há uma revisão de acessibilidade aberta com medições. Transformar em lista verificável, com o valor medido escrito ao lado, **antes de qualquer hex entrar**:

| Par | Piso | Hoje |
|---|---|---|
| `on-action` × `action-primary` | 4,5:1 | 6,3:1 |
| `focus` × `surface` e × `canvas` | 3:1 | 4,45:1 sobre branco |
| `field-border` × `surface` e × `canvas` | 3:1 | **1,49:1 / 1,39:1 — reprova hoje** |
| cada texto semântico × seu `*-soft` | 4,5:1 | 5,2–7,1:1 (é essa folga que vai ser gasta) |
| anel `*-line` × `surface` | 3:1 | **1,3–1,5:1 — reprova hoje** |
| fundo do badge × linha em hover | 3:1 | **1,00–1,07:1 — invisível hoje** |
| `selection-fg` × `selection-bg` | 4,5:1 | — |
| `ink` × `surface` | 12:1 | 16,7:1 |
| `ink-muted` × `surface` | 4,5:1 | **4,9:1 — piso, sem folga** |
| `ink-disabled` × `surface` | 4,5:1 | **~2,4:1 — reprova hoje** |
| ícone da busca (`UiSearchInput.vue:56`) | 3:1 | **2,58:1 — reprova hoje** |

Cinco desses já reprovam **antes** da troca. Aquecer os neutros quase sempre sobe luminância e piora todos.

### 7.2 O acento quente cai em cima de `warning`

Hoje marca (índigo) e `warning` (`#b54708`, `app.css:56`) estão em extremos opostos do círculo cromático. Um acento de celebração quente fica na vizinhança do âmbar — e `warning` é o papel mais usado da operação (expedir, linha pendente, lote com bloqueios, cadastro incompleto) com significado **aprendido**.

Mitigação já escrita em §3.1: o acento é **proibido** na área operacional. Se ainda assim colidir, empurrar `warning` para ocre/oliva mais profundo — deslocar o matiz sem trocar a leitura.

### 7.3 Densidade da operação — orçamento em número

Raio maior, padding maior e ritmo maior vazam com facilidade. Critérios de aceite:

- `--spacing-control-sm` e `--spacing-control-md` (`app.css:82-83`) **não mudam de valor**.
- A lista de Resgates mostra o **mesmo número de linhas** em 1366×768 antes e depois.
- Nenhum degrau generoso existe fora de `[data-surface='portal']` — grep verificável.

O repositório já tem o precedente: `--spacing-control-lg` está marcado como "portal e autenticação".

### 7.4 Retrabalho por renomear token

Renomear em Tailwind v4 é **falha silenciosa**. Mitigação: etapas B e C são invisíveis por construção, e o teste da etapa A pega a classe órfã. Adicional: as famílias `success/warning/danger/info` **não** são renomeadas — muda o valor de `info`, não o nome. Renomear quatro famílias em 19 páginas não paga o risco.

### 7.5 Verificação

Não há regressão visual automatizada, `/public/build` é ignorado (`.gitignore:17`) e o deploy compila dentro da imagem (`docs/CI-CD.md:62`). **Build no host Windows não vale como verificação** — `node_modules` é Linux.

Conjunto mínimo de conferência manual no Docker, ao fim das etapas B, D e F:

1. Resgates (operação densa, com hover de linha e badge).
2. Importações/Show com linhas bloqueadas.
3. Portal/Catalog com foto **e** sem foto.
4. Redeem passo 2.
5. Autenticação (`.bg-mesh`).
6. 403 e 503 (via `artisan down`).
7. Os dois e-mails no Mailpit.
8. Um `<select>` aberto com o SO em modo escuro.

### 7.6 Rollout

Todo `push` na `main` publica em produção, sem preview e sem aprovação intermediária, e a mudança altera a aparência de 100% das telas. Por isso as etapas B/C/D (invisíveis ou corretivas) vão ao ar separadas da etapa F (troca de valores), com a conferência de §7.5 entre elas.

### 7.7 A foto do presente vira o LCP

`Catalog.vue:70-81` usa `image_url` espelhado da Omie (`app/Services/Catalog/MirrorOmieProduct.php:55`): domínio externo, proporção desconhecida, fundo desconhecido, sem proxy nem otimização. Foto ruim ampliada é pior que foto pequena. Mitigação: proporção fixa + `object-fit` + `--color-surface-gift` atrás, e **medir o LCP com uma foto real de produção antes de fechar o tamanho**.

---

## 8. O que preciso da marca

Enviar este pedido **antes** de começar a etapa F. Sem ele, a estrutura fica pronta e parada.

**Arquivos**
1. Logo em SVG: símbolo isolado e versão horizontal (símbolo + wordmark).
2. Versão para fundo claro e versão para fundo escuro.
3. Área de proteção e tamanho mínimo de leitura, em px.
4. Favicon SVG e PNG (32, 180 para apple-touch, 512 para manifest).

**Cores — em sRGB, hex**
5. **Base institucional profunda** — a cor de credibilidade B2B. Preencherá `--brand-800/900`, `--color-brand-surface` e `--color-shadow`.
6. **Cor de ação** — a que pinta o botão primário. Preencherá `--brand-600`, com o degrau de hover (`--brand-700`).
7. **Acento de celebração** — o quente. Preencherá `--accent-500`.
8. **Se a marca tiver só duas cores:** dizer quem deriva a rampa e por qual critério (luminância constante? escala perceptual em OKLCH?). Não vou inventar isso.

**Exigências funcionais que decorrem dos achados — precisam vir da marca, não do desenvolvedor**
9. **Para cada cor sólida, o par de texto legível sobre ela**, com contraste ≥4,5:1. Isso preenche `--color-on-action`, `--color-on-accent` e `--color-on-brand-surface`. Não é detalhe: é o token que quebra primeiro, e um acento quente reprova AA contra branco em toda a faixa laranja/âmbar.
10. **Um degrau claro** da marca (equivalente a 300/400) para atmosfera e gradiente — a rampa curta já custou um valor fora do sistema em `app.css:141`.
11. **Um degrau escuro** (800/900) — sem ele não existe base institucional profunda; o mais escuro disponível hoje já é a tinta de texto.
12. **Temperatura dos neutros:** matiz e croma para `--neutral-hue` / `--neutral-chroma`. Se a marca não tiver opinião, eu proponho — mas com luminância travada nos valores atuais.

**Tipografia**
13. A marca tem fonte institucional? Se sim: nome, licença de uso web e arquivos `woff2`. Se não, digo agora que a alavanca vira escala + peso e a Inter fica.

**Decisão de nome (não é CSS, e trava tudo abaixo)**
14. O que a pessoa que ganha o presente deve ler: **WeGift** ou **WeLog**? Isso define `APP_NAME`, `MAIL_FROM_NAME`, o remetente do e-mail, o `<title>`, o wordmark da tela de entrada e o cabeçalho do portal. Hoje "WeGift" aparece em exatamente um lugar da interface, e é a única tela que o presenteado nunca vê.

---

## Crítica de completude

# Lacunas da crítica — o que os seis diagnósticos deixaram de fora

---

## 1. Superfícies que ninguém (ou quase ninguém) olhou

**1.1 O corpo do e-mail é Markdown do banco, editável pelo Admin — restilizar a moldura não muda a voz.**
Só uma dimensão olhou e-mail, e apenas a moldura (tema `default` do Laravel). O que ficou fora: o texto vem de `D:\Projetos\welog-staging\app\Models\EmailTemplate.php:26-88` (definições padrão) mas quem vence é a linha do banco (`render()`, :99-103). Ou seja: (a) o que a marca pode expressar no e-mail está limitado ao que o Markdown produz — `#`, `**`, botão; (b) a produção pode já ter textos editados que nenhuma mudança de código alcança; (c) o texto padrão diz "plataforma WeLog" (`resources/views/mail/account-invitation.blade.php:4`) e assina `config('app.name')` (`templated.blade.php:11`).
**Fazer:** tratar copy e moldura como um item só. Publicar `vendor/mail` e gerar o CSS do e-mail dos mesmos papéis do `@theme` (hex literais — e-mail não resolve custom property), reescrever os três `body` de `definitions()`, e incluir no rollout a checagem de quais linhas de `email_templates` estão editadas (o admin já tem "restaurar padrão").

**1.2 O nome que chega na caixa de entrada é "WeLog", não a marca.**
`D:\Projetos\welog-staging\.env:1` (`APP_NAME=WeLog`) alimenta `MAIL_FROM_NAME` (`.env.example:77`), a assinatura dos dois templates, o `<title>` (`resources\views\app.blade.php:6`) e o título do documento (`resources\js\app.js:10,13`). "WeGift" aparece em exatamente um lugar da interface: `resources\js\Layouts\AppLayout.vue:152` — a sidebar do operador, a única tela que o presenteado nunca vê.
**Fazer:** decidir explicitamente o contrato de nomes (WeGift = marca para quem ganha o presente; WeLog = plataforma para quem opera) e reduzir a uma fonte de verdade, incluindo remetente do e-mail. Isso é decisão de marca, não de CSS, e trava tudo mais abaixo.

**1.3 A prévia de e-mail do admin mente sobre o e-mail entregue.**
`D:\Projetos\welog-staging\resources\js\Pages\Admin\EmailTemplates\Index.vue:141-143` renderiza `#` como `text-base font-semibold text-ink` e `##` como `text-sm`, dentro de um `UiCard` com tokens do app (`:448-468`). O e-mail real sai pelo tema do Laravel, com outra escala e outras cores. Quem escreve a comunicação do presente nunca vê o que o cliente recebe — e depois da troca a distância aumenta.
**Fazer:** a prévia consome os mesmos tokens de e-mail (iframe com o CSS real é o caminho honesto). É também o lugar mais barato para validar a marca no e-mail sem enviar nada.

**1.4 A página de manutenção (503) é acionada pelo próprio deploy e não existe.**
`D:\Projetos\welog-staging\docs\DEPLOY.md:74` roda `php artisan down --render=errors::503`, e `resources\views\errors\` tem só `403.blade.php`. Todo deploy mostra a página padrão do framework. 404, 419 (CSRF vencido em tela de login aberta) e 500 idem — a dimensão de autenticação citou 404/419, ninguém citou o 503 que o processo de deploy invoca.
**Fazer:** extrair a casca pública Blade (já necessária para o 403) e criar 404/419/500/503 sobre ela; validar com `artisan down` antes de fechar.

**1.5 Existe uma TERCEIRA superfície e a tensão central foi escrita com duas.**
`D:\Projetos\welog-staging\resources\js\Layouts\AppLayout.vue:103-136` é um shell separado para `is_client_admin` (header de 56px, `max-w-5xl`, sem sidebar), e `resources\js\Pages\Projects\Index.vue:21` reaproveita a lista operacional com `ownCompanyOnly`. Esse é o **cliente pagante** olhando o produto — não é operador de 8 horas nem presenteado emocionado. Nenhuma das seis dimensões o mencionou; e é a tela em que a WeGift é avaliada por quem assina o contrato.
**Fazer:** a especificação precisa dizer em qual registro esse shell entra. Recomendação: calma operacional na densidade, presença de marca no cromo (é superfície de relação, não de produção) — e isso é um terceiro valor do escopo de tema, não um caso do portal.

**1.6 Estado de carregamento: a maquinaria existe, está completa e está 100% desligada.**
`D:\Projetos\welog-staging\resources\js\Components\UiTable.vue:13` tem a prop `loading`, renderiza `UiSkeleton` (`:154-158`) e anuncia "Carregando…" em `role="status"` (`:187`); `resources\css\app.css:172-186` tem `.skeleton` com `prefers-reduced-motion`. Nenhuma página passa `:loading` — varredura em `resources\js\Pages` retorna zero. Logo, a única linguagem de carregamento do produto é a barra do Inertia (`resources\js\app.js:35-38`, `#0f172a` cravado, `showSpinner: false`) e o spinner do botão. Agrava: `resources\js\Pages\Products\Index.vue:79-91` faz polling com `router.reload`, então a barra pisca em intervalo fixo enquanto nada na página diz "ainda processando"; e a busca de CEP do portal pode levar até 8s com feedback só dentro do campo.
**Fazer:** definir a linguagem de espera por superfície — operação usa o skeleton que a tabela já suporta; portal usa espera com antecipação — e ligar a barra do Inertia ao token de ação. Sem isso, depois da troca o carregamento continua sendo a única superfície sem marca nenhuma.

**1.7 Antes do Vue montar: falta o caso "JS não roda".**
A dimensão de autenticação cobriu o shell vazio, favicon, `theme-color` e og. Ficou de fora: `resources\views\app.blade.php` não tem `<noscript>`. O público do portal é celular, uso único, link de e-mail, muitas vezes em aparelho corporativo com bloqueio — nesse caso a pessoa fica com um retângulo `#f6f7f9` para sempre, sem uma linha dizendo o que houve.
**Fazer:** shell de marca dentro da div do Inertia + `<noscript>` com a mensagem e um caminho (e-mail de suporte / reabrir no navegador). Custo: bytes.

---

## 2. Decisões tratadas como dadas quando eram decisões escritas — e precisam ser revogadas por escrito

**2.1 "O `tone=portal` não toca o título" não é esquecimento: está especificado.**
`D:\Projetos\welog-staging\docs\REDESIGN-UI.md:331` manda o título do portal usar exatamente `text-2xl font-semibold tracking-tight text-ink` — o mesmo do operacional. A dimensão do portal e a de tipografia diagnosticaram isso como lacuna; é decisão vigente.
**Fazer:** a nova spec precisa de uma tabela de REVOGAÇÕES, item por item, com o motivo. Sem ela, o próximo revisor rejeita o código pela regra antiga (é literalmente o que §4.3 existe para fazer).

**2.2 A porta de revisão em vigor proíbe quase tudo que foi proposto.**
`docs\REDESIGN-UI.md:343-350`: a regra 1 enumera as famílias de cor permitidas (`canvas|surface|surface-muted|line|line-strong|ink|ink-muted|ink-subtle|brand|success|warning|danger|info|focus`) — `accent`, `progress`, `data`, `selected`, `overlay`, `on-brand` são ilegais hoje; a regra 2 proíbe `rounded-xl`/`rounded-2xl` — o "raio mais macio do portal" é ilegal hoje.
**Fazer:** reescrever §4.3 junto com os tokens, mantendo o formato de regra verificável. É a única governança que funciona neste repositório (não há lint — ver 4.1).

**2.3 Trocar a fonte já foi discutido e recusado por custo; ninguém recolocou o custo.**
`docs\REDESIGN-UI.md:111` (decisão C2) e `:121` (C12). Três dimensões pedem `--font-display` e/ou faixa de peso maior. Hoje é uma fonte variável do Google com preconnect (`resources\views\app.blade.php:14-16`), e o LCP do portal é a foto do presente (comentário em `resources\js\Pages\Portal\Catalog.vue:67-69`), em 4G.
**Fazer:** revogar C2/C12 com orçamento explícito: display auto-hospedada, subset latino, só os pesos usados, `font-display: swap`, e escopo de rota (portal/auth) se for uma segunda família. Ou assumir que a marca não traz fonte e a alavanca é escala + peso.

**2.4 "Aquecer os neutros" foi tratado como consequência óbvia da direção.**
`resources\css\app.css:34` diz, no próprio arquivo, que `ink-muted` está no PISO (4,9:1). Aquecer quase sempre sobe luminância. Ninguém escreveu a regra.
**Fazer:** aquecer por matiz/croma com luminância CONSTANTE, token a token, re-medindo cada razão anotada. `ink-muted` não pode subir de luminância; `ink-subtle` já reprova hoje (2,58:1) e vira o mais provável a piorar.

**2.5 "Preparar para tema escuro" entrou como boa prática sem ninguém perguntar se existe no roadmap.**
A reestruturação em duas camadas tem custo real e uma restrição dura de Tailwind (ver 3.1). O que é urgente ali é uma linha: `color-scheme: light`, que corrige defeito de hoje.
**Fazer:** separar as duas coisas. `color-scheme` entra agora; a arquitetura de duas camadas só entra se tema escuro for decisão de produto.

---

## 3. Contradições entre dimensões

**3.1 Duas camadas de token e override por escopo são mutuamente exclusivos em Tailwind v4 — e as duas coisas foram recomendadas juntas.**
A dimensão de tokens propõe primitivos crus num `:root` com o `@theme` referenciando-os por papel, e o escopo sobrescrevendo **os primitivos**; e, no mesmo documento, propõe `.theme-portal { --color-action-primary: var(--color-accent-celebration) }`, que sobrescreve **o papel**. Só uma funciona por vez: sem `inline`, a utilitária gera `var(--color-x)` (override de papel funciona; mas `--color-x: var(--primitivo)` é substituído no `:root` e o override do primitivo não alcança os descendentes); com `@theme inline`, a utilitária inlina `var(--primitivo)` e passa a valer o inverso — override de primitivo funciona, override de papel não faz nada. Fonte: documentação oficial de theme variables (seção de `@theme inline`).
**Fazer:** escolher uma. Recomendação: **uma camada só** — papéis literais no `@theme`, sem `var()` entre tokens — e `.theme-portal` sobrescrevendo papéis. É o que serve a tensão central e é o que resolve o problema do cliente.

**3.2 O acento de celebração tem dois escopos incompatíveis.**
"Portal" e "Valores fora do token" fecham em três lugares e proibem o resto; "Arquitetura de tokens" propõe remapear `--color-action-primary` no escopo do portal, o que pinta TODO botão primário — inclusive "Continuar" do formulário de endereço (`resources\js\Pages\Portal\Redeem.vue:299`) e "Tentar buscar de novo" (`:247`). Consequências diferentes de significado e de contraste.
**Fazer:** decidir e escrever. Sugestão: o acento é papel fechado (revelação, confirmação, marco "entregue"); a ação primária do portal continua sendo marca, com generosidade vindo de raio, escala e espaço.

**3.3 Colisão de nome entre três propostas: `--color-progress`.**
Uma dimensão usa `--color-progress` como semântica de status ("em andamento, não faça nada", frio e dessaturado); outra usa `--color-progress-fill` para preenchimento de upload/passo/gráfico; a terceira usa `--color-progress` para o preenchimento e ainda cria `--color-data-1..n`. Três significados, dois nomes quase iguais — deriva garantida na primeira revisão.
**Fazer:** `--color-state-inprogress` (status) e `--color-meter-fill` (medidor/upload/passo) e `--color-data-*` (gráfico). Nomes que não se confundem quando alguém está com pressa.

**3.4 Sombra quente × véu neutro.**
Duas dimensões querem a tinta de sombra derivada da base institucional (quente) como "alavanca barata de vida"; outra exige `--color-overlay` explicitamente não derivado de `ink` e neutro. São o mesmo mecanismo em papéis diferentes.
**Fazer:** dois tokens declarados com a razão escrita: `--color-shadow` (elevação, pode ser levemente quente) e `--color-overlay` (véu de modal/drawer, neutro escuro).

**3.5 As famílias semânticas dentro do escopo do portal ficaram sem dono.**
Uma dimensão quer `ready` e `blocked_product` migrando para âmbar (mais âmbar na operação); outra quer o âmbar rebaixado no fecho do resgate; e a direção quente coloca o acento perto do âmbar. Pior, e concreto: `success-soft #ecfdf3`, `warning-soft #fffaeb`, `danger-soft #fef3f2` e `info-soft #eef2ff` (`resources\css\app.css:52-67`) são tintas quase brancas calibradas contra `surface #ffffff` e `canvas #f6f7f9` — a própria dimensão de cor mediu 1,00–1,07:1 contra `surface-muted`. Num canvas quente, elas somem.
**Fazer:** a regra do escopo tem que dizer se as quatro famílias são reajustadas dentro de `.theme-portal` ou se o canvas do portal fica claro o bastante para preservá-las — e re-medir os quatro pares depois, não antes.

---

## 4. Restrições práticas de implementação que ninguém levantou

**4.1 Renomear token em Tailwind v4 é falha SILENCIOSA, e não existe nenhuma rede neste repositório.**
`bg-brand-600` sem `--color-brand-600` não gera CSS: sem erro de build, sem aviso, elemento sem fundo. E: não há eslint nem stylelint (`D:\Projetos\welog-staging\package.json:9-22` tem só vite/tailwind/vue/inertia); os testes usam `withoutVite()` (`docs\CI-CD.md:157-159`) e **nunca renderizam Vue** (`docs\PLANO.md:306-307`, escrito pelo próprio projeto).
**Fazer:** (a) migração em duas etapas, exatamente como o precedente C13 (`docs\REDESIGN-UI.md:122`): primeiro adicionar os papéis novos apontando para os valores atuais e migrar componente por componente; só na última etapa remover os antigos. (b) Adicionar um teste que leia `resources\css\app.css`, extraia os tokens e varra `resources\js` + `resources\views` por classes `bg-|text-|border-|ring-|from-|to-` sem token correspondente. É barato e é o único guardião possível com esta suíte.

**4.2 Fechar a paleta (`--color-*: initial`) tem efeito colateral concreto.**
O reset apaga também `transparent`, `current`, `white`/`black`. O repo usa `to-transparent` (`resources\js\Components\UiTable.vue:178,183`) e `bg-current` (`UiBadge.vue:33`).
**Fazer:** fazer o reset junto da troca, redeclarando `transparent` e `current`, e conferir o CSS compilado.

**4.3 Classe que só existe em Blade pode não ser gerada.**
`resources\css\app.css:5` declara `@source` apenas para `**/*.vue`; o Blade depende da autodetecção. Hoje o 403 sobrevive porque todas as suas classes também existem em `.vue`. As páginas de erro novas (404/419/503) e qualquer partial Blade de marca não terão essa sorte.
**Fazer:** acrescentar `@source '../views/**/*.blade.php';` — uma linha, risco zero, e destrava tratar Blade como parte do sistema (inclusive o botão primário duplicado em `resources\views\errors\403.blade.php:25`).

**4.4 Não há como verificar visualmente fora do Docker, e não há regressão visual nenhuma.**
`/public/build` é ignorado (`.gitignore:17`), o deploy compila dentro da imagem (`docs\CI-CD.md:62`) e a VPS tem pico de ~500 MB no build (`docs\CI-CD.md:83-97`). A Fase 11 conferiu no CSS compilado (`docs\PLANO.md:383-385`) — é o método que existe.
**Fazer:** a spec precisa listar o conjunto mínimo de conferência manual no Docker: operação densa (Resgates e Importações/Show), portal com foto e sem foto, Redeem passo 2, autenticação, 403, e o e-mail no Mailpit. E dizer que build no host Windows não vale como verificação.

**4.5 A troca colide com uma revisão de UI/UX aberta, de hoje, nos mesmos arquivos.**
`D:\Projetos\welog-staging\docs\REVISAO-UI-UX.md:49` (item 6 de "Corrigir primeiro") cria `--color-field-line: #7d8798` para resolver SC 1.4.11 em `UiInput/UiSelect/UiTextarea/UiSearchInput/UiFileDrop` — a dimensão de tokens propõe `--color-field-border-*` para os mesmos cinco componentes: mesma correção, dois nomes. `:63` pede `--spacing-control-touch: 2.75rem` (os 7 `min-h-11` escritos de memória no portal), a lupa em `ink-subtle`, o hex cru do `UiSelect` e do `app.js` — todos rediagnosticados pelas dimensões.
**Fazer:** a spec de cor ABSORVE os itens de token da revisão e declara que saem da lista dela. Duas frentes editando `app.css` em paralelo é retrabalho garantido — e a revisão tem prioridade porque contém defeito funcional (o loop de 403, `docs\REVISAO-UI-UX.md:39`).

---

## 5. Riscos que ninguém mencionou

**5.1 Regressão de contraste não tem critério de aceite, e o produto não tem teste que renderize tela.**
As razões estão anotadas nos tokens (`resources\css\app.css:33-35`) e a revisão mediu vários pares.
**Fazer:** transformar as razões em lista verificável antes de qualquer hex entrar: `on-brand` × ação ≥4,5; `focus` × surface E × canvas ≥3; borda de campo × surface E × canvas ≥3; cada texto semântico × seu `soft` ≥4,5 (hoje 5,2–7,1 — há folga, e é ela que vai ser gasta); anel semântico × surface ≥3 (hoje 1,3–1,5); `selection-fg` × `selection-bg` ≥4,5; e o par que a dimensão de cor mediu em 1,00:1 — fundo do badge × linha em hover. Cada item com o valor medido escrito ao lado, como já se faz em `app.css`.

**5.2 Risco de densidade sem orçamento declarado.**
Raio maior, padding maior e ritmo maior vazam com facilidade da spec do portal para o resto. Nada hoje impede.
**Fazer:** escrever o orçamento em número: `--spacing-control-sm/md` (`app.css:82-83`) não mudam; a lista de Resgates mostra o mesmo número de linhas em 1366×768 antes e depois; nenhum degrau generoso do portal existe fora de `.theme-portal`. O repositório já tem o precedente do `--spacing-control-lg` marcado como "portal e autenticação".

**5.3 Risco de retrabalho: ninguém escreveu o pedido para a WeGift.**
Todo o trabalho é "estrutura que recebe a marca", e não existe a lista do que a marca precisa entregar para caber nela.
**Fazer:** enviar o brief antes de implementar — logo em SVG (símbolo e horizontal, versão para fundo claro e escuro), área de proteção e tamanho mínimo, favicon/apple-touch, valores em sRGB, e duas exigências funcionais que decorrem dos achados: o acento precisa vir com um par de texto legível (≥4,5:1) definido pela marca, e a marca precisa de um degrau claro (300/400, hoje já furado pelo `.bg-mesh`) e um escuro (800/900, sem o qual não existe base institucional profunda). Se a marca tiver só duas cores, dizer quem deriva a rampa e por qual critério.

**5.4 Promover a foto do presente a herói coloca o LCP do portal numa CDN de terceiro.**
`resources\js\Pages\Portal\Catalog.vue:70-81` usa `image_url` espelhado da Omie (`app\Services\Catalog\MirrorOmieProduct.php:55`): domínio externo, proporção desconhecida, fundo desconhecido, sem proxy nem otimização. Duas dimensões pedem a foto grande; foto ruim ampliada é pior que foto pequena.
**Fazer:** definir a superfície de presente com proporção fixa, `object-fit` e fundo de marca por trás (a foto raramente vem recortada), o fallback obrigatório quando `image_url` é nulo, e medir o LCP com uma foto real de produção antes de fechar o tamanho.

**5.5 Todo `push` na `main` publica em produção.**
Não há preview visual, não há aprovação intermediária e a mudança é de aparência em 100% das telas.
**Fazer:** a spec precisa dizer como isso vai ao ar — etapa 1 (tokens novos com valores atuais, invisível) separada da etapa 2 (troca dos valores), com a conferência do 4.4 entre as duas.

---

# Achados detalhados

### 1. Não existe o papel "texto/ícone sobre a marca" — 11 pontos assumem branco sobre brand-600

**Categoria:** token-faltante · **Dimensão:** Arquitetura de tokens

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Components\UiButton.vue:23`, `D:\Projetos\welog-staging\resources\js\Components\UiPagination.vue:133`, `D:\Projetos\welog-staging\resources\js\Layouts\AppLayout.vue:110`, `D:\Projetos\welog-staging\resources\js\Layouts\AppLayout.vue:146`, `D:\Projetos\welog-staging\resources\js\Layouts\AppLayout.vue:216`, `D:\Projetos\welog-staging\resources\js\Layouts\PortalLayout.vue:53`, `D:\Projetos\welog-staging\resources\js\Components\AuthCard.vue:14`, `D:\Projetos\welog-staging\resources\js\Pages\Projects\Show.vue:708`, `D:\Projetos\welog-staging\resources\views\errors\403.blade.php:13`, `D:\Projetos\welog-staging\resources\views\errors\403.blade.php:25`, `D:\Projetos\welog-staging\resources\css\app.css:36`

O par `bg-brand-600 text-ink-inverse` está escrito à mão em 10 lugares, e `--color-ink-inverse` é literalmente `#ffffff` (app.css:36). Ou seja: o sistema não tem um token que diga "a cor legível EM CIMA da marca" — tem um token que diz "branco", e alguém decidiu, arquivo por arquivo, que branco serve. O mesmo vale para `bg-danger-solid text-ink-inverse` (UiButton.vue:26). Hoje funciona por sorte aritmética: brand-600 (#4f46e5) contra branco dá 6,3:1.

**Consequência.** Este é o ponto que quebra PRIMEIRO na troca de paleta. Um acento quente de celebração — exatamente a direção escolhida — tem luminância alta: qualquer laranja/âmbar de celebração fica na faixa de 2,5–3,0:1 contra branco, reprovando WCAG AA (4,5:1) para o rótulo do botão primário. E como o par está espalhado em 10 arquivos, não existe um lugar único para corrigir: ou se rebaixa o acento até ele deixar de ser celebratório, ou se editam 10 arquivos.

**Recomendação.** Criar o papel `--color-on-brand` (texto/ícone sobre superfície de marca) e trocar `text-ink-inverse` por ele nos 10 pontos. Ele NÃO é branco por definição — é o valor que a marca exigir. Esse é o token que permite ao acento quente existir sem reprovar contraste: se o acento for claro, `--color-on-brand` recebe a tinta escura da marca. Regra de aceite: `--color-action-primary` + `--color-on-brand` ≥ 4,5:1, verificado antes de qualquer hex entrar no arquivo. Fazer o mesmo par para `danger-solid`.

### 2. `info` não é um alias da marca — são 3 hexes duplicados byte a byte, e `focus` é um quarto

**Categoria:** papel-mal-definido · **Dimensão:** Arquitetura de tokens

**Arquivos:** `D:\Projetos\welog-staging\resources\css\app.css:41`, `D:\Projetos\welog-staging\resources\css\app.css:43`, `D:\Projetos\welog-staging\resources\css\app.css:44`, `D:\Projetos\welog-staging\resources\css\app.css:46`, `D:\Projetos\welog-staging\resources\css\app.css:65`, `D:\Projetos\welog-staging\resources\css\app.css:66`, `D:\Projetos\welog-staging\resources\css\app.css:67`, `D:\Projetos\welog-staging\resources\css\app.css:69`, `D:\Projetos\welog-staging\resources\css\app.css:50`

São quatro pares idênticos, escritos duas vezes cada: brand-50 `#eef2ff` = info-soft `#eef2ff`; brand-200 `#c7d2fe` = info-line `#c7d2fe`; brand-700 `#4338ca` = info `#4338ca`; brand-500 `#6366f1` = focus `#6366f1`. O comentário em app.css:50-51 declara a intenção — "`info` é a própria marca: o sistema não tem dois azuis" — mas a intenção não está codificada em lugar nenhum: são 8 literais independentes. Dos 29 tokens de cor do `@theme`, só 25 têm valor distinto.

**Consequência.** Na troca de paleta, quem editar a rampa `brand` vai atualizar 6 linhas e o arquivo continuará compilando — mas `info` e `focus` ficam para trás com o índigo antigo. O sistema passa a ter exatamente os dois azuis que o comentário promete não ter, e o pior: o azul remanescente estará em `info` (5 dos 7 estágios do pipeline) e no anel de foco de TODA a interface, ou seja, nas duas coisas mais repetidas da tela. Uma duplicação que hoje é invisível vira a falha mais visível do redesign.

**Recomendação.** Introduzir indirecção: `--color-info: var(--color-brand-700)` e `--color-focus: var(--color-brand-500)` — ou, melhor, decidir que são papéis separados e dar tinta própria a `info`. As duas respostas são defensáveis; o que não é defensável é ter a decisão gravada só num comentário. Se `focus` seguir a marca, ele precisa de trava de contraste própria (≥3:1 contra `surface` E contra `canvas`, SC 1.4.11) — hoje #6366f1 dá 4,45:1 sobre branco, mas um acento quente cai para ~2,2:1 e o foco de teclado some.

### 3. 5 dos 7 estágios do pipeline são pintados com `info`, que é a marca — a tabela operacional inteira depende disso

**Categoria:** papel-mal-definido · **Dimensão:** Arquitetura de tokens

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Components\StatusBadge.vue:29`, `D:\Projetos\welog-staging\resources\js\Components\StatusBadge.vue:43`, `D:\Projetos\welog-staging\resources\js\Components\UiBadge.vue:15`, `D:\Projetos\welog-staging\resources\js\Components\UiAlert.vue:15`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redemptions.vue:56`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redemptions.vue:65`

Em StatusBadge.vue:29-42, `design`, `production`, `picking`, `packing` e `invoiced` mapeiam todos para a variante `info`; em importRow (linhas 43-51), `order_created`, `invoiced` e `dispatched` também. A variante `info` do UiBadge (UiBadge.vue:15) resolve para `bg-info-soft text-info ring-info-line` = os valores exatos de brand-50/brand-700/brand-200. Traduzindo: "em andamento" e "esta é a nossa marca" são a mesma tinta. No portal, `bg-info` pinta "Em preparação" e "A caminho" (Redemptions.vue:56,65) — a jornada do presente é contada em azul-informação.

**Consequência.** Na direção quente, a marca vira o acento de celebração e arrasta `info` junto (se houver alias) ou o deixa órfão (se não houver — ver achado anterior). Se arrastar: `info` fica quente e passa a colidir com `warning` (#b54708, também quente) na MESMA tabela — o operador perde, num relance, a diferença entre "seguindo normal" e "precisa de você". Isso é o oposto do que a área operacional pede: cor ali é informação, e duas informações opostas ficariam com a mesma temperatura.

**Recomendação.** Separar `info` de `brand` como papel de primeira classe e definir que o eixo de status operacional é FRIO e neutro (progresso/estado), reservando o quente exclusivamente para `warning` e para o acento de celebração — que não aparece na área operacional. Regra explícita na spec: nenhum token de status pode compartilhar hex com nenhum token de marca. Vale checar com um teste automatizado sobre o app.css (dois tokens com o mesmo valor = falha de build).

### 4. A rampa da marca já foi furada: o fundo das telas públicas usa um indigo-400 cru que não existe como token

**Categoria:** valor-fora-do-token · **Dimensão:** Arquitetura de tokens

**Arquivos:** `D:\Projetos\welog-staging\resources\css\app.css:138`, `D:\Projetos\welog-staging\resources\css\app.css:140`, `D:\Projetos\welog-staging\resources\css\app.css:141`, `D:\Projetos\welog-staging\resources\css\app.css:41`, `D:\Projetos\welog-staging\resources\js\Components\AuthCard.vue:9`

A utilitária `.bg-mesh` (app.css:138-142) — única camada decorativa do sistema, aplicada no fundo de toda tela de autenticação em AuthCard.vue:9 — escreve `rgb(99 102 241 / 0.10)` e `rgb(129 140 248 / 0.10)`. O primeiro é `#6366f1`, que É brand-500. O segundo é `#818cf8` = indigo-400 do Tailwind — um degrau que NÃO EXISTE na rampa (a rampa tem 50,100,200,500,600,700). Ou seja: quando alguém precisou de um brand-400, não achou, e colou o hex do Tailwind direto. É a prova de que a rampa incompleta já custou uma decisão fora do sistema.

**Consequência.** Duas coisas quebram na troca. (1) `.bg-mesh` não passa por `var()` — trocar todos os `--color-*` do arquivo NÃO muda esse fundo; a tela de login continua índigo enquanto o resto da marca fica quente. (2) É a primeira tela que qualquer pessoa vê ao entrar no sistema, e é uma das duas únicas superfícies com atmosfera hoje. Fica a incoerência mais visível possível: a porta de entrada com a paleta velha.

**Recomendação.** Reescrever `.bg-mesh` com `color-mix(in oklab, var(--color-brand-400) 10%, transparent)` (ou `var()` dentro de `rgb()` via canais tokenizados) e completar a rampa em 300/400/800/900. A rampa precisa dos degraus claros (300/400) porque é deles que sai atmosfera — gradiente, halo, fundo celebratório do portal — e dos escuros (800/900) porque é deles que sai a base institucional profunda que a direção pediu. Sem 800/900 não existe superfície escura de marca; hoje a única forma de escurecer é `brand-700`, que já é o texto sobre fundo soft.

### 5. Não existe camada semântica por intenção: 45 linhas em 23 arquivos consomem `brand-*` primitivo direto

**Categoria:** token-faltante · **Dimensão:** Arquitetura de tokens

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Components\UiButton.vue:23`, `D:\Projetos\welog-staging\resources\js\Components\UiTabs.vue:55`, `D:\Projetos\welog-staging\resources\js\Components\UiTimeline.vue:25`, `D:\Projetos\welog-staging\resources\js\Components\UiInput.vue:75`, `D:\Projetos\welog-staging\resources\js\Components\UiCheckbox.vue:23`, `D:\Projetos\welog-staging\resources\js\Components\UiFileDrop.vue:131`, `D:\Projetos\welog-staging\resources\js\Components\UiFileDrop.vue:133`, `D:\Projetos\welog-staging\resources\js\Components\UiFileDrop.vue:167`, `D:\Projetos\welog-staging\resources\js\Components\UiDescriptionList.vue:45`, `D:\Projetos\welog-staging\resources\js\Components\UiDescriptionList.vue:51`, `D:\Projetos\welog-staging\resources\js\Components\UiPagination.vue:133`, `D:\Projetos\welog-staging\resources\js\Components\PipelineChart.vue:32`, `D:\Projetos\welog-staging\resources\js\Components\ShipControl.vue:197`, `D:\Projetos\welog-staging\resources\js\Layouts\AppLayout.vue:174`, `D:\Projetos\welog-staging\resources\js\Layouts\AppLayout.vue:181`, `D:\Projetos\welog-staging\resources\js\Layouts\AppLayout.vue:186`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redeem.vue:178`, `D:\Projetos\welog-staging\resources\js\Pages\Admin\EmailTemplates\Index.vue:377`, `D:\Projetos\welog-staging\resources\css\app.css:126`

Varredura completa: `brand-(50|100|200|500|600|700)` aparece em 45 linhas de uso, distribuídas por 23 arquivos (21 .vue, 1 .blade.php e o próprio app.css no `::selection`, linhas 126-127). Nenhum nível intermediário existe. `bg-brand-600` significa nove coisas diferentes conforme o arquivo: ação primária (UiButton:23), página corrente da paginação (UiPagination:133), marcador do item ativo da sidebar (AppLayout:181), ponto mais recente da linha do tempo (UiTimeline:25), preenchimento do gráfico (PipelineChart:32), barra de upload (UiFileDrop:167), spinner do campo (UiInput:75), passo concluído do portal (Redeem:178) e cor nativa do checkbox (UiCheckbox:23).

**Consequência.** Trocar a paleta obriga a abrir 23 arquivos e decidir, um a um, se aquele `brand-600` era ação, seleção, progresso ou dado — informação que o código não guarda. Pior: as nove intenções acima não devem receber a mesma tinta no sistema novo. Progresso de upload e ação primária compartilharem cor é aceitável no índigo neutro; com um acento quente de celebração, a barra de upload vira festa. E como não há camada intermediária, não existe forma de mudar só uma delas.

**Recomendação.** Inserir a camada por intenção entre a rampa e os componentes, e proibir `brand-*` fora dela. Mínimo necessário, lido dos usos reais acima: `--color-action-primary` / `--color-action-primary-hover` / `--color-on-brand` (UiButton, UiPagination, 403); `--color-selected-bg` / `--color-selected-fg` / `--color-selected-marker` (sidebar, abas, rádio de transportadora, chips); `--color-progress-fill` (upload, passos do portal, gráfico); `--color-link` (UiDescriptionList, links de tabela); `--color-accent-celebration` (só portal, ver achado próprio). Depois disso, trocar a paleta = editar um arquivo. A rampa `brand-*` vira detalhe de implementação e nenhum componente a enxerga.

### 6. Overlay, scrim e seleção de texto não são papéis — são opacidades tiradas do token de texto e da rampa da marca

**Categoria:** papel-mal-definido · **Dimensão:** Arquitetura de tokens

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Components\UiModal.vue:212`, `D:\Projetos\welog-staging\resources\js\Components\UiDrawer.vue:45`, `D:\Projetos\welog-staging\resources\js\Components\UiTable.vue:178`, `D:\Projetos\welog-staging\resources\js\Components\UiTable.vue:183`, `D:\Projetos\welog-staging\resources\css\app.css:126`, `D:\Projetos\welog-staging\resources\css\app.css:127`

O véu de modal e drawer é `bg-ink/40` — o token de TEXTO PRIMÁRIO rebaixado a 40%. As sombras de borda da tabela rolável são `from-ink/10`. E `::selection` (app.css:126-127) pinta com `--color-brand-100` sobre `--color-brand-700`, consumindo a rampa primitiva direto. Nenhum dos três papéis — overlay, scrim de rolagem, seleção de texto — tem token.

**Consequência.** Overlay e texto ficam amarrados: qualquer ajuste no `--color-ink` para os neutros quentes pedidos muda a temperatura do véu de todo modal e drawer, sem que ninguém tenha pedido isso. E `bg-ink/40` só funciona porque o ink é quase preto — se a base institucional profunda entrar no ink, o véu pode ficar leitoso ou opaco demais e não há como corrigir sem mexer no texto. O `::selection` em brand-100/700 é o caso mais direto: se a marca virar quente e clara, selecionar texto vira amarelo sobre laranja, ilegível, e o problema aparece em TODA a aplicação de uma vez.

**Recomendação.** Criar `--color-overlay` (véu de modal/drawer), `--color-scrim` (sombra de rolagem da tabela) e o par `--color-selection-bg` / `--color-selection-fg`. Os três são papéis independentes por natureza: overlay quer neutro escuro com opacidade, seleção quer o par mais legível possível. Definir `--color-selection-*` com trava de 4,5:1 entre si, testada com a paleta WeGift antes de entrar.

### 7. As sombras congelam o RGB do `ink` em decimal — nenhum `var()` alcança elas

**Categoria:** valor-fora-do-token · **Dimensão:** Arquitetura de tokens

**Arquivos:** `D:\Projetos\welog-staging\resources\css\app.css:76`, `D:\Projetos\welog-staging\resources\css\app.css:77`, `D:\Projetos\welog-staging\resources\css\app.css:33`

`--shadow-xs: 0 1px 2px 0 rgb(16 24 40 / 0.05)` e `--shadow-overlay: ... rgb(16 24 40 / 0.16) ...`. `rgb(16 24 40)` é exatamente `#101828`, que é `--color-ink` (app.css:33) — escrito de novo, em decimal, sem referência. A sombra existe como token de elevação (bom), mas a TINTA dentro dela não. São 10 pontos de uso: os 6 `shadow-xs` dos controles e os 6 `shadow-overlay` de modal/drawer/menu/toast/skip-link.

**Consequência.** É uma das quatro coisas que sobrevivem intactas à troca da paleta (junto com `.bg-mesh`, o chevron do UiSelect e a barra do Inertia). Toda sombra do sistema continuará com o azul-acinzentado do índigo antigo por baixo, contra neutros quentes — o resultado é aquele cinza sujo que aparece quando duas temperaturas se sobrepõem em baixa opacidade. Ninguém vai saber nomear o problema; vão só achar que "ficou meio sujo".

**Recomendação.** Tokenizar a tinta da sombra: `--color-shadow` como papel próprio, e escrever as sombras com `color-mix(in oklab, var(--color-shadow) 5%, transparent)`. Sombra colorida é, aliás, a alavanca barata de "vida" que a direção quente pede: uma sombra levemente quente sob os cartões custa uma linha e muda a percepção de profundidade sem adicionar nenhuma cor à tela.

### 8. Três valores de cor cravados fora de qualquer token: chevron do select, barra do Inertia e o `#0f172a` fantasma

**Categoria:** valor-fora-do-token · **Dimensão:** Arquitetura de tokens

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Components\UiSelect.vue:72`, `D:\Projetos\welog-staging\resources\js\app.js:36`, `D:\Projetos\welog-staging\resources\css\app.css:33`, `D:\Projetos\welog-staging\resources\css\app.css:34`

(1) UiSelect.vue:72 embute o chevron como data-URI com `stroke='%23667085'` — o hex de `--color-ink-muted` copiado para dentro de uma string. É a seta de TODO `<select>` do sistema. (2) app.js:36 define a barra de progresso do Inertia como `color: '#0f172a'`. Esse valor não existe em nenhum token: é o slate-900 do Tailwind, enquanto o ink do sistema é `#101828`. São duas tintas quase iguais e nenhuma é a outra — mais um resíduo do estado "ninguém escolheu". (3) A barra do Inertia é o feedback de navegação mais frequente do produto e está em cinza-quase-preto, não na marca.

**Consequência.** Nenhum dos três muda quando a paleta mudar. O chevron continua cinza-azulado dentro de campos com borda quente — visível lado a lado com o spinner do UiInput, que É tokenizado. E a barra do Inertia é a oportunidade desperdiçada mais óbvia: é a única animação que aparece em toda navegação, e hoje ela não diz nada sobre a marca.

**Recomendação.** Trocar o data-URI por uma máscara CSS (`mask-image` + `background-color: var(--color-ink-muted)`) ou por um `<UiIcon>` posicionado — o ícone deixa de carregar cor. E ligar `progress.color` do Inertia à ação primária lendo o token em runtime (`getComputedStyle(document.documentElement).getPropertyValue('--color-action-primary')`). Custo baixo, e transforma a barra de carregamento em presença de marca em 100% das navegações.

### 9. Raio de canto não é token: os 73 `rounded-*` herdam a escala padrão do Tailwind e o foco crava 0.375rem

**Categoria:** token-faltante · **Dimensão:** Arquitetura de tokens

**Arquivos:** `D:\Projetos\welog-staging\resources\css\app.css:7`, `D:\Projetos\welog-staging\resources\css\app.css:106`, `D:\Projetos\welog-staging\resources\js\Components\UiButton.vue:44`, `D:\Projetos\welog-staging\resources\js\Components\UiCard.vue:14`, `D:\Projetos\welog-staging\resources\js\Components\UiModal.vue:222`

O bloco `@theme` (app.css:7-89) tokeniza tipografia, cor, sombra, densidade e easing — mas não tem uma linha `--radius-*`. As 73 ocorrências de `rounded-*` no frontend usam a escala default: `rounded-md` (0.375rem) para controles, `rounded-lg` (0.5rem) para contêineres, `rounded-full` para pílulas. A convenção é consistente e correta; o que falta é ela existir como decisão. Além disso, app.css:106 escreve `border-radius: 0.375rem` cru dentro do `:focus-visible` — o mesmo valor do `rounded-md`, duplicado sem referência.

**Consequência.** É o mesmo defeito de origem do `brand-600 = indigo-600`: o raio atual é o que aparece quando ninguém escolheu. Numa modernização, o raio é uma das duas ou três alavancas mais fortes de personalidade — e a que mais barato diferencia as duas superfícies (operacional pede raio contido e denso; portal pede raio generoso e macio). Hoje não há onde girar esse botão: mudar o raio significa reescrever 73 classes utilitárias em 37 arquivos, e o `:focus-visible` ficaria para trás.

**Recomendação.** Tokenizar `--radius-control`, `--radius-surface` e `--radius-pill` no `@theme` e substituir os `rounded-*` por eles; apontar o `:focus-visible` para `var(--radius-control)`. Feito isso, a diferenciação portal × operacional passa a ser um override de escopo de duas linhas em vez de um refactor. Essa é a alavanca mais barata de "não parece mais um formulário administrativo" que existe no sistema.

### 10. Zero estrutura para tema escuro — e a ausência de `color-scheme` já causa defeito hoje

**Categoria:** token-faltante · **Dimensão:** Arquitetura de tokens

**Arquivos:** `D:\Projetos\welog-staging\resources\css\app.css:7`, `D:\Projetos\welog-staging\resources\css\app.css:91`, `D:\Projetos\welog-staging\resources\views\app.blade.php:2`, `D:\Projetos\welog-staging\resources\views\app.blade.php:25`

Varredura por `prefers-color-scheme`, `dark:`, `@custom-variant`, `.dark`, `data-theme` e `color-scheme` em todo `resources/`: zero resultado. Não há gancho de tema nenhum. Os 29 tokens de cor são literais fixos num único `@theme`, sem camada primitiva por baixo. E `color-scheme` nunca é declarado nem no `<html>` (app.blade.php:2) nem no CSS.

**Consequência.** Duas consequências, uma futura e uma presente. Futura: adicionar tema escuro depois exige reescrever os 29 literais como pares e criar o escopo do zero — e as quatro tintas fora de token (sombras, `.bg-mesh`, chevron do select, barra do Inertia) ficariam claras num tema escuro, sem correção possível. Presente: sem `color-scheme: light` declarado, num SO em modo escuro o navegador pode renderizar em escuro os controles nativos que o sistema não estiliza — a LISTA aberta do `<select>` (UiSelect.vue), a barra de rolagem e o preenchimento automático de campos. Texto escuro em fundo escuro nos formulários de endereço do portal, no celular, sem que ninguém veja isso em dev.

**Recomendação.** Duas ações independentes. Curto prazo (corrige bug real, custo de uma linha): declarar `color-scheme: light` no `@layer base` do `html`. Estrutural: separar o arquivo em duas camadas — primitivos crus (`--brand-500`, `--neutral-700`…) num `:root` normal, e o `@theme` referenciando-os por papel. Assim um futuro `.theme-dark` (ou o escopo do portal) sobrescreve só os primitivos, sem duplicar a camada semântica. Não é preciso implementar o tema escuro agora; é preciso que a estrutura não o impeça.

### 11. Não existe papel de acento/celebração — o portal pinta o momento mais emocional com tokens de status administrativo

**Categoria:** token-faltante · **Dimensão:** Arquitetura de tokens

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Pages\Portal\Catalog.vue:104`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redemptions.vue:56`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redemptions.vue:74`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redemptions.vue:102`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redeem.vue:178`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redeem.vue:215`, `D:\Projetos\welog-staging\resources\js\Components\UiCard.vue:14`

O ponto alto do produto — a pessoa confirmar que quer o presente — é renderizado assim: "Pedido confirmado" em `text-success` (Catalog.vue:104), o mesmo verde que valida um CEP em Redeem.vue:215; "Entregue" em `text-success` (Redemptions.vue:74); "Resgate confirmado!" num `UiAlert variant="success"` (Redemptions.vue:102), o mesmo componente de um aviso de importação bem-sucedida; "Em preparação" e "A caminho" em `bg-info` (Redemptions.vue:56,65), o azul de informação administrativa. O cartão do presente é o `UiCard` da área operacional: `rounded-lg border border-line bg-surface` (UiCard.vue:14) — a mesma caixa de uma tabela de projetos.

**Consequência.** É a raiz técnica do "sem vida" que o cliente relatou. Não é falta de cor: é que o sistema literalmente não tem uma tinta cujo significado seja "isto é bom para VOCÊ". Tem "a operação deu certo" (success) e "eis um dado" (info), e os dois são vocabulário de painel administrativo. Se a paleta WeGift entrar sem esse papel, o portal fica com cores novas e a mesma temperatura emocional — o cliente vai dizer que continua sem vida e ninguém vai saber por quê.

**Recomendação.** Criar `--color-accent-celebration` (+ `--color-accent-celebration-soft` e `--color-on-accent`) como papel de primeira classe, com escopo declarado: existe SÓ no PortalLayout, e só em três lugares — o estado de presente liberado/confirmado (Catalog.vue:104), o cabeçalho de saudação (Catalog.vue:55 via PageHeader) e a confirmação de resgate (Redemptions.vue:102). Zero ocorrências na área operacional; isso precisa estar escrito na spec como regra, não como recomendação. E o cartão do presente precisa deixar de ser `UiCard`: ele é um papel próprio (superfície celebratória) e é onde o acento quente ganha o direito de ser generoso.

### 12. Superfície elevada não é papel: modal, drawer, menu e toast usam o mesmo `bg-surface` do cartão

**Categoria:** papel-mal-definido · **Dimensão:** Arquitetura de tokens

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Components\UiModal.vue:222`, `D:\Projetos\welog-staging\resources\js\Components\UiDrawer.vue:55`, `D:\Projetos\welog-staging\resources\js\Components\UiMenu.vue:162`, `D:\Projetos\welog-staging\resources\js\Components\Toaster.vue:125`, `D:\Projetos\welog-staging\resources\js\Components\UiCard.vue:14`, `D:\Projetos\welog-staging\resources\css\app.css:29`

`--color-surface` (#ffffff) atende a dois papéis que a própria documentação do arquivo mistura no comentário de app.css:29 ("cartão, tabela, modal, drawer"). Hoje o que separa o modal do cartão é exclusivamente a sombra (`shadow-overlay`) — e o menu (UiMenu.vue:162) adiciona `border border-line`, enquanto o modal (UiModal.vue:222) não tem borda nenhuma. Não há token de superfície elevada.

**Consequência.** Sobre canvas quase-branco, a sombra basta. Sobre um canvas quente e mais saturado — a direção pedida —, uma sobreposição branca sem diferenciação de superfície flutua mal, e a única alavanca disponível é aumentar a sombra, que engrossa a interface. E se o tema escuro entrar depois, elevação PRECISA de superfície mais clara (sombra some no escuro): não haverá token para isso. O menu ter borda e o modal não é o sintoma de que a regra nunca foi formalizada.

**Recomendação.** Criar `--color-surface-raised` como papel distinto de `--color-surface`, e definir de uma vez a regra de elevação: superfície elevada = `surface-raised` + `shadow-overlay`, com ou sem borda, mas igual nos quatro componentes. No tema claro `surface-raised` pode até coincidir com `surface`; o valor é ter o gancho pronto e um único lugar para mudar.

### 13. `surface-muted` acumula 7 papéis conflitantes num único hex

**Categoria:** papel-mal-definido · **Dimensão:** Arquitetura de tokens

**Arquivos:** `D:\Projetos\welog-staging\resources\css\app.css:30`, `D:\Projetos\welog-staging\resources\js\Components\UiTable.vue:144`, `D:\Projetos\welog-staging\resources\js\Components\UiTable.vue:210`, `D:\Projetos\welog-staging\resources\js\Components\UiInput.vue:65`, `D:\Projetos\welog-staging\resources\js\Components\UiTabs.vue:47`, `D:\Projetos\welog-staging\resources\js\Components\UiCode.vue:42`, `D:\Projetos\welog-staging\resources\js\Components\UiEmpty.vue:15`, `D:\Projetos\welog-staging\resources\css\app.css:177`

`--color-surface-muted` (#f8fafc) é simultaneamente: fundo do `<thead>` (UiTable.vue:144), hover de linha da tabela (UiTable.vue:210), fundo de campo desabilitado (UiInput.vue:65, UiSelect.vue:48, UiTextarea.vue:53), hover do botão ghost e secondary (UiButton.vue:24-25), trilho das abas segmentadas (UiTabs.vue:47), fundo do chip de código (UiCode.vue:42), fundo do ícone do estado vazio (UiEmpty.vue:15) e cor do skeleton (app.css:177). O comentário na definição admite três desses papéis; o código usa sete.

**Consequência.** Trava a hierarquia da área operacional, que é exatamente onde o cliente vai avaliar "ficou mais legível". Escurecer o cabeçalho da tabela para dar estrutura à tela densa escurece junto todo campo desabilitado (que passaria a parecer preenchido) e o hover de linha (que deixaria de se distinguir do cabeçalho). O sistema não tem como resolver isso: os sete papéis são um valor só.

**Recomendação.** Quebrar em papéis com nome: `--color-surface-sunken` (thead, trilho de abas, chip de código — fundos recuados), `--color-state-hover` (hover de linha e de botão), `--color-state-disabled` (campo desabilitado). No claro os três podem começar próximos; o ganho é poder afastá-los depois sem colateral. É a mudança que mais melhora a leitura da tabela densa por menos esforço.

### 14. `accent-brand-600` no checkbox entrega a cor do "✓" para o navegador decidir

**Categoria:** papel-mal-definido · **Dimensão:** Arquitetura de tokens

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Components\UiCheckbox.vue:23`, `D:\Projetos\welog-staging\resources\js\Pages\Admin\Users\Index.vue:336`

O único checkbox do sistema (UiCheckbox.vue:23, replicado em Admin/Users/Index.vue:336) usa a propriedade nativa `accent-color`. Ela pinta o preenchimento, mas a cor da marca de seleção é derivada automaticamente pelo navegador — branco ou preto conforme ele julgar o contraste. Não existe token nem controle para isso.

**Consequência.** Com o índigo escuro atual o navegador escolhe branco e o resultado é previsível. Com um acento de luminância intermediária — a faixa típica de um acento quente — o resultado passa a divergir entre Chrome, Firefox e Safari, e num deles o "✓" pode simplesmente sumir. O checkbox aparece em toda a atribuição de permissões (Admin/Users) e em toda seleção de produtos por projeto; é controle crítico, não decoração.

**Recomendação.** Não apontar `accent-color` para o acento de marca. Ou manter o checkbox nativo com uma cor de seleção travada em contraste alto (papel `--color-control-selected`, escolhido para dar ≥4,5:1 contra branco E contra preto), ou desenhar o checkbox com SVG — o que dá controle total sobre fundo e marca, e é a única forma de garantir consistência entre navegadores após a troca de paleta.

### 15. Os dois layouts têm raiz idêntica — não há gancho de escopo para o portal receber tratamento próprio

**Categoria:** oportunidade · **Dimensão:** Arquitetura de tokens

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Layouts\PortalLayout.vue:40`, `D:\Projetos\welog-staging\resources\js\Layouts\AppLayout.vue:95`, `D:\Projetos\welog-staging\resources\js\Layouts\PortalLayout.vue:92`, `D:\Projetos\welog-staging\resources\js\Layouts\AppLayout.vue:223`, `D:\Projetos\welog-staging\resources\js\Components\PageHeader.vue:10`, `D:\Projetos\welog-staging\resources\css\app.css:82`

PortalLayout.vue:40 e AppLayout.vue:95 são a MESMA string: `<div class="min-h-dvh bg-canvas">`. Não há classe de tema, `data-surface` nem qualquer seletor onde pendurar overrides. O sistema já reconhece que as duas superfícies são diferentes — PageHeader tem `tone: 'app' | 'portal'` (PageHeader.vue:10), o portal força `text-base` no `<main>` (PortalLayout.vue:92) contra o herdado do App (AppLayout.vue:223), e `--spacing-control-lg` está documentado como "portal e autenticação" (app.css:84). Mas essa distinção é aplicada prop a prop, componente a componente, nunca por escopo.

**Consequência.** É o que impede resolver a tensão central do produto — duas superfícies com necessidades opostas servidas pela mesma base. Hoje, diferenciar o portal exige adicionar uma prop `tone` em cada componente que ele usa e propagar caso a caso. Foi por isso que a diferenciação parou na tipografia e na densidade e nunca chegou à cor: o custo por componente é alto demais.

**Recomendação.** Adicionar um escopo na raiz do PortalLayout (`<div class="min-h-dvh bg-canvas theme-portal">`) e definir nele o override dos papéis — não dos primitivos. Concretamente: `.theme-portal { --color-canvas: <fundo quente>; --radius-surface: <maior>; --color-action-primary: var(--color-accent-celebration); }`. É a mesma marca, os mesmos primitivos, os mesmos componentes — muda o que cada papel recebe. Assim UiCard, UiButton e PageHeader passam a ser generosos no portal e contidos na operação sem uma linha de condicional no componente, e o `tone="portal"` do PageHeader pode inclusive ser aposentado.

### 16. `--color-danger` (papel de texto) está servindo de borda de campo inválido em 5 componentes

**Categoria:** papel-mal-definido · **Dimensão:** Arquitetura de tokens

**Arquivos:** `D:\Projetos\welog-staging\resources\css\app.css:60`, `D:\Projetos\welog-staging\resources\js\Components\UiInput.vue:68`, `D:\Projetos\welog-staging\resources\js\Components\UiTextarea.vue:56`, `D:\Projetos\welog-staging\resources\js\Components\UiSelect.vue:52`, `D:\Projetos\welog-staging\resources\js\Components\UiCheckboxGroup.vue:83`, `D:\Projetos\welog-staging\resources\js\Components\UiFileDrop.vue:137`

A família `danger` tem quatro tokens, cada um documentado: `danger` (#b42318) = "texto/ícone sobre fundo soft" (app.css:60), `danger-soft`, `danger-line` e `danger-solid` = "ÚNICO vermelho sólido do sistema". Nenhum é borda de campo. Mesmo assim, os cinco componentes de formulário usam `border-danger` — o token de TEXTO — como borda do estado inválido. `--color-danger-line` (#fecdca) existe, mas é claro demais e não serve; ninguém criou o degrau intermediário.

**Consequência.** O peso visual do erro de campo é hoje um acidente: herdou a saturação de um token calibrado para texto pequeno. Na troca de paleta, quem reequilibrar a família `danger` (por exemplo, escurecendo o texto para compensar um canvas mais quente) vai engrossar a borda de erro de todos os formulários — inclusive os do portal, no celular, que é onde o erro dói mais. E ninguém vai ligar uma coisa à outra.

**Recomendação.** Nomear `--color-field-border-invalid` como papel próprio e apontar os cinco componentes para ele. Vale generalizar: os estados de campo (`--color-field-border`, `--color-field-border-hover`, `--color-field-border-invalid`, `--color-field-bg`, `--color-field-bg-disabled`) hoje pegam emprestado de `line-strong`, `ink-subtle`, `danger`, `surface` e `surface-muted` — cinco papéis emprestados de cinco famílias diferentes, todos consumidos direto pelo componente.

### 17. Todos os 8 neutros são frios por construção, e o desvio cresce com a escuridão

**Categoria:** valor-fora-do-token · **Dimensão:** Arquitetura de tokens

**Arquivos:** `D:\Projetos\welog-staging\resources\css\app.css:28`, `D:\Projetos\welog-staging\resources\css\app.css:29`, `D:\Projetos\welog-staging\resources\css\app.css:30`, `D:\Projetos\welog-staging\resources\css\app.css:31`, `D:\Projetos\welog-staging\resources\css\app.css:32`, `D:\Projetos\welog-staging\resources\css\app.css:33`, `D:\Projetos\welog-staging\resources\css\app.css:34`, `D:\Projetos\welog-staging\resources\css\app.css:35`

Medindo o desvio azul (B menos R) de cada neutro: canvas #f6f7f9 = +3, surface-muted #f8fafc = +4 (é o slate-50 do Tailwind), line #e4e7ec = +8, line-strong #cfd4dc = +13, ink #101828 = +24, ink-subtle #98a2b3 = +27, ink-muted #667085 = +31. O viés é sistemático e proporcional à escuridão — é uma rampa de cinza-azulado, coerente com um índigo, não com um acento quente. E são 8 literais independentes: não há eixo de temperatura, matiz ou croma parametrizado.

**Consequência.** É o que sustenta o "só tem branco e azul". A percepção de frieza não vem do índigo (que aparece em 3 lugares no portal): vem dos neutros, que são 90% dos pixels. Trocar só a marca não resolve nada. E como cada neutro é um hex isolado, aquecê-los significa reescrever 8 valores à mão sem nenhuma garantia de que a rampa permaneça monotônica em luminância — ou seja, com risco real de inverter contraste entre `line` e `line-strong`, ou entre `ink-muted` e `ink-subtle`, e ninguém perceber.

**Recomendação.** Derivar os neutros de um eixo declarado em vez de 8 literais: primitivos em OKLCH com matiz e croma compartilhados (`--neutral-hue`, `--neutral-chroma`) e só a luminância variando por degrau. Aquecer o sistema inteiro passa a ser uma linha. Travar os pisos de contraste como regra de aceite junto com os tokens: `ink` ≥ 12:1, `ink-muted` ≥ 4,5:1 (hoje 4,9:1 — é PISO, o comentário em app.css:34 já avisa que não há folga) e `ink-subtle` explicitamente marcado como não-texto.

### 18. `ink-subtle` está a 2,58:1 e já pinta um ícone funcional, não decorativo

**Categoria:** papel-mal-definido · **Dimensão:** Arquitetura de tokens

**Arquivos:** `D:\Projetos\welog-staging\resources\css\app.css:35`, `D:\Projetos\welog-staging\resources\js\Components\UiSearchInput.vue:56`, `D:\Projetos\welog-staging\resources\js\Components\UiInput.vue:68`, `D:\Projetos\welog-staging\resources\js\Components\UiSelect.vue:52`

`--color-ink-subtle` (#98a2b3) está documentado como "SÓ ícone decorativo, separador, travessão" e dá 2,58:1 sobre branco. Mas UiSearchInput.vue:56 usa `text-ink-subtle` na lupa — que é a affordance que identifica o campo como busca, não decoração (SC 1.4.11 exige 3:1 para componentes de interface). O mesmo token também é o hover de borda de campo em UiInput.vue:68, UiSelect.vue:52 e UiTextarea.vue:56 — papel de BORDA servido por um token de tinta de ícone.

**Consequência.** Reprova AA hoje, antes de qualquer troca de paleta. E na troca fica pior: `ink-subtle` é o token mais provável de ser "só clareado junto" para acompanhar neutros quentes, empurrando o contraste ainda mais para baixo — em três papéis diferentes de uma vez, porque ele acumula ícone, hover de borda e separador.

**Recomendação.** Separar o papel de borda (`--color-field-border-hover`) do papel de tinta decorativa, e reclassificar o ícone da busca para `--color-ink-muted` (4,9:1). Registrar `ink-subtle` na spec com trava explícita: proibido em qualquer elemento que carregue significado, verificável em revisão. Sem isso, a regra existe só como comentário e já foi violada uma vez.

### 19. `brand-500` é token morto: zero usos, e duplica `--color-focus`

**Categoria:** oportunidade · **Dimensão:** Arquitetura de tokens

**Arquivos:** `D:\Projetos\welog-staging\resources\css\app.css:44`, `D:\Projetos\welog-staging\resources\css\app.css:69`, `D:\Projetos\welog-staging\resources\css\app.css:104`

`--color-brand-500: #6366f1` está anotado como "/* foco */", mas a varredura completa por `bg-brand-500`, `text-brand-500`, `border-brand-500` e `ring-brand-500` em `resources/js` e `resources/views` retorna zero. Quem pinta o foco é `--color-focus` (app.css:69), consumido uma única vez, no `:focus-visible` global (app.css:104) — com o mesmo hex, escrito de novo. Sobra: a rampa "de 6 degraus" na prática tem 5 em uso (50, 100, 200, 600, 700).

**Consequência.** Baixo impacto isolado, mas é o sintoma mais limpo do problema estrutural: o arquivo documenta intenções em comentário ("/* foco */") em vez de codificá-las em referência. Quem for redesenhar a rampa vai gastar tempo decidindo um degrau que ninguém usa, e vai manter a duplicação achando que os dois valores iguais são coincidência.

**Recomendação.** Ao redesenhar a rampa, resolver os dois de uma vez: `--color-focus: var(--color-brand-500)` — aí o comentário vira código e o degrau 500 passa a ter um consumidor real. Se a decisão for que o foco NÃO segue a marca (defensável, e provavelmente correto com acento quente pelo risco de contraste), então dar tinta própria ao foco e remover o comentário enganoso do 500.

### 20. `info` não é uma família semântica: é a marca com outro nome — e o índigo acumula 9 papéis no Dashboard

**Categoria:** papel-mal-definido · **Dimensão:** Cor que carrega significado na operação

**Arquivos:** `resources/css/app.css:41`, `resources/css/app.css:43`, `resources/css/app.css:46`, `resources/css/app.css:65`, `resources/css/app.css:66`, `resources/css/app.css:67`, `resources/js/Pages/Dashboard.vue:81`, `resources/js/Pages/Dashboard.vue:139`, `resources/js/Pages/Dashboard.vue:150`, `resources/js/Pages/Dashboard.vue:178`, `resources/js/Pages/Dashboard.vue:219`, `resources/js/Layouts/AppLayout.vue:146`, `resources/js/Layouts/AppLayout.vue:174`, `resources/js/Layouts/AppLayout.vue:181`

`--color-info: #4338ca` (app.css:65) é o mesmo hexadecimal de `--color-brand-700` (app.css:46). `--color-info-soft: #eef2ff` = `--color-brand-50` (app.css:41). `--color-info-line: #c7d2fe` = `--color-brand-200` (app.css:43). O comentário em app.css:51 assume isso de propósito ("o sistema não tem dois azuis"), mas o efeito colateral é que status e affordance viram a mesma tinta. Só na tela inicial o índigo faz: marca no topo da sidebar (AppLayout.vue:146), fundo+trilho do item ativo (AppLayout.vue:174,181), link de ação da fila (Dashboard.vue:139), ponto da fila de rotina (Dashboard.vue:81), 7 barras do gráfico (Dashboard.vue:150), pílula "Pronto para liberar" (Dashboard.vue:178) e pílula "Processando" (Dashboard.vue:219). Nove trabalhos, uma tinta. Numa linha de Resgates o operador vê a pílula índigo "Em transporte" e o botão índigo "Expedir" lado a lado: mesma família, um é informação, o outro é ação.

**Consequência.** Hoje: o operador não consegue usar a cor para pré-atender a tela — nada em índigo é confiavelmente clicável nem confiavelmente informativo. Na troca: se a marca WeGift for quente e saturada, TODA a camada informacional da operação (processando, em transporte, pedido criado, pronto para liberar, kit, cadastrado automaticamente) fica quente e saturada junto, e a área densa vira um mar de acento. É a mudança de uma linha de CSS com o maior raio de explosão do repositório.

**Recomendação.** Separar `info` da rampa de marca ANTES da troca. Definir dois papéis distintos: (a) `--color-brand-*` = identidade, ação primária, seleção, foco, link — nada mais; (b) um papel novo, nome sugerido `--color-progress` ("em andamento, nada a fazer"), com matiz próprio, deliberadamente DESSATURADO e frio, longe do acento de marca. Trocar as 6 ocorrências de `variant="info"` que significam progresso (StatusBadge.vue:30-34, :39, :47-49, :54) para o papel novo. Manter os pares de contraste atuais como piso verificável: hoje #4338ca sobre #eef2ff dá 7,07:1 — o substituto precisa entregar ≥4,5:1 sobre o próprio soft.

### 21. O mesmo índigo diz "aja agora" numa coluna e "espere" na coluna ao lado — na mesma tela

**Categoria:** papel-mal-definido · **Dimensão:** Cor que carrega significado na operação

**Arquivos:** `resources/js/Components/StatusBadge.vue:39`, `resources/js/Components/StatusBadge.vue:47`, `resources/js/Components/StatusBadge.vue:48`, `resources/js/Components/StatusBadge.vue:49`, `resources/js/Components/StatusBadge.vue:54`, `resources/js/Components/StatusBadge.vue:61`, `resources/js/Components/StatusBadge.vue:62`, `resources/js/Pages/Projects/Show.vue:440`, `resources/js/Pages/Projects/Show.vue:541`

Em `redemption` o índigo é `shipped` (StatusBadge.vue:39) = já saiu, não há o que fazer. Em `importRow` é `order_created`/`invoiced`/`dispatched` (:47-49) = em trânsito, esperar. Em `import` é `processing` (:54) = esperar. Mas em `projectRelease` o índigo é `ready` — "Pronto para liberar" (:61) — que é exatamente o oposto: é a fila principal do operador, o único item da tela que exige clique. E o estado que de fato significa "espere" nesse mesmo mapa, `producing` (:62), é cinza neutro. Em Projects/Show.vue as duas leituras convivem a 100px de distância: o badge do cabeçalho (:440) é `projectRelease` e o badge da tabela (:541) é `redemption`.

**Consequência.** A regra que o operador aprende numa tela é desmentida na seguinte. Índigo deixa de ser um sinal e vira ruído que precisa ser lido palavra por palavra — o que anula a razão de existir do badge numa tabela de 25 linhas. Na troca de paleta esse defeito não some sozinho: ele é copiado para a cor nova.

**Recomendação.** Decidir o eixo do papel `progress`: ele significa "o sistema está trabalhando, não faça nada". Mover `ready` (StatusBadge.vue:61) para o papel de atenção (o mesmo de `confirmed` e `pending`), e mover `producing` (:62) de `neutral` para `progress`. Com isso os três mapas passam a concordar: atenção = fila do operador, progress = em trânsito, success = fim de linha, danger = travado.

### 22. "Precisa da sua ação" está pintado em quatro tintas diferentes

**Categoria:** papel-mal-definido · **Dimensão:** Cor que carrega significado na operação

**Arquivos:** `resources/js/Components/StatusBadge.vue:38`, `resources/js/Components/StatusBadge.vue:44`, `resources/js/Components/StatusBadge.vue:45`, `resources/js/Components/StatusBadge.vue:46`, `resources/js/Components/StatusBadge.vue:61`, `resources/js/Pages/Admin/Users/Index.vue:60`, `resources/js/Pages/Admin/Companies/Index.vue:254`, `resources/js/Pages/Dashboard.vue:53`, `resources/js/Pages/Dashboard.vue:63`, `resources/js/Pages/Dashboard.vue:73`

O produto tem um conceito operacional único — "há uma pessoa esperando que você faça algo" — e ele aparece em quatro cores: âmbar em `confirmed` (StatusBadge.vue:38, expedir) e `pending` (:44), vermelho em `blocked_structural`/`blocked_product` (:45-46), índigo em `ready` (:61, liberar), âmbar em "Convite pendente" (Admin/Users/Index.vue:60) e âmbar em cadastro incompleto (Admin/Companies/Index.vue:254). O próprio Dashboard confessa a inconsistência: as três filas de "Precisa de você" recebem tons declarados à mão — `warning` para liberar (Dashboard.vue:53), `default`/índigo para expedir (:63), `danger` para bloqueadas (:73) — como se fossem três urgências diferentes, quando são três instâncias da mesma coisa.

**Consequência.** Não existe uma varredura visual possível de "o que é meu hoje". O operador tem que ler as três filas do Dashboard inteiras porque nenhuma cor as agrupa. É o défice de hierarquia que o cliente chamou de "cores mal estruturadas", e ele é estrutural, não de matiz.

**Recomendação.** Reservar UM papel para "pede ação humana" (o atual `warning` é o candidato natural — já cobre 3 dos 5 casos) e usar `danger` só para "travado, não avança sozinho". Aplicar em StatusBadge.vue:61 (`ready` → atenção) e no mapa `tones` do Dashboard (Dashboard.vue:78-83), unificando os três pontos de fila num tom só, com a contagem carregando a urgência em vez da cor.

### 23. O estágio do pipeline — o eixo mais importante da operação — não tem cor em lugar nenhum, e o mapa que existe é código morto

**Categoria:** papel-mal-definido · **Dimensão:** Cor que carrega significado na operação

**Arquivos:** `resources/js/Components/StatusBadge.vue:28`, `resources/js/Components/StatusBadge.vue:29`, `resources/js/Components/StatusBadge.vue:30`, `resources/js/Components/StatusBadge.vue:31`, `resources/js/Components/StatusBadge.vue:32`, `resources/js/Components/StatusBadge.vue:33`, `resources/js/Components/StatusBadge.vue:34`, `resources/js/Components/StatusBadge.vue:35`, `resources/js/Pages/Projects/Index.vue:89`, `resources/js/Pages/Dashboard.vue:177`, `resources/js/Pages/Projects/Show.vue:110`, `resources/js/Components/UiDescriptionList.vue:58`

Nenhuma página do repositório renderiza `<StatusBadge kind="pipeline">`. Verifiquei todos os usos: `redemption` (Redemptions/Index.vue:53, Projects/Show.vue:541), `import` (Imports/Index.vue:116, Imports/Show.vue:131, Dashboard.vue:219), `importRow` (Imports/Show.vue:212) e `projectRelease` (Dashboard.vue:178, Projects/Show.vue:440, Projects/Index.vue:91). O estágio sai como texto cinza em Projects/Index.vue:89 (`text-ink-muted`) e Dashboard.vue:177, e como valor sem cor via UiDescriptionList.vue:58 em Projects/Show.vue:110. O mapa de 7 estágios em StatusBadge.vue:28-35 nunca chega à tela. E, se chegasse, 5 dos 7 estágios (design, production, picking, packing, invoiced — :30-34) teriam a MESMA tinta índigo: a cor diria apenas "não começou / andando / acabou", com 7 rótulos por cima.

**Consequência.** O operador não consegue varrer "quais projetos estão em produção" numa lista de 200 linhas — precisa ler cada célula. Ao mesmo tempo, taxonomia sem urgência ganha cor forte na tela ao lado ("Kit · 3 itens" em Products/Index.vue:168). A prioridade está invertida: o que decide o dia é cinza, o que é catálogo é colorido.

**Recomendação.** Duas saídas, escolher uma e documentar. (a) Assumir que estágio é ordinal e não deve virar 7 matizes: manter texto, mas dar PESO (o estágio atual em `font-medium text-ink`, os demais em `ink-muted`) e ordenar visualmente. (b) Se for virar badge, colapsar para 3 estados declarados — não-iniciado / em produção / concluído — e apagar as 5 chaves redundantes de StatusBadge.vue:30-34, que hoje prometem diferenciação que a cor não entrega. Em qualquer caso, remover o mapa morto para que ele não seja repintado por engano na troca de marca.

### 24. Dois bloqueios com resoluções OPOSTAS usam exatamente o mesmo vermelho

**Categoria:** papel-mal-definido · **Dimensão:** Cor que carrega significado na operação

**Arquivos:** `resources/js/Components/StatusBadge.vue:45`, `resources/js/Components/StatusBadge.vue:46`, `resources/js/Pages/Imports/Show.vue:109`, `resources/js/Pages/Imports/Show.vue:110`, `resources/js/Pages/Imports/Show.vue:111`, `resources/js/Pages/Imports/Show.vue:214`, `resources/js/Pages/Imports/Show.vue:223`, `resources/js/lib/format.js:24`, `resources/js/lib/format.js:25`

`blocked_structural` e `blocked_product` são ambos `danger` (StatusBadge.vue:45-46). Mas a ação é oposta e o próprio arquivo sabe disso: em Imports/Show.vue:110 o estrutural diz "Corrija a planilha e importe novamente" (não há botão, a linha está perdida) e em :109 o de produto diz "Requer perfil N1" — ou ganha um botão "Reprocessar" em :223 quando `can_reprocess`. Um é irrecuperável nesta importação; o outro é recuperável em três cliques. A cor os iguala; só o texto do rótulo (format.js:24-25) e a presença/ausência do botão os separam.

**Consequência.** Na tela cuja única razão de existir é destravar linha (comentário em Imports/Show.vue:58-60), o operador não consegue separar por cor as linhas que ele PODE resolver das que ele NÃO PODE. Numa importação com 400 linhas e o filtro "Só bloqueadas" ligado, isso é a diferença entre triagem visual e leitura linha a linha.

**Recomendação.** Diferenciar por papel, não por saturação: manter `danger` só para o que é irrecuperável (`blocked_structural`) e mover `blocked_product` para o papel de atenção — é uma fila de trabalho, não uma falha. Isso alinha com o texto do próprio alerta em Imports/Show.vue:149-154, que trata o bloqueio de produto como procedimento em 3 passos, não como erro.

### 25. O mesmo resgate é âmbar para o operador e índigo para o cliente final

**Categoria:** papel-mal-definido · **Dimensão:** Cor que carrega significado na operação

**Arquivos:** `resources/js/Components/StatusBadge.vue:38`, `resources/js/Components/StatusBadge.vue:39`, `resources/js/Components/StatusBadge.vue:40`, `resources/js/Pages/Portal/Redemptions.vue:56`, `resources/js/Pages/Portal/Redemptions.vue:65`, `resources/js/Pages/Portal/Redemptions.vue:74`, `resources/js/Pages/Portal/Redemptions.vue:122`

O status `confirmed` do mesmo registro é `warning` (âmbar) no badge do operador (StatusBadge.vue:38) e `bg-info` (índigo) no ponto do portal (Portal/Redemptions.vue:56). `shipped` é índigo nos dois, mas com linguagens visuais diferentes: no operador é pílula com `bg-info-soft` + `text-info` + anel; no portal é um ponto de 10px com o token SÓLIDO `bg-info` (#4338ca) ao lado de texto `text-ink` (Portal/Redemptions.vue:122). Só `delivered` e `canceled` coincidem em papel.

**Consequência.** Não existe uma "cor do status de resgate" no nível do sistema. Um chamado de suporte em que o cliente descreve o que vê não é traduzível para o que o operador vê. E, mais concreto para a troca: são duas listas de manutenção — quem repintar StatusBadge.vue não repinta Portal/Redemptions.vue, porque este último não usa StatusBadge nem UiBadge, escreve as classes à mão em 4 `switch cases`.

**Recomendação.** A divergência de TOM entre as superfícies é legítima (o operador precisa de urgência, o cliente precisa de tranquilidade) — mas ela tem que ser derivada, não escrita à mão. Criar um mapa único de status → papel em lib/format.js (ao lado de `redemptionStatusLabels`, format.js:14-19) e duas apresentações: badge tenso na operação, ponto/rótulo calmo no portal. Assim a troca de paleta acerta as duas superfícies com uma edição.

### 26. O preenchimento de TODA pílula de status desaparece na linha sob o cursor — medido entre 1,00:1 e 1,07:1

**Categoria:** valor-fora-do-token · **Dimensão:** Cor que carrega significado na operação

**Arquivos:** `resources/js/Components/UiTable.vue:209`, `resources/js/Components/UiTable.vue:210`, `resources/js/Components/UiTable.vue:211`, `resources/js/Components/UiBadge.vue:11`, `resources/js/Components/UiBadge.vue:12`, `resources/js/Components/UiBadge.vue:13`, `resources/js/Components/UiBadge.vue:14`, `resources/js/Components/UiBadge.vue:15`, `resources/css/app.css:30`, `resources/css/app.css:53`, `resources/css/app.css:57`, `resources/css/app.css:61`, `resources/css/app.css:66`

UiTable.vue:209-211 pinta a linha sob o cursor com `--color-surface-muted` (#f8fafc). Os fundos das pílulas são: `success-soft` #ecfdf3 (app.css:53) → 1,01:1 contra a linha em hover; `warning-soft` #fffaeb (app.css:57) → 1,00:1 (a pílula âmbar é literalmente invisível como preenchimento); `danger-soft` #fef3f2 (app.css:61) → 1,04:1; `info-soft` #eef2ff (app.css:66) → 1,07:1; e `neutral` usa `bg-surface-muted` (UiBadge.vue:11), ou seja, 1,00:1 exato — some por completo. Os anéis não salvam: `success-line` dá 1,32:1 contra branco e `info-line` 1,49:1, contra 1,24:1 do `--color-line` neutro; sobre a linha em hover ficam abaixo disso.

**Consequência.** Na linha que o operador está apontando — exatamente onde a atenção está — o status é comunicado por COR DO TEXTO SOZINHA. Hoje isso ainda funciona porque os quatro textos semânticos têm 5,2:1 a 7,1:1 sobre seus fundos. Mas significa que a troca de paleta não tem folga: qualquer nova tinta com ink mais claro derruba o único portador restante, e não há forma (ícone, borda lateral, peso) para segurar o significado.

**Recomendação.** Duas correções baratas, aplicar as duas. (1) Trocar o hover de linha de fundo por outra pista (borda esquerda de 2px em `line-strong`, ou `surface-muted` só nas células de texto), preservando o preenchimento do badge. (2) Elevar `ring-*-line` para ≥3:1 contra `--color-surface` (hoje 1,3–1,5:1), transformando o anel num portador real de forma. Com isso o badge passa a ter dois portadores independentes e a paleta ganha liberdade de mexer no ink.

### 27. "Acesso pendente" tem três encodings; a situação do cliente final tem quatro estados e zero cor

**Categoria:** papel-mal-definido · **Dimensão:** Cor que carrega significado na operação

**Arquivos:** `resources/js/Pages/Admin/Users/Index.vue:60`, `resources/js/Pages/Admin/Companies/Index.vue:285`, `resources/js/Pages/Projects/Show.vue:233`, `resources/js/Pages/Projects/Show.vue:234`, `resources/js/Pages/Projects/Show.vue:235`, `resources/js/Pages/Projects/Show.vue:236`, `resources/js/Pages/Projects/Show.vue:485`, `resources/js/Pages/Projects/Show.vue:486`, `resources/js/Pages/Projects/Show.vue:487`, `resources/js/Pages/Products/Index.vue:168`

O mesmo fato — a pessoa recebeu o convite mas não criou a senha — aparece como pílula âmbar em Admin/Users/Index.vue:60, pílula âmbar em Admin/Companies/Index.vue:285 e prosa cinza sem cor em Projects/Show.vue:236 (`situation()` devolve a string 'Acesso pendente', renderizada em :485 como `text-ink` puro). Pior: a coluna "Situação" da aba Clientes finais tem 4 estados com precedência declarada (Projects/Show.vue:233-239: Resgatou > Acesso pendente > Notificada em > Ainda não notificada) e é a ÚNICA coluna de situação do sistema inteiro renderizada sem badge — enquanto "Kit · 3 itens", que é taxonomia estática, ganha pílula índigo em Products/Index.vue:168.

**Consequência.** É essa coluna que decide a ação da tela (o menu "Reenviar convite" em Projects/Show.vue:264-267 só habilita quando `access_pending`). Sem badge, o operador varre 25 linhas de texto para achar as 3 que pedem reenvio. E, na troca de marca, essa coluna simplesmente não participa: não há token nenhum a repintar.

**Recomendação.** Passar `situation()` (Projects/Show.vue:233-239) por um mapa de papéis igual aos outros — Resgatou = success, Acesso pendente = atenção, Notificada = progress, Ainda não notificada = neutral — e renderizar com StatusBadge/UiBadge. Em contrapartida, rebaixar "Kit · N itens" (Products/Index.vue:168) de pílula colorida para texto com o contador, liberando a cor para quem carrega estado.

### 28. "Este lote tem linhas bloqueadas" aparece em três encodings diferentes, dois deles na MESMA linha

**Categoria:** papel-mal-definido · **Dimensão:** Cor que carrega significado na operação

**Arquivos:** `resources/js/Pages/Dashboard.vue:218`, `resources/js/Pages/Dashboard.vue:219`, `resources/js/Components/StatusBadge.vue:56`, `resources/js/Pages/Imports/Index.vue:115`, `resources/js/Pages/Imports/Index.vue:116`

Em Dashboard.vue:218 a contagem de bloqueadas é pintada de `text-danger` + `font-medium` quando > 0. Na célula imediatamente à direita (:219) o mesmo fato vira badge âmbar, porque `completed_with_blocks` é `warning` (StatusBadge.vue:56). Vermelho e âmbar para a mesma condição, a 80px de distância. E na tela dedicada a esses lotes, Imports/Index.vue:115, a mesma contagem não recebe cor nenhuma — só o badge em :116.

**Consequência.** Dentro de uma única linha o operador recebe dois graus de urgência contraditórios para o mesmo dado, e ao clicar para a lista completa o sinal vermelho some. A cor deixa de ser um contrato e vira decoração ad hoc de cada página — que é exatamente o tipo de dívida que multiplica o custo da troca de paleta.

**Recomendação.** Escolher o portador: ou o número é tinto (e aí Imports/Index.vue:115 precisa do mesmo tratamento e o badge da linha vira neutro), ou o badge é o portador (e Dashboard.vue:218 vira `font-medium text-ink`, mantendo o peso sem a cor). Recomendo a segunda: número tinto sem rótulo próprio é cor como único portador (ver achado específico) e o badge já diz a mesma coisa com palavra.

### 29. Estoque zero — a condição que trava a liberação — é o texto mais apagado da tela

**Categoria:** papel-mal-definido · **Dimensão:** Cor que carrega significado na operação

**Arquivos:** `resources/js/Pages/Products/Index.vue:173`, `resources/js/Pages/Projects/Show.vue:145`, `resources/js/Pages/Projects/Show.vue:146`, `resources/js/Pages/Projects/Show.vue:147`, `resources/js/Pages/Projects/Show.vue:117`

Products/Index.vue:173 aplica `p.stock_qty > 0 ? 'text-ink' : 'text-ink-muted'`: quantidade zero fica MAIS CLARA que quantidade positiva. Mas é precisamente estoque ausente que produz o bloqueio de liberação em Projects/Show.vue:145-147 ("A Omie ainda não sinalizou estoque disponível para este Projeto") e o "Ainda não disponível" de :117. A tela pinta o normal e apaga a exceção.

**Consequência.** O único número do catálogo capaz de prever um travamento é o mais difícil de enxergar. E o encoding é puramente cromático — não há rótulo, ícone nem peso separando 0 de 40 — então é também um ponto onde a paleta não pode perder contraste sem apagar a informação de vez.

**Recomendação.** Inverter: zero em `text-ink` com peso, acompanhado de um marcador textual curto ("sem estoque"), e valores positivos em `ink` normal. Reservar `ink-muted` para o que app.css:35 já define como seu papel — rótulo e texto secundário —, não para dado que decide ação.

### 30. "Ativo" verde em toda linha: o caso normal é o mais barulhento; o perfil de acesso, que é o que importa, não tem cor

**Categoria:** ritmo-visual · **Dimensão:** Cor que carrega significado na operação

**Arquivos:** `resources/js/Pages/Admin/Users/Index.vue:58`, `resources/js/Pages/Admin/Users/Index.vue:59`, `resources/js/Pages/Admin/Users/Index.vue:60`, `resources/js/Pages/Admin/Users/Index.vue:61`, `resources/js/Pages/Admin/Users/Index.vue:62`, `resources/js/Pages/Admin/Users/Index.vue:255`, `resources/js/Pages/Admin/Users/Index.vue:261`, `resources/js/Pages/Admin/Users/Index.vue:262`, `resources/js/Pages/Admin/Users/Index.vue:263`, `resources/js/Pages/Admin/Users/Index.vue:267`

SITUATIONS (Admin/Users/Index.vue:58-62) dá `success` verde a `active`. Como a esmagadora maioria dos usuários está ativa, a coluna Situação vira uma faixa de pílulas verdes idênticas, e as duas exceções que pedem ação ("Convite pendente" âmbar, "Inativo" cinza) competem com esse fundo. Ao mesmo tempo, o perfil de acesso — Admin WeGift / Operador N1 / Operador N2, o atributo mais consequente da tabela, com descrições ricas em :28-44 — sai como texto preto + resumo cinza (:261-264), sem nenhuma diferenciação. Há ainda um segundo encoding independente para inativo: a linha inteira esmaecida em :255.

**Consequência.** Verde gasto no estado esperado é verde que não significa mais nada quando aparece em "Entregue" ou "Liberado". E um admin não consegue varrer visualmente quem tem acesso total — a pergunta que a tela existe para responder.

**Recomendação.** Rebaixar `active` para neutro/sem badge (o normal não precisa de selo) e deixar a coluna com pílula apenas nas exceções. Se o perfil precisar de diferenciação, usar um papel próprio de baixa saturação — a hierarquia é ordinal (total > opera > consulta) e pede peso/escala, não matiz.

### 31. Cinco lugares onde a cor é o ÚNICO portador — são eles que limitam quanto a paleta pode mudar

**Categoria:** papel-mal-definido · **Dimensão:** Cor que carrega significado na operação

**Arquivos:** `resources/js/Components/ShipControl.vue:190`, `resources/js/Components/ShipControl.vue:196`, `resources/js/Components/ShipControl.vue:197`, `resources/js/Components/ShipControl.vue:198`, `resources/js/Pages/Admin/Users/Index.vue:236`, `resources/js/Pages/Admin/Users/Index.vue:237`, `resources/js/Pages/Admin/Users/Index.vue:238`, `resources/js/Pages/Products/Index.vue:173`, `resources/js/Pages/Dashboard.vue:120`, `resources/js/Pages/Dashboard.vue:121`, `resources/js/Pages/Dashboard.vue:218`

Levantei o inventário completo. Onde a cor tem texto ou ícone ao lado, ela é reforço e pode mudar livremente. Onde não tem: (1) seleção de transportadora — o rádio é `sr-only` (ShipControl.vue:190) e o estado escolhido é só `border-brand-600 bg-brand-50 text-brand-700` (:196-198), sem check, sem peso; (2) chip de filtro pressionado em Admin/Users/Index.vue:236-238 — `aria-pressed` existe (bom), mas visualmente é só cor; (3) estoque zero em Products/Index.vue:173; (4) ponto e número das filas em Dashboard.vue:120-121, com o ponto `aria-hidden` — mitigado pelos títulos distintos, mas a diferenciação entre as três filas é cromática; (5) contagem de bloqueadas em Dashboard.vue:218. Todos os demais usos (badges, alertas, toasts, prazos em Projects/Index.vue:31-35, erro de campo em UiField.vue:73) já têm palavra junto — inclusive com o comentário explícito em Projects/Index.vue:30 e format.js:98 dizendo que a cor só reforça.

**Consequência.** Estes cinco pontos são a trava concreta da troca de paleta: qualquer marca com menos separação de luminância que o índigo atual apaga a informação. Também são falhas de WCAG 1.4.1 hoje, com brand indigo — só que o contraste alto do índigo esconde o problema.

**Recomendação.** Corrigir os cinco ANTES da troca, para que a paleta nova não precise carregar o peso sozinha. Seleção de transportadora e chip de filtro: adicionar ícone de check e `font-semibold` no estado escolhido. Estoque zero e contagem de bloqueadas: adicionar rótulo curto. Filas do Dashboard: dar ícone distinto por fila em vez de três pontos idênticos de cores diferentes.

### 32. Quatro formas visuais para o mesmo vermelho, quatro para o mesmo âmbar — e um ponto decorativo em toda pílula

**Categoria:** ritmo-visual · **Dimensão:** Cor que carrega significado na operação

**Arquivos:** `resources/js/Pages/Imports/Show.vue:214`, `resources/js/Pages/Imports/Show.vue:215`, `resources/js/Pages/Imports/Show.vue:216`, `resources/js/Pages/Admin/Companies/Index.vue:254`, `resources/js/Pages/Admin/Companies/Index.vue:285`, `resources/js/Pages/Admin/Companies/Index.vue:291`, `resources/js/Pages/Admin/Companies/Index.vue:292`, `resources/js/Pages/Projects/Index.vue:34`, `resources/js/Pages/Projects/Index.vue:35`, `resources/js/Components/UiBadge.vue:24`, `resources/js/Components/UiBadge.vue:33`, `resources/js/Pages/Imports/Index.vue:94`, `resources/js/Pages/Imports/Index.vue:96`, `resources/js/Pages/Imports/Index.vue:116`

Vermelho aparece como: badge (StatusBadge), alerta com borda (UiAlert), placa solta `bg-danger-soft px-2 py-1 text-danger` escrita à mão em Imports/Show.vue:214-216, e texto nu em Projects/Index.vue:35. Âmbar aparece como: alerta com borda (Admin/Companies/Index.vue:254), pílula (:285), texto+ícone sem caixa (:291-292) e texto nu (Projects/Index.vue:34). São quatro pesos visuais para dois papéis — o leitor precisa interpretar a FORMA, não a cor. Somando: cada UiBadge carrega 4 marcas tintas simultâneas (fundo, anel em :24, texto e um ponto `bg-current` em :33 que não acrescenta nenhuma informação — repete o que o fundo já disse), multiplicado por 25 linhas. E Imports/Index.vue:96 põe um ponto índigo PULSANTE ao lado de um badge que já diz "Processando" na mesma linha (:116) — a única animação colorida da área operacional, gasta em redundância.

**Consequência.** É exatamente o efeito que o cliente descreveu: a tela parece agitada sem estar informando mais. Numa área que o operador usa 8 horas, cada tinta extra reduz a capacidade de achar a que importa. E cada forma escrita à mão é um lugar que a troca de paleta vai esquecer.

**Recomendação.** Fixar 3 pesos e proibir o resto: pílula = estado de registro; alerta = mensagem sobre a tela toda; texto tinto = qualificador inline (prazo). Converter a placa de Imports/Show.vue:214-216 e a prosa âmbar de Admin/Companies/Index.vue:291-295 para os componentes existentes. Remover o `dot` do UiBadge (UiBadge.vue:33) — ele é a marca tinta que menos trabalha — e remover o ponto pulsante de Imports/Index.vue:94-98.

### 33. PipelineChart: a decisão de tom único continua certa, mas a barra está no token errado

**Categoria:** oportunidade · **Dimensão:** Cor que carrega significado na operação

**Arquivos:** `resources/js/Components/PipelineChart.vue:5`, `resources/js/Components/PipelineChart.vue:6`, `resources/js/Components/PipelineChart.vue:7`, `resources/js/Components/PipelineChart.vue:32`, `resources/js/Pages/Dashboard.vue:139`, `resources/js/Pages/Dashboard.vue:150`, `docs/PLANO.md:361`, `docs/PLANO.md:366`

A decisão registrada em docs/PLANO.md:366 ("série única não carrega informação na cor") está tecnicamente correta: matiz é um canal nominal e o estágio é um eixo ordinal — 7 cores ali seriam ruído, não diferenciação. O gráfico não virou decorativo por falta de cor. O problema é OUTRO: a barra usa `bg-brand-600` (PipelineChart.vue:32) e, empilhada em ~200px verticais no Dashboard, ela convive com o índigo clicável dos links de fila (Dashboard.vue:139) e com o índigo do ponto de fila (Dashboard.vue:81). Sete barras sólidas de marca são a maior massa de cor da tela — e a única que não é clicável nem é status.

**Consequência.** Hoje: o objeto mais chamativo da tela inicial do operador é inerte, e o índigo perde a última chance de significar "clique aqui". Na troca: se o acento de marca for quente e saturado, essas 7 barras passam a ser o centro de gravidade visual da tela de trabalho — o oposto do que uma superfície densa precisa. É a diferença entre um gráfico calmo e um gráfico gritando.

**Recomendação.** Manter o tom único (a decisão é sólida) e mover a barra para um papel de DADO, novo e neutro — `--color-data-bar`, algo como `ink` a 12-16% de opacidade sobre `surface-muted`, com a barra do estágio mais cheio recebendo o acento. Isso preserva a proporcionalidade, libera o acento de marca para o que é clicável e faz o gráfico atravessar a troca de paleta sem virar o protagonista da tela.

### 34. Risco direto da direção escolhida: um acento QUENTE cai em cima da família `warning`

**Categoria:** token-faltante · **Dimensão:** Cor que carrega significado na operação

**Arquivos:** `resources/css/app.css:56`, `resources/css/app.css:57`, `resources/css/app.css:58`, `resources/js/Components/StatusBadge.vue:38`, `resources/js/Components/StatusBadge.vue:44`, `resources/js/Components/StatusBadge.vue:56`, `resources/js/Pages/Admin/Companies/Index.vue:254`

Hoje marca (índigo) e `warning` (#b54708 / #fffaeb / #fedf89, app.css:56-58) estão em extremos opostos do círculo cromático, e por isso convivem sem ambiguidade. A direção "quente + confiável" coloca o acento de celebração exatamente na vizinhança do âmbar. `warning` é o papel mais usado da operação — expedir (StatusBadge.vue:38), linha pendente (:44), lote com bloqueios (:56), cadastro incompleto (Admin/Companies/Index.vue:254) — e o seu significado é APRENDIDO e convencional; não pode migrar de matiz sem custo de reaprendizado.

**Consequência.** Se o acento quente entrar sem regra, "presente celebrado" e "você precisa agir" passam a ser vizinhos cromáticos, e a área operacional perde o sinal mais usado. É a falha mais provável desta modernização, e ela só aparece depois que as cores da marca chegarem.

**Recomendação.** Escrever a regra agora, antes das cores: o acento quente de celebração é PROIBIDO no vocabulário de status da área operacional — vive no portal, na identidade e em superfícies de marca. A operação usa a base institucional profunda como acento de ação, e as três semânticas (blocked / attention / settled) permanecem como estão, com os pares de contraste atuais como piso verificável (medi: âmbar 5,20:1, verde 5,39:1, vermelho 6,1:1, índigo 7,07:1 sobre os respectivos soft). Se a marca ainda assim quiser calor na operação, empurrar `warning` para ocre/oliva mais profundo, deslocando o matiz sem trocar a leitura.

### 35. Tokens ausentes por PAPEL: não existe "dado", nem "seleção", nem "celebração" — tudo cai na marca

**Categoria:** token-faltante · **Dimensão:** Cor que carrega significado na operação

**Arquivos:** `resources/css/app.css:41`, `resources/css/app.css:44`, `resources/css/app.css:46`, `resources/css/app.css:69`, `resources/js/Components/UiTimeline.vue:25`, `resources/js/Components/ShipControl.vue:197`, `resources/js/Components/UiTabs.vue:55`, `resources/js/Components/UiFileDrop.vue:131`, `resources/js/Pages/Admin/EmailTemplates/Index.vue:377`, `resources/js/Pages/Admin/EmailTemplates/Index.vue:412`, `resources/js/Pages/Projects/Show.vue:695`, `resources/js/Pages/Projects/Show.vue:708`, `resources/js/Components/UiStat.vue:8`, `resources/js/Components/UiStat.vue:12`

A rampa de marca serve hoje a seis papéis que nunca foram nomeados: seleção (ShipControl.vue:197, UiTabs.vue:55, UiFileDrop.vue:131, Admin/EmailTemplates/Index.vue:377,412), foco (`--color-focus: #6366f1` em app.css:69 é o mesmo valor de `--color-brand-500` em app.css:44), recência (UiTimeline.vue:25, `bg-brand-600` = "evento mais novo"), ordinal de passo (medalhões numerados em Projects/Show.vue:695,723,761), dado (PipelineChart) e ação (Projects/Show.vue:708). Nenhum tem token próprio; todos leem `brand-*` direto. E UiStat.vue é um componente MORTO — declara uma escala de tons `default/warning/danger/success` (:8, :12-17) e não é importado por nenhuma página do repositório.

**Consequência.** A marca só pode variar o quanto o mais frágil desses seis papéis permitir. Se o acento novo for muito claro, o foco (que é acessibilidade, não estética) cai junto; se for muito saturado, seleção e recência viram alarme. É por isso que a rampa incompleta (faltam 300/400/800/900) dói: não há degrau intermediário para papéis de baixa energia como recência e seleção.

**Recomendação.** Nomear os papéis antes de escolher matizes: `--color-selected-*` (fundo + borda + ink), `--color-focus` explicitamente desacoplado da rampa (pode e deve ter contraste próprio), `--color-data-*` para gráfico, `--color-accent-*` para celebração/portal. Cada um aponta inicialmente para a rampa da marca — a troca depois é uma edição por papel, não uma caçada por `brand-600` em 34 arquivos. E apagar UiStat.vue, para não repintar um componente que ninguém renderiza.

### 36. Vermelho faz dois trabalhos dentro do mesmo formulário; e as 4 variantes do UiAlert viram 2 para leitor de tela

**Categoria:** papel-mal-definido · **Dimensão:** Cor que carrega significado na operação

**Arquivos:** `resources/js/Components/UiField.vue:63`, `resources/js/Components/UiField.vue:73`, `resources/js/Components/UiInput.vue:68`, `resources/js/Components/UiAlert.vue:15`, `resources/js/Components/UiAlert.vue:16`, `resources/js/Components/UiAlert.vue:17`, `resources/js/Components/UiAlert.vue:18`, `resources/js/Components/UiAlert.vue:22`, `resources/js/Components/UiAlert.vue:34`, `resources/js/Components/UiAlert.vue:38`, `resources/js/Components/UiIcon.vue:31`

Em UiField.vue:63 o asterisco de campo obrigatório é `text-danger` — vermelho ANTES de qualquer erro. Em :73 a mensagem de erro é `text-danger` também, e em UiInput.vue:68 a borda inválida é `border-danger`. Três vermelhos no mesmo formulário para dois conceitos ("preencha" vs "você errou"). Já no UiAlert, as quatro variantes (:15-18) diferem só por tinta da caixa e do ícone; o corpo é sempre `text-ink` (:38) e o ícone é `aria-hidden` (UiIcon.vue:31). O `role` (:22) colapsa info+success em `status` e warning+danger em `alert` — para leitor de tela um alerta de sucesso e um informativo são indistinguíveis, e um aviso e um erro também.

**Consequência.** O vermelho perde força justamente onde precisa dela: numa tela com 14 campos obrigatórios (CompanyForm.vue), o formulário já nasce salpicado de vermelho e a mensagem de erro real não se destaca. E a variante do alerta, o sinal com maior peso visual do sistema, é 100% cromática — não sobrevive nem a leitor de tela nem a uma paleta de menor separação.

**Recomendação.** Trocar o asterisco de `text-danger` para `ink-muted` (o `sr-only` "(obrigatório)" em UiField.vue:64 já carrega o significado) e reservar vermelho para erro consumado. No UiAlert, dar ao título uma palavra de papel quando faltar ("Erro", "Atenção") ou expor o nome da variante via `sr-only` — assim o alerta passa a ter portador não cromático e a troca de paleta deixa de ser um risco de acessibilidade.

### 37. Nenhuma das quatro cores do portal existe para dizer "isso é bom"

**Categoria:** token-faltante · **Dimensão:** Portal: onde falta vida

**Arquivos:** `D:\Projetos\welog-staging\resources\css\app.css:52-68`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Catalog.vue:104`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Catalog.vue:121`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redeem.vue:178`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redeem.vue:215`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redeem.vue:334`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redemptions.vue:56-83`

Contagem exata de tinta cromática na jornada inteira: índigo (brand-600/700, e `info` #4338ca é o mesmo valor de brand-700 — app.css:46 e :65), verde `success` #067647, âmbar `warning` #b54708, vermelho `danger` #b42318. São quatro famílias, e três delas são validação ou alarme. Na tela do presente ainda não resgatado (Catalog.vue) existe UM único elemento cromático em toda a página: o fundo do botão "Quero receber" (Catalog.vue:121 → UiButton.vue:23 `bg-brand-600`). Todo o resto é canvas/surface/line/ink/ink-muted/ink-subtle. No Redeem.vue inteiro não há um só pixel de cor positiva ligado ao presente.

**Consequência.** O sistema é estruturalmente incapaz de expressar alegria: quando a marca WeGift entrar, o acento quente não tem onde pousar porque não existe um papel semântico que signifique celebração. Ele vai acabar sendo aplicado como "segunda cor de botão", que é decoração, e o portal continuará parecendo um formulário administrativo com botão colorido.

**Recomendação.** Criar um papel semântico novo, independente de `success`, com significado "celebração / momento bom" — o acento quente da marca. Definir por escrito os três lugares onde ele é obrigatório: (1) a revelação do presente no Catalog, (2) o desfecho positivo da confirmação, (3) o marco "entregue" na jornada de entrega. E definir onde ele é PROIBIDO: qualquer tela da área operacional, onde cor é informação de estado. É esse contrato que faz a mesma base servir as duas superfícies.

### 38. `tone="portal"` do PageHeader não toca no título — o "Olá, Yago" tem exatamente o mesmo peso visual de "Importações"

**Categoria:** papel-mal-definido · **Dimensão:** Portal: onde falta vida

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Components\PageHeader.vue:51`, `D:\Projetos\welog-staging\resources\js\Components\PageHeader.vue:24-32`, `D:\Projetos\welog-staging\resources\js\Components\PageHeader.vue:40-43`, `D:\Projetos\welog-staging\resources\js\Components\PageHeader.vue:54`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Catalog.vue:55`

O `tone` só altera três coisas: o wrapper perde fundo/sticky/borda (linha 26), o link de voltar cresce de `min-h-9 text-sm` para `min-h-12 text-base` (linha 42) e o subtítulo vai de `text-sm` para `text-base` (linha 54). O `<h1>` na linha 51 é literalmente a mesma string de classes nas duas superfícies: `text-2xl font-semibold tracking-tight text-ink`. Ou seja: a saudação para alguém que acabou de descobrir que ganhou um presente é renderizada com o mesmo tamanho, peso e cor do cabeçalho de uma listagem de importações CSV.

**Consequência.** O momento de chegada — o único instante em que o produto pode dizer "algo bom aconteceu" — é tipograficamente indistinguível de uma tela de back-office. Não existe nenhum caminho no sistema para o portal ser maior, mais leve ou mais expressivo, porque `tone` foi implementado como ajuste de alvo de toque, não como registro de voz.

**Recomendação.** Expandir `tone="portal"` para trocar o registro tipográfico do `<h1>` (escala expressiva, peso e tracking próprios) e o papel de cor do subtítulo. O `tone` deixa de ser "ajuste de densidade" e passa a ser a chave que seleciona qual dos dois sistemas — operacional ou de acolhimento — está no ar. Esse é o mecanismo que permite uma base servir necessidades opostas.

### 39. A frase mais emocional do produto está pintada com a tinta de rótulo de formulário

**Categoria:** papel-mal-definido · **Dimensão:** Portal: onde falta vida

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Pages\Portal\Catalog.vue:33-39`, `D:\Projetos\welog-staging\resources\js\Components\PageHeader.vue:52-57`, `D:\Projetos\welog-staging\resources\css\app.css:34`

A copy "A {empresa} preparou um presente para você." (Catalog.vue:37) é a melhor frase do produto inteiro. Ela chega ao PageHeader como `subtitle` e é renderizada em `mt-1 text-base text-ink-muted` (PageHeader.vue:54) — #667085, que app.css:34 documenta como "rótulo, texto secundário", a mesma tinta do label "CEP" no formulário. Pior: Catalog.vue:34 zera o subtítulo quando há presentes de mais de uma empresa (`if (companies.value.length !== 1) return null`), e o dado migra para uma linha de 14px em cinza dentro do cartão (Catalog.vue:85-87).

**Consequência.** O sistema não tem como distinguir "texto que acolhe" de "texto que é menos importante". Qualquer frase calorosa que alguém escreva no portal vai automaticamente para o cinza de segunda categoria. Trocar a paleta não resolve: o papel que falta é semântico, não cromático.

**Recomendação.** Criar um papel de texto de acolhimento — distinto de `ink` (primário) e `ink-muted` (secundário) — que carregue peso e contraste de mensagem principal, não de legenda. No portal, a linha "quem te presenteou" deve ser tratada como conteúdo de primeira ordem: é ela que transforma um pedido logístico num gesto. E ela nunca deve desaparecer por conta do número de empresas.

### 40. A escala tipográfica não passa de 28px, e o único tamanho acima de 24px se chama "stat"

**Categoria:** token-faltante · **Dimensão:** Portal: onde falta vida

**Arquivos:** `D:\Projetos\welog-staging\resources\css\app.css:19-21`, `D:\Projetos\welog-staging\resources\css\app.css:9-13`, `D:\Projetos\welog-staging\resources\js\Components\PageHeader.vue:51`

O comentário em app.css:10 declara que a escala do Tailwind não é redefinida e que "o único tamanho novo é o número grande de indicador" — `--text-stat: 1.75rem` (28px), nomeado a partir de uma necessidade do dashboard operacional. O maior título que o portal consegue produzir é `text-2xl` = 24px (PageHeader.vue:51). Não existe nenhum token de escala expressiva no sistema.

**Consequência.** O portal é tipograficamente incapaz de ter um momento de impacto. A única extensão que alguém já fez na escala foi para atender o operador. Quando a marca chegar com uma família de display, não haverá degrau onde encaixá-la — ela vai ser aplicada em 24px, onde nenhuma fonte expressiva funciona.

**Recomendação.** Reservar um registro tipográfico expressivo (um ou dois degraus acima do maior atual) com dono declarado: exclusivo do portal, proibido na área operacional. Documentar o par — família expressiva + tamanho de revelação — como o slot que a tipografia da marca vai preencher. Hoje só existe uma família (`--font-sans: Inter`, app.css:14) servindo as duas superfícies, o que é a definição de "não houve decisão".

### 41. A tela mais decorada do produto é o formulário de login; a do presente é chapada

**Categoria:** ritmo-visual · **Dimensão:** Portal: onde falta vida

**Arquivos:** `D:\Projetos\welog-staging\resources\css\app.css:136-142`, `D:\Projetos\welog-staging\resources\js\Components\AuthCard.vue:9`, `D:\Projetos\welog-staging\resources\js\Layouts\PortalLayout.vue:40`

`.bg-mesh` (app.css:138-142) é descrita no próprio comentário como "única camada decorativa que sobrevive". Ela está aplicada em AuthCard.vue:9, ou seja, na tela de login/definição de senha. PortalLayout.vue:40 é `min-h-dvh bg-canvas` — #f6f7f9 sólido, sem nenhuma camada. O portal do presente tem menos atmosfera que o formulário de autenticação.

**Consequência.** A hierarquia de investimento visual do produto está invertida: a tela burocrática ganhou profundidade, a tela emocional não. E como `.bg-mesh` é uma utilidade solta com valores rgb literais (linhas 140-141), ela nem sequer segue a troca de paleta — quando a marca entrar, o único enfeite do sistema continuará índigo.

**Recomendação.** Definir um papel de "fundo de acolhimento" para o portal, derivado dos tokens (não de rgb literais), e reservá-lo às telas de revelação e de sucesso. A área operacional permanece em superfície chapada — ali profundidade decorativa disputa atenção com dado. É exatamente o tipo de decisão que faz uma base servir duas necessidades opostas em vez de escolher uma.

### 42. O cabeçalho do portal é o cabeçalho do admin com dois itens a menos — e mostra o e-mail da pessoa como elemento de destaque

**Categoria:** papel-mal-definido · **Dimensão:** Portal: onde falta vida

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Layouts\PortalLayout.vue:48-86`, `D:\Projetos\welog-staging\resources\js\Layouts\PortalLayout.vue:79-82`, `D:\Projetos\welog-staging\resources\js\Layouts\AppLayout.vue:106`, `D:\Projetos\welog-staging\resources\js\Layouts\AppLayout.vue:124`

O bloco de conta em PortalLayout.vue:79 (`min-w-0 max-w-40 text-right` com nome + e-mail) é idêntico ao de AppLayout.vue:124. A marca é um quadrado `size-8` (32px) com a letra "W" (PortalLayout.vue:51-56) mais a palavra "WeLog" (linha 57) — o nome da plataforma de logística, que a pessoa presenteada nunca ouviu falar. Em um viewport de celular de 390×844, o quadrado da marca ocupa 1.024px² de 329.160px²: 0,3% da tela. O nome da empresa que presenteou — dado que existe e é usado em Catalog.vue:86 — não aparece em lugar nenhum do chrome.

**Consequência.** Quem clica em "Resgatar meu presente" no e-mail chega e vê, no topo: uma marca desconhecida e o próprio endereço de e-mail. Nada na primeira dobra diz que isso é bom, e o e-mail no canto superior direito é o sinal visual mais forte de "painel de sistema" que existe. Para quem usa uma única vez, o e-mail não é informação útil — é ruído que confirma a leitura errada.

**Recomendação.** Dar chrome próprio ao portal em vez de herdar o do operador: remover o e-mail (nome basta, e ele já aparece na saudação), reservar o bloco de marca como espaço do logo WeGift em escala que caiba um logo real — não uma inicial em quadrado — e trazer o nome da empresa presenteadora para o topo persistente. Definir a faixa superior do portal como "faixa de acolhimento", papel que a cor institucional profunda da marca vai preencher.

### 43. Sem foto do produto o presente vira lista de estoque, e não existe nenhum plano B

**Categoria:** oportunidade · **Dimensão:** Portal: onde falta vida

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Pages\Portal\Catalog.vue:70-81`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Catalog.vue:89-98`, `D:\Projetos\welog-staging\app\Http\Controllers\Portal\PortalController.php:35`, `D:\Projetos\welog-staging\app\Services\Catalog\MirrorOmieProduct.php:55`

`image_url` vem espelhado da Omie (MirrorOmieProduct.php:55, com fallback para `url_imagem` e depois `null`). Quando é nulo, o `v-if` de Catalog.vue:70 simplesmente não renderiza nada — não há placeholder, ilustração ou padrão. O cartão fica reduzido a: nome em 18px, o rótulo "O QUE VEM DENTRO" em 12px maiúsculo cinza (linha 90) e uma `list-disc` com `{{ component.quantity }}× {{ component.name }}` (linha 95). "2× Caneca" é notação de nota fiscal. Os marcadores da lista usam `marker:text-ink-subtle` (linha 93), #98a2b3 — o cinza mais apagado do sistema.

**Consequência.** Metade dos presentes pode chegar à tela como um romaneio. É o pior cenário possível: a pessoa abre o e-mail esperando um presente e encontra uma conferência de itens. E como o kit é justamente o que a WeGift vende, os componentes — que deveriam ser o momento apetitoso — são o trecho mais seco da página.

**Recomendação.** Definir um papel de "superfície de presente": um fundo com o acento quente da marca e o ícone `gift` em escala grande, que ocupa a mesma área da foto quando ela não existe. O cartão nunca colapsa para texto. E tratar os componentes como conteúdo desejável, não como linha de estoque: cada item ganha respiro próprio e a quantidade deixa de ser prefixo com "×" — o "×" é linguagem de sistema.

### 44. O `description` do produto existe no banco e nunca chega a quem ganha o presente

**Categoria:** oportunidade · **Dimensão:** Portal: onde falta vida

**Arquivos:** `D:\Projetos\welog-staging\app\Models\Product.php:11`, `D:\Projetos\welog-staging\app\Http\Controllers\Portal\PortalController.php:31-43`, `D:\Projetos\welog-staging\app\Http\Controllers\Portal\PortalController.php:56-62`

`description` está no `$fillable` de Product.php:11 e é espelhado da Omie. O `catalog()` monta o payload com `name`, `image_url` e `components` (PortalController.php:33-40) e ignora a descrição; o `redeemForm()` (linhas 56-62) também. A pessoa que ganhou o presente vê um nome e uma lista de itens; o único texto que poderia contar o que o presente é fica no banco.

**Consequência.** O produto joga fora a única copy descritiva que já possui. Toda a carga de "vender" o presente recai sobre o nome do SKU, que é escrito por operação, para operação.

**Recomendação.** Levar a descrição para a tela do presente e dar a ela um papel tipográfico de texto de leitura confortável — não de legenda. Onde a descrição estiver vazia, é a superfície de presente e a lista de componentes que assumem; o layout precisa prever os dois casos desde o token de espaçamento.

### 45. Mais presentes = presente menor; e o portal é mais estreito que a tela do operador

**Categoria:** ritmo-visual · **Dimensão:** Portal: onde falta vida

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Layouts\PortalLayout.vue:92`, `D:\Projetos\welog-staging\resources\js\Layouts\PortalLayout.vue:49`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Catalog.vue:64`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Catalog.vue:77-80`, `D:\Projetos\welog-staging\resources\js\Layouts\AppLayout.vue:133`, `D:\Projetos\welog-staging\resources\js\Layouts\AppLayout.vue:223`

O `<main>` do portal é `max-w-2xl` = 672px (PortalLayout.vue:92). O do operador é `max-w-5xl` = 1024px e `max-w-6xl` = 1152px (AppLayout.vue:133 e :223). Com um presente e foto, `twoColumn` divide o cartão ao meio (Catalog.vue:66) e a imagem fica com ~336px de largura. Com dois ou mais presentes, `sm:grid-cols-2` (linha 64) dá 336px a cada cartão e a foto vira ~336×252. Em qualquer caso o recorte é `aspect-[4/3]` (linha 79) — paisagem, formato de banner, errado para a maioria das fotos de produto e de kit.

**Consequência.** Num monitor de 1920px, o presente é uma miniatura de 336×252 no meio de um campo cinza — enquanto a tabela de importações do operador ocupa 1152px. A largura do produto está alocada ao contrário da importância emocional. E o recorte 4/3 corta verticalmente exatamente o que um kit tem de bom: altura, empilhamento, embalagem.

**Recomendação.** A largura precisa ser função do MOMENTO, não do layout: revelação larga (a foto pode romper o contêiner de leitura), formulário estreito (672px está certo para o Redeem — é a medida de linha legível). Definir dois papéis de contêiner no portal e um papel de proporção de imagem que favoreça o produto (retrato ou quadrado), com a paisagem reservada a banner.

### 46. O UiCard não sabe ser generoso: o padding máximo é 16px, calibrado para tabela

**Categoria:** token-faltante · **Dimensão:** Portal: onde falta vida

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Components\UiCard.vue:11`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Catalog.vue:83`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redemptions.vue:116`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redeem.vue:310`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redeem.vue:317`, `D:\Projetos\welog-staging\resources\css\app.css:82-84`

O mapa de padding do UiCard é `{ none: '', sm: 'p-3', md: 'p-4' }` — teto de 16px. As telas do portal já denunciam a falta: Catalog.vue:83 e Redemptions.vue:116 usam `padding="none"` e escrevem `p-5` à mão, porque o componente não entrega. E onde ninguém contornou — o cartão de conferência do Redeem, linhas 310 e 317 — o momento irreversível é apresentado com o mesmo padding de 16px de uma linha de tabela. Em app.css:82-84 o sistema JÁ admite que o portal tem densidade própria (`--spacing-control-lg: 3rem` está comentado como "portal e autenticação"), mas essa admissão existe só para altura de controle.

**Consequência.** O portal não consegue respirar por construção. `--spacing-control-lg` prova que a necessidade foi reconhecida e resolvida pela metade: alvo de toque virou token, generosidade não. Enquanto o teto de padding for 16px, "acolhedor" depende de cada tela lembrar de escrever `p-5` na mão — e o Redeem esqueceu.

**Recomendação.** Promover a densidade do portal a registro completo, não só a altura de controle: padding generoso no UiCard (um degrau `lg`), raio de canto mais macio e ritmo vertical maior, todos selecionados pela mesma chave que hoje é `tone="portal"`. A área operacional continua no registro denso — ali, espaço perdido é dado a menos na tela do operador de 8 horas.

### 47. O presente some da tela exatamente no passo irreversível

**Categoria:** oportunidade · **Dimensão:** Portal: onde falta vida

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redeem.vue:308-332`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redeem.vue:311-314`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redeem.vue:71-73`, `D:\Projetos\welog-staging\app\Http\Controllers\Portal\PortalController.php:56-62`

O `redeemForm()` envia `product.name` e `components`, mas NÃO envia `image_url` (PortalController.php:56-62) — a foto nem chega ao Redeem. No passo 2, "Confira antes de confirmar", o presente inteiro é: um rótulo "Presente" em 12px maiúsculo cinza (linha 311), o nome em 16px (linha 312), "Presente de {empresa}" em 14px cinza (linha 313) e a `componentsLine` — os componentes achatados numa string única com separador " · " (Redeem.vue:71-73, linha 314). O cartão tem duas seções separadas por divisória, com rótulos maiúsculos de 12px: é literalmente o padrão de painel de detalhe do admin.

**Consequência.** No instante de maior tensão da jornada — a única ação irreversível do sistema, como o próprio comentário em Redeem.vue:36-39 reconhece — a pessoa não vê o que está ganhando. Ela confere um endereço contra uma linha de texto. Some a razão de estar ali e sobra a burocracia, exatamente onde a confiança mais importa.

**Recomendação.** Levar a imagem do presente para o passo 2 e dar a ela presença real na tela de confirmação — o objeto desejado precisa estar visível na hora de decidir. A conferência de endereço deixa de ser "seção 2 de um painel" e passa a ser o subordinado: o presente é o herói, o endereço é o detalhe. E o "·" que hoje achata os componentes numa string devolve o kit a itens legíveis.

### 48. A última coisa que a pessoa lê antes de confirmar é uma caixa de alerta âmbar

**Categoria:** ritmo-visual · **Dimensão:** Portal: onde falta vida

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redeem.vue:334-338`, `D:\Projetos\welog-staging\resources\js\Components\UiAlert.vue:17`, `D:\Projetos\welog-staging\resources\js\Components\UiAlert.vue:22`, `D:\Projetos\welog-staging\resources\css\app.css:56-58`

Entre o resumo e o botão "Confirmar e finalizar" há um `UiAlert variant="warning"` (linha 334): "Depois de confirmar não é possível trocar o endereço." Isso renderiza `border-warning-line bg-warning-soft` com ícone `alert` em #b54708 (UiAlert.vue:17) e `role="alert"` (UiAlert.vue:22). O último acento cromático da jornada de resgate, imediatamente antes do clique mais importante, é âmbar de perigo. Note o contraste: em toda a Redeem.vue, o âmbar aparece duas vezes (linhas 236 e 334) e a comemoração, zero.

**Consequência.** O ritmo emocional da tela termina em alerta. A pessoa clica "Confirmar" logo depois de ver uma caixa de cuidado — a saída do fluxo tem gosto de risco, não de conquista. Além disso o `role="alert"` interrompe leitor de tela com uma advertência no fecho da jornada.

**Recomendação.** Rebaixar o aviso de irreversibilidade a nota discreta ancorada no próprio bloco de endereço — onde a correção acontece e onde ela é acionável — e liberar o espaço imediatamente antes do botão para o registro positivo. O papel de celebração precisa aparecer aqui: a ação primária do momento de conquista deve carregar o acento quente da marca, não o índigo genérico de "botão do sistema".

### 49. O verde de comemoração do sistema está comemorando um CEP

**Categoria:** papel-mal-definido · **Dimensão:** Portal: onde falta vida

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redeem.vue:212-222`, `D:\Projetos\welog-staging\resources\css\app.css:52-54`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Catalog.vue:104-107`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redemptions.vue:102`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redemptions.vue:74-75`

`--color-success: #067647` é um verde institucional escuro — tom de "validado/conforme", não de festa. No Redeem.vue ele aparece exatamente uma vez, nas linhas 215-218: o bloco `bg-success-soft` com ícone de check que confirma que o CEP foi encontrado. O mesmo verde também assina "Pedido confirmado" (Catalog.vue:104), "Resgate confirmado!" (Redemptions.vue:102) e "Entregue" (Redemptions.vue:74-75). É o mesmo colapso semântico já confirmado em `info` = `brand-700`: aqui, validação e celebração são a mesma tinta.

**Consequência.** A tela usa o mesmo sinal visual para "seu código postal está correto" e para "você vai receber um presente". Como o verde já está gasto em validação de formulário, ele perde qualquer poder de marcar o momento bom — e não sobra nenhum outro sinal para marcá-lo.

**Recomendação.** Separar dois papéis hoje fundidos: `success` permanece com validação e estado correto (é da área operacional que ele nasceu, e lá está certo); um papel novo de celebração, preenchido pelo acento quente da marca, assume os momentos de conquista do portal. A regra decide o conflito: se a mensagem é sobre um campo, é validação; se é sobre o presente, é celebração.

### 50. A recompensa da única ação irreversível do produto é uma tira dispensável de 12px de padding, e o título abaixo dela diz "Entregas"

**Categoria:** ritmo-visual · **Dimensão:** Portal: onde falta vida

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redemptions.vue:102-106`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redemptions.vue:26-30`, `D:\Projetos\welog-staging\resources\js\Components\UiAlert.vue:33`, `D:\Projetos\welog-staging\resources\js\Components\UiAlert.vue:37-38`

O sucesso do resgate é um `UiAlert` (Redemptions.vue:102) renderizado como `flex gap-3 rounded-lg border p-3` (UiAlert.vue:33) — 12px de padding — com título em `text-sm` (14px) e corpo em `text-sm` (UiAlert.vue:37-38). É `dismissible`, e está posicionado ANTES do PageHeader (linha 102 vem antes da 106), então o título da página logo abaixo do maior momento da jornada é a palavra "Entregas". O texto da confirmação (linhas 26-30) é "Enviamos os próximos avisos para {e-mail}" — administração de notificação, não o presente. Não há nome do produto, nem imagem, nem empresa, nem data.

**Consequência.** A pessoa acabou de tomar a decisão mais tensa do produto e recebe de volta um aviso de 14px sobre o funcionamento do e-mail, que ela pode fechar por engano, seguido da palavra "Entregas" em destaque. O único momento inequivocamente feliz da jornada é tratado com o mesmo componente que avisa "CSV importado com 3 linhas bloqueadas".

**Recomendação.** O sucesso do resgate precisa ser um ESTADO DA TELA, não um alerta: quando se chega vindo da confirmação, a página assume por inteiro o papel de celebração — superfície de acolhimento, acento quente, o presente visível, o nome do produto e a empresa na mensagem. "Entregas" é rótulo de menu, não título de chegada. E a mensagem fala do presente antes de falar de e-mail.

### 51. A entrega tem três estágios e é desenhada como um ponto parado — sendo que a barra de progresso já existe duas telas antes

**Categoria:** papel-mal-definido · **Dimensão:** Portal: onde falta vida

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redemptions.vue:47-92`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redemptions.vue:120-126`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redeem.vue:176-182`, `D:\Projetos\welog-staging\resources\js\Components\UiTimeline.vue:20-36`

`portalState()` devolve, para `confirmed` (linhas 53-58) e para `shipped` (linhas 59-67), exatamente o mesmo par visual: `dot: 'bg-info'` e `text: 'text-ink'`. "Em preparação" e "A caminho" são pixel a pixel idênticos — só o texto muda. E `bg-info` é #4338ca, o mesmo valor de `brand-700`: o ponto de status usa a tinta da marca. A representação inteira é um `size-2.5` (10px) redondo mais um parágrafo (linhas 121-125). Enquanto isso, a barra de progresso de dois segmentos já foi construída e usada no Redeem.vue:177-180, e o UiTimeline existe — mas está escondido atrás do `<details>` da linha 142.

**Consequência.** A tela de acompanhamento não tem antecipação: não mostra que o presente está avançando, mostra que ele tem um enum. Os dois estágios de espera — o momento em que a expectativa cresce — são visualmente indistinguíveis, e a pessoa que volta na tela dias depois não percebe nenhuma diferença sem ler. Além disso, `info` compartilhar valor com `brand-700` significa que na troca de paleta os pontos de status vão mudar junto com os botões, sem que ninguém tenha decidido isso.

**Recomendação.** Transformar o status numa escala de progresso — o presente percorre um caminho visível de três marcos (preparação, a caminho, entregue), reaproveitando o padrão de segmentos já existente no Redeem. Cada marco recebe um papel de cor próprio, sendo o marco final "entregue" o único que pode acender o acento de celebração. E `info` precisa parar de ser sinônimo de `brand`: se o ponto de status é informação, ele merece um papel próprio que sobreviva à troca de marca.

### 52. O código de rastreio é o menor texto da tela onde ele é a informação mais procurada

**Categoria:** valor-fora-do-token · **Dimensão:** Portal: onde falta vida

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redemptions.vue:128-138`, `D:\Projetos\welog-staging\resources\js\Components\UiCode.vue:41-47`, `D:\Projetos\welog-staging\resources\js\Layouts\PortalLayout.vue:92`

PortalLayout.vue:92 estabelece `text-base` (16px) como corpo do portal. O código de rastreio é renderizado pelo UiCode como `font-mono text-xs` (12px) sobre `bg-surface-muted` (UiCode.vue:42), sob um rótulo "CÓDIGO DE RASTREIO" também em `text-xs` cinza (Redemptions.vue:129-131). O transportador ao lado sai em `text-sm` (linha 134). Ou seja: numa tela cujo corpo é 16px, o dado que a pessoa abriu a página para copiar está em 12px — 25% menor que tudo em volta.

**Consequência.** O UiCode foi dimensionado para caber numa célula de tabela do operador (o comentário na linha 11 fala explicitamente de chave de NF de 44 dígitos em linha de tabela) e foi reaproveitado no portal sem recalibrar. Em celular, 12px monoespaçado cinza é desconfortável de ler e de conferir — e conferir dígito a dígito é justamente o que se faz com um rastreio.

**Recomendação.** O UiCode precisa de registro do portal, na mesma chave que hoje é `tone`: no portal ele sobe para a escala de corpo e ganha superfície de destaque; na área operacional continua compacto. Regra geral que falta ao sistema e que este caso torna verificável: nenhum texto lido no portal pode ficar abaixo da escala de corpo definida pelo layout.

### 53. Nenhuma data para esperar — a única data da tela de antecipação olha para trás

**Categoria:** oportunidade · **Dimensão:** Portal: onde falta vida

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redemptions.vue:154-158`, `D:\Projetos\welog-staging\app\Http\Controllers\Portal\PortalController.php:102-118`, `D:\Projetos\welog-staging\app\Models\Project.php:34`, `D:\Projetos\welog-staging\app\Http\Controllers\ProjectController.php:36`

O rodapé do cartão de acompanhamento mostra "Pedido feito em {data}" (Redemptions.vue:157) em `text-sm text-ink-muted`. É a única data da tela e aponta para o passado. `delivery_deadline` existe no Project (Project.php:34, cast `date` na linha 41) e é entregue ao OPERADOR em ProjectController.php:36 e :68 — mas o `redemptions()` do portal (PortalController.php:102-118) não envia data alguma de previsão. Quem opera vê o prazo; quem espera o presente, não.

**Consequência.** A tela chamada "acompanhar a entrega" não dá nada para acompanhar. Sem uma data à frente, não existe antecipação: a pessoa volta, lê o mesmo parágrafo cinza e sai. É a diferença entre um rastreamento e uma contagem regressiva — e a contagem regressiva é o que transforma espera em expectativa.

**Recomendação.** Trazer uma data de chegada esperada para o portal e tratá-la como o elemento de maior peso do cartão de acompanhamento — é ela que dá emoção à espera. Reservar um papel visual de "antecipação" para esse dado (destaque tipográfico e o acento quente), distinto dos rótulos operacionais. A data do pedido continua sendo metadado discreto.

### 54. Os estados vazios do portal são componentes de admin: borda tracejada, ícone de caminhão e título menor que o corpo do texto

**Categoria:** papel-mal-definido · **Dimensão:** Portal: onde falta vida

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Components\UiEmpty.vue:12`, `D:\Projetos\welog-staging\resources\js\Components\UiEmpty.vue:21-22`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Catalog.vue:57-62`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redemptions.vue:108-112`, `D:\Projetos\welog-staging\resources\js\Layouts\PortalLayout.vue:92`

O UiEmpty é `border-dashed border-line` (linha 12) — a borda tracejada é o sinal universal de "área de upload / placeholder vazio" de ferramenta administrativa. O título é `text-sm font-semibold` e a mensagem `text-sm` (linhas 21-22), ou seja 14px, MENOR que o corpo de 16px que PortalLayout.vue:92 define para o portal: o componente não tem registro de portal e puxa a tela para baixo. O ícone vive num círculo `size-10` em `bg-surface-muted text-ink-muted` — cinza sobre cinza. E em Redemptions.vue:108 o ícone escolhido para "Você ainda não pediu nenhum presente" é um CAMINHÃO.

**Consequência.** O estado vazio do Catalog (linhas 57-62) é a tela que uma pessoa vê quando o presente dela ainda não foi liberado — um momento de "em breve", que é potencialmente positivo. Ele é renderizado como caixa tracejada cinza com texto menor que o normal: parece um formulário incompleto ou um erro. E o estado vazio da tela de entregas anuncia a ausência do presente com o ícone de um caminhão de carga.

**Recomendação.** O estado vazio do portal precisa ser um registro próprio, não a versão apagada do estado vazio operacional: sem borda tracejada (ela significa "faltando"), com escala de texto igual ou maior que o corpo, superfície de acolhimento no lugar do cinza e ícone que fale de presente, não de logística. Regra do sistema: "ainda não" no portal é promessa, e promessa não se desenha com borda pontilhada.

### 55. O produto inteiro não tem um único movimento de conteúdo — e a infraestrutura de animação já está pronta e ociosa

**Categoria:** oportunidade · **Dimensão:** Portal: onde falta vida

**Arquivos:** `D:\Projetos\welog-staging\resources\css\app.css:154-179`, `D:\Projetos\welog-staging\resources\css\app.css:88`, `D:\Projetos\welog-staging\resources\css\app.css:181-186`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Catalog.vue:64-130`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Redemptions.vue:102`

Existem exatamente quatro animações no sistema — `toast-in`, `overlay-in`, `drawer-in` e `skeleton` (app.css:154-179) — e todas as quatro servem chrome: aviso, sobreposição, gaveta e carregamento. Nenhuma serve conteúdo. Não há keyframe de entrada, revelação ou celebração. O token de easing `--ease-out-soft` (app.css:88) já existe e o bloco `prefers-reduced-motion` (linhas 181-186) já está montado e correto. Ou seja: a base está pronta, a revelação do presente (Catalog.vue:64-130) e a confirmação (Redemptions.vue:102) simplesmente aparecem estáticas.

**Consequência.** Nada no produto se move a favor da pessoa. O presente não é revelado, é exibido; a confirmação não acontece, está lá. Movimento é o recurso mais barato de "vida" disponível — o custo aqui é apenas escrever os keyframes, porque o easing, a regra de acessibilidade e a convenção de nomes já existem.

**Recomendação.** Adicionar um registro de movimento com dono declarado: entrada escalonada na revelação do presente e um momento de celebração no sucesso do resgate, ambos exclusivos do portal, ambos incluídos no bloco `prefers-reduced-motion` já existente. A área operacional permanece sem movimento de conteúdo — para quem passa 8 horas na tela, animação de entrada em lista de dados é atrito, não encanto. É mais um lugar onde a mesma base precisa se comportar de forma oposta nas duas superfícies.

### 56. A tela de entrada é a única do produto que não escreve o nome da marca

**Categoria:** papel-mal-definido · **Dimensão:** Autenticação: primeira impressão

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Components\AuthCard.vue:11-20`, `D:\Projetos\welog-staging\resources\js\Layouts\PortalLayout.vue:53-57`, `D:\Projetos\welog-staging\resources\js\Layouts\AppLayout.vue:110-114`, `D:\Projetos\welog-staging\resources\js\Layouts\AppLayout.vue:146-152`, `D:\Projetos\welog-staging\resources\js\Layouts\AppLayout.vue:216-220`

AuthCard.vue:12-17 renderiza um quadrado de 48px em bg-brand-600 com a letra "W", e nada mais: o span está com aria-hidden="true" e não existe nenhum texto com o nome. Já PortalLayout.vue:57 e AppLayout.vue:114/151/220 renderizam exatamente o mesmo quadrado SEGUIDO do wordmark "WeLog" em texto. Ou seja: as telas internas, vistas por quem já sabe onde está, têm nome; a tela de entrada, vista por quem chega de um link de e-mail sem contexto, não tem. AppLayout.vue:152 é o único lugar do produto inteiro onde a palavra "WeGift" aparece na interface ("por WeGift", na sidebar do operador) — e é justamente a tela que o cliente final nunca vê. Para leitor de tela, a página de login anuncia apenas o h1 "Entrar": a identidade é literalmente inaudível.

**Consequência.** O primeiro contato de 100% dos usuários acontece sem nome de marca na tela. Quem recebe um convite por e-mail chega numa página que não confirma que ele está no lugar certo — o sinal mais básico de confiança antes de digitar uma senha. E quando o logo oficial da WeGift chegar, não existe um slot para ele: existe um caractere dentro de um quadrado colorido, que não é um lugar onde um logo cabe (proporção fixa 1:1, fundo sólido, sem espaço para wordmark horizontal).

**Recomendação.** Criar um componente único BrandMark com dois papéis distintos e explícitos: "símbolo" (quadrado/ícone, proporção livre) e "wordmark" (nome por extenso), com props de tamanho. Na tela de entrada usar a combinação símbolo + wordmark, com o nome em texto real (não aria-hidden) para que o leitor de tela anuncie a marca antes do formulário. Enquanto não há SVG, o wordmark é tipográfico — e isso já resolve hoje, porque quando o arquivo chegar ele substitui o conteúdo do slot em um arquivo só. Definir também qual nome vale na porta de entrada: "WeLog" (plataforma) ou "WeGift" (marca), decisão que hoje está tomada por omissão em cada arquivo separadamente.

### 57. O placeholder de marca está copiado literalmente em 6 lugares, em 3 tamanhos, um deles fora do Vue

**Categoria:** papel-mal-definido · **Dimensão:** Autenticação: primeira impressão

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Components\AuthCard.vue:12-17`, `D:\Projetos\welog-staging\resources\views\errors\403.blade.php:13`, `D:\Projetos\welog-staging\resources\js\Layouts\PortalLayout.vue:51-56`, `D:\Projetos\welog-staging\resources\js\Layouts\AppLayout.vue:108-113`, `D:\Projetos\welog-staging\resources\js\Layouts\AppLayout.vue:144-149`, `D:\Projetos\welog-staging\resources\js\Layouts\AppLayout.vue:214-219`

A mesma marcação — flex, items-center, justify-center, rounded, bg-brand-600, font-bold, text-ink-inverse, conteúdo "W" — está escrita seis vezes à mão, com três geometrias diferentes que ninguém decidiu: size-12 + rounded-lg + text-lg na autenticação (AuthCard.vue:14 e 403.blade.php:13), size-8 + rounded-md + text-sm em quatro lugares, e size-9 + rounded-md + text-sm na sidebar (AppLayout.vue:146). Não existe token nem componente: existe repetição. Uma das cópias mora em Blade (errors/403.blade.php:13), fora do alcance de qualquer componente Vue.

**Consequência.** Trocar a assinatura visual pelo logo real da WeGift exige seis edições em duas linguagens, e a versão Blade vai ficar para trás por não compartilhar código com o resto. Pior: como os três tamanhos não são um sistema (12/9/8 sem lógica), o logo oficial vai precisar de três decisões de escala improvisadas na hora, em vez de herdar uma escala já definida. A marca aparece maior na tela de erro e menor na tela onde o usuário passa o dia, sem que isso tenha sido uma decisão.

**Recomendação.** Consolidar em um único BrandMark com escala definida por token (por exemplo três degraus nomeados por papel: entrada, cabeçalho, compacto), e expor uma versão Blade que leia o mesmo markup (partial x-brand-mark) para que a página de erro use a mesma fonte de verdade. Regra a fixar: nenhuma tela escreve bg-brand-600 + letra à mão; a única forma de exibir a marca é o componente.

### 58. O único elemento decorativo da tela de entrada é indigo cravado em hexadecimal, com um matiz que nem existe na rampa

**Categoria:** valor-fora-do-token · **Dimensão:** Autenticação: primeira impressão

**Arquivos:** `D:\Projetos\welog-staging\resources\css\app.css:138-142`, `D:\Projetos\welog-staging\resources\css\app.css:41-46`, `D:\Projetos\welog-staging\resources\js\Components\AuthCard.vue:9`, `D:\Projetos\welog-staging\resources\views\errors\403.blade.php:10`

A utilitária .bg-mesh (app.css:138-142) é descrita no próprio comentário como "fundo de destaque das telas públicas (AuthCard)" — é a única camada atmosférica que sobreviveu ao redesign. Ela usa dois radial-gradient com cor escrita à mão: rgb(99 102 241 / 0.10), que é #6366f1, ou seja o valor de --color-brand-500 duplicado fora do token; e rgb(129 140 248 / 0.10), que é #818cf8 — o indigo-400 do Tailwind, um degrau que NÃO EXISTE na rampa brand (app.css:41-46 tem 50, 100, 200, 500, 600, 700). Aplicada em AuthCard.vue:9 e em errors/403.blade.php:10.

**Consequência.** Na troca de paleta, o fundo da tela de entrada continua indigo. O logo novo, o botão novo e os textos novos vão aparecer sobre um halo da cor antiga — e a tela que mais precisa parecer da marca vai ser a única que ainda parece do Tailwind. O segundo matiz é ainda pior: como #818cf8 não tem token correspondente, não existe "onde" colocar o equivalente da nova marca; alguém vai ter que inventar um valor na hora. Este achado é a prova concreta de que o buraco 300/400 da rampa custa dinheiro.

**Recomendação.** Duas coisas, nesta ordem. Primeiro, completar a rampa com os degraus faltantes (300, 400, 800, 900) para que exista um destino para cada valor que hoje está solto. Segundo, reescrever o .bg-mesh derivando das variáveis, sem nenhum hexadecimal literal — usando color-mix(in oklab, var(--color-brand-400) 10%, transparent) e equivalente para o segundo foco. Melhor ainda: promover a atmosfera a token de papel (algo como --gradient-entrada), para que a decisão de "qual é a cara do fundo público" fique num lugar só e possa mudar de sentido no portal (acento de celebração) sem mudar na área operacional (base institucional).

### 59. A barra de progresso — o primeiro movimento visível de toda navegação — é um slate cravado que não é da marca

**Categoria:** valor-fora-do-token · **Dimensão:** Autenticação: primeira impressão

**Arquivos:** `D:\Projetos\welog-staging\resources\js\app.js:35-38`

createInertiaApp recebe progress: { color: '#0f172a', showSpinner: false }. #0f172a é o slate-900 do Tailwind: não é --color-brand-600, não é --color-ink (#101828), não é token nenhum. É a barra que corre no topo da tela em toda transição do Inertia, incluindo o POST do login — o primeiro feedback de movimento que a pessoa recebe depois de clicar "Entrar".

**Consequência.** A cor mais frequentemente animada do produto inteiro está fora do sistema. Na troca de paleta ela permanece slate escuro, e como é um valor em JavaScript (não em CSS) ela é invisível para qualquer busca por tokens no CSS — vai passar batido. Além disso, o papel está errado: barra de progresso é feedback de ação, deveria carregar a cor de ação/acento, não uma tinta neutra escura que não comunica marca nenhuma.

**Recomendação.** Definir o papel "cor de progresso" no sistema e ler o valor da variável CSS em tempo de execução (getComputedStyle no :root) em vez de escrever hexadecimal no JS. Assim a barra passa a ser um território de marca — num produto Inertia ela aparece dezenas de vezes por sessão do operador e é gratuita. Decidir explicitamente se progresso usa a base institucional (calmo, área operacional) ou o acento (visível, portal).

### 60. Antes do Vue montar não existe marca nenhuma — e o navegador também não recebe nenhum sinal

**Categoria:** oportunidade · **Dimensão:** Autenticação: primeira impressão

**Arquivos:** `D:\Projetos\welog-staging\resources\views\app.blade.php:1-28`, `D:\Projetos\welog-staging\resources\views\app.blade.php:25-26`

O shell tem body com bg-canvas text-ink (app.blade.php:25) e em seguida só @inertia, que renderiza uma div vazia com data-page. Não há flash de tela BRANCA — isso está correto e é mérito do redesign, o fundo já nasce no token — mas há um retângulo #f6f7f9 completamente vazio até o bundle baixar, avaliar e montar. Não há skeleton, não há marca, não há <noscript>. O <head> (linhas 3-21) tem charset, viewport, title, preconnect de fontes, @routes, @vite e @inertiaHead — e mais nada: nenhum <link rel="icon">, nenhum apple-touch-icon, nenhum <meta name="theme-color">, nenhum meta description, nenhuma tag Open Graph. Existe um public/favicon.ico no repositório, mas ele não é declarado e nenhuma outra variante existe.

**Consequência.** O primeiro frame da marca é ausência: por alguns décimos de segundo (mais no 4G de celular, que é o cenário real do cliente final chegando por e-mail) a pessoa vê um retângulo cinza sem nada. Na barra do navegador do celular a área em volta da página fica branca ou cinza do sistema, sem cor da marca. Se alguém compartilhar o link do convite por WhatsApp, aparece um card sem imagem e sem descrição. "Adicionar à tela de início" gera um ícone genérico. São quatro superfícies de identidade que já estão pagas pelo tráfego e não estão sendo usadas.

**Recomendação.** Pintar um shell de marca no próprio HTML — símbolo/wordmark centralizado sobre o fundo de entrada, dentro da div do Inertia, que o Vue substitui ao montar. Custa poucos bytes, elimina o vazio e faz a marca ser a primeira coisa renderizada, antes de qualquer JavaScript. Em paralelo, declarar o conjunto completo de cromo: ícone em SVG e PNG, apple-touch-icon, theme-color lido do token da base institucional, meta description e og:image. Nada disso depende do logo final para ser ESTRUTURADO agora: basta deixar os arquivos e as tags no lugar, com o placeholder tipográfico, para que a troca seja substituir imagens.

### 61. O e-mail promete presente e a tela de destino responde com formulário administrativo

**Categoria:** papel-mal-definido · **Dimensão:** Autenticação: primeira impressão

**Arquivos:** `D:\Projetos\welog-staging\app\Models\EmailTemplate.php:36-45`, `D:\Projetos\welog-staging\app\Mail\ProjectReleasedMail.php:34-42`, `D:\Projetos\welog-staging\resources\views\mail\account-invitation.blade.php:4`, `D:\Projetos\welog-staging\resources\js\Pages\Auth\Login.vue:37`, `D:\Projetos\welog-staging\resources\js\Pages\Auth\SetPassword.vue:42`, `D:\Projetos\welog-staging\resources\js\Pages\Portal\Catalog.vue:37`, `D:\Projetos\welog-staging\resources\views\errors\403.blade.php:20`

O caminho do cliente final é rastreável linha a linha. O e-mail de projeto liberado (EmailTemplate.php:36-45) tem assunto "Seu presente está disponível para resgate!" e corpo "A {{ companyName }} preparou um presente para você: {{ productName }}". O botão é "Resgatar meu presente" e aponta para route('portal.catalog') (ProjectReleasedMail.php:36-41) — que, para visitante não autenticado, cai no /login. O que a pessoa lê ao chegar é o h1 "Entrar" e o subtítulo "Acesse com o e-mail que recebeu o convite." (Login.vue:37). Nem a palavra presente, nem o nome da empresa que presenteou, nem o produto. O e-mail de convite de conta é ainda mais frio: "Sua conta na plataforma WeLog foi criada" (account-invitation.blade.php:4). A frase quente existe no produto — Catalog.vue:37 diz "A {empresa} preparou um presente para você." — mas só depois do login. Detalhe revelador: a página de erro 403 (403.blade.php:20) é hoje a ÚNICA superfície pública que fala de presente ("Entre com o e-mail que recebeu o convite para abrir o seu presente"), enquanto a tela de login, que é o destino real do e-mail, não fala.

**Consequência.** A promessa quebra exatamente no ponto de maior atrito da jornada: a hora de criar/digitar senha. O usuário que estava emocionalmente engajado ("ganhei um presente") passa por uma tela que não confirma nada do que o trouxe, e a taxa de abandono nesse degrau é o custo direto. Também é aqui que a tela "sem vida" mais dói, porque é o único momento em que a expectativa do usuário era alta.

**Recomendação.** Fazer a tela de entrada herdar o contexto de chegada em vez de ser sempre neutra. Estruturalmente: o link do convite já carrega token (rota password.set.form), então o backend pode enviar como prop o primeiro nome e o nome da empresa que presenteou, e a casca de autenticação exibir uma linha de contexto acima do formulário — do tipo "A {empresa} preparou algo para você. Crie sua senha para abrir." Onde não houver contexto (operador entrando de manhã), a mesma casca cai no tom institucional sem a linha. E alinhar o tom do e-mail de convite de conta, que hoje é o mais frio dos três e é justamente o primeiro que o cliente final recebe.

### 62. A casca de autenticação não distingue operador de presenteado — é a mesma tinta para necessidades opostas

**Categoria:** papel-mal-definido · **Dimensão:** Autenticação: primeira impressão

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Components\AuthCard.vue:1-41`, `D:\Projetos\welog-staging\resources\js\Pages\Auth\Login.vue:37`, `D:\Projetos\welog-staging\resources\js\Pages\Auth\SetPassword.vue:42`, `D:\Projetos\welog-staging\resources\js\Pages\Auth\ResetPassword.vue:40`, `D:\Projetos\welog-staging\resources\js\Pages\Auth\ForgotPassword.vue:28`

AuthCard aceita exatamente duas props: title e subtitle (linhas 2-5). As quatro páginas de autenticação passam por ela sem nenhuma distinção de destino. Mas SetPassword é a tela de primeiro acesso TANTO do operador WeGift (convidado por account-invitation) QUANTO do cliente final que ganhou um presente — dois estados emocionais opostos recebendo o mesmo cinza, o mesmo quadrado indigo e o mesmo "Só isso e você já entra." (SetPassword.vue:42). Da mesma forma, /login é a porta única: routes/web.php:15 redireciona a raiz para lá, e LoginController.php:41 só decide o destino DEPOIS do sucesso (redirect()->intended(route($request->user()->homeRouteName()))).

**Consequência.** O sistema não tem como fazer a superfície mais emocional parecer diferente da mais operacional, porque a diferença não existe como parâmetro em lugar nenhum. Qualquer tentativa de "deixar mais bonito" na entrada vai afetar as duas ao mesmo tempo: ou o operador ganha uma tela festiva que ele vê todo dia às 8h (irritante), ou o presenteado continua com formulário administrativo (frio). É este achado que decide se a modernização vai funcionar nas duas superfícies ou falhar nas duas.

**Recomendação.** Introduzir na casca um parâmetro de TOM com dois valores nomeados por papel — institucional (padrão, área operacional) e celebração (portal/presente) — e mapear cada tom para tokens já existentes, não para valores novos: o tom institucional usa a base institucional profunda como painel e a marca como ação; o tom de celebração usa o acento quente no painel e na atmosfera, mantendo a MESMA estrutura, a MESMA tipografia e o MESMO formulário. A regra que torna isso seguro: o tom só pode mudar cor de painel, atmosfera e linha de contexto — nunca mexe em campo, botão primário, foco ou mensagem de erro. Assim a mesma base serve as duas necessidades sem virar dois sistemas.

### 63. A hierarquia dentro do cartão é comprimida: título, rótulo e dado disputam o mesmo peso

**Categoria:** ritmo-visual · **Dimensão:** Autenticação: primeira impressão

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Components\AuthCard.vue:18-19`, `D:\Projetos\welog-staging\resources\js\Components\UiField.vue:61`, `D:\Projetos\welog-staging\resources\js\Pages\Auth\SetPassword.vue:53-56`, `D:\Projetos\welog-staging\resources\js\Pages\Auth\ResetPassword.vue:51-52`, `D:\Projetos\welog-staging\resources\js\Pages\Auth\ResetPassword.vue:40`

O h1 da tela é text-2xl font-semibold text-ink (AuthCard.vue:18) — 24px, peso 600, tinta primária. O rótulo de cada campo é text-sm font-medium text-ink (UiField.vue:61) — 14px, peso 500, MESMA tinta. O salto entre o elemento mais importante da tela e o terceiro nível é de 1,7x em tamanho e um degrau de peso, com zero diferença de cor. Em SetPassword.vue:53-56 a inversão fica explícita: o rótulo "Definindo a senha de" é text-xs font-semibold uppercase tracking-wider (peso 600, caixa alta, entrelinhamento aberto) e o DADO — o e-mail da pessoa, que é a informação que ela precisa conferir — é text-sm sem peso nenhum. O rótulo grita mais alto que o dado. O mesmo padrão em ResetPassword.vue:51-52. Somando: ResetPassword.vue:40 chama AuthCard sem subtitle, enquanto as outras três passam — o bloco de cabeçalho tem altura diferente entre telas da mesma família, sem motivo.

**Consequência.** Numa coluna de 448px com quatro a cinco blocos de texto, nada domina. O olho não recebe uma ordem de leitura — ele varre. É exatamente a sensação de "sem vida": não falta cor, falta CONTRASTE DE PESO. E como o rótulo compartilha a tinta text-ink com o título, quando a nova paleta entrar a hierarquia continuará achatada, porque o problema não é de matiz.

**Recomendação.** Abrir o salto tipográfico no topo: o título de entrada é o único texto grande da tela e deve ficar visivelmente acima de tudo (salto de 2x ou mais em relação ao rótulo), com o subtítulo caindo para a tinta secundária. Rebaixar rótulo de campo para a tinta secundária e reservar text-ink apenas para título, dado e valor digitado — assim a cor passa a codificar nível, não só tamanho. Inverter o par rótulo/dado em SetPassword e ResetPassword: o e-mail é o dado, deve ser o elemento forte; o rótulo é legenda. E padronizar: as quatro telas ou todas têm subtítulo, ou o bloco de cabeçalho reserva a mesma altura.

### 64. A ação primária não tem respiro próprio — o botão respira igual a um campo

**Categoria:** ritmo-visual · **Dimensão:** Autenticação: primeira impressão

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Pages\Auth\Login.vue:54`, `D:\Projetos\welog-staging\resources\js\Pages\Auth\Login.vue:89`, `D:\Projetos\welog-staging\resources\js\Pages\Auth\SetPassword.vue:51`, `D:\Projetos\welog-staging\resources\js\Pages\Auth\SetPassword.vue:93`, `D:\Projetos\welog-staging\resources\js\Pages\Auth\ResetPassword.vue:49`, `D:\Projetos\welog-staging\resources\js\Pages\Auth\ResetPassword.vue:93`, `D:\Projetos\welog-staging\resources\js\Pages\Auth\ForgotPassword.vue:46`

Nas quatro telas o <form> usa class="space-y-4" e o UiButton de submit é o último filho DESSE mesmo container. Consequência mecânica: a distância entre o campo Senha e o botão Entrar é idêntica à distância entre o campo E-mail e o campo Senha — 16px em todos os casos. Em Login.vue a coluna fica com três controles de 48px de altura (UiInput size=lg, UiInput size=lg + botão Mostrar, UiButton size=lg block) separados por espaços iguais. Em SetPassword.vue são quatro. Visualmente, o botão vira "mais um campo da pilha".

**Consequência.** Não existe um momento de conclusão na tela. A leitura é uma lista uniforme de retângulos de 48px, o que reforça a percepção de formulário administrativo e enfraquece a única ação que importa. É um problema de ESPAÇO, não de cor — vai continuar existindo depois da troca de paleta, e vai neutralizar parte do ganho da cor nova no botão.

**Recomendação.** Tirar o submit do fluxo de space-y-4 e dar a ele um bloco próprio com espaço maior (ordem de 1,5x a 2x o espaçamento entre campos), formando três zonas legíveis no cartão: contexto/aviso, campos, ação. Aproveitar para definir isso como token de papel ("espaço entre grupo de campos e ação") em vez de utilitário solto, já que o mesmo ritmo vale no portal e nos formulários da área operacional. Bônus na mesma linha: em Login.vue:78-85 o botão Mostrar/Ocultar é um controle de 48px em variante secondary colado ao campo — considerá-lo affordance interna do campo (ícone dentro do input) devolve largura ao campo e tira um retângulo da pilha.

### 65. A composição é o cartão centralizado padrão — e o cabeçalho é centralizado contra o eixo de leitura

**Categoria:** ritmo-visual · **Dimensão:** Autenticação: primeira impressão

**Arquivos:** `D:\Projetos\welog-staging\resources\js\Components\AuthCard.vue:9-11`, `D:\Projetos\welog-staging\resources\js\Components\AuthCard.vue:22`, `D:\Projetos\welog-staging\resources\js\Components\AuthCard.vue:33-38`

AuthCard.vue:9 é flex min-h-dvh items-center justify-center sobre bg-canvas com a atmosfera bg-mesh; dentro, w-full max-w-md (448px). O bloco de cabeçalho (linha 11) é text-center, e o rodapé (linha 33) também é justify-center text-center. O cartão em si (linha 22) é rounded-lg border border-line bg-surface p-6 sm:p-8. Ou seja: retângulo branco flutuando no meio de um fundo quase liso, tudo centralizado — a composição literal de qualquer sistema administrativo, e a mais barata de reproduzir. Em desktop 1440x900, isso significa 448px de conteúdo e cerca de 1000px de vazio sem função.

**Consequência.** A tela não tem território de marca. Como o cartão ocupa 31% da largura em desktop, os 69% restantes são espaço pago que não comunica nada — e é exatamente onde caberia a identidade sem virar enfeite. O text-center do cabeçalho também trabalha contra o eixo natural de leitura (usuários dedicam bem mais atenção à metade esquerda da tela — NN Group, "Horizontal Attention Leans Left", https://www.nngroup.com/articles/horizontal-attention-leans-left/), e quebra o alinhamento com os rótulos dos campos, que são alinhados à esquerda logo abaixo: o olho muda de eixo duas vezes em 200px.

**Recomendação.** Estrutura alternativa que não é enfeite porque cada metade tem função: em desktop, divisão assimétrica em duas colunas — painel de identidade à esquerda (na ordem de 5/12 da largura, altura total do viewport, preenchido com a base institucional e a atmosfera derivada dos tokens) carregando o wordmark no topo, uma linha de promessa e a nota de segurança no rodapé; formulário à direita (7/12), alinhado à esquerda dentro de uma coluna estreita, centralizado na vertical. Em mobile, o painel colapsa numa faixa superior curta com o wordmark, e o formulário segue no fluxo com a ação primária dentro da zona de alcance do polegar. Isso dá à marca um território real (não um quadradinho), respeita o viés de leitura à esquerda e mantém um único componente servindo os dois tons. Alinhar título e subtítulo à esquerda para que compartilhem o eixo dos rótulos.

### 66. Aviso e ação têm a mesma tinta: o único bloco colorido do login empata com o botão

**Categoria:** papel-mal-definido · **Dimensão:** Autenticação: primeira impressão

**Arquivos:** `D:\Projetos\welog-staging\resources\css\app.css:46`, `D:\Projetos\welog-staging\resources\css\app.css:65-67`, `D:\Projetos\welog-staging\resources\js\Pages\Auth\Login.vue:38-52`, `D:\Projetos\welog-staging\resources\js\Components\UiAlert.vue:15`

Consequência específica, na tela de entrada, do fato já diagnosticado de --color-info (#4338ca, app.css:65) ser idêntico a --color-brand-700 (app.css:46), com info-soft (#eef2ff) idêntico a brand-50 e info-line (#c7d2fe) idêntico a brand-200. UiAlert.vue:15 monta a variante info com border-info-line bg-info-soft text-info. Em Login.vue:40 o flash de status ("enviamos o link", "senha alterada") entra como variant="info" logo acima do formulário. Resultado concreto: o aviso, o quadrado da marca (bg-brand-600) e o botão Entrar (bg-brand-600 hover:bg-brand-700) são todos a mesma família de azul, nos mesmos poucos centímetros de tela.

**Consequência.** Numa tela onde só existem três manchas de cor, duas delas dizendo coisas diferentes ("isto é a marca / clique aqui" e "leia esta informação") têm o mesmo matiz. O usuário não consegue separar informação de ação por cor — que é a única pista rápida disponível. E na troca de paleta o problema piora: como info e marca são o mesmo token na prática, pintar a marca de novo repinta automaticamente todos os avisos informativos do produto, sem que ninguém tenha decidido isso.

**Recomendação.** Separar os papéis antes da paleta chegar: "informação neutra" e "identidade/ação" precisam ser dois tokens independentes, mesmo que temporariamente recebam valores próximos. O caminho natural na direção quente + confiável: informação passa a ser um neutro levemente frio de baixa saturação (calmo, não compete), a marca fica com a base institucional (ação primária), e o acento quente fica reservado para celebração — o que na tela de entrada significa: aviso não compete com botão, e o acento só aparece quando o contexto é presente.

### 67. A tipografia é o maior território de marca disponível na entrada — e hoje é Inter, sem nenhum token de display

**Categoria:** oportunidade · **Dimensão:** Autenticação: primeira impressão

**Arquivos:** `D:\Projetos\welog-staging\resources\views\app.blade.php:14-16`, `D:\Projetos\welog-staging\resources\css\app.css:14-21`, `D:\Projetos\welog-staging\resources\js\Components\AuthCard.vue:18`

A tela de entrada é 100% texto: não há imagem, não há ilustração, não há logo. Portanto a tipografia É a identidade nessa tela. Hoje --font-sans é 'Inter' (app.css:14), carregada do Google Fonts como fonte variável 400..700 (app.blade.php:16) — e o único tamanho novo que o @theme declara é --text-stat (app.css:20-21), voltado ao número grande de indicador da área operacional. Não existe nenhum token de display, nenhum peso acima de 700 disponível no arquivo baixado, e o título da entrada usa a escala padrão do Tailwind (text-2xl semibold).

**Consequência.** Inter é a fonte que aparece quando ninguém escolheu — o mesmo diagnóstico do #4f46e5, aplicado à tipografia. Como a tela não tem outro elemento visual, ela herda a aparência de qualquer painel administrativo genérico, independentemente da cor que entrar depois. E como o arquivo baixado para em 700, mesmo que se queira criar contraste de peso no wordmark tipográfico, o peso alto não está disponível — a estrutura hoje impede a solução.

**Recomendação.** Criar um token de papel --font-display, separado de --font-sans, aplicado a exatamente três lugares: wordmark, título da tela de entrada e título de celebração do portal. Isso é a estrutura que recebe a decisão de marca depois — se a WeGift tiver uma fonte institucional, ela entra em um token só; se não tiver, a escolha é isolada e reversível. Junto: ampliar a faixa de pesos carregada para que exista contraste real (peso alto no display contra peso normal no corpo), e verificar se a fonte deve ser auto-hospedada em vez de vir do Google — hoje a primeira impressão depende de um terceiro responder antes do texto assentar. Observação: app.css:125-128 já usa tokens de marca no ::selection, que é exatamente o tipo de detalhe de identidade barato que deve ser multiplicado (progresso, foco, seleção, cromo do navegador).

### 68. A página de erro é superfície de entrada, recria o botão primário à mão e as outras faltam

**Categoria:** valor-fora-do-token · **Dimensão:** Autenticação: primeira impressão

**Arquivos:** `D:\Projetos\welog-staging\resources\views\errors\403.blade.php:10`, `D:\Projetos\welog-staging\resources\views\errors\403.blade.php:13`, `D:\Projetos\welog-staging\resources\views\errors\403.blade.php:23-28`, `D:\Projetos\welog-staging\resources\js\Components\UiButton.vue:23`, `D:\Projetos\welog-staging\resources\js\Components\AuthCard.vue:9`

errors/403.blade.php é uma cópia manual da casca de autenticação em Blade. Três divergências concretas: (1) linha 10 usa min-h-screen, enquanto AuthCard.vue:9 usa min-h-dvh — no celular, onde a barra do navegador recolhe, as duas telas se comportam de forma diferente; (2) linha 13 recria o quadrado de marca à mão; (3) linhas 23-28 recriam o botão primário à mão (h-control-lg, rounded-md, bg-brand-600, px-4, text-sm font-semibold, hover:bg-brand-700) em vez de herdar do UiButton, e inclusive com peso divergente — UiButton.vue:44 usa font-medium, a cópia usa font-semibold. Além disso, o Glob em resources/views/errors mostra que existe SÓ o 403: não há 404 nem 419 personalizados.

**Consequência.** Quando o botão primário mudar (cor, raio, peso, altura), essa tela fica visualmente fora do sistema — e ela é uma tela de ENTRADA, vista por quem clicou num link para a área errada. Mais grave: 419 ("página expirada" por CSRF vencido) é um erro que acontece justamente em formulário de login deixado aberto, e 404 acontece com link de convite antigo ou digitado errado — os dois cenários mais prováveis do cliente final. Nesses casos o usuário cai na página padrão do framework, sem marca, sem contexto e sem caminho de volta, no meio de uma jornada que começou com "seu presente está disponível".

**Recomendação.** Extrair a casca pública para um layout Blade compartilhado (mesma estrutura, mesmos tokens, mesmo BrandMark) e fazer 403, 404, 419 e 500 herdarem dele, com a mesma tipografia e o mesmo botão do sistema. Padronizar min-h-dvh. E escrever as três mensagens que faltam no mesmo tom da entrada, com a saída sempre explícita (voltar para entrar / pedir novo link) — a tela de erro é o único ponto do produto onde o usuário está frustrado e a marca ainda pode consertar a impressão.

### 69. `info`, `focus` e `brand` são o mesmo hex com nomes diferentes — a troca vai copiar o problema para a paleta nova

**Categoria:** papel-mal-definido · **Dimensão:** Valores fora do token (o que quebra na troca)

**Arquivos:** `resources/css/app.css:41`, `resources/css/app.css:42`, `resources/css/app.css:44`, `resources/css/app.css:46`, `resources/css/app.css:65`, `resources/css/app.css:66`, `resources/css/app.css:67`, `resources/css/app.css:69`, `resources/js/Components/StatusBadge.vue:29`

Não é só `--color-info` == `--color-brand-700` (#4338ca). A família `info` INTEIRA é um apelido numérico da rampa brand: `--color-info-soft: #eef2ff` == `--color-brand-50` (app.css:66 e 41), `--color-info-line: #c7d2fe` == `--color-brand-200` (app.css:67 e 43), `--color-info: #4338ca` == `--color-brand-700` (app.css:65 e 46). E `--color-focus: #6366f1` == `--color-brand-500` (app.css:69 e 44). São QUATRO tokens que declaram valor próprio mas nunca tiveram decisão própria. O efeito se materializa em StatusBadge.vue:29-35: cinco dos sete estágios do pipeline (design, production, picking, packing, invoiced) mapeiam para a variante `info`, ou seja, para a cor da marca.

**Consequência.** Na troca, o comportamento default é copiar: a marca nova entra em brand-*, alguém replica o mesmo valor em info-* e focus, e cinco dos sete estágios do pipeline continuam pintados com a cor institucional — o operador segue sem conseguir distinguir Design de Packing de NF Emitida. O problema muda de matiz e permanece idêntico. Há ainda um risco de acessibilidade: se a base institucional profunda entrar também em `--color-focus`, o anel de foco (app.css:104) perde contraste contra `--color-ink` e o WCAG 2.4.11/2.4.13 quebra em toda a interface de uma vez.

**Recomendação.** Antes de receber qualquer matiz, quebrar o apelido por decisão explícita: (1) `info` deixa de ser a marca e passa a ser um informativo NEUTRO frio, distinto da tinta institucional — assim a marca volta a significar 'ação do produto' e não 'aviso'; (2) `--color-focus` vira um token independente cujo único requisito é contraste ≥3:1 contra canvas, surface E brand-600 simultaneamente, para sobreviver a qualquer paleta; (3) os cinco estágios `info` do pipeline saem da família semântica e passam a consumir uma rampa de PROGRESSO própria (ver achado da rampa de dados). Escrever no topo do @theme a regra de que dois tokens nunca podem compartilhar valor: se compartilham, um deles não existe.

### 70. Uma única tinta carrega oito papéis distintos — não há como destacar um sem destacar os outros

**Categoria:** papel-mal-definido · **Dimensão:** Valores fora do token (o que quebra na troca)

**Arquivos:** `resources/js/Components/PipelineChart.vue:32`, `resources/js/Pages/Portal/Redeem.vue:178`, `resources/js/Pages/Portal/Redeem.vue:179`, `resources/js/Components/UiFileDrop.vue:167`, `resources/js/Components/UiTimeline.vue:25`, `resources/js/Pages/Dashboard.vue:81`, `resources/js/Components/UiPagination.vue:133`, `resources/js/Layouts/AppLayout.vue:120`, `resources/js/Layouts/AppLayout.vue:181`, `resources/js/Layouts/AppLayout.vue:198`, `resources/js/Pages/Projects/Show.vue:695`, `resources/js/Pages/Projects/Show.vue:723`, `resources/js/Pages/Projects/Show.vue:761`, `resources/js/Components/UiButton.vue:23`

`bg-brand-600` / `text-brand-700` / `bg-brand-50` aparecem cumprindo oito funções que não têm relação entre si: ação primária (UiButton.vue:23), item de navegação ativo (AppLayout.vue:181), barra de progresso de upload (UiFileDrop.vue:167), passo do formulário do portal (Redeem.vue:178-179), série de dados do gráfico (PipelineChart.vue:32), marcador de evento mais recente (UiTimeline.vue:25), página atual da paginação (UiPagination.vue:133), avatar de iniciais de pessoa (AppLayout.vue:120 e 198) e numerador de passos (Projects/Show.vue:695, 723, 761). Nenhum desses papéis tem token próprio.

**Consequência.** É a raiz mecânica do 'tudo que se destaca se destaca em azul' e o motivo pelo qual a tela parece sem vida mesmo com a cor presente: quando oito coisas usam a mesma tinta, nenhuma delas é destaque. Na troca de paleta o efeito é literal — os oito mudam juntos, e continua impossível dar ênfase à ação primária sem que o avatar, a paginação e as barras do gráfico subam de intensidade no mesmo movimento. Pior no portal: a barra de progresso do resgate (Redeem.vue:178) tem exatamente o mesmo peso visual do avatar de operador na sidebar administrativa.

**Recomendação.** Separar por papel antes de escolher matiz: `--color-action` (ação primária — a única que a marca pinta), `--color-nav-active` (pode derivar da marca, mas em degrau baixo), `--color-progress` (progresso de tarefa: upload, passo do formulário), `--color-data-1..n` (série de gráfico), `--color-avatar-*` (identidade de pessoa — candidato a neutro, não a marca). Regra explícita: cor de MARCA só em ação primária e navegação ativa. Progresso, dado e identidade deixam de consumir a marca. Isso libera a marca para ser realmente notada quando aparece — hoje ela aparece tanto que não aparece.

### 71. O e-mail é a porta de entrada do portal e está inteiramente fora do sistema de tokens

**Categoria:** valor-fora-do-token · **Dimensão:** Valores fora do token (o que quebra na troca)

**Arquivos:** `resources/views/mail/account-invitation.blade.php:1`, `resources/views/mail/account-invitation.blade.php:7`, `resources/views/mail/templated.blade.php:1`, `resources/views/mail/templated.blade.php:5`

Os dois templates usam `<x-mail::message>` e `<x-mail::button>` do Laravel. Confirmei por Glob que NÃO existe `resources/views/vendor/mail` publicado e por grep que não há nenhuma configuração de tema em config/. Logo, vale o tema `default` do framework, cujo CSS traz cerca de 35 hex hardcoded — botão primário #18181b, botão success #16a34a, botão error #dc2626, fundo #fafafa, texto do corpo #52525b, bordas #e4e4e7, rodapé #a1a1aa. Nenhum desses valores tem qualquer relação com o @theme do projeto, e o botão primário do e-mail nem sequer é azul: é preto zinc.

**Consequência.** O colaborador que ganhou o presente chega ao portal POR ESTE E-MAIL (account-invitation.blade.php é o convite de conta, e a própria tela vazia do catálogo promete 'avisamos por e-mail' em Catalog.vue:44). O primeiro contato dele com a marca é um layout preto e cinza genérico do Laravel. Trocar a paleta do app não muda uma vírgula disso — a inconsistência não só sobrevive à migração, ela AUMENTA, porque hoje o azul indigo e o zinc do e-mail ao menos convivem como 'frios', e uma marca quente vai colidir de frente. É o gap mais caro do inventário porque é o momento mais emocional do produto e o único que ninguém vê ao revisar telas.

**Recomendação.** Publicar os componentes de e-mail (`vendor:publish --tag=laravel-mail`) e reescrever o `default.css` consumindo os mesmos papéis do @theme, com valores literais (e-mail não suporta custom property de forma confiável) gerados a partir de uma fonte única — idealmente um arquivo de tokens JSON que alimente tanto o @theme quanto o CSS do e-mail. Enquanto isso não existe, no mínimo alinhar botão, fundo e texto do e-mail aos mesmos três papéis do app (ação, superfície, tinta). Tratar o e-mail de convite como PRIMEIRA TELA do portal, não como infraestrutura.

### 72. Três valores de cor escritos à mão fora do @theme — incluindo um escondido em data-URI

**Categoria:** valor-fora-do-token · **Dimensão:** Valores fora do token (o que quebra na troca)

**Arquivos:** `resources/js/app.js:36`, `resources/js/Components/UiSelect.vue:72`, `resources/css/app.css:140`, `resources/css/app.css:141`

Este é o inventário COMPLETO de cor literal fora do bloco @theme, após varredura de todo resources/. (1) app.js:36 — `color: '#0f172a'` na barra de progresso do Inertia; é o slate-900 do Tailwind e não corresponde a token nenhum, nem sequer a `--color-ink` (#101828). (2) UiSelect.vue:72 — data-URI do chevron com `stroke='%23667085'`, que é `--color-ink-muted` copiado e URL-encoded dentro de um `<style scoped>`. (3) app.css:140-141 — o `.bg-mesh` com `rgb(99 102 241 / 0.10)` (= brand-500) e `rgb(129 140 248 / 0.10)` (= indigo-400, degrau que não existe na rampa).

**Consequência.** Cada um falha num lugar diferente e todos passam despercebidos numa revisão de telas. O #0f172a pisca no topo da tela a CADA navegação — é o elemento mais frequente da interface inteira e vai continuar slate depois da troca. O chevron aparece em todo `<select>` do sistema (UF no portal, filtros na operação) e vai ficar cinza-azulado quando os neutros esquentarem, num elemento pequeno e repetido que produz exatamente a sensação de 'tem algo errado e não sei o quê'. O `.bg-mesh` cobre as QUATRO telas de autenticação (via AuthCard.vue:9) mais o 403 (403.blade.php:10): a primeira tela que qualquer pessoa vê mantém um halo índigo por cima da paleta nova.

**Recomendação.** app.js:36 passa a ler o token em runtime (`getComputedStyle(document.documentElement).getPropertyValue('--color-action')`) ou, mais simples, o valor sai do JS e a barra é estilizada por CSS. UiSelect.vue:72 troca o data-URI por um `<UiIcon>` posicionado em absolute com `text-ink-muted`, ou por `background-image` com `mask` colorida por `background-color: var(--color-ink-muted)` — o data-URI deixa de conter cor. O `.bg-mesh` passa a referenciar tokens via `color-mix(in oklab, var(--color-brand-500) 10%, transparent)`, que é o mecanismo correto para 'um degrau que não existe' e elimina a necessidade de inventar o valor.

### 73. A rampa curta JÁ produziu o único rgb literal do CSS — a prova de que faltam degraus

**Categoria:** token-faltante · **Dimensão:** Valores fora do token (o que quebra na troca)

**Arquivos:** `resources/css/app.css:41`, `resources/css/app.css:44`, `resources/css/app.css:46`, `resources/css/app.css:141`

Grepei `brand-300|brand-400|brand-800|brand-900` em todo o resources/: zero ocorrências. À primeira vista a rampa curta não incomoda ninguém. Mas o `.bg-mesh` em app.css:141 precisou de um tom mais claro que brand-500 para o segundo halo e o resolveu escrevendo `rgb(129 140 248 / 0.10)` — que é exatamente o indigo-400, ou seja, o `--color-brand-400` que não foi declarado. O degrau ausente não gerou uso do degrau errado; gerou um valor fora do sistema.

**Consequência.** O padrão vai se repetir de forma muito mais agressiva na paleta nova. Uma base institucional profunda com acento quente precisa de degraus intermediários para fundo de hover, estado pressionado, texto sobre fundo soft e superfície de destaque suave do portal — nada disso existe hoje entre brand-200 e brand-500 (um salto enorme) nem acima de brand-700. Sem 300/400/800/900 declarados, cada necessidade nova vira um `/10`, um `/20` ou outro rgb literal, e a migração termina com mais deriva do que começou.

**Recomendação.** Fechar a rampa em 50/100/200/300/400/500/600/700/800/900 antes de receber a marca, ainda que alguns degraus nasçam sem uso — o custo de um token não usado é zero e o custo de um degrau ausente é um valor fora do sistema. Definir o papel de cada degrau na declaração (50 = fundo de item ativo; 100 = avatar/chip; 200 = borda de destaque; 600 = ação; 700 = hover e texto sobre soft; 800/900 = superfície institucional profunda, que a direção 'quente + confiável' vai exigir e hoje não tem onde morar).

### 74. O @theme não fecha a paleta: `bg-indigo-600` volta a compilar em silêncio no dia seguinte à troca

**Categoria:** oportunidade · **Dimensão:** Valores fora do token (o que quebra na troca)

**Arquivos:** `resources/css/app.css:7`, `resources/views/app.blade.php:22`

O bloco abre em `@theme {` (app.css:7) sem nenhum `--color-*: initial`. Confirmei na documentação do Tailwind v4 que `@theme` ESTENDE o tema padrão: declarar `--color-brand-600` não remove `bg-slate-500`, `border-indigo-600`, `from-blue-400` nem nenhuma das 22 famílias × 11 degraus da paleta de fábrica. Todas continuam válidas e compilam sem aviso. O comentário em app.blade.php:22-24 registra que a deriva já aconteceu uma vez — o body carregava `bg-slate-50 text-slate-900` e, por ser utilitário, vencia a regra de `@layer base`, de modo que o token de fundo da aplicação nunca chegava a valer.

**Consequência.** Hoje o markup está limpo, mas isso é resultado de disciplina humana, não de garantia técnica. Depois da troca de paleta o risco cresce muito: o repertório mental de quem escreve o código continua sendo o do Tailwind, e um `bg-indigo-50` digitado por hábito num card novo reintroduz literalmente a cor antiga do projeto, sem erro de build, sem alerta de lint e sem diferença perceptível numa revisão de PR. O comentário no blade prova que esse cenário exato já ocorreu e custou uma investigação.

**Recomendação.** Fechar a paleta com `--color-*: initial;` como primeira linha do @theme e redeclarar apenas os tokens do projeto (mais `transparent` e `current`, usados legitimamente em UiButton.vue:72, UiBadge.vue:33, UiTabs.vue:56 e UiTable.vue:178/183). A partir daí, qualquer cor de fábrica vira erro de compilação em vez de deriva silenciosa. É a única medida do inventário que converte governança em garantia, e o momento de fazê-la é exatamente na migração — depois, cada dia adiciona uma classe nova para auditar.

### 75. Cor decidida na página, com três vocabulários paralelos para a mesma ideia de estado

**Categoria:** papel-mal-definido · **Dimensão:** Valores fora do token (o que quebra na troca)

**Arquivos:** `resources/js/Pages/Dashboard.vue:78`, `resources/js/Pages/Portal/Redemptions.vue:56`, `resources/js/Pages/Portal/Redemptions.vue:65`, `resources/js/Pages/Portal/Redemptions.vue:74`, `resources/js/Pages/Portal/Redemptions.vue:81`, `resources/js/Pages/Portal/Redemptions.vue:88`, `resources/js/lib/format.js:108`, `resources/js/Components/StatusBadge.vue:27`, `resources/js/Components/UiBadge.vue:10`, `resources/js/Components/UiAlert.vue:14`

Existem três formas concorrentes de dizer 'este item está em tal estado'. (1) O jeito certo: StatusBadge.vue:27-64 mapeia status → PAPEL semântico ('info', 'success'), e UiBadge.vue:10-16 / UiAlert.vue:14-19 traduzem papel → classe dentro do componente. (2) Dashboard.vue:78-83 monta um objeto `tones` que devolve CLASSES prontas (`{ dot: 'bg-warning', value: 'text-warning' }`) direto da página. (3) Portal/Redemptions.vue:47-92 tem a função `portalState` que devolve `dot: 'bg-info'` e `text: 'text-success'` também como classes, e format.js:108-111 devolve um terceiro vocabulário — `tone: 'danger'|'warning'|'default'` — que cada consumidor traduz por conta própria.

**Consequência.** A cor deixa de ser decidida em um lugar. Se a migração introduzir uma família semântica nova, ajustar contraste de um estado ou trocar 'info' por um informativo neutro (conforme o primeiro achado), StatusBadge e os componentes Ui acompanham sozinhos, mas Dashboard.vue e Portal/Redemptions.vue ficam para trás em silêncio — e são justamente a primeira tela do operador e a tela de acompanhamento do colaborador. Também gera divergência já hoje: Portal/Redemptions.vue:56 e 65 pintam 'Em preparação' e 'A caminho' com `bg-info`, que é a cor da marca, enquanto o portal precisaria dessas duas fases como estados de expectativa, não como institucional.

**Recomendação.** Padronizar num vocabulário só — o de StatusBadge: página devolve PAPEL, componente devolve classe. `Dashboard.vue:78-83` passa a devolver `tone: 'warning'` e o componente de fila resolve; `portalState` devolve `tone` e um `<UiStatusDot>` traduz. Ganho concreto na migração: a lista de arquivos a auditar cai de 'todas as páginas' para 'os componentes Ui', que é uma dúzia de arquivos e já está correta.

### 76. A marca não é um componente: o "W" está copiado em 6 lugares, com 3 tamanhos e 2 raios

**Categoria:** valor-fora-do-token · **Dimensão:** Valores fora do token (o que quebra na troca)

**Arquivos:** `resources/js/Components/AuthCard.vue:12`, `resources/js/Layouts/AppLayout.vue:108`, `resources/js/Layouts/AppLayout.vue:144`, `resources/js/Layouts/AppLayout.vue:214`, `resources/js/Layouts/PortalLayout.vue:51`, `resources/views/errors/403.blade.php:13`, `resources/js/Layouts/AppLayout.vue:114`, `resources/js/Layouts/PortalLayout.vue:57`, `resources/views/app.blade.php:6`, `resources/js/app.js:10`

O quadrado com a letra W em `bg-brand-600 text-ink-inverse` aparece seis vezes, copiado e divergente: size-12/rounded-lg em AuthCard.vue:12 e 403.blade.php:13, size-9/rounded-md em AppLayout.vue:144, size-8/rounded-md em AppLayout.vue:108, AppLayout.vue:214 e PortalLayout.vue:51. Não existe componente de logotipo. O nome 'WeLog' também está escrito à mão em AppLayout.vue:114, 151, 220 e 229 e em PortalLayout.vue:57, enquanto app.blade.php:6 lê `config('app.name')` e app.js:10 lê `VITE_APP_NAME` — três fontes de verdade para a mesma string. Complementando: `public/favicon.ico` existe mas não é referenciado por nenhum `<link rel="icon">`, e não há `<meta name="theme-color">` nem `og:image` em app.blade.php.

**Consequência.** Este é o item que decide se a chegada da identidade da WeGift é uma tarde ou uma semana. Quando o logo real chegar, são seis edições de markup em quatro arquivos (um deles Blade, fora do alcance dos componentes Vue), mais cinco de texto, e nenhum dos seis pontos tem slot capaz de receber um SVG — todos assumem um caractere dentro de um quadrado colorido. A ausência de `theme-color` dói especificamente no portal: o colaborador abre o link no celular e a barra do navegador fica na cor default, quebrando a moldura da marca no exato momento em que ela deveria aparecer.

**Recomendação.** Criar `<AppLogo :size>` (Vue) e um partial Blade equivalente antes de a marca chegar, com os três tamanhos como variantes e o conteúdo em slot, para que a substituição do W por SVG seja uma edição única em cada linguagem. Padronizar o raio (hoje varia entre rounded-md e rounded-lg sem razão). Consolidar o nome numa fonte só, vinda das props do Inertia. Adicionar `<link rel="icon">` e `<meta name="theme-color" content>` em app.blade.php lendo o token de superfície — e considerar valores diferentes por superfície, já que operação e portal terão climas distintos.

### 77. O gancho para separar operação e portal já existe e nunca tocou em cor

**Categoria:** oportunidade · **Dimensão:** Valores fora do token (o que quebra na troca)

**Arquivos:** `resources/js/Components/PageHeader.vue:10`, `resources/js/Components/PageHeader.vue:15`, `resources/js/Components/PageHeader.vue:24`, `resources/js/Layouts/PortalLayout.vue:40`, `resources/js/Layouts/AppLayout.vue:95`, `resources/js/Pages/Portal/Catalog.vue:55`, `resources/js/Pages/Portal/Redeem.vue:170`

`PageHeader` já declara `tone: 'app' | 'portal'` (PageHeader.vue:10), computa `portal` (linha 15) e é chamado com `tone="portal"` em Catalog.vue:55, Redeem.vue:170 e Redemptions.vue:106. Mas o que o tone altera é exclusivamente espaçamento e tamanho de texto: as margens negativas e o sticky em PageHeader.vue:26-31, `min-h-12 text-base` versus `min-h-9 text-sm` na linha 42, e `text-base` versus `text-sm` no subtítulo (linha 54). Nenhuma decisão de COR passa por ele. E os dois layouts partem do mesmo fundo: `bg-canvas` em PortalLayout.vue:40 e em AppLayout.vue:95.

**Consequência.** É a razão estrutural de o portal parecer um formulário administrativo: as duas superfícies partem literalmente da mesma superfície, e a única distinção que o sistema sabe fazer entre elas é tipográfica e de espaçamento. Sem um escopo de tema, qualquer tentativa de dar clima ao portal vira classe avulsa nas páginas do portal — que é exatamente o tipo de deriva que este inventário está catalogando, só que criada de propósito.

**Recomendação.** Elevar `tone` de prop de um componente para ESCOPO DE TEMA: um `data-surface="portal"` no elemento raiz de PortalLayout.vue:40, e no CSS um bloco `[data-surface='portal'] { --color-canvas: …; --color-action: …; --spacing-control-md: … }` que remapeia um subconjunto pequeno e nomeado de tokens. A mesma base serve as duas necessidades opostas porque o portal só sobrescreve o que precisa — superfície mais quente, ação com o acento de celebração, densidade maior — enquanto herda toda a semântica, o foco e o contraste da operação. A operação não muda nada e não corre risco de regressão. Esse é o mecanismo que a especificação precisa definir, e ele custa um atributo e um bloco de CSS.

### 78. Não existe token para o momento de celebração — o botão que confirma o presente é o mesmo do "Salvar" administrativo

**Categoria:** token-faltante · **Dimensão:** Valores fora do token (o que quebra na troca)

**Arquivos:** `resources/js/Pages/Portal/Redeem.vue:336`, `resources/js/Pages/Portal/Catalog.vue:121`, `resources/js/Components/UiButton.vue:23`, `resources/js/Pages/Portal/Redemptions.vue:102`

A ação mais emocional do produto — 'Confirmar e finalizar' o resgate do presente (Redeem.vue:336) e 'Quero receber' (Catalog.vue:121) — usa `UiButton` na variante primary default, que é `bg-brand-600 text-ink-inverse hover:bg-brand-700` (UiButton.vue:23): exatamente o mesmo tratamento do botão que salva um cadastro de empresa na área administrativa. A confirmação do resgate em Redemptions.vue:102 usa `UiAlert variant="success"`, o mesmo componente e a mesma tinta de 'importação concluída'. Não há nenhum token de acento, celebração ou destaque no @theme (app.css:7-89) — a lista de famílias é brand, success, warning, danger, info e focus, todas funcionais.

**Consequência.** O sistema é tecnicamente incapaz de comemorar. Mesmo com a marca nova e uma paleta quente, se o único vocabulário disponível for 'primary' e 'success', o portal continua com a mesma temperatura da tela de importação de planilha — porque a diferença entre ganhar um presente e concluir um lote não existe em lugar nenhum do código. É a tradução exata de 'sem vida' no ponto onde o cliente sente mais.

**Recomendação.** Declarar uma família de acento com papel escrito e escopo fechado: `--color-accent`, `--color-accent-soft`, `--color-accent-line`, `--color-accent-ink`, usada em exatamente três lugares — a ação de resgate no portal (Catalog.vue:121, Redeem.vue:336), a confirmação de resgate (Redemptions.vue:102) e o estado de presente entregue. Documentar que é PROIBIDA na área operacional, para que o acento quente não vire ruído numa tela de 100 linhas. Adicionar a variante `accent` ao UiButton, para que a decisão continue morando no componente e não vire classe avulsa na página.

### 79. Opacidade sobre cor substituindo tokens que não existem

**Categoria:** token-faltante · **Dimensão:** Valores fora do token (o que quebra na troca)

**Arquivos:** `resources/js/Components/UiModal.vue:212`, `resources/js/Components/UiDrawer.vue:45`, `resources/js/Components/UiTable.vue:178`, `resources/js/Components/UiTable.vue:183`, `resources/js/Components/UiButton.vue:48`, `resources/js/Components/UiPagination.vue:127`, `resources/js/Components/UiFileDrop.vue:207`, `resources/js/Components/UiCheckbox.vue:16`, `resources/js/Pages/Portal/Redeem.vue:247`

Duas famílias. Primeira: `bg-ink/40` como véu de overlay em UiModal.vue:212 e UiDrawer.vue:45, e `from-ink/10 to-transparent` como sombra de rolagem horizontal em UiTable.vue:178 e 183 — quatro derivações do token de texto para papéis que não são texto, sem `--color-overlay` nem `--color-scroll-shadow` declarados. Segunda: `opacity-60` para estado desabilitado repetido em cinco arquivos (UiButton.vue:48, UiPagination.vue:127, UiFileDrop.vue:207, UiCheckbox.vue:16, Redeem.vue:247), sem nenhum token de opacidade.

**Consequência.** O véu é o de maior impacto e o mais fácil de esquecer: `ink/40` sobre um `--color-ink` quente produz um véu amarelado, e o clima de todo modal e drawer muda por consequência de uma decisão sobre texto — ninguém vai pensar em revisar o overlay ao trocar a tinta do parágrafo. O `opacity-60` é inócuo para a paleta, mas já é um problema de acessibilidade hoje: aplicado sobre `text-ink-muted` (que app.css:34 documenta como o PISO de contraste, 4,9:1), o resultado cai para cerca de 2,4:1 e reprova WCAG AA em cada controle desabilitado — inclusive no botão 'Tentar buscar de novo' do portal (Redeem.vue:247).

**Recomendação.** Declarar `--color-overlay` como token próprio (não derivado de ink) e `--color-scroll-shadow`, usando `color-mix()` se a derivação for desejada — a diferença é que a derivação passa a ser uma decisão registrada e revisável, não um efeito colateral. Para o desabilitado, substituir `opacity-60` por um par de tokens de cor (`--color-ink-disabled`, `--color-surface-disabled`) validados contra 4,5:1, o que resolve simultaneamente a duplicação em cinco arquivos e a reprovação de contraste.

### 80. Sombras carregam o hex do texto em número, fora de qualquer referência

**Categoria:** valor-fora-do-token · **Dimensão:** Valores fora do token (o que quebra na troca)

**Arquivos:** `resources/css/app.css:76`, `resources/css/app.css:77`

`--shadow-xs: 0 1px 2px 0 rgb(16 24 40 / 0.05)` e `--shadow-overlay: 0 12px 32px -8px rgb(16 24 40 / 0.16), 0 4px 8px -4px rgb(16 24 40 / 0.08)`. O `16 24 40` é `--color-ink` (#101828) transcrito em decimal na linha acima. É a única dependência do arquivo que o próprio arquivo não consegue expressar — se alguém trocar `--color-ink` na linha 33, nada indica que as linhas 76 e 77 precisam mudar junto.

**Consequência.** Impacto visual pequeno, risco de ficar para trás alto justamente por isso. Se a direção 'neutros levemente quentes' mudar `--color-ink` para uma tinta quente, toda a elevação do sistema — modal, drawer, menu, toast, botão secundário — continua projetando sombra fria azulada sobre superfícies quentes. É um daqueles defeitos que ninguém consegue nomear e todo mundo percebe como 'ainda parece o sistema antigo'.

**Recomendação.** Reescrever com referência ao token: `--shadow-xs: 0 1px 2px 0 color-mix(in oklab, var(--color-ink) 5%, transparent)` e equivalente para o overlay. Aproveitar a passagem para decidir se a sombra deve ser neutra ou levemente tingida com a base institucional — sombra colorida é o recurso mais barato para criar profundidade sem adicionar elemento, e serve especialmente o portal, onde há poucos elementos e muito espaço.

### 81. Botão primário reconstruído à mão em dois lugares que não veem o componente

**Categoria:** valor-fora-do-token · **Dimensão:** Valores fora do token (o que quebra na troca)

**Arquivos:** `resources/js/Pages/Projects/Show.vue:708`, `resources/views/errors/403.blade.php:25`

Projects/Show.vue:708 replica as classes do `UiButton variant="primary"` num `<a>` — `h-control-md inline-flex … rounded-md bg-brand-600 px-4 text-sm font-medium … text-ink-inverse transition-colors … hover:bg-brand-700`. O motivo está documentado nas linhas 704-705 e é legítimo: é download de arquivo, e `UiButton :href` renderiza `<Link>` do Inertia, que interceptaria o clique. 403.blade.php:25 faz o mesmo por outro motivo — é Blade, não tem acesso a componente Vue. As classes usam tokens, então nenhum dos dois quebra na troca de paleta.

**Consequência.** Não quebram na troca de cor, mas ficam para trás em qualquer evolução do TRATAMENTO da ação primária — se o botão ganhar sombra colorida, raio novo, altura diferente ou a variante de acento proposta neste inventário, esses dois viram os únicos botões antigos do sistema, sem nenhum aviso. E são visíveis: 'Baixar modelo (.xlsx)' é o primeiro passo do fluxo de importação, o caminho mais crítico do operador; 'Entrar' é a única saída da tela de acesso negado.

**Recomendação.** Adicionar ao `UiButton` uma prop `external` (ou `as="a"`) que renderize `<a>` nativo em vez de `<Link>`, eliminando a duplicação em Projects/Show.vue. Para o Blade, extrair as classes do botão primário para uma classe de componente em `@layer components` (ex.: `.btn-primary`) consumida pelos dois mundos — assim o Blade deixa de ser um território fora do sistema de design, o que também vai importar para os e-mails.

### 82. A fonte está declarada em dois arquivos que não se conhecem

**Categoria:** valor-fora-do-token · **Dimensão:** Valores fora do token (o que quebra na troca)

**Arquivos:** `resources/css/app.css:14`, `resources/views/app.blade.php:16`

`--font-sans: 'Inter', ui-sans-serif, system-ui, …` em app.css:14, e o arquivo em si carregado por `<link rel="stylesheet" href="…family=Inter:wght@400..700…">` em app.blade.php:16. O comentário nas linhas 8-13 do blade explica corretamente por que o link mora ali (evitar o encadeamento HTML → CSS → googleapis), mas a consequência é que o nome da família existe em dois lugares sem nenhuma ligação entre eles.

**Consequência.** Trocar a tipografia junto com a identidade da WeGift exige editar os dois arquivos, e esquecer o blade não produz erro: a `--font-sans` cai silenciosamente no fallback `ui-sans-serif, system-ui`. Numa equipe com Mac e Windows isso é especialmente traiçoeiro — o fallback é aceitável em um e visivelmente diferente no outro, então a falha passa na revisão de quem está no Mac e chega ao operador. Vale registrar também que a escolha do Inter é o mesmo sintoma do indigo-600: é a fonte que aparece quando ninguém escolheu, e ela é 100% do texto das duas superfícies, incluindo o portal.

**Recomendação.** Manter o `<link>` no blade por performance, mas amarrar os dois: gerar o href a partir da mesma constante que alimenta o token, ou no mínimo deixar um comentário recíproco em app.css:14 apontando app.blade.php:16 e vice-versa. Aproveitar a chegada da marca para tratar tipografia como parte da mesma decisão de identidade — com o portal e a operação podendo divergir em ESCALA e peso (o portal é lido uma vez, no celular, e suporta muito mais contraste tipográfico) mesmo compartilhando a família.

### 83. A escala tipográfica inteira cabe em 4px: 98% do texto está entre 12 e 16px

**Categoria:** ritmo-visual · **Dimensão:** Tipografia, espaço e ritmo

**Arquivos:** `resources/js/Components/PageHeader.vue:51`, `resources/js/Components/UiSectionHeading.vue:12`, `resources/js/Components/UiCard.vue:26`, `resources/js/Pages/Admin/Companies/Index.vue:228`, `resources/js/Components/UiTable.vue:132`, `resources/js/Components/UiTable.vue:144`, `resources/css/app.css:20`

Contei 219 ocorrências de classe de tamanho no app. A distribuição é: `text-xs` (12px) 82×, `text-sm` (14px) 105×, `text-base` (16px) 28× — 215 no total, 98%. Acima disso existem só 4: `text-lg` (18px) 3× e `text-2xl` (24px) 2×. O token `--text-stat` (28px, app.css:20) tem exatamente UM consumidor vivo (Dashboard.vue:121); o outro (UiStat.vue:27) está em componente que nenhuma página importa. A cadeia de hierarquia real é: h1 de página 24px/600 (PageHeader.vue:51) → h2 de seção 16px/600 (UiSectionHeading.vue:12) → h3 de cartão 14px/600 (UiCard.vue:26) → corpo 14px/400 (UiTable.vue:132). Os saltos são 1,5× / 1,14× / 1,17×. Em Admin/Companies/Index.vue o título do cartão da empresa (linha 228, 14px/600) e o nome do administrador dentro dele (linha 283, 14px/500) diferem por um degrau de peso no mesmo tamanho — na prática, o mesmo elemento visual.

**Consequência.** A razão entre o maior e o menor elemento do produto é 2,33:1 no Dashboard e 2:1 em todas as outras telas. Uma página com amplitude de 2× e 98% dos glifos numa faixa de 4px não tem ponto focal: o olho varre e não pousa. É exatamente a sensação que o cliente descreveu como 'sem vida' — e ela não muda um milímetro com paleta nova, porque não é problema de cor.

**Recomendação.** Definir uma escala de 3 patamares com salto real, e não uma rampa contínua: (a) DISPLAY — reservado ao ponto focal de cada tela, mínimo 2× o corpo (hoje o teto de 24px é insuficiente para 14px de corpo); (b) ESTRUTURA — título de seção, que hoje é 16px e precisa se descolar dos 14px do corpo por tamanho, não só por peso; (c) CORPO/APOIO — 14/12px, como está. Criar `--text-display`, `--text-section` como tokens em app.css junto de `--text-stat`, e definir por escrito quais nós consomem cada um (hoje `--text-stat` existe e é usado uma vez — a camada de display do sistema já existe e está ociosa).

### 84. Só três pesos circulam, e o mais forte (700) existe apenas no quadrado placeholder do logo

**Categoria:** ritmo-visual · **Dimensão:** Tipografia, espaço e ritmo

**Arquivos:** `resources/views/app.blade.php:16`, `resources/js/Components/AuthCard.vue:14`, `resources/js/Layouts/AppLayout.vue:110`, `resources/js/Layouts/AppLayout.vue:146`, `resources/js/Layouts/AppLayout.vue:216`, `resources/js/Layouts/PortalLayout.vue:53`

`font-bold` (700) aparece 5 vezes no repositório e todas as 5 são o quadrado com a letra 'W' que substitui o logo. Nenhum texto de conteúdo no produto passa de `font-semibold` (600), que tem 44 ocorrências; `font-medium` (500) tem 58. Ou seja: o intervalo de peso que carrega TODO o conteúdo é 400→600, e o mesmo 600 faz o h1 da página, o h2 de seção, o h3 de cartão, o cabeçalho de coluna de tabela, o micro-rótulo maiúsculo e o número grande do Dashboard. Além disso, app.blade.php:16 pede `Inter:wght@400..700` — o arquivo variável carregado não contém 800 nem 900, então qualquer tentativa futura de usar peso extremo produziria negrito sintético (faux bold).

**Consequência.** Peso deixou de ser um sinal: como 600 marca sete papéis diferentes, ver 600 não informa nada sobre o que aquilo é. E a requisição de fonte trava o teto em 700 — a alavanca mais barata de hierarquia (extremos de peso) está fechada no nível do `<link>`, não do CSS.

**Recomendação.** Estabelecer que peso é o eixo SECUNDÁRIO de hierarquia (o primário é tamanho) e limitar 600 a dois papéis, não sete. Ao trocar a fonte pela da marca, pedir a faixa completa que a família oferecer (ex.: `wght@400..800`) e reservar o degrau mais pesado exclusivamente para o nó de display de cada tela. Se a marca vier com uma família de peso único, a alavanca passa a ser tamanho + caixa, e isso precisa estar escrito antes de alguém tentar `font-black` num arquivo que não tem.

### 85. O micro-rótulo maiúsculo é literalmente o mesmo no cabeçalho de tabela e na apresentação do presente

**Categoria:** papel-mal-definido · **Dimensão:** Tipografia, espaço e ritmo

**Arquivos:** `resources/js/Components/UiTable.vue:144`, `resources/js/Layouts/AppLayout.vue:162`, `resources/js/Components/UiRowErrors.vue:39`, `resources/js/Pages/Portal/Catalog.vue:90`, `resources/js/Pages/Portal/Redeem.vue:311`, `resources/js/Pages/Portal/Redeem.vue:318`, `resources/js/Pages/Portal/Redemptions.vue:129`, `resources/js/Pages/Portal/Redemptions.vue:155`

A combinação `text-xs font-semibold tracking-wider text-ink-muted uppercase` aparece 15 vezes. Ela rotula: o cabeçalho de coluna de toda tabela (UiTable.vue:144), o grupo da sidebar 'OPERAÇÃO'/'ADMINISTRAÇÃO' (AppLayout.vue:162, 236), a tabela de linhas recusadas na importação em lote (UiRowErrors.vue:39,45) — e também 'O QUE VEM DENTRO' no cartão do presente (Catalog.vue:90), 'PRESENTE' e 'VAI PARA' na tela de conferência do resgate (Redeem.vue:311,318), 'CÓDIGO DE RASTREIO' e 'VAI PARA' na tela de entregas (Redemptions.vue:129,155).

**Consequência.** O dispositivo tipográfico que significa 'isto é uma coluna de planilha' é o mesmo que apresenta o conteúdo do presente de alguém. Esta é a prova mais direta e mais específica de que o portal está pintado como formulário administrativo — e é um problema de tipografia, não de cor: trocar a paleta não muda o fato de que a apresentação do presente e o cabeçalho da tabela de erros de importação usam a mesma receita.

**Recomendação.** Separar em dois papéis com nomes distintos: (a) RÓTULO DE DADO — maiúsculas, 12px, tracking aberto, para cabeçalho de tabela, grupo de navegação e rótulo de metadado da área operacional; (b) INTRODUÇÃO DE CONTEÚDO — no portal, caixa normal, tamanho maior que o corpo, sem tracking forçado, para 'O que vem dentro', 'Vai para', 'Código de rastreio'. Regra verificável: `uppercase` fica PROIBIDO dentro de resources/js/Pages/Portal/.

### 86. Não existe `--font-display`: a estrutura que receberia a fonte da marca não está montada

**Categoria:** token-faltante · **Dimensão:** Tipografia, espaço e ritmo

**Arquivos:** `resources/css/app.css:14`, `resources/views/app.blade.php:16`, `resources/js/Components/PageHeader.vue:51`, `resources/js/Components/AuthCard.vue:18`, `resources/js/Pages/Portal/Catalog.vue:84`, `resources/js/Pages/Portal/Redemptions.vue:117`, `resources/js/Pages/Dashboard.vue:121`

Existe `--font-sans` (Inter) e `--font-mono` (app.css:14 e 16). Não existe uma terceira família nem um token que a represente. Inter é competente e é a fonte de todo dashboard SaaS desde 2020 — o problema não é Inter na área operacional, é Inter fazendo tudo. Mapeei os nós onde um par tipográfico mudaria a PERCEPÇÃO (são poucos e todos identificáveis): AuthCard.vue:18 (o h1 de 'Entrar' — o primeiro frame do produto, antes de qualquer dado), PageHeader.vue:51 quando `tone="portal"` (a saudação 'Olá, {nome}' de Catalog.vue:55 e 'Onde você quer receber?' de Redeem.vue:171 — as três frases do produto inteiro em que o tom é humano), Catalog.vue:84 e Redemptions.vue:117 (o nome do presente, hoje 18px) e Dashboard.vue:121 (o número da fila, o único ponto focal legítimo da área operacional).

**Consequência.** Hoje não há nenhum lugar preparado para receber a fonte da marca: ela chegaria como uma troca de `--font-sans`, aplicando-se a 100% do texto, inclusive a células de tabela lidas 8 horas por dia — que é o pior lugar possível para uma face de display. E o inverso também é verdade: sem o token e sem a lista de nós, a decisão de onde a marca aparece vira improviso por arquivo.

**Recomendação.** Criar `--font-display` em app.css hoje, apontando para o mesmo valor de `--font-sans` (troca visual zero, risco zero) e consumi-lo APENAS nos 5 nós listados acima, via uma prop/variante em PageHeader e classes nomeadas nos dois títulos do portal. Escrever a regra de contenção junto: display é proibido abaixo de 18px e proibido dentro de UiTable, UiField, UiBadge, UiButton, UiDescriptionList e da sidebar — nesses lugares seria enfeite e custaria legibilidade em varredura. Quando a fonte da WeGift chegar, a troca é uma linha.

### 87. Nenhum valor de espaço vertical acima de 32px existe no produto, e o único que existe aparece uma vez

**Categoria:** token-faltante · **Dimensão:** Tipografia, espaço e ritmo

**Arquivos:** `resources/js/Components/UiSectionHeading.vue:10`, `resources/js/Pages/Dashboard.vue:107`, `resources/js/Pages/Dashboard.vue:148`, `resources/js/Pages/Dashboard.vue:153`, `resources/js/Pages/Dashboard.vue:194`, `resources/css/app.css:82`

Contei 296 utilitários de espaçamento no app. O maior valor vertical em uso é `mt-8` (32px) e ele tem UMA ocorrência: UiSectionHeading.vue:10 (`mt-8 mb-3`). Fora dele há `py-12` uma vez (UiEmpty.vue:12), `py-10` uma vez (AuthCard.vue:9) e `pb-10` duas (o fundo dos `<main>`). Todo o resto — 290 ocorrências — está entre 2px e 24px. `@theme` (app.css:79-85) define tokens de espaço apenas para ALTURA DE CONTROLE (`--spacing-control-sm/md/lg`) e largura da sidebar. Não existe nenhum token de ritmo vertical.

**Consequência.** No Dashboard, os cinco blocos da tela (fila 'Precisa de você', gráfico de pipeline, tabela de projetos, nota de rodapé, tabela de importações) são separados pelo MESMO `mt-8 mb-3` — linhas 107, 148, 153 e 194 chamam o mesmo componente. A distância entre 'Precisa de você' (o único bloco acionável) e 'Projetos por estágio' é idêntica à distância entre duas tabelas de consulta. Agrupamento por proximidade é relativo, não absoluto: quando toda distância entre blocos é igual, nenhum agrupamento é percebido, e a página lê como cinco coisas equivalentes. Nada diz 'esta primeiro'.

**Recomendação.** Criar dois tokens de ritmo com razão mínima de 2:1 — um espaço de SEÇÃO (abre assunto novo) e um espaço de BLOCO (continua o mesmo assunto) — e dar a UiSectionHeading uma variante que escolha entre os dois. Regra verificável: numa mesma tela, dois blocos que o operador lê em sequência para tomar UMA decisão usam espaço de bloco; blocos que respondem a perguntas diferentes usam espaço de seção. Isso não custa nada em pixels de rolagem no Dashboard (são 4 separadores) e é o que faz a fila subir para o primeiro plano.

### 88. O portal e a área operacional têm o mesmo ritmo vertical, o mesmo raio e o mesmo tamanho de título

**Categoria:** papel-mal-definido · **Dimensão:** Tipografia, espaço e ritmo

**Arquivos:** `resources/js/Layouts/PortalLayout.vue:92`, `resources/js/Layouts/AppLayout.vue:223`, `resources/js/Components/PageHeader.vue:51`, `resources/js/Pages/Portal/Catalog.vue:83`, `resources/js/Pages/Portal/Redemptions.vue:114`, `resources/js/Pages/Auth/Login.vue:54`, `resources/js/Components/UiCard.vue:11`

As diferenças que EXISTEM são reais mas discretas: largura do `<main>` 672px no portal (PortalLayout.vue:92, `max-w-2xl`) contra 1152px na operação (AppLayout.vue:223, `max-w-6xl`); corpo 16px contra 14px; altura de controle 48px contra 40px (app.css:83-84); padding de cartão 20px (`p-5`, Catalog.vue:83 e Redemptions.vue:116) contra 16px (`p-4`, UiCard.vue:11). O que é IDÊNTICO: o raio (`rounded-lg` nos dois), a borda (1px `border-line` nos dois), o micro-rótulo maiúsculo, e — o mais grave — o título da tela. PageHeader.vue:51 aplica `text-2xl font-semibold tracking-tight` independentemente da prop `tone`: a prop só altera o wrapper de fundo (linhas 26-31), o tamanho do subtítulo (linha 54) e a altura do link de voltar (linha 42). 'Olá, Madina' e 'Empresas cliente' são desenhados com exatamente a mesma tipografia. E o ritmo entre blocos do portal é `space-y-4`/16px (Redemptions.vue:114, Redeem.vue:308) — o mesmo valor que separa dois campos de um formulário administrativo (Login.vue:54, Companies/Index.vue:336).

**Consequência.** As duas superfícies têm necessidades opostas e recebem a mesma forma. A diferença de 4px de padding e 2px de corpo não é percebida como 'outra coisa' — é percebida como a mesma tela um pouco mais larga. Nenhuma paleta resolve isso: com cor quente e o mesmo raio, o mesmo título de 24px e o mesmo ritmo de 16px, o portal continua sendo um formulário administrativo, só que colorido.

**Recomendação.** Fazer a distinção acontecer em três eixos que a operação NÃO usa, para que a diferença seja categórica e não gradual: (1) o título do portal precisa quebrar o teto de 24px da área operacional — hoje o portal não tem nenhum tamanho que a operação não tenha; (2) um raio de portal maior que `rounded-lg`, expresso como token, para que a forma leia 'macia' contra a forma 'quadrada' da operação; (3) um degrau de padding de cartão (24-28px) que não exista na escala operacional. Dar a PageHeader uma variante de título de verdade, não só de subtítulo. Regra de contenção: nada disso pode vazar para AppLayout, Dashboard, Projects, Redemptions, Imports, Products, Benefits ou Admin — lá o ganho é calma, não generosidade.

### 89. Raio e sombra estão escritos como classe utilitária em 87 lugares, sem nenhum token

**Categoria:** valor-fora-do-token · **Dimensão:** Tipografia, espaço e ritmo

**Arquivos:** `resources/css/app.css:76`, `resources/css/app.css:77`, `resources/js/Components/UiCard.vue:14`, `resources/js/Components/UiPanel.vue:16`, `resources/js/Components/UiButton.vue:44`, `resources/js/Components/UiTable.vue:122`, `resources/js/Components/UiInput.vue:63`, `resources/js/Components/UiBadge.vue:24`

O inventário de forma é consistente e minúsculo: 73 ocorrências de `rounded-*` (praticamente só `rounded-lg` para contêiner, `rounded-md` para controle, `rounded-full` para ponto/pílula, e `rounded-sm` duas vezes em UiCode.vue:42 e Imports/Index.vue:170) e 14 de sombra (`shadow-xs` em controle com borda, `shadow-overlay` nos quatro elementos que flutuam). A regra de app.css:73-74 ('sombra só em coisa que FLUTUA') é seguida à risca. Traço: `border` 1px em tudo; as únicas linhas de 2px do produto são o sublinhado da aba ativa (UiTabs.vue:54), o anel de foco (app.css:104) e o spinner (UiButton.vue:72). Ícone: `stroke-width="1.7"` fixo em UiIcon.vue:28. Mas nada disso é token: `--shadow-xs` e `--shadow-overlay` existem (app.css:76-77) e são consumidos por classe Tailwind, enquanto o raio não tem token nenhum — `rounded-lg` está literal em 73 arquivos.

**Consequência.** Duas coisas. Primeira: dois raios, duas sombras, uma espessura de traço, um peso de ícone é exatamente a assinatura de qualquer template Tailwind desde 2021 — a geometria não contém nenhuma decisão que alguém perceberia como 'da WeGift'. Segunda, e mais cara: se a identidade da marca trouxer uma linguagem de forma (mais macia, mais quadrada, mais afiada), a mudança exige 73 edições espalhadas em vez de 3 linhas em app.css.

**Recomendação.** Criar `--radius-control`, `--radius-container` e `--radius-pill` em @theme hoje, resolvendo para os valores atuais (0.375rem / 0.5rem / 9999px) — troca visual zero — e migrar os componentes-base (UiButton, UiInput, UiSelect, UiTextarea, UiCard, UiPanel, UiTable, UiBadge, UiAlert, UiMenu, Toaster, UiModal, UiDrawer) para consumi-los. Adicionar o quarto token de raio do portal previsto no achado anterior. Depois disso a linguagem de forma da marca entra por 4 linhas.

### 90. As sombras são derivadas do cinza-azulado do texto, não da marca

**Categoria:** valor-fora-do-token · **Dimensão:** Tipografia, espaço e ritmo

**Arquivos:** `resources/css/app.css:76`, `resources/css/app.css:77`, `resources/js/Components/UiDrawer.vue:45`, `resources/js/Components/UiModal.vue:212`

`--shadow-xs` e `--shadow-overlay` (app.css:76-77) são construídos com `rgb(16 24 40 / …)` — o valor literal de `--color-ink` (#101828), um cinza frio azulado. O véu do modal e do drawer usa `bg-ink/40` (UiModal.vue:212, UiDrawer.vue:45), a mesma tinta fria.

**Consequência.** É o item que quebra de forma visível na troca para uma paleta quente. Sombra e véu cinza-azulados sobre superfícies e neutros quentes produzem uma sujeira acinzentada nas bordas — o efeito de 'foto desbotada' que faz uma paleta quente parecer barata. E como o valor está escrito por extenso dentro da definição da sombra, não há um ponto único onde corrigir.

**Recomendação.** Extrair a cor da sombra para um token próprio (papel: 'tinta de profundidade'), derivado da base institucional profunda da marca e não de `--color-ink`, e fazer `--shadow-xs`, `--shadow-overlay` e o véu de overlay consumirem esse token. Assim a sombra escurece na mesma temperatura em que a paleta esquenta, com uma linha de mudança.

### 91. Zero feedback de pressão e zero animação de entrada: a Fase 10 removeu as microinterações e não repôs nada

**Categoria:** ritmo-visual · **Dimensão:** Tipografia, espaço e ritmo

**Arquivos:** `resources/js/Components/UiButton.vue:44`, `resources/css/app.css:136`, `resources/css/app.css:154`, `resources/js/Pages/Portal/Catalog.vue:121`, `resources/js/Pages/Portal/Redeem.vue:336`, `resources/js/Pages/Dashboard.vue:116`, `docs/PLANO.md:68`

Inventário completo de movimento: quatro keyframes, todos em app.css:154-179 e todos presos a overlay ou carregamento (`toast-in` 0,28s, `overlay-in` 0,15s, `drawer-in` 0,18s, `skeleton-pulse` 1,6s); `transition-colors` 32×, que é a ÚNICA transição que toca conteúdo; `transition-[width]` 2× (PipelineChart.vue:32, UiFileDrop.vue:167); `transition-transform` 3× em chevrons de `<details>`; `animate-spin` 2×. Buscas por `active:`, `hover:shadow`, `hover:-translate`, `hover:scale` e `group-hover` retornam ZERO ocorrências no repositório inteiro. PLANO.md:68 registra que a Fase 5.4 entregou 'fade-in por página, hover-lift em cards, active-scale em botões' — a Fase 10 removeu tudo (o comentário em app.css:136-137 diz explicitamente 'Única camada decorativa que sobrevive: .bg-dotted e o blob com blur saíram') e não repôs o feedback junto com a decoração. A prop `interactive` de UiCard (UiCard.vue:8,15) é código morto: nenhuma página a usa.

**Consequência.** Toda navegação do produto é um corte seco: a página aparece pronta, sem transição. E apertar qualquer botão não produz nenhuma mudança de estado — `transition-colors` cobre hover, não pressão. No celular, onde não existe hover, as duas ações mais importantes do produto ('Quero receber' em Catalog.vue:121 e 'Confirmar e finalizar' em Redeem.vue:336) não dão nenhum sinal de que o toque acertou o alvo. É a definição literal de 'seco'.

**Recomendação.** Repor exatamente três movimentos, nenhum decorativo: (1) feedback de pressão em UiButton.vue:44 — uma redução de escala de ~100ms, que é o laço de retorno completo do toque no mobile; (2) entrada escalonada de conteúdo APENAS no portal (Catalog.vue:64-129 e Redemptions.vue:114-160) — é a única tela do produto em que a chegada do conteúdo É o conteúdo, a diferença entre 'uma página carregou' e 'algo foi apresentado a você'; (3) elevação em hover nas linhas da fila do Dashboard (Dashboard.vue:110-145), que são links cujo propósito inteiro é serem clicados. Explicitamente NÃO: nenhuma transição de página na área operacional (um operador que navega 200×/dia paga 200× o atraso por nada), nada animado em tabela, nada de número contando. Estender o bloco `prefers-reduced-motion` de app.css:181-186 para cobrir os novos keyframes.

### 92. O produto inteiro renderiza um único `<img>`, condicional, e não existe nenhum asset gráfico

**Categoria:** oportunidade · **Dimensão:** Tipografia, espaço e ritmo

**Arquivos:** `resources/js/Pages/Portal/Catalog.vue:70`, `resources/js/Pages/Portal/Catalog.vue:79`, `resources/js/Pages/Portal/Catalog.vue:64`, `public/favicon.ico`

Varri `public/` e `resources/`: o único arquivo de imagem do repositório é `public/favicon.ico`. O único `<img>` do código-fonte é Catalog.vue:70, e ele é condicional (`v-if="gift.product.image_url"`). Os únicos outros bytes gráficos do produto são os paths de ícone 24×24 em lib/icons.js e um chevron em data-URI dentro de UiSelect.vue:72. Pior: mesmo essa única foto nunca chega a ser o maior elemento da tela — com dois ou mais presentes, `sm:grid-cols-2` (Catalog.vue:64) reduz cada cartão a ~328px e a foto 4:3 a uma miniatura de ~246px; com um presente só, `twoColumn` (linha 49) transforma a foto em painel lateral. Nos dois caminhos a imagem é papel de apoio.

**Consequência.** A tela mais emocional do produto tem exatamente um ativo visual, e o layout garante que ele nunca seja o protagonista. Numa tela sobre receber um presente, o que domina o campo visual são bordas de 1px, micro-rótulos maiúsculos e uma lista com marcadores.

**Recomendação.** Deixar a foto do presente ser o maior elemento da primeira dobra do portal quando há um presente só — que é o caso esmagadoramente majoritário. Concretamente: com um presente, a foto ocupa a largura total antes do conteúdo (o layout `twoColumn` de Catalog.vue:49 otimiza para desktop e penaliza o celular, que é onde a pessoa está). Nada disso depende da marca e nada disso é decoração: a foto é o dado mais importante da tela.

### 93. O estado vazio do portal é uma caixa tracejada — a linguagem visual de 'tabela sem registros'

**Categoria:** papel-mal-definido · **Dimensão:** Tipografia, espaço e ritmo

**Arquivos:** `resources/js/Components/UiEmpty.vue:12`, `resources/js/Pages/Portal/Catalog.vue:57`, `resources/js/Pages/Portal/Redemptions.vue:108`, `resources/js/Pages/Dashboard.vue:182`

UiEmpty.vue:12 tem UMA aparência fixa: `border border-dashed border-line`, um círculo cinza de 40px com ícone de 20px, título 14px/600 e mensagem 14px. Catalog.vue:57-62 chama esse mesmo componente para o estado 'ainda não há presente liberado para você', com a mesma marcação que Dashboard.vue:182 usa para 'nenhum projeto ainda' e Companies/Index.vue:219 para 'nenhuma empresa cadastrada'.

**Consequência.** É a primeira impressão da marca para uma pessoa que acabou de receber um e-mail dizendo que ganhou um presente e clicou no link antes da liberação. Ela encontra a moldura tracejada que significa 'consulta sem resultados' num sistema de banco de dados. É o ponto do produto onde a distância entre a expectativa e a tela é máxima — e é 100% independente de cor.

**Recomendação.** Dar a UiEmpty uma dimensão de variante (operacional / portal) em vez de uma aparência única. Na operação, manter exatamente o que está: o operador vê esse estado uma vez e ele não deve custar carregamento. No portal, o estado vazio precisa de espaço generoso, texto em tamanho de leitura e um lugar reservado para a ilustração da marca — hoje não existe nem o slot. A borda tracejada fica proibida em resources/js/Pages/Portal/.

### 94. O logo placeholder está copiado em 5 lugares, com 3 tamanhos e 2 raios diferentes

**Categoria:** oportunidade · **Dimensão:** Tipografia, espaço e ritmo

**Arquivos:** `resources/js/Components/AuthCard.vue:14`, `resources/js/Layouts/AppLayout.vue:110`, `resources/js/Layouts/AppLayout.vue:146`, `resources/js/Layouts/AppLayout.vue:216`, `resources/js/Layouts/PortalLayout.vue:53`

O mesmo bloco — um quadrado `bg-brand-600` com a letra 'W' em `font-bold text-ink-inverse` — está escrito por extenso cinco vezes, com `size-12`/`rounded-lg`/`text-lg` em AuthCard.vue:14, `size-9`/`rounded-md`/`text-sm` em AppLayout.vue:146 e `size-8`/`rounded-md`/`text-sm` nos outros três. Não existe componente de marca. O texto 'WeLog' ao lado dele também está repetido em 4 lugares (AppLayout.vue:114,151,220; PortalLayout.vue:57) com a mesma classe.

**Consequência.** Quando o logo oficial da WeGift chegar, ele entra por cinco edições em três arquivos, com três dimensões a reconciliar e a decisão de proporção tomada em cada lugar separadamente. E o quadrado só existe porque não havia logo: ele é a marca fazendo o papel de decisão de marca, exatamente como o `--color-brand-600` herdado do indigo padrão do Tailwind.

**Recomendação.** Extrair um componente único de marca com uma prop de tamanho (as três alturas em uso são 32/36/48px, e elas já correspondem à escala de controle de app.css:82-84 — vale alinhá-las a esses tokens). Ele renderiza hoje o mesmo quadrado com a letra; quando o SVG oficial chegar, a substituição é um arquivo. Prever também o caso da marca horizontal (símbolo + logotipo) para a sidebar e o cabeçalho, que hoje montam símbolo e texto manualmente.

### 95. O `tracking-tight` do h1 mata silenciosamente a regra de @layer base, e há OpenType específico de Inter no body

**Categoria:** valor-fora-do-token · **Dimensão:** Tipografia, espaço e ritmo

**Arquivos:** `resources/css/app.css:119`, `resources/css/app.css:120`, `resources/css/app.css:130`, `resources/js/Components/PageHeader.vue:51`, `resources/js/Components/AuthCard.vue:18`

app.css:130-132 define `letter-spacing: -0.02em` para h1, h2 e h3 na camada base. Mas PageHeader.vue:51 e AuthCard.vue:18 escrevem `tracking-tight` na classe do h1, que no Tailwind é `-0.025em` e vence a camada base — os únicos dois h1 do produto nunca usam o valor que a base define. Existem, portanto, duas decisões de tracking concorrentes para o mesmo elemento, e a que está no arquivo de tokens é a que perde. Além disso, app.css:119 fixa `font-feature-settings: 'cv11', 'ss01'` no body — features OpenType específicas do arquivo da Inter.

**Consequência.** Duas armadilhas para a troca de fonte. O tracking do título fica fora do alcance de quem editar app.css: ajustar a base não muda nada e a causa não é óbvia. E `font-feature-settings` amarrado a uma face específica: com a fonte da marca no lugar, esses dois nomes ou não existem (silenciosamente inertes) ou acionam alternativas completamente diferentes na nova família — em ambos os casos, o resultado tipográfico muda sem que ninguém tenha pedido.

**Recomendação.** Tirar `tracking-tight` das duas chamadas de h1 e deixar o tracking morar só em @layer base (ou, melhor, virar token de tracking por patamar de tamanho, já que títulos grandes precisam de tracking mais fechado que corpo — hoje a distinção existe mas está espalhada). Mover `font-feature-settings` para uma variável ligada à família ativa, para que a troca de fonte carregue junto suas próprias features em vez de herdar as da Inter.

### 96. O rótulo de campo não se distingue do valor: 14px/500 contra 14px/400

**Categoria:** ritmo-visual · **Dimensão:** Tipografia, espaço e ritmo

**Arquivos:** `resources/js/Components/UiField.vue:61`, `resources/js/Components/UiField.vue:69`, `resources/js/Components/UiInput.vue:26`, `resources/js/Pages/Portal/Redeem.vue:187`, `resources/js/Components/CompanyForm.vue:1`

UiField.vue:61 desenha o rótulo como `text-sm font-medium text-ink` — 14px, peso 500, tinta primária. O texto digitado dentro do controle é 14px peso 400 e tinta primária (UiInput.vue:26, tamanho md). São um degrau de peso de diferença, no mesmo tamanho e na mesma cor. A dica (linha 69) e o erro (linha 73) caem para 12px, então a distância entre rótulo e apoio é maior do que entre rótulo e valor. No formulário de resgate (Redeem.vue:187-296) isso se repete por 8 campos; no cadastro de empresa (CompanyForm.vue), por 13.

**Consequência.** Em formulário longo, a coluna de rótulos não lê como coluna: rótulo e valor se fundem numa massa de 14px, o que obriga a leitura sequencial em vez de varredura. É um dos motivos pelos quais essas telas parecem 'densas e paradas' mesmo tendo espaçamento correto entre campos (16px entre campos contra 6px entre rótulo e controle — a proximidade está certa).

**Recomendação.** Diferenciar rótulo e valor por um eixo que não seja peso: o rótulo desce um degrau de tamanho e assume a tinta secundária, deixando o valor sozinho no tamanho e na tinta primária. Isso inverte a hierarquia para o correto (o dado é mais importante que o nome do dado) e não custa espaço. Vale igual nos dois lados do produto, com tamanhos diferentes — no portal os controles já são 48px e o corpo 16px, então o rótulo tem folga para descer.

### 97. A camada de display do design system existe e está morta: UiStat nunca é importado

**Categoria:** oportunidade · **Dimensão:** Tipografia, espaço e ritmo

**Arquivos:** `resources/js/Components/UiStat.vue:27`, `resources/css/app.css:20`, `resources/js/Pages/Dashboard.vue:121`, `resources/js/Components/UiCard.vue:8`

`--text-stat` (1.75rem / 28px, app.css:20) é o maior tamanho do sistema. Tem dois consumidores no código: UiStat.vue:27 e Dashboard.vue:121. Uma busca por 'UiStat' em todo resources/js não retorna nenhuma importação — o componente é código morto, e o token de display do sistema tem exatamente UM uso vivo em todo o produto. O mesmo vale para a prop `interactive` de UiCard (UiCard.vue:8): definida, implementada na linha 15, jamais usada.

**Consequência.** O sistema já reconheceu que precisa de um patamar de display e construiu a peça — mas nenhuma tela a usa. É a prova de que a ausência de ponto focal não vem de falta de ferramenta, e sim de falta de decisão sobre qual é o elemento mais importante de cada tela. Toda tela de lista (Projects/Index, Redemptions/Index, Products/Index, Companies/Index, Users/Index) tem o mesmo maior elemento: o h1 de 24px, que é o nome da própria tela — a informação menos útil que ela contém.

**Recomendação.** Adotar a regra 'uma tela, um protagonista' e escrevê-la na especificação: cada tela nomeia UM elemento que recebe o patamar de display, e ele nunca é o h1 quando existe algo mais informativo. No Dashboard já é o número da fila (correto). Em Projects/Show deveria ser o estágio ou a situação de liberação, não o nome do projeto. No portal, é o presente. Ou UiStat ganha uso real, ou é removido — um componente órfão no sistema convida à divergência quando a próxima pessoa precisar de um número grande.

