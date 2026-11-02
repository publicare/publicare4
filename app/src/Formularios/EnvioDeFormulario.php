<?php

declare(strict_types=1);

namespace Pbl\Formularios;

use Pbl\Acesso\Rede;
use Pbl\Conteudo\ConflitoDeArvore;
use Pbl\Conteudo\ViolacaoDeRegra;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rotas públicas (sem sessão) de envio de formulário, sob o prefixo reservado `/_pbl/` (ADR 0013):
 *
 * - `POST /_pbl/enviar/{id}`: recebe o envio. 303 para `/_pbl/enviado/{id}` quando dá certo (PRG); 422 com o formulário refeito
 *   quando há erro de preenchimento.
 * - `GET /_pbl/enviado/{id}`: a mensagem de sucesso do formulário.
 *
 * Ordem das defesas, da mais barata para a mais cara, e nenhuma resposta diz mais do que "não encontrado" sobre formulário que
 * não existe, está fechado, fora do ar, de outro site ou mal configurado: formulário inexistente (404), limite por IP (429),
 * tamanho (413), origem e token (403), campo-armadilha (finge sucesso, não grava), campos obrigatórios e tipos (422). Só os campos
 * declarados no formulário são lidos; o resto do corpo é ignorado. O envio vira um objeto **privado**, sem autor (anônimo), pelo
 * `ObjetoRepositorio`, que aplica as regras do tipo; a auditoria guarda quem (anônimo), qual formulário e qual objeto, nunca valores.
 */
final class EnvioDeFormulario
{
    public const MAX_CORPO = 65536;
    private const ROTA = '#^/_pbl/(enviar|enviado)/([1-9][0-9]{0,9})$#D';

    public function __construct(private readonly Servicos $servicos, private readonly Rede $rede, private readonly int $siteId)
    {
    }

    public static function atende(Request $requisicao): bool
    {
        return preg_match(self::ROTA, rtrim($requisicao->getPathInfo(), '/')) === 1;
    }

    public function tratar(Request $requisicao): Response
    {
        if (preg_match(self::ROTA, rtrim($requisicao->getPathInfo(), '/'), $m) !== 1) {
            return $this->naoEncontrado();
        }
        $enviando = $m[1] === 'enviar';
        $id = (int) $m[2];
        $metodo = $requisicao->getMethod();
        if ($enviando ? $metodo !== 'POST' : !in_array($metodo, ['GET', 'HEAD'], true)) {
            $r = $this->pagina('Método não permitido', '<p>Método não permitido.</p>', Response::HTTP_METHOD_NOT_ALLOWED);
            $r->headers->set('Allow', $enviando ? 'POST' : 'GET, HEAD');

            return $r;
        }
        $formulario = $this->servicos->formularios->porId($this->siteId, $id);
        if ($formulario === null) {
            return $this->naoEncontrado();
        }
        if (!$enviando) {
            return $this->pagina($formulario->titulo, '<p>' . FormularioHtml::e($formulario->mensagem) . '</p>');
        }

        $ip = $this->rede->ip($requisicao);
        $limite = $this->servicos->limiteDeEnvios();
        $espera = $limite->bloqueadoPor($id, $ip);
        if ($espera !== null) {
            $r = $this->pagina('Muitos envios', '<p>Muitos envios em pouco tempo. Aguarde alguns minutos e tente de novo.</p>', Response::HTTP_TOO_MANY_REQUESTS);
            $r->headers->set('Retry-After', (string) $espera);

            return $r;
        }
        $limite->registrar($id, $ip);

        $campos = $requisicao->request->all();
        if ((int) $requisicao->headers->get('Content-Length', '0') > self::MAX_CORPO || strlen(http_build_query($campos)) > self::MAX_CORPO) {
            return $this->pagina('Envio grande demais', '<p>O envio é grande demais.</p>', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        $token = $campos[FormularioHtml::CSRF] ?? null;
        if (!$this->rede->origemConfere($requisicao) || !is_string($token) || !$this->servicos->csrf()->conferirFormulario($id, $token)) {
            return $this->pagina('Pedido recusado', '<p>O pedido não pôde ser confirmado. Volte, recarregue a página do formulário e tente de novo.</p>', Response::HTTP_FORBIDDEN);
        }
        $armadilha = $campos[FormularioHtml::ARMADILHA] ?? '';
        if ($armadilha !== '') {
            return $this->sucesso($id); // robô: parece que deu certo, nada é gravado
        }

        [$dados, $valores, $erros] = $this->ler($formulario, $campos);
        if ($erros === []) {
            try {
                $objeto = $this->servicos->objetos->criarDentro(
                    $formulario->siteId,
                    $formulario->classeDoEnvio,
                    $formulario->destinoId,
                    'Envio ' . gmdate('Y-m-d H:i:s') . ' (formulário ' . $formulario->id . ')',
                    'envio-' . bin2hex(random_bytes(8)),
                    $dados,
                    'privado',
                );
                $this->servicos->auditoria->registrar(
                    'formulario.envio',
                    'anonimo',
                    null,
                    $objeto->id,
                    ['formulario' => $formulario->id, 'classe' => $formulario->classeDoEnvio->nome],
                    $limite->hashDoIp($ip),
                );

                return $this->sucesso($id);
            } catch (ConflitoDeArvore) {
                return $this->naoEncontrado();
            } catch (\InvalidArgumentException $e) {
                // A mensagem do repositório pode citar nomes técnicos: só o rótulo do campo vai para a pessoa.
                $nome = $e instanceof ViolacaoDeRegra ? $e->campo : (preg_match('/Propriedade ([a-z][a-z0-9_]*)/', $e->getMessage(), $p) === 1 ? $p[1] : null);
                $erros[] = $this->erroDoCampo($formulario, $nome);
            }
        }

        return $this->pagina(
            $formulario->titulo,
            FormularioHtml::renderizar($formulario, $this->servicos->csrf()->paraFormulario($id), $valores, $erros),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * Lê só os campos declarados. Valor que não é texto simples (lista, por exemplo) conta como vazio.
     *
     * @param array<array-key, mixed> $campos
     * @return array{0: array<string, string|int|float>, 1: array<string, string>, 2: list<string>} dados, valores digitados, erros
     */
    private function ler(Formulario $f, array $campos): array
    {
        $dados = [];
        $valores = [];
        $erros = [];
        foreach ($f->campos as $c) {
            $bruto = $campos[$c['nome']] ?? '';
            $texto = is_string($bruto) ? $bruto : '';
            $rotulo = $c['rotulo'];
            if (!mb_check_encoding($texto, 'UTF-8')) {
                $erros[] = "O campo «{$rotulo}» tem caracteres inválidos.";
                continue;
            }
            $texto = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $texto);
            $valores[$c['nome']] = $c['tipo'] === 'html' ? $texto : trim($texto);
            if ($c['tipo'] === 'booleano') {
                $marcado = in_array($texto, ['1', 'on', 'true'], true);
                $valores[$c['nome']] = $marcado ? '1' : '';
                if ($c['obrigatorio'] && !$marcado) {
                    $erros[] = "Marque «{$rotulo}».";
                }
                $dados[$c['nome']] = $marcado ? 1 : 0;
                continue;
            }
            $v = $valores[$c['nome']];
            if (trim($v) === '') {
                unset($valores[$c['nome']]);
                if ($c['obrigatorio']) {
                    $erros[] = "Preencha o campo «{$rotulo}».";
                }
                continue;
            }
            $limite = $c['tipo'] === 'html' ? Formulario::MAX_HTML : Formulario::MAX_TEXTO;
            if (mb_strlen($v) > $limite) {
                $erros[] = "O campo «{$rotulo}» é grande demais (máximo de {$limite} caracteres).";
                continue;
            }
            if ($c['tipo'] === 'numero') {
                $n = str_replace(',', '.', $v);
                if (preg_match('/^-?[0-9]{1,15}(\.[0-9]{1,10})?$/D', $n) !== 1) {
                    $erros[] = "O campo «{$rotulo}» precisa ser um número.";
                    continue;
                }
                $dados[$c['nome']] = str_contains($n, '.') ? (float) $n : (int) $n;
                continue;
            }
            $dados[$c['nome']] = $v;
        }

        return [$dados, $valores, $erros];
    }

    private function erroDoCampo(Formulario $f, ?string $nome): string
    {
        foreach ($f->campos as $c) {
            if ($c['nome'] === $nome) {
                return "O campo «{$c['rotulo']}» não é válido.";
            }
        }

        return 'Não foi possível receber o envio. Confira os campos e tente de novo.';
    }

    private function sucesso(int $id): Response
    {
        $r = new RedirectResponse('/_pbl/enviado/' . $id, Response::HTTP_SEE_OTHER);
        $r->headers->set('Cache-Control', 'no-store');

        return $r;
    }

    private function naoEncontrado(): Response
    {
        return new Response('Não encontrado', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store']);
    }

    /** Página mínima com cabeçalhos de segurança; o estilo é só o necessário e inline porque não há folha de estilos do site aqui. */
    private function pagina(string $titulo, string $corpo, int $status = Response::HTTP_OK): Response
    {
        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>' . FormularioHtml::e($titulo) . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;max-width:40rem;margin:2rem auto;padding:0 1rem;line-height:1.5}.pbl-campo{margin:.75rem 0}'
            . 'label{display:block}input[type=text],input[type=date],textarea{width:100%;box-sizing:border-box}.pbl-erros{color:#8a1c1c}</style>'
            . '</head><body><main><h1>' . FormularioHtml::e($titulo) . '</h1>' . $corpo . '</main></body></html>';

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }
}
