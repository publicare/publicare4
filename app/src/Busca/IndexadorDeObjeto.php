<?php

declare(strict_types=1);

namespace Pbl\Busca;

use Pbl\Conteudo\ClasseRepositorio;

/**
 * Monta os campos de busca de um objeto (título, descrição e o texto das propriedades `texto` e `html`, sem marcação) e
 * entrega ao driver. É o único ponto que sabe quais dados do objeto entram na busca.
 */
final class IndexadorDeObjeto
{
    /** @var array<int, list<string>> classe_id → nomes das propriedades de texto */
    private array $propriedades = [];

    public function __construct(private readonly BuscaInterface $busca, private readonly ClasseRepositorio $classes)
    {
    }

    /** @param array<string, mixed> $dados */
    public function indexar(int $objetoId, int $classeId, string $titulo, string $descricao, array $dados): void
    {
        $partes = [];
        foreach ($this->propriedadesDeTexto($classeId) as $nome) {
            if (isset($dados[$nome]) && is_scalar($dados[$nome])) {
                $partes[] = self::semMarcacao((string) $dados[$nome]);
            }
        }
        $this->busca->indexar($objetoId, ['titulo' => $titulo, 'descricao' => $descricao, 'corpo' => implode(' ', $partes)]);
    }

    /** HTML vira texto: tags saem (viram espaço, para `a<br>b` não virar `ab`) e entidades são decodificadas. */
    public static function semMarcacao(string $texto): string
    {
        if (!str_contains($texto, '<') && !str_contains($texto, '&')) {
            return $texto;
        }
        $texto = (string) preg_replace('/<[^>]*>/', ' ', mb_substr($texto, 0, Termos::MAX_TEXTO_INDEXADO * 2));

        return html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return list<string> */
    private function propriedadesDeTexto(int $classeId): array
    {
        if (!isset($this->propriedades[$classeId])) {
            $classe = $this->classes->porId($classeId);
            $nomes = [];
            foreach ($classe->propriedades ?? [] as $p) {
                if (in_array($p['tipo'], ['texto', 'html'], true)) {
                    $nomes[] = $p['nome'];
                }
            }
            $this->propriedades[$classeId] = $nomes;
        }

        return $this->propriedades[$classeId];
    }
}
