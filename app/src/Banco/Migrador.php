<?php

declare(strict_types=1);

namespace Pbl\Banco;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
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
use Pbl\Banco\Migracoes\M019FluxoDeRevisao;

/**
 * Aplica migrações pendentes. É o único componente que cria tabelas: o usuário de banco da
 * aplicação em runtime não precisa de DDL (ADR 0003). Migrações devem ser compatíveis com a
 * versão anterior do código (expandir/contrair, ADR 0006).
 */
final class Migrador
{
    /** @var list<Migracao> */
    private array $migracoes;

    /** @param list<Migracao>|null $migracoes */
    public function __construct(private readonly Connection $conexao, ?array $migracoes = null)
    {
        $this->migracoes = $migracoes ?? [new M001Base(), new M002Metadados(), new M003TituloBusca(), new M004BuscaNormalizada(), new M005VersoesEHistoricoDeUrls(), new M006DominiosERedirecionamentos(), new M007Acesso(), new M008Sessoes(), new M009ClassesNaAdministracao(), new M010IndiceDeBusca(), new M011ClasseFormulario(), new M012BuscaTolerancia(), new M013Arquivos(), new M014ModoElevado(), new M015HistoricoDeMoldes(), new M016ClassesFixas(), new M017ConfigDoSite(), new M019FluxoDeRevisao()];
    }

    /** @return list<int> versões aplicadas nesta chamada */
    public function migrar(): array
    {
        $this->garantirTabelaDeVersao();
        $aplicadas = [];
        foreach ($this->migracoes as $migracao) {
            if ($migracao->versao() <= $this->versaoAtual()) {
                continue;
            }
            $this->conexao->transactional(function () use ($migracao): void {
                $migracao->aplicar($this->conexao);
                $this->conexao->insert('schema_versao', [
                    'versao' => $migracao->versao(),
                    'aplicada_em' => gmdate('c'),
                ]);
            });
            $aplicadas[] = $migracao->versao();
        }

        return $aplicadas;
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
        $esquema = new Schema();
        $tabela = $esquema->createTable('schema_versao');
        $tabela->addColumn('versao', 'integer');
        $tabela->addColumn('aplicada_em', 'string', ['length' => 32]);
        $tabela->setPrimaryKey(['versao']);
        $gerenciador->createSchemaObjects($esquema);
    }
}
