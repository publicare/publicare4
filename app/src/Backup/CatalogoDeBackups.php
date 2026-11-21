<?php

declare(strict_types=1);

namespace Pbl\Backup;

use Pbl\Atualizacao\RegistroDaAtualizacao;
use Pbl\Caminhos;

/**
 * Lista o que existe no disco (ADR 0030/0032): pacotes `.zip` da pasta configurada (lendo o manifesto) e as cópias `*.sqlite` do
 * atualizador em `dados/backups/`, que aparecem como "pré-atualização" e a cópia que o "voltar atrás" usa fica protegida.
 */
final class CatalogoDeBackups
{
    public const NOME_VALIDO = '/^[A-Za-z0-9._+-]{1,200}\.zip$/D';

    public function __construct(private readonly Caminhos $caminhos, private readonly ConfigDeBackup $config)
    {
    }

    /** @return list<InfoDeBackup> da mais nova para a mais antiga */
    public function listar(bool $verificar = true): array
    {
        $itens = [];
        $pasta = ValidadorDePasta::resolver($this->caminhos, $this->config->pasta);
        foreach (glob($pasta . '/*.zip') ?: [] as $arquivo) {
            $info = $this->doPacote($arquivo, $verificar);
            if ($info !== null) {
                $itens[] = $info;
            }
        }
        $voltarAtras = RegistroDaAtualizacao::ler($this->caminhos);
        $legado = $this->caminhos->dados() . '/backups';
        foreach (glob($legado . '/*.sqlite') ?: [] as $arquivo) {
            if (is_link($arquivo)) {
                continue;
            }
            $nome = basename($arquivo);
            $v = preg_match('/(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)/', $nome, $m) === 1 ? $m[1] : '?';
            $itens[] = new InfoDeBackup($nome, 'pre-atualizacao', (int) filemtime($arquivo), (int) filesize($arquivo), $v, null, null, true, $voltarAtras !== null && $voltarAtras['backup'] === $nome && !$voltarAtras['revertida']);
        }
        usort($itens, static fn (InfoDeBackup $a, InfoDeBackup $b): int => $b->criadoEm <=> $a->criadoEm);

        return $itens;
    }

    /** @throws ErroDeBackup nome fora do padrão ou arquivo inexistente */
    public function caminhoDe(string $nome): string
    {
        if (preg_match(self::NOME_VALIDO, $nome) !== 1) {
            throw new ErroDeBackup('Nome de cópia inválido.');
        }
        $arquivo = ValidadorDePasta::resolver($this->caminhos, $this->config->pasta) . '/' . $nome;
        if (!is_file($arquivo) || is_link($arquivo)) {
            throw new ErroDeBackup('Cópia não encontrada.');
        }

        return $arquivo;
    }

    /** @return array<string, mixed>|null manifesto lido do pacote */
    public static function lerManifesto(string $arquivo): ?array
    {
        $zip = new \ZipArchive();
        if ($zip->open($arquivo, \ZipArchive::RDONLY) !== true) {
            return null;
        }
        try {
            $i = $zip->locateName('manifesto.json');
            $stat = $i === false ? false : $zip->statIndex($i);
            if ($stat === false || $stat['size'] > 5_000_000) {
                return null;
            }
            $d = json_decode((string) $zip->getFromIndex($i), true);

            return is_array($d) ? $d : null;
        } finally {
            $zip->close();
        }
    }

    /** O SHA-256 do pacote confere com o arquivo lateral `.sha256`. */
    public static function integro(string $arquivo): bool
    {
        $lateral = $arquivo . '.sha256';
        if (!is_file($lateral)) {
            return false;
        }
        $esperado = strtok((string) file_get_contents($lateral), " \t\r\n");

        return is_string($esperado) && hash_equals($esperado, (string) hash_file('sha256', $arquivo));
    }

    private function doPacote(string $arquivo, bool $verificar): ?InfoDeBackup
    {
        if (is_link($arquivo) || preg_match(self::NOME_VALIDO, basename($arquivo)) !== 1) {
            return null;
        }
        $m = self::lerManifesto($arquivo);
        if ($m === null || ($m['formato'] ?? null) !== 1 || !in_array($m['tipo'] ?? null, InfoDeBackup::TIPOS, true)) {
            return null;
        }
        $criado = is_string($m['criado_em'] ?? null) ? strtotime($m['criado_em']) : false;

        return new InfoDeBackup(
            basename($arquivo), $m['tipo'], $criado === false ? (int) filemtime($arquivo) : $criado, (int) filesize($arquivo),
            is_string($m['versao_pbl4'] ?? null) ? $m['versao_pbl4'] : '?', is_int($m['schema'] ?? null) ? $m['schema'] : null,
            $verificar ? self::integro($arquivo) : null,
        );
    }
}
