<?php

declare(strict_types=1);

namespace Pbl\Conteudo\Servico;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Pbl\Acesso\Acao;
use Pbl\Acesso\Auditoria;
use Pbl\Acesso\Permissoes;
use Pbl\Acesso\Usuario;
use Pbl\Arquivos\ErroDeUpload;
use Pbl\Arquivos\ServicoDeUpload;
use Pbl\Consulta\Consulta;
use Pbl\Consulta\Filtro;
use Pbl\Consulta\MotorDeConsulta;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\ConflitoDeArvore;
use Pbl\Conteudo\ConflitoDeVersao;
use Pbl\Conteudo\ErroDeRevisao;
use Pbl\Conteudo\FluxoDeRevisao;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Conteudo\Site;
use Pbl\Conteudo\TagRepositorio;
use Pbl\Conteudo\TiposAceitos;
use Pbl\Conteudo\ViolacaoDeRegra;
use Pbl\Publicacao\FilaDeRepublicacao;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Operações de edição de conteúdo sem HTTP e sem sessão (ADR 0030): o ator entra como parâmetro e toda decisão passa por
 * `Permissoes::pode`, pela validação do {@see ValidadorDeCampos}, pelo `ObjetoRepositorio` (versão otimista, histórico de
 * versões, endereços e 301, auditoria com o autor), pelo `FluxoDeRevisao` e pelo cache público ({@see SincronizadorDePaginas}).
 * Sem permissão de **ver**, o conteúdo "não existe" ({@see ConteudoNaoEncontrado}). Nunca apaga definitivamente.
 * Valores de campos nunca vão para auditoria nem para mensagens de erro.
 */
final class ServicoDeConteudo
{
    /** Motivo gravado na versão: fixo, nunca texto digitado. */
    public const MOTIVO = 'linha de comando';
    private const LIXEIRA = 'apagado';
    private const PAGINA_DA_LISTA = 200;
    private const PAGINAS_MAXIMAS = 20;

    private readonly TiposAceitos $tiposAceitos;

    /** @param \Closure(): ServicoDeUpload $uploads */
    public function __construct(
        private readonly ObjetoRepositorio $objetos,
        private readonly ClasseRepositorio $classes,
        private readonly TagRepositorio $tags,
        private readonly Permissoes $permissoes,
        private readonly FluxoDeRevisao $revisao,
        private readonly MotorDeConsulta $motor,
        private readonly Auditoria $auditoria,
        private readonly SincronizadorDePaginas $paginas,
        private readonly ValidadorDeCampos $validador,
        private readonly FilaDeRepublicacao $fila,
        private readonly \Closure $uploads,
    ) {
        $this->tiposAceitos = new TiposAceitos($classes);
    }

    // ---------------------------------------------------------------- leitura

    /** @throws ConteudoNaoEncontrado inexistente ou sem permissão de ver */
    public function obter(Usuario $quem, int $id): Objeto
    {
        $o = $this->objetos->porId($id);
        if ($o === null || !$this->permissoes->pode($quem, Acao::Ver, $o)) {
            throw new ConteudoNaoEncontrado();
        }

        return $o;
    }

    /** Resolve um endereço do site (`/` ou vazio = página inicial) para um conteúdo que a conta pode ver. */
    public function obterPorEndereco(Usuario $quem, Site $site, string $endereco): Objeto
    {
        $endereco = trim($endereco, '/');
        $o = $endereco === '' ? ($site->objetoRaizId === null ? null : $this->objetos->porId($site->objetoRaizId)) : $this->objetos->porUrl($site->id, $endereco);
        if ($o === null || $o->siteId !== $site->id || !$this->permissoes->pode($quem, Acao::Ver, $o)) {
            throw new ConteudoNaoEncontrado();
        }

        return $o;
    }

    /** O que a conta pode fazer neste conteúdo (a mesma regra do painel, inclusive "enviado para revisão"). */
    public function pode(Usuario $quem, Acao $acao, Objeto $o): bool
    {
        // Enquanto o envio espera revisão, quem não revisa não edita (retira o envio para mexer): o revisor aprova o que viu.
        if ($acao === Acao::Editar && $o->status === 'submetido' && !$this->permissoes->pode($quem, Acao::Publicar, $o)) {
            return false;
        }

        return $this->permissoes->pode($quem, $acao, $o);
    }

    public function podeCriarSob(Usuario $quem, Objeto $pai): bool
    {
        return $this->permissoes->podeCriarSob($quem, $pai);
    }

    /**
     * Conteúdo do site que a conta pode ver, com os filtros pedidos. `$estado` null = tudo menos a lixeira.
     *
     * @return array{itens: list<Objeto>, ha_mais: bool}
     */
    public function listar(Usuario $quem, Site $site, ?Objeto $pai, bool $arvore, ?string $estado, ?string $tipo, ?string $busca, int $limite): array
    {
        $limite = max(1, $limite);
        $classeIds = null;
        if ($tipo !== null) {
            $classe = $this->classes->porNome($tipo);
            $classeIds = $classe === null ? [] : [$classe->id];
        }
        $filtro = $estado === null ? new Filtro('status', '!=', self::LIXEIRA) : new Filtro('status', '=', $estado);
        $itens = [];
        for ($pagina = 0; $pagina < self::PAGINAS_MAXIMAS; $pagina++) {
            $lote = $this->motor->buscar(new Consulta(
                $site->id, $pai?->id, $pai === null || $arvore ? null : 1, $classeIds, [$filtro], 'peso, titulo',
                $pagina * self::PAGINA_DA_LISTA, self::PAGINA_DA_LISTA, false, $busca === null || $busca === '' ? null : $busca,
            ));
            foreach ($lote as $o) {
                if ($this->permissoes->pode($quem, Acao::Ver, $o)) {
                    $itens[] = $o;
                    if (count($itens) > $limite) {
                        return ['itens' => array_slice($itens, 0, $limite), 'ha_mais' => true];
                    }
                }
            }
            if (count($lote) < self::PAGINA_DA_LISTA) {
                break;
            }
        }

        return ['itens' => $itens, 'ha_mais' => false];
    }

    /**
     * Filhos diretos que a conta pode ver (todos os estados), no limite pedido.
     *
     * @return array{itens: list<Objeto>, total: int}
     */
    public function filhos(Usuario $quem, Objeto $pai, int $limite): array
    {
        $todos = $this->motor->buscar(new Consulta($pai->siteId, $pai->id, 1, null, [new Filtro('status', '!=', self::LIXEIRA)], 'peso, titulo', 0, 500, false));
        $visiveis = array_values(array_filter($todos, fn (Objeto $f): bool => $this->permissoes->pode($quem, Acao::Ver, $f)));

        return ['itens' => array_slice($visiveis, 0, max(0, $limite)), 'total' => count($visiveis)];
    }

    /** @return list<array{versao: int, titulo: string, status: string, motivo: string, criado_em: string, usuario_id: int|null}> */
    public function versoes(Usuario $quem, int $id, int $limite = 200): array
    {
        $this->obter($quem, $id);

        return array_slice($this->objetos->versoes($id), 0, max(1, $limite));
    }

    // ---------------------------------------------------------------- gravação

    /**
     * Edita título, descrição, endereço, peso, menu, tags e campos do tipo. Campo ausente mantém o valor; valor vazio remove.
     * `$publicar` grava e publica numa só operação (só quem pode publicar; quem não pode recebe {@see AcessoNegado} e NADA é gravado).
     * `$enviarRevisao` grava e envia para revisão (fluxo do autor).
     *
     * @param array<string, mixed> $campos
     */
    public function editar(Usuario $quem, int $id, int $versao, array $campos, bool $publicar = false, bool $enviarRevisao = false): ResultadoDeConteudo
    {
        $o = $this->obter($quem, $id);
        $this->exigir($quem, Acao::Editar, $o, 'objeto.editar.negado');
        $this->exigirForaDaLixeira($o);
        if ($publicar && $enviarRevisao) {
            throw new EntradaInvalida(['_' => 'Use --publicar ou --enviar-revisao, não os dois.']);
        }
        if ($publicar) {
            $this->exigir($quem, Acao::Publicar, $o, 'objeto.publicar.negado', 'Esta conta não pode publicar; use --enviar-revisao. Nada foi gravado.');
        }
        if ($enviarRevisao && !in_array($o->status, ['privado', 'rejeitado'], true)) {
            throw new EstadoInvalido('Só conteúdo privado ou devolvido pode ser enviado para revisão.');
        }
        $this->conferirVersao($o, $versao);
        $classe = $this->classes->porId($o->classeId) ?? throw new EstadoInvalido('O tipo deste conteúdo não existe mais.');
        [$valores, $erros] = $this->validador->ler($quem, $o->siteId, $classe, $campos, $o);
        if ($erros !== []) {
            throw new EntradaInvalida($erros);
        }

        $mudancas = [];
        foreach (['titulo' => $o->titulo, 'descricao' => $o->descricao, 'url_amigavel' => $o->urlAmigavel, 'peso' => $o->peso, 'no_menu' => $o->noMenu, 'rotulo_menu' => $o->rotuloMenu] as $campo => $atual) {
            if (array_key_exists($campo, $valores) && $valores[$campo] !== $atual) {
                $mudancas[$campo] = $valores[$campo];
            }
        }
        if (isset($valores['tags']) && $this->slugsDe($valores['tags']) !== $this->slugsDe($this->tags->nomesDoObjeto($o->id))) {
            $mudancas['tags'] = $valores['tags'];
        }
        $dados = [];
        foreach ($valores['dados'] as $nome => $novo) {
            $atual = $o->dados[$nome] ?? null;
            if ($novo === null ? $atual !== null : ($atual === null || (string) $atual !== (string) $novo)) {
                $dados[$nome] = $novo;
            }
        }
        if ($dados !== []) {
            $mudancas['dados'] = $dados;
        }
        // De "enviado para revisão" a publicado passa pela aprovação (fica no histórico de revisão); dos demais, direto.
        $aprovar = $publicar && $o->status === 'submetido';
        if ($publicar && $o->status !== 'publicado' && !$aprovar) {
            $mudancas['status'] = 'publicado';
        }

        $novo = $o;
        try {
            if ($mudancas !== []) {
                $novo = $this->objetos->atualizar($o->id, $mudancas, self::MOTIVO, $versao, false, $quem->id);
            }
            if ($aprovar) {
                $novo = $this->doRevisao(fn (): Objeto => $this->revisao->aprovar($quem, $o->id, $novo->versao), $o);
            } elseif ($enviarRevisao) {
                $novo = $this->doRevisao(fn (): Objeto => $this->revisao->enviar($quem, $o->id, $novo->versao), $o);
            }
        } catch (ViolacaoDeRegra $e) {
            throw EntradaInvalida::daRegra($e);
        } catch (\InvalidArgumentException $e) {
            throw new EntradaInvalida(['_' => 'Não foi possível salvar: algum valor não foi aceito. Confira os campos e tente de novo.'], $e);
        }
        if ($novo === $o) {
            return new ResultadoDeConteudo($o, false);
        }

        $ok = true;
        if ($novo->urlAmigavel !== $o->urlAmigavel) {
            $ok = $this->paginas->tirarDoAr($o); // o endereço antigo passa a redirecionar (histórico de URLs)
        }
        $ok = $this->paginas->sincronizar($novo, $this->pai($novo)) && $ok;
        if (isset($mudancas['tags']) || $this->paginas->visibilidadeMudou($o, $novo)) {
            $ok = $this->paginas->republicarPorTags($o, $novo) && $ok;
        }

        return new ResultadoDeConteudo($novo, true, $ok, $o);
    }

    /**
     * Cria conteúdo (começa privado) dentro de `$paiId`, do tipo `$tipo` (nome da classe).
     *
     * @param array<string, mixed> $campos
     */
    public function criar(Usuario $quem, int $paiId, string $tipo, array $campos): ResultadoDeConteudo
    {
        $pai = $this->obter($quem, $paiId);
        if (!$this->permissoes->podeCriarSob($quem, $pai)) {
            $this->auditoria->registrar('objeto.criar.negado', 'usuario:' . $quem->id, $quem->id, $pai->id);
            throw new AcessoNegado(Acao::Criar);
        }
        if ($pai->status === self::LIXEIRA) {
            throw new EstadoInvalido('O conteúdo onde seria criado está na lixeira.', EstadoInvalido::ARVORE);
        }
        $classe = $this->classes->porNome($tipo);
        if ($classe === null) {
            throw new EntradaInvalida(['tipo' => 'Tipo de conteúdo desconhecido.']);
        }
        if (!$this->tiposAceitos->aceita($pai, $classe)) {
            throw new EntradaInvalida(['tipo' => 'O tipo de ' . $pai->titulo . ' não aceita conteúdo do tipo ' . $classe->nome . ' dentro dele.']);
        }
        [$valores, $erros] = $this->validador->ler($quem, $pai->siteId, $classe, $campos, null);
        $url = $valores['url_amigavel'] ?? '';
        if ($erros === [] && $url === '') {
            $url = $this->validador->urlLivre($pai->siteId, $valores['titulo']) ?? '';
            if ($url === '') {
                $erros['url'] = 'Não foi possível criar um endereço a partir do título. Informe o campo url.';
            }
        }
        if ($erros !== []) {
            throw new EntradaInvalida($erros);
        }
        $dados = array_filter($valores['dados'], static fn (string|int|float|null $v): bool => $v !== null);
        try {
            $novo = $this->objetos->criarDentro($pai->siteId, $classe, $pai->id, $valores['titulo'], $url, $dados, 'privado', [
                'peso' => $valores['peso'], 'descricao' => $valores['descricao'], 'no_menu' => $valores['no_menu'], 'rotulo_menu' => $valores['rotulo_menu'], 'criado_por' => $quem->id,
                'tags' => $valores['tags'] ?? null,
            ]);
        } catch (ConflitoDeArvore $e) {
            throw new EstadoInvalido('O conteúdo onde seria criado está na lixeira.', EstadoInvalido::ARVORE);
        } catch (ViolacaoDeRegra $e) {
            throw EntradaInvalida::daRegra($e);
        } catch (\InvalidArgumentException | UniqueConstraintViolationException $e) {
            throw new EntradaInvalida(['_' => 'Não foi possível criar: algum valor não foi aceito (o endereço pode ter acabado de ser usado).'], $e);
        }
        // Defesa em profundidade: se, apesar da trava, o pai foi parar na lixeira, o filho novo (ainda privado) vai junto para ela.
        if ($this->objetos->porId($pai->id)?->status === self::LIXEIRA) {
            $this->desfazer(fn () => $this->objetos->atualizar($novo->id, ['status' => self::LIXEIRA], self::MOTIVO . ': desfeito', null, false, $quem->id));
            throw new EstadoInvalido('O conteúdo onde seria criado foi para a lixeira. Nada foi criado.', EstadoInvalido::ARVORE);
        }

        return new ResultadoDeConteudo($novo, true);
    }

    /** Publica (ou aprova, se estava enviado para revisão). Já publicado: nada a alterar. */
    public function publicar(Usuario $quem, int $id, int $versao): ResultadoDeConteudo
    {
        $o = $this->obter($quem, $id);
        $this->exigir($quem, Acao::Publicar, $o, 'objeto.estado.negado');
        $this->exigirForaDaLixeira($o);
        $this->conferirVersao($o, $versao);
        if ($o->status === 'publicado') {
            return new ResultadoDeConteudo($o, false);
        }
        try {
            $novo = $o->status === 'submetido'
                ? $this->doRevisao(fn (): Objeto => $this->revisao->aprovar($quem, $o->id, $versao), $o)
                : $this->objetos->atualizar($o->id, ['status' => 'publicado'], self::MOTIVO, $versao, false, $quem->id);
        } catch (ViolacaoDeRegra $e) {
            throw EntradaInvalida::daRegra($e);
        }

        return $this->depoisDeMudarEstado($o, $novo);
    }

    /** Tira do ar (volta a privado). Já fora do ar: nada a alterar. */
    public function despublicar(Usuario $quem, int $id, int $versao): ResultadoDeConteudo
    {
        $o = $this->obter($quem, $id);
        $this->exigir($quem, Acao::Publicar, $o, 'objeto.estado.negado');
        $this->exigirForaDaLixeira($o);
        $this->conferirVersao($o, $versao);
        if (in_array($o->status, ['privado', 'rejeitado'], true)) {
            return new ResultadoDeConteudo($o, false);
        }
        if ($o->status === 'submetido') {
            throw new EstadoInvalido('O conteúdo espera revisão; retire o envio ou devolva-o em vez de despublicar.');
        }
        $novo = $this->objetos->atualizar($o->id, ['status' => 'privado'], self::MOTIVO, $versao, false, $quem->id);

        return $this->depoisDeMudarEstado($o, $novo);
    }

    /** O autor (ou editor) manda conteúdo privado ou devolvido para a revisão. */
    public function enviar(Usuario $quem, int $id, int $versao): ResultadoDeConteudo
    {
        $o = $this->obter($quem, $id);
        $this->exigirForaDaLixeira($o);
        $this->conferirVersao($o, $versao);
        $novo = $this->doRevisao(fn (): Objeto => $this->revisao->enviar($quem, $o->id, $versao), $o);

        return new ResultadoDeConteudo($novo, true, true, $o);
    }

    /** Quem enviou tira o envio pendente da fila. */
    public function retirar(Usuario $quem, int $id, int $versao): ResultadoDeConteudo
    {
        $o = $this->obter($quem, $id);
        $this->conferirVersao($o, $versao);
        $novo = $this->doRevisao(fn (): Objeto => $this->revisao->retirar($quem, $o->id, $versao), $o);

        return new ResultadoDeConteudo($novo, true, true, $o);
    }

    /** Move para dentro de `$destinoId` (mesmo site, tipo aceito, sem ciclo). */
    public function mover(Usuario $quem, int $id, int $versao, int $destinoId): ResultadoDeConteudo
    {
        $o = $this->obter($quem, $id);
        $this->exigir($quem, Acao::Apagar, $o, 'objeto.mover.negado');
        $this->exigirForaDaLixeira($o);
        if ($o->paiId === null) {
            throw new EstadoInvalido('A página inicial do site não pode ser movida.');
        }
        $this->conferirVersao($o, $versao);
        $destino = $this->objetos->porId($destinoId);
        // Mesma mensagem para "não existe", "é de outro site" e "sem permissão de criar lá".
        if ($destino === null || $destino->siteId !== $o->siteId || !$this->permissoes->podeCriarSob($quem, $destino)) {
            throw new EntradaInvalida(['para' => 'Indique um conteúdo deste site onde esta conta possa criar conteúdo.']);
        }
        if ($destino->status === self::LIXEIRA) {
            throw new EstadoInvalido('O destino está na lixeira.', EstadoInvalido::ARVORE);
        }
        if (str_starts_with($destino->caminho, $o->caminho)) {
            throw new EntradaInvalida(['para' => 'Não é possível mover um conteúdo para dentro dele mesmo.']);
        }
        if ($destino->id === $o->paiId) {
            return new ResultadoDeConteudo($o, false);
        }
        $classe = $this->classes->porId($o->classeId);
        if ($classe === null || !$this->tiposAceitos->aceita($destino, $classe)) {
            throw new EntradaInvalida(['para' => 'O tipo do destino não aceita conteúdo do tipo ' . ($classe->nome ?? '?') . ' dentro dele.']);
        }
        $paiAntigo = $this->pai($o);
        try {
            $novo = $this->objetos->mover($o->id, $destino->id, self::MOTIVO, $quem->id, $versao, true);
        } catch (ConflitoDeArvore) {
            throw new EstadoInvalido('O destino está na lixeira.', EstadoInvalido::ARVORE);
        } catch (ViolacaoDeRegra $e) {
            throw EntradaInvalida::daRegra($e);
        }
        // Defesa em profundidade: destino foi para a lixeira apesar da trava? Volta para o lugar de antes.
        if ($this->objetos->porId($destino->id)?->status === self::LIXEIRA && $paiAntigo !== null) {
            $this->desfazer(fn () => $this->objetos->mover($novo->id, $paiAntigo->id, self::MOTIVO . ': desfeito', $quem->id));
            throw new EstadoInvalido('O destino foi para a lixeira enquanto movia. Nada mudou.', EstadoInvalido::ARVORE);
        }
        $ok = $this->paginas->sincronizar($novo, $paiAntigo, $destino);

        return new ResultadoDeConteudo($novo, true, $ok, $o);
    }

    /** Manda para a lixeira (nunca apaga de vez). Recusa a página inicial e conteúdo com filhos fora da lixeira. */
    public function lixeira(Usuario $quem, int $id, int $versao): ResultadoDeConteudo
    {
        $o = $this->obter($quem, $id);
        $this->exigir($quem, Acao::Apagar, $o, 'objeto.apagar.negado');
        if ($o->paiId === null) {
            throw new EstadoInvalido('A página inicial do site não pode ir para a lixeira.');
        }
        $this->conferirVersao($o, $versao);
        if ($o->status === self::LIXEIRA) {
            return new ResultadoDeConteudo($o, false);
        }
        if ($this->objetos->filhosForaDaLixeira($o->id) > 0) {
            throw new EstadoInvalido('Este conteúdo tem conteúdo dentro dele; mova ou apague o de dentro primeiro.', EstadoInvalido::ARVORE);
        }
        try {
            $novo = $this->objetos->mandarParaLixeira($o->id, $versao, self::MOTIVO, $quem->id);
        } catch (ConflitoDeArvore) {
            throw new EstadoInvalido('Este conteúdo tem conteúdo dentro dele; mova ou apague o de dentro primeiro.', EstadoInvalido::ARVORE);
        }
        // Defesa em profundidade: apareceu filho fora da lixeira apesar da trava? Volta ao estado anterior.
        if ($this->objetos->filhosForaDaLixeira($novo->id) > 0) {
            $this->desfazer(fn () => $this->objetos->atualizar($novo->id, ['status' => $o->status], self::MOTIVO . ': desfeito', null, false, $quem->id));
            throw new EstadoInvalido('Alguém pôs conteúdo dentro dele enquanto apagava. Nada foi para a lixeira.', EstadoInvalido::ARVORE);
        }
        $ok = $this->paginas->sincronizar($novo, $this->pai($novo));
        if ($this->paginas->visibilidadeMudou($o, $novo)) {
            $ok = $this->paginas->republicarPorTags($o, $novo) && $ok;
        }

        return new ResultadoDeConteudo($novo, true, $ok, $o);
    }

    /** Volta o conteúdo de uma versão antiga como versão nova; o estado (publicado ou não) continua o atual. */
    public function restaurarVersao(Usuario $quem, int $id, int $alvo, int $versao): ResultadoDeConteudo
    {
        $o = $this->obter($quem, $id);
        $this->exigir($quem, Acao::Editar, $o, 'objeto.restaurar.negado');
        $this->exigirForaDaLixeira($o);
        $this->conferirVersao($o, $versao);
        if ($alvo === $o->versao || !in_array($alvo, array_column($this->objetos->versoes($o->id), 'versao'), true)) {
            throw new EntradaInvalida(['para' => 'Essa versão não existe ou já é a atual.']);
        }
        $slugsAntes = $this->slugsDe($this->tags->nomesDoObjeto($o->id));
        try {
            $novo = $this->objetos->restaurar($o->id, $alvo, 'restaurada a versão ' . $alvo, $quem->id, $versao, true);
        } catch (\InvalidArgumentException $e) {
            throw new EntradaInvalida(['para' => 'Essa versão não pode voltar como está: o endereço dela já é de outro conteúdo ou os campos do tipo mudaram desde então.'], $e);
        }
        $ok = true;
        if ($novo->urlAmigavel !== $o->urlAmigavel) {
            $ok = $this->paginas->tirarDoAr($o);
        }
        $ok = $this->paginas->sincronizar($novo, $this->pai($novo)) && $ok;
        if ($this->slugsDe($this->tags->nomesDoObjeto($o->id)) !== $slugsAntes) {
            $ok = $this->paginas->republicarPorTags($o, $novo) && $ok;
        }

        return new ResultadoDeConteudo($novo, true, $ok, $o);
    }

    /**
     * Anexa um arquivo local (já montado como UploadedFile) a um campo `arquivo`/`imagem`. O tipo real, a recodificação e a
     * miniatura são do {@see ServicoDeUpload}. Em imagem o texto alternativo é obrigatório; em arquivo é ignorado.
     */
    public function anexar(Usuario $quem, int $id, int $versao, string $campo, string $tipoDoCampo, UploadedFile $arquivo, ?string $alt): ResultadoDeConteudo
    {
        $o = $this->obter($quem, $id);
        $this->exigir($quem, Acao::Editar, $o, 'objeto.editar.negado');
        $this->exigirForaDaLixeira($o);
        $this->conferirVersao($o, $versao);
        $classe = $this->classes->porId($o->classeId) ?? throw new EstadoInvalido('O tipo deste conteúdo não existe mais.');
        $propriedade = null;
        foreach ($classe->propriedades as $p) {
            if ($p['nome'] === $campo && !($p['arquivada'] ?? false) && $p['tipo'] === $tipoDoCampo) {
                $propriedade = $p;
            }
        }
        if ($propriedade === null) {
            throw new EntradaInvalida(['campo' => 'Este tipo de conteúdo não tem um campo "' . $campo . '" do tipo ' . $tipoDoCampo . '.']);
        }
        if ($tipoDoCampo === 'imagem') {
            $problema = $this->validador->problemaNoAlt((string) $alt);
            if ($problema !== null) {
                throw new EntradaInvalida(['alt' => $problema]);
            }
        }
        try {
            $guardado = ($this->uploads)()->enviar($arquivo, $propriedade, $o->siteId, $o->id, $quem->id, $tipoDoCampo === 'imagem' ? trim((string) $alt) : null);
        } catch (ErroDeUpload $e) {
            throw new EntradaInvalida(['dados.' . $campo => $e->getMessage()], $e);
        }
        try {
            $novo = $this->objetos->atualizar($o->id, ['dados' => [$campo => $guardado->id]], self::MOTIVO, $versao, false, $quem->id);
        } catch (ViolacaoDeRegra $e) {
            throw EntradaInvalida::daRegra($e);
        }
        $ok = $this->paginas->sincronizar($novo, $this->pai($novo));

        return new ResultadoDeConteudo($novo, true, $ok, $o);
    }

    /**
     * Republica o site em lotes, retomável, dentro de `$orcamento` segundos. Mesma permissão da tela de
     * publicação do painel (administrar o site).
     *
     * @return array{estado: array<string, mixed>|null, concluido: bool}
     */
    public function republicar(Usuario $quem, Site $site, float $orcamento): array
    {
        $raiz = $site->objetoRaizId === null ? null : $this->objetos->porId($site->objetoRaizId);
        if (!$this->permissoes->podeAdministrarSite($quem, $raiz)) {
            $this->auditoria->registrar('site.negado', 'usuario:' . $quem->id, $quem->id, null, ['site_id' => $site->id]);
            throw new AcessoNegado(Acao::Administrar, 'Esta conta não pode republicar este site.');
        }
        $limite = microtime(true) + max(1.0, $orcamento);
        $this->fila->iniciar($site, 'cli');
        $estado = null;
        do {
            $r = $this->fila->processar($site, FilaDeRepublicacao::LOTE_PAINEL, max(0.5, $limite - microtime(true)));
            $estado = $r['estado'];
            if ($r['terminou_agora'] && $estado !== null) {
                $this->auditoria->registrar('site.republicar', 'usuario:' . $quem->id, $quem->id, null, ['site_id' => $site->id, 'objetos' => $estado['feitas'], 'falhas' => $estado['falhas']]);
            }
            if ($r['ocupado']) {
                usleep(300000);
            }
        } while ($estado !== null && $estado['status'] === FilaDeRepublicacao::ANDANDO && microtime(true) < $limite);

        return ['estado' => $estado, 'concluido' => $estado !== null && $estado['status'] === FilaDeRepublicacao::CONCLUIDO];
    }

    // ---------------------------------------------------------------- apoio

    /** @throws AcessoNegado (e registra `$negado` na auditoria, só ids) */
    private function exigir(Usuario $quem, Acao $acao, Objeto $o, string $negado, string $mensagem = ''): void
    {
        if (!$this->pode($quem, $acao, $o)) {
            $this->auditoria->registrar($negado, 'usuario:' . $quem->id, $quem->id, $o->id);
            throw new AcessoNegado($acao, $mensagem);
        }
    }

    private function exigirForaDaLixeira(Objeto $o): void
    {
        if ($o->status === self::LIXEIRA) {
            throw new EstadoInvalido('Este conteúdo está na lixeira.');
        }
    }

    private function conferirVersao(Objeto $o, int $esperada): void
    {
        if ($esperada < 1) {
            throw new EntradaInvalida(['versao' => 'A versão esperada precisa ser um número a partir de 1 (veja a versão atual com "ver").']);
        }
        if ($esperada !== $o->versao) {
            throw new ConflitoDeVersao($esperada, $o->versao);
        }
    }

    /** @param \Closure(): Objeto $passo */
    private function doRevisao(\Closure $passo, Objeto $antes): Objeto
    {
        try {
            return $passo();
        } catch (ErroDeRevisao $e) {
            throw match ($e->codigo) {
                ErroDeRevisao::NEGADO => new AcessoNegado(Acao::Editar),
                ErroDeRevisao::CONFLITO => new ConflitoDeVersao($antes->versao, $this->objetos->porId($antes->id)->versao ?? $antes->versao),
                default => new EstadoInvalido($e->getMessage()),
            };
        }
    }

    private function depoisDeMudarEstado(Objeto $o, Objeto $novo): ResultadoDeConteudo
    {
        $ok = $this->paginas->sincronizar($novo, $this->pai($novo));
        if ($this->paginas->visibilidadeMudou($o, $novo)) {
            $ok = $this->paginas->republicarPorTags($o, $novo) && $ok;
        }

        return new ResultadoDeConteudo($novo, true, $ok, $o);
    }

    private function pai(Objeto $o): ?Objeto
    {
        return $o->paiId === null ? null : $this->objetos->porId($o->paiId);
    }

    /**
     * @param list<string> $nomes
     * @return list<string>
     */
    private function slugsDe(array $nomes): array
    {
        $slugs = array_map(TagRepositorio::slug(...), $nomes);
        sort($slugs);

        return $slugs;
    }

    /** @param \Closure(): mixed $reverter */
    private function desfazer(\Closure $reverter): void
    {
        try {
            $reverter();
        } catch (\Throwable) {
            // nada mais a fazer aqui: o erro já sobe e o estado gravado continua visível
        }
    }
}
