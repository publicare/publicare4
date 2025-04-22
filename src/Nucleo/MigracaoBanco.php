<?php

namespace Nucleo;

use Nucleo\Migracoes\CriarTabelaPerfil;
use Nucleo\Migracoes\CriarTabelaUsuario;
use Nucleo\Migracoes\CriarTabelaGrupo;
use Nucleo\Migracoes\CriarTabelaPermissao;
use Nucleo\Migracoes\CriarTabelaClasse;
use Nucleo\Migracoes\CriarTabelaPropriedade;
use Nucleo\Migracoes\CriarTabelaTipoDado;
use Nucleo\Migracoes\CriarTabelaInfoPerfil;
use Nucleo\Migracoes\CriarTabelaReferenciaClasse;

use Nucleo\Migracoes\CriarTabelaObjeto;
use Nucleo\Migracoes\CriarTabelaVersaoObjeto;
use Nucleo\Migracoes\CriarTabelaRelacao;
use Nucleo\Migracoes\CriarTabelaCampo;
use Nucleo\Migracoes\CriarTabelaValor;

use Nucleo\Migracoes\CriarTabelaString;
use Nucleo\Migracoes\CriarTabelaText;
use Nucleo\Migracoes\CriarTabelaBooleano;
use Nucleo\Migracoes\CriarTabelaInteger;
use Nucleo\Migracoes\CriarTabelaFloat;
use Nucleo\Migracoes\CriarTabelaDate;
use Nucleo\Migracoes\CriarTabelaObjetoRef;

use Nucleo\Migracoes\CriarTabelaArquivo;
use Nucleo\Migracoes\CriarTabelaBlob;

use Nucleo\Migracoes\CriarTabelaLog;
use Nucleo\Migracoes\CriarTabelaPendencia;
use Nucleo\Migracoes\CriarTabelaAgendamento;
use Nucleo\Migracoes\CriarTabelaCache;

class MigracaoBanco
{
    public static function executar(): void
    {
        echo "🚧 Iniciando criação das tabelas do Publicare...\n";

        // Bloco de Acesso
        CriarTabelaPerfil::executar();
        CriarTabelaUsuario::executar();
        CriarTabelaGrupo::executar();

        // Estrutura geral
        CriarTabelaClasse::executar();
        CriarTabelaTipoDado::executar();
        CriarTabelaPropriedade::executar();
        CriarTabelaObjeto::executar();

        // Permissões e regras
        CriarTabelaPermissao::executar();
        CriarTabelaInfoPerfil::executar();
        CriarTabelaReferenciaClasse::executar();

        // Versões e estrutura dinâmica
        CriarTabelaVersaoObjeto::executar();
        CriarTabelaRelacao::executar();
        CriarTabelaCampo::executar();
        CriarTabelaValor::executar();

        // Tipos plugáveis
        CriarTabelaString::executar();
        CriarTabelaText::executar();
        CriarTabelaBooleano::executar();
        CriarTabelaInteger::executar();
        CriarTabelaFloat::executar();
        CriarTabelaDate::executar();
        CriarTabelaObjetoRef::executar();

        // Arquivos
        CriarTabelaArquivo::executar();
        CriarTabelaBlob::executar();

        // Sistema e controle
        CriarTabelaLog::executar();
        CriarTabelaPendencia::executar();
        CriarTabelaAgendamento::executar();
        CriarTabelaCache::executar();

        echo "✅ Estrutura do banco concluída com sucesso!\n";
    }
}
