<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Perfil;
use PHPUnit\Framework\TestCase;

/** Telas de contas da administração, de ponta a ponta (ADR 0012, fase 5d). */
final class AdminContasTest extends TestCase
{
    use AdminAuxiliar;

    /** @return list<string> as rotas POST de contas que existem, para a conta `$id` */
    private function postsDe(int $id): array
    {
        return ['/_pbl/contas/nova', ...array_map(static fn (string $a): string => "/_pbl/conta/{$id}/{$a}", ['editar', 'senha', 'desativar', 'reativar', 'conceder', 'revogar'])];
    }

    public function testSemLoginTodaTelaMandaParaEntrarEOPostNaoGrava(): void
    {
        $id = $this->ids['editor'];
        foreach (['/_pbl/contas', '/_pbl/contas/nova', "/_pbl/conta/{$id}"] as $caminho) {
            $r = $this->req('GET', $caminho);
            self::assertSame(303, $r->getStatusCode(), $caminho);
            self::assertSame('/_pbl/entrar', $r->headers->get('Location'));
        }
        foreach ($this->postsDe($id) as $caminho) {
            self::assertSame(303, $this->req('POST', $caminho, null, ['login' => 'invasor', 'nome' => 'X', 'email' => 'x@exemplo.org', 'senha' => self::SENHA, 'senha2' => self::SENHA], self::ORIGEM)->getStatusCode(), $caminho);
        }
        self::assertNull($this->s->usuarios->porLogin('invasor'));
        self::assertSame('Editor', $this->s->usuarios->porId($id)?->nome);
    }

    public function testQuemNaoEhAdministradorNaoVeNemAlteraEATentativaFicaNaAuditoria(): void
    {
        $alvo = $this->ids['leitor'];
        foreach (['editor', 'leitor'] as $login) {
            $token = $this->token($login);
            foreach (['/_pbl/contas', '/_pbl/contas/nova', "/_pbl/conta/{$alvo}"] as $caminho) {
                self::assertSame(403, $this->req('GET', $caminho, $token)->getStatusCode(), "{$login} {$caminho}");
            }
            self::assertSame($this->html($this->req('GET', "/_pbl/conta/{$alvo}", $token)), $this->html($this->req('GET', '/_pbl/conta/999999', $token)), 'conta inexistente = sem permissão');
            self::assertStringNotContainsString('href="/_pbl/contas"', $this->html($this->req('GET', '/_pbl/', $token)), 'o link só aparece para administrador');
        }
        $token = $this->token('editor');
        $ataques = [
            '/_pbl/contas/nova' => ['login' => 'invasor', 'nome' => 'X', 'email' => 'x@exemplo.org', 'senha' => self::SENHA, 'senha2' => self::SENHA],
            "/_pbl/conta/{$alvo}/editar" => ['nome' => 'Hackeado', 'email' => 'h@exemplo.org', 'administrador' => '1'],
            "/_pbl/conta/{$alvo}/senha" => ['senha' => 'outra senha longa 2026', 'senha2' => 'outra senha longa 2026'],
            "/_pbl/conta/{$alvo}/desativar" => [],
            "/_pbl/conta/{$alvo}/conceder" => ['objeto' => (string) $this->inicioId, 'perfil' => 'editor'],
            "/_pbl/conta/{$alvo}/revogar" => ['objeto' => (string) $this->inicioId],
        ];
        foreach ($ataques as $caminho => $campos) {
            self::assertSame(403, $this->post($token, $caminho, $campos)->getStatusCode(), $caminho);
        }
        self::assertNull($this->s->usuarios->porLogin('invasor'));
        $c = $this->s->usuarios->porId($alvo);
        self::assertSame('Leitor', $c?->nome);
        self::assertFalse($c->administrador);
        self::assertTrue($c->ativo);
        self::assertNotNull($this->s->usuarios->autenticar('leitor', self::SENHA));
        self::assertSame([Perfil::Leitor], array_map(static fn (array $a): Perfil => $a['perfil'], $this->s->usuarios->acessosDe($alvo)));
        $negados = $this->auditoria('conta.negado');
        self::assertCount(count($ataques), $negados);
        self::assertSame('usuario:' . $this->ids['editor'], $negados[0]['ator']);
        self::assertStringNotContainsString('Hackeado', (string) json_encode($negados));
        self::assertStringNotContainsString(self::SENHA, (string) json_encode($negados));
    }

    public function testCsrfEOrigemSaoConferidosEmTodoPost(): void
    {
        $token = $this->token('admin');
        $outro = $this->token('editor');
        $alvo = $this->ids['editor'];
        $campos = ['login' => 'novo', 'nome' => 'Mudado', 'email' => 'm@exemplo.org', 'senha' => self::SENHA, 'senha2' => self::SENHA, 'objeto' => (string) $this->inicioId, 'perfil' => 'autor'];
        foreach ($this->postsDe($alvo) as $caminho) {
            self::assertSame(403, $this->req('POST', $caminho, $token, $campos, self::ORIGEM)->getStatusCode(), "{$caminho} sem token");
            self::assertSame(403, $this->req('POST', $caminho, $token, ['_csrf' => 'lixo'] + $campos, self::ORIGEM)->getStatusCode(), "{$caminho} token errado");
            self::assertSame(403, $this->req('POST', $caminho, $token, ['_csrf' => $this->csrf($outro)] + $campos, self::ORIGEM)->getStatusCode(), "{$caminho} token de outra sessão");
            self::assertSame(403, $this->req('POST', $caminho, $token, ['_csrf' => $this->csrf($token)] + $campos, ['HTTP_ORIGIN' => 'https://evil.example'])->getStatusCode(), "{$caminho} origem de fora");
            self::assertSame(403, $this->req('POST', $caminho, $token, ['_csrf' => [$this->csrf($token)]] + $campos, self::ORIGEM)->getStatusCode(), "{$caminho} token em lista");
        }
        self::assertNull($this->s->usuarios->porLogin('novo'));
        self::assertSame('Editor', $this->s->usuarios->porId($alvo)?->nome);
        self::assertTrue($this->s->usuarios->porId($alvo)->ativo);
    }

    public function testRotasInexistentesDao404(): void
    {
        $token = $this->token('admin');
        foreach (['/_pbl/conta/0', '/_pbl/conta/01', '/_pbl/conta/1/apagar', '/_pbl/conta/1/editar/x', '/_pbl/conta/99999999999'] as $caminho) {
            self::assertSame(404, $this->req('GET', $caminho, $token)->getStatusCode(), $caminho);
            self::assertSame(404, $this->post($token, $caminho, [])->getStatusCode(), $caminho);
        }
        self::assertSame(404, $this->req('GET', '/_pbl/conta/1/senha', $token)->getStatusCode(), 'ações só por POST');
        self::assertSame(404, $this->post($token, '/_pbl/contas', [])->getStatusCode());
        self::assertSame(404, $this->req('GET', '/_pbl/conta/999999', $token)->getStatusCode(), 'conta inexistente para o administrador');
        self::assertSame(404, $this->post($token, '/_pbl/conta/999999/editar', ['nome' => 'a', 'email' => 'a@b.co'])->getStatusCode());
    }

    public function testListaMostraContasEscapaTextoEOferecaLink(): void
    {
        $this->s->usuarios->atualizar($this->ids['leitor'], '<script>alert(1)</script>', 'leitor@exemplo.org');
        $r = $this->req('GET', '/_pbl/contas', $this->token('admin'));
        $html = $this->html($r);
        self::assertSame(200, $r->getStatusCode());
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        foreach (['admin', 'admin2', 'editor', 'leitor'] as $login) {
            self::assertStringContainsString('<code>' . $login . '</code>', $html);
            self::assertStringContainsString('href="/_pbl/conta/' . $this->ids[$login] . '"', $html);
        }
        self::assertStringContainsString('href="/_pbl/contas/nova"', $html);
        self::assertStringContainsString('href="/_pbl/contas"', $this->html($this->req('GET', '/_pbl/', $this->token('admin'))), 'link na barra para administrador');
        self::assertStringContainsString("default-src 'none'", (string) $r->headers->get('Content-Security-Policy'));
    }

    public function testCriarContaCaminhoFelizEAuditoriaSemDadosSensiveis(): void
    {
        $token = $this->token('admin');
        self::assertSame(200, $this->req('GET', '/_pbl/contas/nova', $token)->getStatusCode());
        $r = $this->post($token, '/_pbl/contas/nova', ['login' => 'Maria.Silva', 'nome' => 'Maria da Silva', 'email' => 'maria@exemplo.org', 'senha' => 'senha-da-maria-2026', 'senha2' => 'senha-da-maria-2026']);
        $c = $this->s->usuarios->porLogin('maria.silva');
        self::assertNotNull($c);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/conta/' . $c->id . '?ok=criada', $r->headers->get('Location'));
        self::assertFalse($c->administrador);
        self::assertNotNull($this->s->usuarios->autenticar('maria.silva', 'senha-da-maria-2026'));
        $log = $this->auditoria('usuario.criar');
        self::assertSame('usuario:' . $this->ids['admin'], $log[0]['ator']);
        self::assertStringNotContainsString('senha-da-maria', (string) json_encode($this->s->auditoria->recentes(500)));
        self::assertStringContainsString('Conta criada', $this->html($this->req('GET', '/_pbl/conta/' . $c->id . '?ok=criada', $token)));

        $this->post($token, '/_pbl/contas/nova', ['login' => 'chefe', 'nome' => 'Chefe', 'email' => 'chefe@exemplo.org', 'senha' => 'senha-do-chefe-2026', 'senha2' => 'senha-do-chefe-2026', 'administrador' => '1']);
        self::assertTrue($this->s->usuarios->porLogin('chefe')?->administrador);
    }

    public function testCriarContaRecusaDadosInvalidosSemGravarENaoDevolveASenha(): void
    {
        $token = $this->token('admin');
        $ok = ['login' => 'nova', 'nome' => 'Nova', 'email' => 'nova@exemplo.org', 'senha' => 'senha-valida-2026', 'senha2' => 'senha-valida-2026'];
        $casos = [
            'senha curta' => ['senha' => 'curta', 'senha2' => 'curta'],
            'senha óbvia' => ['senha' => 'senha123456', 'senha2' => 'senha123456'],
            'senhas diferentes' => ['senha2' => 'outra-senha-2026'],
            'login já existe' => ['login' => 'ADMIN'],
            'login inválido' => ['login' => 'a b'],
            'e-mail inválido' => ['email' => 'sem-arroba'],
            'nome vazio' => ['nome' => '   '],
            'nome com controle' => ['nome' => "ab\x07c"],
            'nome enorme' => ['nome' => str_repeat('a', 201)],
        ];
        foreach ($casos as $nome => $troca) {
            $r = $this->post($token, '/_pbl/contas/nova', $troca + $ok);
            self::assertSame(422, $r->getStatusCode(), $nome);
            self::assertStringContainsString('role="alert"', $this->html($r), $nome);
            self::assertStringNotContainsString('senha-valida-2026', $this->html($r), $nome . ': a senha não volta para a página');
        }
        self::assertNull($this->s->usuarios->porLogin('nova'));
        self::assertCount(4, $this->s->usuarios->todos());
        self::assertStringContainsString('pelo menos 10', $this->html($this->post($token, '/_pbl/contas/nova', $casos['senha curta'] + $ok)));
        self::assertSame(400, $this->post($token, '/_pbl/contas/nova', ['login' => ['x']] + $ok)->getStatusCode());
        self::assertSame(400, $this->post($token, '/_pbl/contas/nova', ['senha2' => null] + array_diff_key($ok, ['senha2' => 1]))->getStatusCode());
    }

    public function testEditarDadosEMarcaDeAdministrador(): void
    {
        $token = $this->token('admin');
        $alvo = $this->ids['editor'];
        $html = $this->html($this->req('GET', "/_pbl/conta/{$alvo}", $token));
        self::assertStringContainsString('value="Editor"', $html);
        self::assertStringContainsString('name="administrador"', $html);

        $r = $this->post($token, "/_pbl/conta/{$alvo}/editar", ['nome' => 'Editora Chefe', 'email' => 'chefe@exemplo.org', 'administrador' => '1']);
        self::assertSame(303, $r->getStatusCode());
        $c = $this->s->usuarios->porId($alvo);
        self::assertSame('Editora Chefe', $c?->nome);
        self::assertSame('chefe@exemplo.org', $c->email);
        self::assertTrue($c->administrador);
        self::assertSame('editor', $c->login, 'o login não muda');
        $log = (string) json_encode([...$this->auditoria('usuario.atualizar'), ...$this->auditoria('usuario.admin.dar')]);
        self::assertStringContainsString('nome,email', $log);
        self::assertStringNotContainsString('Editora Chefe', $log);
        self::assertStringNotContainsString('chefe@exemplo.org', $log);

        $this->post($token, "/_pbl/conta/{$alvo}/editar", ['nome' => 'Editora Chefe', 'email' => 'chefe@exemplo.org']);
        self::assertFalse($this->s->usuarios->porId($alvo)?->administrador, 'sem a caixa marcada, deixa de ser administrador');
        self::assertCount(1, $this->auditoria('usuario.admin.tirar'));
        self::assertCount(1, $this->auditoria('usuario.atualizar'), 'nada mudou nos dados: nada auditado');
    }

    public function testEditarRecusaDadosInvalidosEEscapaOQueVolta(): void
    {
        $token = $this->token('admin');
        $alvo = $this->ids['editor'];
        $r = $this->post($token, "/_pbl/conta/{$alvo}/editar", ['nome' => '"><script>x</script>', 'email' => 'invalido']);
        $html = $this->html($r);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringNotContainsString('<script>x', $html);
        self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        self::assertSame('Editor', $this->s->usuarios->porId($alvo)?->nome);
        self::assertSame(422, $this->post($token, "/_pbl/conta/{$alvo}/editar", ['nome' => '', 'email' => 'a@exemplo.org'])->getStatusCode());
        self::assertSame(400, $this->post($token, "/_pbl/conta/{$alvo}/editar", ['nome' => ['x'], 'email' => 'a@exemplo.org'])->getStatusCode());
        self::assertSame(400, $this->post($token, "/_pbl/conta/{$alvo}/editar", ['nome' => 'A'])->getStatusCode());
    }

    public function testNinguemTiraDeSiMesmoAMarcaDeAdministrador(): void
    {
        $token = $this->token('admin');
        $eu = $this->ids['admin'];
        self::assertStringNotContainsString('name="administrador"', $this->html($this->req('GET', "/_pbl/conta/{$eu}", $token)));
        $this->post($token, "/_pbl/conta/{$eu}/editar", ['nome' => 'Admin Novo', 'email' => 'admin@exemplo.org']); // forjado: sem a caixa
        self::assertTrue($this->s->usuarios->porId($eu)?->administrador);
        self::assertSame('Admin Novo', $this->s->usuarios->porId($eu)->nome);
    }

    public function testUltimoAdministradorAtivoNaoSaiDoPosto(): void
    {
        $token = $this->token('admin');
        $this->s->usuarios->definirAtivo($this->ids['admin2'], false);
        self::assertTrue($this->s->usuarios->ehUltimoAdministradorAtivo($this->ids['admin']));
        self::assertFalse($this->s->usuarios->ehUltimoAdministradorAtivo($this->ids['admin2']), 'inativa não conta');
        self::assertFalse($this->s->usuarios->ehUltimoAdministradorAtivo($this->ids['editor']));
        try {
            $this->s->usuarios->definirAdministrador($this->ids['admin'], false);
            self::fail('deveria recusar');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('última conta de administrador', $e->getMessage());
        }
        self::assertTrue($this->s->usuarios->porId($this->ids['admin'])?->administrador);
        // Pela tela, a própria conta nem desativa nem perde a marca.
        self::assertSame(422, $this->post($token, '/_pbl/conta/' . $this->ids['admin'] . '/desativar', [])->getStatusCode());
        // Dar a marca a outra conta e tirar da segunda continua permitido enquanto sobrar outra ativa.
        $this->s->usuarios->definirAtivo($this->ids['admin2'], true);
        $this->post($token, '/_pbl/conta/' . $this->ids['admin2'] . '/editar', ['nome' => 'Segundo Admin', 'email' => 'admin2@exemplo.org']);
        self::assertFalse($this->s->usuarios->porId($this->ids['admin2'])?->administrador);
        self::assertTrue($this->s->usuarios->porId($this->ids['admin'])?->administrador);
    }

    public function testRedefinirSenhaDerrubaSessoesDaContaEMantemAsDosOutros(): void
    {
        $admin = $this->token('admin');
        $alvo = $this->ids['editor'];
        $sessaoDoAlvo = $this->token('editor');
        $sessaoDoOutro = $this->token('leitor');
        $r = $this->post($admin, "/_pbl/conta/{$alvo}/senha", ['senha' => 'senha-redefinida-2026', 'senha2' => 'senha-redefinida-2026']);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame("/_pbl/conta/{$alvo}?ok=senha", $r->headers->get('Location'));
        self::assertFalse($this->sessaoViva($sessaoDoAlvo));
        self::assertTrue($this->sessaoViva($sessaoDoOutro));
        self::assertTrue($this->sessaoViva($admin));
        self::assertNull($this->s->usuarios->autenticar('editor', self::SENHA));
        self::assertNotNull($this->s->usuarios->autenticar('editor', 'senha-redefinida-2026'));
        $log = $this->auditoria('senha.trocar');
        self::assertSame('usuario:' . $this->ids['admin'], $log[0]['ator']);
        self::assertStringNotContainsString('senha-redefinida', (string) json_encode($this->s->auditoria->recentes(500)));
    }

    public function testRedefinirAPropriaSenhaEncerraAPropriaSessao(): void
    {
        $admin = $this->token('admin');
        $r = $this->post($admin, '/_pbl/conta/' . $this->ids['admin'] . '/senha', ['senha' => 'minha-senha-nova-2026', 'senha2' => 'minha-senha-nova-2026']);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/_pbl/entrar', $r->headers->get('Location'));
        self::assertFalse($this->sessaoViva($admin));
    }

    public function testRedefinirSenhaRespeitaAPoliticaSemTrocarNada(): void
    {
        $admin = $this->token('admin');
        $alvo = $this->ids['editor'];
        $sessao = $this->token('editor');
        foreach ([['curta', 'curta', 'pelo menos 10'], ['senha123456', 'senha123456', 'fácil demais'], ['senha-valida-2026', 'senha-diferente-2026', 'não são iguais'], ['editor@exemplo.org', 'editor@exemplo.org', 'igual ao e-mail']] as [$a, $b, $msg]) {
            $r = $this->post($admin, "/_pbl/conta/{$alvo}/senha", ['senha' => $a, 'senha2' => $b]);
            self::assertSame(422, $r->getStatusCode(), $a);
            self::assertStringContainsString($msg, $this->html($r));
            if (!str_contains($a, '@')) {
                self::assertStringNotContainsString('value="' . $a, $this->html($r));
            }
        }
        self::assertNotNull($this->s->usuarios->autenticar('editor', self::SENHA));
        self::assertTrue($this->sessaoViva($sessao), 'senha recusada não derruba a sessão');
        self::assertSame(400, $this->post($admin, "/_pbl/conta/{$alvo}/senha", ['senha' => ['a'], 'senha2' => 'x'])->getStatusCode());
    }

    public function testDesativarEReativarConta(): void
    {
        $admin = $this->token('admin');
        $alvo = $this->ids['editor'];
        $sessao = $this->token('editor');
        $r = $this->post($admin, "/_pbl/conta/{$alvo}/desativar", []);
        self::assertSame(303, $r->getStatusCode());
        self::assertFalse($this->s->usuarios->porId($alvo)?->ativo);
        self::assertFalse($this->sessaoViva($sessao));
        self::assertNull($this->s->usuarios->autenticar('editor', self::SENHA));
        $html = $this->html($this->req('GET', "/_pbl/conta/{$alvo}", $admin));
        self::assertStringContainsString('/reativar', $html);
        self::assertStringNotContainsString('/desativar', $html);
        self::assertCount(1, $this->auditoria('usuario.desativar'));

        $this->post($admin, "/_pbl/conta/{$alvo}/desativar", []); // repetir não duplica
        self::assertCount(1, $this->auditoria('usuario.desativar'));
        $this->post($admin, "/_pbl/conta/{$alvo}/reativar", []);
        self::assertTrue($this->s->usuarios->porId($alvo)?->ativo);
        self::assertNotNull($this->s->usuarios->autenticar('editor', self::SENHA));
        self::assertCount(1, $this->auditoria('usuario.ativar'));
    }

    public function testNinguemDesativaASiMesmo(): void
    {
        $admin = $this->token('admin');
        $r = $this->post($admin, '/_pbl/conta/' . $this->ids['admin'] . '/desativar', []);
        self::assertSame(422, $r->getStatusCode());
        self::assertStringContainsString('própria conta', $this->html($r));
        self::assertTrue($this->s->usuarios->porId($this->ids['admin'])?->ativo);
        self::assertTrue($this->sessaoViva($admin));
        self::assertStringNotContainsString('/desativar', $this->html($this->req('GET', '/_pbl/conta/' . $this->ids['admin'], $admin)));
    }

    public function testConcederEPerfilDeSecao(): void
    {
        $admin = $this->token('admin');
        $alvo = $this->ids['leitor'];
        $r = $this->post($admin, "/_pbl/conta/{$alvo}/conceder", ['objeto' => (string) $this->inicioId, 'perfil' => 'autor']);
        self::assertSame(303, $r->getStatusCode());
        $acessos = $this->s->usuarios->acessosDe($alvo);
        self::assertCount(1, $acessos, 'substitui o papel na mesma seção');
        self::assertSame(Perfil::Autor, $acessos[0]['perfil']);
        $html = $this->html($this->req('GET', "/_pbl/conta/{$alvo}", $admin));
        self::assertStringContainsString('href="/_pbl/objeto/' . $this->inicioId . '"', $html);
        self::assertStringContainsString('<td>autor</td>', $html);
        self::assertStringContainsString('Meu Site', $html);
        $log = $this->auditoria('acesso.conceder');
        self::assertSame('usuario:' . $this->ids['admin'], $log[0]['ator']);
        self::assertSame($this->inicioId, $log[0]['objeto_id']);
    }

    public function testConcederRecusaEntradaInvalida(): void
    {
        $admin = $this->token('admin');
        $alvo = $this->ids['leitor'];
        foreach ([['999999', 'editor'], ['abc', 'editor'], ['0', 'editor'], ['', 'editor'], [(string) $this->inicioId, 'superuser'], [(string) $this->inicioId, 'administrador'], [(string) $this->inicioId, '']] as [$objeto, $perfil]) {
            self::assertSame(422, $this->post($admin, "/_pbl/conta/{$alvo}/conceder", ['objeto' => $objeto, 'perfil' => $perfil])->getStatusCode(), "{$objeto}/{$perfil}");
        }
        self::assertSame([Perfil::Leitor], array_map(static fn (array $a): Perfil => $a['perfil'], $this->s->usuarios->acessosDe($alvo)));
    }

    public function testRevogarPerfil(): void
    {
        $admin = $this->token('admin');
        $alvo = $this->ids['leitor'];
        $r = $this->post($admin, "/_pbl/conta/{$alvo}/revogar", ['objeto' => (string) $this->inicioId]);
        self::assertSame(303, $r->getStatusCode());
        self::assertSame([], $this->s->usuarios->acessosDe($alvo));
        self::assertCount(1, $this->auditoria('acesso.revogar'));
        self::assertSame(422, $this->post($admin, "/_pbl/conta/{$alvo}/revogar", ['objeto' => (string) $this->inicioId])->getStatusCode(), 'já revogado');
        self::assertSame(400, $this->post($admin, "/_pbl/conta/{$alvo}/revogar", ['objeto' => 'abc'])->getStatusCode());
        self::assertStringContainsString('Nenhum papel concedido', $this->html($this->req('GET', "/_pbl/conta/{$alvo}", $admin)));
    }

    public function testPapelEmSecaoValeDeFatoDepoisDeConcedido(): void
    {
        $admin = $this->token('admin');
        $outroLeitor = $this->s->usuarios->criar('sem.papel', 'sp@exemplo.org', 'Sem Papel', self::SENHA)->id;
        $this->ids['sem.papel'] = $outroLeitor;
        $token = $this->token('sem.papel');
        self::assertSame(403, $this->req('GET', '/_pbl/objeto/' . $this->inicioId, $token)->getStatusCode());
        $this->post($admin, "/_pbl/conta/{$outroLeitor}/conceder", ['objeto' => (string) $this->inicioId, 'perfil' => 'leitor']);
        self::assertSame(200, $this->req('GET', '/_pbl/objeto/' . $this->inicioId, $token)->getStatusCode());
        $this->post($admin, "/_pbl/conta/{$outroLeitor}/revogar", ['objeto' => (string) $this->inicioId]);
        self::assertSame(403, $this->req('GET', '/_pbl/objeto/' . $this->inicioId, $token)->getStatusCode());
    }

    public function testContaDesativadaPerdeAcessoAsTelasDeContas(): void
    {
        $token = $this->token('admin2');
        $this->s->usuarios->definirAtivo($this->ids['admin2'], false);
        self::assertSame(303, $this->req('GET', '/_pbl/contas', $token)->getStatusCode());
    }
}
