<?php

declare(strict_types=1);

namespace Pbl\Publicacao;

use Pbl\Consulta\Consulta;
use Pbl\Consulta\Filtro;
use Pbl\Consulta\MotorDeConsulta;
use Pbl\Conteudo\ClasseRepositorio;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\ObjetoRepositorio;
use Pbl\Linguagem\FonteDeDados;

/**
 * Liga o runtime do PBL4 ao `MotorDeConsulta`. Só lê conteúdo publicado e vigente, sempre dentro do site do objeto
 * corrente. Uma consulta mal formada (operador ou campo inválido) devolve lista vazia, nunca um erro de SQL.
 */
final class FonteRepositorio implements FonteDeDados
{
    private const SUFIXOS = ['', '2', '3'];

    /** @var array<int, string> */
    private array $nomesDeClasse = [];

    public function __construct(
        private readonly ObjetoRepositorio $objetos,
        private readonly ClasseRepositorio $classes,
        private readonly MotorDeConsulta $motor,
    ) {
    }

    public function filhos(array $objeto, array $filtros): array
    {
        $id = $objeto['id'] ?? null;
        $site = $objeto['site'] ?? null;
        if (!is_int($id) || !is_int($site)) {
            return [];
        }

        return $this->executar(new Consulta(
            siteId: $site,
            paiId: $id,
            niveis: 1,
            classeIds: $this->idsDeClasses((string) ($filtros['classes'] ?? '')),
            ordem: (string) ($filtros['ordem'] ?? ''),
            inicio: (int) ($filtros['inicio'] ?? 0),
            limite: (int) ($filtros['limite'] ?? 100),
        ), $filtros);
    }

    public function localizar(array $objeto, array $filtros): array
    {
        $site = $objeto['site'] ?? null;
        if (!is_int($site)) {
            return [];
        }
        try {
            $lista = $this->filtrosDe($filtros);
        } catch (\InvalidArgumentException) {
            return [];
        }

        return $this->executar(new Consulta(
            siteId: $site,
            paiId: isset($filtros['pai']) ? (int) $filtros['pai'] : null,
            niveis: isset($filtros['niveis']) ? (int) $filtros['niveis'] : null,
            classeIds: $this->idsDeClasses((string) ($filtros['classes'] ?? '')),
            filtros: $lista,
            ordem: (string) ($filtros['ordem'] ?? ''),
            inicio: (int) ($filtros['inicio'] ?? 0),
            limite: (int) ($filtros['limite'] ?? 100),
            busca: isset($filtros['busca']) ? (string) $filtros['busca'] : null,
        ), $filtros);
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

        return $this->contextoDe($achado);
    }

    public function temFilhos(array $objeto, ?int $id): bool
    {
        $alvo = $id ?? ($objeto['id'] ?? null);
        $site = $objeto['site'] ?? null;

        return is_int($alvo) && is_int($site) && $this->motor->temFilhos($alvo, $site);
    }

    public function referencia(array $objeto, string $propriedade): ?array
    {
        $id = $objeto[$propriedade] ?? null;
        $site = $objeto['site'] ?? null;
        if (!is_int($id) || !is_int($site)) {
            return null;
        }
        $achado = $this->motor->porIds($site, [$id])[$id] ?? null;

        return $achado === null ? null : $this->contextos([$achado])[0];
    }

    /**
     * Contexto de um objeto para o molde, com `#temfilhos`.
     *
     * @return array<string, mixed>
     */
    public function contextoDe(Objeto $objeto): array
    {
        return $this->contextos([$objeto])[0];
    }

    /**
     * @param array<string, int|string> $filtros
     * @return list<array<string, mixed>>
     */
    private function executar(Consulta $consulta, array $filtros): array
    {
        if ($consulta->classeIds === []) {
            return [];
        }
        try {
            $achados = $this->motor->buscar($consulta);
        } catch (\InvalidArgumentException) {
            return [];
        }

        return $this->contextos($achados, $this->expandir((string) ($filtros['expandir'] ?? '')));
    }

    /**
     * Contextos em lote: `#temfilhos` numa consulta só e, para cada propriedade `ref` pedida em `expandir`, os
     * objetos apontados numa consulta só (`#autor.titulo` sem uma consulta por item).
     *
     * @param list<Objeto> $objetos
     * @param list<string> $expandir
     * @return list<array<string, mixed>>
     */
    private function contextos(array $objetos, array $expandir = []): array
    {
        if ($objetos === []) {
            return [];
        }
        $site = $objetos[0]->siteId;
        $comFilhos = array_flip($this->motor->idsComFilhos(array_map(static fn (Objeto $o): int => $o->id, $objetos), $site));
        $referenciados = [];
        foreach ($expandir as $propriedade) {
            $ids = [];
            foreach ($objetos as $o) {
                $valor = $o->dados[$propriedade] ?? null;
                if (is_int($valor)) {
                    $ids[] = $valor;
                }
            }
            $referenciados[$propriedade] = $this->motor->porIds($site, $ids);
        }

        $lista = [];
        foreach ($objetos as $o) {
            $contexto = ContextoDeObjeto::de($o, $this->nomeDaClasse($o->classeId), isset($comFilhos[$o->id]));
            foreach ($referenciados as $propriedade => $alvos) {
                $alvo = $alvos[(int) ($o->dados[$propriedade] ?? 0)] ?? null;
                if ($alvo !== null) {
                    $contexto[$propriedade . '.'] = ContextoDeObjeto::de($alvo, $this->nomeDaClasse($alvo->classeId));
                }
            }
            $lista[] = $contexto;
        }

        return $lista;
    }

    /** @return list<string> */
    private function expandir(string $texto): array
    {
        $nomes = [];
        foreach (explode(',', $texto) as $nome) {
            $nome = strtolower(trim($nome));
            if (preg_match('/^[a-z][a-z0-9_]*$/', $nome) === 1) {
                $nomes[] = $nome;
            }
        }

        return array_slice(array_values(array_unique($nomes)), 0, 4);
    }

    /**
     * Até três condições: `campo`/`op`/`valor`, `campo2`/`op2`/`valor2`, `campo3`/`op3`/`valor3`; `busca` é a busca de texto (várias palavras, prefixo, plural, relevância) e vai direto na `Consulta`.
     *
     * @param array<string, int|string> $filtros
     * @return list<Filtro>
     */
    private function filtrosDe(array $filtros): array
    {
        $lista = [];
        foreach (self::SUFIXOS as $sufixo) {
            if (isset($filtros["campo{$sufixo}"], $filtros["valor{$sufixo}"])) {
                $lista[] = new Filtro((string) $filtros["campo{$sufixo}"], (string) ($filtros["op{$sufixo}"] ?? '='), (string) $filtros["valor{$sufixo}"]);
            }
        }

        return $lista;
    }

    /** @return list<int>|null null = qualquer classe; lista vazia = nenhuma classe conhecida (resultado vazio) */
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

    private function nomeDaClasse(int $id): string
    {
        if (!isset($this->nomesDeClasse[$id])) {
            $classe = $this->classes->porId($id);
            $this->nomesDeClasse[$id] = $classe === null ? '' : $classe->nome;
        }

        return $this->nomesDeClasse[$id];
    }
}
