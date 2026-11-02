<?php

declare(strict_types=1);

namespace Pbl\Arquivos;

/** Arquivo recusado. A mensagem é escrita para quem enviou (sem caminho, sem detalhe interno) e pode ir direto para a tela. */
final class ErroDeUpload extends \RuntimeException
{
}
