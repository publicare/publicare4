<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

use Pbl\Conteudo\Dominio;

/**
 * Baixa por HTTPS, com verificação do certificado, tempo limite, tamanho máximo e redirecionamentos só dentro do mesmo host.
 * O endereço vem da configuração do administrador e, para o pacote, do manifesto **assinado**, que só pode apontar para o mesmo host.
 */
final class BaixadorHttps implements Baixador
{
    private const MAX_REDIRECIONAMENTOS = 3;

    public function __construct(private readonly string $agente = 'PBL4')
    {
    }

    public function obter(string $origem, int $maximoBytes): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'pbl');
        if ($caminho === false) {
            throw new ErroDeAtualizacao('Não foi possível criar um arquivo temporário');
        }
        try {
            $this->copiar($origem, $caminho, $maximoBytes);

            return (string) file_get_contents($caminho);
        } finally {
            @unlink($caminho);
        }
    }

    public function copiar(string $origem, string $arquivoDestino, int $maximoBytes): void
    {
        $url = $this->validar($origem);
        $host = (string) parse_url($url, PHP_URL_HOST);
        for ($salto = 0; $salto <= self::MAX_REDIRECIONAMENTOS; $salto++) {
            $contexto = stream_context_create([
                'http' => ['method' => 'GET', 'timeout' => 20, 'follow_location' => 0, 'ignore_errors' => true, 'header' => "User-Agent: {$this->agente}\r\nAccept: */*\r\n"],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
            ]);
            $entrada = @fopen($url, 'rb', false, $contexto);
            if ($entrada === false) {
                throw new ErroDeAtualizacao('Não foi possível conectar ao servidor de atualizações');
            }
            /** @var list<string> $cabecalhos */
            $cabecalhos = stream_get_meta_data($entrada)['wrapper_data'] ?? [];
            $status = isset($cabecalhos[0]) && preg_match('#^HTTP/\S+ (\d{3})#', (string) $cabecalhos[0], $m) === 1 ? (int) $m[1] : 0;
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                fclose($entrada);
                $url = $this->proximo($cabecalhos, $url, $host);
                continue;
            }
            if ($status !== 200) {
                fclose($entrada);
                throw new ErroDeAtualizacao("O servidor de atualizações respondeu {$status}");
            }

            $this->gravar($entrada, $arquivoDestino, $maximoBytes);

            return;
        }

        throw new ErroDeAtualizacao('Redirecionamentos demais');
    }

    public function resolver(string $base, string $relativo): string
    {
        $this->validar($base);
        if (str_contains($relativo, '..') || str_contains($relativo, '\\') || preg_match('/[\x00-\x1F]/', $relativo) === 1) {
            throw new ErroDeAtualizacao('Endereço do pacote inválido');
        }
        $partes = parse_url($base);
        $hostBase = is_array($partes) ? (string) ($partes['host'] ?? '') : '';
        $raiz = 'https://' . $hostBase;
        if (str_starts_with($relativo, 'https://')) {
            $outro = parse_url($relativo);
            if (!is_array($outro) || strtolower((string) ($outro['host'] ?? '')) !== strtolower($hostBase) || isset($outro['user']) || isset($outro['port'])) {
                throw new ErroDeAtualizacao('O pacote precisa estar no mesmo servidor do manifesto');
            }

            return $relativo;
        }
        if (str_contains($relativo, '://')) {
            throw new ErroDeAtualizacao('Endereço do pacote inválido');
        }
        if (str_starts_with($relativo, '/')) {
            return $raiz . $relativo;
        }
        $pasta = substr((string) ($partes['path'] ?? '/'), 0, (int) strrpos((string) ($partes['path'] ?? '/'), '/') + 1);

        return $raiz . $pasta . $relativo;
    }

    private function validar(string $url): string
    {
        $p = parse_url($url);
        if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || !isset($p['host']) || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && $p['port'] !== 443)) {
            throw new ErroDeAtualizacao('As atualizações só podem ser baixadas por HTTPS (https://servidor/caminho)');
        }
        if (!Dominio::hostValido(strtolower($p['host'])) || strlen($url) > 500 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw new ErroDeAtualizacao('Endereço de atualização inválido');
        }

        return $url;
    }

    /** @param list<string> $cabecalhos */
    private function proximo(array $cabecalhos, string $atual, string $host): string
    {
        foreach ($cabecalhos as $linha) {
            if (stripos((string) $linha, 'location:') === 0) {
                $destino = trim(substr((string) $linha, 9));
                $absoluto = str_starts_with($destino, '/') ? 'https://' . $host . $destino : $destino;
                $novo = $this->validar($absoluto);
                if (strtolower((string) parse_url($novo, PHP_URL_HOST)) !== strtolower($host)) {
                    throw new ErroDeAtualizacao('Redirecionamento para outro servidor recusado');
                }

                return $novo;
            }
        }

        throw new ErroDeAtualizacao('Redirecionamento sem destino');
    }

    /** @param resource $entrada */
    private function gravar($entrada, string $arquivoDestino, int $maximoBytes): void
    {
        $saida = @fopen($arquivoDestino, 'wb');
        if ($saida === false) {
            fclose($entrada);
            throw new ErroDeAtualizacao('Não foi possível gravar o arquivo baixado');
        }
        $total = 0;
        try {
            while (!feof($entrada)) {
                $trecho = fread($entrada, 65536);
                if ($trecho === false) {
                    throw new ErroDeAtualizacao('A conexão caiu durante o download');
                }
                $total += strlen($trecho);
                if ($total > $maximoBytes) {
                    throw new ErroDeAtualizacao('O arquivo baixado é maior que o esperado');
                }
                fwrite($saida, $trecho);
            }
        } catch (ErroDeAtualizacao $e) {
            fclose($entrada);
            fclose($saida);
            @unlink($arquivoDestino);
            throw $e;
        }
        fclose($entrada);
        fclose($saida);
    }
}
