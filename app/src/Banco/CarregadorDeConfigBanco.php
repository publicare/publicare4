<?php

declare(strict_types=1);

namespace Pbl\Banco;

use Pbl\Caminhos;

/**
 * Descobre a configuração do banco (ADR 0028 §4): `dados/config/banco.json` (ou o caminho em `PBL_BANCO_ARQUIVO`) e, por
 * cima, as variáveis `PBL_BANCO_*`, que vencem o arquivo. Sem nenhum dos dois, SQLite em `dados/pbl4.sqlite`, como sempre.
 * Os erros nomeiam o campo e nunca mostram valores (a senha em especial).
 */
final class CarregadorDeConfigBanco
{
    private const CAMPOS = ['driver', 'host', 'porta', 'nome', 'usuario', 'senha', 'schema', 'tls', 'modo_simples'];

    private const VARIAVEIS = [
        'PBL_BANCO_DRIVER' => 'driver', 'PBL_BANCO_HOST' => 'host', 'PBL_BANCO_PORTA' => 'porta', 'PBL_BANCO_NOME' => 'nome',
        'PBL_BANCO_USUARIO' => 'usuario', 'PBL_BANCO_SENHA' => 'senha', 'PBL_BANCO_SCHEMA' => 'schema', 'PBL_BANCO_TLS' => 'tls',
        'PBL_BANCO_MODO_SIMPLES' => 'modo_simples',
    ];

    /** @var list<string> */
    private array $avisos = [];

    /** @param array<string, string>|null $ambiente variáveis de ambiente; null = as do processo */
    public function __construct(private readonly Caminhos $caminhos, private readonly ?array $ambiente = null)
    {
    }

    /** @throws ConfigDeBancoInvalida */
    public function carregar(): ConfigBanco
    {
        $this->avisos = [];
        $env = $this->ambiente ?? $this->doProcesso();
        $campos = $this->doArquivo($env);
        foreach (self::VARIAVEIS as $variavel => $campo) {
            if (isset($env[$variavel]) && $env[$variavel] !== '') {
                $campos[$campo] = $env[$variavel];
            }
        }

        return $this->montar($campos);
    }

    /** @return list<string> Avisos não fatais da última leitura (por exemplo, permissão frouxa). */
    public function avisos(): array
    {
        return $this->avisos;
    }

    /**
     * @param array<string, string> $env
     * @return array<string, mixed>
     */
    private function doArquivo(array $env): array
    {
        $indicado = $env['PBL_BANCO_ARQUIVO'] ?? '';
        if ($indicado !== '' && !str_starts_with($indicado, '/')) {
            throw new ConfigDeBancoInvalida('PBL_BANCO_ARQUIVO: use um caminho absoluto.');
        }
        $arquivo = $indicado !== '' ? $indicado : $this->caminhos->config() . '/banco.json';
        if (!is_file($arquivo)) {
            if ($indicado !== '') {
                throw new ConfigDeBancoInvalida('PBL_BANCO_ARQUIVO: o arquivo indicado não existe.');
            }

            return [];
        }
        $perms = fileperms($arquivo);
        if (($perms & 0077) !== 0 && DIRECTORY_SEPARATOR === '/') {
            $this->avisos[] = 'banco.json tem permissão frouxa (' . sprintf('%04o', $perms & 0777) . '): ajuste para 0600, ele guarda a senha do banco.';
        }
        $texto = @file_get_contents($arquivo);
        $dados = $texto === false ? null : json_decode($texto, true);
        if (!is_array($dados) || ($dados !== [] && array_is_list($dados))) {
            throw new ConfigDeBancoInvalida('banco.json inválido: não é um JSON de objeto legível.');
        }
        foreach (array_keys($dados) as $chave) {
            if (!in_array($chave, self::CAMPOS, true)) {
                $nome = is_string($chave) && preg_match('/^[A-Za-z0-9_]{1,40}$/D', $chave) === 1 ? " \"{$chave}\"" : '';
                throw new ConfigDeBancoInvalida("banco.json: campo desconhecido{$nome}. Só se guardam os campos do usuário de operação; o usuário de instalação nunca é gravado.");
            }
        }

        return $dados;
    }

    /** @return array<string, string> */
    private function doProcesso(): array
    {
        $env = [];
        foreach (getenv() as $k => $v) {
            if (str_starts_with((string) $k, 'PBL_BANCO_')) {
                $env[(string) $k] = (string) $v;
            }
        }

        return $env;
    }

    /** @param array<string, mixed> $c */
    private function montar(array $c): ConfigBanco
    {
        $driver = $this->texto($c, 'driver') ?? 'sqlite';
        $nome = $this->texto($c, 'nome');
        if ($driver === 'sqlite') {
            if ($nome !== null && !str_starts_with($nome, '/')) {
                throw new ConfigDeBancoInvalida('nome: o caminho do arquivo SQLite deve ser absoluto.');
            }
            $nome ??= $this->caminhos->banco();
        }

        return new ConfigBanco(
            $driver,
            $nome ?? '',
            $this->texto($c, 'host') ?? '',
            $this->porta($c['porta'] ?? null),
            $this->texto($c, 'usuario') ?? '',
            $this->texto($c, 'senha') ?? '',
            $this->texto($c, 'schema'),
            $this->texto($c, 'tls') ?? 'desligado',
            $this->booleano($c['modo_simples'] ?? false),
        );
    }

    /** @param array<string, mixed> $c */
    private function texto(array $c, string $campo): ?string
    {
        $v = $c[$campo] ?? null;
        if ($v === null) {
            return null;
        }
        if (!is_string($v)) {
            throw new ConfigDeBancoInvalida("{$campo}: deve ser texto.");
        }

        return $v;
    }

    private function porta(mixed $v): ?int
    {
        if ($v === null) {
            return null;
        }
        if (is_int($v) || (is_string($v) && preg_match('/^[0-9]{1,5}$/D', $v) === 1)) {
            return (int) $v;
        }
        throw new ConfigDeBancoInvalida('porta: deve ser um número inteiro de 1 a 65535.');
    }

    private function booleano(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        $t = is_string($v) ? strtolower($v) : '';
        if (in_array($t, ['1', 'true', 'sim', 'on'], true)) {
            return true;
        }
        if (in_array($t, ['0', 'false', 'nao', 'não', 'off'], true)) {
            return false;
        }
        throw new ConfigDeBancoInvalida('modo_simples: use verdadeiro ou falso.');
    }
}
