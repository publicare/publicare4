<?php

declare(strict_types=1);

namespace Pbl\Backup;

use Pbl\Caminhos;

/**
 * Valida (e prepara) a pasta de cópias (ADR 0032): sem `..`, existe ou é criável, gravável, fora de código (`app/`, `vendor/`,
 * `public/`) e do estado interno `dados/backup/`, nunca a raiz nem um ancestral dela. Dentro da raiz do site ganha negação web.
 */
final class ValidadorDePasta
{
    /** @throws ErroDeBackup com o motivo em português */
    public static function resolver(Caminhos $caminhos, string $pasta): string
    {
        $pasta = trim($pasta) === '' ? ConfigDeBackup::PASTA_PADRAO : trim($pasta);
        if (str_contains($pasta, "\0") || in_array('..', explode('/', str_replace('\\', '/', $pasta)), true)) {
            throw new ErroDeBackup('A pasta não pode conter "..".');
        }
        $caminho = str_starts_with($pasta, '/') ? $pasta : $caminhos->raiz . '/' . $pasta;
        if (is_link($caminho)) {
            throw new ErroDeBackup('A pasta de cópias não pode ser um link simbólico.');
        }
        if (!is_dir($caminho) && !@mkdir($caminho, 0750, true) && !is_dir($caminho)) {
            throw new ErroDeBackup('A pasta de cópias não existe e não pôde ser criada.');
        }
        $real = realpath($caminho);
        $raiz = realpath($caminhos->raiz);
        if ($real === false || $raiz === false) {
            throw new ErroDeBackup('Pasta de cópias inválida.');
        }
        if ($real === '/' || $real === $raiz || str_starts_with($raiz . '/', $real . '/')) {
            throw new ErroDeBackup('A pasta de cópias não pode ser a raiz do site nem uma pasta acima dela.');
        }
        foreach (['app', 'vendor', 'public', 'dados/backup', 'dados/cache', 'dados/uploads', 'dados/config'] as $proibida) {
            $p = $raiz . '/' . $proibida;
            if ($real === $p || str_starts_with($real, $p . '/')) {
                throw new ErroDeBackup("A pasta de cópias não pode ficar dentro de {$proibida}/.");
            }
        }
        if (!self::gravavel($real)) {
            throw new ErroDeBackup('A pasta de cópias não tem permissão de escrita.');
        }
        if (str_starts_with($real, $raiz . '/')) {
            self::negarNaWeb($real);
        }

        return $real;
    }

    /** Espaço livre em bytes (null se o sistema não informa). */
    public static function espacoLivre(string $pasta): ?int
    {
        $v = @disk_free_space($pasta);

        return $v === false ? null : (int) $v;
    }

    private static function gravavel(string $pasta): bool
    {
        $sonda = $pasta . '/.sonda-' . bin2hex(random_bytes(4));
        if (@file_put_contents($sonda, 'x') === false) {
            return false;
        }
        @unlink($sonda);

        return true;
    }

    private static function negarNaWeb(string $pasta): void
    {
        $regra = "# Gerado pelo PBL4: cópias de segurança nunca são públicas.\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
        if (!is_file($pasta . '/.htaccess')) {
            @file_put_contents($pasta . '/.htaccess', $regra);
            @chmod($pasta . '/.htaccess', 0644);
        }
        if (!is_file($pasta . '/index.html')) {
            @file_put_contents($pasta . '/index.html', '');
            @chmod($pasta . '/index.html', 0644);
        }
    }
}
