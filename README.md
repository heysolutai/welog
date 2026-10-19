# WeLog

Plataforma de logística de benefícios da WeGift. Backend em Laravel 12 (PHP 8.3),
frontend em Vue 3 + Inertia + Tailwind 4, tudo containerizado.

O escopo do produto é definido pelo Functional Design (Anexo I do contrato) e está
detalhado em [docs/PLANO.md](docs/PLANO.md). **Não implemente features fora dos 12
módulos do documento** — qualquer adição é alteração formal de escopo.

## Subir o ambiente local

Você precisa apenas de **Docker** e **Docker Compose**. Nada de PHP, Composer ou
Node instalados na máquina.

```bash
git clone <url-do-repositorio>
cd welog-staging

cp .env.example .env

docker compose up -d --build

docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

Pronto. Os serviços ficam em:

| Serviço | URL |
|---|---|
| Aplicação | http://localhost:8000 |
| Mailpit (todos os e-mails enviados) | http://localhost:8025 |
| Vite / HMR | http://localhost:5173 |
| Postgres (inspeção externa) | `localhost:5432` |

### Acesso

O seed cria os usuários iniciais. Em desenvolvimento a senha de todos é `password`:

| Papel | E-mail | Senha |
|---|---|---|
| Admin WeGift | `admin@welog.local` | `password` |

> Essas credenciais são exclusivas de desenvolvimento. Em produção as senhas são
> trocadas no primeiro acesso — ver [docs/GO-LIVE.md](docs/GO-LIVE.md).

### Integrações

O `.env.example` deixa **todas as integrações em modo `fake`** (Omie, Trello,
Flash, Correios). Você desenvolve o sistema inteiro sem precisar de nenhuma
credencial externa: os gateways respondem com dados simulados.

Só troque para `api` quando for testar contra os fornecedores de verdade — ver
[docs/INTEGRACOES.md](docs/INTEGRACOES.md). O túnel do ngrok (para receber
webhooks reais) fica fora do `up` padrão e sobe assim:

```bash
docker compose --profile tunnel up -d ngrok   # exige NGROK_AUTHTOKEN no .env
```

## Onde fica o frontend

```
resources/js/
├── Pages/          # telas (Inertia) — Admin, Auth, Benefits, Imports,
│                   #   Portal, Products, Projects, Redemptions
├── Layouts/        # AppLayout (operação) e PortalLayout (cliente final)
├── Components/     # design system (Ui*) + componentes de domínio
├── composables/    # useConfirm, useToast, useTabQuery, useRowAction, useCepLookup
└── lib/            # format.js (rótulos e datas), icons.js, cep.js
resources/css/      # Tailwind 4 — tokens em @theme
lang/pt_BR/         # mensagens de validação e senha
```

O desenho da interface (princípios, tokens, inventário de componentes, navegação por
perfil e a spec de cada tela) está em [docs/REDESIGN-UI.md](docs/REDESIGN-UI.md).
Leia antes de criar tela ou componente novo — as regras ali são verificáveis por diff.

O contêiner `vite` já roda `npm run dev` com HMR — editar um `.vue` recarrega
sozinho, sem precisar rodar nada à mão. Os únicos Blade que restam são os
templates de e-mail em `resources/views/mail/`.

## Testes

Rode antes de dar push — a suíte precisa ficar verde:

```bash
docker compose exec app php artisan test
```

## Comandos do dia a dia

```bash
docker compose exec app php artisan migrate:fresh --seed   # recriar o banco
docker compose exec app php artisan queue:failed           # jobs que falharam
docker compose logs -f vite                                # log do build frontend
docker compose down                                        # derrubar tudo
```

## Fluxo de trabalho

Push direto na `main`. Antes de subir, garanta que `php artisan test` passa e que
o `.env` **não** foi commitado (ele está no `.gitignore` e contém credenciais
reais das integrações — nunca o remova de lá).
