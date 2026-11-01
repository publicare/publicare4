<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Linguagem\FonteDeDados;

/** Liga o runtime do PBL4 aos repositórios. Só lê conteúdo publicado. */
final class FonteRepositorio implements FonteDeDados
{
    /** @var array<int, string> */
    private array $nomesDeClasse = [];

    public function __construct(private readonly ObjetoRepositorio $objetos, private readonly ClasseRepositorio $classes)
    {
    }

    public function filhos(array $objeto, string $classes, string $ordem, int $inicio, int $limite): array
    {
        $id = $objeto['id'] ?? null;
        if (!is_int($id)) {
            return [];
        }
        $ids = null;
        if (trim($classes) !== '') {
            $ids = [];
            foreach (explode(',', $classes) as $nome) {
                $classe = $this->classes->porNome(trim($nome));
                if ($classe !== null) {
                    $ids[] = $classe->id;
                }
            }
            if ($ids === []) {
                return [];
            }
        }
        $lista = [];
        foreach ($this->objetos->filhos($id, $ids, $ordem, $inicio, $limite) as $filho) {
            $lista[] = ContextoDeObjeto::de($filho, $this->nomeDaClasse($filho->classeId));
        }

        return $lista;
    }

    private function nomeDaClasse(int $id): string
    {
        if (!isset($this->nomesDeClasse[$id])) {
            $classe = $this->classes->porId($id);
            $this->nomesDeClasse[$id] = $classe === null ? '' : $classe->nome;
        }

        return $this->nomesDeClasse[$id];
    }
}
