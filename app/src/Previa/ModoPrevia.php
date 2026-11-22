<?php

declare(strict_types=1);

namespace Pbl\Previa;

use Pbl\Caminhos;

/**
 * Modo PRÉVIA: só existe numa cópia descartável montada por `previa/gerar-pacote.php`, que grava o marcador `dados/previa.flag`.
 * Sem o marcador (instalação real) nada daqui age: o domínio exato continua sendo exigido e não há entrada automática.
 * Com ele, (1) o host `pv-…` da prévia atende o primeiro site e (2) `/_pbl/previa-entrar` abre a sessão de uma das contas fictícias,
 * porque a ferramenta de captura só faz GET e não consegue preencher o formulário de entrada.
 */
final class ModoPrevia
{
    public const MARCADOR = 'previa.flag';

    /** Contas fictícias que a entrada de prévia aceita (as mesmas que o gerador cria). */
    public const CONTAS = ['admin', 'editor', 'autor'];

    public function __construct(private readonly Caminhos $caminhos)
    {
    }

    public function ativo(): bool
    {
        return is_file($this->caminhos->dados() . '/' . self::MARCADOR);
    }

    /** O host é o da prévia (`pv-` e só letras minúsculas, números, ponto e hífen)? */
    public static function hostDaPrevia(string $host): bool
    {
        return preg_match('/^pv-[a-z0-9][a-z0-9.-]{0,120}$/D', $host) === 1;
    }

    /** Destino da entrada de prévia: só caminho dentro do painel (nunca outro endereço). */
    public static function destinoSeguro(string $ir): string
    {
        if (preg_match('#^/_pbl(?:/[A-Za-z0-9_./?=&%-]*)?$#D', $ir) !== 1 || str_contains($ir, '//') || str_contains($ir, '..')) {
            return '/_pbl/';
        }

        return $ir;
    }
}
