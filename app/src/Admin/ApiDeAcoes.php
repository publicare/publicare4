<?php

declare(strict_types=1);

namespace Pbl\Admin;

use Pbl\Acesso\Acao;
use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Conteudo\ConflitoDeVersao;
use Pbl\Conteudo\Objeto;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API mínima da barra de edição das páginas públicas (ADR 0022): `GET /_pbl/api/acoes?objeto=ID` (ou `?objetos=1,2,3`, em lote) diz o que a pessoa logada pode fazer
 * naquele conteúdo (só as ações permitidas, mais o token CSRF), e `POST /_pbl/api/estado` publica ou despublica. Sem sessão: 401 em JSON
 * (o `Painel` só chama aqui com sessão válida ou devolve 401 antes). A autorização é sempre refeita no servidor.
 */
final class ApiDeAcoes
{
    public const ROTA_ACOES = '/_pbl/api/acoes';
    public const ROTA_ESTADO = '/_pbl/api/estado';
    public const LIMITE_LOTE = 100;

    public function __construct(private readonly Servicos $servicos, private readonly Rede $rede, private readonly Csrf $csrf)
    {
    }

    public static function atende(string $caminho, string $metodo): bool
    {
        return ($caminho === self::ROTA_ACOES && $metodo === 'GET') || ($caminho === self::ROTA_ESTADO && $metodo === 'POST');
    }

    public static function semSessao(): JsonResponse
    {
        return self::json(['erro' => 'sem_sessao'], Response::HTTP_UNAUTHORIZED);
    }

    public function tratar(SessaoAtiva $sessao, string $caminho, Request $r): JsonResponse
    {
        return $caminho === self::ROTA_ACOES ? $this->acoes($sessao, $r) : $this->estado($sessao, $r);
    }

    private function acoes(SessaoAtiva $sessao, Request $r): JsonResponse
    {
        if ($r->query->has('objetos')) {
            return $this->emLote($sessao, $r->query->get('objetos'));
        }
        $id = $this->id($r->query->get('objeto'));
        $o = $id === null ? null : $this->servicos->objetos->porId($id);
        if ($o === null) {
            return self::json(['erro' => 'nao_encontrado'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->pode($sessao, Acao::Ver, $o) || $o->status === 'apagado') {
            return self::json(['erro' => 'sem_permissao'], Response::HTTP_FORBIDDEN);
        }
        $acoes = $this->acoesDe($sessao, $o);

        return self::json([
            'objeto' => $o->id, 'estado' => $o->status, 'versao' => $o->versao, 'acoes' => $acoes,
            'csrf' => $this->csrfSe($sessao, [$acoes]),
        ]);
    }

    /**
     * `GET /_pbl/api/acoes?objetos=1,2,3`: ações permitidas de vários objetos de uma vez (até LIMITE_LOTE). Objeto inexistente, apagado ou
     * fora do que a conta pode ver simplesmente não aparece na resposta (não distingue os casos, para não revelar que o id existe).
     */
    private function emLote(SessaoAtiva $sessao, mixed $bruto): JsonResponse
    {
        $ids = [];
        foreach (explode(',', is_string($bruto) ? $bruto : '') as $parte) {
            $id = $this->id(trim($parte));
            if ($id === null) {
                return self::json(['erro' => 'pedido_invalido'], Response::HTTP_BAD_REQUEST);
            }
            $ids[$id] = true;
        }
        if ($ids === []) {
            return self::json(['erro' => 'pedido_invalido'], Response::HTTP_BAD_REQUEST);
        }
        if (count($ids) > self::LIMITE_LOTE) {
            return self::json(['erro' => 'muitos_objetos', 'limite' => self::LIMITE_LOTE], Response::HTTP_BAD_REQUEST);
        }
        $saida = [];
        $todas = [];
        foreach (array_keys($ids) as $id) {
            $o = $this->servicos->objetos->porId($id);
            if ($o === null || $o->status === 'apagado' || !$this->pode($sessao, Acao::Ver, $o)) {
                continue;
            }
            $acoes = $this->acoesDe($sessao, $o);
            $todas[] = $acoes;
            $saida[(string) $o->id] = ['estado' => $o->status, 'versao' => $o->versao, 'acoes' => $acoes];
        }

        return self::json(['objetos' => (object) $saida, 'csrf' => $this->csrfSe($sessao, $todas)]);
    }

    /** @return array<string, array{url: string}> */
    private function acoesDe(SessaoAtiva $sessao, Objeto $o): array
    {
        $base = Painel::PREFIXO . '/objeto/' . $o->id;
        $acoes = [];
        if ($this->pode($sessao, Acao::Editar, $o)) {
            $acoes['editar'] = ['url' => $base . '/editar'];
        }
        if ($this->pode($sessao, Acao::Publicar, $o)) {
            $acoes[$o->status === 'publicado' ? 'despublicar' : 'publicar'] = ['url' => self::ROTA_ESTADO];
        }
        if ($this->pode($sessao, Acao::Apagar, $o)) {
            $acoes['mover'] = ['url' => $base . '/mover'];
            $acoes['excluir'] = ['url' => $base . '/apagar'];
        }

        return $acoes;
    }

    /**
     * Token CSRF da sessão só se alguma das listas tem publicar/despublicar (as únicas ações que o JS envia por POST).
     *
     * @param list<array<string, mixed>> $listas
     */
    private function csrfSe(SessaoAtiva $sessao, array $listas): string
    {
        foreach ($listas as $acoes) {
            if (isset($acoes['publicar']) || isset($acoes['despublicar'])) {
                return $this->csrf->daSessao($sessao->idHash);
            }
        }

        return '';
    }

    private function estado(SessaoAtiva $sessao, Request $r): JsonResponse
    {
        $token = $r->request->get('_csrf');
        if (!$this->rede->origemConfere($r) || !is_string($token) || !$this->csrf->conferirSessao($sessao->idHash, $token)) {
            return self::json(['erro' => 'pedido_recusado'], Response::HTTP_FORBIDDEN);
        }
        $id = $this->id($r->request->get('objeto'));
        $acao = $r->request->get('acao');
        if ($id === null || !in_array($acao, ['publicar', 'despublicar'], true)) {
            return self::json(['erro' => 'pedido_invalido'], Response::HTTP_BAD_REQUEST);
        }
        $o = $this->servicos->objetos->porId($id);
        if ($o === null || $o->status === 'apagado') {
            return self::json(['erro' => 'nao_encontrado'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->pode($sessao, Acao::Publicar, $o)) {
            $this->servicos->auditoria->registrar('objeto.estado.negado', 'usuario:' . $sessao->usuario->id, $sessao->usuario->id, $o->id);

            return self::json(['erro' => 'sem_permissao'], Response::HTTP_FORBIDDEN);
        }
        $novoEstado = $acao === 'publicar' ? 'publicado' : 'privado';
        $versao = $this->id($r->request->get('versao'));
        if ($versao === null) {
            // Sem a versão lida pela barra não há bloqueio otimista: recusa em vez de sobrescrever uma mudança concorrente.
            return self::json(['erro' => 'versao_obrigatoria'], Response::HTTP_BAD_REQUEST);
        }
        if ($o->status !== $novoEstado) {
            try {
                $o = $this->servicos->objetos->atualizar($o->id, ['status' => $novoEstado], TelasDeObjetos::MOTIVO, $versao, false, $sessao->usuario->id);
            } catch (ConflitoDeVersao) {
                return self::json(['erro' => 'conflito'], Response::HTTP_CONFLICT);
            }
        }

        return self::json(['objeto' => $o->id, 'estado' => $o->status, 'versao' => $o->versao, 'sincronizado' => $this->sincronizar($o)]);
    }

    private function sincronizar(Objeto $o): bool
    {
        $site = $this->servicos->sites->porId($o->siteId);
        if ($site === null) {
            return false;
        }
        try {
            foreach ([$o, $o->paiId === null ? null : $this->servicos->objetos->porId($o->paiId)] as $alvo) {
                if ($alvo === null) {
                    continue;
                }
                $alvo->visivel(gmdate('c')) ? $this->servicos->publicador->publicar($site, $alvo) : $this->servicos->publicador->despublicar($site, $alvo);
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private function pode(SessaoAtiva $sessao, Acao $acao, Objeto $o): bool
    {
        return $this->servicos->permissoes->pode($sessao->usuario, $acao, $o);
    }

    /** Inteiro positivo de até 10 dígitos, ou null. */
    private function id(mixed $v): ?int
    {
        return (is_string($v) || is_int($v)) && preg_match('/^[1-9][0-9]{0,9}$/D', (string) $v) === 1 ? (int) $v : null;
    }

    /** @param array<string, mixed> $dados */
    private static function json(array $dados, int $status = Response::HTTP_OK): JsonResponse
    {
        $r = new JsonResponse($dados, $status);
        $r->headers->set('Cache-Control', 'no-store');

        return $r;
    }
}
