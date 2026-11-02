<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

use Pbl\Caminhos;

/**
 * O que a última atualização concluída deixou para permitir voltar atrás: `dados/atualizacao/aplicada.json`. Fica ao lado de
 * `anterior/` (o código antigo) e vale até a próxima atualização, que apaga `dados/atualizacao/`. Nada aqui é lido como código;
 * JSON com tipo errado vira "sem registro".
 *
 * @phpstan-type Dados array{de: string, para: string, em: int, backup: string, backup_sha256: string, schema_antes: int|null, schema_depois: int|null, itens: list<string>, sem_anterior: list<string>, revertida: bool, revertida_em: int}
 */
final class RegistroDaAtualizacao
{
    public static function arquivo(Caminhos $caminhos): string
    {
        return $caminhos->dados() . '/atualizacao/aplicada.json';
    }

    /** @param Dados $dados */
    public static function gravar(Caminhos $caminhos, array $dados): void
    {
        $arquivo = self::arquivo($caminhos);
        $tmp = $arquivo . '.tmp';
        if (file_put_contents($tmp, json_encode($dados, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === false || !@rename($tmp, $arquivo)) {
            @unlink($tmp);
            throw new ErroDeAtualizacao('Não foi possível gravar dados/atualizacao/aplicada.json');
        }
    }

    /** @return Dados|null */
    public static function ler(Caminhos $caminhos): ?array
    {
        $arquivo = self::arquivo($caminhos);
        if (!is_file($arquivo) || is_link($arquivo) || filesize($arquivo) > 65536) {
            return null;
        }
        $d = json_decode((string) file_get_contents($arquivo), true);
        if (!is_array($d)) {
            return null;
        }
        $lista = static function (mixed $v): ?array {
            if (!is_array($v)) {
                return null;
            }
            $r = [];
            foreach ($v as $item) {
                if (!is_string($item) || !in_array($item, Pacote::ITENS, true)) {
                    return null;
                }
                $r[] = $item;
            }

            return $r;
        };
        $itens = $lista($d['itens'] ?? null);
        $semAnterior = $lista($d['sem_anterior'] ?? null);
        $inteiro = static fn (mixed $v): ?int => is_int($v) ? $v : null;
        if (
            !is_string($d['de'] ?? null) || !Semver::valida($d['de']) || !is_string($d['para'] ?? null) || !Semver::valida($d['para'])
            || !is_int($d['em'] ?? null) || !is_string($d['backup'] ?? null) || !is_string($d['backup_sha256'] ?? null)
            || $itens === null || $semAnterior === null
        ) {
            return null;
        }

        return [
            'de' => $d['de'], 'para' => $d['para'], 'em' => $d['em'], 'backup' => $d['backup'], 'backup_sha256' => $d['backup_sha256'],
            'schema_antes' => $inteiro($d['schema_antes'] ?? null), 'schema_depois' => $inteiro($d['schema_depois'] ?? null),
            'itens' => $itens, 'sem_anterior' => $semAnterior,
            'revertida' => ($d['revertida'] ?? false) === true, 'revertida_em' => is_int($d['revertida_em'] ?? null) ? $d['revertida_em'] : 0,
        ];
    }
}
