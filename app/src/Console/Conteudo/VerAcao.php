<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;

use Pbl\Acesso\Acao;
use Pbl\Arquivos\Arquivo;
use Pbl\Conteudo\Objeto;

/** Ação de leitura do `conteudo`: sempre pelo serviço (a conta só enxerga o que o painel mostraria). */
final class VerAcao implements AcaoDeConteudo
{
    private const FILHOS_NA_TELA = 100;

    public function nome(): string
    {
        return 'ver';
    }

    public function executar(Contexto $c): Resposta
    {
        $o = (new ResolvedorDeObjeto($c->s->conteudo))->resolver($c->quem, $c->site, $c->alvo());
        $classe = $c->s->classes->porId($o->classeId);
        $agora = gmdate('c');
        $visivel = $o->visivel($agora);
        $base = $visivel ? $c->s->enderecos->base($o->siteId) : '';
        $pai = $o->paiId === null ? null : $c->s->objetos->porId($o->paiId);
        $pai = $pai !== null && $c->s->permissoes->pode($c->quem, Acao::Ver, $pai) ? $pai : null;
        $filhos = $c->s->conteudo->filhos($c->quem, $o, self::FILHOS_NA_TELA);
        $campos = [];
        $esquema = [];
        foreach ($classe->propriedades ?? [] as $p) {
            if ($p['arquivada'] ?? false) {
                continue;
            }
            $valor = $o->dados[$p['nome']] ?? null;
            $esquema[] = ['nome' => $p['nome'], 'tipo' => $p['tipo'], 'obrigatorio' => (bool) ($p['obrigatorio'] ?? false)];
            if (in_array($p['tipo'], ['arquivo', 'imagem'], true)) {
                $arquivo = $valor !== null && ctype_digit((string) $valor) ? $c->s->arquivos->porId((int) $valor) : null;
                $campos[$p['nome']] = $arquivo === null || $arquivo->siteId !== $o->siteId ? null : self::doArquivo($arquivo);
            } else {
                $campos[$p['nome']] = $valor;
            }
        }
        $versoes = $c->s->objetos->versoes($o->id);
        $dados = $c->resumo($o) + [
            'visivel_agora' => $visivel,
            'endereco_publico' => $base === '' ? null : $base . '/' . ltrim($o->urlAmigavel, '/'),
            'descricao' => $o->descricao, 'peso' => $o->peso, 'no_menu' => $o->noMenu, 'rotulo_menu' => $o->rotuloMenu,
            'tags' => $c->s->tags->nomesDoObjeto($o->id),
            'data_publicacao' => $o->dataPublicacao, 'data_validade' => $o->dataValidade,
            'pai' => $pai === null ? null : ['id' => $pai->id, 'titulo' => $pai->titulo],
            'filhos' => array_map(static fn (Objeto $f): array => ['id' => $f->id, 'titulo' => $f->titulo, 'tipo' => $c->nomeDoTipo($f->classeId), 'estado' => $f->status], $filhos['itens']),
            'filhos_total' => $filhos['total'],
            'campos' => $campos, 'esquema' => $esquema,
            'permissoes' => [
                'editar' => $c->s->conteudo->pode($c->quem, Acao::Editar, $o), 'publicar' => $c->s->conteudo->pode($c->quem, Acao::Publicar, $o),
                'apagar' => $c->s->conteudo->pode($c->quem, Acao::Apagar, $o), 'criar' => $c->s->conteudo->podeCriarSob($c->quem, $o),
            ],
            'atualizado_em' => $versoes[0]['criado_em'] ?? null,
        ];

        $t = [$c->linha($o), 'Endereço: ' . $dados['url'] . ($dados['endereco_publico'] === null ? ' (fora do ar)' : ' → ' . $dados['endereco_publico'])];
        $t[] = 'Pai: ' . ($pai === null ? '-' : '#' . $pai->id . ' ' . $pai->titulo);
        if ($o->descricao !== '') {
            $t[] = 'Descrição: ' . $o->descricao;
        }
        foreach ($campos as $nome => $valor) {
            $t[] = $nome . ': ' . (is_array($valor) ? 'arquivo #' . $valor['arquivo_id'] . ' ' . $valor['nome'] . ($valor['alt'] === null ? '' : ' (alt: ' . $valor['alt'] . ')') : (string) ($valor ?? ''));
        }
        foreach ($dados['filhos'] as $f) {
            $t[] = 'Filho #' . $f['id'] . ' ' . $f['titulo'] . ' [' . $f['tipo'] . ', ' . $f['estado'] . ']';
        }
        $t[] = 'Permissões: ' . implode(', ', array_keys(array_filter($dados['permissoes']))) ;

        return new Resposta($dados, implode("\n", $t));
    }

    /** @return array{arquivo_id: int, nome: string, mime: string, tamanho: int, alt: ?string} */
    private static function doArquivo(Arquivo $a): array
    {
        return ['arquivo_id' => $a->id, 'nome' => $a->nome, 'mime' => $a->mime, 'tamanho' => $a->tamanho, 'alt' => $a->alt];
    }
}
