<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Migracao;
use Pbl\Conteudo\ClassesPadrao;

/**
 * Completa as classes fixas de instalações antigas (`garantirFixas` só cria a classe que falta, não acrescenta campos novos a uma
 * que já existe, e antes desta migração nem recriava `formulario`). A fonte é {@see ClassesPadrao::definicoes()}: para cada classe
 * fixa que **existe** na instalação, acrescenta ao fim as propriedades que ela não tem (por nome) e sobe a `versao` da classe.
 * Aditiva: nunca remove, reordena nem altera uma propriedade que já existe (nem uma que o administrador arquivou ou cujo rótulo
 * mudou), e não cria classe nova. Idempotente: com tudo no lugar, não escreve nada. Sem mudança de esquema.
 */
final class M021CompletarClassesFixas implements Migracao
{
    public function versao(): int
    {
        return 21;
    }

    public function aplicar(Connection $conexao): void
    {
        foreach (ClassesPadrao::definicoes() as $d) {
            if (!in_array($d['nome'], ClassesPadrao::FIXAS, true)) {
                continue;
            }
            $linha = $conexao->fetchAssociative('SELECT id, propriedades FROM classe WHERE nome = ?', [$d['nome']]);
            if ($linha === false) {
                continue;
            }
            $atuais = json_decode((string) $linha['propriedades'], true);
            if (!is_array($atuais)) {
                continue; // JSON ilegível: não mexe
            }
            $nomes = [];
            foreach ($atuais as $p) {
                if (is_array($p) && isset($p['nome']) && is_string($p['nome'])) {
                    $nomes[$p['nome']] = true;
                }
            }
            $faltam = array_values(array_filter($d['propriedades'], static fn (array $p): bool => !isset($nomes[$p['nome']])));
            if ($faltam === []) {
                continue;
            }
            $conexao->executeStatement(
                'UPDATE classe SET propriedades = ?, versao = versao + 1 WHERE id = ?',
                [json_encode([...array_values($atuais), ...$faltam], JSON_THROW_ON_ERROR), $linha['id']],
            );
        }
    }
}
