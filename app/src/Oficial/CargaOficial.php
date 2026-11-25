<?php

declare(strict_types=1);

namespace Pbl\Oficial;

use Pbl\Caminhos;
use Pbl\Conteudo\ConflitoDeArvore;
use Pbl\Conteudo\Dominio;
use Pbl\Conteudo\HtmlSeguro;
use Pbl\Conteudo\Modelos;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Site;
use Pbl\Demo\ConteudoPublicare;
use Pbl\Demo\ImagemDecorativa;
use Pbl\Instalacao\Instalador;
use Pbl\Instalacao\PelesDistribuidas;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Carga do conteúdo OFICIAL do site publicare.org: só o conteúdo público do Publicare 4, vindo de {@see ConteudoPublicare}.
 *
 * Diferente do `demo-popular`, nunca cria contas, rascunhos, envios em revisão, aviso de demonstração nem arquivos fictícios.
 *
 * Idempotente: cada objeto da carga é identificado pelo endereço (`url_amigavel`). Rodar de novo cria o que falta, atualiza só o
 * que difere do texto oficial (sem versões vazias) e não toca em nada fora dessa lista: páginas, perguntas e arquivos que o
 * editor criou continuam como estão. Consequência esperada: o que o editor retocar num objeto DA carga volta ao texto oficial.
 *
 * @phpstan-type Resultado array{criados: int, atualizados: int, inalterados: int, removidos: int, publicadas: int, avisos: list<string>}
 */
final class CargaOficial
{
    private const MOTIVO = 'Carga oficial';
    private const CAIXA = 'mensagens-recebidas';

    private Servicos $servicos;
    private Site $site;
    private int $raiz;
    private int $criados = 0;
    private int $atualizados = 0;
    private int $inalterados = 0;
    private int $removidos = 0;
    /** @var list<string> */
    private array $avisos = [];

    public function __construct(private readonly Caminhos $caminhos, Servicos $servicos)
    {
        $this->servicos = $servicos;
    }

    /**
     * @param string|null $host domínio principal a garantir (opcional se o site já tem um domínio principal real)
     * @return Resultado
     * @throws \InvalidArgumentException opção inválida
     * @throws \RuntimeException site sem domínio principal real
     */
    public function carregar(int $siteId, string $emailContato, ?string $host = null, bool $publicar = true): array
    {
        $site = $this->servicos->sites->porId($siteId);
        if ($site === null || $site->objetoRaizId === null) {
            throw new \InvalidArgumentException('Site não encontrado');
        }
        $email = $this->validarEmail($emailContato);
        $this->site = $site;
        $this->raiz = $site->objetoRaizId;
        $this->criados = $this->atualizados = $this->inalterados = $this->removidos = 0;
        $this->avisos = [];

        // Ordem invariante: domínio (o canonical é fixado ao publicar) → configuração → pele e modelos → conteúdo → publicação.
        $this->garantirDominio($host);
        $this->configurar($email);
        $this->site = $this->usarPeleOficial($site);
        $modelos = new Modelos($this->servicos->conexao(), $this->caminhos);
        foreach (['faq', 'destaque', 'bloco'] as $modelo) {
            $modelos->ativar($modelo, $siteId, false);
        }
        $this->servicos->conexao()->transactional(function (): void {
            $this->conteudo();
        });
        $publicadas = $publicar ? $this->servicos->publicador->publicarSite($this->site) : 0;

        return ['criados' => $this->criados, 'atualizados' => $this->atualizados, 'inalterados' => $this->inalterados, 'removidos' => $this->removidos, 'publicadas' => $publicadas, 'avisos' => $this->avisos];
    }

    private function validarEmail(string $email): string
    {
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/\.(invalid|test|local|example)$/i', $email) === 1) {
            throw new \InvalidArgumentException('Informe um e-mail de contato real em --email-contato');
        }

        return $email;
    }

    /** O domínio é infraestrutura do operador: só se garante (e promove a principal) quando pedido em `--host`; sem ele, exige um já cadastrado. */
    private function garantirDominio(?string $host): void
    {
        $dominios = $this->servicos->dominios;
        if ($host !== null && $host !== '') {
            $host = strtolower(trim($host));
            if (!Dominio::hostValido($host)) {
                throw new \InvalidArgumentException("Domínio inválido: {$host}");
            }
            $this->exigirHostReal($host);
            $existente = $dominios->porHost($host);
            if ($existente !== null && $existente->siteId !== $this->site->id) {
                throw new \InvalidArgumentException("O domínio {$host} pertence a outro site");
            }
            if ($existente === null) {
                $dominios->adicionar($this->site->id, $host);
            }
            if ($dominios->principalDe($this->site->id)?->host !== $host) {
                $dominios->definirPrincipal($this->site->id, $host);
            }
            $this->servicos->enderecos->esquecer($this->site->id);
        }
        $principal = $dominios->principalDe($this->site->id)
            ?? throw new \RuntimeException('O site não tem domínio principal: informe --host=publicare.org ou use `bin/pbl dominio`');
        $this->exigirHostReal($principal->host);
    }

    private function exigirHostReal(string $host): void
    {
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false || preg_match('/\.(invalid|test|local|localhost|example)$/', $host) === 1) {
            throw new \RuntimeException("O domínio principal ({$host}) não é um domínio real: o canonical e o compartilhamento sairiam errados");
        }
    }

    private function configurar(string $email): void
    {
        $this->servicos->configs->definir($this->site->id, [
            'nome' => ConteudoPublicare::NOME_DO_SITE,
            'idioma' => 'pt-BR',
            'slogan' => ConteudoPublicare::PROMESSA,
            'descricao' => ConteudoPublicare::PROMESSA,
            'titulo_padrao' => ConteudoPublicare::NOME_DO_SITE,
            'email_contato' => $email,
            'robots_indexar' => true,
            'rss_ligado' => true,
        ]);
    }

    private function usarPeleOficial(Site $site): Site
    {
        $pele = PelesDistribuidas::PADRAO;
        (new PelesDistribuidas($this->caminhos))->sincronizar([$pele]);
        if ($site->pele !== $pele) {
            $this->servicos->sites->definirPele($site->id, $pele);
        }

        return $this->servicos->sites->porId($site->id) ?? $site;
    }

    private function conteudo(): void
    {
        $this->inicio();
        $this->restosDaInstalacao();

        $paginas = [];
        foreach (ConteudoPublicare::paginas() as $url => $p) {
            $paginas[$url] = $this->objeto('pagina', $this->raiz, $p['titulo'], $url, ['resumo' => $p['resumo'], 'corpo' => $p['corpo']], ['peso' => $p['peso'], 'no_menu' => true, 'rotulo_menu' => $p['rotulo'], 'descricao' => $p['descricao']]);
        }

        $chamadas = $this->objeto('pasta', $this->raiz, 'Pronto para experimentar?', 'chamadas', [], ['peso' => 990, 'descricao' => 'Instale em poucos minutos ou veja o que o Publicare 4 faz.']);
        $this->atalho($chamadas->id, 'chamada-instalar', 'Como instalar', $paginas['instalacao-e-requisitos'], 10);
        $this->atalho($chamadas->id, 'chamada-demonstracao', 'O que ele faz', $paginas['o-que-ele-faz'], 20);
        $this->objeto('link', $chamadas->id, 'Código no GitHub', 'chamada-github', ['destino_externo' => ConteudoPublicare::REPOSITORIO, 'nova_aba' => true], ['peso' => 30]);
        foreach (ConteudoPublicare::pilares() as $i => $pilar) {
            $this->atalho($this->raiz, $pilar['url'], $pilar['titulo'], $paginas[$pilar['destino']], ($i + 1) * 10, $pilar['descricao']);
        }

        $this->destaques($paginas);
        $this->guias($paginas['como-acompanhar']);
        $this->perguntas();
        $this->linksUteis($paginas['comunidade'], $paginas['como-acompanhar']);
        $this->atalhosUteis();
        $this->blocos();
        $this->contato();
    }

    /** A home é da carga enquanto ainda traz o texto da instalação ou o oficial; se o editor a reescreveu, fica como está. */
    private function inicio(): void
    {
        $inicio = $this->servicos->objetos->porId($this->raiz);
        if ($inicio === null) {
            return;
        }
        $corpo = (string) ($inicio->dados['corpo'] ?? '');
        $oficial = ConteudoPublicare::inicio();
        $intacta = str_contains($corpo, Instalador::MARCA_DO_INICIO)
            || $corpo === ConteudoPublicare::inicioDaInstalacao()['corpo']
            || $corpo === $oficial['corpo'];
        if (!$intacta) {
            $this->avisos[] = 'A página inicial foi editada no painel: mantida como está.';

            return;
        }
        if ($inicio->titulo !== ConteudoPublicare::NOME_DO_SITE || $this->dadosDifere($inicio, $oficial)) {
            $this->servicos->objetos->atualizar($inicio->id, ['titulo' => ConteudoPublicare::NOME_DO_SITE, 'dados' => $oficial], self::MOTIVO);
            ++$this->atualizados;
        } else {
            ++$this->inalterados;
        }
    }

    /** `sobre` e a notícia de boas-vindas da instalação vão para a lixeira, mas só se ainda trazem o texto original. */
    private function restosDaInstalacao(): void
    {
        foreach (['sobre' => 'Página de exemplo', 'bem-vindo-ao-novo-site' => 'notícia de exemplo'] as $url => $marca) {
            $o = $this->servicos->objetos->porUrl($this->site->id, $url);
            if ($o === null || $o->status === 'apagado' || !str_contains((string) ($o->dados['corpo'] ?? ''), $marca)) {
                continue;
            }
            try {
                $this->servicos->objetos->mandarParaLixeira($o->id, $o->versao, self::MOTIVO);
                ++$this->removidos;
            } catch (ConflitoDeArvore) {
                $this->avisos[] = "O texto inicial de /{$url} ficou como está: há conteúdo dentro dele.";
            }
        }
    }

    private function atalho(int $pai, string $url, string $titulo, Objeto $destino, int $peso, string $descricao = ''): void
    {
        $this->objeto('link', $pai, $titulo, $url, ['destino_interno' => $destino->id, 'destino_externo' => null, 'nova_aba' => false], ['peso' => $peso] + ($descricao === '' ? [] : ['descricao' => $descricao]));
    }

    /** @param array<string, Objeto> $paginas */
    private function destaques(array $paginas): void
    {
        $pasta = $this->objeto('pasta', $this->raiz, 'Destaques', 'destaques', [], ['peso' => 995, 'descricao' => 'Banners da página inicial']);
        foreach ([
            ['conhecer', 'Veja o que ele faz', 'Conteúdo em árvore, moldes, revisão, domínios, busca e cópias de segurança.', [194, 65, 12], 'o-que-ele-faz'],
            ['instalar', 'Instale e experimente', 'Requisitos do servidor e caminhos de instalação.', [232, 89, 12], 'instalacao-e-requisitos'],
            ['participar', 'Participe do projeto', 'Você não precisa programar para ajudar.', [255, 154, 60], 'comunidade'],
        ] as $i => [$u, $titulo, $texto, $cor, $alvo]) {
            $this->objeto('destaque', $pasta->id, $titulo, 'destaque-' . $u, ['texto' => $texto, 'nova_aba' => false, 'destino_interno' => $paginas[$alvo]->id], ['peso' => ($i + 1) * 10], $this->imagem($titulo, $cor, 'destaque-' . $u . '.png'));
        }
    }

    private function guias(Objeto $acompanhar): void
    {
        $guias = $this->objeto('pasta', $acompanhar->id, 'Conceitos e guias', 'noticias', [], ['peso' => 10, 'no_menu' => true, 'descricao' => 'Conceitos do Publicare 4, explicados em poucas linhas']);
        foreach (ConteudoPublicare::guias() as $i => $g) {
            $this->objeto('noticia', $guias->id, $g['titulo'], $g['url'], ['resumo' => $g['resumo'], 'corpo' => $g['corpo']], ['peso' => ($i + 1) * 10, 'tags' => $g['tags']]);
        }
    }

    private function perguntas(): void
    {
        $faq = $this->objeto('perguntas', $this->raiz, 'Perguntas frequentes', 'perguntas-frequentes', [], ['peso' => 50, 'no_menu' => true, 'rotulo_menu' => 'Perguntas', 'descricao' => 'Licença, requisitos, instalação, atualização, backups, migração, acessibilidade e segurança']);
        foreach (ConteudoPublicare::perguntas() as $i => [$pergunta, $resposta, $categoria]) {
            $this->objeto('faq', $faq->id, $pergunta, 'faq-' . ($i + 1), ['resposta' => $resposta, 'categoria' => $categoria], ['peso' => ($i + 1) * 10]);
        }
    }

    private function linksUteis(Objeto $comunidade, Objeto $acompanhar): void
    {
        $pasta = $this->objeto('pasta', $comunidade->id, 'Links úteis', 'links-uteis', [], ['peso' => 30, 'no_menu' => false, 'descricao' => 'Código, documentação e regras do projeto']);
        $repo = ConteudoPublicare::REPOSITORIO;
        $this->atalho($pasta->id, 'link-acompanhar', 'Como acompanhar o projeto', $acompanhar, 10, 'Versões, mudanças e plano do projeto');
        foreach ([
            ['link-github', 'Código no GitHub', $repo, 20, 'O código-fonte aberto do Publicare 4'],
            ['link-changelog', 'CHANGELOG', $repo . '/blob/main/CHANGELOG.md', 30, 'O que mudou em cada versão'],
            ['link-contribuir', 'Como contribuir', $repo . '/blob/main/CONTRIBUTING.md', 40, 'Guia para quem quer ajudar'],
            ['link-conduta', 'Código de Conduta', $repo . '/blob/main/CODE_OF_CONDUCT.md', 50, 'Como convivemos no projeto'],
        ] as [$url, $titulo, $destino, $peso, $descricao]) {
            $this->objeto('link', $pasta->id, $titulo, $url, ['destino_externo' => $destino, 'destino_interno' => null, 'nova_aba' => true], ['peso' => $peso, 'descricao' => $descricao]);
        }
    }

    private function atalhosUteis(): void
    {
        foreach (ConteudoPublicare::atalhosUteis() as $u) {
            $this->objeto('link', $this->raiz, $u['titulo'], $u['url'], ['destino_externo' => $u['destino'], 'destino_interno' => null, 'nova_aba' => true], ['peso' => $u['peso'], 'descricao' => $u['descricao']]);
        }
    }

    private function blocos(): void
    {
        $blocos = $this->objeto('pasta', $this->raiz, 'Blocos', 'blocos', [], ['peso' => 996, 'descricao' => 'Trechos reutilizáveis']);
        $this->objeto('bloco', $blocos->id, 'Rodapé', 'bloco-rodape', ['corpo' => '<p>O Publicare 4 é software livre, sob a licença Apache-2.0. <a href="' . ConteudoPublicare::REPOSITORIO . '">Código-fonte no GitHub</a> · <a href="/comunidade">Comunidade</a> · <a href="/contato">Contato</a></p>']);
    }

    /** Formulário real de contato: as mensagens chegam como conteúdo privado na caixa "Mensagens recebidas" (lida pelo painel). */
    private function contato(): void
    {
        $caixa = $this->objeto('pasta', $this->raiz, 'Mensagens recebidas', self::CAIXA, [], ['descricao' => 'Mensagens enviadas pelo formulário de contato'], null, 'privado');
        $this->objeto('formulario', $this->raiz, 'Contato', 'contato', [
            'corpo' => '<p>Escreva para o projeto: dúvidas, sugestões ou parcerias. Respondemos assim que possível.</p><p>Para relatar uma falha de segurança, use o canal privado descrito na página de <a href="' . ConteudoPublicare::REPOSITORIO . '/blob/main/SECURITY.md">segurança</a>.</p>',
            'destino' => $caixa->id,
            'classe_envio' => 'mensagem_contato',
            'campos' => 'nome,email,mensagem',
            'mensagem' => 'Recebemos a sua mensagem. Obrigado!',
            'botao' => 'Enviar mensagem',
        ], ['peso' => 90, 'no_menu' => true]);
    }

    /**
     * Cria o objeto se o endereço não existe; senão, atualiza só o que difere do texto oficial (e o tira da lixeira, se foi apagado).
     *
     * @param array<string, string|int|float|bool|null> $dados `null` = a propriedade não deve existir
     * @param array{peso?: int, descricao?: string, no_menu?: bool, rotulo_menu?: string, tags?: string} $meta
     * @param (\Closure(int): array<string, string|int|float|bool>)|null $imagem
     */
    private function objeto(string $classe, int $pai, string $titulo, string $url, array $dados, array $meta = [], ?\Closure $imagem = null, string $status = 'publicado'): Objeto
    {
        $objetos = $this->servicos->objetos;
        $atual = $objetos->porUrl($this->site->id, $url);
        if ($atual === null) {
            $c = $this->servicos->classes->porNome($classe) ?? throw new \RuntimeException("Tipo {$classe} não existe");
            ++$this->criados;

            return $objetos->criar($this->site->id, $c, $pai, $titulo, $url, array_filter($dados, static fn ($v): bool => $v !== null), $status, $meta, false, $imagem);
        }
        $mudancas = [];
        if ($atual->titulo !== $titulo) {
            $mudancas['titulo'] = $titulo;
        }
        if ($atual->status !== $status) {
            $mudancas['status'] = $status;
        }
        foreach (['peso' => 0, 'descricao' => '', 'no_menu' => false, 'rotulo_menu' => ''] as $campo => $padrao) {
            $esperado = $meta[$campo] ?? $padrao;
            $valor = match ($campo) {
                'peso' => $atual->peso, 'descricao' => $atual->descricao, 'no_menu' => $atual->noMenu, default => $atual->rotuloMenu,
            };
            if ($valor !== $esperado) {
                $mudancas[$campo] = $esperado;
            }
        }
        if ($this->dadosDifere($atual, $dados)) {
            $mudancas['dados'] = $dados;
        }
        if (isset($meta['tags']) && $this->tagsDifere($atual->id, $meta['tags'])) {
            $mudancas['tags'] = $meta['tags'];
        }
        $moverPara = $atual->paiId !== $pai;
        if ($mudancas === [] && !$moverPara) {
            ++$this->inalterados;

            return $atual;
        }
        $resultado = $atual;
        if ($mudancas !== []) {
            $resultado = $objetos->atualizar($atual->id, $mudancas, self::MOTIVO);
        }
        if ($moverPara) {
            $resultado = $objetos->mover($atual->id, $pai, self::MOTIVO);
        }
        ++$this->atualizados;

        return $resultado;
    }

    /** @param array<string, mixed> $esperado */
    private function dadosDifere(Objeto $o, array $esperado): bool
    {
        foreach ($esperado as $chave => $valor) {
            $atual = $o->dados[$chave] ?? null;
            if ($valor === null || $valor === '') {
                if ($atual !== null && $atual !== '') {
                    return true;
                }
                continue;
            }
            if ($atual === null || !$this->igual($atual, $valor)) {
                return true;
            }
        }

        return false;
    }

    /** O HTML é gravado já limpo (`HtmlSeguro`): compara com o texto oficial como ele ficaria gravado. */
    private function igual(mixed $atual, mixed $esperado): bool
    {
        if ($this->texto($atual) === $this->texto($esperado)) {
            return true;
        }

        return is_string($esperado) && str_contains($esperado, '<') && $this->texto($atual) === HtmlSeguro::limpar($esperado);
    }

    private function texto(mixed $v): string
    {
        return is_bool($v) ? ($v ? '1' : '0') : (string) $v;
    }

    private function tagsDifere(int $id, string $esperadas): bool
    {
        $quebrar = static fn (string $s): array => array_values(array_filter(array_map('trim', explode(',', $s)), static fn (string $t): bool => $t !== ''));
        $a = $this->servicos->tags->nomesDoObjeto($id);
        $b = $quebrar($esperadas);
        sort($a);
        sort($b);

        return $a !== $b;
    }

    /**
     * Arte decorativa do destaque (sem texto). Sem a extensão GD o destaque nasce sem imagem: instalação em hospedagem sem GD não trava.
     * Só é enviada na criação: rodar de novo não duplica arquivos.
     *
     * @param array{0: int, 1: int, 2: int} $cor
     * @return (\Closure(int): array<string, int>)|null
     */
    private function imagem(string $titulo, array $cor, string $nome): ?\Closure
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->avisos[] = 'Extensão GD ausente: os destaques da home foram criados sem imagem.';

            return null;
        }
        $conteudo = ImagemDecorativa::png($titulo, $cor, 1200, 500);

        return function (int $novoId) use ($conteudo, $nome): array {
            $tmp = tempnam(sys_get_temp_dir(), 'pbl-oficial');
            if ($tmp === false || file_put_contents($tmp, $conteudo) === false) {
                throw new \RuntimeException('Não foi possível gravar o arquivo temporário da carga oficial');
            }
            try {
                $arquivo = $this->servicos->uploads()->enviar(new UploadedFile($tmp, $nome, 'image/png', null, true), ['nome' => 'imagem', 'tipo' => 'imagem'], $this->site->id, $novoId, null);
            } finally {
                @unlink($tmp);
            }

            return ['imagem' => $arquivo->id];
        };
    }
}
