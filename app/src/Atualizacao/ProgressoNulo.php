<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/** Não grava nada (o padrão do `Atualizador` e do `Reversor` quando ninguém pede o andamento). */
final class ProgressoNulo implements RegistroDeProgresso
{
    public function iniciar(string $tipo): void
    {
    }

    public function etapa(Etapa $etapa, ?int $percentual = null, ?string $mensagem = null): void
    {
    }

    public function concluir(): void
    {
    }

    public function falhar(string $motivo): void
    {
    }
}
