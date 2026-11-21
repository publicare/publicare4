<?php

declare(strict_types=1);

namespace Pbl\Atualizacao;

/**
 * As etapas que a atualização (ou a volta atrás) mostra na página "Atualização". O percentual é aproximado e segue a ordem real
 * de `Atualizador::aplicar`: baixar, conferir, abrir, guardar o banco, trocar o código, migrar, conferir a versão.
 */
enum Etapa: string
{
    case Preparando = 'preparando';
    case Baixando = 'baixando';
    case Conferindo = 'conferindo';
    case Extraindo = 'extraindo';
    case CopiaBanco = 'copia_banco';
    case TrocandoCodigo = 'trocando_codigo';
    case MigrandoBanco = 'migrando_banco';
    case ConferindoVersao = 'conferindo_versao';
    case Concluido = 'concluido';
    case Falhou = 'falhou';
    case VoltandoAtras = 'voltando_atras';

    /** Percentual aproximado; na falha vale o último que a operação alcançou. */
    public function percentual(): int
    {
        return match ($this) {
            self::Preparando => 5,
            self::Baixando => 15,
            self::Conferindo => 35,
            self::Extraindo => 45,
            self::CopiaBanco => 55,
            self::TrocandoCodigo => 70,
            self::MigrandoBanco => 85,
            self::ConferindoVersao => 95,
            self::Concluido => 100,
            self::Falhou => 0,
            self::VoltandoAtras => 50,
        };
    }

    /** Nome curto da etapa (vai no `aria-live`). */
    public function nome(): string
    {
        return match ($this) {
            self::Preparando => 'Preparando',
            self::Baixando => 'Baixando',
            self::Conferindo => 'Conferindo a assinatura e a integridade',
            self::Extraindo => 'Abrindo os arquivos',
            self::CopiaBanco => 'Guardando a cópia do banco',
            self::TrocandoCodigo => 'Trocando o código',
            self::MigrandoBanco => 'Atualizando o banco',
            self::ConferindoVersao => 'Conferindo a versão',
            self::Concluido => 'Concluído',
            self::Falhou => 'Falhou',
            self::VoltandoAtras => 'Voltando à versão anterior',
        };
    }

    /** Frase curta, em português, sem jargão. */
    public function mensagem(): string
    {
        return match ($this) {
            self::Preparando => 'Preparando e conferindo a versão no canal de atualizações',
            self::Baixando => 'Baixando a nova versão',
            self::Conferindo => 'Conferindo a assinatura e a integridade do pacote',
            self::Extraindo => 'Abrindo os arquivos da nova versão',
            self::CopiaBanco => 'Guardando uma cópia de segurança do banco',
            self::TrocandoCodigo => 'Trocando o código (o site fica em manutenção por instantes)',
            self::MigrandoBanco => 'Atualizando o banco',
            self::ConferindoVersao => 'Conferindo se a nova versão funciona',
            self::Concluido => 'Concluído',
            self::Falhou => 'Falhou',
            self::VoltandoAtras => 'Voltando à versão anterior (código e banco)',
        };
    }
}
