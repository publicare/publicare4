<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/** Onde o `Atualizador` e o `Reversor` contam o que estão fazendo. Gravar o andamento nunca pode derrubar a operação. */
interface RegistroDeProgresso
{
    /** Começa uma operação nova (`atualizar` ou `reverter`), apagando o andamento da anterior. */
    public function iniciar(string $tipo): void;

    public function etapa(Etapa $etapa, ?int $percentual = null, ?string $mensagem = null): void;

    public function concluir(): void;

    public function falhar(string $motivo): void;
}
