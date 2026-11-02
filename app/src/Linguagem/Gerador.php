<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** Gera PHP a partir da árvore. Só emite literais via var_export e chamadas ao Runtime: nada do molde vira código. */
final class Gerador
{
    /** @param list<No> $arvore */
    public function gerar(array $arvore): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\n"
            . "return static function (array \$o0, \\Pbl\\Linguagem\\Runtime \$r): string {\n"
            . "    ob_start();\n"
            . "    try {\n"
            . $this->nos($arvore, 0)
            . "    } catch (\\Throwable \$e) {\n"
            . "        ob_end_clean();\n"
            . "        throw \$e;\n"
            . "    }\n"
            . "    return (string) ob_get_clean();\n"
            . "};\n";
    }

    /** @param list<No> $nos */
    private function nos(array $nos, int $nivel): string
    {
        $codigo = '';
        $o = '$o' . $nivel;
        foreach ($nos as $no) {
            if ($no instanceof NoTexto) {
                $codigo .= '    echo ' . var_export($no->valor, true) . ";\n";
            } elseif ($no instanceof NoEco) {
                $codigo .= '    echo ' . $this->eco($no, $nivel) . ";\n";
            } elseif ($no instanceof NoVar) {
                $codigo .= '    $r->definir(' . var_export($no->nome, true) . ', ' . $this->expr($no->valor, $nivel) . ");\n";
            } elseif ($no instanceof NoSe) {
                $codigo .= '    if ($r->verdade(' . $this->expr($no->condicao, $nivel) . ")) {\n"
                    . $this->nos($no->entao, $nivel)
                    . "    } else {\n"
                    . $this->nos($no->senao, $nivel)
                    . "    }\n";
            } elseif ($no instanceof NoRepetir) {
                $codigo .= '    foreach ($r->faixa(' . $this->expr($no->inicio, $nivel) . ', ' . $this->expr($no->fim, $nivel) . ") as \$n{$nivel}) {\n"
                    . '        $r->definir(' . var_export($no->variavel, true) . ", \$n{$nivel});\n"
                    . $this->nos($no->corpo, $nivel)
                    . "    }\n";
            } elseif ($no instanceof NoColecao) {
                $filho = '$o' . ($nivel + 1);
                // Em `aleatorio` a janela (inicio/limite) vale depois de embaralhar; a consulta traz todos os candidatos.
                $params = $no->aleatorio ? array_diff_key($no->params, ['inicio' => 1, 'limite' => 1]) : $no->params;
                $consulta = $no->tipo === 'localizar'
                    ? "\$r->localizar({$o}, " . $this->mapa($params, $nivel) . ')'
                    : "\$r->filhos({$o}, " . $this->mapa($params, $nivel) . ')';
                if ($no->aleatorio) {
                    $consulta = "\$r->embaralhar({$consulta}, "
                        . $this->expr($no->params['inicio'] ?? new ExprLiteral(0), $nivel) . ', '
                        . $this->expr($no->params['limite'] ?? new ExprLiteral(-1), $nivel) . ')';
                }
                $codigo .= "    \$l{$nivel} = {$consulta};\n"
                    . "    \$r->abrirColecao(count(\$l{$nivel}));\n"
                    . "    foreach (\$l{$nivel} as \$i{$nivel} => {$filho}) {\n"
                    . "        \$r->itemDaColecao(\$i{$nivel} + 1);\n"
                    . $this->nos($no->corpo, $nivel + 1)
                    . "    }\n"
                    . "    \$r->fecharColecao();\n";
            } elseif ($no instanceof NoUsarObjeto) {
                $alvo = '$o' . ($nivel + 1);
                $codigo .= "    {$alvo} = \$r->objeto({$o}, " . $this->mapa($no->params, $nivel) . ");\n"
                    . "    if ({$alvo} !== null) {\n"
                    . $this->nos($no->corpo, $nivel + 1)
                    . "    }\n";
            } elseif ($no instanceof NoTemFilho) {
                $codigo .= "    if (\$r->temFilhos({$o}, " . $this->mapa($no->params, $nivel) . ")) {\n"
                    . $this->nos($no->corpo, $nivel)
                    . "    }\n";
            } elseif ($no instanceof NoSemResultado) {
                $codigo .= "    if (\$r->semResultado()) {\n" . $this->nos($no->corpo, $nivel) . "    }\n";
            } elseif ($no instanceof NoBloco) {
                $codigo .= $this->nos($no->corpo, $nivel);
            } elseif ($no instanceof NoFormulario) {
                $codigo .= "    echo \$r->formulario({$o}, " . $this->mapa($no->params, $nivel) . ");\n";
            } elseif ($no instanceof NoIncluir) {
                $codigo .= '    echo $r->incluir(' . $this->expr($no->arquivo, $nivel) . ", {$o});\n";
            }
        }

        return $codigo;
    }

    /** @param array<string, Expr> $params */
    private function mapa(array $params, int $nivel): string
    {
        $itens = [];
        foreach ($params as $nome => $expr) {
            $itens[] = var_export($nome, true) . ' => ' . $this->expr($expr, $nivel);
        }

        return '[' . implode(', ', $itens) . ']';
    }

    private function eco(NoEco $no, int $nivel): string
    {
        $valor = $this->expr($no->expr, $nivel);
        $valor = match ($no->funcao) {
            'cortar' => '$r->cortar(' . $valor . ', ' . $this->expr($no->extras[0], $nivel) . ', ' . $this->expr($no->extras[1], $nivel) . ')',
            'data' => '$r->data(' . $valor . ', ' . $this->expr($no->extras[0], $nivel) . ')',
            default => $valor,
        };

        return ($no->cru ? '$r->cru(' : '$r->esc(') . $valor . ')';
    }

    private function expr(Expr $e, int $nivel): string
    {
        if ($e instanceof ExprLiteral) {
            return var_export($e->valor, true);
        }
        if ($e instanceof ExprCampo) {
            return '$r->campo($o' . $nivel . ', ' . var_export($e->nome, true) . ')';
        }
        if ($e instanceof ExprVar) {
            return '$r->variavel(' . var_export($e->nome, true) . ')';
        }
        if ($e instanceof ExprMacro) {
            return '$r->macro(' . var_export($e->nome, true) . ')';
        }
        if ($e instanceof ExprBin) {
            $a = $this->expr($e->esq, $nivel);
            $b = $this->expr($e->dir, $nivel);

            return match (true) {
                $e->op === '.' => "\$r->cat({$a}, {$b})",
                $e->ehComparacao() => "\$r->compara({$a}, " . var_export($e->op, true) . ", {$b})",
                default => '$r->aritmetica(' . var_export($e->op, true) . ", {$a}, {$b})",
            };
        }

        throw new \LogicException('Expressão desconhecida');
    }
}
