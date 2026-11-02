<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\ConflitoDeVersao;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telas de classes, os "tipos de conteúdo" (ADR 0012, fase 5c): listar, criar, editar (descrição, filhos aceitos e as
 * propriedades) e apagar. As classes valem para todos os sites da instalação.
 *
 * Quem entrou vê a lista; só quem `Permissoes::podeModelarClasses` (o administrador global) abre as demais telas, conferido em
 * toda rota, também no POST. Todo POST confere origem e CSRF antes de qualquer outra coisa. Nada apaga valor guardado: campo
 * com valores só pode ser **arquivado** (com tela de confirmação mostrando quantos conteúdos têm valor), e o tipo dele não muda;
 * o `ClasseRepositorio` repete essas regras. A versão da classe evita sobrescrever o trabalho de outra pessoa. A auditoria guarda
 * só nomes de classe e de campo, nunca rótulos nem descrições.
 *
 * @phpstan-type LinhaDeCampo array{nome: string, rotulo: string, tipo: string, classe: string, obrigatorio: bool, ordem: string, situacao: string}
 * @phpstan-type CampoNovo array{nome: string, rotulo: string, tipo: string, classe: string, indexavel: bool, obrigatorio: bool, ordem: string}
 * @phpstan-type Rascunho array{descricao: string, aceita_filhos: bool, filhas: list<string>, campos: list<LinhaDeCampo>, novo: CampoNovo}
 * @phpstan-import-type Propriedade from Classe
 */
final class TelasDeClasses
{
    use AuxiliaresDeTela;

    public const SITUACOES = ['ativa' => 'Ativo', 'arquivada' => 'Arquivado', 'remover' => 'Remover'];
    public const NOMES_DOS_TIPOS = [
        'texto' => 'Texto curto', 'numero' => 'Número', 'html' => 'Texto formatado (HTML)', 'data' => 'Data',
        'booleano' => 'Sim ou não', 'ref' => 'Referência a outro conteúdo',
    ];

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
    ) {
    }

    /**
     * Qual tela atende o caminho e o método, ou null (o `Painel` responde 404). O id é validado por expressão ancorada.
     *
     * @return array{0: string, 1: int|null}|null
     */
    public static function rota(string $prefixo, string $caminho, string $metodo): ?array
    {
        if ($caminho === $prefixo . '/classes') {
            return $metodo === 'GET' ? ['lista', null] : null;
        }
        if ($caminho === $prefixo . '/classes/nova') {
            return match ($metodo) {
                'GET' => ['nova', null],
                'POST' => ['criar', null],
                default => null,
            };
        }
        if (preg_match('#^' . preg_quote($prefixo, '#') . '/classe/([1-9][0-9]{0,9})/(editar|apagar)$#D', $caminho, $m) !== 1) {
            return null;
        }
        $id = (int) $m[1];

        return match ([$m[2], $metodo]) {
            ['editar', 'GET'] => ['editar', $id],
            ['editar', 'POST'] => ['salvar', $id],
            ['apagar', 'GET'] => ['apagar', $id],
            ['apagar', 'POST'] => ['confirmarApagar', $id],
            default => null,
        };
    }

    /** @param array{0: string, 1: int|null} $rota */
    public function tratar(SessaoAtiva $sessao, array $rota, Request $requisicao): Response
    {
        [$tela, $id] = $rota;
        if ($tela === 'lista') {
            return $this->lista($sessao, $requisicao);
        }
        $post = $requisicao->getMethod() === 'POST';
        if ($post && !$this->postConfere($sessao, $requisicao)) {
            return Pagina::resposta('Pedido recusado', '<p>O pedido não pôde ser confirmado. Volte, recarregue a página e tente de novo.</p>', Response::HTTP_FORBIDDEN);
        }
        $u = $sessao->usuario;
        if (!$this->servicos->permissoes->podeModelarClasses($u)) {
            if ($post) {
                $this->servicos->auditoria->registrar('classe.negado', 'usuario:' . $u->id, $u->id, null, $id === null ? [] : ['classe_id' => $id]);
            }

            return $this->semPermissao($sessao);
        }
        if ($tela === 'nova') {
            return $this->formularioNovo($sessao, '', '', true, []);
        }
        if ($tela === 'criar') {
            return $this->criar($sessao, $requisicao);
        }
        $classe = $id === null ? null : $this->servicos->classes->porId($id);
        if ($classe === null) {
            return Pagina::resposta('Não encontrado', '<div class="alert alert-warning" role="alert">Este tipo de conteúdo não existe (talvez tenha sido apagado).</div>'
                . '<p><a href="' . $this->linkLista() . '">Voltar aos tipos de conteúdo</a></p>', Response::HTTP_NOT_FOUND, false, $this->barra($sessao));
        }

        return match ($tela) {
            'editar' => $this->formularioDeEdicao($sessao, $classe, $this->rascunhoDe($classe), null, Response::HTTP_OK, $this->avisoDe($requisicao)),
            'salvar' => $this->salvar($sessao, $classe, $requisicao),
            'apagar' => $this->telaDeApagar($sessao, $classe),
            default => $this->apagar($sessao, $classe, $requisicao),
        };
    }

    private function lista(SessaoAtiva $sessao, Request $requisicao): Response
    {
        $modela = $this->servicos->permissoes->podeModelarClasses($sessao->usuario);
        $classes = $this->servicos->classes->todas();
        $usos = $modela ? $this->servicos->classes->objetosPorClasse() : [];
        $corpo = ($requisicao->query->all()['ok'] ?? null) === 'apagada' ? '<div class="alert alert-success" role="status">Tipo de conteúdo apagado.</div>' : '';
        $corpo .= '<p>Um tipo de conteúdo (classe) diz quais campos um conteúdo tem, como "Notícia" com data e autor. Os tipos valem para todos os sites desta instalação.</p>';
        if ($modela) {
            $corpo .= '<p><a class="btn btn-primary" href="' . Painel::PREFIXO . '/classes/nova">Novo tipo de conteúdo</a></p>';
        } else {
            $corpo .= '<p class="text-body-secondary">Só administradores criam e alteram tipos de conteúdo.</p>';
        }
        if ($classes === []) {
            return Pagina::resposta('Tipos de conteúdo', $corpo . '<p>Nenhum tipo de conteúdo ainda.</p>', Response::HTTP_OK, false, $this->barra($sessao));
        }
        $corpo .= '<div class="table-responsive"><table class="table table-sm table-hover align-middle"><caption class="visually-hidden">Tipos de conteúdo</caption><thead><tr>'
            . '<th scope="col">Nome</th><th scope="col">Descrição</th><th scope="col">Campos</th>'
            . ($modela ? '<th scope="col">Conteúdos que usam</th><th scope="col"><span class="visually-hidden">Ações</span></th>' : '') . '</tr></thead><tbody>';
        foreach ($classes as $c) {
            $arquivados = count(array_filter($c->propriedades, static fn (array $p): bool => $p['arquivada'] ?? false));
            $ativos = count($c->propriedades) - $arquivados;
            $corpo .= '<tr><td><code>' . Pagina::h($c->nome) . '</code></td>'
                . '<td>' . ($c->descricao === '' ? '<span class="text-body-secondary">—</span>' : Pagina::h(mb_strimwidth($c->descricao, 0, 160, '…'))) . '</td>'
                . '<td>' . $ativos . ($arquivados > 0 ? ' <span class="text-body-secondary">(+' . $arquivados . ' arquivado' . ($arquivados > 1 ? 's' : '') . ')</span>' : '') . '</td>';
            if ($modela) {
                $corpo .= '<td>' . ($usos[$c->id] ?? 0) . '</td><td class="text-end text-nowrap">'
                    . '<a class="btn btn-sm btn-outline-primary" href="' . $this->link($c->id) . '/editar">Editar<span class="visually-hidden"> ' . Pagina::h($c->nome) . '</span></a> '
                    . '<a class="btn btn-sm btn-outline-danger" href="' . $this->link($c->id) . '/apagar">Apagar<span class="visually-hidden"> ' . Pagina::h($c->nome) . '</span></a></td>';
            }
            $corpo .= '</tr>';
        }
        $corpo .= '</tbody></table></div>';

        return Pagina::resposta('Tipos de conteúdo', $corpo, Response::HTTP_OK, false, $this->barra($sessao));
    }

    // ---------------------------------------------------------------- criar

    /** @param list<string> $filhas */
    private function formularioNovo(SessaoAtiva $sessao, string $nome, string $descricao, bool $aceita, array $filhas, ?string $erro = null, int $status = Response::HTTP_OK): Response
    {
        $corpo = $this->erro($erro)
            . '<form method="post" action="' . Painel::PREFIXO . '/classes/nova"' . ($erro === null ? '' : ' aria-describedby="erro-formulario"') . '>'
            . $this->campoCsrf($sessao)
            . '<div class="mb-3"><label for="campo-nome" class="form-label">Nome técnico</label>'
            . '<input id="campo-nome" class="form-control" name="nome" maxlength="' . ClasseRepositorio::MAX_NOME . '" required value="' . Pagina::h($nome) . '" aria-describedby="ajuda-nome">'
            . '<div id="ajuda-nome" class="form-text">Letras minúsculas sem acento, números e <code>_</code>, começando por letra (por exemplo <code>noticia</code>). É o nome que os moldes usam e não muda depois.</div></div>'
            . $this->camposDaClasse($descricao, $aceita, $filhas, $this->servicos->classes->todas(), null)
            . '<p class="form-text">Os campos (propriedades) são adicionados depois de criar o tipo.</p>'
            . '<button type="submit" class="btn btn-primary">Criar tipo de conteúdo</button> <a class="btn btn-outline-secondary" href="' . $this->linkLista() . '">Cancelar</a></form>';

        return Pagina::resposta('Novo tipo de conteúdo', $corpo, $status, false, $this->barra($sessao));
    }

    private function criar(SessaoAtiva $sessao, Request $requisicao): Response
    {
        $campos = $requisicao->request->all();
        $nome = $campos['nome'] ?? null;
        $descricao = $campos['descricao'] ?? '';
        $filhas = $this->listaDeTextos($campos['filhas'] ?? []);
        if (!is_string($nome) || !is_string($descricao) || $filhas === null) {
            return $this->incompleto();
        }
        $nome = trim($nome);
        $descricao = $this->normalizarDescricao($descricao);
        $aceita = ($campos['aceita_filhos'] ?? null) === '1';
        $erro = match (true) {
            !ClasseRepositorio::nomeValido($nome) => 'Nome técnico inválido: use letras minúsculas sem acento, números e _, começando por letra, até ' . ClasseRepositorio::MAX_NOME . ' caracteres.',
            $this->servicos->classes->porNome($nome) !== null => 'Já existe um tipo de conteúdo com esse nome técnico.',
            default => $this->erroDeTexto($descricao, ClasseRepositorio::MAX_DESCRICAO, true, 'A descrição'),
        };
        if ($erro !== null) {
            return $this->formularioNovo($sessao, $nome, $descricao, $aceita, $filhas, $erro, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $classe = $this->servicos->classes->criar($nome, [], ['descricao' => $descricao, 'aceita_filhos' => $aceita, 'filhas' => $filhas]);
        } catch (\InvalidArgumentException $e) {
            return $this->formularioNovo($sessao, $nome, $descricao, $aceita, $filhas, $this->mensagemDoRepositorio($e), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $u = $sessao->usuario;
        $this->servicos->auditoria->registrar('classe.criar', 'usuario:' . $u->id, $u->id, null, ['classe_id' => $classe->id, 'classe' => $classe->nome]);

        return new RedirectResponse($this->link($classe->id) . '/editar?ok=criada', Response::HTTP_SEE_OTHER);
    }

    // ---------------------------------------------------------------- editar

    /** @return Rascunho */
    private function rascunhoDe(Classe $classe): array
    {
        $campos = [];
        foreach ($classe->propriedades as $i => $p) {
            $campos[] = [
                'nome' => $p['nome'], 'rotulo' => $p['rotulo'] ?? '', 'tipo' => $p['tipo'], 'classe' => $p['classe'] ?? '',
                'obrigatorio' => $p['obrigatorio'] ?? false, 'ordem' => (string) (($i + 1) * 10),
                'situacao' => ($p['arquivada'] ?? false) ? 'arquivada' : 'ativa',
            ];
        }

        return [
            'descricao' => $classe->descricao, 'aceita_filhos' => $classe->aceitaFilhos, 'filhas' => $classe->filhas, 'campos' => $campos,
            'novo' => ['nome' => '', 'rotulo' => '', 'tipo' => 'texto', 'classe' => '', 'indexavel' => false, 'obrigatorio' => false, 'ordem' => ''],
        ];
    }

    /**
     * O formulário enviado, no mesmo formato. null = formulário incompleto ou com valor que não é texto (400).
     *
     * @param array<string, mixed> $f
     * @return Rascunho|null
     */
    private function rascunhoDaRequisicao(Classe $classe, array $f): ?array
    {
        $descricao = $f['descricao'] ?? '';
        $filhas = $this->listaDeTextos($f['filhas'] ?? []);
        $enviados = $f['p'] ?? [];
        $novo = $f['novo'] ?? [];
        if (!is_string($descricao) || $filhas === null || !is_array($enviados) || !is_array($novo)) {
            return null;
        }
        $campos = [];
        foreach ($classe->propriedades as $p) {
            $e = $enviados[$p['nome']] ?? null;
            if (!is_array($e)) {
                return null;
            }
            $linha = ['nome' => $p['nome']];
            foreach (['rotulo', 'tipo', 'classe', 'ordem', 'situacao'] as $chave) {
                $valor = $e[$chave] ?? ($chave === 'classe' ? '' : null);
                if (!is_string($valor)) {
                    return null;
                }
                $linha[$chave] = $chave === 'rotulo' ? trim($valor) : $valor;
            }
            $linha['obrigatorio'] = ($e['obrigatorio'] ?? null) === '1';
            $campos[] = $linha;
        }
        $linhaNova = [];
        foreach (['nome', 'rotulo', 'tipo', 'classe', 'ordem'] as $chave) {
            $valor = $novo[$chave] ?? '';
            if (!is_string($valor)) {
                return null;
            }
            $linhaNova[$chave] = trim($valor);
        }
        $linhaNova['indexavel'] = ($novo['indexavel'] ?? null) === '1';
        $linhaNova['obrigatorio'] = ($novo['obrigatorio'] ?? null) === '1';

        /** @var Rascunho $rascunho */
        $rascunho = [
            'descricao' => $this->normalizarDescricao($descricao), 'aceita_filhos' => ($f['aceita_filhos'] ?? null) === '1', 'filhas' => $filhas,
            'campos' => $campos, 'novo' => $linhaNova,
        ];

        return $rascunho;
    }

    /**
     * Confere o rascunho contra a classe atual e o uso de cada campo. Devolve a mensagem do primeiro problema, ou null.
     *
     * @param Rascunho $r
     * @param array<string, array{objetos: int, historico: int}> $uso
     */
    private function erroDoRascunho(Classe $classe, array $r, array $uso): ?string
    {
        $erro = $this->erroDeTexto($r['descricao'], ClasseRepositorio::MAX_DESCRICAO, true, 'A descrição');
        if ($erro !== null) {
            return $erro;
        }
        foreach ($r['filhas'] as $f) {
            if ($f !== $classe->nome && $this->servicos->classes->porNome($f) === null) {
                return 'Uma das classes filhas escolhidas não existe mais. Recarregue a página.';
            }
        }
        $definidas = [];
        foreach ($classe->propriedades as $p) {
            $definidas[$p['nome']] = $p;
        }
        foreach ($r['campos'] as $c) {
            $p = $definidas[$c['nome']];
            $rotulo = 'O campo ' . $c['nome'];
            $erro = $this->erroDeTexto($c['rotulo'], ClasseRepositorio::MAX_ROTULO, false, 'O rótulo de ' . $c['nome'])
                ?? $this->erroDeOrdem($c['ordem'], $c['nome'])
                ?? $this->erroDeTipo($c['tipo'], $c['classe'], $classe, $c['nome']);
            if ($erro !== null) {
                return $erro;
            }
            if (!isset(self::SITUACOES[$c['situacao']])) {
                return $rotulo . ' tem uma situação inválida.';
            }
            $comValor = max($uso[$c['nome']]['objetos'] ?? 0, $uso[$c['nome']]['historico'] ?? 0);
            $classeNova = $c['tipo'] === 'ref' ? $c['classe'] : '';
            if ($comValor > 0 && ($c['tipo'] !== $p['tipo'] || $classeNova !== ($p['classe'] ?? ''))) {
                return $rotulo . ' tem valor em ' . $comValor . ' conteúdo(s), contando versões antigas, e por isso o tipo dele não pode mudar: os valores guardados deixariam de valer. '
                    . 'Crie um campo novo com o tipo certo e arquive este.';
            }
            if ($comValor > 0 && $c['situacao'] === 'remover') {
                return $rotulo . ' tem valor em ' . $comValor . ' conteúdo(s), contando versões antigas, e não pode ser removido. '
                    . 'Escolha "Arquivado": ele sai dos formulários e os valores continuam guardados.';
            }
        }
        $n = $r['novo'];
        if ($n['nome'] === '') {
            return $n['rotulo'] !== '' ? 'Para adicionar um campo, preencha o nome técnico dele.' : null;
        }
        if (!ClasseRepositorio::nomeValido($n['nome']) || in_array($n['nome'], ClasseRepositorio::RESERVADOS, true)) {
            return 'Nome técnico do campo novo inválido: use letras minúsculas sem acento, números e _, começando por letra; nomes do sistema (como titulo, url, descricao) não podem ser usados.';
        }
        if (isset($definidas[$n['nome']])) {
            return 'Já existe um campo chamado ' . $n['nome'] . ' neste tipo de conteúdo.';
        }
        if (count($classe->propriedades) >= ClasseRepositorio::MAX_PROPRIEDADES) {
            return 'Um tipo de conteúdo pode ter até ' . ClasseRepositorio::MAX_PROPRIEDADES . ' campos.';
        }

        return $this->erroDeTexto($n['rotulo'], ClasseRepositorio::MAX_ROTULO, false, 'O rótulo do campo novo')
            ?? ($n['ordem'] === '' ? null : $this->erroDeOrdem($n['ordem'], $n['nome']))
            ?? $this->erroDeTipo($n['tipo'], $n['classe'], $classe, $n['nome']);
    }

    private function erroDeOrdem(string $ordem, string $campo): ?string
    {
        return $ordem !== '' && strlen($ordem) <= 4 && ctype_digit($ordem) ? null : 'A ordem do campo ' . $campo . ' deve ser um número de 0 a 9999.';
    }

    private function erroDeTipo(string $tipo, string $alvo, Classe $classe, string $campo): ?string
    {
        if (!in_array($tipo, ClasseRepositorio::TIPOS, true)) {
            return 'Tipo inválido no campo ' . $campo . '.';
        }
        if ($tipo === 'ref' && $alvo !== '' && $alvo !== $classe->nome && $this->servicos->classes->porNome($alvo) === null) {
            return 'O campo ' . $campo . ' aponta para um tipo de conteúdo que não existe.';
        }

        return null;
    }

    /**
     * Monta a lista de propriedades para o repositório: na ordem pedida (empate: a ordem de antes), sem as removidas e com o
     * campo novo. Tipo, classe apontada e indexação das existentes vêm da definição salva sempre que o tipo não mudou.
     *
     * @param Rascunho $r
     * @return list<array{nome: string, tipo: string, indexavel: bool, classe?: string, rotulo?: string, obrigatorio?: bool, arquivada?: bool}>
     */
    private function propriedadesDe(Classe $classe, array $r): array
    {
        $definidas = [];
        foreach ($classe->propriedades as $p) {
            $definidas[$p['nome']] = $p;
        }
        $itens = [];
        foreach ($r['campos'] as $i => $c) {
            if ($c['situacao'] === 'remover') {
                continue;
            }
            $antiga = $definidas[$c['nome']];
            $p = ['nome' => $c['nome'], 'tipo' => $c['tipo'], 'indexavel' => $c['tipo'] === $antiga['tipo'] ? $antiga['indexavel'] : false];
            if ($c['tipo'] === 'ref' && $c['classe'] !== '') {
                $p['classe'] = $c['classe'];
            }
            $itens[] = [(int) $c['ordem'], $i, $this->comApresentacao($p, $c['rotulo'], $c['obrigatorio'], $c['situacao'] === 'arquivada')];
        }
        $n = $r['novo'];
        if ($n['nome'] !== '') {
            $p = ['nome' => $n['nome'], 'tipo' => $n['tipo'], 'indexavel' => $n['indexavel']];
            if ($n['tipo'] === 'ref' && $n['classe'] !== '') {
                $p['classe'] = $n['classe'];
            }
            $ordem = $n['ordem'] === '' ? PHP_INT_MAX : (int) $n['ordem'];
            $itens[] = [$ordem, PHP_INT_MAX, $this->comApresentacao($p, $n['rotulo'], $n['obrigatorio'], false)];
        }
        usort($itens, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $item): array => $item[2], $itens);
    }

    /**
     * @param array{nome: string, tipo: string, indexavel: bool, classe?: string} $p
     * @return array{nome: string, tipo: string, indexavel: bool, classe?: string, rotulo?: string, obrigatorio?: bool, arquivada?: bool}
     */
    private function comApresentacao(array $p, string $rotulo, bool $obrigatorio, bool $arquivada): array
    {
        if ($p['tipo'] === 'ref') {
            $p['indexavel'] = true; // o repositório faz o mesmo; aqui para a comparação de "nada mudou" bater
        }
        if ($rotulo !== '') {
            $p['rotulo'] = $rotulo;
        }
        if ($obrigatorio) {
            $p['obrigatorio'] = true;
        }
        if ($arquivada) {
            $p['arquivada'] = true;
        }

        return $p;
    }

    private function salvar(SessaoAtiva $sessao, Classe $classe, Request $requisicao): Response
    {
        $f = $requisicao->request->all();
        $versao = $this->numero($f['versao'] ?? null);
        $r = $this->rascunhoDaRequisicao($classe, $f);
        if ($versao === null || $versao < 1 || $r === null) {
            return $this->incompleto();
        }
        if ($versao !== $classe->versao) {
            return $this->formularioDeEdicao($sessao, $classe, $this->rascunhoDe($classe), 'Outra pessoa alterou este tipo de conteúdo depois que você abriu o formulário. '
                . 'Nada do que você enviou foi gravado. O formulário abaixo mostra o que está salvo agora; refaça as suas mudanças sobre ele.', Response::HTTP_CONFLICT);
        }
        if (($f['revisar'] ?? null) === '1') {
            return $this->formularioDeEdicao($sessao, $classe, $r);
        }
        $uso = $this->servicos->classes->usoDasPropriedades($classe);
        $erro = $this->erroDoRascunho($classe, $r, $uso);
        if ($erro !== null) {
            return $this->formularioDeEdicao($sessao, $classe, $r, $erro, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Arquivar um campo que conteúdos usam hoje pede confirmação, com a contagem à vista.
        $arquivando = [];
        foreach ($r['campos'] as $c) {
            $antes = $this->definicao($classe, $c['nome']);
            if ($c['situacao'] === 'arquivada' && !($antes['arquivada'] ?? false) && ($uso[$c['nome']]['objetos'] ?? 0) > 0) {
                $arquivando[$c['nome']] = $uso[$c['nome']]['objetos'];
            }
        }
        if ($arquivando !== [] && ($f['confirmar'] ?? null) !== '1') {
            return $this->telaDeConfirmacao($sessao, $classe, $r, $arquivando);
        }

        $propriedades = $this->propriedadesDe($classe, $r);
        $filhas = $r['aceita_filhos'] ? $r['filhas'] : [];
        sort($filhas);
        $campos = $this->camposAlterados($classe, $propriedades, $r['descricao'], $r['aceita_filhos'], array_values(array_unique($filhas)));
        if ($campos === []) {
            return new RedirectResponse($this->link($classe->id) . '/editar?ok=nada', Response::HTTP_SEE_OTHER);
        }
        try {
            $nova = $this->servicos->classes->atualizar($classe->id, $versao, $propriedades, ['descricao' => $r['descricao'], 'aceita_filhos' => $r['aceita_filhos'], 'filhas' => $r['filhas']]);
        } catch (ConflitoDeVersao) {
            $atual = $this->servicos->classes->porId($classe->id) ?? $classe;

            return $this->formularioDeEdicao($sessao, $atual, $this->rascunhoDe($atual), 'Outra pessoa alterou este tipo de conteúdo enquanto você salvava. Nada foi gravado; confira o que está salvo agora e refaça as suas mudanças.', Response::HTTP_CONFLICT);
        } catch (\InvalidArgumentException $e) {
            return $this->formularioDeEdicao($sessao, $classe, $r, $this->mensagemDoRepositorio($e), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $u = $sessao->usuario;
        $this->servicos->auditoria->registrar('classe.atualizar', 'usuario:' . $u->id, $u->id, null, [
            'classe_id' => $nova->id, 'classe' => $nova->nome, 'versao' => $nova->versao, 'campos' => mb_substr(implode(',', $campos), 0, 2000),
        ]);

        return new RedirectResponse($this->link($classe->id) . '/editar?ok=salva', Response::HTTP_SEE_OTHER);
    }

    /**
     * Nomes do que mudou, para a auditoria (nunca o texto de rótulo ou descrição).
     *
     * @param list<array{nome: string, tipo: string, indexavel: bool, classe?: string, rotulo?: string, obrigatorio?: bool, arquivada?: bool}> $novas
     * @param list<string> $filhas
     * @return list<string>
     */
    private function camposAlterados(Classe $classe, array $novas, string $descricao, bool $aceita, array $filhas): array
    {
        $campos = [];
        if ($descricao !== $classe->descricao) {
            $campos[] = 'descricao';
        }
        if ($aceita !== $classe->aceitaFilhos) {
            $campos[] = 'aceita_filhos';
        }
        if ($filhas !== $classe->filhas) {
            $campos[] = 'filhas';
        }
        $porNome = [];
        foreach ($novas as $p) {
            $porNome[$p['nome']] = $p;
        }
        $mantidas = [];
        foreach ($classe->propriedades as $antiga) {
            $nome = $antiga['nome'];
            $nova = $porNome[$nome] ?? null;
            if ($nova === null) {
                $campos[] = 'propriedade.' . $nome . '.removida';
                continue;
            }
            $mantidas[] = $nome;
            foreach (['tipo' => '', 'classe' => '', 'rotulo' => '', 'obrigatorio' => false, 'arquivada' => false] as $chave => $padrao) {
                if (($nova[$chave] ?? $padrao) !== ($antiga[$chave] ?? $padrao)) {
                    $campos[] = 'propriedade.' . $nome . '.' . $chave;
                }
            }
        }
        foreach ($novas as $p) {
            if ($this->definicao($classe, $p['nome']) === null) {
                $campos[] = 'propriedade.' . $p['nome'] . '.nova';
            }
        }
        $ordemNova = array_values(array_filter(array_map(static fn (array $p): string => $p['nome'], $novas), static fn (string $n): bool => in_array($n, $mantidas, true)));
        if ($ordemNova !== $mantidas) {
            $campos[] = 'ordem';
        }

        return $campos;
    }

    /** @return Propriedade|null */
    private function definicao(Classe $classe, string $nome): ?array
    {
        foreach ($classe->propriedades as $p) {
            if ($p['nome'] === $nome) {
                return $p;
            }
        }

        return null;
    }

    /**
     * @param Rascunho $r
     * @param array<string, int> $arquivando nome do campo => conteúdos com valor nele hoje
     */
    private function telaDeConfirmacao(SessaoAtiva $sessao, Classe $classe, array $r, array $arquivando): Response
    {
        $lista = '';
        foreach ($arquivando as $nome => $n) {
            $p = $this->definicao($classe, $nome) ?? ['nome' => $nome];
            $lista .= '<li><strong>' . Pagina::h(Classe::rotuloDe($p)) . '</strong> (<code>' . Pagina::h($nome) . '</code>): '
                . $n . ' conteúdo' . ($n > 1 ? 's têm' : ' tem') . ' valor neste campo.</li>';
        }
        $corpo = '<div class="alert alert-warning" role="alert"><p>Você vai arquivar campos que estão em uso:</p><ul>' . $lista . '</ul>'
            . '<p class="mb-0">Arquivar <strong>não apaga</strong> nada: o campo sai dos formulários de conteúdo, os valores continuam guardados e os moldes que já os mostram continuam mostrando. '
            . 'Dá para voltar o campo a "Ativo" depois.</p></div>'
            . '<form method="post" action="' . $this->link($classe->id) . '/editar">' . $this->campoCsrf($sessao)
            . '<input type="hidden" name="versao" value="' . $classe->versao . '">' . $this->camposOcultos($r)
            . '<button type="submit" class="btn btn-warning" name="confirmar" value="1">Confirmar e salvar</button> '
            . '<button type="submit" class="btn btn-outline-secondary" name="revisar" value="1">Voltar ao formulário</button></form>';

        return Pagina::resposta('Confirmar: ' . $classe->nome, $corpo, Response::HTTP_OK, false, $this->barra($sessao));
    }

    /** @param Rascunho $r */
    private function camposOcultos(array $r): string
    {
        $h = static fn (string $nome, string $valor): string => '<input type="hidden" name="' . Pagina::h($nome) . '" value="' . Pagina::h($valor) . '">';
        $saida = $h('descricao', $r['descricao']) . ($r['aceita_filhos'] ? $h('aceita_filhos', '1') : '');
        foreach ($r['filhas'] as $f) {
            $saida .= $h('filhas[]', $f);
        }
        foreach ($r['campos'] as $c) {
            $base = 'p[' . $c['nome'] . ']';
            foreach (['rotulo', 'tipo', 'classe', 'ordem', 'situacao'] as $chave) {
                $saida .= $h($base . '[' . $chave . ']', $c[$chave]);
            }
            $saida .= $c['obrigatorio'] ? $h($base . '[obrigatorio]', '1') : '';
        }
        foreach (['nome', 'rotulo', 'tipo', 'classe', 'ordem'] as $chave) {
            $saida .= $h('novo[' . $chave . ']', $r['novo'][$chave]);
        }
        $saida .= ($r['novo']['indexavel'] ? $h('novo[indexavel]', '1') : '') . ($r['novo']['obrigatorio'] ? $h('novo[obrigatorio]', '1') : '');

        return $saida;
    }

    /** @param Rascunho $r */
    private function formularioDeEdicao(SessaoAtiva $sessao, Classe $classe, array $r, ?string $erro = null, int $status = Response::HTTP_OK, string $aviso = ''): Response
    {
        $todas = $this->servicos->classes->todas();
        $uso = $this->servicos->classes->usoDasPropriedades($classe);
        $total = $this->servicos->classes->contarObjetos($classe->id);
        $corpo = $aviso . $this->erro($erro)
            . '<p>Nome técnico: <code>' . Pagina::h($classe->nome) . '</code> · ' . $total . ' conteúdo' . ($total === 1 ? ' usa' : 's usam') . ' este tipo · versão ' . $classe->versao . '.</p>'
            . '<form method="post" action="' . $this->link($classe->id) . '/editar"' . ($erro === null ? '' : ' aria-describedby="erro-formulario"') . '>'
            . $this->campoCsrf($sessao) . '<input type="hidden" name="versao" value="' . $classe->versao . '">'
            . $this->camposDaClasse($r['descricao'], $r['aceita_filhos'], $r['filhas'], $todas, $classe->nome)
            . '<h2 class="h5 mt-4">Campos</h2>';

        if ($r['campos'] === []) {
            $corpo .= '<p class="text-body-secondary">Este tipo ainda não tem campos.</p>';
        } else {
            $corpo .= '<p class="form-text">Mude a ordem trocando os números (menor primeiro). Campo com valores guardados não troca de tipo e não pode ser removido, só arquivado.</p>'
                . '<div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">Campos deste tipo de conteúdo</caption><thead><tr>'
                . '<th scope="col">Nome técnico</th><th scope="col">Rótulo</th><th scope="col">Tipo</th><th scope="col">Obrigatório</th>'
                . '<th scope="col">Ordem</th><th scope="col">Situação</th><th scope="col">Em uso</th></tr></thead><tbody>';
            foreach ($r['campos'] as $i => $c) {
                $p = $this->definicao($classe, $c['nome']);
                $emUso = max($uso[$c['nome']]['objetos'] ?? 0, $uso[$c['nome']]['historico'] ?? 0);
                $base = 'p[' . $c['nome'] . ']';
                $id = 'campo-' . $i;
                $corpo .= '<tr><th scope="row"><code>' . Pagina::h($c['nome']) . '</code>'
                    . (($p['indexavel'] ?? false) ? ' <span class="badge text-bg-light">indexável</span>' : '')
                    . ($c['situacao'] === 'arquivada' ? ' <span class="badge text-bg-secondary">arquivado</span>' : '') . '</th>'
                    . '<td><label class="visually-hidden" for="' . $id . '-rotulo">Rótulo de ' . Pagina::h($c['nome']) . '</label>'
                    . '<input id="' . $id . '-rotulo" class="form-control form-control-sm" name="' . Pagina::h($base) . '[rotulo]" maxlength="' . ClasseRepositorio::MAX_ROTULO . '" value="' . Pagina::h($c['rotulo']) . '"></td><td>';
                if ($emUso > 0) {
                    $corpo .= Pagina::h($this->descreverTipo($c['tipo'], $c['classe']))
                        . '<input type="hidden" name="' . Pagina::h($base) . '[tipo]" value="' . Pagina::h($c['tipo']) . '">'
                        . '<input type="hidden" name="' . Pagina::h($base) . '[classe]" value="' . Pagina::h($c['classe']) . '">';
                } else {
                    $corpo .= $this->seletorDeTipo($id, $base, $c['tipo'], $c['classe'], $todas, $c['nome']);
                }
                $corpo .= '</td><td><div class="form-check"><input class="form-check-input" type="checkbox" id="' . $id . '-obrigatorio" name="' . Pagina::h($base) . '[obrigatorio]" value="1"' . ($c['obrigatorio'] ? ' checked' : '') . '>'
                    . '<label class="form-check-label" for="' . $id . '-obrigatorio"><span class="visually-hidden">' . Pagina::h($c['nome']) . ' é </span>obrigatório</label></div></td>'
                    . '<td><label class="visually-hidden" for="' . $id . '-ordem">Ordem de ' . Pagina::h($c['nome']) . '</label>'
                    . '<input id="' . $id . '-ordem" class="form-control form-control-sm campo-ordem" name="' . Pagina::h($base) . '[ordem]" inputmode="numeric" pattern="[0-9]{1,4}" maxlength="4" required value="' . Pagina::h($c['ordem']) . '"></td>'
                    . '<td><label class="visually-hidden" for="' . $id . '-situacao">Situação de ' . Pagina::h($c['nome']) . '</label>'
                    . '<select id="' . $id . '-situacao" class="form-select form-select-sm" name="' . Pagina::h($base) . '[situacao]">';
                foreach (self::SITUACOES as $valor => $texto) {
                    if ($valor === 'remover' && $emUso > 0) {
                        continue;
                    }
                    $corpo .= '<option value="' . $valor . '"' . ($c['situacao'] === $valor ? ' selected' : '') . '>' . $texto . '</option>';
                }
                $corpo .= '</select></td><td>' . ($uso[$c['nome']]['objetos'] ?? 0)
                    . (($uso[$c['nome']]['historico'] ?? 0) > ($uso[$c['nome']]['objetos'] ?? 0) ? ' <span class="text-body-secondary">(+ versões antigas)</span>' : '') . '</td></tr>';
            }
            $corpo .= '</tbody></table></div>';
        }

        $n = $r['novo'];
        $corpo .= '<fieldset class="border rounded p-3 mb-3"><legend class="h6 float-none w-auto px-1">Adicionar um campo (opcional)</legend><div class="row g-2">'
            . '<div class="col-md-4"><label for="novo-nome" class="form-label">Nome técnico</label><input id="novo-nome" class="form-control" name="novo[nome]" maxlength="' . ClasseRepositorio::MAX_NOME . '" value="' . Pagina::h($n['nome']) . '" aria-describedby="ajuda-novo-nome">'
            . '<div id="ajuda-novo-nome" class="form-text">Minúsculas, números e <code>_</code>; não muda depois.</div></div>'
            . '<div class="col-md-4"><label for="novo-rotulo" class="form-label">Rótulo</label><input id="novo-rotulo" class="form-control" name="novo[rotulo]" maxlength="' . ClasseRepositorio::MAX_ROTULO . '" value="' . Pagina::h($n['rotulo']) . '"></div>'
            . '<div class="col-md-4"><label for="novo-ordem" class="form-label">Ordem</label><input id="novo-ordem" class="form-control" name="novo[ordem]" inputmode="numeric" pattern="[0-9]{1,4}" maxlength="4" value="' . Pagina::h($n['ordem']) . '" aria-describedby="ajuda-novo-ordem">'
            . '<div id="ajuda-novo-ordem" class="form-text">Em branco: vai para o fim.</div></div>'
            . '<div class="col-md-8">' . $this->seletorDeTipo('novo', 'novo', $n['tipo'], $n['classe'], $todas, null, true) . '</div>'
            . '<div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" id="novo-obrigatorio" name="novo[obrigatorio]" value="1"' . ($n['obrigatorio'] ? ' checked' : '') . '><label class="form-check-label" for="novo-obrigatorio">Obrigatório</label></div>'
            . '<div class="form-check"><input class="form-check-input" type="checkbox" id="novo-indexavel" name="novo[indexavel]" value="1"' . ($n['indexavel'] ? ' checked' : '') . ' aria-describedby="ajuda-novo-indexavel"><label class="form-check-label" for="novo-indexavel">Indexável</label>'
            . '<div id="ajuda-novo-indexavel" class="form-text">Permite filtrar e ordenar por este campo nos moldes. Não muda depois que houver valores. Referências são sempre indexáveis.</div></div></div>'
            . '</div></fieldset>'
            . '<p class="form-text">"Obrigatório" fica guardado no tipo; a exigência ao salvar um conteúdo vem com as telas de conteúdo.</p>'
            . '<button type="submit" class="btn btn-primary">Salvar</button> <a class="btn btn-outline-secondary" href="' . $this->linkLista() . '">Voltar aos tipos</a></form>'
            . '<p class="mt-4"><a class="link-danger" href="' . $this->link($classe->id) . '/apagar">Apagar este tipo de conteúdo</a></p>';

        return Pagina::resposta('Tipo de conteúdo: ' . $classe->nome, $corpo, $status, false, $this->barra($sessao));
    }

    /** @param list<Classe> $todas */
    private function seletorDeTipo(string $id, string $base, string $tipo, string $alvo, array $todas, ?string $nomeDoCampo, bool $comRotulos = false): string
    {
        $sufixo = $nomeDoCampo === null ? ' do campo novo' : ' de ' . $nomeDoCampo;
        $html = '<label class="' . ($comRotulos ? 'form-label' : 'visually-hidden') . '" for="' . $id . '-tipo">Tipo' . ($comRotulos ? '' : Pagina::h($sufixo)) . '</label>'
            . '<select id="' . $id . '-tipo" class="form-select' . ($comRotulos ? '' : ' form-select-sm') . '" name="' . Pagina::h($base) . '[tipo]">';
        foreach (self::NOMES_DOS_TIPOS as $valor => $texto) {
            $html .= '<option value="' . $valor . '"' . ($tipo === $valor ? ' selected' : '') . '>' . $texto . '</option>';
        }
        $html .= '</select><label class="' . ($comRotulos ? 'form-label mt-2' : 'visually-hidden') . '" for="' . $id . '-classe">Se for referência, aponta para' . ($comRotulos ? '' : Pagina::h($sufixo)) . '</label>'
            . '<select id="' . $id . '-classe" class="form-select' . ($comRotulos ? '' : ' form-select-sm mt-1') . '" name="' . Pagina::h($base) . '[classe]">'
            . '<option value=""' . ($alvo === '' ? ' selected' : '') . '>' . ($comRotulos ? 'qualquer tipo (ou não é referência)' : 'referência: qualquer tipo') . '</option>';
        foreach ($todas as $c) {
            $html .= '<option value="' . Pagina::h($c->nome) . '"' . ($alvo === $c->nome ? ' selected' : '') . '>referência: ' . Pagina::h($c->nome) . '</option>';
        }

        return $html . '</select>';
    }

    private function descreverTipo(string $tipo, string $alvo): string
    {
        $nome = self::NOMES_DOS_TIPOS[$tipo] ?? $tipo;

        return $tipo === 'ref' && $alvo !== '' ? $nome . ' (' . $alvo . ')' : $nome;
    }

    /**
     * Descrição e filhos aceitos: comuns a criar e editar.
     *
     * @param list<string> $filhas
     * @param list<Classe> $todas
     */
    private function camposDaClasse(string $descricao, bool $aceita, array $filhas, array $todas, ?string $propria): string
    {
        $html = '<div class="mb-3"><label for="campo-descricao" class="form-label">Descrição</label>'
            . '<textarea id="campo-descricao" class="form-control" name="descricao" rows="3" maxlength="' . ClasseRepositorio::MAX_DESCRICAO . '" aria-describedby="ajuda-descricao">' . Pagina::h($descricao) . '</textarea>'
            . '<div id="ajuda-descricao" class="form-text">Opcional: para que serve este tipo, em poucas palavras.</div></div>'
            . '<fieldset class="mb-3"><legend class="form-label fs-6">Conteúdo dentro deste</legend>'
            . '<div class="form-check"><input class="form-check-input" type="checkbox" id="campo-aceita" name="aceita_filhos" value="1"' . ($aceita ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="campo-aceita">Pode ter conteúdo dentro (filhos)</label></div>';
        $opcoes = '';
        $nomes = array_map(static fn (Classe $c): string => $c->nome, $todas);
        if ($propria === null || !in_array($propria, $nomes, true)) {
            $nomes[] = $propria ?? '';
        }
        foreach (array_values(array_filter($nomes, static fn (string $n): bool => $n !== '')) as $i => $nome) {
            $opcoes .= '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="filha-' . $i . '" name="filhas[]" value="' . Pagina::h($nome) . '"' . (in_array($nome, $filhas, true) ? ' checked' : '') . '>'
                . '<label class="form-check-label" for="filha-' . $i . '"><code>' . Pagina::h($nome) . '</code>' . ($nome === $propria ? ' (o próprio)' : '') . '</label></div>';
        }
        if ($opcoes !== '') {
            $html .= '<p class="form-text mb-1 mt-2">Que tipos podem ficar dentro. Nenhum marcado: qualquer tipo. Ignorado se não pode ter filhos.</p>' . $opcoes;
        }

        return $html . '<p class="form-text">A regra de filhos fica guardada no tipo; a conferência ao criar ou mover conteúdo vem com as telas de conteúdo.</p></fieldset>';
    }

    // ---------------------------------------------------------------- apagar

    private function telaDeApagar(SessaoAtiva $sessao, Classe $classe): Response
    {
        $bloqueio = $this->bloqueioDeApagar($classe);
        if ($bloqueio !== null) {
            return Pagina::resposta('Apagar: ' . $classe->nome, $bloqueio . '<p><a href="' . $this->linkLista() . '">Voltar aos tipos de conteúdo</a></p>', Response::HTTP_OK, false, $this->barra($sessao));
        }
        $corpo = '<div class="alert alert-warning" role="alert">Nenhum conteúdo usa o tipo <code>' . Pagina::h($classe->nome) . '</code>. Apagar não tem volta: '
            . 'moldes da pele com esse nome deixam de ser usados.</div>'
            . '<form method="post" action="' . $this->link($classe->id) . '/apagar">' . $this->campoCsrf($sessao)
            . '<input type="hidden" name="versao" value="' . $classe->versao . '">' . $this->campoSenha($sessao)
            . '<button type="submit" class="btn btn-danger">Apagar o tipo ' . Pagina::h($classe->nome) . '</button> '
            . '<a class="btn btn-outline-secondary" href="' . $this->linkLista() . '">Cancelar</a></form>';

        return Pagina::resposta('Apagar: ' . $classe->nome, $corpo, Response::HTTP_OK, false, $this->barra($sessao));
    }

    /** Explicação (HTML) de por que a classe não pode ser apagada agora, ou null. */
    private function bloqueioDeApagar(Classe $classe): ?string
    {
        $n = $this->servicos->classes->contarObjetos($classe->id);
        if ($n > 0) {
            return '<div class="alert alert-info" role="status">Este tipo não pode ser apagado: ' . $n . ' conteúdo' . ($n > 1 ? 's usam' : ' usa') . ' o tipo <code>' . Pagina::h($classe->nome) . '</code> '
                . '(contando os que estão na lixeira, com estado "apagado"). Só um tipo sem nenhum conteúdo pode ser apagado, para nada se perder.</div>';
        }
        $dependentes = $this->servicos->classes->dependentes($classe);
        if ($dependentes !== []) {
            return '<div class="alert alert-info" role="status">Este tipo não pode ser apagado: os tipos '
                . implode(', ', array_map(static fn (string $d): string => '<code>' . Pagina::h($d) . '</code>', $dependentes))
                . ' dependem dele (como tipo aceito dentro deles ou como alvo de uma referência). Tire essa ligação neles primeiro.</div>';
        }

        return null;
    }

    private function apagar(SessaoAtiva $sessao, Classe $classe, Request $requisicao): Response
    {
        $versao = $this->numero($requisicao->request->all()['versao'] ?? null);
        if ($versao === null || $versao < 1) {
            return $this->incompleto();
        }
        $falha = $this->exigirReautenticacao($sessao, $requisicao, 'classe');
        if ($falha !== null) {
            return $this->aplicarFalhaDeReautenticacao(Pagina::resposta('Apagar: ' . $classe->nome, '<div class="alert alert-danger" role="alert">' . Pagina::h($falha['mensagem']) . '</div>', Response::HTTP_OK, false, $this->barra($sessao)), $falha);
        }
        $bloqueio = $this->bloqueioDeApagar($classe);
        if ($bloqueio !== null) {
            return Pagina::resposta('Apagar: ' . $classe->nome, $bloqueio, Response::HTTP_CONFLICT, false, $this->barra($sessao));
        }
        try {
            $this->servicos->classes->apagar($classe->id, $versao);
        } catch (ConflitoDeVersao) {
            return Pagina::resposta('Apagar: ' . $classe->nome, '<div class="alert alert-warning" role="alert">Outra pessoa alterou este tipo depois que você abriu a página. Nada foi apagado; confira de novo.</div>'
                . '<p><a href="' . $this->link($classe->id) . '/apagar">Conferir de novo</a></p>', Response::HTTP_CONFLICT, false, $this->barra($sessao));
        } catch (\InvalidArgumentException $e) {
            return Pagina::resposta('Apagar: ' . $classe->nome, '<div class="alert alert-info" role="status">' . Pagina::h($this->mensagemDoRepositorio($e)) . '</div>', Response::HTTP_CONFLICT, false, $this->barra($sessao));
        }
        $u = $sessao->usuario;
        $this->servicos->auditoria->registrar('classe.apagar', 'usuario:' . $u->id, $u->id, null, ['classe_id' => $classe->id, 'classe' => $classe->nome]);

        return new RedirectResponse($this->linkLista() . '?ok=apagada', Response::HTTP_SEE_OTHER);
    }

    // ---------------------------------------------------------------- utilidades

    private function postConfere(SessaoAtiva $sessao, Request $requisicao): bool
    {
        $token = $requisicao->request->all()['_csrf'] ?? null;

        return $this->rede->origemConfere($requisicao) && is_string($token) && $this->csrf->conferirSessao($sessao->idHash, $token);
    }

    private function avisoDe(Request $requisicao): string
    {
        return match ($requisicao->query->all()['ok'] ?? null) {
            'criada' => '<div class="alert alert-success" role="status">Tipo de conteúdo criado. Agora adicione os campos dele.</div>',
            'salva' => '<div class="alert alert-success" role="status">Alterações salvas.</div>',
            'nada' => '<div class="alert alert-secondary" role="status">Nada mudou.</div>',
            default => '',
        };
    }

    private function erro(?string $erro): string
    {
        return $erro === null ? '' : '<div id="erro-formulario" class="alert alert-danger" role="alert">' . Pagina::h($erro) . '</div>';
    }

    private function erroDeTexto(string $texto, int $max, bool $multilinha, string $campo): ?string
    {
        if (!mb_check_encoding($texto, 'UTF-8')) {
            return $campo . ' tem caracteres inválidos.';
        }
        if (mb_strlen($texto) > $max) {
            return $campo . ' pode ter até ' . $max . ' caracteres.';
        }
        // Bytes de controle (abaixo de 0x20 e 0x7F) nunca aparecem dentro de um caractere UTF-8 de vários bytes.
        if (preg_match($multilinha ? '/[\x00-\x08\x0B-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $texto) === 1) {
            return $campo . ' tem caracteres de controle, que não são permitidos.';
        }

        return null;
    }

    private function normalizarDescricao(string $texto): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $texto));
    }

    /**
     * Lista de textos de um grupo de caixas (`filhas[]`), com teto. null = não é lista de textos.
     *
     * @return list<string>|null
     */
    private function listaDeTextos(mixed $valor): ?array
    {
        if (!is_array($valor) || count($valor) > 500) {
            return null;
        }
        $saida = [];
        foreach ($valor as $v) {
            if (!is_string($v)) {
                return null;
            }
            $saida[$v] = true;
        }

        return array_map('strval', array_keys($saida));
    }

    /** As mensagens do repositório já são em português e não trazem dado de conteúdo; só ganham ponto final. */
    private function mensagemDoRepositorio(\InvalidArgumentException $e): string
    {
        return rtrim($e->getMessage(), '.') . '.';
    }

    private function incompleto(): Response
    {
        return Pagina::resposta('Pedido inválido', '<p>O formulário chegou incompleto. Volte, recarregue a página e tente de novo.</p>', Response::HTTP_BAD_REQUEST);
    }

    private function semPermissao(SessaoAtiva $sessao): Response
    {
        return Pagina::resposta('Sem acesso', '<div class="alert alert-warning" role="alert">Só administradores podem criar, alterar ou apagar tipos de conteúdo.</div>'
            . '<p><a href="' . $this->linkLista() . '">Ver a lista de tipos de conteúdo</a></p>', Response::HTTP_FORBIDDEN, false, $this->barra($sessao));
    }

    private function campoCsrf(SessaoAtiva $sessao): string
    {
        return '<input type="hidden" name="_csrf" value="' . Pagina::h($this->csrf->daSessao($sessao->idHash)) . '">';
    }

    private function barra(SessaoAtiva $sessao): string
    {
        return Pagina::barra($sessao->usuario->nome, $this->csrf->daSessao($sessao->idHash), $sessao->usuario->administrador);
    }

    private function link(int $id): string
    {
        return Painel::PREFIXO . '/classe/' . $id;
    }

    private function linkLista(): string
    {
        return Painel::PREFIXO . '/classes';
    }

    /** Inteiro não negativo vindo da requisição (só dígitos, até 9), ou null. */
    private function numero(mixed $valor): ?int
    {
        return is_string($valor) && $valor !== '' && strlen($valor) <= 9 && ctype_digit($valor) ? (int) $valor : null;
    }
}
