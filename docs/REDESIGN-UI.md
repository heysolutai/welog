# REDESIGN-UI — Especificação de redesenho da interface WeLog

> **Status:** especificação executável. Consolida cinco auditorias independentes
> (navegação, operação, portal, administração, design system) em uma decisão única.
> **Público:** agentes de implementação que não viram as auditorias.
> **Escopo:** interface. Backend, rotas, nomes de rota e regras de negócio ficam como estão.

---

## 0. Como usar este documento

1. Leia a **§1 Princípios** e a **§2 Restrições**. São a régua de aceitação de todo PR.
2. Antes de tocar em qualquer tela, o componente que ela usa precisa existir na versão
   descrita em **§6 Inventário de componentes**. A ordem está em **§12**.
3. Ao implementar uma tela, use **§10 Spec de cada tela** como fonte única. Ela lista:
   objetivo, layout, hierarquia, **props exatas que o controller manda hoje**, ações com o
   *bag* de erro de cada uma, estados obrigatórios e o que deve ser **removido**.
4. Todo texto visível vem do **§11 Glossário**. Não invente sinônimo.
5. Nada neste documento autoriza mudar controller, rota, migration, serviço ou model.

**Convenção de leitura dos layouts ASCII:** eles descrevem *hierarquia e ordem*,
não pixels. Onde há duas versões (desktop / mobile), o corte é `lg` (1024px),
salvo indicação em contrário.

---

## 1. Princípios do redesign

Sete regras. Todas verificáveis por leitura de diff — se um PR viola uma delas, é rejeitado.

### P1 — Erro nasce onde a ação foi disparada
Nenhuma mensagem de erro de domínio (`release`, `entitlement`, `batch`, `invite`, `ship`,
`deliver`, `row`, `file`, `cnpj`, `active`, `role`, `redeem`) pode ser exibida apenas no topo
da página. Ela é renderizada dentro do painel, do diálogo ou da linha que a originou.
*Verificação:* `grep` por `UiFlash` em `resources/js` retorna zero ocorrências ao fim da migração;
cada tela em §10 lista seus bags e onde cada um aparece.

### P2 — Ação primária nunca desaparece
Botão que existe para um estado e some no outro vira recall. A ação primária de uma tela
tem sempre um dos três estados: **ativo**, **desabilitado com o motivo escrito ao lado**, ou
**concluído com o registro do que aconteceu**. Nunca ausente.
*Verificação:* nenhum `v-if` que remova a ação primária do DOM por causa de estado de dado
(permissão é exceção — ver P7).

### P3 — Nenhum texto lido pelo usuário abaixo de 4,5:1
`text-slate-400` (#94a3b8, 2,5:1) é proibido em texto. Piso: `text-ink-muted` (#667085, 4,9:1).
`text-ink-subtle` (#98a2b3) existe **só** para ícone decorativo, separador e travessão de
célula vazia. Cor semântica nunca carrega significado sozinha — sempre acompanhada de palavra
("ATRASADO", "Bloqueada", "Pendente").
*Verificação:* `grep -r "slate-400\|slate-300" resources/js` = zero em arquivo migrado.

### P4 — A lista é a tela; criar é uma ação
Nenhum formulário nasce aberto acima de uma lista. Criar/editar acontece em painel lateral
(`UiDrawer`) ou modal (`UiModal`), aberto por botão no cabeçalho ou no menu da linha.
Exceção única: `Projects/Create`, que é uma tela dedicada de formulário.

### P5 — Consequência antes do clique, não depois
Ação irreversível é confirmada por `UiConfirm` que **quantifica** o efeito e cujo botão
**repete o número**. `window.confirm()` é proibido. Upload nunca dispara no `@change`:
seleciona → mostra o arquivo → o usuário clica em enviar.

### P6 — Nenhum enum cru na interface
`{{ x.status }}` direto no template é proibido. Todo status passa por `lib/format.js`
(via `StatusBadge`). Toda tela tem um nome só, repetido no item de menu, no `<Head>`,
no `<h1>` e no breadcrumb.

### P7 — Item de menu por PAPEL, ação por PERMISSÃO
O menu é decidido por `auth.user.is_operator | is_admin | is_client_admin | is_end_customer`.
O botão é decidido pelo que o backend já manda (`canCreate`, `canShip`, `canImport`,
`permissions.critical`, `permissions.manage_customers`, `can_be_released`, `can_reprocess`).
O usuário N2 (somente consulta) **vê todos os destinos** e, onde o N1 vê botão, ele vê
**texto de estado** — nunca célula vazia, nunca `—`.

---

## 2. Restrições duras

**Permitido:** `resources/js/**`, `resources/css/app.css`, `lang/pt_BR/*.php` (arquivos de
texto — nenhuma classe PHP alterada), `resources/views/errors/403.blade.php` (view nova).

**Proibido:**
- Alterar controller, service, model, middleware, migration, `routes/web.php`, `bootstrap/app.php`.
- Renomear rota ou mudar o payload esperado por qualquer endpoint.
- Adicionar dependência npm. Só **Tailwind CSS 4 + Vue 3 (`<script setup>`) + Inertia**.
  Options API continua proibida.
- Descartar dado que o controller envia. Se uma tela deixa de exibir um campo na visão
  principal, ele vai para painel/expansor — não some.
- Texto em qualquer idioma que não pt-BR.

### 2.1 Fora de escopo (registrado para ninguém tentar)

| Item pedido por auditoria | Por que ficou de fora | Onde ficaria |
|---|---|---|
| Busca e filtros em `Projects/Index` | Exige `when($request->q)` + `withQueryString()` no `ProjectController`. Filtrar só a página corrente no front seria mentira ("filtrando 20 de 214"). | `ProjectController@index` |
| Exibir `released_by` em `Projects/Show` | O controller não envia o campo. | `ProjectController@show` |
| `product.description` / `image_url` no `redeemForm` | Controller não envia. | `PortalController` |
| Guarda "já resgatado" no `redeemForm` | É regra de acesso no controller. | `PortalController@redeemForm` |
| `RedirectIfAuthenticated::redirectUsing(homeRouteName)` | Wiring de middleware. | `bootstrap/app.php` |
| Paginação server-side de `entitlements` / `redemptions` | Controller manda a lista inteira. Resolvido no front com paginação client-side (§9.5). | `ProjectController@show` |
| Validação `size:9` no `zip` do resgate | Regra de validação do controller. Mitigado no front por guarda de interface. | `PortalController@redeem` |

---

## 3. Decisões de conflito entre as auditorias

Onde duas auditorias propuseram coisas incompatíveis, vale o que está aqui.

| # | Tema | Decisão | Motivo |
|---|---|---|---|
| C1 | `UiFlash` global: manter como rede de segurança **ou** deletar | **Deletar.** Componente removido do projeto. | Uma "allowlist de bags" no layout duplica o que cada página já sabe, e uma rede de segurança que mostra erro sem contexto é ruído. §10 enumera, por tela, todos os bags e onde cada um é renderizado — isso torna a cobertura verificável em revisão. |
| C2 | Trocar a fonte (IBM Plex / JetBrains) | **Manter Inter.** Adicionar apenas `--font-mono` com pilha do sistema. | Trocar a sans é custo sem defeito auditado por trás (duas auditorias classificaram como último item / opcional). A ausência de `--font-mono` **é** defeito real: `font-mono` cai na monoespaçada do SO e desalinha código Omie entre Windows e macOS. Pilha do sistema = zero requisição nova. |
| C3 | Sidebar: grupo ADMINISTRAÇÃO colapsável com `localStorage` **ou** dois grupos rotulados sempre visíveis | **Dois grupos rotulados, sempre visíveis.** | O defeito é "9 itens sem hierarquia", e o rótulo de grupo + separador já resolve. Colapsar acrescenta estado persistido, auto-abertura por rota e um clique extra para economizar 4 linhas numa coluna que tem espaço sobrando. |
| C4 | Mobile: barra inferior de 4 itens + "Mais" **ou** topbar + drawer | **Topbar + `UiDrawer`** na área operacional. **Barra inferior só no Portal.** | A barra de 4 + "Mais" exige manter uma segunda lista de navegação diferente da sidebar — duas fontes de verdade. O drawer renderiza o **mesmo** array `nav`. O Portal é a exceção legítima: 2 destinos fixos, sem "Mais", sem lista divergente, público 100% celular. |
| C5 | Nome da prop de retorno no `PageHeader`: `parent` **ou** `back` | **`back: { label, href }`.** | Mesma coisa com dois nomes; unificado. |
| C6 | Abas de `Projects/Show`: "Visão geral / Colaboradores / Resgates" **ou** "Clientes finais / Resgates / Kits" | **`Clientes finais (N) · Resgates (N) · Kits (N)`**, padrão = Clientes finais. | Não sobra conteúdo para uma aba "Visão geral": os 4 indicadores viram faixa de metadados no cabeçalho, que fica **acima** das abas e sempre visível. Uma aba que só tem a tabela de kits é a aba Kits. |
| C7 | Vocabulário: "Colaboradores" **ou** "Clientes finais" | **"Clientes finais"** em todo lugar. | É o termo do FD e do backend (`entitlements`, `role=end_customer`) e já é usado nas telas de admin. "Colaboradores" criaria um terceiro nome para a mesma coisa. |
| C8 | Portal: "Presentes/Entregas" **ou** "Meu presente/Acompanhar entrega" | **"Meu presente" e "Entregas".** | Caso dominante é um presente só; "Acompanhar entrega" quebra linha numa barra inferior de 2 alvos. Os `<h1>` das páginas podem ser mais longos. |
| C9 | Resgate no portal: formulário único com CEP **ou** 2 passos | **2 passos** (endereço → conferência). | É a única ação irreversível do sistema e **não existe rota de correção de endereço em lugar nenhum** (confirmado em `routes/web.php`). Um passo de conferência é a única proteção possível sem backend. Custo: um `ref` e um `v-if`. |
| C10 | Expedição: popover ancorado **ou** drawer **ou** segmentado inline na linha | **`UiDrawer`.** | Popover ancorado a `<tr>` dentro de `overflow-x-auto` é armadilha de posicionamento (o container corta e rola). O drawer tem espaço para o cabeçalho de contexto (cliente, cidade, kit) que diz *o que* está sendo expedido, e reusa a mesma primitiva dos formulários de admin. |
| C11 | Dashboard: 3 cartões de tarefa **ou** 4 indicadores densos linkados | **3 cartões de tarefa** com verbo, destino e itens derivados. | Duas auditorias chegaram nisso de forma independente. `totalProjects` não muda decisão nenhuma. E como `Projects/Index` não tem filtro (e não podemos criar), listar os nomes dentro do cartão é a única forma de fechar o ciclo "li 3, cadê os 3?". |
| C12 | Escala tipográfica: redefinir `--text-sm` para 13px | **Não redefinir a escala do Tailwind.** Só adicionar `--text-stat`. | Mudar `--text-sm` de 14→13px altera silenciosamente toda tela já existente, inclusive as ainda não migradas, durante um rollout faseado. O defeito real é a **ausência de salto** entre título de seção e corpo — resolvido por receita de classes (§4.2), não por encolher o corpo. |
| C13 | Sombras `--shadow-card/lift/pop`: remover agora | **Manter definidas (marcadas como obsoletas) até a última etapa.** | `app.css` é editado uma única vez, na etapa 1. Se as sombras sumirem ali, todas as telas ainda não migradas quebram visualmente. Removê-las é a última etapa do plano. |
| C14 | `UiForm` + `UiFormSection` | **Só `UiFormSection`.** | O valor do `UiForm` seria bloquear envio duplo, e `useForm.processing` + `:loading` no botão (que já desabilita) faz isso. Menos indireção. |
| C15 | Traduções `lang/pt_BR` | **Dentro do escopo.** | Não é backend: são arquivos de texto, nenhuma classe PHP alterada. E "The zip field is required." chegando ao cliente final é defeito de interface direto, contra a exigência de pt-BR. |

---

## 4. Tokens

### 4.1 Bloco `@theme` — colar em `resources/css/app.css`

Substitui o arquivo inteiro. `.bg-dotted`, `.animate-fade-in-up`, `.animate-scale-in` e
`html { scroll-behavior: smooth }` saem. Telas ainda não migradas que usem essas classes
simplesmente deixam de ter o efeito — **não quebram o build** (classe inexistente no Tailwind
é ignorada).

```css
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
@import 'tailwindcss';

@source '../../resources/js/**/*.vue';

@theme {
    /* ─────────────────────────────────────────────────────────────
       TIPOGRAFIA
       A escala do Tailwind NÃO é redefinida (ver decisão C12).
       Só entra a mono — hoje `font-mono` cai na monoespaçada do SO
       e desalinha código Omie / CNPJ / rastreio entre Windows e macOS.
       ───────────────────────────────────────────────────────────── */
    --font-sans: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif,
        'Apple Color Emoji', 'Segoe UI Emoji';
    --font-mono: ui-monospace, 'SFMono-Regular', 'Cascadia Mono', 'Segoe UI Mono', Menlo,
        Consolas, 'Liberation Mono', monospace;

    /* Único tamanho novo: o número grande de indicador. */
    --text-stat: 1.75rem;
    --text-stat--line-height: 2rem;

    /* ─────────────────────────────────────────────────────────────
       NEUTROS — nomeados por PAPEL, não por matiz.
       O nome é o que torna a regra de contraste (P3) verificável:
       `ink-subtle` está documentado como proibido em texto lido.
       ───────────────────────────────────────────────────────────── */
    --color-canvas: #f6f7f9;          /* fundo da aplicação */
    --color-surface: #ffffff;         /* cartão, tabela, modal, drawer */
    --color-surface-muted: #f8fafc;   /* thead, hover de linha, campo desabilitado */
    --color-line: #e4e7ec;            /* borda e divisória padrão */
    --color-line-strong: #cfd4dc;     /* borda de campo, hover de borda */
    --color-ink: #101828;             /* texto primário — 16,7:1 */
    --color-ink-muted: #667085;       /* rótulo, texto secundário — 4,9:1 (PISO) */
    --color-ink-subtle: #98a2b3;      /* SÓ ícone decorativo, separador, travessão */
    --color-ink-inverse: #ffffff;

    /* ─────────────────────────────────────────────────────────────
       MARCA — 6 degraus (os 10 atuais nunca foram todos usados)
       ───────────────────────────────────────────────────────────── */
    --color-brand-50: #eef2ff;
    --color-brand-100: #e0e7ff;
    --color-brand-200: #c7d2fe;
    --color-brand-500: #6366f1;   /* foco */
    --color-brand-600: #4f46e5;   /* ação primária */
    --color-brand-700: #4338ca;   /* hover/active, texto sobre fundo soft */

    /* ─────────────────────────────────────────────────────────────
       SEMÂNTICAS — 3 famílias com trio (texto / fundo / borda).
       `info` é a própria marca: o sistema não tem dois azuis.
       ───────────────────────────────────────────────────────────── */
    --color-success: #067647;
    --color-success-soft: #ecfdf3;
    --color-success-line: #abefc6;

    --color-warning: #b54708;
    --color-warning-soft: #fffaeb;
    --color-warning-line: #fedf89;

    --color-danger: #b42318;         /* texto/ícone sobre fundo soft */
    --color-danger-soft: #fef3f2;
    --color-danger-line: #fecdca;
    --color-danger-solid: #d92d20;   /* ÚNICO vermelho sólido do sistema */

    --color-info: #4338ca;
    --color-info-soft: #eef2ff;
    --color-info-line: #c7d2fe;

    --color-focus: #6366f1;

    /* ─────────────────────────────────────────────────────────────
       ELEVAÇÃO
       Regra: sombra só em coisa que FLUTUA (modal, drawer, menu, toast).
       Cartão e tabela usam `border border-line`.
       ───────────────────────────────────────────────────────────── */
    --shadow-xs: 0 1px 2px 0 rgb(16 24 40 / 0.05);
    --shadow-overlay: 0 12px 32px -8px rgb(16 24 40 / 0.16), 0 4px 8px -4px rgb(16 24 40 / 0.08);

    /* OBSOLETOS — mantidos só para as telas ainda não migradas não
       quebrarem visualmente. Removidos na última etapa do plano (§12). */
    --shadow-card: 0 1px 2px -1px rgb(15 23 42 / 0.06), 0 1px 3px 0 rgb(15 23 42 / 0.04);
    --shadow-lift: 0 8px 24px -6px rgb(15 23 42 / 0.10), 0 3px 8px -4px rgb(15 23 42 / 0.06);
    --shadow-pop: 0 16px 40px -8px rgb(15 23 42 / 0.20), 0 6px 14px -6px rgb(15 23 42 / 0.10);

    /* ─────────────────────────────────────────────────────────────
       DENSIDADE — alturas de controle (alvo de toque)
       ───────────────────────────────────────────────────────────── */
    --spacing-control-sm: 2.25rem;  /* 36px — ação por linha de tabela */
    --spacing-control-md: 2.5rem;   /* 40px — padrão da área operacional */
    --spacing-control-lg: 3rem;     /* 48px — portal e autenticação */
    --spacing-sidebar: 15rem;       /* 240px */

    /* ───────────────────────────────────────────────────────────── */
    --ease-out-soft: cubic-bezier(0.16, 1, 0.3, 1);
}

@layer base {
    /* Foco visível único em toda a interface. Nenhum componente define
       o seu próprio anel de foco em campo (o `focus:ring-4` do UiInput sai). */
    :focus-visible {
        outline: 2px solid var(--color-focus);
        outline-offset: 2px;
        border-radius: 0.375rem;
    }

    /* Cabeçalho de contexto é sticky (64px). Sem isto, o foco de teclado
       fica escondido atrás dele — WCAG 2.2 SC 2.4.11. */
    :where(a, button, input, select, textarea, [tabindex]) {
        scroll-margin-top: 5rem;
    }

    body {
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
        text-rendering: optimizeLegibility;
        font-feature-settings: 'cv11', 'ss01';
        letter-spacing: -0.006em;
        background-color: var(--color-canvas);
        color: var(--color-ink);
    }

    ::selection {
        background-color: var(--color-brand-100);
        color: var(--color-brand-700);
    }

    h1, h2, h3 {
        letter-spacing: -0.02em;
    }
}

@layer utilities {
    /* Fundo de destaque das telas públicas (AuthCard). Única camada
       decorativa que sobrevive: `.bg-dotted` e o blob com blur saíram. */
    .bg-mesh {
        background-image:
            radial-gradient(60% 55% at 15% 0%, rgb(99 102 241 / 0.10) 0%, transparent 60%),
            radial-gradient(50% 50% at 100% 0%, rgb(129 140 248 / 0.10) 0%, transparent 55%);
    }

    .scrollbar-slim {
        scrollbar-width: thin;
        scrollbar-color: var(--color-line-strong) transparent;
    }
    .scrollbar-slim::-webkit-scrollbar { height: 6px; width: 6px; }
    .scrollbar-slim::-webkit-scrollbar-thumb {
        background-color: var(--color-line-strong);
        border-radius: 9999px;
    }

    @keyframes toast-in {
        from { opacity: 0; transform: translateX(12px) scale(0.98); }
        to   { opacity: 1; transform: translateX(0) scale(1); }
    }
    .animate-toast-in { animation: toast-in 0.28s var(--ease-out-soft) both; }

    @keyframes overlay-in {
        from { opacity: 0; }
        to   { opacity: 1; }
    }
    .animate-overlay-in { animation: overlay-in 0.15s var(--ease-out-soft) both; }

    @keyframes drawer-in {
        from { opacity: 0; transform: translateX(16px); }
        to   { opacity: 1; transform: translateX(0); }
    }
    .animate-drawer-in { animation: drawer-in 0.18s var(--ease-out-soft) both; }

    @keyframes skeleton-pulse {
        0%, 100% { opacity: 1; }
        50%      { opacity: 0.45; }
    }
    .skeleton {
        background-color: var(--color-surface-muted);
        animation: skeleton-pulse 1.6s var(--ease-out-soft) infinite;
    }

    @media (prefers-reduced-motion: reduce) {
        .animate-toast-in,
        .animate-overlay-in,
        .animate-drawer-in,
        .skeleton { animation: none; }
    }
}
```

### 4.2 Receitas tipográficas (usar exatamente estas classes)

O defeito atual é que título de seção (`text-sm font-semibold text-slate-700`) tem o mesmo
tamanho de uma célula de tabela (`text-sm`) — o olho não tem âncora. O salto vem daqui:

| Papel | Classes | Onde |
|---|---|---|
| Título de página | `text-2xl font-semibold tracking-tight text-ink` | `PageHeader` (`tone="app"`) |
| Título de página (portal/auth) | `text-2xl font-semibold tracking-tight text-ink` | `PageHeader` (`tone="portal"`), `AuthCard` |
| Título de seção | `text-base font-semibold text-ink` + `mt-8 mb-3` | `UiSectionHeading` |
| Título de cartão / drawer | `text-sm font-semibold text-ink` | `UiCard`, `UiDrawer` |
| Corpo | `text-sm text-ink` | tabela, formulário |
| Corpo do portal | `text-base text-ink` | telas `Portal/*` |
| Rótulo secundário / meta | `text-xs text-ink-muted` | linha de metadados |
| Cabeçalho de tabela | `text-xs font-semibold uppercase tracking-wider text-ink-muted` | `UiTable` |
| Número de indicador | `text-stat font-semibold tabular-nums text-ink` | `UiStat` |
| Código (Omie, CNPJ, NF, rastreio) | `font-mono text-xs` | `UiCode` |

### 4.3 Regras de estilo verificáveis (porta de revisão)

1. Nenhuma classe `slate-*`, `emerald-*`, `amber-*`, `red-*`, `blue-*`, `indigo-*` em arquivo
   migrado. Cor só entra por `canvas | surface | surface-muted | line | line-strong | ink |
   ink-muted | ink-subtle | brand | success | warning | danger | info | focus`.
2. Nenhum `rounded-xl` ou `rounded-2xl`. Raios permitidos: `rounded-sm` (chip de código),
   `rounded-md` (campo, botão, item de menu), `rounded-lg` (cartão, tabela, painel, modal),
   `rounded-full` (badge, avatar, ponto).
3. Nenhum `shadow-card`, `shadow-lift`, `shadow-pop`, `shadow-sm`, `shadow-md`. Só
   `shadow-xs` (campo/botão secundário) e `shadow-overlay` (modal, drawer, menu, toast).
4. Nenhum tamanho arbitrário de texto (`text-[11px]`).
5. Coluna numérica de tabela: sempre `text-right tabular-nums`.
6. Nenhum `<svg>` inline em `Pages/`. Ícone só via `<UiIcon name="…" />`.
7. Nenhum `router.post/put/delete` para escrita solto — sempre `useForm` ou `useRowAction`.
8. Nenhum `{{ algo.status }}` cru no template.
9. Nenhum `window.confirm` / `alert`.

---

## 5. Estrutura de arquivos alvo

```
resources/js/
├── Layouts/
│   ├── AppLayout.vue          REESCREVER  (operador + shell mínimo do Admin Cliente)
│   └── PortalLayout.vue       REESCREVER  (cliente final)
├── Components/
│   ├── AuthCard.vue           REESCREVER
│   ├── PageHeader.vue         REESCREVER
│   ├── PipelineChart.vue      MANTER      (só troca de tokens)
│   ├── ShipControl.vue        REESCREVER
│   ├── StatusBadge.vue        REESCREVER
│   ├── Toaster.vue            REESCREVER
│   ├── UiAlert.vue            NOVO
│   ├── UiBadge.vue            REESCREVER
│   ├── UiButton.vue           REESCREVER
│   ├── UiCard.vue             REESCREVER
│   ├── UiCheckbox.vue         NOVO
│   ├── UiCheckboxGroup.vue    NOVO
│   ├── UiCode.vue             NOVO
│   ├── UiConfirm.vue          NOVO
│   ├── UiDescriptionList.vue  NOVO
│   ├── UiDrawer.vue           NOVO
│   ├── UiEmpty.vue            REESCREVER
│   ├── UiField.vue            REESCREVER
│   ├── UiFileDrop.vue         NOVO
│   ├── UiFormSection.vue      NOVO
│   ├── UiIcon.vue             NOVO
│   ├── UiInput.vue            REESCREVER
│   ├── UiMenu.vue             NOVO
│   ├── UiModal.vue            NOVO
│   ├── UiPagination.vue       REESCREVER
│   ├── UiPanel.vue            NOVO
│   ├── UiRowErrors.vue        NOVO
│   ├── UiSearchInput.vue      NOVO
│   ├── UiSectionHeading.vue   NOVO
│   ├── UiSelect.vue           REESCREVER
│   ├── UiSkeleton.vue         NOVO
│   ├── UiStat.vue             REESCREVER
│   ├── UiTable.vue            REESCREVER
│   ├── UiTabs.vue             NOVO
│   ├── UiTextarea.vue         NOVO
│   ├── UiTimeline.vue         NOVO
│   └── UiFlash.vue            REMOVER  ← deletar o arquivo
├── composables/                NOVO (diretório)
│   ├── useCepLookup.js
│   ├── useConfirm.js
│   ├── useRowAction.js
│   ├── useTabQuery.js
│   └── useToast.js
├── lib/
│   ├── cep.js                 MANTER (sem alteração)
│   ├── format.js              ESTENDER
│   └── icons.js               NOVO
└── Pages/                      (ver §10)

lang/pt_BR/                     NOVO
├── validation.php
└── passwords.php

resources/views/errors/
└── 403.blade.php               NOVO
```

---

## 6. Inventário de componentes

Notação: `nome: Tipo = padrão`. Prop sem `=` é obrigatória.
Todos os componentes usam `<script setup>`. Nenhum usa Options API.

### 6.1 Estrutura e superfícies

---

#### `PageHeader.vue` — **REESCREVER**
**Responsabilidade.** Cabeçalho de contexto de toda página: retorno de um nível, título,
metadados, ações primárias e (opcionalmente) as abas. Na área operacional é **sticky**.

| Prop | Tipo | Padrão | Observação |
|---|---|---|---|
| `title` | `String` | — | obrigatório |
| `subtitle` | `String` | `null` | só existe se responder "o que eu faço aqui" ou "de onde vem este dado" |
| `back` | `Object` | `null` | `{ label: String, href: String }` — renderiza `‹ Label` **acima** do H1, à esquerda |
| `tone` | `String` | `'app'` | `'app'` \| `'portal'` — `portal` não é sticky e usa alvos de 48px |
| `sticky` | `Boolean` | `true` | ignorado quando `tone="portal"` |

**Slots:** `#actions` (à direita do título, **no máximo 2 botões**), `#meta` (linha de
metadados abaixo do título), `#tabs` (abaixo de tudo, encostado na borda inferior).

**Estrutura:**
```
┌──────────────────────────────────────────────────────────────┐
│ ‹ Projetos                                     [ Ação prim. ]│  ← back + #actions
│ Kit Natal 2026                                               │  ← title
│ Suvinil S/A · 500 kits · prazo 12/12/2026                    │  ← #meta
│ ┌──────────────┬──────────────┬────────┐                     │  ← #tabs
│ │Clientes finais│  Resgates   │  Kits  │                     │
└──────────────────────────────────────────────────────────────┘
```
Sticky: `sticky top-0 z-20 -mx-4 border-b border-line bg-surface px-4 sm:-mx-6 sm:px-6`.

---

#### `UiSectionHeading.vue` — **NOVO**
**Responsabilidade.** Título de bloco dentro da página (hoje escrito à mão com pesos e
margens diferentes em cada tela).

| Prop | Tipo | Padrão |
|---|---|---|
| `title` | `String` | — |
| `count` | `Number` | `null` |
| `hint` | `String` | `null` |

**Slots:** `#actions`. Renderiza `text-base font-semibold text-ink` com `mt-8 mb-3`;
`count` sai como `text-ink-muted tabular-nums` ao lado.

---

#### `UiCard.vue` — **REESCREVER**
| Prop | Tipo | Padrão |
|---|---|---|
| `title` | `String` | `null` |
| `subtitle` | `String` | `null` |
| `padding` | `String` | `'md'` — `'none'` \| `'sm'` \| `'md'` |
| `interactive` | `Boolean` | `false` |

**Slots:** default, `#header` (ações no cabeçalho), `#footer`.
`rounded-lg border border-line bg-surface`. **Sem sombra.** `interactive` muda a borda para
`hover:border-line-strong` — **nunca translada** (o `hover:-translate-y-0.5` atual sai).

---

#### `UiPanel.vue` — **NOVO**
**Responsabilidade.** Superfície sem cabeçalho, para faixas de conteúdo (metadados, barra de
ação, aviso de contexto). Substitui os `<div class="rounded-xl border … shadow-sm">` escritos
à mão em `Projects/Show`, `Imports/Show` e `Portal/Redemptions`.

| Prop | Tipo | Padrão |
|---|---|---|
| `tone` | `String` | `'default'` — `'default'` \| `'muted'` |
| `padding` | `String` | `'sm'` — `'sm'` \| `'md'` |

---

#### `UiTable.vue` — **REESCREVER**
| Prop | Tipo | Padrão | Observação |
|---|---|---|---|
| `columns` | `Array` | — | `[{ key, label, align?: 'start'\|'end', numeric?: Boolean, width?: String, srOnlyLabel?: Boolean }]` |
| `caption` | `String` | `null` | renderizado como `<caption class="sr-only">` |
| `density` | `String` | `'default'` | `'compact'` \| `'default'` |
| `stickyHeader` | `Boolean` | `false` | |
| `maxRows` | `Number` | `null` | quando definido, o corpo rola internamente com cabeçalho fixo |

**Slots:** default (as `<tr>`), `#empty` (renderiza automaticamente `<tr><td :colspan="columns.length">`).

**Obrigatório no markup:** `scope="col"` em todo `<th>`; container rolável com
`tabindex="0" role="region" :aria-label="caption"`; coluna de ações declarada como
`{ key: 'actions', label: 'Ações', srOnlyLabel: true }` (nunca `label: ''`);
`numeric: true` aplica `text-right tabular-nums` na coluna inteira, cabeçalho incluído.

---

#### `UiDescriptionList.vue` — **NOVO**
**Responsabilidade.** Pares rótulo/valor. Hoje há duas implementações incompatíveis
(`Dashboard` e `Admin/Companies`).

| Prop | Tipo | Padrão |
|---|---|---|
| `items` | `Array` | — `[{ key, label, value, mono?: Boolean, href?: String }]` |
| `layout` | `String` | `'inline'` — `'inline'` \| `'stacked'` \| `'grid'` |
| `columns` | `Number` | `2` (só em `grid`) |

**Slots:** `#value-<key>` para valor customizado (badge, link, `UiCode`).
`inline` = uma linha separada por `·`, usado na faixa de metadados do `PageHeader`.

---

#### `UiStat.vue` — **REESCREVER**
| Prop | Tipo | Padrão |
|---|---|---|
| `value` | `String \| Number` | — |
| `label` | `String` | — |
| `tone` | `String` | `'default'` — `'default'` \| `'warning'` \| `'danger'` \| `'success'` |
| `href` | `String` | `null` — com `href`, a caixa inteira vira `<Link>` |

**Sai:** a prop `icon`, a sombra e o `hover:-translate-y-0.5`.
Número em `text-stat font-semibold tabular-nums`, rótulo em `text-xs text-ink-muted`.

---

#### `UiTabs.vue` — **NOVO**
| Prop | Tipo | Padrão |
|---|---|---|
| `modelValue` | `String` | — chave da aba ativa |
| `tabs` | `Array` | — `[{ key, label, count?: Number }]` |
| `variant` | `String` | `'underline'` — `'underline'` \| `'segmented'` |

**Eventos:** `update:modelValue`.
**Acessibilidade obrigatória:** `role="tablist"`, `role="tab"`, `aria-selected`,
`aria-controls`; navegação por ←/→/Home/End; o painel correspondente tem `role="tabpanel"`
e `tabindex="0"`. Alvo mínimo 40px. Abaixo de `sm`, a fila rola horizontalmente
(`overflow-x-auto scrollbar-slim`) — nunca quebra em duas linhas.

---

#### `UiTimeline.vue` — **NOVO**
Eventos de rastreio do portal (hoje inline em `Portal/Redemptions`).

| Prop | Tipo | Padrão |
|---|---|---|
| `events` | `Array` | — `[{ description, occurredAt, location }]` |

Primeiro evento com ponto `bg-brand-600`, demais `bg-line-strong`.
Data/local em `text-sm text-ink-muted` (nunca `text-xs text-ink-subtle`).

---

#### `PipelineChart.vue` — **MANTER**
Só substituir `text-slate-500` → `text-ink-muted`, `bg-slate-100` → `bg-surface-muted` e
`text-slate-400` do estado vazio → `text-ink-muted`. API inalterada: `data: Array<{label, value}>`.

---

### 6.2 Formulário

---

#### `UiField.vue` — **REESCREVER**
**Responsabilidade.** Rótulo + controle + dica + erro, com a ligação acessível correta.
Hoje o componente envolve tudo num `<label>`, o que faz o leitor de tela anunciar
*"CEP CEP não encontrado — confira ou preencha à mão"* como **nome** do campo, e produz
HTML inválido em `Projects/Create` (label dentro de label).

| Prop | Tipo | Padrão | Observação |
|---|---|---|---|
| `label` | `String` | `null` | |
| `error` | `String` | `null` | |
| `hint` | `String` | `null` | some quando há erro |
| `required` | `Boolean` | `false` | |
| `as` | `String` | `'label'` | `'label'` \| `'group'` — `group` renderiza `<fieldset><legend>` |

**Slots:** default, `#hint`, `#error`.

**Contrato obrigatório:**
- Raiz é `<div>`. O rótulo é `<label :for="id">` com **apenas o texto do rótulo**.
- Gera `id` com `useId()`; `hint` e `error` recebem ids próprios.
- `provide('uiField', { id, describedBy, invalid, required })`. `UiInput`, `UiSelect`,
  `UiTextarea`, `UiCheckboxGroup` e `UiFileDrop` fazem `inject` e aplicam `:id`,
  `:aria-describedby`, `:aria-invalid`, `:required`.
- O erro tem `role="alert"`.
- O asterisco é `aria-hidden="true"`; o rótulo ganha `<span class="sr-only"> (obrigatório)</span>`.
- Com `as="group"` não emite `for` (é o caso do grupo de checkboxes de `Projects/Create`).

---

#### `UiInput.vue` — **REESCREVER**
| Prop | Tipo | Padrão |
|---|---|---|
| `type` | `String` | `'text'` |
| `invalid` | `Boolean` | `false` |
| `size` | `String` | `'md'` — `'sm'` \| `'md'` \| `'lg'` (mapeia `control-sm/md/lg`) |
| `width` | `String` | `'full'` — `'full'` \| `'auto'` \| `'sm'` \| `'md'` |
| `loading` | `Boolean` | `false` — spinner dentro do campo (busca de CEP) |
| `prefix` | `String` | `null` |
| `suffix` | `String` | `null` |

`v-model` via `defineModel({ type: [String, Number], default: '' })`.
**Sai** o `focus:ring-4` (o `:focus-visible` global de `app.css` é o único anel de foco).
A largura vira prop — isso acaba com a colisão `w-full` vs `class="w-40"` do `ShipControl`.
Atributos livres (`autocomplete`, `inputmode`, `maxlength`, `placeholder`) continuam
chegando por fallthrough.

---

#### `UiTextarea.vue` — **NOVO**
Existem hoje três textareas escritos à mão com três tratamentos de foco diferentes.

| Prop | Tipo | Padrão |
|---|---|---|
| `rows` | `Number` | `3` |
| `invalid` | `Boolean` | `false` |
| `mono` | `Boolean` | `false` |
| `autogrow` | `Boolean` | `false` |
| `maxlength` | `Number` | `null` |
| `counter` | `Boolean` | `false` — mostra `412 / 5000` abaixo, à direita |

Mesmas classes de borda/foco do `UiInput`.

---

#### `UiSelect.vue` — **REESCREVER**
| Prop | Tipo | Padrão |
|---|---|---|
| `options` | `Array` | `null` — `[{ value, label, disabled? }]` |
| `placeholder` | `String` | `null` |
| `invalid` | `Boolean` | `false` |
| `size` | `String` | `'md'` |
| `width` | `String` | `'full'` |

**Slot** default mantido (uso com `<option>` cru continua válido). Mantém o chevron via
`background-image`, trocando `%2364748b` por `%23667085` (`ink-muted`).

---

#### `UiCheckbox.vue` / `UiCheckboxGroup.vue` — **NOVO**
`UiCheckbox`: `v-model: Boolean`; props `label: String`, `description: String = null`,
`disabled: Boolean = false`. Alvo mínimo 40px de altura de linha.

`UiCheckboxGroup`: `v-model: Array`; props:

| Prop | Tipo | Padrão |
|---|---|---|
| `options` | `Array` | — `[{ value, label, hint? }]` |
| `columns` | `Number` | `1` |
| `searchable` | `Boolean` | `false` |
| `maxHeight` | `String` | `null` |
| `emptyLabel` | `String` | `'Nenhum resultado.'` |

Com `searchable`, filtra por `label` e `hint`. Mostra `N selecionados` abaixo da caixa.
Usado em `Projects/Create` (lista de até 40 produtos, hoje sem busca).

---

#### `UiFileDrop.vue` — **NOVO**
**Responsabilidade.** Único ponto de upload do sistema. Hoje há duas implementações
divergentes que **enviam no `@change`**, sem confirmação, sem mostrar o arquivo, sem progresso.

| Prop | Tipo | Padrão |
|---|---|---|
| `accept` | `String` | `'.xlsx,.csv'` |
| `disabled` | `Boolean` | `false` |
| `progress` | `Number` | `null` — `form.progress?.percentage` |
| `file` | `Object` | `null` — o `File` selecionado (controlado pelo pai) |
| `label` | `String` | `'Arraste o arquivo ou clique para selecionar'` |

**Eventos:** `select(File)`, `clear()`.
**Slots:** `#hint` (formato aceito, `<details>` recolhido), `#result` (painel de resultado).

**Comportamento obrigatório:**
- Aceita clique **e** arrastar-e-soltar.
- O `<input type="file">` é `sr-only` (não `hidden`) e o contorno do drop zone reage ao
  `:focus-within` — hoje o input está `hidden` e é inalcançável por teclado.
- Depois de escolher, mostra `arquivo: resgates-07.xlsx · 340 KB  [trocar]` e **não envia**.
  O envio é sempre um botão explícito do pai.
- Com `progress != null`, troca o drop zone por barra de progresso + texto de etapa.

---

#### `UiSearchInput.vue` — **NOVO**
| Prop | Tipo | Padrão |
|---|---|---|
| `modelValue` | `String` | `''` |
| `placeholder` | `String` | `'Buscar…'` |
| `debounce` | `Number` | `300` |
| `resultLabel` | `String` | `null` — ex.: `'18 produtos'` |

**Eventos:** `update:modelValue`, `search(value)`, `clear()`.
Botão de limpar com `aria-label="Limpar busca"`, alvo 40px. `type="search"`, `role="searchbox"`.

---

#### `UiFormSection.vue` — **NOVO**
| Prop | Tipo | Padrão |
|---|---|---|
| `title` | `String` | `null` |
| `description` | `String` | `null` |
| `columns` | `Number` | `1` |

Título em `text-xs font-semibold uppercase tracking-wide text-ink-muted`.

---

### 6.3 Ação, sobreposição e retorno

---

#### `UiButton.vue` — **REESCREVER**
| Prop | Tipo | Padrão |
|---|---|---|
| `variant` | `String` | `'primary'` — `'primary'` \| `'secondary'` \| `'ghost'` \| `'danger'` \| `'danger-soft'` |
| `size` | `String` | `'md'` — `'sm'` (`control-sm`, 36px) \| `'md'` (`control-md`, 40px) \| `'lg'` (`control-lg`, 48px) |
| `href` | `String` | `null` |
| `type` | `String` | `'button'` |
| `disabled` | `Boolean` | `false` |
| `loading` | `Boolean` | `false` |
| `block` | `Boolean` | `false` — largura total (mata o `class="w-full"` do Login) |
| `icon` | `String` | `null` — nome no registro de `lib/icons.js` |
| `iconTrailing` | `String` | `null` |
| `iconOnly` | `Boolean` | `false` |
| `label` | `String` | `null` — **obrigatório quando `iconOnly`**, vira `aria-label` |

**Correções obrigatórias:**
- `size="sm"` sobe de ~25px para **36px** (`h-control-sm`). O tamanho atual está no limite
  absoluto da WCAG 2.2 SC 2.5.8 e é usado justamente onde há maior densidade.
- Com `href` + (`loading` \| `disabled`): renderiza `<Link>` com `aria-disabled="true"`,
  `tabindex="-1"` e `@click.prevent`. Hoje link ignora os dois estados.
- `variant="danger"` passa a ser **sólido** (`bg-danger-solid text-ink-inverse`) e é reservado
  a remoção definitiva dentro de `UiConfirm`. O antigo (branco com texto vermelho) vira
  `danger-soft`.
- `+` e `←` deixam de ser caractere literal dentro do texto: usar `icon="plus"` / `back` do `PageHeader`.

---

#### `UiModal.vue` — **NOVO** (base) e `UiDrawer.vue` — **NOVO**
| Prop | Tipo | Padrão |
|---|---|---|
| `open` | `Boolean` | — |
| `title` | `String` | — |
| `description` | `String` | `null` |
| `size` | `String` | `'md'` — `'sm'` (400px) \| `'md'` (560px) \| `'lg'` (720px) |
| `dismissible` | `Boolean` | `true` |

`UiDrawer` acrescenta `side: String = 'right'` e ocupa **480px** no desktop, **tela cheia**
abaixo de `sm`.

**Eventos:** `update:open`, `close`.
**Slots:** default, `#footer` (rodapé fixo com as ações).

**Contrato de acessibilidade (idêntico nos dois):** `Teleport to="body"`; overlay
`bg-ink/40`; `role="dialog" aria-modal="true"` com `aria-labelledby`/`aria-describedby`;
**foco preso** dentro do painel (Tab cicla); `Esc` fecha quando `dismissible`; foco devolvido
ao elemento que abriu; `overflow: hidden` no `<body>` enquanto aberto; **um painel aberto por
vez** (abrir um fecha o outro).

---

#### `UiConfirm.vue` + `composables/useConfirm.js` — **NOVO**
Substitui os três `window.confirm()` do sistema.

```js
const confirm = useConfirm();
const ok = await confirm({
  title: 'Liberar "Kit Natal 2026"?',
  description: '128 pessoas receberão o e-mail de resgate agora. Esta ação não pode ser desfeita.',
  warning: '12 dessas pessoas ainda não criaram a senha de acesso.', // opcional
  confirmLabel: 'Liberar e notificar 128 pessoas',
  cancelLabel: 'Cancelar',   // padrão
  variant: 'default',        // 'default' | 'danger'
  errorBag: 'release',       // opcional: bag lido de page.props.errors e exibido DENTRO
  action: () => form.post(route('projects.release', id), { preserveScroll: true }), // opcional
});
```
Um único host `<UiConfirm />` montado em cada layout. Foco inicial em **Cancelar**.
Sem `action`, a promessa resolve `true` no "sim" e o chamador escreve (`if (!ok) return;`).
Com `action`, quem dispara a escrita é o diálogo: ele **permanece aberto** enquanto espera o
desfecho e, se o backend recusar, mostra a mensagem do `errorBag` dentro dele — confirmar de
novo chama `action()` outra vez. A promessa resolve `false` no cancelamento e `true` no sucesso.

---

#### `UiMenu.vue` — **NOVO**
Menu "⋯" de ações de linha. Substitui as fileiras de 3-4 botões por linha.

| Prop | Tipo | Padrão |
|---|---|---|
| `items` | `Array` | — `[{ label, icon?, onSelect, danger?: Boolean, disabled?: Boolean, separatorBefore?: Boolean }]` |
| `align` | `String` | `'end'` |
| `label` | `String` | `'Ações'` — vira `aria-label` do gatilho |

Gatilho `iconOnly` de 40×40px com `aria-haspopup="menu"` e `aria-expanded`.
Item `danger` fica **abaixo de um separador** e em `text-danger`. Navegação por ↑/↓,
`Esc` fecha, foco volta ao gatilho.

---

#### `UiAlert.vue` — **NOVO**
Mensagem **no contexto do bloco**. É o substituto do `UiFlash`.

| Prop | Tipo | Padrão |
|---|---|---|
| `variant` | `String` | `'info'` — `'info'` \| `'success'` \| `'warning'` \| `'danger'` |
| `title` | `String` | `null` |
| `dismissible` | `Boolean` | `false` |

**Slots:** default, `#actions`.
`variant="danger"` e `"warning"` renderizam com `role="alert"`; os demais com `role="status"`.

---

#### `UiRowErrors.vue` — **NOVO**
Lista de erros por linha de planilha. O backend concatena tudo numa string única
(`'Linha 4: … | Linha 17: … | Linha 23: …'`), que hoje vira um parágrafo vermelho ilegível.

| Prop | Tipo | Padrão |
|---|---|---|
| `raw` | `String` | — a string do bag `batch` |
| `max` | `Number` | `10` — o excedente colapsa em "ver mais N" |

Parse **exclusivamente no front**, sobre formato fixo e garantido:
```js
raw.split(' | ')
   .map(s => s.match(/^Linha (\d+): (.*)$/))
   .map(m => m ? { line: Number(m[1]), message: m[2] } : { line: null, message: s });
```
Renderiza uma tabela `Linha | Motivo` com o número em `font-mono`, dentro de bloco rolável
`max-h-64`. Linha sem o prefixo esperado cai no fallback (mensagem inteira, sem número).

---

#### `Toaster.vue` — **REESCREVER** + `composables/useToast.js` — **NOVO**
**Dois defeitos a corrigir:**
1. O `watch` sobre `page.props.flash.status` **não dispara quando a string se repete** —
   salvar duas parametrizações seguidas mostra o toast só na primeira.
   *Correção:* enfileirar por evento de navegação, não por mudança de valor:
   ```js
   router.on('success', (event) => {
     const status = event.detail.page.props.flash?.status;
     if (status) push(classify(status), status);
   });
   ```
2. Reprocesso que **falhou** volta em `flash.status` e é pintado de verde com ícone de check.
   *Correção:* `classify(msg)` devolve `'warning'` quando a mensagem casa
   `/continua bloqueada|ainda ausente|não foi possível/i`, senão `'success'`.

**Demais requisitos:** container com `role="region" aria-label="Notificações"` e região
interna `aria-live="polite" aria-atomic="true"`; auto-dispensa em 6s, **pausada em hover e
em foco**; `clearTimeout` de tudo em `onUnmounted`; no máximo 3 toasts visíveis;
`fixed bottom-4` abaixo de `lg` (para não cobrir o cabeçalho sticky) e `top-4 right-6` acima.

`useToast()` expõe `toast.success(msg)`, `toast.warning(msg)`, `toast.error(msg)` para uso
imperativo dentro de `onSuccess`.

---

#### `UiFlash.vue` — **REMOVER**
Deletar o arquivo. Toda referência sai de `AppLayout`, `PortalLayout` e `Auth/Login`.
Destinos de substituição: erro de campo → `UiField :error`; erro de domínio → `UiAlert` no
bloco/painel/diálogo; sucesso → toast; status neutro das telas de auth → `UiAlert variant="info"`.

---

### 6.4 Exibição

---

#### `UiBadge.vue` — **REESCREVER**
| Prop | Tipo | Padrão |
|---|---|---|
| `variant` | `String` | `'neutral'` — `'neutral'` \| `'success'` \| `'warning'` \| `'danger'` \| `'info'` |
| `dot` | `Boolean` | `false` |
| `size` | `String` | `'sm'` — `'sm'` \| `'md'` |

`info` passa a usar os tokens da marca. O sistema deixa de ter dois azuis
(`blue-500` no ponto e `indigo-600` na navegação, hoje na mesma tela).

---

#### `StatusBadge.vue` — **REESCREVER**
| Prop | Tipo | Padrão |
|---|---|---|
| `status` | `String` | — |
| `kind` | `String` | `'pipeline'` — `'pipeline'` \| `'redemption'` \| `'importRow'` \| `'import'` \| `'projectRelease'` |

**Dois `kind` novos:**
- `import` → status do **lote** de importação, hoje exibido cru em inglês em três telas.
  Variante derivada do próprio status: `failed`→`danger`, `completed_with_blocks`→`warning`,
  `completed`→`success`, `processing`→`info`. **Nunca** de `rows_blocked > 0` (hoje um lote
  `failed` sem linhas bloqueadas aparece verde).
- `projectRelease` → recebe uma das três chaves sintéticas `released` \| `ready` \|
  `producing`, hoje montadas à mão em `Projects/Index`.

---

#### `UiCode.vue` — **NOVO**
Chip monoespaçado para código Omie, CNPJ, chave de NF, rastreio. Hoje existem seis variações
de `<code class="rounded bg-slate-100 …">`.

| Prop | Tipo | Padrão |
|---|---|---|
| `value` | `String` | — |
| `truncate` | `String` | `'none'` — `'none'` \| `'start'` \| `'end'` |
| `copyable` | `Boolean` | `false` — botão "Copiar" de 40px, com retorno "Copiado" por 2s |

`font-mono text-xs rounded-sm bg-surface-muted px-1.5 py-0.5 text-ink`.
Com `truncate`, o valor completo vai em `title` **e** num `<span class="sr-only">`.

---

#### `UiIcon.vue` — **NOVO** + `lib/icons.js` — **NOVO**
Hoje há três objetos de `path` SVG espalhados (`AppLayout`, `Dashboard`, prop `icon` do
`UiStat`) e o mesmo path de nuvem repetido literalmente em três arquivos.

| Prop | Tipo | Padrão |
|---|---|---|
| `name` | `String` | — chave do registro |
| `size` | `Number` | `16` |

Sempre `aria-hidden="true"` (o significado mora no texto ao lado).
`viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"`.

**Registro mínimo congelado em `lib/icons.js`** (nenhum ícone fora desta lista):
`dashboard, projects, catalog, redemptions, imports, benefits, users, companies, emails,
logout, menu, close, chevron-left, chevron-right, chevron-down, plus, upload, download,
search, more, check, alert, info, copy, gift, truck, clock, external`.

---

#### `UiEmpty.vue` — **REESCREVER**
A prop `inline` (que trocava a tag raiz entre `<tr>` e `<div>`) e a prop `colspan` **saem**:
o caso de tabela passa a ser o slot `#empty` do `UiTable`.

| Prop | Tipo | Padrão |
|---|---|---|
| `message` | `String` | — |
| `title` | `String` | `null` |
| `icon` | `String` | `null` — nome do registro |

**Slot:** `#action`. Todo estado vazio que tem ação disponível renderiza a ação **dentro dele**
(hoje `Products/Index` diz "clique em Importar da Omie" e o botão está no cabeçalho, fora do
campo de visão de quem lê a mensagem).

---

#### `UiSkeleton.vue` — **NOVO**
| Prop | Tipo | Padrão |
|---|---|---|
| `variant` | `String` | `'text'` — `'text'` \| `'block'` \| `'row'` |
| `lines` | `Number` | `3` |
| `columns` | `Number` | `4` (só em `row`) |

---

#### `UiPagination.vue` — **REESCREVER**
| Prop | Tipo | Padrão |
|---|---|---|
| `links` | `Array` | — `paginator->linkCollection()` |
| `label` | `String` | `'Resultados'` |

**Correções obrigatórias:**
- **Sem `v-html`.** Hoje o rótulo da navegação vem do backend em HTML (`&laquo; Anterior`).
  Derivar do array: item ativo = `link.active`; total = quantidade de itens numéricos;
  primeiro/último = "Anterior"/"Próxima", escritos no front.
- Renderizar `Página X de Y` à esquerda — calculável do próprio array, sem dado novo.
- `<nav aria-label="Paginação">`, `aria-current="page"` no ativo, `aria-disabled="true"` +
  `tabindex="-1"` nos extremos desabilitados.
- Janela de no máximo 5 números com elipse. Alvo mínimo 40px.

---

#### `ShipControl.vue` — **REESCREVER**
| Prop | Tipo | Padrão |
|---|---|---|
| `redemption` | `Object` | — |
| `canShip` | `Boolean` | `false` |

Deixa de renderizar formulário dentro da célula. Vira **uma célula com um botão** que abre o
`UiDrawer` de expedição. Detalhe em §10.7.

---

### 6.5 Composables

| Arquivo | API | Substitui |
|---|---|---|
| `useConfirm.js` | `const confirm = useConfirm(); await confirm({...})` | os 3 `window.confirm()` |
| `useToast.js` | `toast.success/warning/error(msg)` | push manual |
| `useRowAction.js` | `const { run, pending } = useRowAction(); run(id, url, opts); pending(id)` | o `useForm` compartilhado de `Imports/Show` (que hoje acende **todos** os botões da página) e os `router.post` soltos de `Admin/Users` |
| `useCepLookup.js` | `const { hint, state, loading, lookup } = useCepLookup(form); @blur="lookup()"` | o `cepState` duplicado em `Admin/Companies` |
| `useTabQuery.js` | `const tab = useTabQuery('aba', 'clientes')` — `ref` sincronizado com a querystring via `router.replace` sem recarregar | estado de aba compartilhável |

**`useRowAction` — contrato obrigatório:** estado indexado por id
(`reactive({})`), `pending(id)` devolve booleano daquele id, `run` bloqueia reentrada do
mesmo id. **Regra do sistema:** toda ação por linha usa `useRowAction`. Nunca
`form.processing` compartilhado.

---

### 6.6 `lib/format.js` — **ESTENDER**

Manter tudo que existe (`pipelineLabels`, `redemptionStatusLabels`, `importRowStatusLabels`,
`carrierLabels`, `formatDate`, `formatCnpj`) e acrescentar:

```js
export const importStatusLabels = {
    processing: 'Processando',
    completed: 'Concluída',
    completed_with_blocks: 'Concluída com bloqueios',
    failed: 'Falhou',
};

export const projectReleaseLabels = {
    released: 'Liberado',
    ready: 'Pronto para liberar',
    producing: 'Em ciclo produtivo',
};

/** Deriva a chave de projectRelease a partir das flags do projeto. */
export function releaseState({ released, stock_available }) {
    if (released) return 'released';
    return stock_available ? 'ready' : 'producing';
}

/**
 * Estado do prazo de entrega. Cor NUNCA sozinha: o texto carrega o significado.
 * @returns {{ label: string, tone: 'default'|'warning'|'danger' }|null}
 */
export function deadlineState(date) {
    if (!date) return null;
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const target = new Date(date); target.setHours(0, 0, 0, 0);
    const days = Math.round((target - today) / 86400000);
    if (days < 0) return { label: `Atrasado ${Math.abs(days)} dia${Math.abs(days) > 1 ? 's' : ''}`, tone: 'danger' };
    if (days === 0) return { label: 'Vence hoje', tone: 'warning' };
    if (days === 1) return { label: 'Vence amanhã', tone: 'warning' };
    return { label: `Em ${days} dias`, tone: 'default' };
}

export function formatCpf(value) {
    if (!value) return '';
    return String(value).replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
}

/** Primeiro nome — saudação do portal. */
export function firstName(name) {
    return String(name ?? '').trim().split(/\s+/)[0] ?? '';
}

export const UFS = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA',
    'PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
```

---

## 7. Mapa de navegação final, por perfil

A navegação é organizada por **entidade**, não por fluxo. O operador N1 alterna o dia inteiro
entre três tarefas (liberar Projeto, expedir resgate, destravar linha de importação), sendo
duas do Fluxo A e uma do B; navegar por fluxo obrigaria a duplicar Resgates dentro de A e
Linhas dentro de B, e forçaria o operador a saber a que fluxo um pedido pertence *antes* de
conseguir procurá-lo. A única exceção é **Importações Parceiros**, que é literalmente a entrada
do Fluxo B e não tem equivalente no A. Os rótulos "Fluxo A" e "Fluxo B" **desaparecem da
interface**.

### 7.1 Admin WeGift (`is_admin`)

```
WeLog
│
│ OPERAÇÃO
├─ Início                            dashboard
├─ Projetos                          projects.index
│  ├─ [Criar projeto manualmente]      projects.create      (botão, se canCreate)
│  └─ {Projeto}                      projects.show
│     ├─ Clientes finais (128)       ?aba=clientes   (padrão)
│     │  └─ [Adicionar clientes finais] → drawer: Um por um | Enviar planilha
│     ├─ Resgates (84)               ?aba=resgates
│     │  └─ [Expedir]                  → drawer de expedição
│     └─ Kits (2)                     ?aba=kits
├─ Resgates                          redemptions.index
│  └─ [Expedir] / [Marcar entregue]    → drawer de expedição
├─ Importações Parceiros               imports.index
│  ├─ [Nova importação]                → drawer (upload + formato aceito)
│  └─ {arquivo}                       imports.show
│     └─ linha bloqueada → [Reprocessar]
├─ Produtos                          products.index
│  └─ [Importar da Omie]               (botão no cabeçalho)
│
│ ADMINISTRAÇÃO
├─ Usuários WeGift                   admin.users.index
├─ Empresas cliente                  admin.companies.index
├─ Benefícios fiscais                benefits.index
└─ Modelos de e-mail                 admin.email-templates.index

  rodapé da sidebar → {Nome do usuário} ⌄ → Sair
```

### 7.2 Usuário WeGift N1 (`is_operator && can_critical && !is_admin`)

Idêntico, **sem o grupo ADMINISTRAÇÃO**. Executa tudo o mais.

### 7.3 Usuário WeGift N2 — somente consulta (`is_operator && !can_critical`)

**Estrutura de menu idêntica à do N1.** Decisão explícita: não se esconde destino do N2.
Ele precisa consultar tudo; esconder destino transformaria "não posso agir" em "não consigo
achar". O que some são as **ações**, já resolvidas pelo backend em `canCreate` / `canShip` /
`canImport` / `permissions.critical` / `can_reprocess`. Ver §9.8 para o que ele vê no lugar
de cada botão.

### 7.4 Admin Cliente (`is_client_admin`)

Shell **sem sidebar** — uma barra de navegação com um único item que está sempre ativo não é
navegação, e consome 20% da largura útil da tela.

```
WeLog  (topbar de 56px: marca à esquerda, menu de conta à direita)
└─ Projetos da sua empresa           projects.index
   └─ {Projeto}                      projects.show
      ├─ Clientes finais (128)         ← a tarefa dele
      │  └─ [Adicionar clientes finais]
      ├─ Resgates (84)                 (leitura; sem controle de expedição)
      └─ Kits (2)
```
A navegação dele é a hierarquia da página, ancorada pelo `back` do `PageHeader`.
**Não exibir o nome da empresa na topbar** — `auth.user` não carrega `company`.

### 7.5 Cliente Final (`is_end_customer`) — `PortalLayout`

```
WeLog
├─ Meu presente                      portal.catalog        ← home
│  └─ Endereço de entrega            portal.redeem.form    (2 passos)
└─ Entregas                          portal.redemptions
```

### 7.6 Tabela de renderização de cada item

| Item | Condição | Fonte |
|---|---|---|
| Início | `auth.user.is_operator` | `HandleInertiaRequests` |
| Projetos | `is_operator \|\| is_client_admin` | idem |
| Resgates, Importações Parceiros, Produtos | `is_operator` | idem |
| Grupo ADMINISTRAÇÃO (4 itens) | `is_admin` | idem |
| Shell mínimo (só topbar) | `is_client_admin` | idem |
| Portal (Meu presente / Entregas) | `is_end_customer` | idem |
| "Voltar para Projetos" no portal | `is_client_admin` | idem |
| Selo "Somente consulta" no rodapé | `is_operator && !can_critical` | idem |

Ações **nunca** entram nesta tabela. `canCreate`, `canShip`, `canImport`,
`permissions.critical`, `permissions.manage_customers`, `can_be_released` e `can_reprocess`
continuam decidindo botão a botão, exatamente como o backend já manda.

---

## 8. Layouts

### 8.1 `AppLayout.vue` — desktop (≥1024px)

```
┌──────────────────┬───────────────────────────────────────────────────────────┐
│ W  WeLog         │ ‹ Projetos                            [ Ação primária ]   │ 64px
│    por WeGift    │ Kit Boas-vindas Ambev                                     │ sticky
│                  │ Ambev · 500 kits · prazo 12/12/2026                       │
│ OPERAÇÃO         ├───────────────────────────────────────────────────────────┤
│ ▌Início          │ ┌───────────────┬──────────────┬────────┐                 │
│  Projetos        │ │Clientes finais│  Resgates 84 │ Kits 2 │                 │
│  Resgates        │ └───────────────┴──────────────┴────────┘                 │
│  Importações     │                                                           │
│   Suvinil        │  conteúdo — max-w-6xl, px-8                               │
│  Produtos        │                                                           │
│                  │                                                           │
│ ADMINISTRAÇÃO    │                                                           │
│  Usuários WeGift │                                                           │
│  Empresas cliente│                                                           │
│  Benefícios fisc.│                                                           │
│  Modelos de e-m. │                                                           │
│ ──────────────── │                                                           │
│ (YC) Yago C.  ⌄  │                                                           │
│  Somente consulta│  ← só quando is_operator && !can_critical                 │
└──────────────────┴───────────────────────────────────────────────────────────┘
      240px
```

**Requisitos:**
- `aside` com `w-sidebar` (240px). `<nav aria-label="Navegação principal">`.
- Dois grupos com rótulo `text-xs font-semibold uppercase tracking-wide text-ink-muted`,
  separados por `border-t border-line`. O grupo ADMINISTRAÇÃO só renderiza se `is_admin`.
- Item ativo: `bg-brand-50 text-brand-700`, barrinha `bg-brand-600` à esquerda,
  `aria-current="page"`. (Já está correto hoje — preservar.)
- Rodapé: bloco de conta vira **`UiMenu`** com nome, e-mail e "Sair". O logout sai do lugar
  de maior permanência (hoje é o único botão sempre visível na topbar mobile).
- Quando `is_operator && !can_critical`, exibir `UiBadge variant="neutral"` com
  **"Somente consulta"** logo abaixo do e-mail.
- **Primeiro filho focável do shell:** `<a href="#conteudo" class="sr-only focus:not-sr-only …">
  Pular para o conteúdo</a>`. O `<main id="conteudo" tabindex="-1">`.
- **`<UiConfirm />` e `<Toaster />` montados uma vez, no fim do layout.**
- **REMOVER:** o `<UiFlash only-errors>`, o `<div :key="$page.url">` e a classe
  `animate-fade-in-up`. O `:key` destrói e recria a página a cada mudança de URL, o que anula
  o `preserveState` da busca de `Products/Index` (o operador perde o foco do campo a cada Enter).

### 8.2 `AppLayout.vue` — mobile (<1024px)

```
┌────────────────────────────────────┐
│ [☰]  WeLog                         │ 56px — topbar
├────────────────────────────────────┤
│ ‹ Projetos                         │ PageHeader (não sticky abaixo de lg)
│ Kit Boas-vindas Ambev              │
│ ┌ Clientes 128 │ Resg. 84 │ Kits ┐ │ abas roláveis
├────────────────────────────────────┤
│  conteúdo, px-4                    │
└────────────────────────────────────┘

  [☰] abre UiDrawer side="left":
  ┌──────────────────────┐
  │ WeLog            [✕] │
  │ OPERAÇÃO             │
  │  Início              │ 48px por item
  │  Projetos            │
  │  Resgates            │
  │  Importações Parceiros │
  │  Produtos            │
  │ ADMINISTRAÇÃO        │
  │  Usuários WeGift     │
  │  Empresas cliente    │
  │  Benefícios fiscais  │
  │  Modelos de e-mail   │
  │ ──────────────────── │
  │ Yago C.              │
  │ yago@wegift.com.br   │
  │ Somente consulta     │
  │ Sair                 │
  └──────────────────────┘
```
O drawer renderiza **o mesmo array `nav`** da sidebar — uma fonte de verdade só.
**REMOVER:** a faixa `overflow-x-auto` de 9 itens no topo e o botão "Sair" da topbar.

### 8.3 `AppLayout.vue` — Admin Cliente (todas as larguras)

Quando `user.is_client_admin`, o mesmo arquivo renderiza um shell diferente — **sem duplicar
`UiConfirm`/`Toaster`**:

```
┌──────────────────────────────────────────────────────────────────┐
│ W WeLog                                        (YC) Yago C. ⌄    │ 56px
├──────────────────────────────────────────────────────────────────┤
│  ‹ Projetos                                                      │
│  Kit Boas-vindas 2026                                            │
│  ┌───────────────┬───────────┬──────┐                            │
│  │Clientes finais│ Resgates  │ Kits │                            │
│  └───────────────┴───────────┴──────┘                            │
│  conteúdo — max-w-5xl, px-8                                      │
└──────────────────────────────────────────────────────────────────┘
```
Sem sidebar, sem botão de menu, sem barra inferior: um destino não justifica navegação.

### 8.4 `PortalLayout.vue`

**Mobile (<640px)** — bottom nav de 2 alvos (exceção de C4):
```
┌────────────────────────────────────┐
│ [Ir para o conteúdo]  (só teclado) │
├────────────────────────────────────┤
│  W WeLog                    Yago ⌄ │ 56px — menu de conta 44×44
├────────────────────────────────────┤
│  conteúdo, 1 coluna, px-4          │
│  padding-bottom: 72px              │
├────────────────────────────────────┤
│  🎁 Meu presente │ 🚚 Entregas     │ 56px fixa + env(safe-area-inset-bottom)
└────────────────────────────────────┘   2 alvos de 50%, rótulo textual sempre visível
```

**Desktop (≥640px)** — nav à esquerda, colada à marca:
```
┌──────────────────────────────────────────────────────────────┐
│ W WeLog   [ Meu presente │ Entregas ]           Yago C. ⌄     │ 56px
├──────────────────────────────────────────────────────────────┤
│                  conteúdo — max-w-2xl (672px), centrado      │
└──────────────────────────────────────────────────────────────┘
```

**Requisitos:**
- Largura máxima cai de `max-w-4xl` (896px) para **`max-w-2xl` (672px)**. Não existe conteúdo
  aqui que justifique 900px, e a coluna estreita mantém desktop e mobile com a mesma hierarquia.
- Corpo em `text-base` (um degrau acima da área operacional) e controles `size="lg"` (48px).
- `<nav aria-label="Navegação do portal">` com `aria-current="page"` no item ativo.
- Menu de conta (`UiMenu`) com **nome e e-mail do usuário** (`auth.user`, hoje computado e
  descartado) e "Sair". Alvo 44px. O `<button>` de "Sair" solto de ~24×16px sai.
- Quando `user.is_client_admin`, o menu de conta ganha o item **"Voltar para Projetos"**
  apontando para `route('projects.index')` — hoje um Admin Cliente que caia em `/portal`
  fica sem saída.
- **REMOVER:** `<UiFlash only-errors>` (duplica cada erro de campo com o do `UiField`),
  `:key="$page.url"` e `animate-fade-in-up`.
- `<UiConfirm />` + `<Toaster />` montados aqui também.

### 8.5 `AuthCard.vue`

**REMOVER:** `.bg-dotted`, o `<div>` de 32rem com `blur-3xl`, o `backdrop-blur-sm` do cartão
e o gradiente do logo. Quatro efeitos de composição para um formulário de dois campos, num
aparelho de entrada.

**FICA:** `bg-mesh` sobre `bg-canvas`, cartão `rounded-lg border border-line bg-surface`,
`max-w-md`, `p-6 sm:p-8`. Controles `size="lg"`. Rodapé com alvo de 44px.

| Prop | Tipo | Padrão |
|---|---|---|
| `title` | `String` | — |
| `subtitle` | `String` | `null` |

**Slots:** default, `#footer`, **`#alert`** (novo — bloco no topo do cartão, acima do
formulário, para erro de token e aviso de "senha ainda não criada").

---

## 9. Padrões transversais

### 9.1 Formulário

```vue
<form class="space-y-4" @submit.prevent="submit">
  <UiFormSection title="Contato" :columns="2">
    <UiField label="E-mail de contato" :error="form.errors.contact_email"
             hint="Recebe os avisos de liberação">
      <UiInput v-model="form.contact_email" type="email"
               :invalid="!!form.errors.contact_email" autocomplete="email" />
    </UiField>
  </UiFormSection>

  <UiAlert v-if="form.errors.cnpj" variant="danger">{{ form.errors.cnpj }}</UiAlert>

  <div class="flex items-center gap-2">
    <UiButton type="submit" :loading="form.processing">Salvar empresa</UiButton>
    <UiButton variant="ghost" type="button" @click="cancel">Cancelar</UiButton>
  </div>
</form>
```

**Regras:**
1. **Um formulário, um `useForm`.** Nunca `router.post/put/delete` para escrita — é o
   `useForm` que dá `processing`, e é `processing` que impede o envio duplo.
2. Rótulo sempre visível, acima do campo. Placeholder só como exemplo de formato
   (`00.000.000/0000-00`), nunca no lugar do rótulo.
3. Uma coluna por padrão. Duas ou mais só para campos curtos da mesma família
   (número / complemento / UF). Nunca seis colunas.
4. Convenção de obrigatoriedade decidida **por seção**, nunca por campo: se a maioria é
   obrigatória, marca-se com `required` (asterisco `aria-hidden` + "(obrigatório)" sr-only);
   se a maioria é opcional, escreve-se "(opcional)" no rótulo dos opcionais.
5. Ações no rodapé, alinhadas ao início; primária à esquerda, "Cancelar" como `ghost` à
   direita. Rótulo é **verbo + objeto** ("Salvar empresa", "Criar e enviar convite"),
   nunca "OK" nem "Enviar".
6. Formulário longo vai em `UiDrawer`/`UiModal` ou tela própria — nunca expandido no meio
   de uma lista.
7. Ao abrir um painel de edição: `form.defaults({...})` → `form.reset()` → `form.clearErrors()`.
   Sem isso, o erro de um registro vaza para o próximo (defeito atual em `Admin/Users`).

### 9.2 Confirmação de ação crítica

Usar `useConfirm()` **sempre** para: liberar Projeto, desativar usuário, remover benefício
fiscal, restaurar modelo de e-mail, descartar alterações não salvas.

**Anatomia do texto:**
- **Título:** pergunta que nomeia o registro. `Liberar "Kit Natal 2026"?`
- **Corpo:** a consequência **quantificada** + o que **não** muda + irreversibilidade.
- **Botão:** repete o número/verbo. `Liberar e notificar 128 pessoas`.
- Foco inicial em Cancelar. `Esc` fecha. Se o backend recusar, o diálogo **fica aberto** com
  o erro dentro (`errorBag`).

### 9.3 Feedback — três destinos, sem sobreposição

| Tipo | Onde aparece | Componente |
|---|---|---|
| Sucesso | Toast, 6s, pausável | `Toaster` (automático via `flash.status`) |
| Erro de **campo** | Abaixo do campo, `role="alert"` | `UiField :error` |
| Erro de **domínio** (bag) | Dentro do painel / diálogo / linha que disparou | `UiAlert` |
| Resultado durável (lote, importação) | Bloco persistente que só fecha com clique | `UiAlert` + `UiRowErrors` |

**Uma mensagem, um lugar.** É proibido exibir a mesma mensagem em dois canais.

**Mapa completo de bags → destino** (cobertura verificável):

| Bag | Tela | Destino |
|---|---|---|
| `email` | `Auth/Login`, `Auth/SetPassword`, `Auth/ResetPassword` | `#alert` do `AuthCard` quando for erro de token/senha-não-criada; `UiField` quando for formato de e-mail |
| `release` | `Projects/Show` | dentro do `UiConfirm` de liberação |
| `entitlement` | `Projects/Show` | dentro do drawer "Um por um" |
| `batch` | `Projects/Show` | painel de resultado dentro do drawer "Enviar planilha", via `UiRowErrors` |
| `invite` | `Projects/Show`, `Admin/Users` | toast de aviso (ação sem formulário) |
| `ship` / `deliver` | `Redemptions/Index`, `Projects/Show` | dentro do drawer de expedição, que permanece aberto |
| `row` | `Imports/Show` | `UiAlert` ancorado **na própria linha** |
| `file` | `Imports/Index` | dentro do drawer de nova importação |
| `cnpj` | `Admin/Companies` | `UiAlert` dentro do drawer de cadastro |
| `role` / `active` | `Admin/Users` | dentro do drawer de edição / dentro do `UiConfirm` |
| `redeem` | `Portal/Redeem` | `UiAlert` acima do cartão, `role="alert"` (hoje não é renderizado em lugar nenhum da página) |

### 9.4 Tabela

- Coluna `#` com id do banco **sai de todas as listas**. A identificação primária é o nome do
  Projeto / do cliente final / do arquivo — que já são o link.
- Máximo **1 ação visível por linha** + `UiMenu` "⋯". A destrutiva mora dentro do menu,
  abaixo de um separador.
- Coluna numérica: `numeric: true` (aplica `text-right tabular-nums`).
- Toda ação por linha usa `useRowAction` (pendência por id).
- Abaixo de `md`, tabela com mais de 5 colunas vira lista empilhada de cartões
  (rótulo/valor), **nunca** rolagem em dois eixos.
- Toda tabela tem `caption` (sr-only) e `scope="col"`.

### 9.5 Paginação e listas longas

- Lista paginada pelo backend → `UiPagination :links`.
- Lista que o controller manda inteira (`project.entitlements`, `project.redemptions`,
  `companies`, `users`, `benefits`, `templates`) → **paginação client-side** de 25 itens
  com `UiPagination` alimentado por um array de links gerado no front, **ou** `UiTable maxRows`
  com rolagem interna, o que for mais adequado à tela (§10 especifica caso a caso).
  Nunca renderizar 500 `<tr>` de uma vez.
- Lista com mais de 15 itens ganha `UiSearchInput` client-side sobre o array já disponível
  nas props, com contador `mostrando 12 de 64`.

### 9.6 Estado vazio

Sempre com três partes: **o que não existe**, **por que** e **o que fazer**. A ação primária
renderiza **dentro** do bloco vazio (`#action`), nunca só no cabeçalho da página.

### 9.7 Upload

Sequência obrigatória, nos três pontos de upload do sistema:
`selecionar` → `mostrar nome + tamanho + [trocar]` → `clicar em enviar` → `barra de progresso
+ texto de etapa` → `bloco de resultado persistente`.
**Nunca** `@change="upload"`. Espera acima de 10s exige texto de etapa, não spinner mudo.

### 9.8 Permissão — o que o N2 (somente consulta) vê

Regra: onde o N1 vê botão, o N2 vê **texto de estado**. Nunca vazio, nunca `—`.
O `—` fica reservado a estados que realmente não têm ação (cancelado).

| Tela | N1 vê | N2 vê hoje | N2 **deve** ver |
|---|---|---|---|
| `Redemptions/Index` (confirmado) | botão "Expedir" | `—` | `Aguardando expedição` (`text-ink-muted`) |
| `Redemptions/Index` (cancelado) | `—` | `—` | `—` |
| `Imports/Show` (linha bloqueada) | botão "Reprocessar" | célula vazia | `Requer perfil N1` |
| `Projects/Show` | botão "Liberar…" | nada | `Aguardando liberação (perfil N1)` |
| `Projects/Show` | drawer de cadastro | nada | correto ocultar — é ferramenta, não estado |
| `Imports/Index` | drawer de importação | nada | correto ocultar |
| `Products/Index` | "Importar da Omie" | nada | correto ocultar |

Além disso: selo **"Somente consulta"** persistente no rodapé da sidebar (§8.1). Sem ele o N2
conclui que o sistema está quebrado.

---

## 10. Spec de cada tela

Formato de cada bloco: **Objetivo · Layout · Hierarquia · Props consumidas · Ações ·
Estados · Removido**. As props listadas são exatamente as que o controller envia hoje —
nada a mais, nada a menos.

---

### 10.1 `Dashboard.vue` → **"Início"**

**Objetivo.** Responder "o que precisa de mim agora" e levar até lá. Deixa de ser painel de
métricas e vira fila de trabalho.

**Layout (desktop; mobile empilha em uma coluna):**
```
Início

┌─ PRECISA DE VOCÊ ────────────────────────────────────────────────┐
│ ● 3   Projetos prontos para liberar               [ Ver todos → ]│
│       Kit Natal 2026 · Suvinil S/A                               │
│       Onboarding Ambev · Ambev                                   │
│       + 1 outro                                                  │
│ ─────────────────────────────────────────────────────────────────│
│ ● 47  Resgates aguardando expedição               [ Expedir   → ]│
│ ─────────────────────────────────────────────────────────────────│
│ ● 2   Linhas Suvinil bloqueadas                   [ Resolver  → ]│
│       Produto não cadastrado na Omie                             │
└──────────────────────────────────────────────────────────────────┘
   ↑ cada bloco é um <Link> inteiro, min-h 44px

Projetos por estágio                                    (faixa larga)
┌──────┬──────┬────────┬────────┬────────┬──────┬───────────┐
│Criado│Design│Produção│Picking │Packing │  NF  │ Concluído │
│  4   │  2   │   7    │   1    │   0    │  3   │    12     │
└──────┴──────┴────────┴────────┴────────┴──────┴───────────┘

Projetos recentes                                    [ Todos → ]
PROJETO / CLIENTE          ESTÁGIO       SITUAÇÃO
Kit Natal 2026             Produção      Pronto para liberar
Suvinil S/A

Importações Parceiros recentes                         [ Todas → ]
ARQUIVO                    CRIADOS  BLOQUEADAS  STATUS
resgates-07.xlsx             338         2      Concluída com bloqueios
```

**Hierarquia.** Primário = as 3 filas. Secundário = pipeline. Terciário = tabelas de recentes.

**Props consumidas.** `projectsByStatus: {status: int}` (usar `?? 0`); `stats: { awaitingRelease,
pendingRedemptions, blockedRows, totalProjects }`; `recentProjects[] { id, name, company,
pipeline_status, stock_available, released }`; `recentImports[] { id, filename, source,
rows_created, rows_blocked, status }`.

**Ações.** Nenhuma escrita. Só navegação:
`projects.index`, `redemptions.index`, `imports.index`, `projects.show(id)`, `imports.show(id)`.

Os nomes dentro do cartão de liberação vêm de
`recentProjects.filter(p => p.stock_available && !p.released).slice(0, 2)`; o
`+ N outros` = `stats.awaitingRelease - listados` (só renderiza se > 0).

**Estados.**
- Fila com valor `0` → estado neutro **sem cor de alerta**: "Nenhum projeto aguardando
  liberação", sem seta, sem link.
- `recentProjects` vazio → `#empty` do `UiTable`: "Nenhum projeto ainda. Eles nascem
  automaticamente do Pedido de Venda na Omie."
- `recentImports` vazio → "Nenhuma importação processada."
- Sem permissão: a rota já é `role:operator`; não há variação por perfil aqui.

**Removido.** O cartão "Resumo rápido" inteiro (repete literalmente os mesmos 4 números que
estão 300px acima); o indicador "Projetos no total" (métrica de vaidade — o total vai como
texto discreto no rodapé da lista de recentes); o subtítulo "Visão consolidada dos dois fluxos
operacionais"; os ícones decorativos e o `hover:-translate-y-0.5` dos indicadores; a coluna
"Origem" das importações recentes; o status cru em inglês (`completed_with_blocks`) →
`<StatusBadge kind="import">`; o H1 "Painel de acompanhamento" → **"Início"** (o item de menu
e a tela passam a ter o mesmo nome).

---

### 10.2 `Projects/Index.vue` → **"Projetos"**

**Objetivo.** Achar o projeto e ver, na varredura, quais exigem ação.

**Layout:**
```
Projetos                                    [ Criar projeto manualmente ]

PROJETO / CLIENTE            PRAZO             ESTÁGIO      SITUAÇÃO
─────────────────────────────────────────────────────────────────────
Onboarding Ambev             28/07/2026        NF Emitida   ● Pronto para
Ambev                        Atrasado 3 dias                  liberar
─────────────────────────────────────────────────────────────────────
Kit Natal 2026               12/12/2026        Produção     Em ciclo produtivo
Suvinil S/A                  Em 12 dias
─────────────────────────────────────────────────────────────────────
Kit Boas-vindas              05/08/2026        Concluído    Liberado
Fulano Ltda                  Em 5 dias

                                                 ‹ Anterior  1 2 3  Próxima ›
                                                 Página 1 de 3
```

**Hierarquia.** O nome do Projeto é o link e a âncora de leitura. "Situação" é a única badge
da linha, porque é ela que decide a ação; "Estágio" vira texto discreto.

**Props consumidas.** `projects: { data[], links[] }` com
`data[] { id, name, company, quantity, delivery_deadline, pipeline_status, stock_available,
released }`; `canCreate: bool`.

**Ações.** `Link` para `projects.show(id)`; se `canCreate`, `Link` para `projects.create`.

**Estados.**
- Vazio: "Nenhum projeto. Eles nascem automaticamente do Pedido de Venda na Omie." — **sem**
  ação no bloco vazio (não há ação disponível para o caso geral).
- `is_client_admin`: H1 vira **"Projetos da sua empresa"**, e a coluna "Cliente" **não
  renderiza** (é sempre a mesma empresa). Sem o botão de criar.
- Sem permissão de criar (N2): botão ausente — é ferramenta, não estado (§9.8).

**Removido.** Coluna `#` (id do banco na posição de maior atenção); coluna "Qtde" (não decide
nada aqui, está no detalhe); o subtítulo "Fluxo A — kits corporativos sob demanda"; a
duplicação Estágio + Situação como duas badges. Rótulo do botão: "+ Onboarding manual" →
**"Criar projeto manualmente"**.

**Novo.** O prazo ganha segunda linha com `deadlineState()`: `Em 12 dias` (`text-ink-muted`),
`Vence amanhã` (`text-warning`), `Atrasado 3 dias` (`text-danger font-medium`). A palavra
carrega o significado — cor nunca sozinha.

---

### 10.3 `Projects/Show.vue` → **{nome do Projeto}**

**Objetivo.** Ver o estado do Projeto, liberar quando puder, cadastrar clientes finais e
acompanhar resgates — sem rolar por conteúdo que não é da tarefa atual.

**Layout (desktop):**
```
‹ Projetos
Kit Natal 2026                              [ Liberar e notificar 128 pessoas ]
Suvinil S/A · 12.345.678/0001-90 · Pedido Omie 45231 · 500 kits · prazo 12/12/2026
                                                                    [ card Trello ↗ ]
┌─────────────────────────────────────────────────────────────────────────────┐
│ Estágio: Produção     Estoque na Omie: Disponível     Liberação: não liberado│
└─────────────────────────────────────────────────────────────────────────────┘

┌ Clientes finais (128) │ Resgates (34) │ Kits (2) ┐
└───────────────────────┴───────────────┴──────────┘

  [ Adicionar clientes finais ▾ ]         🔍 [ nome ou e-mail        ]  128 pessoas

  NOME / E-MAIL                    KIT              SITUAÇÃO            ⋯
  ─────────────────────────────────────────────────────────────────────────
  Ana Souza                        Kit Executivo    Resgatou            ⋯
  ana@suvinil.com.br                                notificada 12/07
  ─────────────────────────────────────────────────────────────────────────
  Bruno Lima                       Kit Padrão       Acesso pendente     ⋯
  bruno@suvinil.com.br                              ainda não notificado
  ─────────────────────────────────────────────────────────────────────────
                                                     ‹ 1 2 3 … 6 ›
```

**Hierarquia.** Primário = identidade + estado + a ação de liberar. Secundário = as 3 abas.
Sob demanda = cadastros (drawer), composição do kit (aba Kits), detalhes por linha.

**Props consumidas.**
`project { id, name, company{name,cnpj}, omie_order_number, quantity, delivery_deadline,
pipeline_status, trello_card_url, stock_available, released, released_at, can_be_released,
products[]{id,omie_code,name,quantity,components[]{name,quantity}},
entitlements[]{id,user{name,email},product,notified_at,redeemed,access_pending},
redemptions[]{id,user,product,status,carrier,tracking_code,delivered_at,address} }`;
`kits[]{id,name}`; `permissions{critical, manage_customers}`.

**Abas.** `useTabQuery('aba', 'clientes')` → `?aba=clientes | resgates | kits`.
Os dados já vêm todos nas props: **nenhuma requisição nova**. A aba Resgates só monta quando
ativa (corta o DOM inicial pela metade).

**Ação 1 — Liberar (bag `release`).** Três estados, sempre visível (P2):

| Condição | Botão | Texto ao lado |
|---|---|---|
| `can_be_released && entitlements.length > 0` | **ativo** — `Liberar e notificar {N} pessoas` | — |
| `!stock_available` | desabilitado | "A Omie ainda não sinalizou estoque disponível para este Projeto." |
| `stock_available && entitlements.length === 0` | desabilitado | "Cadastre ao menos um cliente final antes de liberar." |
| `released` | ausente; no lugar, bloco de estado | "Liberado em 12/07/2026 · 128 pessoas notificadas" |
| `!permissions.critical` (N2) | ausente | "Aguardando liberação (perfil N1)" |

`UiConfirm`:
- título `Liberar "{project.name}"?`
- corpo `{N} pessoas receberão o e-mail de resgate agora.`
- aviso (se houver) `{M} dessas pessoas ainda não criaram a senha de acesso.` —
  `entitlements.filter(e => e.access_pending).length`
- `Esta ação não pode ser desfeita. Pessoas cadastradas depois da liberação não recebem o
  e-mail automaticamente.`
- botão `Liberar e notificar {N} pessoas`; `errorBag: 'release'`.

**Ação 2 — Adicionar clientes finais** (`permissions.manage_customers`). Botão no cabeçalho da
aba abre `UiDrawer` com `UiTabs segmented`: **"Um por um"** | **"Enviar planilha"**.

*Um por um* (bag `entitlement`) — POST `projects.entitlements.store`, payload
`{ name, email, cpf, phone, product_id }`. `product_id` vem de `kits`. Erro do bag renderiza
dentro do drawer, acima do botão.

*Enviar planilha* (bag `batch`) — fluxo numerado de 3 passos:
```
┌ Enviar planilha de clientes finais ───────────────────────  ✕ ───┐
│  1  Baixe o modelo                                               │
│     [ ⬇ Baixar modelo (.xlsx) ]   ← BOTÃO primário, não link     │
│     Já vem com o cabeçalho e um exemplo preenchido.              │
│                                                                  │
│  2  Preencha                                                     │
│     Obrigatórias:  nome · email · codigo_kit                     │
│     Opcionais:     cpf · telefone                                │
│     Códigos de kit válidos NESTE projeto:                        │
│     ┌───────────────┬──────────────────────────┬────┐            │
│     │ KIT-NATAL-01  │ Kit Natal Executivo      │ 📋 │            │
│     │ KIT-NATAL-02  │ Kit Natal Padrão         │ 📋 │            │
│     └───────────────┴──────────────────────────┴────┘            │
│                                                                  │
│  3  Envie                                                        │
│     [ UiFileDrop ]   arquivo: lista-natal.xlsx · 42 KB [trocar]  │
│                                    [ Cancelar ] [ Importar ]     │
└──────────────────────────────────────────────────────────────────┘
```
A tabela de códigos sai de `project.products` (`omie_code` + `name`), que **já está nas
props** — mata o vai-e-volta de rolagem até a tabela de kits para memorizar o código.
`GET projects.entitlements.template` é `<a href>` (download, não visita Inertia).

*Resultado do lote* — bloco persistente **dentro do drawer**, que só fecha com clique:
```
┌ Resultado da importação ──────────────────────────────────  ✕ ───┐
│   ✓ 116 cadastrados            ✕ 12 não cadastrados              │
│   LINHAS QUE FALHARAM                        ← UiRowErrors       │
│   Linha 4   e-mail inválido: joao@                               │
│   Linha 17  codigo_kit "KIT-99" não existe                       │
│   Linha 23  e-mail já cadastrado neste projeto                   │
│   Corrija apenas essas linhas e envie de novo. As 116 já         │
│   cadastradas não serão duplicadas.                              │
│                                    [ Enviar planilha corrigida ] │
└──────────────────────────────────────────────────────────────────┘
```
Hoje esse caso devolve **dois sinais opostos**: um toast verde de 4,5s ("116 cadastrados") e
uma linha vermelha de texto corrido no topo da página, fora da viewport. O painel unifica.

**Ação 3 — Reenviar convite** (bag `invite`). Item do `UiMenu` da linha, via `useRowAction`.
Erro vira toast (é ação sem formulário).

**Ação 4 — Expedir / Marcar entregue.** Na aba Resgates, mesmo drawer de §10.7.

**Tabela de clientes finais.** De 6 colunas para 4: `Nome / E-mail` (duas linhas), `Kit`,
`Situação`, `Ações`. A coluna **Situação** funde `Acesso`, `Notificado` e `Resgatou?` com
precedência: `Resgatou` > `Acesso pendente` > `Notificada em {data}` > `Ainda não notificada`,
com a data como texto secundário. Paginação client-side de 25 + `UiSearchInput` sobre
`nome`/`e-mail` (o controller manda a lista inteira).

**Aba Kits.** A tabela atual (`Código`, `Produto`, `Qtde`, `Composição`) permanece, com
`omie_code` em `UiCode`.

**Estados.**
- Aba Clientes finais vazia: "Nenhum cliente final cadastrado ainda." + botão "Adicionar
  clientes finais" dentro do bloco (se `manage_customers`).
- Aba Resgates vazia: "Nenhum resgate confirmado ainda. Eles aparecem aqui conforme os
  clientes finais informam o endereço."
- Carregando (envio de planilha): `UiFileDrop :progress` + texto de etapa.
- Sem permissão (`is_client_admin`): ver abaixo.

**Removido.**
- O botão "← Projetos" do canto superior **direito** → vira `back` do `PageHeader`, à esquerda.
- Os 4 `UiStat` de 30px → faixa de metadados de uma linha no `PageHeader`.
- O cartão da empresa como bloco separado → funde na faixa de metadados.
- Os dois cartões de cadastro sempre abertos (~500px de altura permanente) → drawer.
- As colunas separadas "Acesso", "Notificado", "Resgatou?" → coluna "Situação".
- Os `<h2 class="text-sm">` soltos → abas.
- O `confirm()` nativo da liberação.
- **Para `is_client_admin`, saem também:** o número do Pedido de Venda na Omie, o link do card
  Trello e o item "Estoque na Omie" da faixa de metadados. São dados do ERP e da ferramenta
  interna de produção da WeGift, e não mudam nenhuma decisão dele. Envolver os três em
  `v-if="permissions.critical"`. A faixa dele fica com Quantidade · Prazo · Estágio ·
  Situação de liberação.

---

### 10.4 `Projects/Create.vue` → **"Criar projeto manualmente"**

**Objetivo.** Fallback quando o Pedido de Venda não chegou da Omie.

**Layout.** Uma coluna, `max-w-2xl`. A forma atual está quase certa; os ajustes são pontuais.

**Props consumidas.** `products[] { id, omie_code, name }`.

**Ações.** POST `projects.store` com
`{ company_name, company_cnpj, name, quantity, delivery_deadline, product_ids[] }`.
Erros de campo em `UiField`.

**Mudanças.**
- Subtítulo: "Fallback para quando a comunicação com a Omie falhar" → **"Use apenas quando o
  Pedido de Venda não chegou da Omie. Normalmente os Projetos nascem sozinhos."**
  (explica a consequência, não a causa).
- A caixa `max-h-56` de checkboxes vira `<UiField as="group" label="Kits/produtos do pedido">`
  + `<UiCheckboxGroup searchable :max-height="'14rem'">`. Isso corrige **HTML inválido**
  (hoje são 40 `<label>` dentro de um `<label>`, e clicar no nome do produto pode não marcar
  o checkbox) e adiciona a busca que faltava. Contador "3 kits selecionados" abaixo da caixa.
- Botão: "Criar Projeto" → **"Criar Projeto e abrir card no Trello"** (é o que de fato acontece).
- `company_cnpj` com `placeholder="00.000.000/0000-00"`, `inputmode="numeric"`.

**Estados.** `products` vazio → `UiEmpty` no lugar do grupo: "Nenhum produto no catálogo.
Importe o catálogo da Omie em Produtos antes de criar o Projeto." + link para `products.index`.

---

### 10.5 `Products/Index.vue` → **"Produtos"**

**Objetivo.** Consultar o catálogo espelhado da Omie e trazer produtos novos — que é o
caminho de correção das linhas bloqueadas do Fluxo B.

**Layout:**
```
Produtos                                          [ Importar da Omie ]
Catálogo espelhado da Omie

🔍 [ nome ou código                    ]         240 produtos · 18 kits

CÓDIGO      PRODUTO                    TIPO              ESTOQUE  SINCRONIZADO
SUV-1023    Tinta Suvinil 18L          Kit · 4 itens        120   30/07/2026
```

**Props consumidas.** `products` (paginator completo — única tela assim: tem `total`,
`current_page`, `per_page`); `filters { q }`; `summary { total, kits }`; `canImport: bool`.

**Ações.**
- Busca: `router.get(route('products.index'), { q }, { preserveState, preserveScroll, replace })`
  via `UiSearchInput` com debounce de 300ms. **Funciona corretamente só depois de remover o
  `:key="$page.url"` do `AppLayout`** — hoje a página é remontada a cada Enter e o operador
  perde o foco do campo.
- POST `products.import` (`canImport`). Job assíncrono.

**Estados.**
- **Importando (novo, obrigatório):** ao voltar do POST, botão em `Importando…` e
  `router.reload({ only: ['products', 'summary'] })` em polling de 5s por até 60s, parando
  assim que `summary.total` mudar. `UiAlert variant="info"` persistente:
  **"Buscando produtos na Omie. A lista se atualiza sozinha nos próximos minutos."**
  com `#actions` → botão "Atualizar agora". Hoje a tela promete que "os produtos aparecem em
  instantes" e nunca muda, então o operador clica de novo e enfileira outro job.
- Vazio sem busca: "Nenhum produto no catálogo." + botão "Importar da Omie" **dentro** do
  bloco vazio (`#action`).
- Vazio com busca: "Nenhum produto para \"{q}\"." + botão "Limpar busca".
- N2: sem o botão de importar.

**Removido.** As badges de resumo ("N produtos" / "N kits") acima da tabela → viram texto no
`resultLabel` da busca. A busca sai da borda **direita** e vai para a esquerda (é a ação mais
usada da tela).

---

### 10.6 `Redemptions/Index.vue` → **"Resgates"**

**Objetivo.** Expedir a fila de resgates confirmados com o mínimo de interações.

**Layout:**
```
Resgates

CLIENTE FINAL / DESTINO       KIT              EMPRESA        SITUAÇÃO      AÇÃO
──────────────────────────────────────────────────────────────────────────────────
Ana Souza                     Kit Executivo    Suvinil S/A    Confirmado  [Expedir] ⋯
Curitiba/PR
──────────────────────────────────────────────────────────────────────────────────
Bruno Lima                    Kit Padrão       Ambev          Em          [Marcar    ⋯
São Paulo/SP                                                  transporte   entregue]
                                                              MRB · BR123456789BR
──────────────────────────────────────────────────────────────────────────────────
Carla Dias                    Kit Padrão       Suvinil S/A    Entregue    —          ⋯
Recife/PE                                                     05/08/2026
```

**Props consumidas.** `redemptions { data[], links[] }` com
`data[] { id, user, city, product, project{id, company}, status, carrier, tracking_code,
delivered_at }`; `canShip: bool`.
⚠️ Aqui **não** vem `address` (isso só existe no shape de `Projects/Show`); vem `city`.

**Ações.** Ver §10.7 (drawer de expedição). `UiMenu` da linha: **"Abrir projeto"** →
`projects.show(r.project.id) + '?aba=resgates'` (cai direto na aba certa).

**Estados.**
- Vazio: "Nenhum resgate confirmado."
- N2 (`!canShip`): coluna AÇÃO mostra `Aguardando expedição` em `text-ink-muted` para
  `confirmed`, `Em transporte` para `shipped`, e `—` só para `canceled`.

**Removido.** Coluna `#`; o subtítulo "Fluxo A — expedição e acompanhamento"; o `<select>` +
`<input>` de rastreio dentro da célula (até **75 controles de formulário** simultâneos numa
página de 25 linhas, e a razão pela qual a tabela estoura horizontalmente).
A coluna "Projeto" é **renomeada para "Empresa"** e perde o link: o rótulo dizia "Projeto",
o conteúdo era o nome da empresa e o destino era um projeto específico — três coisas
diferentes na mesma célula.

---

### 10.7 Drawer de expedição (usado por `Redemptions/Index` e pela aba Resgates de `Projects/Show`)

Componente: `ShipControl.vue` (reescrito) — a célula renderiza **um botão**; o drawer tem o
formulário.

```
┌ Expedir resgate ───────────────────────────────────────  ✕ ───┐
│ Ana Souza · Curitiba/PR                                       │  ← contexto
│ Kit Executivo                                                 │
├───────────────────────────────────────────────────────────────┤
│ Transportadora *                                              │
│ ┌────────────┐ ┌────────────┐ ┌────────────┐                  │
│ │  Correios  │ │    MRB     │ │  DDM Log   │   ← rádio grande │
│ └────────────┘ └────────────┘ └────────────┘      48px        │
│                                                               │
│ ▸ com Correios:                                               │
│   O código de rastreio é gerado automaticamente.              │
│                                                               │
│ ▸ com MRB ou DDM Log:                                         │
│   Código de rastreio *                                        │
│   [                                        ]                  │
│   Informe o código fornecido pela transportadora.             │
│                                                               │
│ [ UiAlert: erro do bag `ship` aparece AQUI ]                  │
├───────────────────────────────────────────────────────────────┤
│                        [ Cancelar ]  [ Expedir e avisar ]     │
└───────────────────────────────────────────────────────────────┘
```

**Regras.**
- Rádio segmentado no lugar do `<select>`: corta 2 cliques para 1 e mostra as opções sem abrir nada.
- O campo de rastreio **só renderiza** quando `carrier` é `mrb` ou `ddm`. Hoje ele fica sempre
  visível, inclusive com Correios, onde o valor digitado é **ignorado** (a API gera o código).
- Botão desabilitado enquanto `['mrb','ddm'].includes(carrier) && !tracking_code`, com o motivo
  ao lado. É **guarda de interface**: a validação do backend continua `nullable` e não muda.
- Rótulo do botão nomeia a consequência: **"Expedir e avisar"** (dispara e-mail ao cliente).
- POST `redemptions.ship` com `{ carrier, tracking_code }`. Erro no bag `ship` → `UiAlert`
  dentro do drawer, **que permanece aberto**.

**Marcar entregue.** Só aparece para `status === 'shipped'` e `carrier` manual (`mrb`/`ddm`).
Botão "Marcar entregue" → `UiConfirm` (`Marcar como entregue o resgate de Ana Souza?` /
`O cliente final recebe o e-mail "Pedido entregue!". Esta ação não pode ser desfeita.` /
botão `Marcar entregue`) → POST `redemptions.delivered`, sem payload. Bag `deliver`.

**Contagem de interações:** Correios de 5 → 2 (abrir + confirmar). Manual de 5 → 4.

---

### 10.8 `Imports/Index.vue` → **"Importações Parceiros"**

**Objetivo.** Enviar a planilha de resgates e ver o desfecho de cada lote.
⚠️ Cada linha válida cria um **Pedido de Venda na Omie** — efeito fora do sistema, irreversível
pela interface. A tela precisa dizer isso.

**Layout:**
```
Importações Parceiros                              [ Nova importação ]
Planilhas de resgate processadas — pedidos criados na Omie automaticamente

ARQUIVO                          CRIADOS  BLOQUEADAS  STATUS
────────────────────────────────────────────────────────────────────────
resgates-suvinil-07.xlsx            338        2      Concluída com bloqueios
enviado por Ana · 30/07/2026 14:22
────────────────────────────────────────────────────────────────────────
resgates-suvinil-06.xlsx            412        0      Concluída
automático (pasta monitorada) · 01/07/2026 03:10
```

**Drawer "Nova importação"** (`canImport`, bag `file`):
```
┌ Nova importação ──────────────────────────────────────  ✕ ───┐
│ ⚠ Cada linha válida gera um Pedido de Venda na Omie.         │
│   Arquivos já importados são recusados automaticamente.      │
│                                                              │
│ [ UiFileDrop ]                                               │
│ arquivo: resgates-07.xlsx · 340 KB  [trocar]                 │
│                                                              │
│ ▸ Ver formato aceito                        (<details>)      │
│   cpf, nome, email, telefone, codigo_produto, produto,       │
│   quantidade, cep, endereco, numero, complemento, bairro,    │
│   cidade, uf                                                 │
│   A pasta monitorada é consumida automaticamente a cada      │
│   10 minutos.                                                │
│                                                              │
│ [ UiAlert: erro do bag `file` aqui ]                         │
├──────────────────────────────────────────────────────────────┤
│                [ Cancelar ]  [ Importar e criar pedidos ]    │
└──────────────────────────────────────────────────────────────┘

durante o envio, o drop zone vira:
┌──────────────────────────────────────────────────────────────┐
│ ▓▓▓▓▓▓▓▓░░░░░░░░  Criando pedidos na Omie…                   │
│ Pode levar alguns minutos — não feche esta aba.              │
└──────────────────────────────────────────────────────────────┘
```
O processamento roda **dentro do request** e pode levar minutos. Hoje o único feedback é
`:disabled` num `<input type="file" class="hidden">` — ou seja, **nada visível**. A barra de
progresso + texto de etapa é obrigatória.

**Props consumidas.** `imports { data[], links[] }` com
`data[] { id, filename, source, imported_by, rows_total, rows_created, rows_blocked, status,
created_at }`; `canImport: bool`.

**Ações.** POST `imports.store` multipart `{ file }`. Sucesso redireciona para `imports.show`.

**Estados.**
- Vazio: "Nenhuma importação processada." + botão "Nova importação" no bloco (se `canImport`).
- Lote com `status === 'processing'` na lista: linha com indicador pulsante e
  `router.reload({ only: ['imports'] })` a cada 10s **enquanto existir** algum em processamento.
- N2: sem o botão.

**Removido.** Colunas `#`, "Origem", "Importado por" e "Linhas" — as três últimas viram a
linha secundária sob o nome do arquivo ("enviado por Ana · data" / "automático (pasta
monitorada) · data"), e o total cabe no `#hint`. O bloco permanente com as 14 colunas aceitas
(informação necessária uma vez, exibida todas as vezes) → `<details>` dentro do drawer.
O status cru em inglês → `<StatusBadge kind="import">`.
**Nome único:** menu, `<Head>`, `<h1>` e breadcrumb passam todos a dizer
**"Importações Parceiros"**. Os termos "appnoz" e "Pintou Parceria" saem da interface (podem
ficar dentro do `<details>` de formato, se necessário para suporte).

---

### 10.9 `Imports/Show.vue` → **{nome do arquivo}**

**Objetivo.** Destravar as linhas bloqueadas. É a única razão de a tela existir.

**Layout:**
```
‹ Importações Parceiros
resgates-suvinil-07.xlsx                        Concluída com bloqueios
340 linhas · 338 pedidos criados · 2 bloqueadas

┌ ⛔ 2 de 340 linhas bloqueadas ────────────────────────────────────┐
│ Motivo: o produto não está cadastrado na Omie.                    │
│ É a única condição que impede a criação do Pedido de Venda.       │
│                                                                   │
│ Como corrigir:                                                    │
│  1. Cadastre o produto na Omie                                    │
│  2. Atualize o catálogo aqui  → [ Produtos · Importar da Omie ]   │
│  3. Volte e clique em Reprocessar na linha                        │
└───────────────────────────────────────────────────────────────────┘

[ ● Só bloqueadas (2) ] ( Todas 50 )              🔍 [ CPF, nome ou produto ]

LINHA  CLIENTE                PRODUTO              SITUAÇÃO           AÇÃO
──────────────────────────────────────────────────────────────────────────────
  17   Ana Souza              PROD-99              Produto não        [Reprocessar]
       111.222.333-44         (não encontrado)     cadastrado na Omie
       ▸ detalhes
──────────────────────────────────────────────────────────────────────────────
  18   Bruno Lima             PROD-12              Pedido criado      —
       222.333.444-55         Tinta Suvinil 18L
       ▾ detalhes
         Qtde 2 · Pedido Omie 90231 · Benefício BF-04
         NF …a91f7c22   XML enviado à Flash
         Rastreio Flash FL9928311BR
```

**Hierarquia.** Primário = o bloco de bloqueio e como sair dele. Secundário = contadores e
filtro. Sob demanda = os campos técnicos.

**Props consumidas.** `importItem { id, filename, status, rows_total, rows_created,
rows_blocked }`; `rows { data[], links[] }` com
`data[] { id, row_number, customer_name, cpf, customer_auto_created, product_code,
product_name, quantity, omie_order_number, benefit_code, nf_key, nf_xml_sent, tracking_code,
status, block_reason, can_reprocess }`.
⚠️ A prop é `importItem`, não `import` (palavra reservada em JS).

**Ações.** POST `imports.rows.reprocess(rowId)` via **`useRowAction`**.
Hoje um `useForm` é compartilhado pela tela inteira e `:loading="reprocessForm.processing"`
acende **todos** os botões de todas as linhas ao mesmo tempo.

**Desfecho do reprocesso.** O backend devolve os dois resultados por `flash.status` — ou seja,
"A linha continua bloqueada (produto ainda ausente na Omie)." chega pelo mesmo canal do
sucesso e é pintada de verde com ícone de check. Correção **no front**:
1. `Toaster.classify()` reconhece a mensagem e usa a variante de aviso (§6.3).
2. `onSuccess`, se a linha continuar bloqueada, renderizar `UiAlert variant="warning"`
   **ancorado na própria linha**: "Continua bloqueada: o produto ainda não existe na Omie."
   com link direto para `products.index`.

**Estados.**
- `rows_blocked > 0` → o chip "Só bloqueadas" já vem **ligado** por padrão.
- Filtro client-side sobre `rows.data`: rotular explicitamente que atua na página corrente
  ("filtrando as 50 linhas desta página").
- Vazio: "Sem linhas nesta importação."
- N2 (`can_reprocess === false`): coluna AÇÃO mostra **"Requer perfil N1"** em
  `text-ink-muted`, nunca célula em branco.

**Removido.** Da tabela principal, as colunas "Qtde", "Pedido Omie", "Benefício", "NF" e
"Rastreio Flash" — de **10 para 5** colunas, o que acaba com a rolagem horizontal que hoje
joga a coluna de ação para fora da tela. Todas vão para o expansor "▸ detalhes" da linha
(os dados já vêm todos na prop; nada é descartado).
Os 4 `UiStat` do topo → faixa de uma linha. O cartão com o status cru em inglês.
O botão "← Importações" do canto direito → `back` do `PageHeader`.
**Contraste:** `block_reason` sai de `text-xs text-slate-400` (2,5:1, reprovado em WCAG AA)
para `text-sm text-danger` sobre `bg-danger-soft`. É o texto que decide a ação e hoje é o
menos legível da tela.

---

### 10.10 `Benefits/Index.vue` → **"Benefícios fiscais"**

**Objetivo.** Manter a regra "código de produto → código de benefício" aplicada nos pedidos
das Importações Parceiros.

**Layout:**
```
Benefícios fiscais                                    [ Nova regra ]
Cada código de produto recebe um código de benefício quando o pedido é
criado na Omie (Importações Parceiros).

🔍 [ código de produto, benefício ou descrição ]                 18 regras

PRODUTO NA OMIE   BENEFÍCIO   DESCRIÇÃO                            ⋯
SUV-1023          PR001       Tinta acrílica 18L                   ⋯
SUV-1044          PR001       Tinta acrílica 3,6L                  ⋯
```
`UiMenu`: **Editar regra** · ─── · **Remover regra** (danger).

**Props consumidas.** `benefits[] { id, product_code, benefit_code, description }` (sem paginação).

**Ações.**
- POST `benefits.store` `{ product_code, benefit_code, description }` — **é `updateOrCreate`
  por `product_code`**. O formulário rotulado como criação sobrescreve regras existentes em
  silêncio. Correção **só de interface**, com o array `benefits` que já está nas props:
  enquanto o usuário digita, se `product_code` já existir, mostrar dentro do drawer
  `UiAlert variant="warning"`: **"Já existe uma regra para SUV-1023 (benefício PR001).
  Salvar substitui a regra atual."** e o botão passa a se chamar **"Substituir regra"**.
- "Editar regra" abre o mesmo drawer com `product_code` **somente leitura** — editar deixa de
  ser efeito colateral de criar.
- DELETE `benefits.destroy(id)` via `UiConfirm`:
  título `Remover a regra do produto SUV-1023?`
  corpo `A partir de agora, os pedidos criados na Omie para SUV-1023 vão sem o benefício
  PR001. As importações já processadas não mudam.`
  botão `Remover regra`, `variant: 'danger'`.

**Estados.** Vazio: "Nenhuma regra cadastrada. Sem regra, os pedidos das Importações Parceiros
são criados na Omie sem benefício fiscal." + botão "Nova regra" dentro do bloco.

**Removido.** O formulário sempre aberto no topo; o botão "Remover" exposto em toda linha;
o `confirm()` nativo de 3 palavras que não diz qual registro está sendo apagado; o `<code>`
ad hoc → `UiCode`; "Fluxo B" do subtítulo.

---

### 10.11 `Admin/Users/Index.vue` → **"Usuários WeGift"**

**Objetivo.** Saber quem da equipe entra no sistema e o que cada um pode fazer.

**Layout:**
```
Usuários WeGift                                    [ Novo usuário ]
Quem da equipe entra no sistema e o que cada um pode fazer.

🔍 [ nome ou e-mail ]                                       12 usuários
( Todos ) ( Ativos 9 ) ( Convite pendente 2 ) ( Inativos 1 )

PESSOA                        PERFIL                    SITUAÇÃO         ⋯
────────────────────────────────────────────────────────────────────────────
Ana Prado                     Admin WeGift              Ativo            ⋯
ana@wegift.com.br             acesso total
────────────────────────────────────────────────────────────────────────────
Bruno Lima                    Operador N1               Convite pendente ⋯
bruno@wegift.com.br           opera e libera
────────────────────────────────────────────────────────────────────────────
Caio Reis        (esmaecido)  Operador N2               Inativo          ⋯
caio@wegift.com.br            somente consulta
```
`UiMenu`: **Editar** · **Reenviar convite** (só se `access_pending`) · ─── ·
**Desativar acesso** / **Ativar acesso** (danger).

**Props consumidas.** `users[] { id, name, email, role, active, access_pending }`;
`roles[] { value, label }`.

**Drawer (criar / editar).** Cabeçalho identifica o registro: `Novo usuário` ou
`Bruno Lima · bruno@wegift.com.br`.

O perfil deixa de ser `<select>` e vira **três opções em rádio com a consequência escrita**:
```
O que essa pessoa poderá fazer? *
( ) Admin WeGift
    Tudo, incluindo usuários, empresas, modelos de e-mail e benefícios fiscais
( ) Operador N1
    Cria projetos, libera resgates e importa planilhas
( ) Operador N2
    Somente consulta. Não libera projeto nem importa planilha
                                                    (nenhuma pré-marcada)
```
Hoje o `createForm` nasce com `role: 'wegift_n1'` — o caminho de menor esforço (nome, e-mail,
Enter) concede o perfil operacional pleno. Numa ação feita poucas vezes por ano, forçar a
escolha consciente custa um clique.
Na **edição**, o e-mail aparece como texto fixo com a nota "o e-mail não pode ser alterado"
(hoje o campo simplesmente não existe e o admin não sabe se é limitação ou esquecimento).

**Ações e bags.**
- POST `admin.users.store` `{ name, email, role }` → drawer; erros de campo em `UiField`.
- PUT `admin.users.update(id)` `{ name, role }` → drawer; bag **`role`** dentro do drawer.
- POST `admin.users.toggle(id)` → `UiConfirm` com `errorBag: 'active'`:
  `Desativar o acesso de Bruno Lima?` /
  `Ele deixa de conseguir entrar no WeLog no próximo login. Os projetos e resgates que ele
  tocou continuam no sistema. Você pode reativar depois.` / botão `Desativar acesso`.
  Se o backend recusar (auto-desativação), a mensagem aparece **dentro do diálogo**, aberto.
- POST `admin.users.resend(id)` via `useRowAction`; bag `invite` → toast de aviso.

**Estados.** Vazio: "Nenhum usuário WeGift." + botão no bloco.
Linha inativa: **linha inteira esmaecida** (`text-ink-muted`), não apenas uma badge cinza.

**Removido.** O cartão "Novo usuário" sempre aberto no topo; a **edição inline** (que troca
duas células por controles de tamanhos diferentes e desalinha a linha); o `editForm`
compartilhado sem `clearErrors()` (o erro de um registro vaza para o próximo); os três botões
de ~25px por linha, com o destrutivo encostado nos outros dois e mudando de posição conforme
a linha tenha ou não convite pendente; o badge duplo "Ativo" + "Convite pendente" (vira uma
coluna só, com precedência `Inativo > Convite pendente > Ativo`); "(módulo 10)" do subtítulo.

---

### 10.12 `Admin/Companies/Index.vue` → **"Empresas cliente"**

**Objetivo.** Manter o cadastro das empresas atendidas e garantir que cada uma tenha
administrador — sem admin, ninguém da empresa consegue cadastrar clientes finais.

**Layout:**
```
Empresas cliente                                    [ Nova empresa ]
Cada empresa tem administradores, que cadastram os clientes finais que
resgatam os kits.

🔍 [ razão social, nome fantasia ou CNPJ ]                  32 empresas
( Todas ) ( Cadastro incompleto 7 ) ( Sem administrador 4 )

┌──────────────────────────────────────────────────────────────────────┐
│ Suvinil Tintas S.A. (Suvinil)                    3 projetos      ⋯   │
│ 12.345.678/0001-90 · Omie 4471                                       │
│ ⚠ Falta e-mail de contato, CEP e cidade      [ Completar cadastro ]  │
│                                                                      │
│   Administradores (1)                        [ Adicionar admin ]     │
│   └ Marina Alves · marina@suvinil.com · Convite pendente             │
│      └ 128 clientes finais cadastrados por eles                      │
└──────────────────────────────────────────────────────────────────────┘
┌──────────────────────────────────────────────────────────────────────┐
│ Appnoz Ltda                                      1 projeto       ⋯   │
│ 98.765.432/0001-10                                                   │
│                                                                      │
│   Administradores (0)                        [ Adicionar admin ]     │
│   └ ⚠ Nenhum admin ainda — sem admin, ninguém da empresa consegue    │
│       cadastrar clientes finais.                                     │
└──────────────────────────────────────────────────────────────────────┘
```
A hierarquia Empresa › Administradores › Clientes finais é expressa por **indentação**, não
por um jargão de banco no subtítulo. Empresas com `missing_fields` sobem para o topo da lista.
`UiMenu`: **Editar cadastro**.

**Props consumidas.** `companies[] { id, name, trade_name, cnpj, omie_customer_code,
contact_name, contact_email, contact_phone, zip, street, number, complement, district, city,
state, notes, full_address, missing_fields[], admins[]{id,name,email,access_pending},
customers_count, projects_count }` (sem paginação).

**Drawer de cadastro** — **um único `CompanyForm.vue`** (extraído para
`Components/CompanyForm.vue`, `props: { form }`) usado por criar e editar. Hoje o mesmo
formulário de 14 campos está escrito **duas vezes** no template, e as cópias já divergiram:
a de criação tem placeholders de CNPJ/telefone/CEP/UF, a de edição não — justamente a que é
usada para completar o cadastro que veio incompleto da Omie.

```
┌ Suvinil Tintas S.A.                                        ✕ ─┐
│ 12.345.678/0001-90 · criada pela Omie                         │
├───────────────────────────────────────────────────────────────┤
│ ⚠ Falta e-mail de contato, CEP e cidade.                      │
│   Sem isso a expedição não consegue faturar.                  │
├───────────────────────────────────────────────────────────────┤
│ IDENTIFICAÇÃO      Razão social * / Nome fantasia / CNPJ *    │
│ CONTATO            Nome / E-mail ⚠ / Telefone                 │
│ ENDEREÇO           CEP ⚠ (busca automática) / Logradouro / Nº │
│                    Complemento / Bairro / Cidade ⚠ / UF ⚠     │
│ ▸ Observações internas                          (recolhido)   │
│ [ UiAlert: bag `cnpj` aqui ]                                  │
├───────────────────────────────────────────────────────────────┤
│                            [ Cancelar ] [ Salvar empresa ]    │
└───────────────────────────────────────────────────────────────┘
```
Os campos listados em `missing_fields` recebem o marcador ⚠ — o alerta do topo passa a
apontar para onde clicar. Três `UiFormSection`. CEP via `useCepLookup`.

**Drawer de administrador.** Aberto pelo botão que fica **ao lado da lista de
administradores** (hoje ele está no canto superior do card e o formulário aparece no rodapé,
longe do clique). Cabeçalho: `Novo administrador · Suvinil Tintas S.A.`.
Corpo com a consequência explícita: *"Ela recebe um convite por e-mail. Ao definir a senha,
passa a ver apenas os projetos da Suvinil e a cadastrar os clientes finais dessa empresa."*

**Ações.** POST `admin.companies.store` / PUT `admin.companies.update(id)` — mesmo payload de
14 campos; bag **`cnpj`** dentro do drawer. POST `admin.companies.admins.store(companyId)`
`{ name, email }`. `GET cep.lookup` via `useCepLookup`.

**Estados.** Vazio: "Nenhuma empresa cadastrada. Elas normalmente nascem do webhook da Omie."
+ botão no bloco. Busca sem resultado: "Nenhuma empresa para \"{q}\"."

**Removido.** A cópia duplicada do formulário de 14 campos; contato e endereço em modo leitura
no card (ficam só no drawer — hoje aparecem duas vezes na mesma tela, um como texto e outro
como input); o formulário de empresa e o de admin podendo ficar abertos **ao mesmo tempo** no
mesmo card, com dois botões de salvar em contextos diferentes; o textarea de observações da
visão principal; a fileira de três badges de mesmo peso (o alerta de `missing_fields` — a
informação mais acionável da tela — era o terceiro badge, depois de dois contadores);
"(Company → Admin Cliente → Clientes Finais)" do subtítulo.

---

### 10.13 `Admin/EmailTemplates/Index.vue` → **"E-mails automáticos"**

**Objetivo.** Editar o que o cliente final recebe em cada etapa, **vendo o resultado antes de
publicar**.

**Layout:**
```
E-mails automáticos
O que o cliente final recebe em cada etapa. Vale para todos os envios a
partir de agora.

[ Presente liberado ] ( Resgate confirmado ) ( Status do pedido )
├───────────────────────────────┬────────────────────────────────────┤
│ EDITANDO                      │ PRÉVIA · dados de exemplo          │
│ Assunto *                     │ De: WeLog <nao-responda@…>         │
│ [Seu presente está dispo…   ] │ Para: Maria Silva                  │
│ inserir: {{userName}}         │ Assunto: Seu presente está         │
│          {{productName}}      │          disponível!               │
│          {{companyName}}      │ ─────────────────────────────────  │
│                               │ Olá, Maria Silva!                  │
│ Mensagem *                    │                                    │
│ ┌───────────────────────────┐ │ A Suvinil preparou um presente     │
│ │# Olá, {{ userName }}!     │ │ para você:                         │
│ │A **{{ companyName }}**…   │ │                                    │
│ └───────────────────────────┘ │ Kit Boas-vindas                    │
│ inserir: {{userName}} …       │                                    │
│ Clique para inserir onde o    │ ⚠ {{ userNome }} não existe neste  │
│ cursor está.                  │   modelo — sairia em branco no     │
│ 412 / 5000 caracteres         │   e-mail. Você quis dizer          │
│                               │   {{ userName }}?                  │
├───────────────────────────────┴────────────────────────────────────┤
│ [ Salvar e usar nos próximos envios ] [ Descartar ]  Voltar ao padrão│
└─────────────────────────────────────────────────────────────────────┘
```
Abaixo de `lg`, a prévia vai para uma segunda aba interna ("Editar" | "Prévia").

**Props consumidas.** `templates[] { id, key, name, subject, body,
variables[]{name, description} }`.

**Ações.** PUT `admin.email-templates.update(id)` `{ subject, body }`.
POST `admin.email-templates.restore(id)`.

**Correções obrigatórias (as duas mais graves do sistema — o efeito sai da tela do admin e
chega à caixa de entrada de centenas de clientes finais):**

1. **"Restaurar padrão" grava o padrão e deixa o texto antigo na tela.** Os formulários são
   criados uma única vez no setup (`ref(props.templates.map(t => useForm(...)))`) e nunca
   re-sincronizam com a prop; a URL não muda, então o `:key="$page.url"` do layout também não
   remonta nada. O admin lê "Modelo restaurado", vê o texto ruim, ajusta e clica em Salvar —
   **regravando exatamente o que acabou de descartar**.
   *Correção:* um único `useForm` ligado à aba ativa, com
   `watch([() => props.templates, activeKey], () => { form.defaults({...}); form.reset(); })`.
   No `onSuccess` do restore, reidratar antes de qualquer coisa. Remover o `router.reload`
   redundante (o `back()` do controller já devolve as props novas).

2. **Variável digitada errada vira campo em branco no e-mail.** `EmailTemplate::substitute`
   casa `/\{\{\s*(\w+)\s*\}\}/` e troca token desconhecido por string vazia. "Olá,
   {{ userNome }}" vira **"Olá, !"** para centenas de pessoas, e nada na interface fica entre
   o erro de digitação e a caixa de entrada.
   *Correção (100% front):* extrair todos os tokens de `subject` e `body` com
   `/\{\{\s*(\w+)\s*\}\}/g`, comparar com `t.variables`. Token desconhecido:
   - bloqueia o botão Salvar;
   - mostra `UiAlert variant="danger"` ao lado do campo com sugestão por similaridade
     (distância de Levenshtein simples sobre a lista de `variables`):
     **"{{ userNome }} não existe neste modelo — sairia em branco no e-mail. Você quis dizer
     {{ userName }}?"**

**Prévia.** Renderização mínima de Markdown feita à mão (`#`, `##`, `**negrito**`, parágrafos,
quebras) — **sem dependência nova**. Placeholders substituídos por valores de exemplo fixos no
front: `userName: 'Maria Silva'`, `companyName: 'Suvinil'`, `productName: 'Kit Boas-vindas'`,
`address: 'Rua XV de Novembro, 100 — Curitiba/PR'`, `eventTitle: 'Objeto em trânsito'`,
`eventDescription: 'Objeto encaminhado para a unidade de distribuição'`,
`trackingCode: 'BR123456789BR'`, `carrier: 'Correios'`. Rotulada **"Prévia · dados de exemplo"**.

**Chips de variável.** Inserir na **posição do cursor** (`selectionStart`/`selectionEnd` do
campo em foco), não concatenar no fim. Duplicar a lista de chips **abaixo do campo Assunto** —
o assunto também aceita variável (o padrão de `shipment_update` é literalmente
`{{ eventTitle }}`), mas hoje os chips só escrevem no corpo. A descrição da variável sai do
atributo `title` (invisível no toque e para leitor de tela) e vira texto na lista.

**Guard de navegação.** Trocar de aba ou sair da página com alterações pendentes dispara
`UiConfirm`: `Descartar as alterações em "Presente liberado"?`.

**Estados.** Salvando: botão com `loading`. Erro de campo: `UiField`.
Nenhum estado vazio (o conjunto é fixo em 3 e não se cria nem se apaga).

**Removido.** Os três editores abertos ao mesmo tempo (é fácil ajustar um modelo e salvar o
card errado por rolagem); os três botões "Salvar" idênticos e distantes; o badge passivo
"Alterações não salvas" (vira guard de navegação); o `ref([...map(useForm)])`;
o `title=""` como único lugar da descrição da variável; "(Módulo 11)" do subtítulo.

---

### 10.14 `Portal/Catalog.vue` → **"Meu presente"**

**Objetivo.** A pessoa entende o que ganhou e começa o resgate em um toque.
**Premissa de desenho do portal:** entra 1 a 3 vezes na vida, quase sempre pelo celular, vinda
de um e-mail. Meta: **do e-mail à caixa na porta com 3 toques e 2 campos digitados.**

**Layout (mobile — presente disponível):**
```
┌────────────────────────────────────┐
│  W WeLog                    Yago ⌄ │
├────────────────────────────────────┤
│  Olá, Marina                       │  text-2xl semibold
│  A Suvinil preparou um presente    │  text-base ink-muted
│  para você.                        │
│                                    │
│ ┌────────────────────────────────┐ │
│ │  ▓▓▓ foto do kit (4:3) ▓▓▓     │ │ ← SÓ se image_url != null
│ ├────────────────────────────────┤ │
│ │ Kit Boas-vindas Suvinil        │ │ text-lg semibold
│ │                                │ │
│ │ O QUE VEM DENTRO               │ │ text-xs uppercase ink-muted
│ │ • 1×  Caneca de cerâmica       │ │ text-sm ink   ← promovido
│ │ • 1×  Camiseta                 │ │
│ │ • 2×  Adesivo                  │ │
│ │                                │ │
│ │ [     Quero receber          ] │ │ 48px, largura total
│ │ Leva 1 minuto — só precisamos  │ │ text-sm ink-muted
│ │ do seu CEP.                    │ │
│ └────────────────────────────────┘ │
├────────────────────────────────────┤
│  🎁 Meu presente │ 🚚 Entregas     │
└────────────────────────────────────┘
```

**Layout (mobile — já resgatado):**
```
│ │ Kit Boas-vindas Suvinil        │ │
│ │ ✓ Pedido confirmado            │ │ text-success
│ │ [  Acompanhar entrega     →  ] │ │ secundário, 48px
```

**Desktop.** Duas colunas (`foto 1:1 | conteúdo`) quando há imagem; **uma coluna** quando não
há — nunca uma caixa cinza vazia.

**Props consumidas.** `gifts[] { id, product { name, image_url, components[]{name,quantity} },
company, redeemed }`; `auth.user { name, email }` (hoje disponível e descartado).

**Ações.** `Link` para `portal.redeem.form(gift.id)` quando `!redeemed`;
`Link` para `portal.redemptions` quando `redeemed`.

**Estados.**
- **Vazio (destino provável logo após o convite — o convite é enviado no cadastro, antes da
  liberação do Projeto):**
  ```
  Ainda não há presente liberado para você
  Assim que a sua empresa liberar, avisamos por e-mail em marina@empresa.com.br.
  Você não precisa fazer nada agora.
  ```
  Usa `auth.user.email`, já disponível.
- Um presente → **card de largura total**. Dois ou mais → `grid sm:grid-cols-2`.

**Removido.** A caixa `aspect-video` de ~200px de cinza quando `image_url` é `null` (o caso
comum, já que os produtos vêm do catálogo Omie) — ela empurra nome, composição e botão para
fora da primeira dobra; o `grid sm:grid-cols-2` fixo; o par redundante badge "Resgatado" +
botão "Acompanhar"; o subtítulo "Kits liberados para você resgatar" (três palavras de jargão
numa frase só); os `<h1>` escritos à mão → `PageHeader tone="portal"`;
o `hover:-translate-y-0.5 hover:shadow-lift` do card.
**Promovido:** a lista de componentes (o BOM — a única resposta para "o que eu estou
ganhando?") sai de `text-xs text-slate-500`, o menor e mais claro texto do card, para
`text-sm text-ink` com rótulo próprio.

---

### 10.15 `Portal/Redeem.vue` → **"Endereço de entrega"** (2 passos, 1 rota)

**Objetivo.** Coletar o endereço certo com o mínimo de digitação e **impedir o endereço
errado gravado para sempre**.
⚠️ `RedeemKit` bloqueia qualquer segunda tentativa e **não existe rota de correção de endereço
em lugar nenhum do sistema** — nem no portal, nem no operador. É a única ação irreversível
sem desfazer.

**Passo 1 — "Onde você quer receber?" (mobile):**
```
┌────────────────────────────────────┐
│  ‹ Voltar ao presente              │
│  Onde você quer receber?           │
│  Kit Boas-vindas Suvinil           │
│  ● ─────── ○   Passo 1 de 2        │
│                                    │
│  Quem vai receber *                │
│  [ Marina Alves               ]    │ 48px, pré-preenchido
│                                    │ autocomplete="name"
│  CEP *                             │
│  [ 80010-000 ]  buscando…          │ inputmode="numeric"
│  ✓ Curitiba/PR — Centro            │ autocomplete="postal-code"
│    Rua XV de Novembro              │ text-success
│    [ Não é esse endereço? ]        │ → abre bloco manual
│                                    │
│  Número *          Complemento     │
│  [ 100    ]  [ apto 42        ]    │ inputmode="numeric" no nº
│                                    │
│  [        Continuar           ]    │ 48px, largura total
└────────────────────────────────────┘
```
**Campos digitados no caminho feliz: 2** (CEP e número). O nome já vem preenchido.
Hoje são **8 campos digitados à mão**, no celular, para o público que menos digita — enquanto
a rota `cep.lookup` está aberta a qualquer usuário autenticado, `lib/cep.js` existe e funciona,
e o comentário de `routes/web.php` diz textualmente que ela foi feita *"no portal, no endereço
de entrega do resgate"*. O portal simplesmente nunca importou a lib.

**CEP não encontrado / serviço fora do ar** → o bloco manual abre com as mensagens que a lib
já devolve: *"CEP não encontrado — confira ou preencha à mão."* /
*"Serviço de CEP indisponível — preencha à mão."* Nesse modo, **UF é `UiSelect` com as 27
unidades** (`UFS` de `lib/format.js`), nunca `<input maxlength="2">` — hoje quem digita
"Paraná" vê "Pa", passa na validação `size:2` e o valor vai direto para o payload de
prepostagem dos Correios.

**Passo 2 — "Confira antes de confirmar":**
```
┌────────────────────────────────────┐
│  ‹ Corrigir endereço               │
│  Confira antes de confirmar        │
│  ○ ─────── ●   Passo 2 de 2        │
│ ┌────────────────────────────────┐ │
│ │ PRESENTE                       │ │
│ │ Kit Boas-vindas Suvinil        │ │
│ │ 1× Caneca · 1× Camiseta        │ │
│ ├────────────────────────────────┤ │
│ │ VAI PARA                       │ │
│ │ Marina Alves                   │ │
│ │ Rua XV de Novembro, 100        │ │
│ │ apto 42 — Centro               │ │
│ │ Curitiba/PR — 80010-000        │ │
│ │                   [ Corrigir ] │ │ alvo 44px
│ └────────────────────────────────┘ │
│  ⚠ Depois de confirmar não é       │ text-warning
│    possível trocar o endereço.     │
│  [   Confirmar e finalizar    ]    │ 48px
└────────────────────────────────────┘
```
O passo é um `ref` no front. **O POST só acontece no passo 2.** Mesma rota, mesmo payload,
mesma regra de negócio.

**Props consumidas.** `entitlement { id, company, product { name, components[] } }`;
`defaultName: String`.

**Ações.** POST `portal.redeem(entitlement.id)` com
`{ recipient_name, zip, street, number, complement, district, city, state }`.
`GET cep.lookup` via `useCepLookup`.

**Bag `redeem`** (ex.: "Este kit já foi resgatado.", "Este Projeto ainda não foi liberado
para resgate.") → `UiAlert variant="danger" role="alert"` **acima do cartão, dentro da
página**. Hoje `Redeem.vue` não renderiza esse bag em lugar nenhum: só o `UiFlash` do layout
o pega por acidente.

**Atributos obrigatórios** (hoje nenhum campo declara `autocomplete` nem `inputmode`, o que
desliga o autopreenchimento nativo de endereço do Chrome/Safari — justamente o recurso que
resolveria o formulário em um toque):

| Campo | `autocomplete` | `inputmode` |
|---|---|---|
| `recipient_name` | `name` | — |
| `zip` | `postal-code` | `numeric` |
| `street` | `address-line1` | — |
| `number` | — | `numeric` |
| `complement` | `address-line2` | — |
| `district` | `address-level3` | — |
| `city` | `address-level2` | — |
| `state` | `address-level1` | — |

**Guardas de interface** (não são regra nova — a regra já existe no backend):
"Continuar" desabilitado enquanto `!isCompleteCep(form.zip) || !form.number`.

**Estados.** Buscando CEP: `UiInput :loading` + hint "buscando…". Enviando: botão com
`loading`, campos permanecem habilitados.

**Removido.** O card lateral "Resumo" da terceira coluna (no mobile ele já ia para **depois**
do botão de confirmação, onde ninguém lê; no desktop rouba 1/3 da largura para repetir o que
a pessoa acabou de ver no catálogo) → vira a linha fina `Kit Boas-vindas Suvinil` no topo, e
o BOM reaparece no passo 2. Cidade, Bairro e UF como campos digitáveis por padrão.
O botão "Voltar" ao lado do "Confirmar" (ação de saída do mesmo tamanho e ao lado da ação
irreversível) → vira o link `‹ Voltar ao presente` no topo. A ordem embaralhada
CEP → Cidade → Rua → Número → Complemento → UF → Bairro.

---

### 10.16 `Portal/Redemptions.vue` → **"Entregas"**

**Objetivo.** Dizer, em português de gente, onde está o presente e o que acontece agora.

**Layout (mobile — logo após confirmar):**
```
┌────────────────────────────────────┐
│ ┌────────────────────────────────┐ │
│ │ ✓ Resgate confirmado!          │ │ bloco PERSISTENTE (não toast)
│ │ Enviamos os próximos avisos    │ │ role="status"
│ │ para marina@empresa.com.br     │ │
│ └────────────────────────────────┘ │
│  Entregas                          │
│ ┌────────────────────────────────┐ │
│ │ Kit Boas-vindas Suvinil        │ │
│ │ Presente de Suvinil            │ │ ← r.company, hoje ignorado
│ │                                │ │
│ │ ● Em preparação                │ │ linha de estado em texto
│ │   Seu presente está sendo      │ │
│ │   separado. Quando sair para   │ │
│ │   entrega, o código de rastreio│ │
│ │   aparece aqui.                │ │
│ │                                │ │
│ │ VAI PARA                       │ │
│ │ Rua XV de Novembro, 100        │ │
│ │ Curitiba/PR                    │ │
│ │ Pedido feito em 31/07/2026     │ │
│ └────────────────────────────────┘ │
└────────────────────────────────────┘
```

**A caminho (com rastreio):**
```
│ │ ● A caminho                    │ │
│ │   Última atualização: objeto em│ │ ← events[0].description
│ │   trânsito para Curitiba/PR    │ │
│ │   Hoje, 14:32                  │ │ text-sm ink-muted
│ │ CÓDIGO DE RASTREIO             │ │
│ │ [ AB123456789BR ] [ Copiar ]   │ │ UiCode copyable, 44px
│ │ Correios                       │ │
│ │ [ Ver histórico completo (7) ▾]│ │ ← colapsado por padrão
```

**Props consumidas.** `redemptions[] { id, product, company, status, carrier, tracking_code,
created_at, address, events[]{occurred_at, description, location} }`.

**Ações.** Nenhuma escrita. `Link` para `portal.catalog` no estado vazio.

**Mapa status → linguagem de negócio** (obrigatório, ver §11.4):
- `confirmed` → "Em preparação — seu presente está sendo separado. Quando sair para entrega,
  o código de rastreio aparece aqui."
- `shipped` → "A caminho — última atualização: {events[0].description}. {data}."
- `delivered` → "Entregue — chegou em {data}."
- `canceled` → "Pedido cancelado. Fale com o RH da sua empresa."

**Estados.**
- Confirmação pós-resgate: o `flash.status` do `portal.redeem` chega aqui e hoje **nunca
  aparece inline** (o `PortalLayout` usa `only-errors`), existindo só como toast de 4,5s.
  Renderizar como `UiAlert variant="success"` **persistente** no topo desta página quando
  houver `flash.status`.
- Vazio: "Você ainda não pediu nenhum presente." + botão "Ver meu presente" → `portal.catalog`.
- Sem eventos de rastreio: a frase de estado sozinha, sem timeline vazia.

**Removido.** O log bruto dos Correios exposto de cara (texto do gateway, tipo "Objeto
encaminhado de Unidade de Tratamento em CURITIBA/PR para Unidade de Distribuição…") → fica
atrás de "Ver histórico completo (N)"; `text-slate-400` em 12px nas datas e locais (2,6:1) →
`text-sm text-ink-muted`; o badge solto no canto superior direito como única indicação de
estado (padrão de tabela de operador, não comunica nada para leigo) → linha de estado em texto.

---

### 10.17 `Auth/Login.vue` → **"Entrar"**

**Objetivo.** Entrar, ou chegar rápido em quem resolve o problema.

```
        ◆
      Entrar
Acesse com o e-mail que recebeu o convite.

┌────────────────────────────────────┐
│ ⚠ Você ainda não criou sua senha.  │ ← #alert, role="alert"
│   [ Receber link de acesso ]       │   a ação que RESOLVE
├────────────────────────────────────┤
│ E-mail *   [                    ]  │ 48px, autocomplete="email"
│ Senha *    [ •••••• ] [ Mostrar ]  │ 48px
│            [      Entrar        ]  │ 48px, block
└────────────────────────────────────┘
        Esqueci minha senha            alvo 44px
```

**Props consumidas.** Nenhuma de página. Globais: `flash.status`, `errors`.

**Ações.** POST `login` `{ email, password }`.

**Estados / bag `email`.** O controller manda duas mensagens diferentes no mesmo bag:
- `'Credenciais inválidas.'` → renderizar em `UiField :error` **nos dois campos**
  (`:invalid` também na Senha — hoje só o e-mail fica vermelho, embora o problema costume ser
  a senha).
- `'Você ainda não definiu sua senha…'` → detectar pela mensagem e renderizar no `#alert` do
  `AuthCard`, com o `UiButton` "Receber link de acesso" → `route('password.request')`.
- `flash.status` (vindo de `password.update` / `password.email`) → `UiAlert variant="info"`.

**Novo.** Botão "Mostrar senha" (`type="button"`, alterna `type` text/password,
`aria-pressed`).

**Removido.** O `UiFlash`; o subtítulo "Acesse com suas credenciais" ("credenciais" é palavra
de sistema); o `class="w-full"` colidindo com a largura do botão → `block`.

---

### 10.18 `Auth/SetPassword.vue` → **"Criar sua senha"** (a tela mais crítica)

**Objetivo.** Criar a senha no primeiro acesso e, quando o link expirou, oferecer a saída.
⚠️ O token expira em **60 minutos**, mas o controller renderiza a tela para **qualquer** token.
Quem abre o convite no dia seguinte preenche a senha, clica, e só então recebe o erro — que
cai no bag `email` e é renderizado **embaixo do campo E-mail**. Ou seja: um problema de token
é comunicado como problema de e-mail, e a tela não tem nenhum link de recuperação.

```
             ◆
      Criar sua senha
   Só isso e você já entra.

┌────────────────────────────────────┐
│ ⚠ Este link de convite expirou.    │ ← #alert, role="alert"
│   Peça um novo abaixo — leva       │
│   1 minuto.                        │
├────────────────────────────────────┤
│ DEFININDO A SENHA DE               │ text-xs uppercase ink-muted
│ marina@empresa.com.br              │ TEXTO, não input
│                                    │
│ Sua senha *                        │
│ [ •••••••• ] [ Mostrar ]           │ 48px
│ Mínimo de 8 caracteres             │
│                                    │
│ [   Criar senha e entrar       ]   │ 48px, block
└────────────────────────────────────┘
   O link expirou? Receba um novo      ← RODAPÉ NOVO, obrigatório
```

**Props consumidas.** `token: String`, `email: String|null`.

**Ações.** POST `password.set` `{ token, email, password, password_confirmation }`.

**Mudanças obrigatórias.**
1. **E-mail vira texto**, com o valor mantido no `useForm` como campo oculto. O token está
   atrelado àquele e-mail: qualquer alteração garante falha. Hoje o campo é editável e é
   exatamente onde o erro de token aparece — a interface convida a pessoa a mexer no único
   campo que não pode mudar.
2. **Erro de token no `#alert`, no topo do cartão**, não sob o campo E-mail.
3. **Rodapé com `<template #footer>`** e link para `route('password.request')`:
   *"O link expirou? Receba um novo por e-mail"*. Sem ele, a tela é beco sem saída.
4. Botão "Mostrar senha". Com a senha visível, o campo "Confirmar senha" pode ser removido;
   **se for mantido**, ligar `:error="form.errors.password"` nele também (hoje o `UiField`
   de confirmação não tem binding de erro nenhum). O payload continua enviando
   `password_confirmation` — a validação do backend é `confirmed` e não muda.
   *Decisão:* **manter o campo**, com o binding corrigido. Remover mudaria o payload.
5. Campos de senha com `autocomplete="new-password"`.

**Subtítulo.** "Defina sua senha para acessar seus presentes" → **"Só isso e você já entra."**
A mesma tela serve ao convite de Admin WeGift, N1, N2 e Admin Cliente — um operador
recém-contratado lê hoje que vai "acessar seus presentes".

---

### 10.19 `Auth/ResetPassword.vue` → **"Criar nova senha"**

Espelho exato de `SetPassword` (e-mail como texto, "Mostrar senha", erro de token no topo,
rodapé de saída). **Única diferença:** aqui o backend **não autentica** — redireciona para o
login com flash. Isso precisa estar escrito **antes** do clique, senão a pessoa acha que
travou:

> Depois de salvar, você entra com a nova senha.

**Props consumidas.** `token: String`, `email: String|null`.
**Ações.** POST `password.update` `{ token, email, password, password_confirmation }`.
**Rótulos.** H1 "Criar nova senha"; rótulo do e-mail "Nova senha para"; botão "Salvar senha".

---

### 10.20 `Auth/ForgotPassword.vue` → **"Receber link de acesso"**

**Antes de enviar:**
```
        ◆
  Receber link de acesso
Enviamos um link para você criar ou trocar sua senha.

┌────────────────────────────────────┐
│ E-mail *  [                     ]  │ 48px
│           [    Enviar link      ]  │ 48px, block
└────────────────────────────────────┘
        ‹ Voltar para entrar
```

**Depois de enviar — o formulário SOME:**
```
┌────────────────────────────────────┐
│ ✉ Pronto                           │
│ Se este e-mail estiver cadastrado, │  ← mantém a neutralidade
│ o link chega em instantes.         │     anti-enumeração
│                                    │
│ • O link vale por 1 hora           │  ← a tela nunca disse isso
│ • Não achou? Veja o spam ou a      │
│   caixa de promoções               │
│                                    │
│ [ Enviar de novo ]                 │  secundário
└────────────────────────────────────┘
```
Hoje a mensagem neutra aparece **acima de um formulário ainda preenchido**, o que faz a pessoa
reenviar sem parar.

**Props consumidas.** Nenhuma. Globais: `flash.status`, `errors`.
**Ações.** POST `password.email` `{ email }`. A resposta é sempre neutra (não revela se o
e-mail existe) — **preservar**.

---

### 10.21 `lang/pt_BR/` e `errors/403.blade.php`

**Por que está no escopo.** São arquivos de **texto e de view**; nenhuma classe PHP, rota ou
regra é alterada. `APP_LOCALE=pt_BR` está definido, mas **não existe diretório `lang/`** no
projeto: a única fonte de tradução é a do framework, em inglês. Resultado hoje: o cliente
final que erra o CEP lê *"The zip field is required."*; quem abre o convite tarde lê
*"This password reset token is invalid."*. E `CompanyController` traduz só os **atributos**,
produzindo frases híbridas do tipo *"The razão social field is required."*.

**`lang/pt_BR/validation.php`** — copiar de
`vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php` e traduzir.
Mínimo obrigatório (chaves efetivamente usadas pelo sistema):
`required`, `email`, `confirmed`, `size.string`, `size.numeric`, `max.string`, `max.numeric`,
`min.string`, `min.numeric`, `integer`, `array`, `date`, `file`, `mimes`, `exists`, `unique`,
`in`, `string`, `numeric`, `boolean`.

**`lang/pt_BR/passwords.php`** — as 4 chaves, com texto que diz o que fazer:
```php
return [
    'reset'     => 'Senha redefinida com sucesso.',
    'sent'      => 'Se este e-mail estiver cadastrado, o link chega em instantes.',
    'throttled' => 'Aguarde alguns instantes antes de tentar de novo.',
    'token'     => 'Este link expirou. Peça um novo abaixo — leva 1 minuto.',
    'user'      => 'Não encontramos este e-mail. Confira se abriu o link mais recente que enviamos.',
];
```

**`resources/views/errors/403.blade.php`** — hoje não existe `resources/views/errors/`, então
qualquer 403 mostra a página preta "403 FORBIDDEN" do Symfony. Cenário provável: o cliente
final reabre o link do convite dias depois, o middleware `guest` o manda para `/dashboard`
(que é `role:operator`) e ele leva um 403 sem saída. Criar uma view em pt-BR, com a marca,
o texto *"Você não tem acesso a esta página."* e um link para `/login`.
⚠️ A correção definitiva (`RedirectIfAuthenticated::redirectUsing`) é backend e está
**fora de escopo** (§2.1).

---

## 11. Glossário de interface (pt-BR)

Vocabulário **único**. Se um termo aparece aqui, é assim que ele é escrito em toda a interface.
Termo que não está aqui não entra na tela.

### 11.1 Nomes de tela e de menu

| Rota | Menu | `<Head>` | `<h1>` | Breadcrumb |
|---|---|---|---|---|
| `dashboard` | Início | Início | Início | — |
| `projects.index` | Projetos | Projetos | Projetos / **Projetos da sua empresa** (`is_client_admin`) | — |
| `projects.show` | — | {nome do Projeto} | {nome do Projeto} | ‹ Projetos |
| `projects.create` | — | Criar projeto manualmente | Criar projeto manualmente | ‹ Projetos |
| `redemptions.index` | Resgates | Resgates | Resgates | — |
| `imports.index` | Importações Parceiros | Importações Parceiros | Importações Parceiros | — |
| `imports.show` | — | {nome do arquivo} | {nome do arquivo} | ‹ Importações Parceiros |
| `products.index` | Produtos | Produtos | Produtos | — |
| `benefits.index` | Benefícios fiscais | Benefícios fiscais | Benefícios fiscais | — |
| `admin.users.index` | Usuários WeGift | Usuários WeGift | Usuários WeGift | — |
| `admin.companies.index` | Empresas cliente | Empresas cliente | Empresas cliente | — |
| `admin.email-templates.index` | Modelos de e-mail | E-mails automáticos | E-mails automáticos | — |
| `portal.catalog` | Meu presente | Meu presente | Olá, {primeiro nome} | — |
| `portal.redeem.form` | — | Endereço de entrega | Onde você quer receber? / Confira antes de confirmar | ‹ Voltar ao presente |
| `portal.redemptions` | Entregas | Entregas | Entregas | — |
| `login` | — | Entrar | Entrar | — |
| `password.set.form` | — | Criar sua senha | Criar sua senha | — |
| `password.request` | — | Receber link de acesso | Receber link de acesso | — |
| `password.reset` | — | Criar nova senha | Criar nova senha | — |

**Proibido na interface:** "Fluxo A", "Fluxo B", "módulo N", "appnoz", "Pintou Parceria",
"Company", "Admin Cliente → Clientes Finais", "onboarding manual", "Dashboard",
"Painel de acompanhamento", "Importações appnoz", "Meus Presentes", "Meus Resgates",
"credenciais", "parametrização" (usar "regra"), "colaborador" (usar "cliente final").

### 11.2 Subtítulos aprovados

Regra: subtítulo só existe quando responde **"o que eu faço aqui"** ou **"de onde vem este
dado"**. Se não responde, não existe.

| Tela | Subtítulo |
|---|---|
| Início | *(nenhum)* |
| Projetos | *(nenhum)* |
| Resgates | *(nenhum)* |
| Importações Parceiros | Planilhas de resgate processadas — pedidos criados na Omie automaticamente. |
| Produtos | Catálogo espelhado da Omie. |
| Criar projeto manualmente | Use apenas quando o Pedido de Venda não chegou da Omie. Normalmente os Projetos nascem sozinhos. |
| Usuários WeGift | Quem da equipe entra no sistema e o que cada um pode fazer. |
| Empresas cliente | Cada empresa tem administradores, que cadastram os clientes finais que resgatam os kits. |
| Benefícios fiscais | Cada código de produto recebe um código de benefício quando o pedido é criado na Omie (Importações Parceiros). |
| E-mails automáticos | O que o cliente final recebe em cada etapa. Vale para todos os envios a partir de agora. |
| Meu presente | A {empresa} preparou um presente para você. |
| Entregas | *(nenhum)* |
| Entrar | Acesse com o e-mail que recebeu o convite. |
| Criar sua senha | Só isso e você já entra. |
| Receber link de acesso | Enviamos um link para você criar ou trocar sua senha. |

### 11.3 Entidades e papéis

| Termo do domínio | Como escrever na interface |
|---|---|
| `Project` | **Projeto** (maiúscula — é entidade do FD) |
| `Company` | **empresa cliente** |
| `ProjectEntitlement` | **cliente final** (nunca "colaborador", "entitlement", "autorização") |
| `Redemption` (operador) | **resgate** |
| `Redemption` (portal) | **presente** (catálogo) / **entrega** (acompanhamento) |
| `Product` com `is_kit` | **kit** |
| `components` / BOM | **O que vem dentro** (portal) / **Composição** (operador) |
| `AppnozImport` | **importação Suvinil** |
| `AppnozImportRow` | **linha** |
| `FiscalBenefit` | **regra de benefício fiscal** |
| `EmailTemplate` | **modelo de e-mail** |
| `admin_wegift` | **Admin WeGift** — "Tudo, incluindo usuários, empresas, modelos de e-mail e benefícios fiscais" |
| `wegift_n1` | **Operador N1** — "Cria projetos, libera resgates e importa planilhas" |
| `wegift_n2` | **Operador N2** — "Somente consulta. Não libera projeto nem importa planilha" |
| `admin_client` | **admin da empresa cliente** |
| `end_customer` | **cliente final** |
| `omie_order_number` | **Pedido Omie** |
| `trello_card_url` | **card Trello** |
| `tracking_code` | **código de rastreio** |
| `carrier` | **transportadora** |

### 11.4 Status — rótulo único por chave

**`pipeline_status`** (`pipelineLabels`, já existe — não mexer):
`created`→Criado · `design`→Design · `production`→Produção · `picking`→Picking ·
`packing`→Packing · `invoiced`→NF Emitida · `done`→Concluído

**`redemption.status`, área operacional** (`redemptionStatusLabels`, já existe):
`confirmed`→Confirmado · `shipped`→Em transporte · `delivered`→Entregue · `canceled`→Cancelado

**`redemption.status`, portal** (linguagem de negócio, frase inteira — §10.16):
`confirmed`→"Em preparação" · `shipped`→"A caminho" · `delivered`→"Entregue" ·
`canceled`→"Pedido cancelado"

**`importRow.status`** (`importRowStatusLabels`, já existe):
`pending`→Pendente · `blocked_structural`→Erro estrutural ·
`blocked_product`→Produto não cadastrado · `order_created`→Pedido criado ·
`invoiced`→NF emitida · `dispatched`→Despachado (Flash) · `delivered`→Entregue

**`import.status`** (`importStatusLabels`, **NOVO** — hoje sai cru em inglês em 3 telas):
`processing`→Processando · `completed`→Concluída ·
`completed_with_blocks`→Concluída com bloqueios · `failed`→Falhou

**Situação de liberação do Projeto** (`projectReleaseLabels`, **NOVO**):
`released`→Liberado · `ready`→Pronto para liberar · `producing`→Em ciclo produtivo

**Situação do cliente final** (`Projects/Show`, precedência de cima para baixo):
Resgatou · Acesso pendente · Notificada em {data} · Ainda não notificada

**Situação do usuário WeGift** (precedência): Inativo · Convite pendente · Ativo

**`carrier`** (`carrierLabels`, já existe): `correios`→Correios · `mrb`→MRB · `ddm`→DDM Log

**Prazo** (`deadlineState`): Em {N} dias · Vence amanhã · Vence hoje · Atrasado {N} dias

### 11.5 Rótulos de ação (verbo + objeto — nunca "OK", "Enviar", "Salvar" sozinho)

| Contexto | Rótulo |
|---|---|
| Criar projeto manual | Criar Projeto e abrir card no Trello |
| Liberar projeto | Liberar e notificar {N} pessoas |
| Cadastro individual | Cadastrar cliente final |
| Planilha de clientes finais | Importar planilha |
| Baixar modelo | Baixar modelo (.xlsx) |
| Reenviar convite | Reenviar convite |
| Expedir | Expedir e avisar |
| Marcar entregue | Marcar entregue |
| Reprocessar linha | Reprocessar |
| Nova importação Suvinil | Importar e criar pedidos |
| Importar catálogo | Importar da Omie |
| Novo usuário | Criar e enviar convite |
| Desativar usuário | Desativar acesso |
| Nova empresa / edição | Salvar empresa |
| Novo admin de cliente | Criar e enviar convite |
| Nova regra fiscal | Salvar regra / **Substituir regra** (quando o código já existe) |
| Remover regra fiscal | Remover regra |
| Salvar modelo de e-mail | Salvar e usar nos próximos envios |
| Restaurar modelo | Voltar ao padrão |
| Portal — iniciar resgate | Quero receber |
| Portal — avançar | Continuar |
| Portal — confirmar | Confirmar e finalizar |
| Portal — acompanhar | Acompanhar entrega |
| Auth — entrar | Entrar |
| Auth — primeiro acesso | Criar senha e entrar |
| Auth — redefinir | Salvar senha |
| Auth — recuperar | Enviar link |

### 11.6 Estados vazios aprovados

| Tela | Texto |
|---|---|
| Projetos | Nenhum projeto. Eles nascem automaticamente do Pedido de Venda na Omie. |
| Clientes finais do Projeto | Nenhum cliente final cadastrado ainda. |
| Resgates do Projeto | Nenhum resgate confirmado ainda. Eles aparecem aqui conforme os clientes finais informam o endereço. |
| Resgates (lista) | Nenhum resgate confirmado. |
| Importações | Nenhuma importação processada. |
| Linhas da importação | Sem linhas nesta importação. |
| Produtos (sem busca) | Nenhum produto no catálogo. |
| Produtos (com busca) | Nenhum produto para "{q}". |
| Benefícios | Nenhuma regra cadastrada. Sem regra, os pedidos das Importações Parceiros são criados na Omie sem benefício fiscal. |
| Usuários | Nenhum usuário WeGift. |
| Empresas | Nenhuma empresa cadastrada. Elas normalmente nascem do webhook da Omie. |
| Portal — presentes | Ainda não há presente liberado para você. Assim que a sua empresa liberar, avisamos por e-mail em {e-mail}. Você não precisa fazer nada agora. |
| Portal — entregas | Você ainda não pediu nenhum presente. |

---

## 12. Checklist de acessibilidade

Cada item é verificável. PR que não cumpre é rejeitado.

### 12.1 Estrutura e navegação
- [ ] Um `<h1>` por página, vindo do `PageHeader`. Nada de `<h2>` antes de `<h1>`.
- [ ] `<a href="#conteudo" class="sr-only focus:not-sr-only …">Pular para o conteúdo</a>` como
      primeiro elemento focável dos três layouts; `<main id="conteudo" tabindex="-1">`.
- [ ] Todo `<nav>` com `aria-label` distinto ("Navegação principal", "Navegação do portal",
      "Paginação"). Hoje há dois `<nav>` sem rótulo no `AppLayout` — o leitor anuncia
      "navegação" duas vezes sem distinguir.
- [ ] Item ativo com `aria-current="page"` (sidebar, drawer, portal, paginação, abas).
- [ ] `scroll-margin-top: 5rem` em elementos focáveis (§4.1) — o `PageHeader` sticky não pode
      esconder o foco de teclado (WCAG 2.2 SC 2.4.11).

### 12.2 Formulário
- [ ] `UiField` renderiza `<label :for>` com **apenas o rótulo**. Dica e erro são irmãos,
      ligados por `aria-describedby`. Nunca dentro do `<label>`.
- [ ] Campo inválido com `aria-invalid="true"`; a mensagem de erro com `role="alert"`.
- [ ] Campo obrigatório com `required` no controle; asterisco `aria-hidden="true"` +
      "(obrigatório)" em `sr-only`.
- [ ] Grupo de checkboxes/rádios em `<fieldset><legend>` (`UiField as="group"`).
      Nunca `<label>` dentro de `<label>`.
- [ ] `<input type="file">` é `sr-only`, não `hidden`, e o drop zone reage a `:focus-within`.
- [ ] `autocomplete` e `inputmode` conforme a tabela de §10.15 em todo campo de endereço,
      e-mail e senha.

### 12.3 Alvos e teclado
- [ ] Alvo mínimo **36px** na área operacional (ação por linha) e **44px** no portal e na
      autenticação. Nenhum controle abaixo de 24×24px (WCAG 2.2 SC 2.5.8).
- [ ] Todo diálogo (`UiModal`, `UiDrawer`, `UiConfirm`, `UiMenu`) com foco preso, `Esc` para
      fechar e foco devolvido ao gatilho.
- [ ] `UiTabs` com ←/→/Home/End; `UiMenu` com ↑/↓.
- [ ] Container rolável de `UiTable` com `tabindex="0"` e `role="region"` (senão não rola por
      teclado).
- [ ] Nenhuma ação depende de hover.

### 12.4 Contraste e cor
- [ ] Nenhum texto abaixo de **4,5:1**. `text-ink-subtle` (#98a2b3) proibido em texto.
- [ ] Borda de campo e ícone informativo acima de **3:1**.
- [ ] Nenhuma informação transmitida só por cor (WCAG SC 1.4.1): badge sempre com texto,
      prazo atrasado sempre com a palavra "Atrasado", linha bloqueada sempre com "Bloqueada".
- [ ] Anel de foco visível em **todo** elemento interativo, com contraste ≥ 3:1 contra o fundo.

### 12.5 Feedback dinâmico
- [ ] `Toaster` com `role="region" aria-label="Notificações"` + região
      `aria-live="polite" aria-atomic="true"`.
- [ ] `UiAlert` de erro/aviso com `role="alert"`; de info/sucesso com `role="status"`.
- [ ] Auto-dispensa do toast pausa em hover **e** em foco; o botão fechar é focável.
- [ ] Estado de carregamento anunciado por texto, não só por spinner
      (`aria-busy` no container + texto de etapa).

### 12.6 Tabela
- [ ] `scope="col"` em todo `<th>`; `<caption class="sr-only">` em toda tabela.
- [ ] Coluna de ações com rótulo real em `sr-only` — nunca `label: ''`.
- [ ] Célula vazia com `—` **e** `aria-label` explicando (ou texto de estado, §9.8).

### 12.7 Movimento
- [ ] Todas as animações desligadas sob `prefers-reduced-motion: reduce` (já coberto em §4.1).
- [ ] Nenhuma animação de entrada de página (o `animate-fade-in-up` de 320ms a cada clique sai).

---

## 13. Ordem de implementação

Regra de paralelismo: **dois agentes nunca editam o mesmo arquivo**. As etapas de mesma faixa
podem rodar em paralelo; uma etapa só começa quando as suas dependências terminaram.

### Etapa 1 — Tokens *(dependência: nenhuma)*
`resources/css/app.css`
Colar §4.1 inteiro. Remover `.bg-dotted`, `.animate-fade-in-up`, `.animate-scale-in` e
`scroll-behavior: smooth`. Manter `--shadow-card/lift/pop` marcados como obsoletos, senão as
telas ainda não migradas quebram visualmente.

### Etapa 2 — Base do design system *(depende de 1)*
`lib/format.js` (§6.6), `lib/icons.js`, `Components/UiIcon.vue`, `UiButton.vue`, `UiBadge.vue`,
`UiCode.vue`, `UiSkeleton.vue`, `UiAlert.vue`, `UiCard.vue`, `UiPanel.vue`, `UiEmpty.vue`,
`UiSectionHeading.vue`, `UiDescriptionList.vue`, `UiStat.vue`, `PipelineChart.vue`,
`StatusBadge.vue`.

### Etapa 3 — Formulário *(depende de 2)*
`UiField.vue`, `UiInput.vue`, `UiTextarea.vue`, `UiSelect.vue`, `UiCheckbox.vue`,
`UiCheckboxGroup.vue`, `UiFormSection.vue`, `UiSearchInput.vue`, `UiFileDrop.vue`.

### Etapa 4 — Sobreposição e feedback *(depende de 2)* — **paralela à 3**
`UiModal.vue`, `UiDrawer.vue`, `UiConfirm.vue`, `UiMenu.vue`, `UiTabs.vue`, `UiTimeline.vue`,
`UiRowErrors.vue`, `Toaster.vue`, `composables/{useConfirm,useToast,useRowAction,useTabQuery}.js`.

### Etapa 5 — Tabela e paginação *(depende de 2)* — **paralela à 3 e à 4**
`UiTable.vue`, `UiPagination.vue`.

### Etapa 6 — CEP *(depende de 3)*
`composables/useCepLookup.js` (encapsula a máquina de estados hoje duplicada em
`Admin/Companies`; `lib/cep.js` fica intacta).

### Etapa 7 — Layouts *(depende de 2, 3, 4, 5)*
`Layouts/AppLayout.vue`, `Layouts/PortalLayout.vue`, `Components/PageHeader.vue`,
`Components/AuthCard.vue`, **deletar `Components/UiFlash.vue`**.
Esta etapa é o gargalo: nenhuma tela pode ser migrada antes, porque todas dependem do
`PageHeader` novo e da remoção do `UiFlash` e do `:key="$page.url"`.

### Etapa 8 — Textos em pt-BR *(dependência: nenhuma)* — **pode rodar desde o início**
`lang/pt_BR/validation.php`, `lang/pt_BR/passwords.php`,
`resources/views/errors/403.blade.php`.

### Etapa 9 — Autenticação *(depende de 7 e 8)*
`Pages/Auth/{Login,SetPassword,ResetPassword,ForgotPassword}.vue`.

### Etapa 10 — Portal *(depende de 7, 6 e 8)*
`Pages/Portal/{Catalog,Redeem,Redemptions}.vue`.
**Maior impacto por linha alterada de todo o redesign** — atinge todos os clientes finais e
não exige nada do backend.

### Etapa 11 — Expedição *(depende de 7)*
`Components/ShipControl.vue`, `Pages/Redemptions/Index.vue`.

### Etapa 12 — Projeto *(depende de 7 e 11)*
`Pages/Projects/{Index,Show,Create}.vue`.
`Show` consome o `ShipControl` novo, por isso depende da 11.

### Etapa 13 — Importações *(depende de 7)* — **paralela à 11 e à 12**
`Pages/Imports/{Index,Show}.vue`.

### Etapa 14 — Catálogo e início *(depende de 7)* — **paralela à 11, 12 e 13**
`Pages/Products/Index.vue`, `Pages/Dashboard.vue`.

### Etapa 15 — Administração A *(depende de 7)* — **paralela à 11–14**
`Pages/Admin/Users/Index.vue`, `Pages/Benefits/Index.vue`.

### Etapa 16 — Administração B *(depende de 7)* — **paralela à 11–15**
`Pages/Admin/Companies/Index.vue` + `Components/CompanyForm.vue` (novo, extraído),
`Pages/Admin/EmailTemplates/Index.vue`.

### Etapa 17 — Limpeza final *(depende de TODAS)*
`resources/css/app.css`: remover `--shadow-card`, `--shadow-lift`, `--shadow-pop` e `.bg-mesh`
se o `AuthCard` tiver deixado de usá-la. Rodar as verificações de §4.3 sobre `resources/js`:
zero `slate-*`/`emerald-*`/`amber-*`/`red-*`/`blue-*`/`indigo-*`, zero `rounded-xl|2xl`,
zero `shadow-card|lift|pop|sm|md`, zero `UiFlash`, zero `window.confirm`, zero `<svg` em
`Pages/`, zero `{{ *.status }}` cru.

### 13.1 Se só der para fazer uma coisa

**Etapa 10 (Portal)** — ligar a busca de CEP em `Redeem.vue` transforma 8 campos digitados em
2, no aparelho e no público em que cada campo custa mais, e o backend está pronto,
documentado e sem restrição de perfil desde o primeiro dia.

**Se der para fazer duas:** a segunda é **P1 — erro no ponto da ação** (etapas 4, 7 e as telas
de operação). É a diferença entre uma interface que responde e uma que finge que nada
aconteceu — e hoje ela finge nas quatro operações críticas: liberar Projeto, cadastrar em
lote, expedir resgate, destravar linha.

---

## 14. Matriz de arquivos por etapa (para o orquestrador)

| Etapa | Arquivos | Depende de |
|---|---|---|
| 1 | `resources/css/app.css` | — |
| 2 | `lib/format.js`, `lib/icons.js`, `Components/{UiIcon,UiButton,UiBadge,UiCode,UiSkeleton,UiAlert,UiCard,UiPanel,UiEmpty,UiSectionHeading,UiDescriptionList,UiStat,PipelineChart,StatusBadge}.vue` | 1 |
| 3 | `Components/{UiField,UiInput,UiTextarea,UiSelect,UiCheckbox,UiCheckboxGroup,UiFormSection,UiSearchInput,UiFileDrop}.vue` | 2 |
| 4 | `Components/{UiModal,UiDrawer,UiConfirm,UiMenu,UiTabs,UiTimeline,UiRowErrors,Toaster}.vue`, `composables/{useConfirm,useToast,useRowAction,useTabQuery}.js` | 2 |
| 5 | `Components/{UiTable,UiPagination}.vue` | 2 |
| 6 | `composables/useCepLookup.js` | 3 |
| 7 | `Layouts/{AppLayout,PortalLayout}.vue`, `Components/{PageHeader,AuthCard}.vue`, **del** `Components/UiFlash.vue` | 2,3,4,5 |
| 8 | `lang/pt_BR/{validation,passwords}.php`, `resources/views/errors/403.blade.php` | — |
| 9 | `Pages/Auth/{Login,SetPassword,ResetPassword,ForgotPassword}.vue` | 7,8 |
| 10 | `Pages/Portal/{Catalog,Redeem,Redemptions}.vue` | 6,7,8 |
| 11 | `Components/ShipControl.vue`, `Pages/Redemptions/Index.vue` | 7 |
| 12 | `Pages/Projects/{Index,Show,Create}.vue` | 7,11 |
| 13 | `Pages/Imports/{Index,Show}.vue` | 7 |
| 14 | `Pages/{Products/Index,Dashboard}.vue` | 7 |
| 15 | `Pages/Admin/Users/Index.vue`, `Pages/Benefits/Index.vue` | 7 |
| 16 | `Pages/Admin/Companies/Index.vue`, `Components/CompanyForm.vue`, `Pages/Admin/EmailTemplates/Index.vue` | 7 |
| 17 | `resources/css/app.css` + verificações globais | todas |

**Nenhum arquivo aparece em duas etapas**, exceto `app.css` (etapas 1 e 17, que são a primeira
e a última e nunca rodam em paralelo).

---

## 15. Riscos conhecidos

1. **Testes de feature que casam texto.** Renomeamos "Dashboard"→"Início",
   "Importações appnoz"→"Importações Parceiros", "Meus Presentes"→"Meu presente",
   "Meus Resgates"→"Entregas", "+ Onboarding manual"→"Criar projeto manualmente".
   Todo teste que faz `assertSee('…')` sobre esses textos quebra. **Atualizar o teste, não
   o texto** — as rotas e os payloads não mudaram.
2. **Testes que inspecionam props do Inertia.** Nenhuma prop foi adicionada, removida ou
   renomeada. Testes de `assertInertia(fn ($page) => $page->has('…'))` continuam passando.
3. **Contratos de props de componente.** `UiEmpty` perde `inline` e `colspan`; `UiStat` perde
   `icon` (String de path SVG) e `accent` vira `tone`; `UiButton` muda o significado de
   `variant="danger"` (passa a ser sólido). Toda página que usa essas props precisa ser
   migrada na mesma onda — por isso a etapa 7 é gargalo e nenhuma tela pode ser migrada antes.
4. **`UiFlash` deletado.** Qualquer arquivo não listado em §14 que ainda o importe quebra o
   build do Vite. Grepar antes de deletar.
5. **Remoção do `:key="$page.url"`.** Páginas que dependiam da remontagem para reinicializar
   estado local passam a preservar esse estado entre navegações da mesma rota.
   Ponto de atenção real: `Admin/EmailTemplates` (é justamente o bug do "Restaurar padrão", que
   esta spec corrige com `form.defaults()` + `watch`) e `Projects/Show` (as abas precisam
   reagir a `?aba=` via `useTabQuery`, não a remontagem).
6. **`--shadow-card/lift/pop` mantidos até a etapa 17.** Se alguém removê-los antes, todas as
   telas não migradas perdem a elevação de uma vez. Não é quebra funcional, mas é regressão
   visível.
7. **Paginação client-side.** `Projects/Show` passa a fatiar `entitlements`/`redemptions` no
   front. Se um teste contar `<tr>` renderizadas, o número muda (25 por página em vez de todas).
8. **`lang/pt_BR`.** Ao criar o diretório, **todas** as mensagens de validação do sistema
   mudam de idioma de uma vez — inclusive as das telas ainda não migradas e as retornadas por
   endpoints JSON. Teste que casa `'The … field is required.'` quebra. É a intenção.

---

*Fim da especificação.*

