<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

use Pbl\Caminhos;

/**
 * Roda o MESMO comando da linha de comando (`php bin/pbl atualizar`) num processo separado, em segundo plano. Nunca no processo da
 * requisição: a atualização demora e troca o código que está rodando. O shell só serve para soltar o processo (`&`, sem esperar) e
 * para gravar o código de saída; nenhum dado da requisição entra na linha de comando (só caminhos montados aqui, passados como
 * argumentos do shell, nunca concatenados).
 */
final class LancadorDeProcesso implements Lancador
{
    public function __construct(private readonly Caminhos $caminhos, private readonly ?string $php = null)
    {
    }

    /**
     * O PHP de linha de comando. Sob PHP-FPM ou Apache `PHP_BINARY` não é o `php` e não serve; nesse caso procura ao lado e, se não
     * achar, devolve null (o administrador pode indicar o caminho em `dados/config/atualizacao.json`, chave `php`).
     */
    public static function descobrirPhp(string $configurado = ''): ?string
    {
        $candidatos = [$configurado, PHP_BINARY, PHP_BINDIR . '/php'];
        foreach ($candidatos as $c) {
            if ($c !== '' && str_starts_with($c, '/') && is_file($c) && is_executable($c) && preg_match('/^php[0-9.]*$/D', basename($c)) === 1) {
                return $c;
            }
        }

        return null;
    }

    public static function disponivel(): bool
    {
        return function_exists('proc_open') && !in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
    }

    public function iniciar(string $arquivoDeSaida, string $arquivoDeCodigo): void
    {
        $php = $this->php ?? self::descobrirPhp();
        if ($php === null) {
            throw new ErroDeAtualizacao('Não achei o PHP de linha de comando neste servidor. Indique o caminho em "php" no arquivo dados/config/atualizacao.json ou atualize pelo terminal.');
        }
        if (!self::disponivel()) {
            throw new ErroDeAtualizacao('Este servidor não permite iniciar processos (proc_open desligado). Atualize pelo terminal: php bin/pbl atualizar');
        }
        $script = $this->caminhos->raiz . '/bin/pbl';
        if (!is_file($script)) {
            throw new ErroDeAtualizacao('Não achei bin/pbl nesta instalação.');
        }
        // $0 = php, $1 = script, $2 = saída, $3 = código. O código só aparece depois de completo (mv é atômico).
        $interno = '"$0" "$1" atualizar >"$2" 2>&1 </dev/null; c=$?; echo "$c" >"$3.tmp" && mv "$3.tmp" "$3"';
        $comando = 'nohup sh -c ' . escapeshellarg($interno) . ' '
            . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($arquivoDeSaida) . ' ' . escapeshellarg($arquivoDeCodigo)
            . ' >/dev/null 2>&1 &';
        $proc = @proc_open(['sh', '-c', $comando], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $this->caminhos->raiz);
        if (!is_resource($proc)) {
            throw new ErroDeAtualizacao('Não foi possível iniciar o processo da atualização.');
        }
        proc_close($proc); // o shell já voltou (o comando ficou em segundo plano)
    }
}
