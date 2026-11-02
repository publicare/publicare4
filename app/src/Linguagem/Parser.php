<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** Constrói a árvore a partir dos tokens, validando comandos, parâmetros e aninhamento. */
final class Parser
{
    private const PARAMS = [
        'eco_limite' => ['texto', 'limite', 'inicio'],
        'formatadata' => ['valor', 'formato'],
        'filhos' => ['nome', 'classes', 'ordem', 'inicio', 'limite', 'expandir'],
        'localizar' => ['nome', 'classes', 'ordem', 'inicio', 'limite', 'pai', 'niveis', 'campo', 'op', 'valor', 'campo2', 'op2', 'valor2', 'campo3', 'op3', 'valor3', 'busca', 'expandir'],
        'usarobjeto' => ['id', 'url', 'titulo'],
        'temfilho' => ['id'],
        'formulario' => ['id'],
        'menu' => ['profundidade', 'inicio'],
        'arquivo' => ['valor', 'info'],
        'miniatura' => ['valor', 'tamanho'],
    ];

    /** comando de abertura => [tipo da coleção, aleatório] */
    private const COLECOES = [
        'filhos' => ['filhos', false],
        'aleatorio' => ['filhos', true],
        'localizar' => ['localizar', false],
        'localizaraleatorio' => ['localizar', true],
    ];

    private const FECHAVEIS = ['se', 'repetir', 'filhos', 'aleatorio', 'localizar', 'localizaraleatorio', 'semfilhos', 'naolocalizado', 'usarobjeto', 'temfilho', 'bloco'];

    public function __construct(
        private readonly Lexer $lexer = new Lexer(),
        private readonly ExpressaoParser $expr = new ExpressaoParser(),
    ) {
    }

    /** @return list<No> */
    public function analisar(string $fonte): array
    {
        $raiz = [];
        /** @var list<array{no: NoSe|NoRepetir|NoColecao|NoSemResultado|NoUsarObjeto|NoTemFilho|NoBloco, nome: string, linha: int, naSenao: bool}> $pilha */
        $pilha = [];
        $primeiro = true;

        $adicionar = function (No $no) use (&$raiz, &$pilha): void {
            if ($pilha === []) {
                $raiz[] = $no;

                return;
            }
            $topo = $pilha[count($pilha) - 1]['no'];
            if ($topo instanceof NoSe) {
                if ($pilha[count($pilha) - 1]['naSenao']) {
                    $topo->senao[] = $no;
                } else {
                    $topo->entao[] = $no;
                }
            } else {
                $topo->corpo[] = $no;
            }
        };

        foreach ($this->lexer->separar($fonte) as $t) {
            if ($t['tipo'] === 'texto') {
                if ($t['valor'] !== '' && trim($t['valor']) !== '') {
                    $primeiro = false;
                }
                $adicionar(new NoTexto($t['valor']));
                continue;
            }
            $linha = $t['linha'];
            $nome = $t['nome'];
            $resto = $t['resto'];

            if ($t['fecha']) {
                $this->fechar($nome, $resto, $linha, $pilha, $adicionar);
                $primeiro = false;
                continue;
            }

            if ($nome === 'estende') {
                if (!$primeiro || $pilha !== []) {
                    throw new ErroSintaxe('estende precisa ser o primeiro comando do molde', $linha);
                }
                $arquivo = $this->expr->expressao($resto, $linha);
                if (!$arquivo instanceof ExprLiteral || !is_string($arquivo->valor)) {
                    throw new ErroSintaxe('estende exige o nome do layout entre aspas', $linha);
                }
                $adicionar(new NoEstende($arquivo->valor, $linha));
                $primeiro = false;
                continue;
            }
            $primeiro = false;

            switch ($nome) {
                case '=':
                case 'eco':
                case 'ecocru':
                    $adicionar(new NoEco($this->expr->expressao($resto, $linha), $nome === 'ecocru'));
                    break;
                case 'eco_limite':
                    $p = $this->parametros($nome, $resto, $linha, ['texto']);
                    $adicionar(new NoEco($p['texto'], false, 'cortar', [
                        $p['limite'] ?? new ExprLiteral(-1),
                        $p['inicio'] ?? new ExprLiteral(0),
                    ]));
                    break;
                case 'formatadata':
                    $p = $this->parametros($nome, $resto, $linha, ['valor', 'formato']);
                    $adicionar(new NoEco($p['valor'], false, 'data', [$p['formato']]));
                    break;
                case 'var':
                    [$var, $valor] = $this->expr->atribuicao($resto, $linha);
                    $adicionar(new NoVar($var, $valor));
                    break;
                case 'se':
                    $pilha[] = ['no' => new NoSe($this->expr->condicao($resto, $linha)), 'nome' => 'se', 'linha' => $linha, 'naSenao' => false];
                    break;
                case 'senao':
                    $i = count($pilha) - 1;
                    if ($resto !== '' || $i < 0 || $pilha[$i]['nome'] !== 'se' || $pilha[$i]['naSenao']) {
                        throw new ErroSintaxe('senao fora de um se', $linha);
                    }
                    $pilha[$i]['naSenao'] = true;
                    break;
                case 'repetir':
                    [$var, $ini, $fim] = $this->expr->intervalo($resto, $linha);
                    $pilha[] = ['no' => new NoRepetir($var, $ini, $fim), 'nome' => 'repetir', 'linha' => $linha, 'naSenao' => false];
                    break;
                case 'filhos':
                case 'aleatorio':
                case 'localizar':
                case 'localizaraleatorio':
                    [$tipo, $aleatorio] = self::COLECOES[$nome];
                    $pilha[] = ['no' => new NoColecao($tipo, $this->parametros($tipo, $resto, $linha, []), $aleatorio), 'nome' => $nome, 'linha' => $linha, 'naSenao' => false];
                    break;
                case 'usarobjeto':
                    $params = $this->parametros($nome, $resto, $linha, []);
                    if ($params === []) {
                        throw new ErroSintaxe('usarobjeto exige id, url ou titulo', $linha);
                    }
                    $pilha[] = ['no' => new NoUsarObjeto($params), 'nome' => $nome, 'linha' => $linha, 'naSenao' => false];
                    break;
                case 'temfilho':
                    $pilha[] = ['no' => new NoTemFilho($this->parametros($nome, $resto, $linha, [])), 'nome' => $nome, 'linha' => $linha, 'naSenao' => false];
                    break;
                case 'semfilhos':
                case 'naolocalizado':
                    $this->semArgumentos($nome, $resto, $linha);
                    $pilha[] = ['no' => new NoSemResultado(), 'nome' => $nome, 'linha' => $linha, 'naSenao' => false];
                    break;
                case 'bloco':
                    $id = strtolower($resto);
                    if (preg_match('/^[a-z_][a-z0-9_]*$/', $id) !== 1) {
                        throw new ErroSintaxe('bloco exige um nome (letras, números e _)', $linha);
                    }
                    $pilha[] = ['no' => new NoBloco($id, [], $linha), 'nome' => 'bloco', 'linha' => $linha, 'naSenao' => false];
                    break;
                case 'formulario':
                    $adicionar(new NoFormulario($this->parametros($nome, $resto, $linha, [])));
                    break;
                case 'menu':
                    $adicionar(new NoMenu($this->parametros($nome, $resto, $linha, [])));
                    break;
                case 'arquivo':
                    $adicionar(new NoArquivo($this->parametros($nome, $resto, $linha, ['valor', 'info'])));
                    break;
                case 'miniatura':
                    $adicionar(new NoMiniatura($this->parametros($nome, $resto, $linha, ['valor', 'tamanho'])));
                    break;
                case 'incluir':
                    $p = $this->parametros($nome, $resto, $linha, ['arquivo']);
                    $adicionar(new NoIncluir($p['arquivo']));
                    break;
                default:
                    throw new ErroSintaxe("Comando desconhecido: {$nome}", $linha);
            }
        }

        if ($pilha !== []) {
            $aberto = $pilha[count($pilha) - 1];
            throw new ErroSintaxe("Comando {$aberto['nome']} não foi fechado", $aberto['linha']);
        }

        return $raiz;
    }

    /**
     * @param list<array{no: NoSe|NoRepetir|NoColecao|NoSemResultado|NoUsarObjeto|NoTemFilho|NoBloco, nome: string, linha: int, naSenao: bool}> $pilha
     */
    private function fechar(string $nome, string $resto, int $linha, array &$pilha, \Closure $adicionar): void
    {
        if ($resto !== '' || !in_array($nome, self::FECHAVEIS, true)) {
            throw new ErroSintaxe("Fechamento inválido: /{$nome}", $linha);
        }
        $topo = array_pop($pilha);
        if ($topo === null || $topo['nome'] !== $nome) {
            throw new ErroSintaxe("Fechamento sem abertura correspondente: /{$nome}", $linha);
        }
        $adicionar($topo['no']);
    }

    /**
     * @param list<string> $obrigatorios
     * @return array<string, Expr>
     */
    private function parametros(string $comando, string $resto, int $linha, array $obrigatorios): array
    {
        $params = $this->expr->parametros($resto, $linha);
        $aceitos = self::PARAMS[$comando] ?? $obrigatorios;
        foreach (array_keys($params) as $nome) {
            if (!in_array($nome, $aceitos, true)) {
                throw new ErroSintaxe("Parâmetro desconhecido em {$comando}: {$nome}", $linha);
            }
        }
        foreach ($obrigatorios as $nome) {
            if (!isset($params[$nome])) {
                throw new ErroSintaxe("O comando {$comando} exige o parâmetro {$nome}", $linha);
            }
        }

        return $params;
    }

    private function semArgumentos(string $nome, string $resto, int $linha): void
    {
        if ($resto !== '') {
            throw new ErroSintaxe("O comando {$nome} não recebe argumentos", $linha);
        }
    }
}
