<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

use Pbl\Caminhos;
use Pbl\Instalacao\Requisitos;

/**
 * Roda o MESMO comando da linha de comando (`php bin/pbl atualizar` ou `php bin/pbl reverter --confirmar`) num processo separado,
 * em segundo plano. Nunca no processo da requisição: a atualização (ou a volta atrás) demora e troca o código que está rodando. O
 * shell só serve para soltar o processo (`&`, sem esperar) e para gravar o código de saída; nenhum dado da requisição entra na
 * linha de comando (só caminhos e o nome do comando montados aqui, passados como argumentos do shell, nunca concatenados). O
 * painel já exigiu senha e confirmação antes de chamar `iniciar`, então `--confirmar` aqui não é um atalho sem confirmação.
 */
final class LancadorDeProcesso implements Lancador
{
    public function __construct(private readonly Caminhos $caminhos, private readonly ?string $php = null)
    {
    }

    /**
     * O PHP de linha de comando. Sob PHP-FPM ou Apache `PHP_BINARY` não é o `php` (ex.: `/usr/sbin/php-fpm8.3`) e não serve; nesse caso
     * procura em `PHP_BINDIR` (`php` e `phpX.Y`) e, se não achar, devolve null (o administrador pode indicar o caminho em
     * `dados/config/atualizacao.json`, chave `php`). Os filtros que não tocam o disco (caminho absoluto, nome `php*`, dentro do
     * `open_basedir`) vêm ANTES de qualquer `is_file`, para nunca testar um caminho proibido (hospedagem comum gera aviso).
     * Os três últimos parâmetros existem para teste; em produção valem os do PHP em execução.
     */
    public static function descobrirPhp(string $configurado = '', ?string $openBasedir = null, string $binario = PHP_BINARY, string $pastaDeBinarios = PHP_BINDIR): ?string
    {
        $openBasedir ??= (string) ini_get('open_basedir');
        $versao = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        foreach ([$configurado, $binario, $pastaDeBinarios . '/php', $pastaDeBinarios . '/php' . $versao] as $c) {
            if ($c === '' || !str_starts_with($c, '/') || preg_match('/^php[0-9.]*$/D', basename($c)) !== 1 || !Requisitos::dentroDoOpenBasedir($c, $openBasedir)) {
                continue;
            }
            // @: mesmo dentro do open_basedir um caminho pode falhar por permissão; aqui só interessa "é utilizável?".
            if (@is_file($c) && @is_executable($c)) {
                return $c;
            }
        }

        return null;
    }

    public static function disponivel(): bool
    {
        return function_exists('proc_open') && !in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
    }

    public function iniciar(string $arquivoDeSaida, string $arquivoDeCodigo, string $comando = 'atualizar'): void
    {
        if (!in_array($comando, ['atualizar', 'reverter'], true)) {
            throw new ErroDeAtualizacao('Comando desconhecido.');
        }
        $php = $this->php ?? self::descobrirPhp();
        if ($php === null) {
            throw new ErroDeAtualizacao('Não achei o PHP de linha de comando neste servidor. Indique o caminho em "php" no arquivo dados/config/atualizacao.json ou atualize pelo terminal.');
        }
        if (!self::disponivel()) {
            throw new ErroDeAtualizacao('Este servidor não permite iniciar processos (proc_open desligado). Atualize pelo terminal: php bin/pbl ' . $comando);
        }
        $script = $this->caminhos->raiz . '/bin/pbl';
        if (!is_file($script)) {
            throw new ErroDeAtualizacao('Não achei bin/pbl nesta instalação.');
        }
        // $0 = php, $1 = script, $2 = saída, $3 = código, $4 = comando (atualizar ou reverter, nunca dado da requisição).
        // reverter precisa de --confirmar: quem chamou (o painel) já exigiu senha e confirmação antes de disparar o processo.
        $argumentoExtra = $comando === 'reverter' ? ' --confirmar' : '';
        $interno = '"$0" "$1" "$4"' . $argumentoExtra . ' >"$2" 2>&1 </dev/null; c=$?; echo "$c" >"$3.tmp" && mv "$3.tmp" "$3"';
        $shell = 'nohup sh -c ' . escapeshellarg($interno) . ' '
            . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($arquivoDeSaida) . ' ' . escapeshellarg($arquivoDeCodigo) . ' ' . escapeshellarg($comando)
            . ' >/dev/null 2>&1 &';
        $proc = @proc_open(['sh', '-c', $shell], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $this->caminhos->raiz);
        if (!is_resource($proc)) {
            throw new ErroDeAtualizacao('Não foi possível iniciar o processo da ' . ($comando === 'reverter' ? 'volta atrás' : 'atualização') . '.');
        }
        proc_close($proc); // o shell já voltou (o comando ficou em segundo plano)
    }
}
