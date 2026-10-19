<script setup>
import { computed } from 'vue';
import {
    AlertTriangle, ArrowRight, Bell, Boxes, Building2, Calendar, ChartColumn, Check,
    ChevronDown, ChevronLeft, ChevronRight, ClipboardList, Copy, Download, ExternalLink,
    Eye, EyeOff, FileText, Folder, Gift, Headset, Hourglass, House, Image, ImagePlus, Info, LayoutDashboard, LogOut, Mail, Menu,
    Settings,
    MessageCircle, MoreVertical, Package, PackageCheck, Percent, Plus, RefreshCw, Search,
    ShieldCheck, Trash2, Truck, Upload, Users, X,
} from 'lucide-vue-next';

/**
 * Ponte para o Lucide mantendo os nomes que as 19 páginas já usam.
 *
 * O mapa existe para a troca não virar um "renomeie 200 chamadas": `name` segue
 * sendo o papel no produto ("imports", "benefits"), não o nome do desenho.
 * Ícone desconhecido cai num neutro em vez de sumir da tela — buraco silencioso
 * é pior que ícone genérico.
 */
const props = defineProps({
    name: { type: String, required: true },
    size: { type: Number, default: 16 },
    strokeWidth: { type: Number, default: 1.8 },
});

const MAP = {
    // Navegação
    dashboard: LayoutDashboard,
    home: House,
    projects: Folder,
    redemptions: Gift,
    imports: Upload,
    catalog: Package,
    users: Users,
    companies: Building2,
    benefits: Percent,
    emails: Mail,
    reports: ChartColumn,
    audit: ShieldCheck,
    logout: LogOut,

    // Parametrização (Customizações)
    settings: Settings,
    imageAdd: ImagePlus,

    // Estrutura
    menu: Menu,
    close: X,
    more: MoreVertical,
    plus: Plus,
    search: Search,
    eye: Eye,
    eyeOff: EyeOff,
    chevronDown: ChevronDown,
    chevronLeft: ChevronLeft,
    chevronRight: ChevronRight,
    arrowRight: ArrowRight,
    external: ExternalLink,
    copy: Copy,
    download: Download,
    upload: Upload,
    image: Image,
    // Campos de um formulário (Customizações: "Definir campos para resgate").
    fields: ClipboardList,
    // Atendimento: o contato de suporte no portal do Colaborador.
    support: Headset,
    trash: Trash2,
    refresh: RefreshCw,
    bell: Bell,
    calendar: Calendar,

    // Estado
    check: Check,
    alert: AlertTriangle,
    info: FileText,
    // O "i" de informação, para o aviso discreto (o `info` acima é a folha).
    infoCircle: Info,
    clock: Hourglass,
    gift: Gift,
    truck: Truck,
    // Canal de contato (UAT "Fale com a WeGift"): o Lucide não traz marca
    // registrada, então o balão de conversa representa o WhatsApp.
    whatsapp: MessageCircle,

    // Etapas do pipeline — cada uma com desenho próprio, para a esteira ser
    // reconhecida pela forma e não só pela cor.
    stageDesign: ClipboardList,
    stageProduction: Hourglass,
    stageAssembly: PackageCheck,
    stageShipping: Truck,
    stageStock: Boxes,
    stageDone: Check,
};

/**
 * Cinco chamadas escrevem o nome em kebab-case ("chevron-left"), e cair no
 * neutro transformava toda seta de "voltar" do sistema numa caixa. Aceitar as
 * duas grafias é mais barato — e mais durável — que caçar as ocorrências.
 */
const normalize = (name) => String(name).replace(/-(\w)/g, (_, letter) => letter.toUpperCase());

const component = computed(() => {
    const icon = MAP[props.name] ?? MAP[normalize(props.name)];

    if (!icon && import.meta.env.DEV) {
        console.warn(`[UiIcon] ícone "${props.name}" não está mapeado em UiIcon.vue`);
    }

    return icon ?? Package;
});
</script>

<template>
    <component
        :is="component"
        class="shrink-0"
        :size="size"
        :stroke-width="strokeWidth"
        aria-hidden="true"
        focusable="false"
    />
</template>
