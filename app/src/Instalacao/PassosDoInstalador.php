<?php

declare(strict_types=1);

namespace Pbl\Instalacao;

use Pbl\Admin\Pagina;

/** Os passos do assistente web, a barra de progresso e a tradução de um erro em "em que passo corrigir" e "como corrigir". */
final class PassosDoInstalador
{
    public const REQUISITOS = 1;
    public const SITE = 2;
    public const ADMINISTRADOR = 3;
    public const MODELOS = 4;
    public const REVISAO = 5;
    public const PRONTO = 6;

    /** @var array<int, string> */
    public const TITULOS = [
        self::REQUISITOS => 'Boas-vindas e requisitos',
        self::SITE => 'Site: nome e domínio',
        self::ADMINISTRADOR => 'Administrador',
        self::MODELOS => 'Modelos opcionais',
        self::REVISAO => 'Revisão e instalar',
        self::PRONTO => 'Pronto',
    ];

    /** Barra de progresso (`<progress>` nativo: sem estilo em linha, que a CSP proíbe) e lista dos passos com `aria-current`. */
    public static function barra(int $atual): string
    {
        $total = count(self::TITULOS);
        $itens = '';
        foreach (self::TITULOS as $n => $titulo) {
            $estado = $n < $atual ? 'feito' : ($n === $atual ? 'atual' : 'a-fazer');
            $itens .= '<li class="pbl-passo pbl-passo-' . $estado . '" data-passo-item="' . $n . '"' . ($n === $atual ? ' aria-current="step"' : '') . '>'
                . '<span class="pbl-passo-n" aria-hidden="true">' . ($n < $atual ? '✔' : (string) $n) . '</span><span class="pbl-passo-titulo">' . Pagina::h($titulo)
                . ($n < $atual ? '<span class="visually-hidden"> (concluído)</span>' : '') . '</span></li>';
        }

        return '<nav class="pbl-passos" aria-label="Etapas da instalação">'
            . '<p class="pbl-passo-resumo mb-1" id="pbl-passo-resumo">Passo <span data-pbl-passo-numero>' . $atual . '</span> de ' . $total . ': <strong data-pbl-passo-titulo>' . Pagina::h(self::TITULOS[$atual] ?? '') . '</strong></p>'
            . '<progress class="pbl-progresso" max="' . $total . '" value="' . $atual . '" aria-labelledby="pbl-passo-resumo"></progress>'
            . '<ol class="pbl-passos-lista">' . $itens . '</ol></nav>';
    }

    /** Em que passo está o problema descrito por uma mensagem de erro (o passo do formulário que a pessoa precisa rever). */
    public static function passoDoErro(string $mensagem): int
    {
        $m = mb_strtolower($mensagem);
        foreach (['domínio', 'dominio', 'nome do site', 'site'] as $trecho) {
            if (str_contains($m, $trecho)) {
                return self::SITE;
            }
        }
        foreach (['senha', 'login', 'e-mail', 'email', 'nome'] as $trecho) {
            if (str_contains($m, $trecho)) {
                return self::ADMINISTRADOR;
            }
        }
        if (str_contains($m, 'modelo')) {
            return self::MODELOS;
        }

        return self::REVISAO;
    }

    /** Uma frase de "como corrigir" para o erro (a mensagem original continua sendo mostrada). */
    public static function comoCorrigir(string $mensagem): string
    {
        $m = mb_strtolower($mensagem);

        return match (true) {
            str_contains($m, 'não são iguais') => 'Digite a mesma senha nos dois campos. Dica: copie e cole, ou use o botão "mostrar senha".',
            str_contains($m, 'pelo menos'), str_contains($m, 'longa demais') => 'Use uma frase longa, como "meu gato dorme no sofa azul": tamanho vale mais que símbolos.',
            str_contains($m, 'fácil demais') => 'Evite sequências e senhas comuns. Junte 3 ou 4 palavras sem relação entre si.',
            str_contains($m, 'igual ao') => 'Escolha uma senha diferente do seu login e do seu e-mail.',
            str_contains($m, 'domínio') => 'Escreva só o endereço, sem "https://" nem "/" no fim. Exemplo: www.meusite.org.br (ou localhost para testar).',
            str_contains($m, 'nome do site') => 'Digite o nome que aparecerá no topo do site, por exemplo "Prefeitura de Exemplo".',
            str_contains($m, 'login') => 'Use de 3 a 64 letras minúsculas, números, ponto, hífen ou sublinhado, sem espaços. Exemplo: ana.silva.',
            str_contains($m, 'e-mail') => 'Use um e-mail completo, como ana@exemplo.org.',
            str_contains($m, 'não consegue gravar') => 'Dê permissão de escrita às pastas dentro de dados/ (755 ou 775) e clique em Instalar de novo.',
            default => 'Confira os campos e tente de novo. Nada foi instalado pela metade.',
        };
    }
}
