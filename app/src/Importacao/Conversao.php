<?php

declare(strict_types=1);

namespace Pbl\Importacao;

use Pbl\Arquivos\TiposDeArquivo;
use Pbl\Busca\Texto;
use Pbl\Caminhos;

/** Conversões puras de valor do PBL3 para o PBL4 (datas, endereços, nomes, estado). Sem banco e sem relógio. */
final class Conversao
{
    /** Estados do PBL3 (`cod_status`) => estados do PBL4. `apagado=1` (lixeira) vira `apagado` por cima de qualquer um. */
    public const STATUS = [1 => 'privado', 2 => 'publicado', 3 => 'rejeitado', 4 => 'submetido'];

    /**
     * Data do PBL3 (`YYYYMMDDHHMMSS`; aceita também `YYYYMMDDHHMM`, `YYYYMMDD` e um carimbo unix) como data e hora local em
     * `Y-m-d H:i:s`. Zero, vazio, data impossível e "sem fim" (ano a partir de 9000) viram `null`.
     */
    public static function dataLocal(mixed $valor): ?string
    {
        $v = trim((string) $valor);
        if ($v === '' || preg_match('/^\d+$/D', $v) !== 1 || (int) $v === 0) {
            return null;
        }
        $n = strlen($v);
        if ($n === 10) {
            $d = (new \DateTimeImmutable('@' . $v))->setTimezone(new \DateTimeZone('UTC'));

            return (int) $d->format('Y') >= 9000 ? null : $d->format('Y-m-d H:i:s');
        }
        if (!in_array($n, [8, 12, 14], true)) {
            return null;
        }
        $v = str_pad($v, 14, '0');
        [$a, $m, $d, $h, $i, $s] = [(int) substr($v, 0, 4), (int) substr($v, 4, 2), (int) substr($v, 6, 2), (int) substr($v, 8, 2), (int) substr($v, 10, 2), (int) substr($v, 12, 2)];
        if ($a < 1 || $a >= 9000 || !checkdate($m, $d, $a) || $h > 23 || $i > 59 || $s > 59) {
            return null;
        }

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $a, $m, $d, $h, $i, $s);
    }

    /** A vigência (`data_publicacao`, `data_validade`) em UTC, ISO 8601, a partir da hora local do `$fuso`; `null` se não houver. */
    public static function vigencia(mixed $valor, \DateTimeZone $fuso): ?string
    {
        $local = self::dataLocal($valor);
        if ($local === null) {
            return null;
        }

        return (new \DateTimeImmutable($local, $fuso))->setTimezone(new \DateTimeZone('UTC'))->format('c');
    }

    /** Valor de uma propriedade `data` do PBL4: só a data quando não há hora, senão data e hora (sem fuso, como o PBL4 guarda). */
    public static function dataDeCampo(mixed $valor): ?string
    {
        $local = self::dataLocal($valor);
        if ($local === null) {
            return null;
        }

        return str_ends_with($local, ' 00:00:00') ? substr($local, 0, 10) : $local;
    }

    /**
     * Endereço amigável válido no PBL4 (`a-z0-9` e hífens, segmentos separados por `/`). Parte do `url_amigavel` do PBL3 e, se ele
     * não serve, do título. Pode devolver vazio (sem nada aproveitável) e `index` (reservada) vira vazio.
     */
    public static function endereco(string $url, string $titulo): string
    {
        foreach ([$url, $titulo] as $origem) {
            $segmentos = [];
            foreach (explode('/', str_replace('\\', '/', $origem)) as $parte) {
                $s = self::slug($parte);
                if ($s !== '') {
                    $segmentos[] = $s;
                }
            }
            $e = substr(implode('/', $segmentos), 0, Caminhos::URL_TAMANHO_MAXIMO - 8);
            $e = rtrim($e, '/-');
            if ($e !== '' && $e !== 'index' && Caminhos::urlValida($e)) {
                return $e;
            }
        }

        return '';
    }

    public static function slug(string $texto): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', Texto::normalizar($texto)), '-');
    }

    /** Nome técnico (`^[a-z][a-z0-9_]*$`, até 64) a partir de um nome qualquer do PBL3. */
    public static function nomeTecnico(string $texto, string $reserva): string
    {
        $n = trim((string) preg_replace('/[^a-z0-9]+/', '_', Texto::normalizar($texto)), '_');
        if ($n === '') {
            $n = $reserva;
        }
        if (preg_match('/^[a-z]/', $n) !== 1) {
            $n = $reserva . '_' . $n;
        }

        return rtrim(substr($n, 0, 64), '_');
    }

    /** Login válido no PBL4 (3 a 64, minúsculas, números, `.`, `-`, `_`). Vazio se o original não tem nada aproveitável. */
    public static function login(string $login): string
    {
        $l = (string) preg_replace('/[^a-z0-9._-]+/', '', mb_strtolower(trim(Texto::normalizar($login))));
        $l = trim($l, '._-');
        if (strlen($l) < 3) {
            return '';
        }

        return substr($l, 0, 64) === $l ? $l : rtrim(substr($l, 0, 64), '._-');
    }

    /** O arquivo de `tbl_blob.arquivo` (nome original) é uma imagem para o PBL4? Pela extensão. */
    public static function ehImagem(string $nome): bool
    {
        return (TiposDeArquivo::da(self::extensao($nome))['imagem'] ?? false) === true;
    }

    public static function extensao(string $nome): string
    {
        return strtolower(pathinfo(str_replace('\\', '/', $nome), PATHINFO_EXTENSION));
    }

    /**
     * Onde o PBL3 guarda o arquivo de um blob: `<raiz>/<pasta>/<codigo>.<extensao>`, com a pasta `0000` para códigos de até 3
     * dígitos e, nos demais, o milhar com 4 dígitos (`Blob::identificaPasta` do PBL3).
     */
    public static function caminhoDoBlob(string $raiz, int $codigo, string $nomeOriginal): string
    {
        $pasta = strlen((string) $codigo) <= 3 ? '0000' : str_pad((string) intdiv($codigo, 1000), 4, '0', STR_PAD_LEFT);
        $ext = self::extensao($nomeOriginal);

        return rtrim($raiz, '/\\') . '/' . $pasta . '/' . $codigo . ($ext === '' ? '' : '.' . $ext);
    }
}
