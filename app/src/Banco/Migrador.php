<?php

declare(strict_types=1);

namespace Pbl\Banco;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Dialeto\Dialeto;
use Pbl\Banco\Dialeto\Dialetos;
use Pbl\Banco\Migracoes\M001Base;
use Pbl\Banco\Migracoes\M002Metadados;
use Pbl\Banco\Migracoes\M003TituloBusca;
use Pbl\Banco\Migracoes\M004BuscaNormalizada;
use Pbl\Banco\Migracoes\M005VersoesEHistoricoDeUrls;
use Pbl\Banco\Migracoes\M006DominiosERedirecionamentos;
use Pbl\Banco\Migracoes\M007Acesso;
use Pbl\Banco\Migracoes\M008Sessoes;
use Pbl\Banco\Migracoes\M009ClassesNaAdministracao;
use Pbl\Banco\Migracoes\M010IndiceDeBusca;
use Pbl\Banco\Migracoes\M011ClasseFormulario;
use Pbl\Banco\Migracoes\M012BuscaTolerancia;
use Pbl\Banco\Migracoes\M013Arquivos;
use Pbl\Banco\Migracoes\M014ModoElevado;
use Pbl\Banco\Migracoes\M015HistoricoDeMoldes;
use Pbl\Banco\Migracoes\M016ClassesFixas;
use Pbl\Banco\Migracoes\M017ConfigDoSite;
use Pbl\Banco\Migracoes\M018PeleEMoldePorObjeto;
use Pbl\Banco\Migracoes\M019FluxoDeRevisao;
use Pbl\Banco\Migracoes\M021CompletarClassesFixas;
use Pbl\Banco\Migracoes\M022Tags;
use Pbl\Banco\Migracoes\M023Pilha;
use Pbl\Banco\Migracoes\M024IconeDeClasse;
use Pbl\Banco\Migracoes\M025ImportacaoPbl3;
use Pbl\Banco\Migracoes\M026ContaDeServico;
use Pbl\Banco\Migracoes\M027AltDeArquivo;
use Pbl\Banco\Migracoes\M028TokenDeConta;
use Pbl\Banco\Migracoes\M029SenhaProvisoria;

/**
 * Aplica migrações pendentes. É o único componente que cria tabelas: o usuário de banco da
 * aplicação em runtime não precisa de DDL (ADR 0003). Migrações devem ser compatíveis com a
 * versão anterior do código (expandir/contrair, ADR 0006).
 */
final class Migrador
{
    private const TRAVA = 'pbl_migracao';

    /** @var list<Migracao> */
    private array $migracoes;

    private readonly Dialeto $dialeto;

    /** @param list<Migracao>|null $migracoes */
    public function __construct(private readonly Connection $conexao, ?array $migracoes = null, ?Dialeto $dialeto = null)
    {
        $this->dialeto = $dialeto ?? Dialetos::paraConexao($conexao);
        $this->migracoes = $migracoes ?? [new M001Base(), new M002Metadados(), new M003TituloBusca(), new M004BuscaNormalizada(), new M005VersoesEHistoricoDeUrls(), new M006DominiosERedirecionamentos(), new M007Acesso(), new M008Sessoes(), new M009ClassesNaAdministracao(), new M010IndiceDeBusca(), new M011ClasseFormulario(), new M012BuscaTolerancia(), new M013Arquivos(), new M014ModoElevado(), new M015HistoricoDeMoldes(), new M016ClassesFixas(), new M017ConfigDoSite(), new M018PeleEMoldePorObjeto(), new M019FluxoDeRevisao(), new M021CompletarClassesFixas(), new M022Tags(), new M023Pilha(), new M024IconeDeClasse(), new M025ImportacaoPbl3(), new M026ContaDeServico(), new M027AltDeArquivo(), new M028TokenDeConta(), new M029SenhaProvisoria()];
    }

    /**
     * Aplica as migrações pendentes. Nos bancos de servidor (MySQL, MariaDB e PostgreSQL) uma trava nomeada impede duas migrações ao
     * mesmo tempo. Onde o DDL é transacional (SQLite e PostgreSQL) cada migração roda numa transação; onde o banco comita o DDL sozinho
     * (MySQL e MariaDB) não há como desfazer, então a migração roda direto e a versão só é registrada depois (ADR 0028 §2).
     *
     * @return list<int> versões aplicadas nesta chamada
     */
    public function migrar(): array
    {
        $comTrava = $this->dialeto->nome() !== 'sqlite';
        if ($comTrava && !$this->dialeto->obterTrava($this->conexao, self::TRAVA, 30)) {
            throw new \RuntimeException('Outra migração está em andamento neste banco. Tente de novo em alguns minutos.');
        }
        try {
            $this->garantirTabelaDeVersao();
            $aplicadas = [];
            foreach ($this->migracoes as $migracao) {
                if ($migracao->versao() <= $this->versaoAtual()) {
                    continue;
                }
                $aplicar = function () use ($migracao): void {
                    $migracao->aplicar($this->conexao);
                    $this->conexao->insert('schema_versao', [
                        'versao' => $migracao->versao(),
                        'aplicada_em' => gmdate('c'),
                    ]);
                };
                if ($this->dialeto->ddlTransacional()) {
                    $this->conexao->transactional($aplicar);
                } else {
                    $aplicar();
                }
                $aplicadas[] = $migracao->versao();
            }

            return $aplicadas;
        } finally {
            if ($comTrava) {
                $this->dialeto->soltarTrava($this->conexao, self::TRAVA);
            }
        }
    }

    public function versaoAtual(): int
    {
        $this->garantirTabelaDeVersao();

        return (int) $this->conexao->fetchOne('SELECT COALESCE(MAX(versao), 0) FROM schema_versao');
    }

    private function garantirTabelaDeVersao(): void
    {
        $gerenciador = $this->conexao->createSchemaManager();
        if ($gerenciador->tablesExist(['schema_versao'])) {
            return;
        }
        $esquema = EsquemaPortavel::novo($this->conexao);
        $tabela = $esquema->createTable('schema_versao');
        $tabela->addColumn('versao', 'integer');
        $tabela->addColumn('aplicada_em', 'string', ['length' => 32]);
        $tabela->setPrimaryKey(['versao']);
        $gerenciador->createSchemaObjects($esquema);
    }
}
