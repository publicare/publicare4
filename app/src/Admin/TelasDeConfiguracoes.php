<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Arquivos\ErroDeUpload;
use Pbl\Conteudo\ConfigDoSite;
use Pbl\Conteudo\ConflitoDeConfig;
use Pbl\Conteudo\ErroDeConfig;
use Pbl\Conteudo\Site;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tela de configurações do site (ADR 0017, fase 5m): `/_pbl/site/{id}/configuracoes`. Chamada por `TelasDeSite::tratar`, que já
 * conferiu origem, CSRF e a permissão de administrar o site (não exige modo elevado). Só chaves do catálogo; valor validado pelo
 * tipo; edição otimista pela versão; a auditoria guarda só os nomes das chaves alteradas.
 *
 * Abas (`?aba=`, mesmo padrão da conta): cada aba é um formulário próprio e grava só as chaves do seu grupo. Imagens (logo, ícone,
 * imagem padrão, imagem de abertura) são enviadas no próprio formulário (`multipart`): o `ServicoDeUpload` confere o tipo real pelo
 * conteúdo (PNG, JPG ou WebP), o tamanho e recodifica a imagem; o arquivo fica ligado ao objeto raiz do site (propriedade
 * `config_<chave>`) e o número dele vai para a configuração. O caminho em disco nunca aparece na tela. Sem JavaScript inline.
 */
final class TelasDeConfiguracoes
{
    use AuxiliaresDeTela;

    /** Tipos aceitos nas imagens da configuração. SVG e `.ico` não entram: a lista de tipos do PBL4 (ADR 0014) não os tem. */
    private const TIPOS_IMAGEM = ['png', 'jpg', 'webp'];
    /** Tamanho máximo, em MB, por imagem. */
    private const LIMITE_MB = ['logo' => 2, 'icone' => 1, 'imagem_padrao' => 5, 'imagem_hero' => 5];
    private const PREFIXO_PROPRIEDADE = 'config_';
    private const PREFIXO_ARQUIVO = 'arquivo_';
    private const PREFIXO_REMOVER = 'remover_';

    /**
     * @return array<string, array{0: string, 1: list<string>}> aba => [rótulo, chaves do catálogo que ela grava]
     */
    public static function abas(): array
    {
        return [
            'identidade' => ['Identidade', ['nome', 'slogan', 'idioma', 'logo', 'icone', 'imagem_hero']],
            'busca' => ['Busca e compartilhamento', ['descricao', 'imagem_padrao', 'titulo_padrao', 'titulo_sufixo', 'robots_indexar']],
            'contato' => ['Contato', ['email_contato', 'telefone', 'endereco', 'redes_sociais']],
            'rodape' => ['Rodapé e métricas', ['rodape', 'analytics']],
            'comportamento' => ['Comportamento', ['busca_ligada', 'itens_por_pagina', 'menu_profundidade']],
        ];
    }

    /** Campos pouco usados: ficam num bloco recolhido dentro da aba. */
    private const RAROS = ['imagem_hero'];

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
    ) {
    }

    public function tratar(SessaoAtiva $sessao, Site $site, Request $req): Response
    {
        $validas = array_keys(self::abas());
        if ($req->getMethod() !== 'POST') {
            $aviso = match ($req->query->all()['ok'] ?? null) {
                'salvo' => '<div class="alert alert-success" role="status">Configurações salvas e site republicado.</div>',
                'pendente' => '<div class="alert alert-warning" role="status">Configurações salvas. O site é grande: a republicação ficou pendente e termina em segundo plano (<code>bin/pbl publicar</code>).</div>',
                'falha' => '<div class="alert alert-warning" role="status">Configurações salvas, mas a republicação falhou. Tente de novo ou rode <code>bin/pbl publicar</code>.</div>',
                default => '',
            };

            return $this->formulario($sessao, $site, Pagina::abaDe($req, 'identidade', $validas), [], [], $aviso);
        }

        $dados = $req->request->all();
        $aba = is_string($dados['aba'] ?? null) && in_array($dados['aba'], $validas, true) ? $dados['aba'] : null;
        if ($aba === null) {
            return $this->incompleto($sessao);
        }
        $versao = is_string($dados['versao'] ?? null) ? $dados['versao'] : '';
        // Confere a versão antes de gravar qualquer arquivo: um envio que ia ser recusado não deixa imagem solta.
        if (!hash_equals($this->servicos->configs->versao($site->id), $versao)) {
            return $this->conflito($sessao, $site, $aba, $dados);
        }

        $catalogo = ConfigDoSite::catalogo();
        $envio = [];
        $erros = [];
        $arquivos = $req->files->all();
        foreach (self::abas()[$aba][1] as $chave) {
            $tipo = $catalogo[$chave]['tipo'];
            if ($tipo === ConfigDoSite::ARQUIVO) {
                $enviado = $arquivos[self::PREFIXO_ARQUIVO . $chave] ?? null;
                if ($enviado instanceof UploadedFile) {
                    try {
                        $envio[$chave] = (string) $this->guardarImagem($sessao, $site, $chave, $enviado)->id;
                    } catch (ErroDeUpload $e) {
                        $erros[$chave] = $e->getMessage();
                    }
                } elseif (($dados[self::PREFIXO_REMOVER . $chave] ?? null) === '1') {
                    $envio[$chave] = '';
                }
                continue; // sem envio nem remoção: a imagem atual continua
            }
            $v = $dados[$chave] ?? null;
            $envio[$chave] = $tipo === ConfigDoSite::BOOLEANO ? ($v === '1' ? '1' : '0') : (is_string($v) ? $v : '');
        }
        if ($erros !== []) {
            return $this->formulario($sessao, $site, $aba, $envio, $erros, $this->erro('Corrija os campos indicados.'), Response::HTTP_UNPROCESSABLE_ENTITY, $versao);
        }
        try {
            $mudou = $this->servicos->configs->definir($site->id, $envio, $versao);
        } catch (ConflitoDeConfig) {
            return $this->conflito($sessao, $site, $aba, $dados);
        } catch (ErroDeConfig $e) {
            return $this->formulario($sessao, $site, $aba, $envio, $e->erros, $this->erro('Corrija os campos indicados.'), Response::HTTP_UNPROCESSABLE_ENTITY, $versao);
        }
        $this->servicos->auditoria->registrar('site.config', 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, null, ['site_id' => $site->id, 'chaves' => implode(',', $mudou)]);
        $ok = 'salvo';
        if ($mudou !== []) {
            try {
                $ok = $this->servicos->publicador->republicarPorConfiguracao($site) ? 'salvo' : 'pendente';
            } catch (\Throwable) {
                $ok = 'falha';
            }
        }

        return new RedirectResponse($this->link($site) . '?ok=' . $ok . '&aba=' . $aba, Response::HTTP_SEE_OTHER);
    }

    /** @param array<string, mixed> $dados */
    private function conflito(SessaoAtiva $sessao, Site $site, string $aba, array $dados): Response
    {
        $digitado = [];
        foreach (self::abas()[$aba][1] as $chave) {
            if (isset($dados[$chave]) && is_string($dados[$chave])) {
                $digitado[$chave] = $dados[$chave];
            }
        }

        return $this->formulario(
            $sessao,
            $site,
            $aba,
            $digitado,
            [],
            $this->erro('Estas configurações mudaram desde que você abriu a tela. Seus valores continuam abaixo; confira as configurações atuais antes de salvar de novo.'),
            Response::HTTP_CONFLICT,
        );
    }

    /** @throws ErroDeUpload */
    private function guardarImagem(SessaoAtiva $sessao, Site $site, string $chave, UploadedFile $arquivo): \Pbl\Arquivos\Arquivo
    {
        if ($site->objetoRaizId === null) {
            throw new ErroDeUpload('Este site ainda não tem o conteúdo raiz: não dá para guardar a imagem.');
        }
        $propriedade = [
            'nome' => self::PREFIXO_PROPRIEDADE . $chave,
            'tipo' => 'imagem',
            'tipos' => self::TIPOS_IMAGEM,
            'tamanho_max_mb' => self::LIMITE_MB[$chave] ?? 2,
        ];

        return $this->servicos->uploads()->enviar($arquivo, $propriedade, $site->id, $site->objetoRaizId, $sessao->usuario->id);
    }

    /**
     * @param array<string, mixed> $digitado valores da aba que falhou (sobrepõem os gravados só nessa aba)
     * @param array<string, string> $erros
     */
    private function formulario(SessaoAtiva $sessao, Site $site, string $aba, array $digitado, array $erros, string $aviso, int $status = Response::HTTP_OK, ?string $versao = null): Response
    {
        $efetivas = $this->servicos->configs->efetivas($site->id);
        $versaoAtual = $this->servicos->configs->versao($site->id);
        $paineis = [];
        foreach (self::abas() as $chave => [$rotulo, $campos]) {
            $valores = $chave === $aba ? array_merge($efetivas, array_diff_key($digitado, array_flip($this->chavesDeArquivo()))) : $efetivas;
            $paineis[$chave] = [$rotulo, $this->painel($sessao, $site, $chave, $campos, $valores, $chave === $aba ? $erros : [], $chave === $aba && $versao !== null ? $versao : $versaoAtual)];
        }
        $corpo = Pagina::abas($paineis, $aba, $aviso, 'Grupos de configurações');
        $ajuda = 'Valores usados pelas páginas do site (nome, SEO, contato, rodapé, busca, menu). Cada aba grava só o próprio grupo; ao salvar, o site é republicado. Campo vazio volta ao padrão.';

        return Pagina::resposta('Configurações de ' . $site->nome, $corpo, $status, false, $this->barra($sessao), [[$site->nome, Painel::PREFIXO . '/'], ['Configurações', null]], '', Pagina::h($ajuda));
    }

    /** @return list<string> */
    private function chavesDeArquivo(): array
    {
        return array_keys(array_filter(ConfigDoSite::catalogo(), static fn (array $d): bool => $d['tipo'] === ConfigDoSite::ARQUIVO));
    }

    /**
     * @param list<string> $campos
     * @param array<string, mixed> $valores
     * @param array<string, string> $erros
     */
    private function painel(SessaoAtiva $sessao, Site $site, string $aba, array $campos, array $valores, array $erros, string $versao): string
    {
        $catalogo = ConfigDoSite::catalogo();
        $comuns = '';
        $raros = '';
        $temArquivo = false;
        $abrirRaros = false;
        foreach ($campos as $chave) {
            $html = $this->campoDe($site, $chave, $catalogo[$chave], $valores[$chave] ?? $catalogo[$chave]['padrao'], $erros[$chave] ?? null);
            $temArquivo = $temArquivo || $catalogo[$chave]['tipo'] === ConfigDoSite::ARQUIVO;
            if (in_array($chave, self::RAROS, true)) {
                $raros .= $html;
                $abrirRaros = $abrirRaros || isset($erros[$chave]);
            } else {
                $comuns .= $html;
            }
        }
        if ($raros !== '') {
            $comuns .= '<div class="accordion mb-3">' . Pagina::recolhivel('raros-' . $aba, '<strong>Opções avançadas</strong> <span class="pbl-resumo-meta ms-2">(pouco usadas)</span>', $raros, $abrirRaros) . '</div>';
        }

        return '<form method="post" action="' . $this->link($site) . '?aba=' . $aba . '"' . ($temArquivo ? ' enctype="multipart/form-data"' : '') . ' novalidate>'
            . $this->campoCsrf($sessao)
            . '<input type="hidden" name="versao" value="' . Pagina::h($versao) . '"><input type="hidden" name="aba" value="' . $aba . '">'
            . $comuns . '<div class="pbl-acoes"><button type="submit" class="btn btn-primary">Salvar e republicar</button></div></form>';
    }

    /** @param array{tipo: string, padrao: mixed, rotulo: string, ajuda: string, max?: int, min?: int} $def */
    private function campoDe(Site $site, string $chave, array $def, mixed $valor, ?string $erro): string
    {
        $id = 'cfg-' . $chave;
        $desc = $id . '-ajuda';
        $atr = ' aria-describedby="' . $desc . ($erro !== null ? ' ' . $id . '-erro' : '') . '"' . ($erro !== null ? ' aria-invalid="true"' : '');
        $texto = is_array($valor) ? implode("\n", array_map('strval', $valor)) : (is_bool($valor) ? ($valor ? '1' : '0') : (string) $valor);
        $ajuda = Pagina::ajuda($desc, $def['rotulo'], Pagina::h($def['ajuda']));
        $rotulo = Pagina::rotulo($id, Pagina::h($def['rotulo']), $ajuda);
        $msg = $erro !== null ? '<div id="' . $id . '-erro" class="text-danger" role="alert">' . Pagina::h($erro) . '</div>' : '';
        if ($def['tipo'] === ConfigDoSite::BOOLEANO) {
            $campo = '<div class="form-check"><input id="' . $id . '" class="form-check-input" type="checkbox" name="' . $chave . '" value="1"' . ($texto === '1' ? ' checked' : '') . $atr . '>'
                . '<label for="' . $id . '" class="form-check-label">' . Pagina::h($def['rotulo']) . '</label>' . $ajuda . '</div>';
        } elseif ($def['tipo'] === ConfigDoSite::ARQUIVO) {
            return '<div class="mb-3">' . $this->campoDeImagem($site, $chave, $def, $texto, $erro) . '</div>';
        } elseif ($def['tipo'] === ConfigDoSite::HTML || $def['tipo'] === ConfigDoSite::LISTA_URL) {
            $campo = $rotulo . '<textarea id="' . $id . '" class="form-control" name="' . $chave . '" rows="4"' . $atr . '>' . Pagina::h($texto) . '</textarea>';
        } else {
            $tipo = $def['tipo'] === ConfigDoSite::INTEIRO ? 'number' : 'text';
            $campo = $rotulo . '<input id="' . $id . '" class="form-control" type="' . $tipo . '" name="' . $chave . '" value="' . Pagina::h($texto) . '"' . $atr . '>';
        }

        return '<div class="mb-3">' . $campo . $msg . '</div>';
    }

    /** @param array{tipo: string, padrao: mixed, rotulo: string, ajuda: string} $def */
    private function campoDeImagem(Site $site, string $chave, array $def, string $atual, ?string $erro): string
    {
        $id = 'cfg-' . $chave;
        $desc = $id . '-ajuda';
        $limite = self::LIMITE_MB[$chave] ?? 2;
        $ajuda = Pagina::h($def['ajuda']) . ' Até ' . $limite . ' MB. Escolher outra imagem substitui a atual.';
        $arquivo = ctype_digit($atual) ? $this->servicos->arquivos->porId((int) $atual) : null;
        if ($arquivo !== null && ($arquivo->siteId !== $site->id || !$arquivo->ehImagem())) {
            $arquivo = null;
        }
        $html = Pagina::rotulo($id, Pagina::h($def['rotulo']), Pagina::ajuda($desc, $def['rotulo'], $ajuda));
        if ($arquivo !== null) {
            $link = Painel::PREFIXO . '/arquivo/' . $arquivo->id;
            $html .= '<div class="mb-2"><img src="' . Pagina::h($link . '/miniatura/150') . '" alt="Imagem atual: ' . Pagina::h($def['rotulo']) . '" width="150" loading="lazy" class="img-thumbnail"></div>'
                . Pagina::checkbox($id . '-remover', self::PREFIXO_REMOVER . $chave, 'Remover a imagem atual', false);
        }
        $html .= '<input id="' . $id . '" class="form-control" type="file" name="' . self::PREFIXO_ARQUIVO . $chave . '" accept="image/png,image/jpeg,image/webp" aria-describedby="' . $desc . ($erro !== null ? ' ' . $id . '-erro' : '') . '"' . ($erro !== null ? ' aria-invalid="true"' : '') . '>';
        if ($erro !== null) {
            $html .= '<div id="' . $id . '-erro" class="text-danger" role="alert">' . Pagina::h($erro) . '</div>';
        }

        return $html;
    }

    private function link(Site $site): string
    {
        return Painel::PREFIXO . '/site/' . $site->id . '/configuracoes';
    }
}
