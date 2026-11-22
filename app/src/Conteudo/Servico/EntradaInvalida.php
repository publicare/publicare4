<?php

declare(strict_types=1);

namespace Pbl\Conteudo\Servico;

use Pbl\Conteudo\ViolacaoDeRegra;

/** A entrada não passou na validação. `erros` é campo => mensagem (`_` = erro geral); nunca carrega o valor digitado. */
final class EntradaInvalida extends \RuntimeException
{
    /** @param array<string, string> $erros */
    public function __construct(public readonly array $erros, ?\Throwable $anterior = null)
    {
        parent::__construct($erros === [] ? 'Entrada inválida.' : (string) reset($erros), 0, $anterior);
    }

    public static function daRegra(ViolacaoDeRegra $e): self
    {
        return new self([$e->campo !== null ? 'dados.' . $e->campo : '_' => $e->getMessage()], $e);
    }
}
