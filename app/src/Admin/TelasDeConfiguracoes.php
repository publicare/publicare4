<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Conteudo\ConfigDoSite;
use Pbl\Conteudo\ConflitoDeConfig;
use Pbl\Conteudo\ErroDeConfig;
use Pbl\Conteudo\Site;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tela de configurações do site (ADR 0017, fase 5m): `/_pbl/site/{id}/configuracoes`. Chamada por `TelasDeSite::tratar`, que já
 * conferiu origem, CSRF e a permissão de administrar o site (não exige modo elevado). Só chaves do catálogo; valor validado pelo
 * tipo; edição otimista pela versão; a auditoria guarda só os nomes das chaves alteradas. Sem JavaScript.
 */
final class TelasDeConfiguracoes
{
    use AuxiliaresDeTela;

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
    ) {
    }

    public function tratar(SessaoAtiva $sessao, Site $site, Request $req): Response
    {
        if ($req->getMethod() !== 'POST') {
            $aviso = match ($req->query->all()['ok'] ?? null) {
                'salvo' => '<div class="alert alert-success" role="status">Configurações salvas e site republicado.</div>',
                'pendente' => '<div class="alert alert-warning" role="status">Configurações salvas. O site é grande: a republicação ficou pendente e termina em segundo plano (<code>bin/pbl publicar</code>).</div>',
                'falha' => '<div class="alert alert-warning" role="status">Configurações salvas, mas a republicação falhou. Tente de novo ou rode <code>bin/pbl publicar</code>.</div>',
                default => '',
            };

            return $this->formulario($sessao, $site, $this->servicos->configs->efetivas($site->id), $this->servicos->configs->versao($site->id), [], $aviso);
        }

        $dados = $req->request->all();
        $envio = [];
        foreach (ConfigDoSite::catalogo() as $chave => $def) {
            $v = $dados[$chave] ?? null;
            $envio[$chave] = $def['tipo'] === ConfigDoSite::BOOLEANO ? ($v === '1' ? '1' : '0') : (is_string($v) ? $v : '');
        }
        $versao = is_string($dados['versao'] ?? null) ? $dados['versao'] : '';
        try {
            $mudou = $this->servicos->configs->definir($site->id, $envio, $versao);
        } catch (ConflitoDeConfig $e) {
            return $this->formulario(
                $sessao,
                $site,
                $envio,
                $this->servicos->configs->versao($site->id),
                [],
                $this->erro($e->getMessage() . ' Seus valores continuam abaixo; confira as configurações atuais antes de salvar de novo.'),
                Response::HTTP_CONFLICT,
            );
        } catch (ErroDeConfig $e) {
            return $this->formulario($sessao, $site, $envio, $versao, $e->erros, $this->erro('Corrija os campos indicados.'), Response::HTTP_UNPROCESSABLE_ENTITY);
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

        return new RedirectResponse($this->link($site) . '?ok=' . $ok, Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, mixed> $valores
     * @param array<string, string> $erros
     */
    private function formulario(SessaoAtiva $sessao, Site $site, array $valores, string $versao, array $erros, string $aviso, int $status = Response::HTTP_OK): Response
    {
        $corpo = $aviso . '<p>Valores usados pelas páginas do site (nome, SEO, contato, rodapé, busca, menu). Ao salvar, o site é republicado. Campo vazio volta ao padrão.</p>'
            . '<form method="post" action="' . $this->link($site) . '" novalidate>' . $this->campoCsrf($sessao)
            . '<input type="hidden" name="versao" value="' . Pagina::h($versao) . '">';
        foreach (ConfigDoSite::catalogo() as $chave => $def) {
            $id = 'cfg-' . $chave;
            $valor = $valores[$chave] ?? $def['padrao'];
            $texto = is_array($valor) ? implode("\n", array_map('strval', $valor)) : (is_bool($valor) ? ($valor ? '1' : '0') : (string) $valor);
            $erro = $erros[$chave] ?? null;
            $desc = $id . '-ajuda';
            $atr = ' aria-describedby="' . $desc . ($erro !== null ? ' ' . $id . '-erro' : '') . '"' . ($erro !== null ? ' aria-invalid="true"' : '');
            $rotulo = '<label for="' . $id . '" class="form-label">' . Pagina::h($def['rotulo']) . '</label>';
            if ($def['tipo'] === ConfigDoSite::BOOLEANO) {
                $campo = '<div class="form-check"><input id="' . $id . '" class="form-check-input" type="checkbox" name="' . $chave . '" value="1"' . ($texto === '1' ? ' checked' : '') . $atr . '>'
                    . '<label for="' . $id . '" class="form-check-label">' . Pagina::h($def['rotulo']) . '</label></div>';
            } elseif ($def['tipo'] === ConfigDoSite::HTML || $def['tipo'] === ConfigDoSite::LISTA_URL) {
                $campo = $rotulo . '<textarea id="' . $id . '" class="form-control" name="' . $chave . '" rows="4"' . $atr . '>' . Pagina::h($texto) . '</textarea>';
            } else {
                $tipo = in_array($def['tipo'], [ConfigDoSite::INTEIRO, ConfigDoSite::ARQUIVO], true) ? 'number' : 'text';
                $campo = $rotulo . '<input id="' . $id . '" class="form-control" type="' . $tipo . '" name="' . $chave . '" value="' . Pagina::h($texto) . '"' . $atr . '>';
            }
            $corpo .= '<div class="mb-3">' . $campo
                . '<div id="' . $desc . '" class="form-text">' . Pagina::h($def['ajuda']) . '</div>'
                . ($erro !== null ? '<div id="' . $id . '-erro" class="text-danger" role="alert">' . Pagina::h($erro) . '</div>' : '') . '</div>';
        }
        $corpo .= '<div class="pbl-acoes"><button type="submit" class="btn btn-primary">Salvar e republicar</button></div></form>';

        return Pagina::resposta('Configurações de ' . $site->nome, $corpo, $status, false, $this->barra($sessao), [[$site->nome, Painel::PREFIXO . '/'], ['Configurações', null]]);
    }

    private function link(Site $site): string
    {
        return Painel::PREFIXO . '/site/' . $site->id . '/configuracoes';
    }
}
