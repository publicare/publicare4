<?php

declare(strict_types=1);

namespace Pbl\Conversao;

use Pbl\Conteudo\ConfigDoSite;
use Pbl\Linguagem\ErroSintaxe;
use Pbl\Linguagem\ExpressaoLexer;
use Pbl\Linguagem\ExpressaoParser;
use Pbl\Linguagem\Parser;
use Pbl\Linguagem\Token;

/**
 * Converte um molde PBL3 em PBL4. Texto fora das tags é preservado; cada tag é traduzida ou, quando
 * não há equivalente seguro, substituída por um comentário `<@-- REVISAR … --@>` com o original.
 * O resultado é sempre revalidado pelo parser do PBL4. Regras em docs/linguagem-pbl4.md.
 */
final class Conversor
{
    /** Campos do PBL3 com outro nome no PBL4. */
    private const CAMPOS = ['cod_objeto' => 'id', 'cod_pai' => 'pai', 'cod_classe' => 'classe_id'];
    /** Campos que o PBL4 ainda não oferece no contexto do molde. */
    private const CAMPOS_AUSENTES = ['tags', 'cod_usuario', 'cod_pele', 'script_exibir', 'versao', 'versao_publicada', 'objetosistema', 'nome_classe'];
    private const STATUS = [1 => 'privado', 2 => 'publicado', 3 => 'rejeitado', 4 => 'submetido'];
    /** Colunas de objeto que não são propriedades da classe. */
    private const METADADOS = ['id', 'titulo', 'peso', 'descricao', 'data_publicacao', 'data_validade', 'criado_em', 'atualizado_em'];
    private const NAO_PORTADOS = [
        'incluimenu' => 'menu do admin: fase 3/5',
        'protegido' => 'área protegida: depende da autenticação (fase 3)',
        'executar' => 'executava script PHP; vira módulo com interface estável (ADR 0005)',
        'iconeblob' => 'ícone por tipo de arquivo: não existe no PBL4',
        'iconeclasse' => 'ícone da classe: não existe no PBL4',
    ];
    /** `srcblob`/`linkblob`/`downloadblob` eram o mesmo link no PBL3; no PBL4 inline/anexo é decidido pelo servidor. */
    private const BLOB_INFO = ['srcblob' => 'url', 'linkblob' => 'url', 'downloadblob' => 'url', 'tamanhoblob' => 'tamanho', 'tipoblob' => 'tipo'];

    /** @var list<Aviso> */
    private array $avisos = [];
    /** @var list<string> */
    private array $incluidos = [];
    /** @var array<string, true> */
    private array $campos = [];
    private int $ecosCrus = 0;
    private int $linha = 1;
    /**
     * Pilha de `usarblob`: a propriedade (nome literal de `nome=`) corrente para `srcblob`/`tamanhoblob`/etc.; null = aberto
     * sem nome fixo (sem conversão automática, vai para revisão).
     *
     * @var list<string|null>
     */
    private array $blobPilha = [];

    public function __construct(
        private readonly bool $modoSeguro = false,
        private readonly ExpressaoLexer $lexer = new ExpressaoLexer(),
        private readonly ExpressaoParser $parser = new ExpressaoParser(),
    ) {
    }

    public function converter(string $fonte): Resultado
    {
        $this->reiniciar();

        if (!mb_check_encoding($fonte, 'UTF-8')) {
            $fonte = mb_convert_encoding($fonte, 'UTF-8', 'ISO-8859-1');
            $this->avisos[] = new Aviso(Aviso::INFO, 0, 'Arquivo em ISO-8859-1 convertido para UTF-8');
        }
        $fonte = $this->removerPhp($fonte);
        $saida = $this->tags($fonte);

        if ($this->ecosCrus > 0) {
            $this->avisos[] = new Aviso(Aviso::INFO, 0, "{$this->ecosCrus} saída(s) convertida(s) para ecocru (sem escape, como no PBL3); use o modo seguro para escapar HTML");
        }
        $valido = true;
        try {
            (new Parser())->analisar($saida);
        } catch (ErroSintaxe $e) {
            $valido = false;
            $this->avisos[] = new Aviso(Aviso::ERRO, $e->linha, 'O PBL4 rejeita o resultado: ' . $e->getMessage());
        }
        usort($this->avisos, static fn (Aviso $a, Aviso $b): int => $a->linha <=> $b->linha);

        return new Resultado($saida, $this->avisos, array_values(array_unique($this->incluidos)), array_keys($this->campos), $valido);
    }

    private function reiniciar(): void
    {
        $this->avisos = [];
        $this->incluidos = [];
        $this->campos = [];
        $this->ecosCrus = 0;
        $this->linha = 1;
        $this->blobPilha = [];
    }

    private function removerPhp(string $fonte): string
    {
        return (string) preg_replace_callback('/<\?.*?(?:\?>|\z)/s', function (array $m) use ($fonte): string {
            $bloco = $m[0];
            $posicao = strpos($fonte, $bloco);
            $linha = $posicao === false ? 0 : substr_count($fonte, "\n", 0, $posicao) + 1;
            $resumo = $this->resumir($bloco);
            $this->avisos[] = new Aviso(Aviso::REVISAR, $linha, "PHP removido (o PBL4 não executa PHP em moldes): {$resumo}");

            return "<@-- REVISAR: PHP removido: {$resumo} --@>" . str_repeat("\n", substr_count($bloco, "\n"));
        }, $fonte);
    }

    private function tags(string $fonte): string
    {
        $saida = '';
        $pos = 0;
        $n = strlen($fonte);
        while (($ini = strpos($fonte, '<@', $pos)) !== false) {
            $saida .= substr($fonte, $pos, $ini - $pos);
            $this->linha = substr_count($fonte, "\n", 0, $ini) + 1;
            if (substr($fonte, $ini + 2, 2) === '--') {
                $fimComentario = strpos($fonte, '--@>', $ini + 4);
                $fimComentario = $fimComentario === false ? $n : $fimComentario + 4;
                $saida .= substr($fonte, $ini, $fimComentario - $ini);
                $pos = $fimComentario;
                continue;
            }
            $fim = $this->fimDaTag($fonte, $ini + 2);
            if ($fim === null) {
                $this->avisos[] = new Aviso(Aviso::ERRO, $this->linha, 'Tag não fechada (falta @>)');
                $saida .= substr($fonte, $ini);
                $pos = $n;
                break;
            }
            $original = substr($fonte, $ini, $fim + 2 - $ini);
            $saida .= $this->tag(trim(substr($fonte, $ini + 2, $fim - $ini - 2)), $original);
            $pos = $fim + 2;
        }

        return $saida . substr($fonte, $pos);
    }

    private function fimDaTag(string $fonte, int $de): ?int
    {
        $n = strlen($fonte);
        $aspas = null;
        for ($i = $de; $i < $n; $i++) {
            $c = $fonte[$i];
            if ($aspas !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $aspas) {
                    $aspas = null;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $aspas = $c;
            } elseif ($c === '@' && ($fonte[$i + 1] ?? '') === '>') {
                return $i;
            }
        }

        return null;
    }

    private function tag(string $interno, string $original): string
    {
        if (preg_match('/^([\\\\\/]?)\s*([a-zA-Z_]+|=)(.*)$/s', $interno, $m) !== 1) {
            return $this->revisar($original, 'tag malformada');
        }
        $fecha = $m[1] !== '';
        $nome = strtolower($m[2]);
        $resto = trim($m[3]);

        if ($fecha) {
            return match (true) {
                $nome === 'usarblob' => $this->fecharUsarBlob(),
                isset(self::NAO_PORTADOS[$nome]) => $this->comentario("fechamento de {$nome} não portado"),
                in_array($nome, ['se', 'repetir', 'filhos', 'aleatorio', 'localizar', 'localizaraleatorio', 'semfilhos', 'naolocalizado', 'usarobjeto', 'temfilho'], true) => "<@/{$nome}@>",
                default => $this->revisar($original, "fechamento desconhecido: {$nome}"),
            };
        }
        if (isset(self::NAO_PORTADOS[$nome])) {
            return $this->revisar($original, "{$nome} não portado: " . self::NAO_PORTADOS[$nome]);
        }

        return match ($nome) {
            '=', 'eco', 'ecoe' => $this->eco($nome, $resto, $original),
            'eco_limite', 'formatadata' => $this->comParametros($nome, $resto, $original),
            'var' => $this->var($resto, $original),
            'se' => $this->se($resto, $original),
            'senao', 'semfilhos', 'naolocalizado' => $resto === '' ? "<@{$nome}@>" : $this->revisar($original, "{$nome} não aceita argumentos"),
            'repetir' => $this->repetir($resto, $original),
            'filhos', 'aleatorio', 'localizar', 'localizaraleatorio' => $this->colecao($nome, $resto, $original),
            'usarobjeto', 'temfilho' => $this->objeto($nome, $resto, $original),
            'incluir' => $this->incluir($resto, $original),
            'usarblob' => $this->usarBlob($resto, $original),
            'srcblob', 'linkblob', 'downloadblob', 'tamanhoblob', 'tipoblob' => $this->blobInfo($nome, $original),
            'srcthumb' => $this->srcThumb($original),
            'config' => $this->config($resto, $original),
            default => $this->revisar($original, "comando desconhecido: {$nome}"),
        };
    }

    // --- configuração do site (fase 5m, ADR 0017) -------------------------------------------------

    /**
     * `config` (valor da configuração do site): com o nome da chave fixo (`nome="x"`, `chave="x"`, `["x"]` ou `"x"`) e uma chave
     * que existe no catálogo do PBL4, vira `<@eco %SITE.config.x@>`; qualquer outra forma (nome calculado, chave fora do catálogo,
     * `config` de outro grupo como `portal`) vai para revisão, com o motivo.
     */
    private function config(string $resto, string $original): string
    {
        $literal = null;
        if (preg_match('/^(["\'])(.*)\1$/s', trim($resto), $m) === 1) {
            $literal = $m[0];
        } elseif (preg_match('/^\[\s*(["\'][^"\']*["\'])\s*\]$/', trim($resto), $m) === 1) {
            $literal = $m[1];
        } else {
            $params = $this->parametros($resto);
            $literal = $params === null ? null : ($params['nome'] ?? $params['chave'] ?? null);
        }
        $chave = $literal === null ? null : $this->propriedadeLiteral($literal);
        if ($chave === null) {
            return $this->revisar($original, 'config: o nome da configuração não é um texto fixo; use %SITE.config.chave (chaves em docs/linguagem-pbl4.md)');
        }
        if (!ConfigDoSite::existe($chave)) {
            return $this->revisar($original, "config \"{$chave}\": não existe no catálogo de configurações do PBL4; veja as chaves em docs/linguagem-pbl4.md");
        }
        $this->avisos[] = new Aviso(Aviso::INFO, $this->linha, "config \"{$chave}\" virou %SITE.config.{$chave} (configuração do site; confira o valor em /_pbl/site/{id}/configuracoes)");

        return "<@eco %SITE.config.{$chave}@>";
    }

    // --- blob (fase 7b) ----------------------------------------------------------------------------

    /**
     * `usarblob nome=["x"]@>` abria um bloco onde `srcblob`/`tamanhoblob`/etc. liam a propriedade `x`. O PBL4 não tem esse
     * bloco: os comandos novos (`arquivo`/`miniatura`) recebem `valor=#x` direto. Aqui só guarda `x` (pilha, para aninhar)
     * para os comandos dentro do bloco converterem; a tag em si não emite nada.
     */
    private function usarBlob(string $resto, string $original): string
    {
        $params = $this->parametros($resto);
        $literal = $params === null ? null : ($params['nome'] ?? null);
        $prop = $literal === null ? null : $this->propriedadeLiteral($literal);
        if ($prop === null) {
            $this->avisos[] = new Aviso(Aviso::REVISAR, $this->linha, 'usarblob: nome da propriedade não é um texto fixo, não convertido automaticamente: ' . $this->limpar($original));
            $this->blobPilha[] = null;

            return $this->comentario('REVISAR: usarblob não convertido: ' . $this->limpar($original));
        }
        $this->blobPilha[] = $prop;

        return '';
    }

    private function fecharUsarBlob(): string
    {
        array_pop($this->blobPilha);

        return '';
    }

    /** `"x"`/`'x'` com um nome de propriedade simples dentro (sem variável nem concatenação); outra coisa é null. */
    private function propriedadeLiteral(string $valor): ?string
    {
        return preg_match('/^(["\'])([a-zA-Z_][a-zA-Z0-9_]*)\1$/', trim($valor), $m) === 1 ? strtolower($m[2]) : null;
    }

    /** `srcblob`/`linkblob`/`downloadblob`/`tamanhoblob`/`tipoblob`, sem parâmetros: leem a propriedade do `usarblob` corrente. */
    private function blobInfo(string $nome, string $original): string
    {
        $prop = $this->blobPilha[count($this->blobPilha) - 1] ?? null;
        if ($prop === null) {
            return $this->revisar($original, "{$nome} fora de um usarblob com nome fixo");
        }

        return '<@arquivo valor=#' . $prop . ' info="' . self::BLOB_INFO[$nome] . '"@>';
    }

    /**
     * `srcthumb`: miniatura do blob corrente. O PBL3 não tinha tamanho configurável aqui; o PBL4 exige um de
     * `ServicoDeMiniaturas::TAMANHOS`, então o conversor fixa 150 (o mais perto de uma "miniatura" comum) e avisa.
     */
    private function srcThumb(string $original): string
    {
        $prop = $this->blobPilha[count($this->blobPilha) - 1] ?? null;
        if ($prop === null) {
            return $this->revisar($original, 'srcthumb fora de um usarblob com nome fixo');
        }
        $this->avisos[] = new Aviso(Aviso::INFO, $this->linha, 'srcthumb virou miniatura tamanho=150 (o PBL3 não tinha tamanho configurável aqui; os tamanhos disponíveis no PBL4 são 64, 150, 400 e 800)');

        return '<@miniatura valor=#' . $prop . ' tamanho=150@>';
    }

    // --- comandos ---------------------------------------------------------------------------------

    private function eco(string $nome, string $resto, string $original): string
    {
        if (preg_match('/^cortaTexto\s*\(\s*(.+?)\s*,\s*(\d+)\s*\)$/s', $resto, $m) === 1) {
            $texto = $this->expressao($m[1]);
            if ($texto !== null) {
                $this->avisos[] = new Aviso(Aviso::INFO, $this->linha, 'cortaTexto(…) virou eco_limite (a saída agora é escapada)');

                return "<@eco_limite texto={$texto} limite={$m[2]}@>";
            }
        }
        $expr = $this->expressao($resto);
        if ($expr === null) {
            return $this->revisar($original, 'expressão não convertida automaticamente');
        }
        $cru = $nome !== 'ecoe' && !$this->modoSeguro;
        if ($cru) {
            $this->ecosCrus++;
        }

        return ($cru ? '<@ecocru ' : '<@eco ') . $expr . '@>';
    }

    private function var(string $resto, string $original): string
    {
        if (preg_match('/^(\$[a-zA-Z0-9_]+)\s*=\s*(.+)$/s', $resto, $m) !== 1) {
            return $this->revisar($original, 'var em formato desconhecido');
        }
        $valor = $this->expressao($m[2]);

        return $valor === null ? $this->revisar($original, 'valor da variável não convertido') : '<@var ' . strtolower($m[1]) . ' = ' . $valor . '@>';
    }

    private function se(string $resto, string $original): string
    {
        $cond = trim($resto);
        if ($cond !== '' && $cond[0] === '[' && str_ends_with($cond, ']')) {
            $cond = trim(substr($cond, 1, -1));
        }
        $expr = $this->expressao($cond, true);

        return $expr === null ? $this->revisar($original, 'condição não convertida automaticamente') : "<@se [{$expr}]@>";
    }

    private function repetir(string $resto, string $original): string
    {
        if (preg_match('/^(\$[a-zA-Z0-9_]+)\s*=\s*\[(.+),(.+)\]$/s', $resto, $m) !== 1) {
            return $this->revisar($original, 'repetir em formato desconhecido');
        }
        $a = $this->expressao($m[2]);
        $b = $this->expressao($m[3]);

        return $a === null || $b === null ? $this->revisar($original, 'limites do repetir não convertidos') : '<@repetir ' . strtolower($m[1]) . "=[{$a},{$b}]@>";
    }

    private function comParametros(string $nome, string $resto, string $original): string
    {
        $params = $this->parametros($resto);
        if ($params === null) {
            return $this->revisar($original, 'parâmetros em formato desconhecido');
        }
        $saida = [];
        foreach ($params as $chave => $valor) {
            $expr = $this->expressao($valor);
            if ($expr === null) {
                return $this->revisar($original, "parâmetro {$chave} não convertido");
            }
            $saida[] = "{$chave}={$expr}";
        }

        return "<@{$nome} " . implode(' ', $saida) . '@>';
    }

    private function colecao(string $nome, string $resto, string $original): string
    {
        $params = $this->parametros($resto);
        if ($params === null) {
            return $this->revisar($original, 'parâmetros em formato desconhecido');
        }
        $ehLocalizar = str_starts_with($nome, 'localizar');
        $saida = [];
        $notas = [];
        foreach ($params as $chave => $valor) {
            switch ($chave) {
                case 'nome':
                    break;
                case 'classes':
                case 'limite':
                case 'inicio':
                    $e = $this->expressao($valor);
                    if ($e === null) {
                        return $this->revisar($original, "parâmetro {$chave} não convertido");
                    }
                    $saida[] = "{$chave}={$e}";
                    break;
                case 'ordem':
                    $e = $this->expressao($valor);
                    if ($e === null) {
                        return $this->revisar($original, 'ordem não convertida');
                    }
                    $saida[] = "ordem={$e}";
                    $this->registrarOrdem($valor);
                    break;
                case 'pai':
                    if (!$ehLocalizar) {
                        return $this->revisar($original, 'pai só existe em localizar');
                    }
                    if (trim($valor) === '-1') {
                        break;
                    }
                    $e = $this->expressao($valor);
                    if ($e === null) {
                        return $this->revisar($original, 'pai não convertido');
                    }
                    $saida[] = "pai={$e}";
                    break;
                case 'niveis':
                    if (!$ehLocalizar) {
                        return $this->revisar($original, 'niveis só existe em localizar');
                    }
                    if (preg_match('/^\s*(-?\d+)\s*$/', $valor, $n) !== 1) {
                        return $this->revisar($original, 'niveis não literal: converta à mão (no PBL4, niveis=1 são os filhos diretos)');
                    }
                    if ((int) $n[1] >= 0) {
                        $saida[] = 'niveis=' . ((int) $n[1] + 1);
                        $this->avisos[] = new Aviso(Aviso::INFO, $this->linha, "niveis={$n[1]} virou niveis=" . ((int) $n[1] + 1) . ' (no PBL3 o nível 0 já incluía os filhos diretos)');
                    }
                    break;
                case 'condicao':
                    $c = $this->condicao($valor);
                    if ($c === null) {
                        $notas[] = "condicao não convertida: {$this->limpar($valor)}";
                    } else {
                        $saida[] = $c;
                    }
                    break;
                case 'like':
                case 'ilike':
                case 'tags':
                    $notas[] = "{$chave} não portado (busca no título: use busca=; tags ainda não existem)";
                    break;
                default:
                    $notas[] = "parâmetro {$chave} desconhecido";
            }
        }
        $tag = "<@{$nome}" . ($saida === [] ? '' : ' ' . implode(' ', $saida)) . '@>';
        if ($notas !== []) {
            foreach ($notas as $nota) {
                $this->avisos[] = new Aviso(Aviso::REVISAR, $this->linha, $nota);
            }

            return $this->comentario('REVISAR: ' . implode('; ', $notas)) . $tag;
        }

        return $tag;
    }

    /**
     * `condicao=["a=1 and b>2"]` → `campo="a" valor="1" campo2="b" op2=">" valor2="2"`. Aceita até três comparações simples
     * ligadas por `and`; `or`, parênteses, `like` e subconsultas ficam para revisão manual.
     */
    private function condicao(string $valor): ?string
    {
        $texto = trim($valor);
        if (preg_match('/^(["\'])(.*)\1$/s', $texto, $q) === 1) {
            $texto = $q[2];
        }
        $partes = preg_split('/\s+and\s+/i', $texto) ?: [];
        if ($partes === [] || count($partes) > 3) {
            return null;
        }
        $params = [];
        $campos = [];
        foreach ($partes as $i => $parte) {
            if (preg_match('/^\s*([a-z][a-z0-9_]*)\s*(<=|>=|<>|!=|=|<|>)\s*(.+?)\s*$/i', $parte, $m) !== 1 || preg_match('/\s(or|like)\s|[()]/i', $m[3]) === 1) {
                return null;
            }
            $sufixo = $i === 0 ? '' : (string) ($i + 1);
            $operador = $m[2] === '<>' ? '!=' : $m[2];
            $v = trim($m[3], " '\"");
            $campo = strtolower($m[1]);
            $campos[] = $campo;
            $params[] = "campo{$sufixo}=\"{$campo}\"" . ($operador === '=' ? '' : " op{$sufixo}=\"{$operador}\"") . " valor{$sufixo}=\"" . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
        }
        foreach ($campos as $campo) {
            if (!in_array($campo, self::METADADOS, true)) {
                $this->campos[$campo] = true;
            }
        }
        $this->avisos[] = new Aviso(Aviso::INFO, $this->linha, "condicao \"{$texto}\": os campos " . implode(', ', array_unique($campos)) . ' precisam ser indexáveis na classe');

        return implode(' ', $params);
    }

    private function registrarOrdem(string $valor): void
    {
        $texto = trim($valor, " \t\n\r[]'\"");
        foreach (explode(',', $texto) as $item) {
            $nome = strtolower(ltrim(trim($item), '+-'));
            $nome = (string) preg_replace('/\s+(asc|desc)$/i', '', $nome);
            if ($nome !== '' && preg_match('/^[a-z][a-z0-9_]*$/', $nome) === 1 && !in_array($nome, self::METADADOS, true)) {
                $this->campos[$nome] = true;
            }
        }
    }

    private function objeto(string $nome, string $resto, string $original): string
    {
        $params = $this->parametros($resto);
        if ($params === null || $params === []) {
            return $nome === 'temfilho' && $resto === '' ? '<@temfilho@>' : $this->revisar($original, 'parâmetros em formato desconhecido');
        }
        $saida = [];
        foreach ($params as $chave => $valor) {
            $destino = $chave === 'cod_objeto' ? 'id' : $chave;
            if (!in_array($destino, $nome === 'temfilho' ? ['id'] : ['id', 'titulo', 'url'], true)) {
                return $this->revisar($original, "parâmetro {$chave} não portado");
            }
            $e = $this->expressao($valor);
            if ($e === null) {
                return $this->revisar($original, "parâmetro {$chave} não convertido");
            }
            $saida[] = "{$destino}={$e}";
        }

        return "<@{$nome} " . implode(' ', $saida) . '@>';
    }

    private function incluir(string $resto, string $original): string
    {
        $params = $this->parametros($resto);
        $arquivo = $params['arquivo'] ?? null;
        if ($arquivo === null || preg_match('/^\s*(["\'])(.*)\1\s*$/s', $arquivo, $m) !== 1) {
            return $this->revisar($original, 'incluir com arquivo calculado: converta à mão');
        }
        $nome = NomesDeMolde::de($m[2]);
        $this->incluidos[] = $nome;

        return "<@incluir arquivo=\"{$nome}\"@>";
    }

    // --- expressões e parâmetros -------------------------------------------------------------------

    /**
     * Converte uma expressão PBL3 em PBL4, ou devolve null se houver PHP, funções ou macros sem equivalente.
     */
    private function expressao(string $texto, bool $condicao = false): ?string
    {
        $t = trim($texto);
        if ($t !== '' && $t[0] === '[' && str_ends_with($t, ']') && !$condicao) {
            $t = trim(substr($t, 1, -1));
        }
        // #cod_status numérico (1 a 4) vira #status com o nome do estado, em qualquer expressão
        $t = (string) preg_replace_callback('/#cod_status\s*(==|!=|=)\s*([1-4])\b/i', function (array $m): string {
            $this->avisos[] = new Aviso(Aviso::INFO, $this->linha, "#cod_status {$m[1]} {$m[2]} virou #status com o nome do estado");

            return '#status ' . ($m[1] === '=' ? '==' : $m[1]) . ' "' . self::STATUS[(int) $m[2]] . '"';
        }, $t);
        $t = (string) preg_replace_callback('/\$_page->config\[["\']portal["\']\]\[["\']url["\']\]/', function (): string {
            $this->avisos[] = new Aviso(Aviso::INFO, $this->linha, 'URL absoluta do portal removida (os links ficam relativos à raiz do site)');

            return '""';
        }, $t);
        try {
            $tokens = $this->lexer->separar($t, $this->linha);
        } catch (ErroSintaxe) {
            return null;
        }
        if ($tokens === []) {
            return null;
        }
        $partes = [];
        foreach ($tokens as $tk) {
            switch ($tk->tipo) {
                case Token::STR:
                    $partes[] = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $tk->valor) . '"';
                    break;
                case Token::NUM:
                    $partes[] = (string) $tk->valor;
                    break;
                case Token::CAMPO:
                    $nome = (string) $tk->valor;
                    if ($nome === 'cod_status') {
                        $this->avisos[] = new Aviso(Aviso::REVISAR, $this->linha, '#cod_status é numérico (1 a 4) no PBL3 e texto no PBL4 (#status: privado, publicado, rejeitado, submetido); confira o uso');
                        $nome = 'status';
                        $partes[] = '#status';
                        break;
                    }
                    if (in_array($nome, self::CAMPOS_AUSENTES, true)) {
                        $this->avisos[] = new Aviso(Aviso::REVISAR, $this->linha, "#{$nome} ainda não existe no PBL4 (vai vazio)");
                    }
                    $partes[] = '#' . (self::CAMPOS[$nome] ?? $nome);
                    break;
                case Token::VAR:
                    $partes[] = '$' . $tk->valor;
                    break;
                case Token::MACRO:
                    $partes[] = '%' . $tk->valor;
                    break;
                case Token::OP:
                    $op = (string) $tk->valor;
                    if (!in_array($op, ['.', '+', '-', '*', '/', '(', ')', '==', '!=', '<', '>', '<=', '>=', '='], true)) {
                        return null;
                    }
                    $partes[] = $op === '=' ? '==' : $op;
                    break;
                default:
                    return null;
            }
        }
        $saida = trim((string) preg_replace('/\(\s+/', '(', (string) preg_replace('/\s+\)/', ')', implode(' ', $partes))));
        try {
            $condicao ? $this->parser->condicao($saida, $this->linha) : $this->parser->expressao($saida, $this->linha);
        } catch (ErroSintaxe) {
            return null;
        }

        return $saida;
    }

    /**
     * Lê `nome=[valor] nome2="texto" nome3=#campo` do PBL3. Valores entre colchetes perdem os colchetes.
     *
     * @return array<string, string>|null
     */
    private function parametros(string $texto): ?array
    {
        $params = [];
        $n = strlen($texto);
        $i = 0;
        while ($i < $n) {
            if (ctype_space($texto[$i])) {
                $i++;
                continue;
            }
            if (preg_match('/\G([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*/', $texto, $m, 0, $i) !== 1) {
                return null;
            }
            $chave = strtolower($m[1]);
            $i += strlen($m[0]);
            $inicio = $i;
            $aspas = null;
            $profundidade = 0;
            for (; $i < $n; $i++) {
                $c = $texto[$i];
                if ($aspas !== null) {
                    if ($c === '\\') {
                        $i++;
                    } elseif ($c === $aspas) {
                        $aspas = null;
                    }
                    continue;
                }
                if ($c === '"' || $c === "'") {
                    $aspas = $c;
                } elseif ($c === '[') {
                    $profundidade++;
                } elseif ($c === ']') {
                    $profundidade--;
                } elseif (ctype_space($c) && $profundidade === 0) {
                    break;
                }
            }
            if ($aspas !== null || $profundidade !== 0) {
                return null;
            }
            $valor = trim(substr($texto, $inicio, $i - $inicio));
            if ($valor !== '' && $valor[0] === '[' && str_ends_with($valor, ']')) {
                $valor = trim(substr($valor, 1, -1));
            }
            $params[$chave] = $valor;
        }

        return $params;
    }

    // --- saída -------------------------------------------------------------------------------------

    private function revisar(string $original, string $motivo): string
    {
        $this->avisos[] = new Aviso(Aviso::REVISAR, $this->linha, "{$motivo}: " . $this->limpar($original));

        return $this->comentario('REVISAR (' . $motivo . '): ' . $this->limpar($original)) . str_repeat("\n", substr_count($original, "\n"));
    }

    private function comentario(string $texto): string
    {
        return '<@-- ' . $this->limpar($texto) . ' --@>';
    }

    /** Texto seguro para dentro de um comentário `<@-- … --@>` e para o relatório. */
    private function limpar(string $texto): string
    {
        $texto = trim((string) preg_replace('/\s+/', ' ', $texto));
        $texto = str_replace(['--@>', '@>', '<@', '<?', '?>'], ['- -@ >', '@ >', '< @', '< ?', '? >'], $texto);

        return mb_strlen($texto) > 160 ? mb_substr($texto, 0, 157) . '...' : $texto;
    }

    private function resumir(string $bloco): string
    {
        return $this->limpar($bloco);
    }
}
