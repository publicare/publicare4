<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/** Gera PHP a partir da árvore. Só emite literais escapados com var_export: nada do molde vira código. */
final class Gerador
{
    /** @param list<No> $arvore */
    public function gerar(array $arvore): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\n"
            . "return static function (array \$c0, \\Pbl\\Linguagem\\Runtime \$r): string {\n"
            . "    ob_start();\n"
            . $this->nos($arvore, 0)
            . "    return (string) ob_get_clean();\n"
            . "};\n";
    }

    /** @param list<No> $nos */
    private function nos(array $nos, int $nivel): string
    {
        $codigo = '';
        $ctx = '$c' . $nivel;
        foreach ($nos as $no) {
            if ($no instanceof NoTexto) {
                $codigo .= '    echo ' . var_export($no->valor, true) . ";\n";
            } elseif ($no instanceof NoEco) {
                $valor = "\$r->campo({$ctx}, " . var_export($no->campo, true) . ')';
                $codigo .= $no->cru ? "    echo \$r->cru({$valor});\n" : "    echo \$r->esc({$valor});\n";
            } elseif ($no instanceof NoSe) {
                $codigo .= "    if (\$r->verdade(\$r->campo({$ctx}, " . var_export($no->campo, true) . "))) {\n"
                    . $this->nos($no->entao, $nivel)
                    . "    } else {\n"
                    . $this->nos($no->senao, $nivel)
                    . "    }\n";
            } elseif ($no instanceof NoRepetir) {
                $proximo = '$c' . ($nivel + 1);
                $codigo .= "    foreach (\$r->lista({$ctx}, " . var_export($no->fonte, true) . ") as {$proximo}) {\n"
                    . $this->nos($no->corpo, $nivel + 1)
                    . "    }\n";
            }
        }

        return $codigo;
    }
}
