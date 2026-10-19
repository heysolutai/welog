<script setup>
import { computed, onMounted } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/PageHeader.vue';
import PortalSupportContact from '../../Components/PortalSupportContact.vue';
import UiAlert from '../../Components/UiAlert.vue';
import UiButton from '../../Components/UiButton.vue';
import UiCard from '../../Components/UiCard.vue';
import UiCode from '../../Components/UiCode.vue';
import UiEmpty from '../../Components/UiEmpty.vue';
import UiFormHeading from '../../Components/UiFormHeading.vue';
import UiIcon from '../../Components/UiIcon.vue';
import UiRefreshButton from '../../Components/UiRefreshButton.vue';
import UiTimeline from '../../Components/UiTimeline.vue';
import { useCarriers } from '../../composables/useCarriers';
import { dismissToast, toasts } from '../../composables/useToast';
import { formatDate } from '../../lib/format';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    redemptions: { type: Array, default: () => [] },
});

const { carrierLabel, trackingLink } = useCarriers();

const page = usePage();
const user = computed(() => page.props.auth?.user);
const flash = computed(() => page.props.flash?.status);

const confirmation = computed(() =>
    user.value?.email
        ? `Enviamos os próximos avisos para ${user.value.email}.`
        : 'Enviamos os próximos avisos por e-mail.',
);

/**
 * A confirmação do resgate é bloco persistente nesta tela, não aviso de 6s: é
 * a resposta à única ação irreversível do sistema. O `Toaster` do layout já
 * enfileirou a mesma mensagem no redirecionamento — uma mensagem, um lugar.
 */
onMounted(() => {
    const status = flash.value?.trim();
    if (!status) return;

    toasts.value
        .filter((toast) => toast.message === status)
        .forEach((toast) => dismissToast(toast.id));
});

/** Status do resgate em linguagem de negócio — o enum nunca chega à tela. */
function portalState(redemption) {
    const latest = redemption.events[0] ?? null;

    switch (redemption.status) {
        case 'confirmed':
            return {
                label: 'Em preparação',
                description:
                    'Seu presente está sendo separado. Quando sair para entrega, o código de rastreio aparece aqui.',
                dot: 'bg-info',
                text: 'text-ink',
            };
        case 'shipped':
            return {
                label: 'A caminho',
                description: latest
                    ? `Última atualização: ${latest.description}. ${formatDate(latest.occurred_at, true)}.`
                    : 'Seu presente saiu para entrega.',
                dot: 'bg-info',
                text: 'text-ink',
            };
        case 'delivered':
            return {
                label: 'Entregue',
                description: latest
                    ? `Chegou em ${formatDate(latest.occurred_at, true)}.`
                    : 'Seu presente foi entregue.',
                dot: 'bg-success',
                text: 'text-success',
            };
        case 'canceled':
            return {
                label: 'Pedido cancelado',
                description: 'Fale com o RH da sua empresa.',
                dot: 'bg-danger',
                text: 'text-danger',
            };
        default:
            return {
                label: 'Em andamento',
                description: 'Assim que houver novidade, avisamos por e-mail.',
                dot: 'bg-line-strong',
                text: 'text-ink',
            };
    }
}

const items = computed(() =>
    props.redemptions.map((redemption) => ({ ...redemption, state: portalState(redemption) })),
);

// "Acompanhe o seu presente", quando o Cliente trocou (Customizações → Textos
// do portal). O item do menu continua "Entregas": é o nome do lugar.
const titulo = computed(() => user.value?.portal_texts?.entregas || 'Entregas');
</script>

<template>
    <Head :title="titulo" />

    <UiAlert v-if="flash" variant="success" title="Resgate confirmado!" dismissible class="mb-6">
        {{ confirmation }}
    </UiAlert>

    <PageHeader tone="portal" :title="titulo" :logo="user?.portal_logo_url">
        <!-- O rastreio anda no servidor (webhook da transportadora) sem avisar
             a página aberta: o botão refaz o GET e renova as props. -->
        <template #actions>
            <UiRefreshButton quiet />
        </template>
    </PageHeader>

    <UiEmpty v-if="!items.length" icon="truck" message="Você ainda não pediu nenhum presente.">
        <template #action>
            <UiButton size="lg" :href="route('portal.catalog')">Ver meu presente</UiButton>
        </template>
    </UiEmpty>

    <div v-else class="space-y-4">
        <UiCard v-for="item in items" :key="item.id" padding="none">
            <!-- Cabeçalho no padrão de cadastro: título com chip + contexto,
                 separado do corpo pela divisória. -->
            <div class="border-b border-line p-5">
                <UiFormHeading icon="truck" :title="item.product" />
                <p class="mt-1 pl-10 text-sm text-ink-muted">
                    Presente de {{ item.company }}<template v-if="item.quantity > 1"> · {{ item.quantity }} unidades</template>
                </p>
            </div>

            <div class="p-5">
                <div>
                    <p :class="['flex items-center gap-2 text-base font-semibold', item.state.text]">
                        <span :class="['size-2.5 shrink-0 rounded-full', item.state.dot]" aria-hidden="true" />
                        {{ item.state.label }}
                    </p>
                    <p class="mt-1 text-base text-ink-muted">{{ item.state.description }}</p>
                </div>

                <!-- Código, link, ou os dois: a transportadora é quem decide o
                     que existe. Quem espera o presente quer clicar e ver onde
                     ele está — o link vem primeiro quando há. -->
                <div v-if="item.tracking_code || item.tracking_url" class="mt-4">
                    <h3 class="text-xs font-semibold tracking-wider text-ink-muted uppercase">
                        Rastreio
                    </h3>
                    <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-2">
                        <a
                            v-if="trackingLink(item)"
                            :href="trackingLink(item)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex min-h-11 items-center gap-1.5 text-base font-medium text-brand-700 underline underline-offset-4"
                        >
                            Acompanhar entrega
                            <UiIcon name="external" :size="16" aria-hidden="true" />
                        </a>

                        <UiCode v-if="item.tracking_code" :value="item.tracking_code" copyable />

                        <span v-if="item.carrier" class="text-sm text-ink-muted">
                            {{ carrierLabel(item.carrier) }}
                        </span>
                    </div>
                </div>

                <!-- O log dos Correios é a linguagem do gateway, não a de quem
                     espera o presente: fica atrás de um clique. -->
                <details v-if="item.events.length" class="group mt-4">
                    <summary
                        class="flex min-h-11 cursor-pointer list-none items-center gap-1.5 text-base font-medium text-brand-700 [&::-webkit-details-marker]:hidden"
                    >
                        Ver histórico completo ({{ item.events.length }})
                        <UiIcon name="chevron-down" :size="18" class="transition-transform group-open:rotate-180" />
                    </summary>
                    <div class="mt-3 border-t border-line pt-3">
                        <UiTimeline :events="item.events" />
                    </div>
                </details>

                <div class="mt-4 border-t border-line pt-4">
                    <h3 class="text-xs font-semibold tracking-wider text-ink-muted uppercase">Vai para</h3>
                    <p class="mt-1 text-base text-ink">{{ item.address }}</p>
                    <!-- CPF chega mascarado do servidor; resgates antigos, de
                         antes da obrigatoriedade, vêm sem. -->
                    <p v-if="item.cpf" class="mt-1 text-sm text-ink-muted">
                        CPF de quem recebe:
                        <span class="font-medium text-ink tabular-nums">{{ item.cpf }}</span>
                    </p>
                    <p class="mt-1 text-sm text-ink-muted">Pedido feito em {{ formatDate(item.created_at) }}</p>

                    <div v-if="item.notes" class="mt-3">
                        <h3 class="text-xs font-semibold tracking-wider text-ink-muted uppercase">
                            Observações para a entrega
                        </h3>
                        <p class="mt-1 text-base whitespace-pre-line text-ink">{{ item.notes }}</p>
                    </div>
                </div>
            </div>
        </UiCard>
    </div>

    <PortalSupportContact class="mt-6" />
</template>
