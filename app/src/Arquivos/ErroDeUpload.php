<?php

declare(strict_types=1);

namespace Pbl\Arquivos;

/**
 * Arquivo recusado. A mensagem é escrita para quem enviou (sem caminho, sem detalhe interno) e pode ir direto para a tela.
 * `propriedade`: nome técnico do campo que falhou, quando o upload acontece dentro de uma transação maior (criação de
 * conteúdo com arquivo, [ADR 0014](../../../docs/adr/0014-upload-de-arquivos.md) §4) e quem pega a exceção precisa saber
 * em qual campo mostrar o erro; vazio nos demais casos (quem pega já sabe o campo, como na edição).
 */
final class ErroDeUpload extends \RuntimeException
{
    public function __construct(string $mensagem, public readonly string $propriedade = '', ?\Throwable $anterior = null)
    {
        parent::__construct($mensagem, 0, $anterior);
    }
}
