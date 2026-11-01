<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

/**
 * A operação deixaria a árvore num estado proibido: conteúdo fora da lixeira dentro de um conteúdo na lixeira (criar, mover ou
 * tirar da lixeira debaixo de um pai apagado; mandar para a lixeira quem ainda tem filhos fora dela). Nada é gravado.
 */
final class ConflitoDeArvore extends \RuntimeException
{
}
