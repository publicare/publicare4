<?php

declare(strict_types=1);

namespace Pbl\Manual;

/**
 * Uma edição do manual no site: a estável (raiz do site), a beta (`/beta/`) ou uma versão guardada (`/<versão>/`). A versão é a
 * única marca de versão e vive só no endereço e no rótulo do seletor, nunca no corpo do texto (ADR 0042).
 */
final class Edicao
{
    public const ESTAVEL = 'estavel';
    public const BETA = 'beta';

    private function __construct(public readonly string $chave, public readonly string $rotulo)
    {
    }

    public static function estavel(): self
    {
        return new self(self::ESTAVEL, 'Estável');
    }

    public static function beta(): self
    {
        return new self(self::BETA, 'Beta');
    }

    /** A edição guardada de uma versão (`v1.2.3` ou `1.2.3-rc.1`): o endereço usa a versão sem o `v` e com `-` no lugar de `.`. */
    public static function daVersao(string $versao): self
    {
        $chave = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(ltrim(trim($versao), 'vV'))), '-');
        if ($chave === '' || strlen($chave) > 40 || in_array($chave, [self::ESTAVEL, self::BETA], true)) {
            throw new \InvalidArgumentException('Versão inválida para a edição do manual.');
        }

        return new self($chave, ltrim(trim($versao), 'vV'));
    }

    /**
     * As edições a carregar para uma versão: `auto` (tag sem hífen = estável: guarda a versão e atualiza a raiz; com hífen = pré-release:
     * guarda a versão e atualiza /beta/) ou o nome pedido (`estavel`, `beta` ou uma versão).
     *
     * @return list<self>
     */
    public static function para(string $pedido, string $versao): array
    {
        return match ($pedido) {
            'auto' => str_contains(ltrim($versao, 'vV'), '-') ? [self::daVersao($versao), self::beta()] : [self::daVersao($versao), self::estavel()],
            self::ESTAVEL => [self::estavel()],
            self::BETA => [self::beta()],
            default => [self::daVersao($pedido)],
        };
    }

    public function ehEstavel(): bool
    {
        return $this->chave === self::ESTAVEL;
    }

    /** Prefixo dos endereços (`url_amigavel`) desta edição: vazio na estável. */
    public function prefixoDeUrl(): string
    {
        return $this->ehEstavel() ? '' : $this->chave . '/';
    }

    /** Prefixo dos links dentro do HTML (sempre com barra no começo e no fim). */
    public function prefixoDeLink(): string
    {
        return '/' . $this->prefixoDeUrl();
    }

    /** Pasta das capturas desta edição, dentro dos assets da pele. */
    public function pastaDeCapturas(): string
    {
        return '/_pbl/peles/manual/assets/capturas/' . $this->chave;
    }

    public function origem(string $resto): string
    {
        return 'manual:' . $this->chave . ':' . $resto;
    }
}
