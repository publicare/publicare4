<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Arquivos\TiposDeArquivo;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\ClassesPadrao;
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
 * Campos `arquivo`/`imagem` ganham `tipos` (subconjunto da whitelist global, ADR 0014) e `tamanho_max_mb` (até
 * `TiposDeArquivo::TETO_MB`); a validação de verdade é sempre a do `ClasseRepositorio` — esta tela só monta os campos e
 * mostra o erro dele se a combinação não bater (extensão de fora da categoria, tamanho fora da faixa).
 *
 * @phpstan-type LinhaDeCampo array{nome: string, rotulo: string, tipo: string, classe: string, obrigatorio: bool, ordem: string, situacao: string, tipos: list<string>, tamanho_max_mb: string}
 * @phpstan-type CampoNovo array{nome: string, rotulo: string, tipo: string, classe: string, indexavel: bool, obrigatorio: bool, ordem: string, tipos: list<string>, tamanho_max_mb: string}
 * @phpstan-type Rascunho array{descricao: string, aceita_filhos: bool, filhas: list<string>, campos: list<LinhaDeCampo>, novo: CampoNovo}
 * @phpstan-import-type Propriedade from Classe
 */
final class TelasDeClasses
{
    use AuxiliaresDeTela;

    private const DESCRICAO_LISTA = 'Um tipo de conteúdo diz quais campos um conteúdo tem, como "Notícia" com data e autor. Os tipos valem para todos os sites desta instalação.';
    public const SITUACOES = ['ativa' => 'Ativo', 'arquivada' => 'Arquivado', 'remover' => 'Remover'];
    public const NOMES_DOS_TIPOS = [
        'texto' => 'Texto curto', 'numero' => 'Número', 'html' => 'Texto formatado (HTML)', 'data' => 'Data',
        'booleano' => 'Sim ou não', 'ref' => 'Referência a outro conteúdo',
        'imagem' => 'Imagem (jpg, png, gif, webp)', 'arquivo' => 'Arquivo (pdf, texto, planilha, documento...)',
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
        if ($modela) {
            $corpo .= '<p><a class="btn btn-primary" href="' . Painel::PREFIXO . '/classes/nova">Novo tipo de conteúdo</a> '
                . '<a class="btn btn-outline-secondary" href="' . Painel::PREFIXO . '/modelos">Modelos</a></p>';
        } else {
            $corpo .= '<p class="text-body-secondary">Só administradores criam e alteram tipos de conteúdo.</p>';
        }
        if ($classes === []) {
            return Pagina::resposta('Tipos de conteúdo', $corpo . '<p>Nenhum tipo de conteúdo ainda.</p>', Response::HTTP_OK, false, $this->barra($sessao), [], self::DESCRICAO_LISTA);
        }
        $corpo .= '<div class="table-responsive"><table class="table table-sm table-hover align-middle"><caption class="visually-hidden">Tipos de conteúdo</caption><thead><tr>'
            . '<th scope="col">Nome</th><th scope="col">Descrição</th><th scope="col">Campos</th>'
            . ($modela ? '<th scope="col">Conteúdos que usam</th><th scope="col"><span class="visually-hidden">Ações</span></th>' : '') . '</tr></thead><tbody>';
        foreach ($classes as $c) {
            $arquivados = count(array_filter($c->propriedades, static fn (array $p): bool => $p['arquivada'] ?? false));
            $ativos = count($c->propriedades) - $arquivados;
            $corpo .= '<tr><td><code>' . Pagina::h($c->nome) . '</code>'
                . ($c->fixa ? ' <span class="badge text-bg-dark" title="Tipo essencial: não se apaga e mantém os campos de base">fixa</span>' : '') . '</td>'
                . '<td>' . ($c->descricao === '' ? '<span class="text-body-secondary">—</span>' : Pagina::h(mb_strimwidth($c->descricao, 0, 160, '…'))) . '</td>'
                . '<td>' . $ativos . ($arquivados > 0 ? ' <span class="text-body-secondary">(+' . $arquivados . ' arquivado' . ($arquivados > 1 ? 's' : '') . ')</span>' : '') . '</td>';
            if ($modela) {
                $corpo .= '<td>' . ($usos[$c->id] ?? 0) . '</td><td class="text-end text-nowrap">'
                    . '<a class="btn btn-sm btn-outline-primary" href="' . $this->link($c->id) . '/editar">Editar<span class="visually-hidden"> ' . Pagina::h($c->nome) . '</span></a> '
                    . ($c->fixa ? '' : '<a class="btn btn-sm btn-outline-danger" href="' . $this->link($c->id) . '/apagar">Apagar<span class="visually-hidden"> ' . Pagina::h($c->nome) . '</span></a>') . '</td>';
            }
            $corpo .= '</tr>';
        }
        $corpo .= '</tbody></table></div>';

        return Pagina::resposta('Tipos de conteúdo', $corpo, Response::HTTP_OK, false, $this->barra($sessao), [], self::DESCRICAO_LISTA);
    }

    // ---------------------------------------------------------------- criar

    /** @param list<string> $filhas */
    private function formularioNovo(SessaoAtiva $sessao, string $nome, string $descricao, bool $aceita, array $filhas, ?string $erro = null, int $status = Response::HTTP_OK): Response
    {
        $corpo = $this->erro($erro)
            . '<form method="post" action="' . Painel::PREFIXO . '/classes/nova"' . ($erro === null ? '' : ' aria-describedby="erro-formulario"') . '>'
            . $this->campoCsrf($sessao)
            . $this->card('Identificação', $this->campo('campo-nome', 'nome', 'Nome técnico', $nome, [
                'maxlength' => ClasseRepositorio::MAX_NOME, 'obrigatorio' => true,
                'ajuda' => 'Letras minúsculas sem acento, números e <code>_</code>, começando por letra (por exemplo <code>noticia</code>). É o nome que os moldes usam e não muda depois.',
            ]) . $this->camposDaClasse($descricao, $aceita, $filhas, $this->servicos->classes->todas(), null))
            . '<p class="form-text">Os campos (propriedades) são adicionados depois de criar o tipo.</p>'
            . $this->acoes('Criar tipo de conteúdo', [['Cancelar', $this->linkLista()]]) . '</form>';

        return Pagina::resposta('Novo tipo de conteúdo', $corpo, $status, false, $this->barra($sessao), [['Tipos de conteúdo', $this->linkLista()], ['Novo', null]], 'Dê um nome ao tipo; os campos entram na tela seguinte.');
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
                'tipos' => $p['tipos'] ?? [], 'tamanho_max_mb' => isset($p['tamanho_max_mb']) ? (string) $p['tamanho_max_mb'] : '',
            ];
        }

        return [
            'descricao' => $classe->descricao, 'aceita_filhos' => $classe->aceitaFilhos, 'filhas' => $classe->filhas, 'campos' => $campos,
            'novo' => ['nome' => '', 'rotulo' => '', 'tipo' => 'texto', 'classe' => '', 'indexavel' => false, 'obrigatorio' => false, 'ordem' => '', 'tipos' => [], 'tamanho_max_mb' => ''],
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
            $tipos = $this->listaDeTextos($e['tipos'] ?? []);
            if ($tipos === null) {
                return null;
            }
            $linha['tipos'] = $tipos;
            $linha['tamanho_max_mb'] = is_string($e['tamanho_max_mb'] ?? null) ? trim($e['tamanho_max_mb']) : '';
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
        $tiposNovo = $this->listaDeTextos($novo['tipos'] ?? []);
        if ($tiposNovo === null) {
            return null;
        }
        $linhaNova['tipos'] = $tiposNovo;
        $linhaNova['tamanho_max_mb'] = is_string($novo['tamanho_max_mb'] ?? null) ? trim($novo['tamanho_max_mb']) : '';

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
                ?? $this->erroDeTipo($c['tipo'], $c['classe'], $classe, $c['nome'])
                ?? $this->erroDeArquivo($c['tipo'], $c['tipos'], $c['tamanho_max_mb'], $c['nome']);
            if ($erro !== null) {
                return $erro;
            }
            if (!isset(self::SITUACOES[$c['situacao']])) {
                return $rotulo . ' tem uma situação inválida.';
            }
            if ($classe->fixa && in_array($c['nome'], ClassesPadrao::nomesDaBase($classe->nome), true) && $c['situacao'] !== 'ativa') {
                return $rotulo . ' faz parte da base do tipo fixo ' . $classe->nome . ' e não pode ser ' . ($c['situacao'] === 'remover' ? 'removido' : 'arquivado') . '.';
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
            ?? $this->erroDeTipo($n['tipo'], $n['classe'], $classe, $n['nome'])
            ?? $this->erroDeArquivo($n['tipo'], $n['tipos'], $n['tamanho_max_mb'], $n['nome']);
    }

    /**
     * `tipos`/`tamanho_max_mb` só valem em `arquivo`/`imagem` (ignorados nos outros tipos). Confere de novo o que o
     * `ClasseRepositorio::normalizarRestricoesDeArquivo` também confere, para o erro aparecer ligado ao campo certo.
     *
     * @param list<string> $tipos
     */
    private function erroDeArquivo(string $tipo, array $tipos, string $tamanhoMaxMb, string $campo): ?string
    {
        if (!in_array($tipo, ['arquivo', 'imagem'], true)) {
            return null;
        }
        $base = $tipo === 'imagem' ? TiposDeArquivo::imagens() : TiposDeArquivo::documentos();
        foreach ($tipos as $extensao) {
            if (!in_array($extensao, $base, true)) {
                return 'O campo ' . $campo . ' aceita só tipos de ' . ($tipo === 'imagem' ? 'imagem' : 'arquivo') . '; ' . $extensao . ' não é um deles.';
            }
        }
        if ($tamanhoMaxMb !== '' && (!ctype_digit($tamanhoMaxMb) || (int) $tamanhoMaxMb < 1 || (int) $tamanhoMaxMb > TiposDeArquivo::TETO_MB)) {
            return 'O tamanho máximo do campo ' . $campo . ' deve ser um número de 1 a ' . TiposDeArquivo::TETO_MB . ' (MB).';
        }

        return null;
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
     * @return list<array{nome: string, tipo: string, indexavel: bool, classe?: string, rotulo?: string, obrigatorio?: bool, arquivada?: bool, tipos?: list<string>, tamanho_max_mb?: int}>
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
            $itens[] = [(int) $c['ordem'], $i, $this->comApresentacao($p, $c['rotulo'], $c['obrigatorio'], $c['situacao'] === 'arquivada', $c['tipos'], $c['tamanho_max_mb'])];
        }
        $n = $r['novo'];
        if ($n['nome'] !== '') {
            $p = ['nome' => $n['nome'], 'tipo' => $n['tipo'], 'indexavel' => $n['indexavel']];
            if ($n['tipo'] === 'ref' && $n['classe'] !== '') {
                $p['classe'] = $n['classe'];
            }
            $ordem = $n['ordem'] === '' ? PHP_INT_MAX : (int) $n['ordem'];
            $itens[] = [$ordem, PHP_INT_MAX, $this->comApresentacao($p, $n['rotulo'], $n['obrigatorio'], false, $n['tipos'], $n['tamanho_max_mb'])];
        }
        usort($itens, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $item): array => $item[2], $itens);
    }

    /**
     * `$tipos`/`$tamanhoMaxMb` só pesam em propriedades `arquivo`/`imagem` (o `ClasseRepositorio` ignora nas demais, e
     * confere de novo a combinação: extensão fora da categoria ou tamanho fora da faixa vira erro no `salvar()`).
     *
     * @param array{nome: string, tipo: string, indexavel: bool, classe?: string} $p
     * @param list<string> $tipos
     * @return array{nome: string, tipo: string, indexavel: bool, classe?: string, rotulo?: string, obrigatorio?: bool, arquivada?: bool, tipos?: list<string>, tamanho_max_mb?: int}
     */
    private function comApresentacao(array $p, string $rotulo, bool $obrigatorio, bool $arquivada, array $tipos = [], string $tamanhoMaxMb = ''): array
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
        if ($tipos !== []) {
            $p['tipos'] = $tipos;
        }
        if ($tamanhoMaxMb !== '' && ctype_digit($tamanhoMaxMb)) {
            $p['tamanho_max_mb'] = (int) $tamanhoMaxMb;
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
     * @param list<array{nome: string, tipo: string, indexavel: bool, classe?: string, rotulo?: string, obrigatorio?: bool, arquivada?: bool, tipos?: list<string>, tamanho_max_mb?: int}> $novas
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
            . '<div class="pbl-acoes"><button type="submit" class="btn btn-warning" name="confirmar" value="1">Confirmar e salvar</button>'
            . '<button type="submit" class="btn btn-outline-secondary" name="revisar" value="1">Voltar ao formulário</button></div></form>';

        return Pagina::resposta('Confirmar: ' . $classe->nome, $corpo, Response::HTTP_OK, false, $this->barra($sessao), [['Tipos de conteúdo', $this->linkLista()], [$classe->nome, $this->link($classe->id) . '/editar'], ['Confirmar', null]]);
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
            foreach (['rotulo', 'tipo', 'classe', 'ordem', 'situacao', 'tamanho_max_mb'] as $chave) {
                $saida .= $h($base . '[' . $chave . ']', $c[$chave]);
            }
            $saida .= $c['obrigatorio'] ? $h($base . '[obrigatorio]', '1') : '';
            foreach ($c['tipos'] as $extensao) {
                $saida .= $h($base . '[tipos][]', $extensao);
            }
        }
        foreach (['nome', 'rotulo', 'tipo', 'classe', 'ordem', 'tamanho_max_mb'] as $chave) {
            $saida .= $h('novo[' . $chave . ']', $r['novo'][$chave]);
        }
        $saida .= ($r['novo']['indexavel'] ? $h('novo[indexavel]', '1') : '') . ($r['novo']['obrigatorio'] ? $h('novo[obrigatorio]', '1') : '');
        foreach ($r['novo']['tipos'] as $extensao) {
            $saida .= $h('novo[tipos][]', $extensao);
        }

        return $saida;
    }

    /** @param Rascunho $r */
    private function formularioDeEdicao(SessaoAtiva $sessao, Classe $classe, array $r, ?string $erro = null, int $status = Response::HTTP_OK, string $aviso = ''): Response
    {
        $todas = $this->servicos->classes->todas();
        $uso = $this->servicos->classes->usoDasPropriedades($classe);
        $total = $this->servicos->classes->contarObjetos($classe->id);
        $corpo = $aviso . $this->erro($erro)
            . ($classe->fixa ? $this->alerta('info', 'Este é um tipo <strong>fixo</strong> (essencial ao sistema): não pode ser apagado nem renomeado, e os campos de base dele não podem ser removidos nem arquivados. Acrescentar campos é permitido.', '', true) : '')
            . '<form method="post" action="' . $this->link($classe->id) . '/editar"' . ($erro === null ? '' : ' aria-describedby="erro-formulario"') . '>'
            . $this->campoCsrf($sessao) . '<input type="hidden" name="versao" value="' . $classe->versao . '">'
            . $this->card('Sobre o tipo', $this->camposDaClasse($r['descricao'], $r['aceita_filhos'], $r['filhas'], $todas, $classe->nome))
            . '<h2 class="h5 mt-4">Campos deste tipo</h2>';

        if ($r['campos'] === []) {
            $corpo .= '<p class="text-body-secondary">Este tipo ainda não tem campos.</p>';
        } else {
            $corpo .= '<p class="form-text">Mude a ordem trocando os números (menor primeiro). Campo com valores guardados não troca de tipo e não pode ser removido, só arquivado.</p>';
            $paineis = '';
            foreach ($r['campos'] as $i => $c) {
                $p = $this->definicao($classe, $c['nome']);
                $objetos = $uso[$c['nome']]['objetos'] ?? 0;
                $historico = $uso[$c['nome']]['historico'] ?? 0;
                $emUso = max($objetos, $historico);
                $base = 'p[' . $c['nome'] . ']';
                $id = 'campo-' . $i . '-' . (preg_replace('/[^A-Za-z0-9_-]/', '-', $c['nome']) ?? '');
                $selos = (($p['indexavel'] ?? false) ? ' <span class="badge text-bg-light border">indexável</span>' : '')
                    . ($c['obrigatorio'] ? ' <span class="badge text-bg-light border">obrigatório</span>' : '')
                    . ($c['situacao'] === 'arquivada' ? ' <span class="badge text-bg-secondary">arquivado</span>' : '');
                $situacoes = [];
                foreach (self::SITUACOES as $valor => $texto) {
                    if ($valor === 'remover' && $emUso > 0) {
                        continue;
                    }
                    if ($valor !== 'ativa' && $classe->fixa && in_array($c['nome'], ClassesPadrao::nomesDaBase($classe->nome), true)) {
                        continue; // campo de base de tipo fixo (ADR 0015)
                    }
                    $situacoes[$valor] = $texto;
                }
                if ($emUso > 0) {
                    $tipo = '<div class="pbl-largo"><span class="form-label d-block">Tipo do campo</span><p class="mb-1">' . Pagina::h($this->descreverTipo($c['tipo'], $c['classe'])) . '</p>'
                        . '<div class="form-text">Não muda porque há conteúdo com valor neste campo.</div>'
                        . '<input type="hidden" name="' . Pagina::h($base) . '[tipo]" value="' . Pagina::h($c['tipo']) . '">'
                        . '<input type="hidden" name="' . Pagina::h($base) . '[classe]" value="' . Pagina::h($c['classe']) . '"></div>';
                } else {
                    $tipo = '<div class="pbl-largo">' . $this->seletorDeTipo($id, $base, $c['tipo'], $c['classe'], $todas) . '</div>';
                }
                $interno = '<div class="pbl-grade">'
                    . $this->campo($id . '-rotulo', $base . '[rotulo]', 'Rótulo', $c['rotulo'], ['maxlength' => ClasseRepositorio::MAX_ROTULO, 'ajuda' => 'O nome que aparece nos formulários.', 'grupo' => 'mb-0'])
                    . $this->campo($id . '-ordem', $base . '[ordem]', 'Ordem', $c['ordem'], ['inputmode' => 'numeric', 'pattern' => '[0-9]{1,4}', 'maxlength' => 4, 'obrigatorio' => true, 'classe' => 'campo-ordem', 'ajuda' => 'Menor número aparece primeiro.', 'grupo' => 'mb-0'])
                    . $tipo
                    . $this->campo($id . '-situacao', $base . '[situacao]', 'Situação', $c['situacao'], ['tipo' => 'select', 'opcoes' => $situacoes, 'grupo' => 'mb-0'])
                    . '<div class="d-flex align-items-end">' . $this->checkbox($id . '-obrigatorio', $base . '[obrigatorio]', 'Obrigatório', $c['obrigatorio']) . '</div>'
                    . '<p class="form-text pbl-largo mb-0">Em uso por ' . $objetos . ' conteúdo' . ($objetos === 1 ? '' : 's')
                    . ($historico > $objetos ? ' (+ versões antigas)' : '') . '.</p>'
                    . '<div class="pbl-largo">' . $this->camposDeArquivo($id, $base, $c['tipos'], $c['tamanho_max_mb'], in_array($c['tipo'], ['arquivo', 'imagem'], true)) . '</div>'
                    . '</div>';
                $resumo = '<code>' . Pagina::h($c['nome']) . '</code>'
                    . ($c['rotulo'] === '' ? '' : ' <span class="ms-2">' . Pagina::h($c['rotulo']) . '</span>')
                    . ' <span class="pbl-resumo-meta ms-2">' . Pagina::h($this->descreverTipo($c['tipo'], $c['classe'])) . ' · ordem ' . Pagina::h($c['ordem']) . '</span>' . $selos;
                $paineis .= Pagina::recolhivel($id, $resumo, $interno, $erro !== null);
            }
            $corpo .= '<div class="accordion mb-3">' . $paineis . '</div>';
        }

        $n = $r['novo'];
        $novo = '<div class="pbl-grade pbl-grade-3">'
            . $this->campo('novo-nome', 'novo[nome]', 'Nome técnico', $n['nome'], ['maxlength' => ClasseRepositorio::MAX_NOME, 'ajuda' => 'Minúsculas, números e <code>_</code>; não muda depois.', 'grupo' => 'mb-0'])
            . $this->campo('novo-rotulo', 'novo[rotulo]', 'Rótulo', $n['rotulo'], ['maxlength' => ClasseRepositorio::MAX_ROTULO, 'grupo' => 'mb-0'])
            . $this->campo('novo-ordem', 'novo[ordem]', 'Ordem', $n['ordem'], ['inputmode' => 'numeric', 'pattern' => '[0-9]{1,4}', 'maxlength' => 4, 'ajuda' => 'Em branco: vai para o fim.', 'grupo' => 'mb-0'])
            . '<div class="pbl-largo">' . $this->seletorDeTipo('novo', 'novo', $n['tipo'], $n['classe'], $todas) . '</div>'
            . '<div class="pbl-largo">' . $this->checkbox('novo-obrigatorio', 'novo[obrigatorio]', 'Obrigatório', $n['obrigatorio'])
            . $this->checkbox('novo-indexavel', 'novo[indexavel]', 'Indexável', $n['indexavel'], 'Permite filtrar e ordenar por este campo nos moldes. Não muda depois que houver valores. Referências são sempre indexáveis.') . '</div>'
            . '<div class="pbl-largo">' . $this->camposDeArquivo('novo', 'novo', $n['tipos'], $n['tamanho_max_mb'], in_array($n['tipo'], ['arquivo', 'imagem'], true), true) . '</div>'
            . '</div>';
        $corpo .= '<div class="accordion mb-3">' . Pagina::recolhivel('novo-campo', '<strong>Adicionar um campo</strong> <span class="pbl-resumo-meta ms-2">(opcional)</span>', $novo, $this->novoPreenchido($n)) . '</div>'
            . '<p class="form-text">"Obrigatório" fica guardado no tipo; a exigência ao salvar um conteúdo vem com as telas de conteúdo.</p>'
            . $this->acoes('Salvar alterações', [['Voltar aos tipos', $this->linkLista()]], $classe->fixa ? null : ['Apagar este tipo de conteúdo', $this->link($classe->id) . '/apagar'])
            . '</form>';

        $descricao = '<code>' . Pagina::h($classe->nome) . '</code> · ' . $total . ' conteúdo' . ($total === 1 ? ' usa' : 's usam') . ' este tipo · versão ' . $classe->versao;

        return Pagina::resposta('Tipo de conteúdo: ' . $classe->nome, $corpo, $status, false, $this->barra($sessao), [['Tipos de conteúdo', $this->linkLista()], [$classe->nome, null]], $descricao);
    }

    /**
     * `tipos` (subconjunto da whitelist, por categoria) e `tamanho_max_mb` (até `TiposDeArquivo::TETO_MB`) de uma propriedade
     * `arquivo`/`imagem` (ADR 0014). Em campo existente os controles só são desenhados se o tipo atual já é Arquivo/Imagem. No
     * formulário de campo novo (`$condicional`) o tipo ainda vai ser escolhido: o bloco é desenhado `hidden` e o `pbl-admin.js` o
     * mostra quando o seletor `novo-tipo` vira arquivo/imagem (`data-pbl-visivel-se`); sem JavaScript ele fica oculto e as opções
     * aparecem ao editar o campo depois de criado. O `ClasseRepositorio` ignora os dois fora de `arquivo`/`imagem`.
     *
     * @param list<string> $tiposSelecionados
     */
    private function camposDeArquivo(string $id, string $base, array $tiposSelecionados, string $tamanhoMaxMb, bool $relevante, bool $condicional = false): string
    {
        if (!$relevante && !$condicional) {
            return '';
        }
        $caixas = '';
        foreach ([TiposDeArquivo::imagens(), TiposDeArquivo::documentos()] as $extensoes) {
            foreach ($extensoes as $extensao) {
                $caixas .= $this->checkbox($id . '-tipo-' . $extensao, $base . '[tipos][]', $extensao, in_array($extensao, $tiposSelecionados, true), '', $extensao);
            }
        }
        $html = '<fieldset><legend>Arquivos permitidos</legend>'
            . '<div class="form-text mb-1">Sem marcar nenhum, vale a categoria inteira do tipo (imagens ou documentos).</div>'
            . '<div class="pbl-extensoes">' . $caixas . '</div></fieldset>'
            . $this->campo($id . '-tamanho-max-mb', $base . '[tamanho_max_mb]', 'Tamanho máximo (MB)', $tamanhoMaxMb, [
                'tipo' => 'number', 'min' => 1, 'max' => TiposDeArquivo::TETO_MB, 'step' => 1, 'classe' => 'pbl-tamanho', 'grupo' => 'mb-0',
                'ajuda' => 'Em branco: ' . TiposDeArquivo::PADRAO_MB . ' MB. Máximo permitido: ' . TiposDeArquivo::TETO_MB . ' MB.',
            ]);

        $gatilho = $condicional ? ' data-pbl-visivel-se="' . Pagina::h($id . '-tipo') . ':arquivo,imagem"' . ($relevante ? '' : ' hidden') : '';

        return '<div class="border-top pt-3"' . $gatilho . '><h3 class="h6">Opções de arquivo e imagem</h3>' . $html . '</div>';
    }

    /** @param array<string, mixed> $n */
    private function novoPreenchido(array $n): bool
    {
        return ($n['nome'] ?? '') !== '' || ($n['rotulo'] ?? '') !== '';
    }

    /** @param list<Classe> $todas */
    private function seletorDeTipo(string $id, string $base, string $tipo, string $alvo, array $todas): string
    {
        $referencias = ['' => 'qualquer tipo (ou não é referência)'];
        foreach ($todas as $c) {
            $referencias[$c->nome] = 'referência: ' . $c->nome;
        }

        return '<div class="pbl-grade">'
            . $this->campo($id . '-tipo', $base . '[tipo]', 'Tipo do campo', $tipo, ['tipo' => 'select', 'opcoes' => self::NOMES_DOS_TIPOS, 'grupo' => 'mb-0'])
            . $this->campo($id . '-classe', $base . '[classe]', 'Se for referência, aponta para', $alvo, ['tipo' => 'select', 'opcoes' => $referencias, 'grupo' => 'mb-0'])
            . '</div>';
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
        $html = $this->campo('campo-descricao', 'descricao', 'Descrição', $descricao, ['tipo' => 'textarea', 'linhas' => 3, 'maxlength' => ClasseRepositorio::MAX_DESCRICAO, 'ajuda' => 'Opcional: para que serve este tipo, em poucas palavras.']);
        $dentro = $this->checkbox('campo-aceita', 'aceita_filhos', 'Pode ter conteúdo dentro (filhos)', $aceita);
        $opcoes = '';
        $nomes = array_map(static fn (Classe $c): string => $c->nome, $todas);
        if ($propria === null || !in_array($propria, $nomes, true)) {
            $nomes[] = $propria ?? '';
        }
        foreach (array_values(array_filter($nomes, static fn (string $n): bool => $n !== '')) as $i => $nome) {
            $opcoes .= $this->checkbox('filha-' . $i, 'filhas[]', '<code>' . Pagina::h($nome) . '</code>' . ($nome === $propria ? ' (o próprio)' : ''), in_array($nome, $filhas, true), '', $nome, true);
        }
        if ($opcoes !== '') {
            $dentro .= '<p class="form-text mb-1 mt-2">Que tipos podem ficar dentro. Nenhum marcado: qualquer tipo. Ignorado se não pode ter filhos.</p><div class="pbl-extensoes">' . $opcoes . '</div>';
        }
        $dentro .= '<p class="form-text">A regra de filhos fica guardada no tipo; a conferência ao criar ou mover conteúdo vem com as telas de conteúdo.</p>';

        return $html . '<div class="accordion mb-3">' . Pagina::recolhivel('filhos', '<strong>Que tipos podem ficar dentro</strong> <span class="pbl-resumo-meta ms-2">(conteúdo dentro deste tipo)</span>', $dentro, $filhas !== []) . '</div>';
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
            . $this->acoes('Apagar o tipo ' . $classe->nome, [['Cancelar', $this->link($classe->id) . '/editar']], null, 'btn-danger') . '</form>';

        return Pagina::resposta('Apagar: ' . $classe->nome, $corpo, Response::HTTP_OK, false, $this->barra($sessao), [['Tipos de conteúdo', $this->linkLista()], [$classe->nome, $this->link($classe->id) . '/editar'], ['Apagar', null]]);
    }

    /** Explicação (HTML) de por que a classe não pode ser apagada agora, ou null. */
    private function bloqueioDeApagar(Classe $classe): ?string
    {
        if ($classe->fixa) {
            return '<div class="alert alert-info" role="status">O tipo <code>' . Pagina::h($classe->nome) . '</code> é fixo (essencial ao sistema) e não pode ser apagado.</div>';
        }
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
