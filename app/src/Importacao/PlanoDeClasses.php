<?php

declare(strict_types=1);

namespace Pbl\Importacao;

use Pbl\Busca\Texto;
use Pbl\Conteudo\Classe;
use Pbl\Conteudo\ClasseRepositorio;

/**
 * Decide, para cada classe e propriedade do PBL3, onde ela vai parar no PBL4 (ADR 0027): as classes de fábrica do PBL3 (pasta,
 * documento, imagem, arquivo, link, interlink, notícia) caem nas classes padrão do PBL4; qualquer outra vira uma classe nova.
 * Nenhum campo com valor se perde: a propriedade do PBL3 que não tem par na classe padrão vira uma propriedade extra dessa classe
 * (criada na primeira importação, reaproveitada nas seguintes). Só é descartado o que não cabe de jeito nenhum (limite de
 * propriedades por classe) e o relatório diz o motivo.
 */
final class PlanoDeClasses
{
    /** Tipos de dado do PBL3 (`tipodado.cod_tipodado`). */
    public const TIPOS_PBL3 = [1 => 'blob', 2 => 'boolean', 3 => 'date', 4 => 'integer', 5 => 'float', 6 => 'objref', 7 => 'string', 8 => 'text'];

    /** @var array<string, array{0: string, 1: array<string, string>}> classe do PBL3 (prefixo ou nome) => [classe padrão, propriedade do PBL3 => propriedade do PBL4] */
    private const PADRAO = [
        'folder' => ['pasta', []],
        'pasta' => ['pasta', []],
        'document' => ['pagina', ['texto' => 'corpo', 'resumo' => 'resumo']],
        'documento' => ['pagina', ['texto' => 'corpo', 'resumo' => 'resumo']],
        'imagem' => ['imagem', ['conteudo' => 'imagem', 'legenda' => 'legenda']],
        'arquivo' => ['arquivo', ['conteudo' => 'arquivo']],
        'link' => ['link', ['endereco' => 'destino_externo']],
        'interlink' => ['link', ['endereco' => 'destino_interno']],
        'noticia' => ['noticia', ['texto' => 'corpo', 'resumo' => 'resumo', 'imagem' => 'imagem_destaque']],
    ];

    /** Tipos do PBL4 que aceitam cada tipo de dado do PBL3. */
    private const COMPATIVEL = [
        'blob' => ['imagem', 'arquivo'],
        'boolean' => ['booleano'],
        'date' => ['data'],
        'integer' => ['numero', 'ref'],
        'float' => ['numero'],
        'objref' => ['ref'],
        'string' => ['texto', 'html'],
        'text' => ['html', 'texto'],
    ];

    /** @var array<int, array{classe: Classe, padrao: bool}> cod_classe do PBL3 => classe de destino */
    public array $classes = [];
    /** @var array<int, array{nome: string, tipo: string, tipo3: string}> cod_propriedade => propriedade de destino */
    public array $propriedades = [];
    /** @var array<int, string> cod_propriedade => "classe.propriedade (motivo)" do que foi descartado */
    public array $descartadas = [];
    /** @var array<int, true> propriedades `incluirmenu` (viram o `no_menu` do objeto) */
    public array $menu = [];

    private function __construct()
    {
    }

    /**
     * Lê as classes e propriedades da origem e cria (ou reaproveita, se a importação já passou por aqui) as classes de destino.
     * Chamar dentro de uma transação.
     */
    public static function montar(OrigemPbl3 $origem, ClasseRepositorio $destino, MapaDeIds $ids, Relatorio $relatorio): self
    {
        $plano = new self();
        $porClasse = [];
        foreach ($origem->propriedades() as $p) {
            $porClasse[(int) $p['cod_classe']][] = $p;
        }
        $estatisticas = $origem->tiposDeBlobPorPropriedade(Conversao::ehImagem(...));
        foreach ($origem->classes() as $c) {
            $cod = (int) $c['cod_classe'];
            $chave = Texto::normalizar(trim((string) ($c['prefixo'] ?? '')) !== '' ? (string) $c['prefixo'] : (string) $c['nome']);
            $nomeOrigem = trim((string) ($c['prefixo'] ?? '')) !== '' ? (string) $c['prefixo'] : (string) $c['nome'];
            $propriedades = $porClasse[$cod] ?? [];
            $padrao = self::PADRAO[$chave] ?? null;
            $existente = $padrao === null ? null : $destino->porNome($padrao[0]);
            if ($padrao !== null && $existente !== null) {
                $classe = $plano->mapearPadrao($existente, $padrao[1], $propriedades, $nomeOrigem, $estatisticas, $destino, $relatorio);
                $plano->classes[$cod] = ['classe' => $classe, 'padrao' => true];
                $relatorio->contar('classes do PBL3 mapeadas para classes padrão');
                continue;
            }
            $plano->personalizada($cod, $c, $nomeOrigem, $propriedades, $estatisticas, $destino, $ids, $relatorio);
        }

        return $plano;
    }

    /**
     * @param array<string, string> $de
     * @param list<array<string, mixed>> $propriedades
     * @param array<int, array{imagens: int, outros: int}> $estatisticas
     */
    private function mapearPadrao(Classe $classe, array $de, array $propriedades, string $nomeOrigem, array $estatisticas, ClasseRepositorio $destino, Relatorio $relatorio): Classe
    {
        $definidas = [];
        foreach ($classe->propriedades as $p) {
            $definidas[$p['nome']] = $p['tipo'];
        }
        $usadas = [];
        $ocupados = [];
        $extras = [];
        foreach ($propriedades as $p) {
            $cod = (int) $p['cod_propriedade'];
            $nome = Texto::normalizar((string) $p['nome']);
            $tipo3 = self::TIPOS_PBL3[(int) $p['cod_tipodado']] ?? 'string';
            if ($nome === 'incluirmenu') {
                $this->menu[$cod] = true;
                continue;
            }
            $alvo = $de[$nome] ?? null;
            if ($alvo === null || isset($usadas[$alvo]) || !isset($definidas[$alvo]) || !in_array($definidas[$alvo], self::COMPATIVEL[$tipo3], true)) {
                $extras[$cod] = $this->extra($p, $nomeOrigem, $estatisticas[$cod] ?? null, $relatorio);
                continue;
            }
            $usadas[$alvo] = true;
            $ocupados[$alvo] = true;
            $this->propriedades[$cod] = ['nome' => $alvo, 'tipo' => $definidas[$alvo], 'tipo3' => $tipo3];
        }

        return $this->acrescentar($classe, $extras, $ocupados, $nomeOrigem, $destino, $relatorio);
    }

    /**
     * Propriedade do PBL3 que vai virar (ou reaproveitar) uma propriedade extra do destino.
     *
     * @param array<string, mixed> $p
     * @param ?array{imagens: int, outros: int} $estatistica
     * @return array{nome: string, tipo: string, tipo3: string, rotulo: string, origem: string}
     */
    private function extra(array $p, string $nomeOrigem, ?array $estatistica, Relatorio $relatorio): array
    {
        $tipo3 = self::TIPOS_PBL3[(int) $p['cod_tipodado']] ?? 'string';
        $nome = Conversao::nomeTecnico((string) $p['nome'], 'campo');
        if ((int) ($p['obrigatorio'] ?? 0) === 1) {
            $relatorio->avisar('propriedade obrigatória no PBL3 importada como opcional', $nomeOrigem . '.' . $nome);
        }

        return [
            'nome' => $nome, 'tipo' => self::tipoDeDestino($tipo3, $estatistica), 'tipo3' => $tipo3,
            'rotulo' => self::limpar((string) ($p['rotulo'] ?? ''), ClasseRepositorio::MAX_ROTULO), 'origem' => $nomeOrigem . '.' . $nome,
        ];
    }

    /**
     * Garante no destino uma propriedade para cada extra: reaproveita a de mesmo nome e tipo que não esteja em uso (é o que torna a
     * importação repetível) ou acrescenta ao fim da classe, com sufixo `_campo` se o nome é reservado ou tem outro tipo. Só o que não
     * cabe no limite de propriedades da classe é descartado. Nunca remove nem altera uma propriedade que já existe.
     *
     * @param array<int, array{nome: string, tipo: string, tipo3: string, rotulo: string, origem: string}> $extras
     * @param array<string, true> $ocupados nomes do destino que outra propriedade do PBL3 já ocupa
     */
    private function acrescentar(Classe $classe, array $extras, array $ocupados, string $nomeOrigem, ClasseRepositorio $destino, Relatorio $relatorio): Classe
    {
        if ($extras === []) {
            return $classe;
        }
        $lista = $classe->propriedades;
        $indice = [];
        foreach ($lista as $i => $p) {
            $indice[$p['nome']] = $i;
        }
        $criadas = 0;
        foreach ($extras as $cod => $e) {
            $escolhido = null;
            for ($k = 0; $k < 30 && $escolhido === null; ++$k) {
                $nome = $k === 0 ? $e['nome'] : substr($e['nome'], 0, 56) . '_campo' . ($k === 1 ? '' : (string) ($k - 1));
                if (in_array($nome, ClasseRepositorio::RESERVADOS, true) || isset($ocupados[$nome])) {
                    continue;
                }
                $i = $indice[$nome] ?? null;
                if ($i === null) {
                    if (count($lista) >= ClasseRepositorio::MAX_PROPRIEDADES) {
                        break;
                    }
                    $nova = ['nome' => $nome, 'tipo' => $e['tipo'], 'indexavel' => in_array($e['tipo'], ['texto', 'numero', 'booleano', 'data'], true)];
                    if ($e['rotulo'] !== '') {
                        $nova['rotulo'] = $e['rotulo'];
                    }
                    $indice[$nome] = count($lista);
                    $lista[] = $nova;
                    ++$criadas;
                    $relatorio->avisar('propriedade extra criada na classe do PBL4 (campo do PBL3 sem par)', $classe->nome . '.' . $nome);
                    $escolhido = $nome;
                } elseif ($lista[$i]['tipo'] === $e['tipo'] && !($lista[$i]['arquivada'] ?? false)) {
                    $escolhido = $nome;
                }
            }
            if ($escolhido === null) {
                $this->descartadas[$cod] = $e['origem'] . ' (a classe ' . $classe->nome . ' já está no limite de ' . ClasseRepositorio::MAX_PROPRIEDADES . ' propriedades)';
                continue;
            }
            if ($escolhido !== $e['nome']) {
                $relatorio->avisar('propriedade renomeada (nome reservado ou repetido)', $nomeOrigem . '.' . $e['nome'] . ' => ' . $escolhido);
            }
            $ocupados[$escolhido] = true;
            $this->propriedades[$cod] = ['nome' => $escolhido, 'tipo' => $e['tipo'], 'tipo3' => $e['tipo3']];
        }
        if ($criadas > 0) {
            $classe = $destino->atualizar($classe->id, $classe->versao, $lista, ['descricao' => $classe->descricao, 'aceita_filhos' => $classe->aceitaFilhos, 'filhas' => $classe->filhas]);
            $relatorio->contar('propriedades extras criadas em classes existentes', $criadas);
        }

        return $classe;
    }

    /**
     * @param array<string, mixed> $c
     * @param list<array<string, mixed>> $propriedades
     * @param array<int, array{imagens: int, outros: int}> $estatisticas
     */
    private function personalizada(int $cod, array $c, string $nomeOrigem, array $propriedades, array $estatisticas, ClasseRepositorio $destino, MapaDeIds $ids, Relatorio $relatorio): void
    {
        $definicao = [];
        $nomes = [];
        $planejado = [];
        foreach ($propriedades as $p) {
            $codP = (int) $p['cod_propriedade'];
            $original = Texto::normalizar((string) $p['nome']);
            if ($original === 'incluirmenu') {
                $this->menu[$codP] = true;
                continue;
            }
            if (count($definicao) >= ClasseRepositorio::MAX_PROPRIEDADES) {
                $this->descartadas[$codP] = $nomeOrigem . '.' . $original . ' (a classe já está no limite de ' . ClasseRepositorio::MAX_PROPRIEDADES . ' propriedades)';
                continue;
            }
            $tipo3 = self::TIPOS_PBL3[(int) $p['cod_tipodado']] ?? 'string';
            $tipo = self::tipoDeDestino($tipo3, $estatisticas[$codP] ?? null);
            $nome = Conversao::nomeTecnico((string) $p['nome'], 'campo');
            if (in_array($nome, ClasseRepositorio::RESERVADOS, true) || isset($nomes[$nome])) {
                $novo = $nome;
                for ($i = 0; in_array($novo, ClasseRepositorio::RESERVADOS, true) || isset($nomes[$novo]); ++$i) {
                    $novo = substr($nome, 0, 56) . '_campo' . ($i === 0 ? '' : (string) $i);
                }
                $relatorio->avisar('propriedade renomeada (nome reservado ou repetido)', $nomeOrigem . '.' . $nome . ' => ' . $novo);
                $nome = $novo;
            }
            $nomes[$nome] = true;
            $nova = ['nome' => $nome, 'tipo' => $tipo, 'indexavel' => in_array($tipo, ['texto', 'numero', 'booleano', 'data'], true)];
            $rotulo = self::limpar((string) ($p['rotulo'] ?? ''), ClasseRepositorio::MAX_ROTULO);
            if ($rotulo !== '') {
                $nova['rotulo'] = $rotulo;
            }
            $definicao[] = $nova;
            $planejado[$codP] = ['nome' => $nome, 'tipo' => $tipo, 'tipo3' => $tipo3, 'rotulo' => $rotulo, 'origem' => $nomeOrigem . '.' . $nome];
            if ((int) ($p['obrigatorio'] ?? 0) === 1) {
                $relatorio->avisar('propriedade obrigatória no PBL3 importada como opcional', $nomeOrigem . '.' . $nome);
            }
        }
        $existente = null;
        $mapeado = $ids->obter(MapaDeIds::CLASSE, $cod);
        if ($mapeado !== null) {
            $existente = $destino->porId($mapeado);
        }
        if ($existente === null) {
            $base = Conversao::nomeTecnico($nomeOrigem, 'classe');
            $nomeClasse = $base;
            for ($i = 2; $destino->porNome($nomeClasse) !== null; ++$i) {
                $nomeClasse = substr($base, 0, 58) . '_pbl3' . ($i === 2 ? '' : (string) $i);
            }
            if ($nomeClasse !== $base) {
                $relatorio->avisar('classe renomeada (o nome já existia)', $base . ' => ' . $nomeClasse);
            }
            $existente = $destino->criar($nomeClasse, $definicao, ['descricao' => self::limpar((string) ($c['descricao'] ?? ''), ClasseRepositorio::MAX_DESCRICAO), 'aceita_filhos' => true, 'filhas' => []]);
            $ids->gravar(MapaDeIds::CLASSE, $cod, $existente->id);
            $relatorio->contar('classes criadas');
            foreach ($planejado as $codP => $p) {
                $this->propriedades[$codP] = $p;
            }
        } else {
            $existente = $this->acrescentar($existente, $planejado, [], $nomeOrigem, $destino, $relatorio);
            $relatorio->contar('classes já importadas (reaproveitadas)');
        }
        $this->classes[$cod] = ['classe' => $existente, 'padrao' => false];
        $relatorio->contar('propriedades importadas', count($planejado));
    }

    /** @param ?array{imagens: int, outros: int} $estatistica */
    private static function tipoDeDestino(string $tipo3, ?array $estatistica): string
    {
        return match ($tipo3) {
            'blob' => $estatistica !== null && $estatistica['imagens'] > 0 && $estatistica['imagens'] >= $estatistica['outros'] ? 'imagem' : 'arquivo',
            'boolean' => 'booleano',
            'date' => 'data',
            'integer', 'float' => 'numero',
            'objref' => 'ref',
            'text' => 'html',
            default => 'texto',
        };
    }

    private static function limpar(string $texto, int $max): string
    {
        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1');
        }

        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $texto)), 0, $max);
    }
}
