<?php

declare(strict_types=1);

namespace Pbl\Email;

use Pbl\Arquivos\GravacaoAtomica;

/**
 * Grava cada e-mail como `.eml` (0600, nome aleatório) numa pasta de `dados/`: desenvolvimento e servidores sem SMTP. Atenção: o
 * arquivo contém links de uso único válidos; em produção quem opera usa `bin/pbl` e apaga a caixa.
 */
final class TransporteArquivo implements Transporte
{
    public function __construct(private readonly string $pasta, private readonly string $remetente)
    {
    }

    public function enviar(Mensagem $mensagem): void
    {
        GravacaoAtomica::gravar($this->pasta . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.eml', $mensagem->formatar($this->remetente));
    }

    public function tipo(): string
    {
        return self::ARQUIVO;
    }
}
