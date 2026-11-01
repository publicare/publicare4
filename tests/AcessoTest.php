<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Doctrine\DBAL\Connection;
use Pbl\Acesso\Acao;
use Pbl\Acesso\Auditoria;
use Pbl\Acesso\Perfil;
use Pbl\Acesso\Permissoes;
use Pbl\Acesso\SenhaPolitica;
use Pbl\Acesso\Usuario;
use Pbl\Acesso\UsuarioRepositorio;
use Pbl\Banco\Conexao;
use Pbl\Banco\Migrador;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\ObjetoRepositorio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Contas, senhas, perfis herdados pela árvore e o ponto único de decisão de permissão (ADR 0011). */
final class AcessoTest extends TestCase
{
    private Connection $con;
    private UsuarioRepositorio $usuarios;
    private Permissoes $permissoes;
    private ObjetoRepositorio $objetos;
    private Auditoria $auditoria;
    private Classe $pagina;
    private const SENHA = 'uma senha bem longa 2026';

    protected function setUp(): void
    {
        $this->con = Conexao::memoria();
        (new Migrador($this->con))->migrar();
        $this->auditoria = new Auditoria($this->con);
        $this->usuarios = new UsuarioRepositorio($this->con, $this->auditoria);
        $this->permissoes = new Permissoes($this->con);
        $this->objetos = new ObjetoRepositorio($this->con);
        $this->pagina = (new ClasseRepositorio($this->con))->criar('pagina', []);
    }

    private function usuario(string $login, bool $admin = false): Usuario
    {
        return $this->usuarios->criar($login, "{$login}@exemplo.org", ucfirst($login), self::SENHA, $admin);
    }

    private function objeto(string $url, ?int $pai = null, ?int $porUsuario = null, int $site = 1): Objeto
    {
        return $this->objetos->criar($site, $this->pagina, $pai, $url, $url, [], 'publicado', ['criado_por' => $porUsuario]);
    }

    public function testSenhaEGuardadaComArgon2idOuBcryptNuncaEmTexto(): void
    {
        $this->usuario('ana');
        $hash = (string) $this->con->fetchOne("SELECT senha_hash FROM usuario WHERE login = 'ana'");

        self::assertStringNotContainsString(self::SENHA, $hash);
        self::assertTrue(str_starts_with($hash, defined('PASSWORD_ARGON2ID') ? '$argon2id$' : '$2y$'));
        self::assertTrue(password_verify(self::SENHA, $hash));
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function contasInvalidas(): iterable
    {
        yield 'login curto' => ['a', 'a@exemplo.org', 'Nome', 'uma senha bem longa 2026'];
        yield 'login com maiúscula e espaço' => ['Ana Maria', 'a@exemplo.org', 'Nome', 'uma senha bem longa 2026'];
        yield 'login com ponto no fim' => ['ana.', 'a@exemplo.org', 'Nome', 'uma senha bem longa 2026'];
        yield 'e-mail inválido' => ['ana', 'sem-arroba', 'Nome', 'uma senha bem longa 2026'];
        yield 'nome vazio' => ['ana', 'a@exemplo.org', '  ', 'uma senha bem longa 2026'];
        yield 'senha curta' => ['ana', 'a@exemplo.org', 'Nome', 'curta123'];
        yield 'senha comum' => ['ana', 'a@exemplo.org', 'Nome', 'password123'];
        yield 'senha repetida' => ['ana', 'a@exemplo.org', 'Nome', 'aaaaaaaaaaaa'];
        yield 'senha igual ao login' => ['usuariolongo01', 'a@exemplo.org', 'Nome', 'usuariolongo01'];
    }

    #[DataProvider('contasInvalidas')]
    public function testContaInvalidaERecusada(string $login, string $email, string $nome, string $senha): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->usuarios->criar($login, $email, $nome, $senha);
    }

    public function testLoginDuplicadoIgnoraMaiusculasEPoliticaDeSenha(): void
    {
        $this->usuario('ana');

        try {
            $this->usuarios->criar('ANA', 'outra@exemplo.org', 'Outra', self::SENHA);
            self::fail('login duplicado');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('já existe', $e->getMessage());
        }
        self::assertNull(SenhaPolitica::problema(self::SENHA));
        self::assertNotNull(SenhaPolitica::problema(str_repeat('x', SenhaPolitica::MAXIMO + 1)));
    }

    public function testAutenticarAceitaSenhaCertaERecusaOResto(): void
    {
        $this->usuario('ana');

        self::assertSame('ana', $this->usuarios->autenticar('ana', self::SENHA)?->login);
        self::assertSame('ana', $this->usuarios->autenticar('  ANA ', self::SENHA)?->login, 'login sem diferenciar maiúsculas');
        self::assertNotNull($this->con->fetchOne("SELECT ultimo_acesso FROM usuario WHERE login = 'ana'"));
        self::assertNull($this->usuarios->autenticar('ana', 'senha errada de verdade'));
        self::assertNull($this->usuarios->autenticar('ninguem', self::SENHA), 'login inexistente: mesma resposta');
        self::assertNull($this->usuarios->autenticar('ana', ''));
    }

    public function testUsuarioInativoNaoEntraEReativarDevolve(): void
    {
        $u = $this->usuario('ana');
        $this->usuarios->definirAtivo($u->id, false);
        self::assertNull($this->usuarios->autenticar('ana', self::SENHA));

        $this->usuarios->definirAtivo($u->id, true);
        self::assertNotNull($this->usuarios->autenticar('ana', self::SENHA));
    }

    public function testHashAntigoEAtualizadoNoLogin(): void
    {
        $u = $this->usuario('ana');
        $this->con->update('usuario', ['senha_hash' => password_hash(self::SENHA, PASSWORD_BCRYPT, ['cost' => 4])], ['id' => $u->id]);

        self::assertNotNull($this->usuarios->autenticar('ana', self::SENHA));

        $hash = (string) $this->con->fetchOne('SELECT senha_hash FROM usuario WHERE id = ?', [$u->id]);
        self::assertFalse(password_needs_rehash($hash, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT));
    }

    public function testTrocarSenhaInvalidaAAntigaEValidaAPolitica(): void
    {
        $u = $this->usuario('ana');
        $this->usuarios->definirSenha($u->id, 'outra senha igualmente longa');

        self::assertNull($this->usuarios->autenticar('ana', self::SENHA));
        self::assertNotNull($this->usuarios->autenticar('ana', 'outra senha igualmente longa'));
        $this->expectException(\InvalidArgumentException::class);
        $this->usuarios->definirSenha($u->id, 'curta');
    }

    public function testAuditoriaRegistraEPorNenhumaHoraGuardaSenha(): void
    {
        $u = $this->usuario('ana');
        $this->usuarios->definirSenha($u->id, 'outra senha igualmente longa', 'cli');
        $this->usuarios->definirAtivo($u->id, false, 'cli');
        $raiz = $this->objeto('raiz');
        $this->usuarios->conceder($u->id, $raiz->id, Perfil::Editor, 'cli');
        $this->usuarios->revogar($u->id, $raiz->id, 'cli');

        $acoes = array_column($this->auditoria->recentes(), 'acao');
        self::assertSame(['acesso.revogar', 'acesso.conceder', 'usuario.desativar', 'senha.trocar', 'usuario.criar'], $acoes);
        $tudo = json_encode($this->con->fetchAllAssociative('SELECT * FROM auditoria'), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::SENHA, $tudo);
        self::assertStringNotContainsString('outra senha', $tudo);
        self::assertStringNotContainsString('argon2', $tudo);
    }

    public function testConcederRevogarEAcessos(): void
    {
        $u = $this->usuario('ana');
        $a = $this->objeto('a');

        $this->usuarios->conceder($u->id, $a->id, Perfil::Leitor);
        $this->usuarios->conceder($u->id, $a->id, Perfil::Editor); // substitui, não duplica

        self::assertSame([['objeto_id' => $a->id, 'perfil' => Perfil::Editor]], $this->usuarios->acessosDe($u->id));
        $this->usuarios->revogar($u->id, $a->id);
        self::assertSame([], $this->usuarios->acessosDe($u->id));
        foreach ([fn () => $this->usuarios->conceder(999, $a->id, Perfil::Leitor), fn () => $this->usuarios->conceder($u->id, 999, Perfil::Leitor), fn () => $this->usuarios->revogar($u->id, $a->id)] as $op) {
            self::assertNotNull($this->recusa($op));
        }
    }

    public function testPerfilValeNaSubarvoreEPelaForcaMaior(): void
    {
        $ana = $this->usuario('ana');
        $raiz = $this->objeto('raiz');
        $a = $this->objeto('a', $raiz->id);
        $a1 = $this->objeto('a1', $a->id);
        $b = $this->objeto('b', $raiz->id);
        $this->usuarios->conceder($ana->id, $raiz->id, Perfil::Leitor);
        $this->usuarios->conceder($ana->id, $a->id, Perfil::Editor);

        self::assertSame(Perfil::Editor, $this->permissoes->perfilEm($ana, $a1), 'herdado de a (o mais forte)');
        self::assertSame(Perfil::Leitor, $this->permissoes->perfilEm($ana, $b), 'em b só vale o leitor da raiz');
        self::assertTrue($this->permissoes->pode($ana, Acao::Publicar, $a1));
        self::assertFalse($this->permissoes->pode($ana, Acao::Publicar, $b));
        self::assertTrue($this->permissoes->pode($ana, Acao::Ver, $b));
    }

    public function testMatrizDeAcoesPorPerfil(): void
    {
        $raiz = $this->objeto('raiz');
        $obj = $this->objeto('obj', $raiz->id);
        $esperado = [
            Perfil::Leitor->value => ['ver' => true, 'criar' => false, 'editar' => false, 'publicar' => false, 'apagar' => false, 'administrar' => false],
            Perfil::Editor->value => ['ver' => true, 'criar' => true, 'editar' => true, 'publicar' => true, 'apagar' => true, 'administrar' => false],
            Perfil::Autor->value => ['ver' => true, 'criar' => true, 'editar' => false, 'publicar' => false, 'apagar' => false, 'administrar' => false],
        ];
        foreach ($esperado as $perfil => $acoes) {
            $u = $this->usuario("u{$perfil}");
            $this->usuarios->conceder($u->id, $raiz->id, Perfil::from($perfil));
            foreach (Acao::cases() as $acao) {
                self::assertSame($acoes[$acao->value], $this->permissoes->pode($u, $acao, $obj), "{$perfil} / {$acao->value}");
            }
        }
    }

    public function testAutorEditaSoOQueEleCriou(): void
    {
        $ana = $this->usuario('ana');
        $bia = $this->usuario('bia');
        $raiz = $this->objeto('raiz');
        $this->usuarios->conceder($ana->id, $raiz->id, Perfil::Autor);
        $dela = $this->objeto('dela', $raiz->id, $ana->id);
        $alheio = $this->objeto('alheio', $raiz->id, $bia->id);
        $sem = $this->objeto('sem-dono', $raiz->id);

        self::assertTrue($this->permissoes->pode($ana, Acao::Editar, $dela));
        self::assertFalse($this->permissoes->pode($ana, Acao::Editar, $alheio));
        self::assertFalse($this->permissoes->pode($ana, Acao::Editar, $sem), 'sem dono conhecido: não edita');
        self::assertTrue($this->permissoes->pode($ana, Acao::Ver, $alheio));
        self::assertFalse($this->permissoes->pode($ana, Acao::Apagar, $dela), 'nem o próprio: apagar é do editor');
        self::assertSame($ana->id, $this->objetos->porId($dela->id)?->criadoPor);
    }

    public function testAdministradorPodeTudoInativoNada(): void
    {
        $adm = $this->usuario('adm', true);
        $o = $this->objeto('o');

        foreach (Acao::cases() as $acao) {
            self::assertTrue($this->permissoes->pode($adm, $acao, $o));
        }
        $this->usuarios->definirAtivo($adm->id, false);
        $inativo = $this->usuarios->porId($adm->id);
        self::assertNotNull($inativo);
        foreach (Acao::cases() as $acao) {
            self::assertFalse($this->permissoes->pode($inativo, $acao, $o), 'inativo não pode nem sendo administrador');
        }
    }

    public function testNaoHaAcessoForaDaSubarvoreNemEntreSites(): void
    {
        $ana = $this->usuario('ana');
        $raiz = $this->objeto('raiz');
        $irmao = $this->objeto('irmao');
        $this->usuarios->conceder($ana->id, $raiz->id, Perfil::Editor);
        $outroSite = $this->objeto('raiz', null, null, 2);

        self::assertFalse($this->permissoes->pode($ana, Acao::Editar, $irmao), 'outra árvore');
        self::assertFalse($this->permissoes->pode($ana, Acao::Ver, $outroSite), 'outro site');
    }

    public function testCaminhoNaoCasaPorPrefixoDeTexto(): void
    {
        $ana = $this->usuario('ana');
        $primeiro = $this->objeto('o1');
        for ($i = 2; $i <= 11; $i++) {
            $ultimo = $this->objeto("o{$i}");
        }
        $this->usuarios->conceder($ana->id, $primeiro->id, Perfil::Editor);

        self::assertSame('/1/', $primeiro->caminho);
        self::assertSame('/11/', $ultimo->caminho);
        self::assertNull($this->permissoes->perfilEm($ana, $ultimo), '/1/ não cobre /11/');
    }

    public function testCriarSobExigePaiOuAdministrador(): void
    {
        $ana = $this->usuario('ana');
        $adm = $this->usuario('adm', true);
        $a = $this->objeto('a');
        $this->usuarios->conceder($ana->id, $a->id, Perfil::Autor);

        self::assertTrue($this->permissoes->podeCriarSob($ana, $a));
        self::assertFalse($this->permissoes->podeCriarSob($ana, null), 'na raiz do site só o administrador');
        self::assertTrue($this->permissoes->podeCriarSob($adm, null));
    }

    public function testQuemMudouFicaNoHistoricoDeVersoes(): void
    {
        $ana = $this->usuario('ana');
        $o = $this->objeto('doc', null, $ana->id);
        $this->objetos->atualizar($o->id, ['titulo' => 'Novo'], 'ajuste', null, false, $ana->id);
        $this->objetos->atualizar($o->id, ['titulo' => 'Sem autor conhecido']);

        self::assertSame([null, $ana->id, $ana->id], array_column($this->objetos->versoes($o->id), 'usuario_id'));
    }

    /** Mensagem do erro de validação que a operação provocou, ou null se ela foi aceita. */
    private function recusa(callable $operacao): ?string
    {
        try {
            $operacao();
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function testBcryptDeReservaNaoTruncaSenhasLongas(): void
    {
        $bcrypt = new UsuarioRepositorio($this->con, $this->auditoria, PASSWORD_BCRYPT);
        $prefixo = str_repeat('x', 72);
        $bcrypt->criar('longa', 'longa@exemplo.org', 'Longa', $prefixo . 'AAAA-final-certo');

        self::assertStringStartsWith('$2y$', (string) $this->con->fetchOne("SELECT senha_hash FROM usuario WHERE login = 'longa'"));
        self::assertNotNull($bcrypt->autenticar('longa', $prefixo . 'AAAA-final-certo'));
        self::assertNull($bcrypt->autenticar('longa', $prefixo . 'BBBB-final-errado'), 'mesmo começo de 72 bytes, final diferente: não entra');
        self::assertNull($bcrypt->autenticar('longa', $prefixo), 'só o começo também não');
    }

    public function testHashBcryptSemPreHashAntigoAindaEntraEViraNovo(): void
    {
        $bcrypt = new UsuarioRepositorio($this->con, $this->auditoria, PASSWORD_BCRYPT);
        $u = $bcrypt->criar('velha', 'velha@exemplo.org', 'Velha', self::SENHA);
        $this->con->update('usuario', ['senha_hash' => password_hash(self::SENHA, PASSWORD_BCRYPT, ['cost' => 4])], ['id' => $u->id]);

        self::assertNotNull($bcrypt->autenticar('velha', self::SENHA));

        $depois = (string) $this->con->fetchOne('SELECT senha_hash FROM usuario WHERE id = ?', [$u->id]);
        self::assertFalse(password_verify(self::SENHA, $depois), 'regravado com o pré-hash');
        self::assertNotNull($bcrypt->autenticar('velha', self::SENHA));
    }

    public function testAlteracoesDeConteudoVaoParaAAuditoriaSemOConteudo(): void
    {
        $ana = $this->usuario('ana');
        $objetos = new ObjetoRepositorio($this->con, $this->auditoria);
        $classe = (new ClasseRepositorio($this->con))->criar('nota', [['nome' => 'texto', 'tipo' => 'texto']]);
        $o = $objetos->criar(1, $classe, null, 'Título confidencial', 'nota', ['texto' => 'segredo do conteúdo'], 'privado', ['criado_por' => $ana->id]);
        $objetos->atualizar($o->id, ['status' => 'publicado', 'dados' => ['texto' => 'outro segredo']], 'ajuste', null, false, $ana->id);
        $filho = $objetos->criar(1, $classe, null, 'Filho', 'filho', []);
        $objetos->mover($filho->id, $o->id, 'organiza', $ana->id);

        $acoes = array_column($this->auditoria->recentes(), 'acao');
        self::assertSame(['objeto.mover', 'objeto.criar', 'objeto.estado', 'objeto.atualizar', 'objeto.criar', 'usuario.criar'], $acoes);
        $linhas = $this->con->fetchAllAssociative("SELECT * FROM auditoria WHERE acao LIKE 'objeto.%' ORDER BY id");
        self::assertSame('usuario:' . $ana->id, $linhas[0]['ator']);
        self::assertStringContainsString('dados.texto', (string) $linhas[1]['detalhe'], 'diz QUE campo mudou');
        $tudo = json_encode($linhas, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach (['Título confidencial', 'segredo do conteúdo', 'outro segredo'] as $conteudo) {
            self::assertStringNotContainsString($conteudo, $tudo, 'o conteúdo não vai para a auditoria');
        }
    }
}
