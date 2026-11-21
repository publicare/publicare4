<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

/**
 * Quais tipos (classes) um objeto aceita como filhos (`aceita_filhos` e a lista `filhas`; vazia = qualquer tipo).
 * Usado pelo painel (tela de criação) e pela API da barra de edição. Guarda o resultado por classe do pai durante a requisição.
 */
final class TiposAceitos
{
    /** @var array<int, list<Classe>> */
    private array $cache = [];

    public function __construct(private readonly ClasseRepositorio $classes)
    {
    }

    /** @return list<Classe> */
    public function de(Objeto $pai): array
    {
        if (isset($this->cache[$pai->classeId])) {
            return $this->cache[$pai->classeId];
        }
        $classePai = $this->classes->porId($pai->classeId);
        $aceitas = $classePai === null ? [] : array_values(array_filter(
            $this->classes->todas(),
            static fn (Classe $c): bool => $classePai->aceita($c),
        ));

        return $this->cache[$pai->classeId] = $aceitas;
    }

    public function aceita(Objeto $pai, Classe $filha): bool
    {
        foreach ($this->de($pai) as $c) {
            if ($c->id === $filha->id) {
                return true;
            }
        }

        return false;
    }
}
