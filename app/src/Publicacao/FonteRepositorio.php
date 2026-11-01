<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Linguagem\FonteDeDados;

/** Liga o runtime do PBL4 aos repositórios. Só lê conteúdo publicado, sempre dentro do site do objeto corrente. */
final class FonteRepositorio implements FonteDeDados
{
    /** @var array<int, string> */
    private array $nomesDeClasse = [];

    public function __construct(private readonly ObjetoRepositorio $objetos, private readonly ClasseRepositorio $classes)
    {
    }

    public function filhos(array $objeto, array $filtros): array
    {
        $id = $objeto['id'] ?? null;
        if (!is_int($id)) {
            return [];
        }
        $ids = $this->idsDeClasses((string) ($filtros['classes'] ?? ''));
        if ($ids === []) {
            return [];
        }

        return $this->contextos($this->objetos->filhos($id, $ids, (string) ($filtros['ordem'] ?? ''), (int) ($filtros['inicio'] ?? 0), (int) ($filtros['limite'] ?? 100)));
    }

    public function localizar(array $objeto, array $filtros): array
    {
        $site = $objeto['site'] ?? null;
        if (!is_int($site)) {
            return [];
        }
        $ids = $this->idsDeClasses((string) ($filtros['classes'] ?? ''));
        if ($ids === []) {
            return [];
        }
        $f = [];
        foreach (['pai', 'niveis', 'inicio', 'limite'] as $chave) {
            if (isset($filtros[$chave])) {
                $f[$chave] = (int) $filtros[$chave];
            }
        }
        foreach (['campo', 'valor', 'busca', 'ordem'] as $chave) {
            if (isset($filtros[$chave])) {
                $f[$chave] = (string) $filtros[$chave];
            }
        }
        if ($ids !== null) {
            $f['classeIds'] = $ids;
        }

        return $this->contextos($this->objetos->localizar($site, $f));
    }

    public function objeto(array $objeto, string $id, string $url, string $titulo): ?array
    {
        $site = $objeto['site'] ?? null;
        if (!is_int($site)) {
            return null;
        }
        $achado = null;
        if (ctype_digit($id)) {
            $achado = $this->objetos->porId((int) $id);
        } elseif ($url !== '') {
            $achado = $this->objetos->porUrl($site, trim($url, '/'));
        } elseif ($titulo !== '') {
            $achado = $this->objetos->porTitulo($site, $titulo);
        }
        if ($achado === null || $achado->siteId !== $site || !$achado->visivel(gmdate('c'))) {
            return null;
        }

        return ContextoDeObjeto::de($achado, $this->nomeDaClasse($achado->classeId));
    }

    public function temFilhos(array $objeto, ?int $id): bool
    {
        $alvo = $id ?? ($objeto['id'] ?? null);

        return is_int($alvo) && $this->objetos->temFilhos($alvo);
    }

    /**
     * @return list<int>|null null = qualquer classe; lista vazia = nenhuma classe conhecida (resultado vazio)
     */
    private function idsDeClasses(string $classes): ?array
    {
        if (trim($classes) === '') {
            return null;
        }
        $ids = [];
        foreach (explode(',', $classes) as $nome) {
            $classe = $this->classes->porNome(trim($nome));
            if ($classe !== null) {
                $ids[] = $classe->id;
            }
        }

        return $ids;
    }

    /**
     * @param list<Objeto> $objetos
     * @return list<array<string, mixed>>
     */
    private function contextos(array $objetos): array
    {
        return array_map(fn (Objeto $o): array => ContextoDeObjeto::de($o, $this->nomeDaClasse($o->classeId)), $objetos);
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
