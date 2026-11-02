<?php

declare(strict_types=1);

namespace Pbl\Arquivos;

use Pbl\Servicos;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Rotas públicas (sem sessão) que entregam o arquivo de um objeto publicado e vigente, sob o prefixo reservado `/_pbl/`
 * (como `EnvioDeFormulario`, tratada pela `Aplicacao` antes do `Painel`): fluxo 7, ADR 0014.
 *
 * - `GET /_pbl/arquivo/{id}[/qualquer-coisa]`: o arquivo original.
 * - `GET /_pbl/arquivo/{id}/miniatura/{tamanho}[/qualquer-coisa]`: miniatura (só de imagem; tamanho de `ServicoDeMiniaturas::TAMANHOS`).
 *
 * Privado, fora do ar, vencido ou de outro site: 404 (a mesma resposta, para não revelar o que existe). `Content-Type` vem
 * sempre da whitelist (nunca do que foi detectado no upload, embora sejam o mesmo valor), com `nosniff` e `Content-Disposition`
 * seguro (nome saneado, `attachment` para o que não é feito para abrir no navegador). Suporta `Range` de um único trecho.
 */
final class ArquivoController
{
    /** Base da URL pública de um arquivo (sem a barra final); usada também por quem monta o link (administração, molde). */
    public const PREFIXO = '/_pbl/arquivo';
    private const ROTA_MINIATURA = '#^/_pbl/arquivo/([1-9][0-9]{0,9})/miniatura/([0-9]{1,4})(?:/[^/]{1,150})?$#D';
    private const ROTA_ARQUIVO = '#^/_pbl/arquivo/([1-9][0-9]{0,9})(?:/[^/]{1,150})?$#D';

    public function __construct(private readonly Servicos $servicos, private readonly int $siteId)
    {
    }

    public static function atende(Request $requisicao): bool
    {
        $caminho = rtrim($requisicao->getPathInfo(), '/');

        return preg_match(self::ROTA_MINIATURA, $caminho) === 1 || preg_match(self::ROTA_ARQUIVO, $caminho) === 1;
    }

    public function tratar(Request $requisicao): Response
    {
        if (!in_array($requisicao->getMethod(), ['GET', 'HEAD'], true)) {
            return $this->naoEncontrado();
        }
        $caminho = rtrim($requisicao->getPathInfo(), '/');
        if (preg_match(self::ROTA_MINIATURA, $caminho, $m) === 1) {
            return $this->miniatura($requisicao, (int) $m[1], (int) $m[2]);
        }
        if (preg_match(self::ROTA_ARQUIVO, $caminho, $m) === 1) {
            return $this->original($requisicao, (int) $m[1]);
        }

        return $this->naoEncontrado();
    }

    private function original(Request $requisicao, int $id): Response
    {
        $arquivo = $this->arquivoVisivel($id);
        if ($arquivo === null) {
            return $this->naoEncontrado();
        }
        $caminho = (new ArmazenamentoDeArquivos($this->servicos->caminhos))->caminho($arquivo->chave, $arquivo->extensao);
        if (!is_file($caminho)) {
            return $this->naoEncontrado();
        }
        $tipo = TiposDeArquivo::da($arquivo->extensao);

        return $this->arquivoResposta($requisicao, $caminho, $arquivo->mime, $arquivo->nome, $tipo['inline'] ?? false, $arquivo->sha256);
    }

    private function miniatura(Request $requisicao, int $id, int $tamanho): Response
    {
        $arquivo = $this->arquivoVisivel($id);
        if ($arquivo === null || !ServicoDeMiniaturas::tamanhoValido($tamanho)) {
            return $this->naoEncontrado();
        }
        $caminho = $this->servicos->miniaturas()->caminho($arquivo, $tamanho);
        if ($caminho === null) {
            return $this->naoEncontrado();
        }

        return $this->arquivoResposta($requisicao, $caminho, 'image/jpeg', $arquivo->nome . '-' . $tamanho . '.jpg', true, $arquivo->sha256 . '-' . $tamanho);
    }

    /** O arquivo existe, é deste site e o objeto ao qual foi enviado está publicado e vigente agora? */
    private function arquivoVisivel(int $id): ?Arquivo
    {
        $arquivo = $this->servicos->arquivos->porId($id);
        if ($arquivo === null || $arquivo->siteId !== $this->siteId) {
            return null;
        }
        $objeto = $this->servicos->objetos->porId($arquivo->objetoId);
        if ($objeto === null || $objeto->siteId !== $this->siteId || !$objeto->visivel(gmdate('c'))) {
            return null;
        }

        return $arquivo;
    }

    private function arquivoResposta(Request $requisicao, string $caminho, string $mime, string $nomeParaBaixar, bool $inline, string $etag): Response
    {
        // autoEtag desligado: o ETag é o sha256 do conteúdo (ou dele + o tamanho da miniatura), não um hash do arquivo em disco.
        $resposta = new BinaryFileResponse($caminho, Response::HTTP_OK, [], true, null, false, true);
        $resposta->headers->set('Content-Type', $mime);
        $resposta->headers->set('X-Content-Type-Options', 'nosniff');
        $resposta->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $resposta->setEtag($etag);
        $resposta->setContentDisposition($inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->nomeAscii($nomeParaBaixar), $this->nomeAscii($nomeParaBaixar));
        // prepare() decide 304 (ETag/data) e Range (trecho único) a partir da requisição; o index.php só chama send().
        $resposta->prepare($requisicao);

        return $resposta;
    }

    /** `Content-Disposition` aceita só ASCII no `filename` básico; o nome de verdade (com acento) vai em `filename*` (RFC 5987), que o `setContentDisposition` do Symfony já monta a partir do nome dado. */
    private function nomeAscii(string $nome): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $nome);
        $ascii = is_string($ascii) ? preg_replace('/[^A-Za-z0-9._-]/', '_', $ascii) : null;

        return $ascii === null || $ascii === '' ? 'arquivo' : $ascii;
    }

    private function naoEncontrado(): Response
    {
        return new Response('Não encontrado', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Content-Type-Options' => 'nosniff']);
    }
}
