<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/** Dispara `bin/pbl atualizar` fora do processo da requisição. Existe como interface para os testes não rodarem uma atualização de verdade. */
interface Lancador
{
    /**
     * Inicia em segundo plano e volta na hora. Ao terminar, o processo grava o texto que o comando escreveu em `$arquivoDeSaida` e o
     * código de saída em `$arquivoDeCodigo` (só então; o arquivo aparecer significa "acabou").
     *
     * @throws ErroDeAtualizacao se não der para iniciar (sem `proc_open`, sem PHP de linha de comando…)
     */
    public function iniciar(string $arquivoDeSaida, string $arquivoDeCodigo): void;
}
