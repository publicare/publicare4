<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Pbl\Acesso\Csrf;
use Pbl\Acesso\Rede;
use Pbl\Acesso\SessaoAtiva;
use Pbl\Admin\AuxiliaresDeTela;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\Request;

/** Tela mínima que usa o trait `AuxiliaresDeTela` e expõe `exigirReautenticacao` (privado) aos testes do modo elevado. */
final class TelaComAuxiliares
{
    use AuxiliaresDeTela;

    public function __construct(
        private readonly Servicos $servicos,
        private readonly Rede $rede,
        private readonly Csrf $csrf,
    ) {
    }

    /** @return array{mensagem: string, status: int, retryAfter: int|null}|null */
    public function exigir(SessaoAtiva $sessao, Request $requisicao, bool $sempreExigir): ?array
    {
        return $this->exigirReautenticacao($sessao, $requisicao, 'teste', $sempreExigir);
    }
}
