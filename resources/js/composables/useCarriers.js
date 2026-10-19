import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * A tabela de transportadoras, que chega em toda página como prop compartilhada.
 *
 * O mapa de rótulos vivia escrito no `format.js` e a lista de "sem API" dentro
 * do componente de expedição. Contratar a quarta transportadora obrigava a
 * editar os dois — e esquecer um deles fazia ela aparecer na tela sem nome, ou
 * aparecer e ser recusada no servidor. Agora tudo sai do cadastro.
 */
export function useCarriers() {
    const page = usePage();

    /** Todas, inclusive as inativas: resgate antigo precisa dizer por quem foi. */
    const carriers = computed(() => page.props.carriers ?? []);

    const bySlug = computed(() => Object.fromEntries(carriers.value.map((c) => [c.slug, c])));

    /** As que podem receber uma expedição agora. */
    const activeCarriers = computed(() => carriers.value.filter((c) => c.active));

    /**
     * O nome de exibição. Cai no próprio slug quando o resgate aponta para uma
     * transportadora que foi apagada — melhor "ddm" do que um espaço em branco
     * onde deveria estar quem entregou.
     */
    const carrierLabel = (slug) => (slug ? (bySlug.value[slug]?.name ?? slug) : null);

    /**
     * Para onde o rastreio deste resgate leva, se leva a algum lugar.
     *
     * Duas origens: a transportadora de LINK grava a URL pronta, e a de CÓDIGO
     * ganha o link montado pelo molde do cadastro. Sem molde, o código é só
     * texto — e é assim que deve aparecer, não como um link morto.
     */
    const trackingLink = (redemption) => {
        if (!redemption) return null;

        if (redemption.tracking_url) return redemption.tracking_url;

        const molde = bySlug.value[redemption.carrier]?.tracking_url_template;
        const codigo = redemption.tracking_code;

        return molde && codigo ? molde.replace('{codigo}', encodeURIComponent(codigo)) : null;
    };

    return { carriers, activeCarriers, bySlug, carrierLabel, trackingLink };
}
