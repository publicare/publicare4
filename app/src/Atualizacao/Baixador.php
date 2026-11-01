<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/** De onde vêm o manifesto e o pacote. A autenticidade não depende do canal: quem decide é a assinatura. */
interface Baixador
{
    /** Conteúdo (de até `$maximoBytes`) de uma URL ou caminho. @throws ErroDeAtualizacao */
    public function obter(string $origem, int $maximoBytes): string;

    /** Copia para um arquivo sem carregar tudo na memória. @throws ErroDeAtualizacao */
    public function copiar(string $origem, string $arquivoDestino, int $maximoBytes): void;

    /** Endereço do pacote a partir do endereço do manifesto. @throws ErroDeAtualizacao se apontar para outro lugar */
    public function resolver(string $base, string $relativo): string;
}
