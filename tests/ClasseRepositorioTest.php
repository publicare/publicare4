<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\ConflitoDeVersao;
use Pbl\Conteudo\ObjetoRepositorio;
use PHPUnit\Framework\TestCase;

/** As regras de segurança dos dados ficam no repositório, não só na tela (fase 5c). */
final class ClasseRepositorioTest extends TestCase
{
    private Connection $con;
    private ClasseRepositorio $classes;
    private ObjetoRepositorio $objetos;
    private Classe $nota;

    protected function setUp(): void
    {
        $this->con = Conexao::memoria();
        (new Migrador($this->con))->migrar();
        $this->classes = new ClasseRepositorio($this->con);
        $this->objetos = new ObjetoRepositorio($this->con);
        $this->nota = $this->classes->criar('nota', [['nome' => 'texto', 'tipo' => 'texto'], ['nome' => 'livre', 'tipo' => 'numero']]);
        $this->objetos->criar(1, $this->nota, null, 'Uma nota', 'uma-nota', ['texto' => 'olá']);
    }

    public function testClasseAntigaContinuaComOMesmoJsonEValoresPadrao(): void
    {
        self::assertSame('[{"nome":"texto","tipo":"texto","indexavel":false},{"nome":"livre","tipo":"numero","indexavel":false}]', $this->con->fetchOne('SELECT propriedades FROM classe WHERE id = ?', [$this->nota->id]));
        $this->con->insert('classe', ['nome' => 'velha', 'propriedades' => '[]']); // como gravava o código anterior
        $velha = $this->classes->porNome('velha') ?? self::fail();
        self::assertSame('', $velha->descricao);
        self::assertTrue($velha->aceitaFilhos);
        self::assertSame([], $velha->filhas);
        self::assertSame(1, $velha->versao);
    }

    public function testUsoContaObjetosAtuaisEHistorico(): void
    {
        $o = $this->objetos->porUrl(1, 'uma-nota') ?? self::fail();
        $this->objetos->criar(1, $this->nota, null, 'Outra', 'outra', ['texto' => 'x']);
        self::assertSame(['texto' => ['objetos' => 2, 'historico' => 2], 'livre' => ['objetos' => 0, 'historico' => 0]], $this->classes->usoDasPropriedades($this->nota));

        $this->objetos->atualizar($o->id, ['dados' => ['texto' => null]]);
        self::assertSame(['objetos' => 1, 'historico' => 2], $this->classes->usoDasPropriedades($this->nota)['texto']);
        self::assertSame([$this->nota->id => 2], $this->classes->objetosPorClasse());
    }

    public function testRemoverOuMudarTipoDePropriedadeComValorEhRecusado(): void
    {
        foreach ([
            [['nome' => 'livre', 'tipo' => 'numero']],
            [['nome' => 'texto', 'tipo' => 'html'], ['nome' => 'livre', 'tipo' => 'numero']],
            [['nome' => 'texto', 'tipo' => 'texto', 'indexavel' => true], ['nome' => 'livre', 'tipo' => 'numero']],
        ] as $propriedades) {
            try {
                $this->classes->atualizar($this->nota->id, 1, $propriedades, []);
                self::fail('deveria recusar');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('texto tem valores em 1', $e->getMessage());
            }
        }
        self::assertSame(1, $this->classes->porId($this->nota->id)?->versao);
    }

    public function testArquivarRotularEMexerNoQueNaoTemValorFunciona(): void
    {
        $nova = $this->classes->atualizar($this->nota->id, 1, [
            ['nome' => 'livre', 'tipo' => 'data', 'rotulo' => '  Quando  ', 'obrigatorio' => true],
            ['nome' => 'texto', 'tipo' => 'texto', 'arquivada' => true],
        ], ['descricao' => 'Notas', 'aceita_filhos' => false, 'filhas' => ['nota']]);

        self::assertSame(2, $nova->versao);
        self::assertSame([
            ['nome' => 'livre', 'tipo' => 'data', 'indexavel' => false, 'rotulo' => 'Quando', 'obrigatorio' => true],
            ['nome' => 'texto', 'tipo' => 'texto', 'indexavel' => false, 'arquivada' => true],
        ], $this->classes->porId($this->nota->id)?->propriedades);
        self::assertSame([], $nova->filhas, 'sem filhos, a lista de filhas é descartada');
        $o = $this->objetos->porUrl(1, 'uma-nota') ?? self::fail();
        self::assertSame('olá', $this->objetos->atualizar($o->id, ['titulo' => 'Renomeada'])->dados['texto'], 'propriedade arquivada continua valendo');
    }

    public function testVersaoOtimistaNaClasse(): void
    {
        $this->classes->atualizar($this->nota->id, 1, $this->nota->propriedades, ['descricao' => 'primeira']);

        $this->expectException(ConflitoDeVersao::class);
        $this->classes->atualizar($this->nota->id, 1, $this->nota->propriedades, ['descricao' => 'segunda']);
    }

    public function testValidacoesDeEntrada(): void
    {
        $invalidas = [
            'repetida' => [[['nome' => 'a', 'tipo' => 'texto'], ['nome' => 'a', 'tipo' => 'numero']], []],
            'rótulo longo' => [[['nome' => 'a', 'tipo' => 'texto', 'rotulo' => str_repeat('r', 101)]], []],
            'rótulo com controle' => [[['nome' => 'a', 'tipo' => 'texto', 'rotulo' => "a\tb"]], []],
            'filha inexistente' => [[], ['filhas' => ['nao_existe']]],
            'descrição longa' => [[], ['descricao' => str_repeat('d', 2001)]],
            'nome longo' => [[['nome' => str_repeat('a', 65), 'tipo' => 'texto']], []],
        ];
        foreach ($invalidas as $caso => [$props, $meta]) {
            try {
                $this->classes->criar('caso_' . count($this->classes->todas()), $props, $meta);
                self::fail($caso);
            } catch (\InvalidArgumentException) {
                self::assertCount(2, $this->classes->todas(), "{$caso}: nada foi criado (só `pagina` e `formulario`)");
            }
        }
        $propria = $this->classes->criar('pasta', [], ['filhas' => ['pasta']]);
        self::assertSame(['pasta'], $propria->filhas, 'pode aceitar a si mesma');
        $comRef = $this->classes->atualizar($propria->id, 1, [['nome' => 'mae', 'tipo' => 'ref', 'classe' => 'pasta']], ['filhas' => ['pasta']]);
        self::assertSame('pasta', $comRef->propriedades[0]['classe'] ?? null, 'referência à própria classe');
    }

    public function testApagarSoSemObjetosESemDependentes(): void
    {
        try {
            $this->classes->apagar($this->nota->id, 1);
            self::fail('tem objeto');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('usada por 1', $e->getMessage());
        }
        $alvo = $this->classes->criar('alvo', []);
        $this->classes->criar('aponta', [['nome' => 'para', 'tipo' => 'ref', 'classe' => 'alvo']]);
        self::assertSame(['aponta'], $this->classes->dependentes($alvo));
        try {
            $this->classes->apagar($alvo->id, 1);
            self::fail('tem dependente');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('aponta', $e->getMessage());
        }
        $livre = $this->classes->criar('livre_de_tudo', []);
        try {
            $this->classes->apagar($livre->id, 9);
            self::fail('versão velha');
        } catch (ConflitoDeVersao) {
            self::assertNotNull($this->classes->porId($livre->id));
        }
        $this->classes->apagar($livre->id, 1);
        self::assertNull($this->classes->porId($livre->id));
    }
}
