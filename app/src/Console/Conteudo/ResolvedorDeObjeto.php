<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;

use Pbl\Acesso\Usuario;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Servico\ConteudoNaoEncontrado;
use Pbl\Conteudo\Servico\ServicoDeConteudo;
use Pbl\Conteudo\Site;

/**
 * `<id|caminho>`: número = id do conteúdo; qualquer outra coisa = endereço do site (`/` = página inicial). O conteúdo precisa
 * ser do site informado; sem permissão de ver é o mesmo "não encontrado".
 */
final class ResolvedorDeObjeto
{
    public function __construct(private readonly ServicoDeConteudo $conteudo)
    {
    }

    public function resolver(Usuario $quem, Site $site, ?string $referencia): Objeto
    {
        $referencia = $referencia === null ? '' : trim($referencia);
        if ($referencia === '') {
            throw new UsoInvalido('Informe o conteúdo: <id|caminho>.');
        }
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $referencia) === 1) {
            $o = $this->conteudo->obter($quem, (int) $referencia);
            if ($o->siteId !== $site->id) {
                throw new ConteudoNaoEncontrado();
            }

            return $o;
        }

        return $this->conteudo->obterPorEndereco($quem, $site, $referencia);
    }
}
