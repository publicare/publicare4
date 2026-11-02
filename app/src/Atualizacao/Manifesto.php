<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/** O `latest.json` de uma versão, já validado. Só se confia nele depois de a assinatura ter sido conferida. */
final class Manifesto
{
    public const FORMATO = 1;
    public const MAX_PACOTE = 200_000_000;

    public function __construct(
        public readonly string $canal,
        public readonly string $versao,
        public readonly string $data,
        public readonly string $phpMinimo,
        public readonly string $atualizarAPartirDe,
        public readonly bool $seguranca,
        public readonly bool $migracoes,
        public readonly string $resumo,
        public readonly string $notas,
        public readonly string $urlDoPacote,
        public readonly int $tamanho,
        public readonly string $sha256,
        /** Só vale se o manifesto foi assinado pela chave de recuperação (o Atualizador confere). */
        public readonly ?string $novaChaveDeUso = null,
        /** @var list<string> idem */
        public readonly array $chavesRevogadas = [],
    ) {
    }

    public static function analisar(string $json): self
    {
        $d = json_decode($json, true);
        if (!is_array($d) || ($d['formato'] ?? null) !== self::FORMATO) {
            throw new ErroDeAtualizacao('Manifesto em formato desconhecido');
        }
        $texto = static fn (string $campo, int $max = 300): string => isset($d[$campo]) && is_string($d[$campo]) && strlen($d[$campo]) <= $max
            ? $d[$campo]
            : throw new ErroDeAtualizacao("Manifesto sem o campo {$campo} válido");
        $versao = $texto('versao', 64);
        $minimo = $texto('atualizar_a_partir_de', 64);
        if (!Semver::valida($versao) || !Semver::valida($minimo)) {
            throw new ErroDeAtualizacao('Manifesto com versão inválida');
        }
        $canal = $texto('canal', 20);
        if (!in_array($canal, ['estavel', 'beta', 'teste'], true)) {
            throw new ErroDeAtualizacao('Manifesto com canal desconhecido');
        }
        $phpMinimo = $texto('php_minimo', 20);
        if (preg_match('/^\d+\.\d+(\.\d+)?$/D', $phpMinimo) !== 1) {
            throw new ErroDeAtualizacao('Manifesto com versão de PHP inválida');
        }
        $data = $texto('data', 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $data) !== 1) {
            throw new ErroDeAtualizacao('Manifesto com data inválida');
        }
        $p = $d['pacote'] ?? null;
        if (!is_array($p) || !isset($p['url'], $p['tamanho'], $p['sha256']) || !is_string($p['url']) || !is_int($p['tamanho']) || !is_string($p['sha256'])) {
            throw new ErroDeAtualizacao('Manifesto sem os dados do pacote');
        }
        if ($p['tamanho'] <= 0 || $p['tamanho'] > self::MAX_PACOTE || preg_match('/^[0-9a-f]{64}$/D', $p['sha256']) !== 1 || strlen($p['url']) > 500) {
            throw new ErroDeAtualizacao('Manifesto com pacote inválido');
        }
        if (Semver::comparar($minimo, $versao) > 0) {
            throw new ErroDeAtualizacao('Manifesto incoerente: a versão mínima para atualizar é maior que a própria versão');
        }

        $novaChave = null;
        $revogadas = [];
        if (isset($d['chaves'])) {
            $c = $d['chaves'];
            if (!is_array($c)) {
                throw new ErroDeAtualizacao('Manifesto com bloco de chaves inválido');
            }
            if (isset($c['uso'])) {
                if (!is_string($c['uso']) || !ChavesLocais::valida($c['uso'])) {
                    throw new ErroDeAtualizacao('Manifesto com chave de uso inválida');
                }
                $novaChave = $c['uso'];
            }
            foreach (is_array($c['revogadas'] ?? []) ? ($c['revogadas'] ?? []) : throw new ErroDeAtualizacao('Manifesto com lista de chaves revogadas inválida') as $r) {
                if (!is_string($r) || !ChavesLocais::valida($r)) {
                    throw new ErroDeAtualizacao('Manifesto com chave revogada inválida');
                }
                $revogadas[] = $r;
            }
            if (count($revogadas) > 20) {
                throw new ErroDeAtualizacao('Manifesto com chaves revogadas demais');
            }
        }

        return new self(
            $canal, $versao, $data, $phpMinimo, $minimo,
            ($d['seguranca'] ?? false) === true, ($d['migracoes'] ?? false) === true,
            isset($d['resumo']) && is_string($d['resumo']) ? mb_substr($d['resumo'], 0, 300) : '',
            isset($d['notas']) && is_string($d['notas']) && str_starts_with($d['notas'], 'https://') ? mb_substr($d['notas'], 0, 300) : '',
            $p['url'], $p['tamanho'], $p['sha256'],
            $novaChave, array_values(array_unique($revogadas)),
        );
    }
}
