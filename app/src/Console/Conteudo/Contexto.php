<?php

declare(strict_types=1);

namespace Pbl\Console\Conteudo;

use Pbl\Acesso\Usuario;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\Servico\EntradaInvalida;
use Pbl\Conteudo\Servico\ResultadoDeConteudo;
use Pbl\Conteudo\Site;
use Pbl\Servicos;
use Symfony\Component\Console\Input\InputInterface;

/** Tudo que uma ação precisa: serviços, conta (`--como`), site e as opções já lidas. */
final class Contexto
{
    /** Teto da entrada padrão (`--json=-`). */
    public const LIMITE_ENTRADA = 2097152;
    /** Nomes que são do próprio conteúdo; qualquer outro `--campo` é uma propriedade do tipo (ou `dados.<nome>`). */
    private const RESERVADOS = ['titulo', 'descricao', 'url', 'peso', 'no_menu', 'rotulo_menu', 'tags'];

    /** @var array<int, string> */
    private array $nomesDeTipo = [];

    public function __construct(
        public readonly Servicos $s,
        public readonly Usuario $quem,
        public readonly Site $site,
        public readonly InputInterface $input,
        private readonly Entrada $entrada,
        private readonly ?string $alvo,
        private readonly bool $json,
        private readonly bool $lerEntrada,
    ) {
    }

    public function alvo(): ?string
    {
        return $this->alvo;
    }

    public function json(): bool
    {
        return $this->json;
    }

    public function opcao(string $nome): ?string
    {
        $v = $this->input->getOption($nome);

        return is_string($v) && $v !== '' ? $v : null;
    }

    public function marca(string $nome): bool
    {
        return (bool) $this->input->getOption($nome);
    }

    /** A versão que quem chama viu (`--versao=N`), obrigatória em toda mudança. */
    public function versao(): int
    {
        $v = $this->opcao('versao');
        if ($v === null) {
            throw new EntradaInvalida(['versao' => 'Informe --versao=N (veja a versão atual com o subcomando "ver").']);
        }

        return $this->inteiro('versao', $v);
    }

    public function inteiro(string $nome, string $valor): int
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/D', $valor) !== 1) {
            throw new EntradaInvalida([$nome => 'Use um número inteiro a partir de 1.']);
        }

        return (int) $valor;
    }

    public function exigirConfirmacao(string $efeito): void
    {
        if (!$this->marca('confirmar')) {
            throw new ConfirmacaoNecessaria($efeito . ' Nada foi feito; repita o comando com --confirmar.');
        }
    }

    /**
     * Os campos para editar/criar: `--json=-` (stdin) primeiro, `--campo nome=valor` por cima (vence em caso de repetição).
     *
     * @return array<string, mixed>
     */
    public function campos(): array
    {
        $campos = ['dados' => []];
        if ($this->lerEntrada) {
            try {
                $bruto = $this->entrada->ler(self::LIMITE_ENTRADA);
            } catch (EntradaGrandeDemais $e) {
                throw new EntradaInvalida(['_' => $e->getMessage()]);
            }
            $campos = $this->doJson($bruto);
        }
        $lista = $this->input->getOption('campo');
        foreach (is_array($lista) ? $lista : [] as $par) {
            $par = (string) $par;
            $pos = strpos($par, '=');
            if ($pos === false || $pos === 0) {
                throw new EntradaInvalida(['campo' => 'Use --campo nome=valor.']);
            }
            $nome = substr($par, 0, $pos);
            $valor = substr($par, $pos + 1);
            if (str_starts_with($nome, 'dados.')) {
                $campos['dados'][substr($nome, 6)] = $valor;
            } elseif (in_array($nome, self::RESERVADOS, true)) {
                $campos[$nome] = $valor;
            } else {
                $campos['dados'][$nome] = $valor;
            }
        }

        return $campos;
    }

    /** @return array<string, mixed> */
    private function doJson(string $bruto): array
    {
        if (!mb_check_encoding($bruto, 'UTF-8')) {
            throw new EntradaInvalida(['_' => 'A entrada não é UTF-8 válido.']);
        }
        try {
            $lido = json_decode($bruto, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new EntradaInvalida(['_' => 'A entrada não é um JSON válido.']);
        }
        if (!is_array($lido) || ($lido !== [] && array_is_list($lido))) {
            throw new EntradaInvalida(['_' => 'A entrada precisa ser um objeto JSON (titulo, descricao, ..., dados).']);
        }
        $campos = ['dados' => []];
        foreach ($lido as $chave => $valor) {
            $chave = (string) $chave;
            if ($chave === 'dados' || $chave === 'campos') {
                if (!is_array($valor) || ($valor !== [] && array_is_list($valor))) {
                    throw new EntradaInvalida([$chave => 'Precisa ser um objeto nome => valor.']);
                }
                foreach ($valor as $nome => $v) {
                    $campos['dados'][(string) $nome] = $this->comoTexto($chave . '.' . $nome, $v);
                }
            } elseif (in_array($chave, self::RESERVADOS, true)) {
                $campos[$chave] = $chave === 'tags' && is_array($valor) ? implode(',', array_map(fn (mixed $t): string => $this->comoTexto('tags', $t), $valor)) : $this->comoTexto($chave, $valor);
            } else {
                throw new EntradaInvalida([$chave => 'Campo desconhecido na entrada.']);
            }
        }

        return $campos;
    }

    private function comoTexto(string $nome, mixed $valor): string
    {
        return match (true) {
            $valor === null => '',
            is_bool($valor) => $valor ? '1' : '0',
            is_int($valor), is_float($valor) => (string) $valor,
            is_string($valor) => $valor,
            default => throw new EntradaInvalida([$nome => 'Precisa ser texto, número ou booleano.']),
        };
    }

    /** O conteúdo apontado pelo argumento `<id|caminho>`, já conferido (site e permissão de ver). */
    public function objeto(): Objeto
    {
        return (new ResolvedorDeObjeto($this->s->conteudo))->resolver($this->quem, $this->site, $this->alvo);
    }

    /** Resposta padrão das gravações: id, versão nova, estado, se mudou algo e se o cache público acompanhou. */
    public function gravada(ResultadoDeConteudo $r, string $feito): Resposta
    {
        $o = $r->objeto;

        return new Resposta(
            $this->resumo($o) + ['alterado' => $r->alterado, 'cache_atualizado' => $r->publicacaoOk],
            $r->alterado ? $this->linha($o) . ' ' . $feito . '.' . ($r->publicacaoOk ? '' : ' Aviso: a página pública não foi atualizada agora; use "republicar".') : $this->linha($o) . ': nada a alterar.',
        );
    }

    public function nomeDoTipo(int $classeId): string
    {
        return $this->nomesDeTipo[$classeId] ??= $this->s->classes->porId($classeId)->nome ?? '?';
    }

    public function urlRelativa(Objeto $o): string
    {
        return '/' . ltrim($o->urlAmigavel, '/');
    }

    /** @return array<string, mixed> */
    public function resumo(Objeto $o): array
    {
        return [
            'id' => $o->id, 'titulo' => $o->titulo, 'tipo' => $this->nomeDoTipo($o->classeId), 'estado' => $o->status,
            'versao' => $o->versao, 'url' => $this->urlRelativa($o),
        ];
    }

    public function linha(Objeto $o): string
    {
        return '#' . $o->id . ' ' . $o->titulo . ' [' . $this->nomeDoTipo($o->classeId) . ', ' . $o->status . ', v' . $o->versao . ']';
    }
}
