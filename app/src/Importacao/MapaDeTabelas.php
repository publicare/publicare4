<?php

declare(strict_types=1);

namespace Pbl\Importacao;

/**
 * Nomes físicos das tabelas e colunas da base do PBL3. No PBL3 eles são configuráveis (`$PBLCONFIG["bd"]["tabelas"]`); este mapa
 * começa pelos nomes padrão e aceita o mesmo tipo de ajuste: `{"objeto": {"nome": "obj", "colunas": {"cod_objeto": "id"}}}`.
 * Só se diz o que é diferente do padrão; tabela e coluna lógicas que não existem são recusadas (erro de digitação não passa).
 */
final class MapaDeTabelas
{
    /** @var array<string, list<string>> tabela lógica => colunas lógicas que o importador lê */
    public const COLUNAS = [
        'classe' => ['cod_classe', 'nome', 'prefixo', 'descricao', 'temfilhos'],
        'propriedade' => ['cod_propriedade', 'cod_classe', 'cod_tipodado', 'cod_referencia_classe', 'campo_ref', 'nome', 'posicao', 'descricao', 'rotulo', 'obrigatorio'],
        'objeto' => ['cod_objeto', 'cod_pai', 'cod_classe', 'cod_usuario', 'cod_pele', 'cod_status', 'titulo', 'descricao', 'data_publicacao', 'data_validade', 'apagado', 'objetosistema', 'peso', 'url_amigavel', 'versao', 'incluirmenu'],
        'tbl_string' => ['cod_objeto', 'cod_propriedade', 'valor'],
        'tbl_text' => ['cod_objeto', 'cod_propriedade', 'valor'],
        'tbl_integer' => ['cod_objeto', 'cod_propriedade', 'valor'],
        'tbl_float' => ['cod_objeto', 'cod_propriedade', 'valor'],
        'tbl_date' => ['cod_objeto', 'cod_propriedade', 'valor'],
        'tbl_boolean' => ['cod_objeto', 'cod_propriedade', 'valor'],
        'tbl_objref' => ['cod_objref', 'cod_objeto', 'cod_propriedade', 'valor'],
        'tbl_blob' => ['cod_blob', 'cod_objeto', 'cod_propriedade', 'arquivo'],
        'versaoobjeto' => ['cod_objeto', 'versao', 'conteudo', 'data_criacao', 'cod_usuario'],
        'usuario' => ['cod_usuario', 'nome', 'login', 'email', 'valido'],
        'perfil' => ['cod_perfil', 'nome'],
        'usuarioxobjetoxperfil' => ['cod_usuario', 'cod_objeto', 'cod_perfil'],
        'pele' => ['cod_pele', 'nome', 'prefixo'],
        'tag' => ['cod_tag', 'nome_tag'],
        'tagxobjeto' => ['cod_tag', 'cod_objeto'],
    ];

    /**
     * Colunas que nem toda base tem: se não existirem, são simplesmente ignoradas (sem erro). `incluirmenu` aparece em algumas bases
     * do PBL3 e corresponde ao `no_menu` do PBL4.
     *
     * @var array<string, list<string>>
     */
    public const OPCIONAIS = ['objeto' => ['incluirmenu', 'objetosistema']];

    /** Tabelas sem as quais não há o que importar; as outras só deixam de ser lidas, com aviso. */
    public const OBRIGATORIAS = ['classe', 'propriedade', 'objeto'];

    /**
     * Grafias conhecidas que divergem do padrão: o SQL de estrutura do MySQL do PBL3 escreve `cod_obeto` em `tbl_blob`, enquanto a
     * configuração padrão e o PostgreSQL usam `cod_objeto`. O importador tenta a alternativa quando a coluna esperada não existe.
     *
     * @var array<string, array<string, string>>
     */
    public const ALTERNATIVAS = ['tbl_blob' => ['cod_objeto' => 'cod_obeto']];

    /** @param array<string, array{nome: string, colunas: array<string, string>}> $mapa */
    private function __construct(private readonly array $mapa)
    {
    }

    public static function padrao(): self
    {
        $mapa = [];
        foreach (self::COLUNAS as $tabela => $colunas) {
            $mapa[$tabela] = ['nome' => $tabela, 'colunas' => array_combine($colunas, $colunas)];
        }

        return new self($mapa);
    }

    /**
     * @param array<mixed> $ajustes o conteúdo do arquivo de `--mapa-tabelas`
     * @throws \InvalidArgumentException
     */
    public function com(array $ajustes): self
    {
        $mapa = $this->mapa;
        foreach ($ajustes as $tabela => $dados) {
            if (!is_string($tabela) || !isset($mapa[$tabela])) {
                throw new \InvalidArgumentException('Tabela desconhecida no mapa: ' . (is_string($tabela) ? $tabela : '?') . '. As válidas: ' . implode(', ', array_keys($mapa)));
            }
            if (!is_array($dados)) {
                throw new \InvalidArgumentException("O mapa de {$tabela} deve ser um objeto com \"nome\" e/ou \"colunas\"");
            }
            if (isset($dados['nome'])) {
                $mapa[$tabela]['nome'] = self::identificador($dados['nome'], "nome de {$tabela}");
            }
            foreach (is_array($dados['colunas'] ?? null) ? $dados['colunas'] : [] as $logica => $fisica) {
                if (!is_string($logica) || !isset($mapa[$tabela]['colunas'][$logica])) {
                    throw new \InvalidArgumentException("Coluna desconhecida em {$tabela}: " . (is_string($logica) ? $logica : '?'));
                }
                $mapa[$tabela]['colunas'][$logica] = self::identificador($fisica, "coluna {$logica} de {$tabela}");
            }
        }

        return new self($mapa);
    }

    /** @throws \InvalidArgumentException */
    public static function doArquivo(string $arquivo): self
    {
        $json = is_file($arquivo) ? file_get_contents($arquivo) : false;
        if ($json === false) {
            throw new \InvalidArgumentException("Não consegui ler o mapa de tabelas: {$arquivo}");
        }
        $dados = json_decode($json, true);
        if (!is_array($dados)) {
            throw new \InvalidArgumentException("O mapa de tabelas não é um JSON válido: {$arquivo}");
        }

        return self::padrao()->com($dados);
    }

    public function tabela(string $logica): string
    {
        return $this->mapa[$logica]['nome'] ?? throw new \InvalidArgumentException("Tabela lógica desconhecida: {$logica}");
    }

    public function coluna(string $logica, string $coluna): string
    {
        return $this->mapa[$logica]['colunas'][$coluna] ?? throw new \InvalidArgumentException("Coluna lógica desconhecida: {$logica}.{$coluna}");
    }

    /** Nome físico: só letras, números, sublinhado e (na tabela) um ponto para esquema.banco; nada que dê para injetar. */
    private static function identificador(mixed $valor, string $onde): string
    {
        if (!is_string($valor) || preg_match('/^[A-Za-z_][A-Za-z0-9_$]{0,63}(\.[A-Za-z_][A-Za-z0-9_$]{0,63})?$/D', $valor) !== 1) {
            throw new \InvalidArgumentException("Nome físico inválido para {$onde}");
        }

        return $valor;
    }
}
