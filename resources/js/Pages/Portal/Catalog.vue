<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import PortalChoiceGroupCard from '../../Components/PortalChoiceGroupCard.vue';
import PortalGradeCard from '../../Components/PortalGradeCard.vue';
import PortalGiftTile from '../../Components/PortalGiftTile.vue';
import PortalOrderCard from '../../Components/PortalOrderCard.vue';
import PortalSupportContact from '../../Components/PortalSupportContact.vue';
import UiAlert from '../../Components/UiAlert.vue';
import UiEmpty from '../../Components/UiEmpty.vue';
import UiIcon from '../../Components/UiIcon.vue';
import UiRefreshButton from '../../Components/UiRefreshButton.vue';
import { firstName } from '../../lib/format';
import { preencher } from '../../lib/portalTexts';

/*
 * Layout direto, sem função.
 *
 * Passar `wide` por uma função de layout — `(page) => h(PortalLayout, …)` —
 * parece funcionar e não funciona: nesta versão do Inertia, função de layout
 * com um argumento recebe as PROPS DA PÁGINA e precisa devolver um componente
 * (ou props para o layout padrão). Devolvendo um VNode, o layout inteiro era
 * descartado em silêncio: a tela do presente aparecia sem cabeçalho, sem
 * navegação e sem o menu da conta.
 *
 * Quem decide a largura agora é o próprio layout, pela rota — ver a lista em
 * PortalLayout.
 */
defineOptions({ layout: PortalLayout });

const props = defineProps({
    gifts: { type: Array, default: () => [] },
});

const page = usePage();
const user = computed(() => page.props.auth?.user);

// Corrida de duas abas: a outra aba escolheu primeiro e este pedido voltou
// para cá com o aviso. É erro flash do redirect, não de formulário.
const redeemError = computed(() => page.props.errors?.redeem);

const greeting = computed(() => {
    const name = firstName(user.value?.name);

    return name ? `Olá, ${name}` : 'Olá';
});

/**
 * Seções da tela: brindes com o MESMO `choice_group` viram UMA seção de
 * escolha ("escolha 1 destas N opções"); sem grupo, cartão individual como
 * sempre. Grupo de uma opção só não é escolha nenhuma — vira individual.
 */
const sections = computed(() => {
    const groups = new Map();
    const result = [];

    for (const gift of props.gifts) {
        if (!gift.choice_group) {
            result.push({ type: 'single', key: `gift-${gift.id}`, gift });
            continue;
        }

        let group = groups.get(gift.choice_group);

        if (!group) {
            group = { type: 'group', key: `group-${gift.choice_group}`, options: [] };
            groups.set(gift.choice_group, group);
            result.push(group);
        }

        group.options.push(gift);
    }

    return result.map((section) => {
        if (section.type !== 'group') return section;

        if (section.options.length === 1) {
            return { type: 'single', key: section.key, gift: section.options[0] };
        }

        // Todas as opções são tamanhos do mesmo pai na grade da Omie: é UM
        // presente com escolha de tamanho, não N presentes iguais.
        const pai = section.options[0].grade?.pai_code;

        return pai && section.options.every((option) => option.grade?.pai_code === pai)
            ? { type: 'grade', key: section.key, options: section.options }
            : section;
    });
});

// Escolhas de um entre vários, e os presentes à parte (avulso ou com tamanho).
const grupos = computed(() => sections.value.filter((section) => section.type === 'group'));
const avulsos = computed(() => sections.value.filter((section) => section.type !== 'group'));

const companies = computed(() => [...new Set(props.gifts.map((gift) => gift.company))]);

// A empresa vai no subtítulo quando é uma só. Com mais de uma, quem diz de
// quem é o presente é cada cartão — o dado nunca some da tela. Um grupo de
// escolha conta como UM presente: a pessoa fica com uma opção.
const subtitle = computed(() => {
    // A voz da campanha, quando o Cliente trocou ("Neste aniversário, o
    // presente é para você!"). Itens do pedido > Tela de resgate.
    if (user.value?.portal_texts?.saudacao) {
        return preencher(user.value.portal_texts.saudacao, { empresa: companies.value[0] ?? '' });
    }

    if (companies.value.length !== 1) return null;

    return sections.value.length === 1
        ? `A ${companies.value[0]} preparou um presente para você.`
        : `A ${companies.value[0]} preparou presentes para você.`;
});

const emptyMessage = computed(() => {
    const email = user.value?.email;

    return `Assim que a sua empresa liberar, avisamos por e-mail${email ? ` em ${email}` : ''}. Você não precisa fazer nada agora.`;
});

// O detalhe por etapa vive em PortalOrderCard; o banner só precisa saber
// se tudo que foi resgatado já chegou.
const delivered = (gift) =>
    !!(gift.redemption?.delivered_at || gift.redemption?.status === 'delivered');

// Banner do topo: aparece quando existe pedido andando (ou entregue).
const banner = computed(() => {
    const redeemed = props.gifts.filter((g) => g.redeemed);
    if (!redeemed.length) return null;

    if (redeemed.every(delivered)) {
        return { title: 'Presente entregue!', text: 'Esperamos que você goste. Qualquer problema, fale com o RH da sua empresa.' };
    }

    return { title: 'Seu presente está a caminho!', text: 'Acompanhe cada etapa da entrega por aqui.' };
});
</script>

<template>
    <Head title="Meu presente" />

    <!-- A logo da campanha (Customizações → Logo → Colaborador) à esquerda
         do "Olá", na mesma posição em que ela aparece na tela do Projeto. -->
    <PageHeader tone="portal" :title="greeting" :subtitle="subtitle" :logo="user?.portal_logo_url">
        <!-- Liberação e etapa mudam no servidor sem avisar a página aberta:
             o botão refaz o GET e renova as props sem recarregar o app. -->
        <template #actions>
            <UiRefreshButton quiet />
        </template>
    </PageHeader>

    <UiAlert v-if="redeemError" variant="danger" class="mb-4">{{ redeemError }}</UiAlert>

    <UiEmpty
        v-if="!gifts.length"
        icon="gift"
        title="Ainda não há presente liberado para você"
        :message="emptyMessage"
    />

    <template v-else>
        <div
            v-if="banner"
            class="mb-4 flex items-center gap-3 rounded-card bg-surface p-4 shadow-glow-brand"
        >
            <span class="flex size-10 shrink-0 items-center justify-center rounded-control bg-brand-50 text-brand-600">
                <UiIcon name="gift" :size="20" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-ink">{{ banner.title }}</p>
                <p class="mt-0.5 text-sm text-ink-muted">{{ banner.text }}</p>
            </div>
            <UiIcon name="truck" :size="28" class="hidden shrink-0 text-brand-300 sm:block" aria-hidden="true" />
        </div>

        <!--
            Uma vitrine só, centrada, com ladrilhos de largura fixa.

            Antes havia dois desenhos: presente ÚNICO virava um painel largo
            com a foto gigante ao lado do texto; vários viravam uma grade de
            duas colunas. Quem recebia um presente via uma tela, quem recebia
            três via outra — e a foto mudava de tamanho conforme a quantidade.

            Agora o ladrilho tem sempre a mesma medida e o que sobra é margem.
            `justify-center` resolve a linha incompleta sem regra por
            quantidade: 1, 2, 4 ou 5 presentes centralizam igual.
        -->
        <!--
            Grupos de escolha primeiro, cada um com o seu título; os presentes
            à parte depois, num bloco próprio. Na mesma grade, os presentes à
            parte caíam logo abaixo de "Escolha 1 destas 3 opções" e liam como
            a 4ª e a 5ª opção daquela escolha.
        -->
        <div class="space-y-14">
            <!-- Grupo de escolha: ocupa a largura toda, porque tem título
                 próprio ("escolha 1 destas N") e as opções centram dentro
                 dele, com os MESMOS ladrilhos. -->
            <PortalChoiceGroupCard
                v-for="(section, index) in grupos"
                :key="section.key"
                :options="section.options"
                :show-company="companies.length > 1"
                :first-image-eager="index === 0"
            />

            <section v-if="avulsos.length">
                <!-- O título só existe quando há uma escolha acima: sozinhos,
                     os presentes não precisam se explicar. -->
                <div v-if="grupos.length" class="mx-auto mb-6 max-w-xl text-center">
                    <h2 class="text-lg font-semibold text-ink">
                        {{ avulsos.length === 1 ? 'Este também é seu' : 'Estes também são seus' }}
                    </h2>
                    <p class="mt-1 text-sm text-ink-muted">
                        {{ avulsos.length === 1
                            ? 'Não entra na escolha acima. Você pode pedir este também.'
                            : 'Não entram na escolha acima. Você pode pedir cada um deles.' }}
                    </p>
                </div>

                <div class="flex flex-wrap justify-center gap-5">
                    <template v-for="(section, index) in avulsos" :key="section.key">
                        <!-- Tamanhos de uma grade da Omie: um presente, escolhe o tamanho. -->
                        <PortalGradeCard
                            v-if="section.type === 'grade'"
                            :options="section.options"
                            :show-company="companies.length > 1"
                            :eager="!grupos.length && index === 0"
                        />

                        <!-- Presente avulso já resgatado vira acompanhamento, e
                             acompanhamento ocupa a largura: é o mesmo motivo do
                             grupo de escolha. -->
                        <PortalOrderCard
                            v-else-if="section.gift.redeemed"
                            :gift="section.gift"
                            :show-company="companies.length > 1"
                            class="w-full"
                        />

                        <PortalGiftTile v-else :gift="section.gift" :eager="!grupos.length && index === 0" />
                    </template>
                </div>
            </section>
        </div>
    </template>

    <PortalSupportContact class="mt-6" />
</template>
