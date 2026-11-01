<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/**
 * Chaves públicas de assinatura em que o PBL4 confia. **Vazia até a primeira release**: o mantenedor gera o par com
 * `bin/pbl chaves gerar` (a privada fica com ele, fora do repositório) e coloca a pública aqui. Enquanto estiver vazia, o
 * atualizador se recusa a aplicar qualquer pacote. Para trocar de chave sem quebrar quem atualiza, publique uma versão que
 * confie nas duas e só depois retire a antiga.
 */
final class Chaves
{
    /** @var list<string> */
    public const PUBLICAS = [];
}
