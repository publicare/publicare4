<?php

declare(strict_types=1);

namespace Pbl\Testes;

/** Utilidades de teste: pasta temporária que se apaga sozinha. */
final class Ajuda
{
    public static function pastaTemporaria(): string
    {
        $pasta = sys_get_temp_dir() . '/pbl4-teste-' . bin2hex(random_bytes(6));
        mkdir($pasta, 0775, true);

        return $pasta;
    }

    public static function apagar(string $pasta): void
    {
        if (!is_dir($pasta)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pasta, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($pasta);
    }

    /** Raiz de projeto de teste com a pele básica disponível em app/recursos. */
    public static function raizDeProjeto(): string
    {
        $raiz = self::pastaTemporaria();
        mkdir($raiz . '/app/recursos/peles', 0775, true);
        $origem = dirname(__DIR__) . '/app/recursos/peles';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($origem, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $item) {
            $destino = $raiz . '/app/recursos/peles/' . substr($item->getPathname(), strlen($origem) + 1);
            $item->isDir() ? @mkdir($destino, 0775, true) : copy($item->getPathname(), $destino);
        }

        return $raiz;
    }

    /** A conexão do banco da instalação de teste: o SQLite da raiz ou o banco de servidor de `PBL_BANCO_*`. */
    public static function conexao(\Pbl\Caminhos $caminhos): \Doctrine\DBAL\Connection
    {
        return \Pbl\Banco\Conexao::abrir((new \Pbl\Banco\CarregadorDeConfigBanco($caminhos))->carregar());
    }

    /** Para os testes que dependem do arquivo SQLite (cópia, restauração, demo): nos bancos de servidor eles ainda não se aplicam (plano, fase f). */
    public static function pularSeBancoDeServidor(string $motivo = 'Depende do arquivo SQLite; cópia, restauração e atualização em bancos de servidor ficam para a fase f do plano multibanco.'): void
    {
        if (in_array(getenv('PBL_BANCO_DRIVER') ?: 'sqlite', ['mysql', 'pgsql'], true)) {
            \PHPUnit\Framework\Assert::markTestSkipped($motivo);
        }
    }
}
