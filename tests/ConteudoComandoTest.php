<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Console\Conteudo\Contexto;
use Pbl\Console\ConteudoComando;
use PHPUnit\Framework\TestCase;

/** `bin/pbl conteudo` de ponta a ponta: contrato JSON, códigos de saída, permissões, versão, validação e revisão (ADR 0033/0034). */
final class ConteudoComandoTest extends TestCase
{
    use ConteudoAuxiliar;

    public function testVerTemOContratoEstavelDeChaves(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos', 'publicado');
        $filho = $this->pagina('Equipe', 'quem-somos/equipe', 'privado', $id);
        [$codigo, $saida, $j] = $this->cli(['acao' => 'ver', 'alvo' => (string) $id, '--como' => 'claudim', '--json' => null]);

        self::assertSame(0, $codigo, $saida);
        self::assertSame(1, $j['api']);
        self::assertSame('ver', $j['acao']);
        self::assertSame(1, substr_count(trim($saida), "\n") + 1, 'uma linha só');
        $esperadas = ['id', 'titulo', 'tipo', 'estado', 'versao', 'url', 'visivel_agora', 'endereco_publico', 'descricao', 'peso', 'no_menu', 'rotulo_menu', 'tags',
            'data_publicacao', 'data_validade', 'pai', 'filhos', 'filhos_total', 'campos', 'esquema', 'permissoes', 'atualizado_em'];
        self::assertSame([], array_diff($esperadas, array_keys($j['dados'])), 'só se acrescenta chave; nunca se remove');
        self::assertSame(['editar', 'publicar', 'apagar', 'criar'], array_keys($j['dados']['permissoes']));
        self::assertSame('publicado', $j['dados']['estado']);
        self::assertTrue($j['dados']['visivel_agora']);
        self::assertSame('https://www.exemplo.org/quem-somos', $j['dados']['endereco_publico']);
        self::assertSame($filho, $j['dados']['filhos'][0]['id']);
        self::assertSame('pagina', $j['dados']['tipo']);
        self::assertArrayHasKey('corpo', $j['dados']['campos']);
        self::assertSame($this->inicioId, $j['dados']['pai']['id']);
    }

    public function testVerPorCaminhoEPorPaginaInicial(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        [, , $a] = $this->cli(['acao' => 'ver', 'alvo' => 'quem-somos', '--como' => 'editor', '--json' => null]);
        [, , $b] = $this->cli(['acao' => 'ver', 'alvo' => '/quem-somos/', '--como' => 'editor', '--json' => null]);
        [, , $inicio] = $this->cli(['acao' => 'ver', 'alvo' => '/', '--como' => 'editor', '--json' => null]);

        self::assertSame($id, $a['dados']['id']);
        self::assertSame($id, $b['dados']['id']);
        self::assertSame($this->inicioId, $inicio['dados']['id']);
    }

    public function testVerTextoLegivelPorPadrao(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        [$codigo, $saida] = $this->cli(['acao' => 'ver', 'alvo' => (string) $id, '--como' => 'claudim']);

        self::assertSame(0, $codigo);
        self::assertStringContainsString('Sobre nós', $saida);
        self::assertStringContainsString('privado', $saida);
        self::assertNull(json_decode($saida, true));
    }

    public function testListarComFiltros(): void
    {
        $a = $this->pagina('Alfa', 'alfa', 'publicado');
        $this->pagina('Beta', 'beta', 'privado');
        $classe = $this->s->classes->porNome('noticia');
        self::assertNotNull($classe);
        $this->s->objetos->criarDentro($this->siteId, $classe, $this->inicioId, 'Gama notícia', 'gama', ['corpo' => '<p>x</p>'], 'publicado');

        [, , $todos] = $this->cli(['acao' => 'listar', '--como' => 'editor', '--json' => null]);
        $titulos = array_column($todos['dados']['itens'], 'titulo');
        self::assertContains('Alfa', $titulos);
        self::assertContains('Beta', $titulos);

        [, , $pub] = $this->cli(['acao' => 'listar', '--como' => 'editor', '--estado' => 'publicado', '--json' => null]);
        self::assertContains('Alfa', array_column($pub['dados']['itens'], 'titulo'));
        self::assertNotContains('Beta', array_column($pub['dados']['itens'], 'titulo'));

        [, , $tipo] = $this->cli(['acao' => 'listar', '--como' => 'editor', '--tipo' => 'noticia', '--json' => null]);
        self::assertSame(['Gama notícia'], array_column($tipo['dados']['itens'], 'titulo'));

        [, , $busca] = $this->cli(['acao' => 'listar', '--como' => 'editor', '--busca' => 'Alfa', '--json' => null]);
        self::assertSame($a, $busca['dados']['itens'][0]['id']);

        [, , $lim] = $this->cli(['acao' => 'listar', '--como' => 'editor', '--limite' => '1', '--json' => null]);
        self::assertCount(1, $lim['dados']['itens']);
        self::assertTrue($lim['dados']['ha_mais']);

        [$codigo, , $ruim] = $this->cli(['acao' => 'listar', '--como' => 'editor', '--estado' => 'inventado', '--json' => null]);
        self::assertSame(6, $codigo);
        self::assertSame('validacao', $ruim['erro']['codigo']);
    }

    public function testListarNaoMostraLixeiraSalvoPedido(): void
    {
        $id = $this->pagina('Velha', 'velha');
        $v = $this->objeto($id)->versao;
        $this->s->objetos->mandarParaLixeira($id, $v);
        [, , $j] = $this->cli(['acao' => 'listar', '--como' => 'editor', '--json' => null]);
        self::assertNotContains('Velha', array_column($j['dados']['itens'], 'titulo'));
        [, , $j] = $this->cli(['acao' => 'listar', '--como' => 'editor', '--estado' => 'apagado', '--json' => null]);
        self::assertSame(['Velha'], array_column($j['dados']['itens'], 'titulo'));
    }

    public function testEditarGravaENovaVersaoENadaAAlterar(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        [$codigo, $saida, $j] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'claudim', '--versao' => '1', '--campo' => ['titulo=Quem somos', 'resumo=Um resumo'], '--json' => null]);

        self::assertSame(0, $codigo, $saida);
        self::assertTrue($j['dados']['alterado']);
        self::assertSame(2, $j['dados']['versao']);
        $o = $this->objeto($id);
        self::assertSame('Quem somos', $o->titulo);
        self::assertSame('Um resumo', $o->dados['resumo']);

        [$codigo, , $igual] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'claudim', '--versao' => '2', '--campo' => ['titulo=Quem somos'], '--json' => null]);
        self::assertSame(0, $codigo);
        self::assertFalse($igual['dados']['alterado']);
        self::assertSame(2, $this->objeto($id)->versao, 'sem diferença real não cria versão');
    }

    public function testEditarExigeAVersao(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        [$codigo, , $j] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'claudim', '--campo' => ['titulo=Outro'], '--json' => null]);

        self::assertSame(6, $codigo);
        self::assertSame('validacao', $j['erro']['codigo']);
        self::assertArrayHasKey('versao', $j['erro']['campos']);
        self::assertSame('Sobre nós', $this->objeto($id)->titulo);
    }

    public function testConflitoDeVersaoDevolveAVersaoAtual(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        $this->s->objetos->atualizar($id, ['titulo' => 'Feito por outra pessoa'], 'x', 1);
        [$codigo, , $j] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'claudim', '--versao' => '1', '--campo' => ['titulo=Meu'], '--json' => null]);

        self::assertSame(5, $codigo);
        self::assertSame('conflito_de_versao', $j['erro']['codigo']);
        self::assertSame(2, $j['erro']['versao_atual']);
        self::assertSame('Feito por outra pessoa', $this->objeto($id)->titulo);
    }

    public function testValidacaoPorTipoCampoDesconhecidoEObrigatorio(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        [$codigo, , $j] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'claudim', '--versao' => '1', '--campo' => ['seo_nao_indexar=talvez', 'inexistente=1', 'titulo='], '--json' => null]);

        self::assertSame(6, $codigo);
        self::assertSame('validacao', $j['erro']['codigo']);
        self::assertArrayHasKey('dados.seo_nao_indexar', $j['erro']['campos']);
        self::assertArrayHasKey('dados.inexistente', $j['erro']['campos']);
        self::assertArrayHasKey('titulo', $j['erro']['campos']);
        self::assertSame(1, $this->objeto($id)->versao);
    }

    public function testEnderecoEmUsoEPesoInvalido(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        $this->pagina('Outra', 'outra');
        [$codigo, , $j] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'claudim', '--versao' => '1', '--campo' => ['url=outra', 'peso=abc'], '--json' => null]);

        self::assertSame(6, $codigo);
        self::assertArrayHasKey('url', $j['erro']['campos']);
        self::assertArrayHasKey('peso', $j['erro']['campos']);
    }

    public function testHtmlMaliciosoPassaPelaMesmaLimpezaDoPainel(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        $sujo = '<p onclick="roubar()">Oi</p><script>alert(1)</script><a href="javascript:alert(2)">x</a><img src=x onerror=alert(3)>';
        $entrada = json_encode(['dados' => ['corpo' => $sujo]], JSON_THROW_ON_ERROR);
        [$codigo, $saida] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'claudim', '--versao' => '1', '--json' => '-'], $entrada);

        self::assertSame(0, $codigo, $saida);
        $corpo = (string) $this->objeto($id)->dados['corpo'];
        foreach (['<script', 'onclick', 'onerror', 'javascript:', 'alert('] as $perigo) {
            self::assertStringNotContainsStringIgnoringCase($perigo, $corpo);
        }
        self::assertStringContainsString('Oi', $corpo);
        self::assertSame(\Pbl\Conteudo\HtmlSeguro::limpar($sujo), $corpo, 'a mesma limpeza do painel');
    }

    public function testHtmlQueFicaVazioEhRecusado(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        [$codigo, , $j] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'claudim', '--versao' => '1', '--campo' => ['corpo=<script>alert(1)</script>'], '--json' => null]);

        self::assertSame(6, $codigo);
        self::assertArrayHasKey('dados.corpo', $j['erro']['campos']);
    }

    public function testEntradaPorStdinECampoVenceOJson(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        $entrada = json_encode(['titulo' => 'Do JSON', 'tags' => ['a', 'b'], 'dados' => ['resumo' => 'do json', 'seo_nao_indexar' => true]], JSON_THROW_ON_ERROR);
        [$codigo, $saida] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'claudim', '--versao' => '1', '--json' => '-', '--campo' => ['titulo=Do campo']], $entrada);

        self::assertSame(0, $codigo, $saida);
        $o = $this->objeto($id);
        self::assertSame('Do campo', $o->titulo);
        self::assertSame('do json', $o->dados['resumo']);
        self::assertSame(1, (int) $o->dados['seo_nao_indexar']);
        self::assertSame(['a', 'b'], $this->servicos()->tags->nomesDoObjeto($id));
        self::assertNotNull(json_decode($saida), '--json=- também liga a saída em JSON');
    }

    public function testEntradaMalformadaOuComChaveDesconhecida(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        foreach (['{nao e json', '[1,2]', '{"titulo":"x","surpresa":"y"}', "\xff\xfe"] as $ruim) {
            [$codigo, , $j] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'claudim', '--versao' => '1', '--json' => '-'], $ruim);
            self::assertSame(6, $codigo, $ruim);
            self::assertSame('validacao', $j['erro']['codigo']);
        }
        self::assertSame(1, $this->objeto($id)->versao);
    }

    public function testEntradaAcimaDoLimiteEhRecusada(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        $grande = str_repeat('a', Contexto::LIMITE_ENTRADA + 1);
        [$codigo, , $j] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'claudim', '--versao' => '1', '--json' => '-'], $grande);

        self::assertSame(6, $codigo);
        self::assertSame('validacao', $j['erro']['codigo']);
    }

    public function testCampoAcimaDoLimiteDoPainelEhRecusado(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        $entrada = json_encode(['titulo' => str_repeat('t', 301)], JSON_THROW_ON_ERROR);
        [$codigo, , $j] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'claudim', '--versao' => '1', '--json' => '-'], $entrada);

        self::assertSame(6, $codigo);
        self::assertArrayHasKey('titulo', $j['erro']['campos']);
    }

    public function testPermissaoNegadaELeitorNaoEdita(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        [$codigo, , $j] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'leitor', '--versao' => '1', '--campo' => ['titulo=Hack'], '--json' => null]);

        self::assertSame(4, $codigo);
        self::assertSame('sem_permissao', $j['erro']['codigo']);
        self::assertSame('Sobre nós', $this->objeto($id)->titulo);
        $negados = array_filter($this->servicos()->auditoria->recentes(50), static fn (array $e): bool => $e['acao'] === 'objeto.editar.negado');
        self::assertNotEmpty($negados);
        self::assertStringContainsString('"via":"cli"', (string) array_values($negados)[0]['detalhe']);
    }

    public function testContaSemPermissaoDeVerRecebeNaoEncontrado(): void
    {
        $outro = $this->s->usuarios->criar('semsecao', 'sem@exemplo.org', 'Sem seção', 'uma senha bem longa 2026');
        $id = $this->pagina('Sobre nós', 'quem-somos');
        [$codigo, , $j] = $this->cli(['acao' => 'ver', 'alvo' => (string) $id, '--como' => $outro->login, '--json' => null]);

        self::assertSame(3, $codigo);
        self::assertSame('nao_encontrado', $j['erro']['codigo']);
        [$inexistente] = $this->cli(['acao' => 'ver', 'alvo' => '999999', '--como' => 'editor', '--json' => null]);
        self::assertSame(3, $inexistente, 'mesma resposta para o que não existe');
    }

    public function testContaESiteInvalidos(): void
    {
        [$codigo, , $j] = $this->cli(['acao' => 'listar', '--como' => 'fantasma', '--json' => null]);
        self::assertSame(3, $codigo);
        self::assertSame('nao_encontrado', $j['erro']['codigo']);

        [$codigo, , $j] = $this->cli(['acao' => 'listar', '--json' => null]);
        self::assertSame(2, $codigo);
        self::assertSame('uso', $j['erro']['codigo']);

        [$codigo] = $this->cli(['acao' => 'listar', '--como' => 'editor', '--site' => 'nao.existe.org', '--json' => null]);
        self::assertSame(3, $codigo);

        [$codigo] = $this->cli(['acao' => 'inventada', '--como' => 'editor', '--json' => null]);
        self::assertSame(2, $codigo);

        $this->s->usuarios->definirAtivo($this->ids['editor'], false);
        [$codigo, , $j] = $this->cli(['acao' => 'listar', '--como' => 'editor', '--json' => null]);
        self::assertSame(4, $codigo);
        self::assertSame('sem_permissao', $j['erro']['codigo']);
    }

    public function testComDoisSitesOSiteEhObrigatorio(): void
    {
        $this->s->sites->criar('Outro Site', 'outro.exemplo.org');
        $t = new \Symfony\Component\Console\Tester\CommandTester(new ConteudoComando($this->caminhos));
        $codigo = $t->execute(['acao' => 'listar', '--como' => 'admin', '--json' => null]);

        self::assertSame(2, $codigo);
        self::assertStringContainsString('--site', $t->getDisplay());
    }

    public function testAutorSoEnviaParaRevisaoENaoPublica(): void
    {
        $id = $this->pagina('Rascunho', 'rascunho', 'privado', null, $this->ids['autor']);
        [$codigo, , $j] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'autor', '--versao' => '1', '--campo' => ['titulo=Novo título'], '--publicar' => true, '--json' => null]);

        self::assertSame(4, $codigo);
        self::assertSame('sem_permissao', $j['erro']['codigo']);
        $o = $this->objeto($id);
        self::assertSame('Rascunho', $o->titulo, 'tudo ou nada: nada foi gravado');
        self::assertSame('privado', $o->status);

        [$codigo, $saida, $j] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'autor', '--versao' => '1', '--campo' => ['titulo=Novo título'], '--enviar-revisao' => true, '--json' => null]);
        self::assertSame(0, $codigo, $saida);
        self::assertSame('submetido', $j['dados']['estado']);
        $o = $this->objeto($id);
        self::assertSame('Novo título', $o->titulo);
        self::assertSame('submetido', $o->status);

        [$codigo] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'autor', '--versao' => (string) $o->versao, '--campo' => ['titulo=De novo'], '--json' => null]);
        self::assertSame(4, $codigo, 'enviado para revisão não se edita');
    }

    public function testEditorGravaEPublicaEAprovaOQueFoiEnviado(): void
    {
        $id = $this->pagina('Rascunho', 'rascunho');
        [$codigo, $saida, $j] = $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'editor', '--versao' => '1', '--campo' => ['titulo=No ar'], '--publicar' => true, '--json' => null]);
        self::assertSame(0, $codigo, $saida);
        self::assertSame('publicado', $j['dados']['estado']);
        self::assertTrue($this->objeto($id)->visivel(gmdate('c')));
        self::assertFileExists($this->caminhos->pagina($this->siteId, 'rascunho'), 'a página publicada foi para o cache');

        $outro = $this->pagina('Enviado', 'enviado', 'privado', null, $this->ids['autor']);
        $this->cli(['acao' => 'enviar', 'alvo' => (string) $outro, '--como' => 'autor', '--versao' => '1', '--json' => null]);
        $v = $this->objeto($outro)->versao;
        [$codigo, $saida, $j] = $this->cli(['acao' => 'publicar', 'alvo' => (string) $outro, '--como' => 'claudim', '--versao' => (string) $v, '--json' => null]);
        self::assertSame(0, $codigo, $saida);
        self::assertSame('publicado', $j['dados']['estado']);
        $passos = array_column($this->servicos()->revisao->historico($outro), 'acao');
        self::assertContains('aprovou', $passos);
    }

    public function testDespublicarEPublicarSaoIdempotentes(): void
    {
        $id = $this->pagina('No ar', 'no-ar', 'publicado');
        [$codigo, , $j] = $this->cli(['acao' => 'publicar', 'alvo' => (string) $id, '--como' => 'editor', '--versao' => '1', '--json' => null]);
        self::assertSame(0, $codigo);
        self::assertFalse($j['dados']['alterado']);

        [$codigo, , $j] = $this->cli(['acao' => 'despublicar', 'alvo' => (string) $id, '--como' => 'editor', '--versao' => '1', '--json' => null]);
        self::assertSame(0, $codigo);
        self::assertTrue($j['dados']['alterado']);
        self::assertSame('privado', $this->objeto($id)->status);
        self::assertFileDoesNotExist($this->caminhos->pagina($this->siteId, 'no-ar'), 'saiu do cache público');

        [$codigo] = $this->cli(['acao' => 'publicar', 'alvo' => (string) $id, '--como' => 'autor', '--versao' => '2', '--json' => null]);
        self::assertSame(4, $codigo, 'autor não publica');
    }

    public function testCriarRespeitaTiposObrigatoriosEEnderecoUnico(): void
    {
        $pagina = $this->pagina('Mãe', 'mae');
        [$codigo, $saida, $j] = $this->cli(['acao' => 'criar', '--pai' => (string) $pagina, '--tipo' => 'pagina', '--como' => 'claudim', '--campo' => ['titulo=Filha Nova', 'resumo=R'], '--json' => null]);
        self::assertSame(0, $codigo, $saida);
        self::assertSame('privado', $j['dados']['estado']);
        self::assertSame('/filha-nova', $j['dados']['url']);
        $novo = $this->objeto($j['dados']['id']);
        self::assertSame($pagina, $novo->paiId);
        self::assertSame($this->ids['claudim'], $novo->criadoPor);

        [, , $j2] = $this->cli(['acao' => 'criar', '--pai' => (string) $pagina, '--tipo' => 'pagina', '--como' => 'claudim', '--campo' => ['titulo=Filha Nova'], '--json' => null]);
        self::assertSame('/filha-nova-2', $j2['dados']['url'], 'endereço gerado nunca colide');

        [$codigo, , $j] = $this->cli(['acao' => 'criar', '--pai' => (string) $pagina, '--tipo' => 'pagina', '--como' => 'claudim', '--campo' => ['titulo=Repetida', 'url=mae'], '--json' => null]);
        self::assertSame(6, $codigo);
        self::assertArrayHasKey('url', $j['erro']['campos']);

        [$codigo, , $j] = $this->cli(['acao' => 'criar', '--pai' => (string) $pagina, '--tipo' => 'nao_existe', '--como' => 'claudim', '--campo' => ['titulo=X'], '--json' => null]);
        self::assertSame(6, $codigo);
        self::assertArrayHasKey('tipo', $j['erro']['campos']);

        [$codigo] = $this->cli(['acao' => 'criar', '--tipo' => 'pagina', '--como' => 'claudim', '--json' => null]);
        self::assertSame(2, $codigo, 'sem --pai é erro de uso');
    }

    public function testCriarRecusaTipoQueOPaiNaoAceitaECampoObrigatorio(): void
    {
        $classe = $this->s->classes->porNome('noticia');
        self::assertNotNull($classe);
        $noticia = $this->s->objetos->criarDentro($this->siteId, $classe, $this->inicioId, 'Notícia', 'noticia-pai', ['corpo' => '<p>x</p>'], 'privado')->id;
        [$codigo, , $j] = $this->cli(['acao' => 'criar', '--pai' => (string) $noticia, '--tipo' => 'pagina', '--como' => 'claudim', '--campo' => ['titulo=Não cabe'], '--json' => null]);
        self::assertSame(6, $codigo);
        self::assertStringContainsString('não aceita', $j['erro']['campos']['tipo']);

        $pagina = $this->pagina('Mãe', 'mae');
        [$codigo, , $j] = $this->cli(['acao' => 'criar', '--pai' => (string) $pagina, '--tipo' => 'link', '--como' => 'claudim', '--campo' => ['titulo=Link sem destino'], '--json' => null]);
        self::assertSame(6, $codigo, 'link sem destino viola a regra do tipo');
        self::assertSame('validacao', $j['erro']['codigo']);
    }

    public function testAutorCriaEPaiForaDaSecaoNaoApareceParaAConta(): void
    {
        $pagina = $this->pagina('Mãe', 'mae');
        [$codigo, , $j] = $this->cli(['acao' => 'criar', '--pai' => (string) $pagina, '--tipo' => 'pagina', '--como' => 'autor', '--campo' => ['titulo=Do autor'], '--json' => null]);
        self::assertSame(0, $codigo);
        self::assertSame('privado', $j['dados']['estado']);

        [$codigo] = $this->cli(['acao' => 'criar', '--pai' => (string) $pagina, '--tipo' => 'pagina', '--como' => 'leitor', '--campo' => ['titulo=Do leitor'], '--json' => null]);
        self::assertSame(4, $codigo);
    }

    public function testOperacoesDestrutivasExigemConfirmar(): void
    {
        $a = $this->pagina('A', 'a');
        $b = $this->pagina('B', 'b');
        $casos = [
            ['acao' => 'lixeira', 'alvo' => (string) $a],
            ['acao' => 'mover', 'alvo' => (string) $a, '--para' => (string) $b],
            ['acao' => 'restaurar-versao', 'alvo' => (string) $a, '--para' => '1'],
            ['acao' => 'republicar'],
        ];
        foreach ($casos as $caso) {
            [$codigo, , $j] = $this->cli($caso + ['--como' => 'admin', '--versao' => '1', '--json' => null]);
            self::assertSame(6, $codigo, $caso['acao']);
            self::assertSame('confirmacao_necessaria', $j['erro']['codigo']);
        }
        $o = $this->objeto($a);
        self::assertSame(1, $o->versao);
        self::assertSame($this->inicioId, $o->paiId);
        self::assertSame('privado', $o->status);
    }

    public function testLixeiraNuncaApagaDeVezERecusaPaginaInicialEPaiComFilhos(): void
    {
        $mae = $this->pagina('Mãe', 'mae');
        $filha = $this->pagina('Filha', 'mae/filha', 'privado', $mae);
        [$codigo, , $j] = $this->cli(['acao' => 'lixeira', 'alvo' => (string) $mae, '--como' => 'editor', '--versao' => '1', '--confirmar' => true, '--json' => null]);
        self::assertSame(5, $codigo);
        self::assertSame('conflito_de_arvore', $j['erro']['codigo']);

        [$codigo, , $j] = $this->cli(['acao' => 'lixeira', 'alvo' => '/', '--como' => 'admin', '--versao' => '1', '--confirmar' => true, '--json' => null]);
        self::assertSame(5, $codigo);
        self::assertSame('estado_invalido', $j['erro']['codigo']);

        [$codigo, , $j] = $this->cli(['acao' => 'lixeira', 'alvo' => (string) $filha, '--como' => 'editor', '--versao' => '1', '--confirmar' => true, '--json' => null]);
        self::assertSame(0, $codigo);
        self::assertSame('apagado', $j['dados']['estado']);
        $o = $this->objeto($filha);
        self::assertSame('apagado', $o->status);

        [$codigo, , $j] = $this->cli(['acao' => 'lixeira', 'alvo' => (string) $filha, '--como' => 'editor', '--versao' => (string) $o->versao, '--confirmar' => true, '--json' => null]);
        self::assertSame(0, $codigo);
        self::assertFalse($j['dados']['alterado']);
    }

    public function testNaoExisteCaminhoDeApagarDefinitivamenteNoCli(): void
    {
        foreach (glob(dirname(__DIR__) . '/app/src/Console/Conteudo/*.php') ?: [] as $arquivo) {
            self::assertStringNotContainsString('apagarDefinitivamente', (string) file_get_contents($arquivo), $arquivo);
        }
        foreach (['ConteudoComando.php', 'Conteudo/Servico/ServicoDeConteudo.php'] as $arquivo) {
            self::assertStringNotContainsString('apagarDefinitivamente', (string) file_get_contents(dirname(__DIR__) . '/app/src/' . ($arquivo === 'ConteudoComando.php' ? 'Console/' : '') . $arquivo));
        }
    }

    public function testMoverRespeitaDestinoTipoECiclo(): void
    {
        $a = $this->pagina('A', 'a');
        $b = $this->pagina('B', 'b');
        $filha = $this->pagina('Filha de A', 'a/filha', 'privado', $a);
        [$codigo, $saida, $j] = $this->cli(['acao' => 'mover', 'alvo' => (string) $a, '--para' => (string) $b, '--como' => 'editor', '--versao' => '1', '--confirmar' => true, '--json' => null]);
        self::assertSame(0, $codigo, $saida);
        self::assertSame($b, $this->objeto($a)->paiId);
        self::assertSame(2, $j['dados']['versao']);

        [$codigo, , $j] = $this->cli(['acao' => 'mover', 'alvo' => (string) $a, '--para' => (string) $filha, '--como' => 'editor', '--versao' => '2', '--confirmar' => true, '--json' => null]);
        self::assertSame(6, $codigo, 'para dentro de si mesmo');
        self::assertArrayHasKey('para', $j['erro']['campos']);

        $classe = $this->s->classes->porNome('noticia');
        self::assertNotNull($classe);
        $noticia = $this->s->objetos->criarDentro($this->siteId, $classe, $this->inicioId, 'Notícia', 'noticia-x', ['corpo' => '<p>x</p>'], 'privado')->id;
        [$codigo] = $this->cli(['acao' => 'mover', 'alvo' => (string) $b, '--para' => (string) $noticia, '--como' => 'editor', '--versao' => '1', '--confirmar' => true, '--json' => null]);
        self::assertSame(6, $codigo, 'notícia só aceita arquivo, imagem e link');

        [$codigo] = $this->cli(['acao' => 'mover', 'alvo' => (string) $b, '--para' => (string) $a, '--como' => 'editor', '--versao' => '9', '--confirmar' => true, '--json' => null]);
        self::assertSame(5, $codigo);
    }

    public function testRestaurarVersaoMantemOEstado(): void
    {
        $id = $this->pagina('Original', 'orig', 'publicado');
        $this->s->objetos->atualizar($id, ['titulo' => 'Alterado'], 'x', 1);
        [$codigo, $saida, $j] = $this->cli(['acao' => 'restaurar-versao', 'alvo' => (string) $id, '--para' => '1', '--como' => 'editor', '--versao' => '2', '--confirmar' => true, '--json' => null]);

        self::assertSame(0, $codigo, $saida);
        self::assertSame(3, $j['dados']['versao']);
        $o = $this->objeto($id);
        self::assertSame('Original', $o->titulo);
        self::assertSame('publicado', $o->status);

        [$codigo] = $this->cli(['acao' => 'restaurar-versao', 'alvo' => (string) $id, '--para' => '99', '--como' => 'editor', '--versao' => '3', '--confirmar' => true, '--json' => null]);
        self::assertSame(6, $codigo);
    }

    public function testHistoricoMostraAutorEAgente(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        $this->cli(['acao' => 'editar', 'alvo' => (string) $id, '--como' => 'claudim', '--versao' => '1', '--campo' => ['titulo=Novo'], '--json' => null]);
        [$codigo, , $j] = $this->cli(['acao' => 'historico', 'alvo' => (string) $id, '--como' => 'editor', '--json' => null]);

        self::assertSame(0, $codigo);
        self::assertSame(2, $j['dados']['versao_atual']);
        self::assertSame(['login' => 'claudim', 'agente' => true], $j['dados']['versoes'][0]['autor']);
    }

    public function testImagemExigeAltEValidaOConteudoReal(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD ausente');
        }
        $id = $this->pagina('Sobre nós', 'quem-somos');
        $png = $this->raiz . '/foto.png';
        $im = imagecreatetruecolor(40, 30);
        imagepng($im, $png);

        [$codigo, , $j] = $this->cli(['acao' => 'imagem', 'alvo' => (string) $id, '--campo' => 'seo_imagem', '--arquivo' => $png, '--como' => 'claudim', '--versao' => '1', '--json' => null]);
        self::assertSame(6, $codigo);
        self::assertArrayHasKey('alt', $j['erro']['campos']);

        [$codigo, $saida, $j] = $this->cli(['acao' => 'imagem', 'alvo' => (string) $id, '--campo' => 'seo_imagem', '--arquivo' => $png, '--alt' => 'Uma foto de teste', '--como' => 'claudim', '--versao' => '1', '--json' => null]);
        self::assertSame(0, $codigo, $saida);
        self::assertSame(2, $j['dados']['versao']);
        self::assertFileExists($png, 'o arquivo de origem não é tocado');

        [, , $ver] = $this->cli(['acao' => 'ver', 'alvo' => (string) $id, '--como' => 'claudim', '--json' => null]);
        $campo = $ver['dados']['campos']['seo_imagem'];
        self::assertSame('Uma foto de teste', $campo['alt']);
        self::assertSame('image/png', $campo['mime']);
        self::assertSame(['arquivo_id', 'nome', 'mime', 'tamanho', 'alt'], array_keys($campo));
    }

    public function testUploadInvalidoNaoAlteraOObjeto(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        $falso = $this->raiz . '/falso.png';
        file_put_contents($falso, '<?php echo "isto não é uma imagem"; ?>');
        [$codigo, , $j] = $this->cli(['acao' => 'imagem', 'alvo' => (string) $id, '--campo' => 'seo_imagem', '--arquivo' => $falso, '--alt' => 'x', '--como' => 'claudim', '--versao' => '1', '--json' => null]);

        self::assertSame(6, $codigo);
        self::assertArrayHasKey('dados.seo_imagem', $j['erro']['campos']);
        $o = $this->objeto($id);
        self::assertSame(1, $o->versao);
        self::assertArrayNotHasKey('seo_imagem', $o->dados);

        foreach ([$this->raiz . '/nao-existe.png', $this->raiz] as $caminho) {
            [$codigo, , $j] = $this->cli(['acao' => 'imagem', 'alvo' => (string) $id, '--campo' => 'seo_imagem', '--arquivo' => $caminho, '--alt' => 'x', '--como' => 'claudim', '--versao' => '1', '--json' => null]);
            self::assertSame(6, $codigo);
            self::assertArrayHasKey('arquivo', $j['erro']['campos']);
        }
        $elo = $this->raiz . '/elo.png';
        if (@symlink($falso, $elo)) {
            [$codigo] = $this->cli(['acao' => 'imagem', 'alvo' => (string) $id, '--campo' => 'seo_imagem', '--arquivo' => $elo, '--alt' => 'x', '--como' => 'claudim', '--versao' => '1', '--json' => null]);
            self::assertSame(6, $codigo, 'link simbólico recusado');
        }
    }

    public function testArquivoNaoAceitaAltECampoPrecisaSerDoTipo(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        $txt = $this->raiz . '/a.txt';
        file_put_contents($txt, 'oi');
        [$codigo, , $j] = $this->cli(['acao' => 'arquivo', 'alvo' => (string) $id, '--campo' => 'seo_imagem', '--arquivo' => $txt, '--alt' => 'x', '--como' => 'claudim', '--versao' => '1', '--json' => null]);
        self::assertSame(6, $codigo);
        self::assertArrayHasKey('alt', $j['erro']['campos']);

        [$codigo, , $j] = $this->cli(['acao' => 'arquivo', 'alvo' => (string) $id, '--campo' => 'seo_imagem', '--arquivo' => $txt, '--como' => 'claudim', '--versao' => '1', '--json' => null]);
        self::assertSame(6, $codigo, 'seo_imagem é imagem, não arquivo');
        self::assertArrayHasKey('campo', $j['erro']['campos']);
    }

    public function testAltComHtmlEhRecusado(): void
    {
        $id = $this->pagina('Sobre nós', 'quem-somos');
        $png = $this->raiz . '/f.png';
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD ausente');
        }
        imagepng(imagecreatetruecolor(8, 8), $png);
        [$codigo, , $j] = $this->cli(['acao' => 'imagem', 'alvo' => (string) $id, '--campo' => 'seo_imagem', '--arquivo' => $png, '--alt' => '<b>x</b>', '--como' => 'claudim', '--versao' => '1', '--json' => null]);
        self::assertSame(6, $codigo);
        self::assertArrayHasKey('alt', $j['erro']['campos']);
    }

    public function testRepublicarExigePermissaoDeAdministrarOSite(): void
    {
        [$codigo, , $j] = $this->cli(['acao' => 'republicar', '--como' => 'claudim', '--confirmar' => true, '--json' => null]);
        self::assertSame(4, $codigo);
        self::assertSame('sem_permissao', $j['erro']['codigo']);

        [$codigo, $saida, $j] = $this->cli(['acao' => 'republicar', '--como' => 'admin', '--confirmar' => true, '--json' => null]);
        self::assertSame(0, $codigo, $saida);
        self::assertTrue($j['dados']['concluido']);
        self::assertGreaterThan(0, $j['dados']['publicadas']);
        $achou = array_filter($this->servicos()->auditoria->recentes(20), static fn (array $e): bool => $e['acao'] === 'site.republicar');
        self::assertNotEmpty($achou);
    }

    public function testSaidaDeTextoNaoDeixaPassarSequenciasDeTerminal(): void
    {
        $id = $this->pagina("Título \x1b[31mvermelho\x1b[0m\x07", 'quem-somos');
        [$codigo, $saida] = $this->cli(['acao' => 'ver', 'alvo' => (string) $id, '--como' => 'claudim']);
        self::assertSame(0, $codigo);
        self::assertStringNotContainsString("\x1b", $saida);
        self::assertStringNotContainsString("\x07", $saida);
        self::assertStringContainsString('vermelho', $saida);

        [, $saidaLista] = $this->cli(['acao' => 'listar', '--como' => 'claudim']);
        self::assertStringNotContainsString("\x1b", $saidaLista);
    }

    public function testSaidaJsonEscapaMarcacaoEsemFormatadorDoSymfony(): void
    {
        $id = $this->pagina('<b>Negrito</b> & <error>tag</error>', 'quem-somos');
        [, $saida, $j] = $this->cli(['acao' => 'ver', 'alvo' => (string) $id, '--como' => 'claudim', '--json' => null]);
        self::assertStringNotContainsString('<b>', $saida, 'JSON com HEX_TAG');
        self::assertSame('<b>Negrito</b> & <error>tag</error>', $j['dados']['titulo']);

        [, $texto] = $this->cli(['acao' => 'ver', 'alvo' => (string) $id, '--como' => 'claudim']);
        self::assertStringContainsString('<error>tag</error>', $texto, 'o conteúdo não é interpretado como estilo do console');
    }

    public function testComandoNaoTemOpcaoDeSegredo(): void
    {
        $definicao = (new ConteudoComando($this->caminhos))->getDefinition();
        foreach (array_keys($definicao->getOptions()) as $nome) {
            self::assertDoesNotMatchRegularExpression('/senha|token|segredo|secret|password|chave/i', $nome);
        }
    }

    public function testCodigosNovosNaoUsamFuncoesProibidasNaHospedagemComum(): void
    {
        $arquivos = array_merge(glob(dirname(__DIR__) . '/app/src/Console/Conteudo/*.php') ?: [], glob(dirname(__DIR__) . '/app/src/Conteudo/Servico/*.php') ?: [], [dirname(__DIR__) . '/app/src/Console/ConteudoComando.php']);
        self::assertNotEmpty($arquivos);
        foreach ($arquivos as $arquivo) {
            $codigo = (string) file_get_contents($arquivo);
            foreach (['proc_open', 'shell_exec', 'popen(', 'passthru', 'php://input', "'/tmp", '"/tmp', 'exec('] as $proibido) {
                self::assertStringNotContainsString($proibido, $codigo, basename($arquivo) . ' usa ' . $proibido);
            }
            self::assertDoesNotMatchRegularExpression('/\bSELECT\b|\bINSERT\b|\bUPDATE\b|\bDELETE\b|->conexao\(\)/', $codigo, basename($arquivo) . ' não pode ter SQL direto');
        }
    }
}
