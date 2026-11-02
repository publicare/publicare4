<?php

declare(strict_types=1);

namespace Pbl\Linguagem;

/**
 * Realce de sintaxe dos moldes no servidor (fase 5j, ADR 0012 §9): usa o próprio `Lexer` para separar texto, tags e
 * comentários, e devolve HTML já escapado, com uma `<span>` por tipo de token (`pbl-rc-tag`, `pbl-rc-nome`,
 * `pbl-rc-string`, `pbl-rc-comentario`; as cores ficam em `public/_pbl/assets/pbl.css`). Só para leitura: a tela usa
 * isto na visualização somente-leitura, na diferença entre versões e no histórico; a caixa de edição continua uma
 * `<textarea>` simples (sem JavaScript, ADR 0012). Nunca lança: um molde que nem o `Lexer` consegue separar (tag
 * malformada numa versão antiga, por exemplo) volta como texto simples escapado, em vez de quebrar a tela.
 *
 * Processa o texto inteiro de uma vez só; quem mostra diferença linha a linha chama de novo para cada linha (uma
 * tag que atravessa duas linhas perde o realce nessa linha e aparece como texto simples — limitação conhecida,
 * documentada no ADR 0012 §9, sem JavaScript para resolver de outro jeito).
 */
final class Realce
{
    public function __construct(private readonly Lexer $lexer = new Lexer())
    {
    }

    public function html(string $fonte): string
    {
        try {
            $tokens = $this->lexer->separarComComentarios($fonte);
        } catch (ErroSintaxe) {
            return $this->escapar($fonte);
        }
        $html = '';
        foreach ($tokens as $t) {
            $html .= match ($t['tipo']) {
                'texto' => $this->escapar($t['valor']),
                'comentario' => '<span class="pbl-rc-comentario">' . $this->escapar($t['valor']) . '</span>',
                'tag' => $this->tag($t),
            };
        }

        return $html;
    }

    /** @param array{tipo: 'tag', fecha: bool, nome: string, resto: string, linha: int} $t */
    private function tag(array $t): string
    {
        $abertura = '<span class="pbl-rc-tag">' . $this->escapar('<@' . ($t['fecha'] ? '/' : '')) . '</span>';
        $nome = '<span class="pbl-rc-nome">' . $this->escapar($t['nome']) . '</span>';
        $resto = $t['resto'] === '' ? '' : ' ' . $this->argumentos($t['resto']);

        return $abertura . $nome . $resto . '<span class="pbl-rc-tag">' . $this->escapar('@>') . '</span>';
    }

    /** Dentro dos argumentos de uma tag, realça só as strings entre aspas (como o `Lexer::fimDaTag` as reconhece). */
    private function argumentos(string $resto): string
    {
        $html = '';
        $pendente = '';
        $n = strlen($resto);
        $i = 0;
        while ($i < $n) {
            $c = $resto[$i];
            if ($c !== '"' && $c !== "'") {
                $pendente .= $c;
                $i++;
                continue;
            }
            $html .= $this->escapar($pendente);
            $pendente = '';
            $aspas = $c;
            $str = $c;
            $i++;
            while ($i < $n) {
                $cc = $resto[$i];
                $str .= $cc;
                $i++;
                if ($cc === '\\' && $i < $n) {
                    $str .= $resto[$i];
                    $i++;
                    continue;
                }
                if ($cc === $aspas) {
                    break;
                }
            }
            $html .= '<span class="pbl-rc-string">' . $this->escapar($str) . '</span>';
        }

        return $html . $this->escapar($pendente);
    }

    private function escapar(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
