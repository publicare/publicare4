<?php

declare(strict_types=1);

namespace Pbl\Testes;

use PHPUnit\Framework\TestCase;

/** instalar.sh: o que dá para provar offline (sintaxe, recusas e regras de segurança do texto). O download real é validado no CI. */
final class InstalarShTest extends TestCase
{
    private const ARQUIVO = __DIR__ . '/../infra/instalador/instalar.sh';

    private function codigo(): string
    {
        return (string) preg_replace('/^\s*#.*$/m', '', (string) file_get_contents(self::ARQUIVO));
    }

    /** @return array{0: int, 1: string} */
    private function rodar(string $args, string $ambiente = ''): array
    {
        $pasta = Ajuda::pastaTemporaria();
        try {
            exec('cd ' . escapeshellarg($pasta) . ' && ' . $ambiente . ' sh ' . escapeshellarg(self::ARQUIVO) . ' ' . $args . ' 2>&1 </dev/null', $saida, $codigo);
        } finally {
            Ajuda::apagar($pasta);
        }

        return [$codigo, implode("\n", $saida)];
    }

    public function testSintaxePosixValida(): void
    {
        exec('sh -n ' . escapeshellarg(self::ARQUIVO) . ' 2>&1', $saida, $codigo);

        self::assertSame(0, $codigo, implode("\n", $saida));
        self::assertStringStartsWith('#!/bin/sh', (string) file_get_contents(self::ARQUIVO));
        self::assertStringNotContainsString('[[', $this->codigo(), 'sem bashismos');
    }

    public function testRegrasDeSegurancaDoTexto(): void
    {
        $c = $this->codigo();

        self::assertStringContainsString('set -eu', $c);
        self::assertDoesNotMatchRegularExpression('/\beval\b/', $c);
        self::assertDoesNotMatchRegularExpression('/\|\s*(sh|bash|php)\b/', (string) preg_replace('/printf[^|]*\|\s*php bin\/pbl/', '', $c), 'nada de "baixa e executa" por pipe');
        self::assertStringContainsString("--proto '=https'", $c);
        self::assertStringContainsString('--https-only', $c);
        self::assertDoesNotMatchRegularExpression('/curl[^\n]*(-k\b|--insecure)|wget[^\n]*--no-check-certificate/', $c);
        self::assertDoesNotMatchRegularExpression('/--(admin-)?(senha|password)/', $c, 'senha nunca por flag');
        self::assertStringContainsString('PBL_ADMIN_SENHA', $c);
        self::assertStringContainsString('https://atualizacoes.publicare.org', $c);
    }

    public function testRecusaArgumentoDeSenhaEDesconhecido(): void
    {
        [$codigo, $saida] = $this->rodar('--senha=segredo');

        self::assertSame(1, $codigo);
        self::assertStringContainsString('argumento desconhecido', $saida);
        self::assertStringNotContainsString('segredo', str_replace('--senha=segredo', '', $saida));
    }

    public function testAjudaFuncionaERecusaBaseSemHttps(): void
    {
        [$codigo, $saida] = $this->rodar('--ajuda');
        self::assertSame(0, $codigo);
        self::assertStringContainsString('Uso: sh instalar.sh', $saida);

        [$codigo, $saida] = $this->rodar('', 'PBL_INSTALAR_URL_BASE=http://exemplo.org');
        self::assertSame(1, $codigo);
        self::assertStringContainsString('https', $saida);
    }

    public function testRecusaPastaQueJaTemOPublicare(): void
    {
        $pasta = Ajuda::pastaTemporaria();
        mkdir($pasta . '/app');
        try {
            exec('cd ' . escapeshellarg($pasta) . ' && sh ' . escapeshellarg(self::ARQUIVO) . ' 2>&1 </dev/null', $saida, $codigo);
        } finally {
            Ajuda::apagar($pasta);
        }

        self::assertSame(1, $codigo);
        self::assertStringContainsString('já tem o Publicare', implode("\n", $saida));
    }
}
