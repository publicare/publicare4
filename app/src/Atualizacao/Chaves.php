<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/**
 * Chaves públicas de assinatura em que o PBL4 confia (ADR 0019). **`USO` e `RECUPERACAO` já estão embutidas** (são públicas, vão no
 * repositório). As privadas foram geradas offline pelo dono (`bin/pbl gerar-chaves`) e nunca entram no repositório, no CI nem no servidor.
 * Um build sem nenhuma chave (lista vazia) faz o atualizador recusar qualquer pacote.
 *
 * - `USO`: a chave do dia a dia, que assina os manifestos de cada versão.
 * - `RECUPERACAO`: guardada em mídia fria; só ela assina a **troca da chave de uso** e a **revogação** de chaves (no manifesto).
 *   Nunca é trocada por manifesto: trocá-la exige uma versão nova do produto com outra constante.
 * - `REVOGADAS`: chaves que o produto já nasce recusando (soma-se ao que um manifesto da recuperação revogar, guardado em
 *   `dados/config/chaves-confiaveis.json`).
 * - `TESTE`: chave do canal `teste` (demo e versões de teste), que pode ficar no CI. Só vale para o canal `teste`; as chaves
 *   de produção não valem nele e ela não vale em nenhum outro canal.
 */
final class Chaves
{
    /** @var list<string> */
    public const USO = ['862a04bc8a5c8166d31a691ff4416198ea251fb4da2a72197c53a4c0e6aa0ce8'];

    /** @var list<string> */
    public const RECUPERACAO = ['aa8837093423b48935588a6a6fcebf3e3f1a6c5a52cdb1cafe3a4c6e06a70411'];

    /** @var list<string> */
    public const REVOGADAS = [];

    /** @var list<string> */
    public const TESTE = [];

    /** @var list<string> as de produção (uso + recuperação) */
    public const PUBLICAS = [...self::USO, ...self::RECUPERACAO];

    /**
     * Não há em que confiar para este canal? Nos canais de produção olha as chaves de produção informadas; no canal `teste`
     * basta haver chave de teste (um build só com `TESTE` preenchido deve chegar ao verificador).
     *
     * @param list<string> $producao
     * @param list<string> $teste
     */
    public static function semConfianca(array $producao, string $canal, array $teste = self::TESTE): bool
    {
        return $canal === 'teste' ? $teste === [] : $producao === [];
    }
}
