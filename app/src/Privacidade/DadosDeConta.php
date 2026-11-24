<?php

declare(strict_types=1);

namespace Pbl\Privacidade;

use Doctrine\DBAL\Connection;
use Pbl\Acesso\Auditoria;
use Pbl\Acesso\LimiteDeTentativas;
use Pbl\Acesso\Usuario;

/**
 * Direitos do titular sobre uma **conta da equipe** (LGPD): exportar o que o sistema guarda dela e anonimizar. Não há coleta nova;
 * o que sai é o que as tabelas `usuario`, `acesso`, `sessao`, `auditoria`, `objeto` e `objeto_versao` já têm. Nunca exporta o hash da
 * senha. A anonimização é irreversível: troca login, e-mail e nome por valores neutros, desativa a conta, encerra sessões, remove
 * papéis, zera o IP (hash) e o login escrito na auditoria, e **mantém** as linhas de auditoria e a autoria do conteúdo apontando
 * para o mesmo número de conta, que deixa de identificar alguém.
 *
 * Fora daqui: dados de participantes de formulário (titular externo, sem conta); ver `docs/tecnica/lgpd.md`.
 */
final class DadosDeConta
{
    public const ACOES_COM_LOGIN = ['usuario.criar', 'usuario.importar'];

    public function __construct(private readonly Connection $conexao, private readonly Auditoria $auditoria, private readonly LimiteDeTentativas $limite)
    {
    }

    /** @return array<string, mixed> */
    public function exportar(Usuario $u): array
    {
        $acessos = $this->conexao->fetchAllAssociative('SELECT objeto_id, perfil FROM acesso WHERE usuario_id = ? ORDER BY objeto_id', [$u->id]);
        $conta = $this->conexao->fetchAssociative('SELECT criado_em, ultimo_acesso FROM usuario WHERE id = ?', [$u->id]) ?: [];

        return [
            'gerado_em' => gmdate('c'),
            'conta' => [
                'id' => $u->id, 'login' => $u->login, 'email' => $u->email, 'nome' => $u->nome,
                'administrador' => $u->administrador, 'ativo' => $u->ativo,
                'criado_em' => $conta['criado_em'] ?? null, 'ultimo_acesso' => $conta['ultimo_acesso'] ?? null,
            ],
            'acessos' => array_map(static fn (array $a): array => ['objeto_id' => (int) $a['objeto_id'], 'perfil' => (string) $a['perfil']], $acessos),
            'tokens_de_conta_abertos' => (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM token_conta WHERE usuario_id = ? AND usado_em IS NULL', [$u->id]), // só a contagem; nunca o token
            'sessoes_abertas' => (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM sessao WHERE usuario_id = ?', [$u->id]),
            'objetos_criados' => array_map('intval', $this->conexao->fetchFirstColumn('SELECT id FROM objeto WHERE criado_por = ? ORDER BY id', [$u->id])),
            'versoes_gravadas' => (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM objeto_versao WHERE usuario_id = ?', [$u->id]),
            'auditoria' => array_map(static function (array $l): array {
                unset($l['_id']);

                return $l;
            }, $this->linhasDeAuditoria($u)),
            'observacao' => 'A senha nunca é exportada (só existe o hash, que não sai do banco). O IP aparece apenas como hash.',
        ];
    }

    public function anonimizar(Usuario $u, string $ator = 'cli'): void
    {
        if ($u->administrador && $u->ativo && (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM usuario WHERE administrador = 1 AND ativo = 1') <= 1) {
            throw new \InvalidArgumentException('Esta é a última conta de administrador ativa; crie outra antes de anonimizá-la');
        }
        $linhas = $this->linhasDeAuditoria($u);
        $this->conexao->transactional(function () use ($u, $linhas): void {
            $this->conexao->update('usuario', [
                'login' => "anonimo-{$u->id}",
                'email' => "anonimo-{$u->id}@anonimizado.invalid",
                'nome' => 'Conta anonimizada',
                'senha_hash' => '!anonimizada', // não é hash válido: nenhuma senha confere
                'administrador' => 0,
                'ativo' => 0,
                'ultimo_acesso' => null,
            ], ['id' => $u->id]);
            $this->conexao->delete('sessao', ['usuario_id' => $u->id]);
            $this->conexao->delete('acesso', ['usuario_id' => $u->id]);
            $this->conexao->delete('token_conta', ['usuario_id' => $u->id]); // links de uso único não sobrevivem à anonimização
            $this->conexao->executeStatement('UPDATE auditoria SET ip_hash = NULL WHERE usuario_id = ?', [$u->id]);
            foreach ($linhas as $l) {
                if (in_array($l['acao'], self::ACOES_COM_LOGIN, true) && $l['_id'] !== null) {
                    $this->conexao->update('auditoria', ['detalhe' => $this->semLogin((string) $l['detalhe'])], ['id' => $l['_id']]);
                }
            }
        });
        $this->limite->liberarLogin($u->login); // o contador de tentativas do login antigo não sobra
        $this->auditoria->registrar('conta.anonimizar', $ator, $u->id);
    }

    /** @return list<array{_id: int|null, quando: string, ator: string, acao: string, objeto_id: int|null, detalhe: string|null, ip_hash: string|null}> */
    private function linhasDeAuditoria(Usuario $u): array
    {
        $saida = [];
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT id, quando, ator, acao, objeto_id, detalhe, ip_hash, usuario_id FROM auditoria WHERE usuario_id = ? OR acao IN (?, ?) ORDER BY id',
            [$u->id, ...self::ACOES_COM_LOGIN],
        );
        foreach ($linhas as $l) {
            $propria = $l['usuario_id'] !== null && (int) $l['usuario_id'] === $u->id;
            if (!$propria && $this->loginNoDetalhe((string) $l['detalhe']) !== $u->login) {
                continue;
            }
            $saida[] = [
                '_id' => (int) $l['id'], 'quando' => (string) $l['quando'], 'ator' => (string) $l['ator'], 'acao' => (string) $l['acao'],
                'objeto_id' => $l['objeto_id'] === null ? null : (int) $l['objeto_id'],
                'detalhe' => $l['detalhe'] === null ? null : (string) $l['detalhe'], 'ip_hash' => $l['ip_hash'] === null ? null : (string) $l['ip_hash'],
            ];
        }

        return $saida;
    }

    private function loginNoDetalhe(string $detalhe): ?string
    {
        $d = json_decode($detalhe, true);

        return is_array($d) && isset($d['usuario']) && is_string($d['usuario']) ? $d['usuario'] : null;
    }

    private function semLogin(string $detalhe): string
    {
        $d = json_decode($detalhe, true);
        if (is_array($d)) {
            $d['usuario'] = 'anonimizado';
        }

        return json_encode($d, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
