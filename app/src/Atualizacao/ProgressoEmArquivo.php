<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

use Pbl\Caminhos;

/**
 * Grava o andamento em `dados/atualizacao/progresso.json` (0600, atômico: quem lê vê o arquivo antigo ou o novo, nunca pela metade).
 * Qualquer falha ao gravar é engolida: a atualização vale mais do que a barra de progresso. O motivo da falha passa por
 * {@see SaneadorDeMensagem} (sem a raiz da instalação nem caminhos de servidor). As classes usadas depois da troca do código são
 * carregadas em {@see iniciar}, porque depois dela o autoload já aponta para outra versão.
 */
final class ProgressoEmArquivo implements RegistroDeProgresso
{
    public const LIMITE = 8192;

    /** @var \Closure(): int */
    private readonly \Closure $relogio;
    private string $tipo = 'atualizar';
    private int $inicio = 0;
    private int $ultimoPercentual = 0;

    /**
     * @param string $origem `terminal` ou `cron`
     * @param (\Closure(): int)|null $relogio só para os testes
     */
    public function __construct(private readonly Caminhos $caminhos, private readonly string $origem = 'terminal', ?\Closure $relogio = null)
    {
        $this->relogio = $relogio ?? static fn (): int => time();
    }

    public static function arquivo(Caminhos $caminhos): string
    {
        return $caminhos->dados() . '/atualizacao/progresso.json';
    }

    /** @return Progresso|null null se não há, está corrompido ou passa de {@see LIMITE} */
    public static function ler(Caminhos $caminhos): ?Progresso
    {
        $t = ArquivoAtomico::ler(self::arquivo($caminhos), self::LIMITE);

        return $t === null ? null : Progresso::deArray(json_decode($t, true));
    }

    public function iniciar(string $tipo): void
    {
        class_exists(Progresso::class);
        class_exists(ArquivoAtomico::class);
        class_exists(SaneadorDeMensagem::class);
        enum_exists(Etapa::class);
        $this->tipo = $tipo === 'reverter' ? 'reverter' : 'atualizar';
        $this->inicio = ($this->relogio)();
        $this->ultimoPercentual = 0;
        $this->gravar(Etapa::Preparando, null, null, null, '');
    }

    public function etapa(Etapa $etapa, ?int $percentual = null, ?string $mensagem = null): void
    {
        $this->gravar($etapa, $percentual, $mensagem, null, '');
    }

    public function concluir(): void
    {
        $this->gravar(Etapa::Concluido, null, null, ($this->relogio)(), '');
    }

    public function falhar(string $motivo): void
    {
        $this->gravar(Etapa::Falhou, $this->ultimoPercentual, null, ($this->relogio)(), SaneadorDeMensagem::sanear($motivo, $this->caminhos->raiz));
    }

    private function gravar(Etapa $etapa, ?int $percentual, ?string $mensagem, ?int $fim, string $falha): void
    {
        try {
            $agora = ($this->relogio)();
            $p = max(0, min(100, $percentual ?? $etapa->percentual()));
            if ($etapa !== Etapa::Falhou) {
                $this->ultimoPercentual = $p;
            }
            $registro = new Progresso($this->origem, $this->tipo, $etapa, $p, $mensagem ?? $etapa->mensagem(), $this->inicio === 0 ? $agora : $this->inicio, $fim, $falha, $agora);
            ArquivoAtomico::gravar(self::arquivo($this->caminhos), json_encode($registro->paraArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable) {
            // o andamento é um aviso; falhar ao gravá-lo não pode atrapalhar a atualização
        }
    }
}
