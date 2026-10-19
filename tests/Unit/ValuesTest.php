<?php

namespace Tests\Unit;

use App\Support\Values\Cep;
use App\Support\Values\Cnpj;
use App\Support\Values\Cpf;
use App\Support\Values\Email;
use App\Support\Values\Telefone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Os objetos de valor.
 *
 * O que estes testes protegem não é o algoritmo — é a GARANTIA: não existe
 * caminho para construir um valor inválido. Se um dia alguém acrescentar um
 * construtor público "só para um caso especial", estes testes continuam
 * passando e a garantia acaba em silêncio; por isso o último teste confere que
 * o construtor segue privado.
 */
class ValuesTest extends TestCase
{
    // ── CNPJ ────────────────────────────────────────────────────────────────

    public function test_cnpj_aceita_com_e_sem_mascara(): void
    {
        $this->assertSame('11222333000181', (string) Cnpj::de('11.222.333/0001-81'));
        $this->assertSame('11222333000181', (string) Cnpj::de('11222333000181'));
    }

    /** A regra que NENHUMA das três portas tinha. */
    public function test_cnpj_recusa_digito_verificador_errado(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cnpj::de('11222333000182');
    }

    /** Era o que passava em `digits:14` e fundia empresas. */
    public function test_cnpj_recusa_sequencia_repetida(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cnpj::de('11111111111111');
    }

    /**
     * O caso real: o fallback manual aceitava `['required','string']`, fazia
     * `preg_replace` e gravava string vazia — que o `firstOrCreate` casava com
     * a empresa vazia existente, fundindo dois clientes numa linha só.
     */
    public function test_cnpj_recusa_o_texto_que_fundia_empresas(): void
    {
        $this->assertNull(Cnpj::tentar('a definir'));
        $this->assertNull(Cnpj::tentar('—'));
        $this->assertNull(Cnpj::tentar(''));
    }

    public function test_cnpj_formata_para_leitura(): void
    {
        $this->assertSame('11.222.333/0001-81', Cnpj::de('11222333000181')->formatado());
    }

    /** `===` compararia instâncias e diria "não" — bug chato de achar. */
    public function test_cnpj_compara_por_valor(): void
    {
        $a = Cnpj::de('11.222.333/0001-81');
        $b = Cnpj::de('11222333000181');

        $this->assertTrue($a->equals($b));
        $this->assertNotSame($a, $b);
    }

    // ── CPF ─────────────────────────────────────────────────────────────────

    public function test_cpf_aceita_valido_com_e_sem_mascara(): void
    {
        $this->assertSame('52998224725', (string) Cpf::de('529.982.247-25'));
        $this->assertSame('52998224725', (string) Cpf::de('52998224725'));
    }

    public function test_cpf_recusa_digito_verificador_errado(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cpf::de('52998224726');
    }

    public function test_cpf_recusa_sequencia_repetida(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cpf::de('11111111111');
    }

    /** Duas das três portas aceitavam `'string'` — qualquer coisa passava. */
    public function test_cpf_recusa_o_que_as_portas_aceitavam(): void
    {
        $this->assertNull(Cpf::tentar('não tem'));
        $this->assertNull(Cpf::tentar('123'));
    }

    public function test_cpf_formata_para_leitura(): void
    {
        $this->assertSame('529.982.247-25', Cpf::de('52998224725')->formatado());
    }

    // ── E-mail ──────────────────────────────────────────────────────────────

    /** O defeito real: escrita em minúsculas, leitura crua, Postgres distingue. */
    public function test_email_normaliza_caixa_e_espacos(): void
    {
        $this->assertSame('marina@empresa.com.br', (string) Email::de('  Marina@Empresa.COM.BR  '));
    }

    public function test_email_normalizar_funciona_sem_construir_o_objeto(): void
    {
        // O login precisa disto: ali o e-mail pode ser inválido (é tentativa de
        // acesso, não cadastro) e ainda assim tem de casar com o que foi gravado.
        $this->assertSame('joao@x.com', Email::normalizar('  JOAO@X.com '));
    }

    public function test_email_compara_por_valor_ignorando_caixa(): void
    {
        $this->assertTrue(Email::de('JOAO@x.com')->equals(Email::de('joao@X.COM')));
    }

    public function test_email_recusa_invalido(): void
    {
        $this->assertNull(Email::tentar('joao'));
        $this->assertNull(Email::tentar('joao@'));
        $this->assertNull(Email::tentar('@x.com'));
    }

    public function test_email_recusa_acima_do_limite_da_coluna(): void
    {
        $this->assertNull(Email::tentar(str_repeat('a', 250).'@empresa.com.br'));
    }

    // ── CEP ─────────────────────────────────────────────────────────────────

    public function test_cep_aceita_com_e_sem_mascara(): void
    {
        $this->assertSame('80010000', (string) Cep::de('80010-000'));
        $this->assertSame('80010000', (string) Cep::de('80010000'));
    }

    /** O endereço de entrega aceitava `['required','string']`. */
    public function test_cep_recusa_o_que_o_portal_aceitava(): void
    {
        $this->assertNull(Cep::tentar('não sei'));
        $this->assertNull(Cep::tentar('8001-000'));
        $this->assertNull(Cep::tentar('00000000'));
    }

    public function test_cep_formata_para_leitura(): void
    {
        $this->assertSame('80010-000', Cep::de('80010000')->formatado());
    }

    // ── Telefone ────────────────────────────────────────────────────────────

    /** Cada RH escreve de um jeito; todos viram o mesmo número. */
    public function test_telefone_normaliza_para_mais_55(): void
    {
        foreach ([
            '+5541999990000',
            '5541999990000',
            '41999990000',
            '(41) 99999-0000',
            '(41) 9 9999-0000',
            '+55 (41) 99999-0000',
            '55 41 99999-0000',
            '041 99999-0000',
            '0055 41 99867.9696',
            '0 15 41 99999-0000', // com código de operadora
        ] as $entrada) {
            $this->assertSame('+5541999990000', (string) Telefone::de($entrada), $entrada);
        }
    }

    public function test_telefone_fixo_tambem_entra(): void
    {
        $this->assertSame('+554133334444', (string) Telefone::de('(41) 3333-4444'));
        $this->assertSame('+554133334444', (string) Telefone::de('55 41 3333-4444'));
    }

    /** DDD 55 (RS) não se confunde com o código do país. */
    public function test_telefone_com_ddd_55(): void
    {
        $this->assertSame('+5555999998888', (string) Telefone::de('(55) 99999-8888'));
        $this->assertSame('+555533334444', (string) Telefone::de('(55) 3333-4444'));
    }

    public function test_telefone_recusa_o_que_nao_e_numero_com_ddd(): void
    {
        $this->assertNull(Telefone::tentar('99999-0000')); // sem DDD
        $this->assertNull(Telefone::tentar('não tenho'));
        $this->assertNull(Telefone::tentar('+1 415 555 2671')); // fora do Brasil
        $this->assertNull(Telefone::tentar('001 415 555 2671'));
        $this->assertNull(Telefone::tentar('(41) 99999-00001234')); // dígito demais
    }

    // ── A garantia ──────────────────────────────────────────────────────────

    /**
     * O construtor privado é a linha mais importante de cada classe: sem ela,
     * `new Cnpj('a definir')` volta a ser possível e todo o resto é decoração.
     */
    public function test_nenhum_valor_pode_ser_construido_por_fora(): void
    {
        foreach ([Cnpj::class, Cpf::class, Email::class, Cep::class, Telefone::class] as $tipo) {
            $construtor = (new \ReflectionClass($tipo))->getConstructor();

            $this->assertNotNull($construtor, "{$tipo} deveria ter construtor.");
            $this->assertTrue(
                $construtor->isPrivate(),
                "{$tipo}: o construtor deixou de ser privado — a garantia acabou.",
            );
        }
    }

    /** Vazio é assunto do `required`/`nullable`, não da validação de formato. */
    public function test_tentar_devolve_null_para_vazio_em_todos(): void
    {
        foreach ([Cnpj::class, Cpf::class, Email::class, Cep::class, Telefone::class] as $tipo) {
            $this->assertNull($tipo::tentar(null), "{$tipo} com null");
            $this->assertNull($tipo::tentar('   '), "{$tipo} com espaços");
        }
    }
}
