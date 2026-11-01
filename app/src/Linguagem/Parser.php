<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** Constrói a árvore a partir dos tokens e valida comandos e parâmetros. */
final class Parser
{
    public function __construct(private readonly Lexer $lexer = new Lexer())
    {
    }

    /** @return list<No> */
    public function analisar(string $fonte): array
    {
        $raiz = [];
        /** @var list<array{no: NoSe|NoRepetir, nome: string, linha: int, naSenao: bool}> $pilha */
        $pilha = [];

        $adicionar = function (No $no) use (&$raiz, &$pilha): void {
            if ($pilha === []) {
                $raiz[] = $no;

                return;
            }
            $topo = &$pilha[count($pilha) - 1];
            if ($topo['no'] instanceof NoRepetir) {
                $topo['no']->corpo[] = $no;
            } elseif ($topo['naSenao']) {
                $topo['no']->senao[] = $no;
            } else {
                $topo['no']->entao[] = $no;
            }
        };

        foreach ($this->lexer->separar($fonte) as $t) {
            if ($t['tipo'] === 'texto') {
                $adicionar(new NoTexto($t['valor']));
                continue;
            }
            $linha = $t['linha'];
            $nome = $t['nome'];
            $arg = $t['arg'];

            if ($t['fecha']) {
                if ($arg !== null || !in_array($nome, ['se', 'repetir'], true)) {
                    throw new ErroSintaxe("Fechamento inválido: /{$nome}", $linha);
                }
                $topo = array_pop($pilha);
                if ($topo === null || $topo['nome'] !== $nome) {
                    throw new ErroSintaxe("Fechamento sem abertura correspondente: /{$nome}", $linha);
                }
                $adicionar($topo['no']);
                continue;
            }

            switch ($nome) {
                case 'eco':
                case 'ecocru':
                    $adicionar(new NoEco($this->exigir($arg, $nome, $linha), $nome === 'ecocru'));
                    break;
                case 'se':
                    $pilha[] = ['no' => new NoSe($this->exigir($arg, $nome, $linha)), 'nome' => 'se', 'linha' => $linha, 'naSenao' => false];
                    break;
                case 'senao':
                    if ($arg !== null || $pilha === [] || $pilha[count($pilha) - 1]['nome'] !== 'se' || $pilha[count($pilha) - 1]['naSenao']) {
                        throw new ErroSintaxe('senao fora de um se', $linha);
                    }
                    $pilha[count($pilha) - 1]['naSenao'] = true;
                    break;
                case 'repetir':
                    if ($this->exigir($arg, $nome, $linha) !== 'filhos') {
                        throw new ErroSintaxe('repetir só aceita a fonte "filhos"', $linha);
                    }
                    $pilha[] = ['no' => new NoRepetir('filhos'), 'nome' => 'repetir', 'linha' => $linha, 'naSenao' => false];
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

    private function exigir(?string $arg, string $nome, int $linha): string
    {
        if ($arg === null) {
            throw new ErroSintaxe("O comando {$nome} exige um campo", $linha);
        }

        return $arg;
    }
}
