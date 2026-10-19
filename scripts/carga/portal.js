/*
 * Teste de carga do caminho do Colaborador (k6).
 *
 *   k6 run -e BASE_URL=https://welog.exemplo.com.br -e USERS=./usuarios.csv scripts/carga/portal.js
 *
 * Ele percorre exatamente o que as nove mil pessoas fazem quando o e-mail de
 * liberação sai: entram, abrem o catálogo, abrem o formulário do brinde. O
 * resgate em si NÃO é disparado — ele consome saldo de verdade e manda e-mail
 * de verdade; o que interessa medir é o caminho até ele, que é onde todo mundo
 * se acumula.
 *
 * O CSV é o que separa um teste honesto de um número bonito: mil máquinas
 * virtuais batendo no MESMO login exercitam o cache e não o banco. Uma linha
 * por pessoa, `email,senha`, de contas de teste criadas para isto.
 *
 * O que observar enquanto roda, do outro lado:
 *   - `listen queue` do FPM (pm.status_path) tem de voltar a zero entre
 *     rajadas; subindo sem parar = `max_children` curto.
 *   - conexões abertas no banco contra o limite do plano.
 *   - tamanho da fila `mail` no Redis.
 */
import http from 'k6/http';
import { check, sleep } from 'k6';
import { SharedArray } from 'k6/data';
import papaparse from 'https://jslib.k6.io/papaparse/5.1.1/index.js';

const BASE = __ENV.BASE_URL;

if (!BASE) {
    throw new Error('Informe -e BASE_URL=https://...');
}

/** Carregado UMA vez e compartilhado: um array por VU estouraria a memória. */
const usuarios = new SharedArray('usuarios', () => {
    const arquivo = __ENV.USERS || './usuarios.csv';

    return papaparse.parse(open(arquivo), { header: true }).data.filter((u) => u.email);
});

export const options = {
    /*
     * O perfil é o do disparo, não o de um dia normal: a rampa sobe rápido
     * porque o e-mail chega para todo mundo ao mesmo tempo. Ajuste `target`
     * ao tamanho real da campanha.
     */
    stages: [
        { duration: '1m', target: 200 },   // as primeiras que abrem o e-mail
        { duration: '2m', target: 1500 },  // o grosso, nos primeiros minutos
        { duration: '3m', target: 1500 },  // platô: é aqui que se mede
        { duration: '1m', target: 0 },
    ],
    thresholds: {
        // Se mais de 1% falha, o teto foi encontrado — e o número que importa
        // é em qual `target` isso começou.
        http_req_failed: ['rate<0.01'],
        // Acima disso a pessoa sente a tela travando.
        http_req_duration: ['p(95)<1500'],
    },
};

export default function () {
    const pessoa = usuarios[__VU % usuarios.length];
    const jar = http.cookieJar();

    // 1. Página de login — mede o servir de HTML e o token CSRF.
    const login = http.get(`${BASE}/login`, { tags: { rota: 'login_form' } });
    check(login, { 'login abriu': (r) => r.status === 200 });

    const token = login.html().find('input[name=_token]').attr('value');

    // 2. Autenticar. É a requisição mais cara do caminho: bcrypt da senha.
    const entrou = http.post(
        `${BASE}/login`,
        { _token: token, email: pessoa.email, password: pessoa.senha },
        { tags: { rota: 'login' }, redirects: 1 },
    );
    check(entrou, { 'entrou': (r) => r.status === 200 && !r.url.endsWith('/login') });

    sleep(1);

    // 3. Catálogo: a consulta por `user_id` em project_entitlements. É esta
    //    que exige o índice — sem ele, varredura da tabela inteira por pessoa.
    const catalogo = http.get(`${BASE}/portal`, {
        tags: { rota: 'catalogo' },
        headers: { 'X-Inertia': 'true', 'X-Inertia-Version': '' },
    });
    check(catalogo, { 'catálogo abriu': (r) => r.status === 200 || r.status === 409 });

    sleep(2);

    // 4. Entregas. Fecha o ciclo sem gravar nada.
    const entregas = http.get(`${BASE}/portal/meus-resgates`, {
        tags: { rota: 'entregas' },
        headers: { 'X-Inertia': 'true', 'X-Inertia-Version': '' },
    });
    check(entregas, { 'entregas abriu': (r) => r.status === 200 || r.status === 409 });

    // Sessão descartada: a pessoa seguinte deste VU entra do zero, como na
    // campanha de verdade. Sem isto o teste mediria só usuário já logado.
    jar.clear(BASE);

    sleep(2);
}
