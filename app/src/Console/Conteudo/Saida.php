<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Escreve a resposta em JSON estável (api=1, uma linha) ou em texto limpo de caracteres de controle. */
final class Saida
{
    public const API = 1;
    private const FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(private readonly OutputInterface $saida, private readonly bool $json, private readonly string $acao)
    {
    }

    public function sucesso(Resposta $r): void
    {
        if ($this->json) {
            $this->escrever(self::codificar(['ok' => true, 'api' => self::API, 'acao' => $this->acao, 'dados' => $r->dados]));

            return;
        }
        $this->escrever(self::limpar($r->texto));
    }

    /** @param array<string, mixed> $extra chaves de `erro` além de codigo e mensagem */
    public function erro(string $codigo, string $mensagem, array $extra = []): void
    {
        if ($this->json) {
            $this->escrever(self::codificar(['ok' => false, 'api' => self::API, 'acao' => $this->acao, 'erro' => ['codigo' => $codigo, 'mensagem' => $mensagem] + $extra]));

            return;
        }
        $linhas = 'Erro (' . $codigo . '): ' . $mensagem;
        foreach (($extra['campos'] ?? []) as $campo => $texto) {
            $linhas .= "\n  " . $campo . ': ' . $texto;
        }
        if (isset($extra['versao_atual'])) {
            $linhas .= "\n  versão atual: " . $extra['versao_atual'];
        }
        $destino = $this->saida instanceof ConsoleOutputInterface ? $this->saida->getErrorOutput() : $this->saida;
        $destino->write(self::limpar($linhas), true, OutputInterface::OUTPUT_RAW);
    }

    /** @param array<string, mixed> $dados */
    public static function codificar(array $dados): string
    {
        return json_encode($dados, self::FLAGS);
    }

    /** Tira ESC e os demais controles C0/C1, menos quebra de linha e tabulação: conteúdo malicioso não injeta sequências de terminal. */
    public static function limpar(string $texto): string
    {
        $texto = mb_scrub($texto, 'UTF-8');

        return (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F\x{80}-\x{9F}]/u', '', $texto);
    }

    private function escrever(string $linha): void
    {
        $this->saida->write($linha, true, OutputInterface::OUTPUT_RAW);
    }
}
