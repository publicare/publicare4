<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

use Doctrine\DBAL\Connection;
use Pbl\Acesso\Acao;
use Pbl\Acesso\Auditoria;
use Pbl\Acesso\Perfil;
use Pbl\Acesso\Permissoes;
use Pbl\Acesso\Usuario;
use Pbl\Acesso\UsuarioRepositorio;

/**
 * Fluxo de revisão (fase 6, ADR 0012): o autor "envia para revisão" (`privado` → `submetido`), pode retirar o envio (volta a
 * `privado`) e, se for devolvido (`rejeitado`, com motivo), reenvia; quem tem o perfil de editor na área (ou o administrador
 * global) aprova (`publicado`) ou devolve (`rejeitado`, motivo obrigatório). Não existe tabela de "quem aprova quem": o revisor
 * de um objeto é quem tem `Acao::Publicar` nele pelas regras de sempre (`Permissoes`: perfil editor na seção ou em qualquer
 * ancestral, que vale para tudo abaixo). Cada passo é gravado em `revisao` (quem, quando, motivo) e na auditoria (sem o motivo).
 */
final class FluxoDeRevisao
{
    public const MOTIVO_MIN = 3;
    public const MOTIVO_MAX = 500;
    /** Teto de itens que uma fila ou lista carrega de uma vez. */
    public const LIMITE = 200;

    public function __construct(
        private readonly Connection $conexao,
        private readonly ObjetoRepositorio $objetos,
        private readonly Permissoes $permissoes,
        private readonly Auditoria $auditoria,
        private readonly UsuarioRepositorio $usuarios,
    ) {
    }

    // ---------------------------------------------------------------- passos

    /** Autor (ou editor) manda um conteúdo privado, ou devolvido, para a revisão. */
    public function enviar(Usuario $quem, int $id, ?int $versao = null): Objeto
    {
        return $this->conexao->transactional(function () use ($quem, $id, $versao): Objeto {
            $o = $this->objeto($id);
            if (!in_array($o->status, ['privado', 'rejeitado'], true)) {
                throw new ErroDeRevisao(ErroDeRevisao::ESTADO, 'Só conteúdo privado ou devolvido pode ser enviado para revisão');
            }
            $this->exigir($quem, Acao::Editar, $o);

            return $this->mudar($quem, $o, 'submetido', $o->status === 'rejeitado' ? 'reenviou' : 'enviou', $versao);
        });
    }

    /** Quem enviou tira o envio pendente da fila; o conteúdo volta a privado. */
    public function retirar(Usuario $quem, int $id, ?int $versao = null): Objeto
    {
        return $this->conexao->transactional(function () use ($quem, $id, $versao): Objeto {
            $o = $this->objeto($id);
            if ($o->status !== 'submetido') {
                throw new ErroDeRevisao(ErroDeRevisao::ESTADO, 'Só um envio pendente pode ser retirado');
            }
            $this->exigir($quem, Acao::Editar, $o);
            if ($this->quemEnviou($o) !== $quem->id) {
                throw new ErroDeRevisao(ErroDeRevisao::NEGADO, 'Só quem enviou pode retirar o envio');
            }

            return $this->mudar($quem, $o, 'privado', 'retirou', $versao);
        });
    }

    /** Revisor aprova: o conteúdo passa a `publicado` (as datas de publicação e validade que ele já tem continuam valendo). */
    public function aprovar(Usuario $quem, int $id, ?int $versao = null): Objeto
    {
        return $this->conexao->transactional(function () use ($quem, $id, $versao): Objeto {
            $o = $this->objeto($id);
            if ($o->status !== 'submetido') {
                throw new ErroDeRevisao(ErroDeRevisao::ESTADO, 'Só um envio pendente pode ser aprovado');
            }
            $this->exigir($quem, Acao::Publicar, $o);

            return $this->mudar($quem, $o, 'publicado', 'aprovou', $versao);
        });
    }

    /** Revisor devolve ao autor com um motivo obrigatório. */
    public function devolver(Usuario $quem, int $id, string $motivo, ?int $versao = null): Objeto
    {
        $motivo = trim(str_replace(["\r\n", "\r"], "\n", $motivo));

        return $this->conexao->transactional(function () use ($quem, $id, $motivo, $versao): Objeto {
            $o = $this->objeto($id);
            if ($o->status !== 'submetido') {
                throw new ErroDeRevisao(ErroDeRevisao::ESTADO, 'Só um envio pendente pode ser devolvido');
            }
            $this->exigir($quem, Acao::Publicar, $o);
            $erro = self::problemaNoMotivo($motivo);
            if ($erro !== null) {
                throw new ErroDeRevisao(ErroDeRevisao::MOTIVO, $erro);
            }

            return $this->mudar($quem, $o, 'rejeitado', 'devolveu', $versao, $motivo);
        });
    }

    /** Texto do motivo: UTF-8 válido, de 3 a 500 caracteres, sem caracteres de controle (a quebra de linha vale). */
    public static function problemaNoMotivo(string $motivo): ?string
    {
        if (!mb_check_encoding($motivo, 'UTF-8') || preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $motivo) === 1) {
            return 'O motivo tem caracteres inválidos.';
        }
        $n = mb_strlen($motivo);
        if ($n < self::MOTIVO_MIN) {
            return 'Explique o motivo da devolução (pelo menos ' . self::MOTIVO_MIN . ' caracteres).';
        }
        if ($n > self::MOTIVO_MAX) {
            return 'O motivo pode ter até ' . self::MOTIVO_MAX . ' caracteres.';
        }

        return null;
    }

    // ---------------------------------------------------------------- consultas

    /** O usuário pode revisar algo? (administrador global, ou perfil de editor em alguma seção) */
    public function ehRevisor(Usuario $u): bool
    {
        return $u->ativo && ($u->administrador || $this->areasComoEditor($u) !== []);
    }

    /** O usuário pode criar/enviar conteúdo? (administrador, ou perfil de autor ou editor em alguma seção) */
    public function podeEnviar(Usuario $u): bool
    {
        if (!$u->ativo) {
            return false;
        }

        return $u->administrador || (bool) $this->conexao->fetchOne("SELECT 1 FROM acesso WHERE usuario_id = ? AND perfil IN ('autor', 'editor') LIMIT 1", [$u->id]);
    }

    /**
     * A fila de quem revisa: envios pendentes das áreas em que a conta é editor (o administrador global vê todas), do mais antigo
     * para o mais novo. Só entra o que `Permissoes` deixaria publicar; nada de outras áreas.
     *
     * @return list<Objeto>
     */
    public function pendencias(Usuario $u, ?int $areaId = null, int $limite = self::LIMITE): array
    {
        if (!$u->ativo) {
            return [];
        }
        $area = $areaId === null ? null : $this->objetos->porId($areaId);
        $linhas = $this->conexao->fetchAllAssociative("SELECT * FROM objeto WHERE status = 'submetido' ORDER BY atualizado_em, id");
        $saida = [];
        $cobertura = $u->administrador ? null : $this->areasComoEditor($u);
        foreach ($this->objetos->hidratarLinhas($linhas) as $o) {
            if ($cobertura !== null && !$this->coberto($cobertura, $o)) {
                continue;
            }
            if ($area !== null && ($o->siteId !== $area->siteId || !str_starts_with($o->caminho, $area->caminho))) {
                continue;
            }
            $saida[] = $o;
            if (count($saida) >= $limite) {
                break;
            }
        }

        return $saida;
    }

    /** Quantos envios aguardam este revisor (o contador do menu). Barato: só id, site e caminho. */
    public function contarPendencias(Usuario $u): int
    {
        if (!$u->ativo) {
            return 0;
        }
        $cobertura = $u->administrador ? null : $this->areasComoEditor($u);
        if ($cobertura === []) {
            return 0;
        }
        $n = 0;
        foreach ($this->conexao->fetchAllAssociative("SELECT site_id, caminho FROM objeto WHERE status = 'submetido'") as $l) {
            if ($cobertura === null || $this->cobre($cobertura, (int) $l['site_id'], (string) $l['caminho'])) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * Áreas para o filtro da fila: onde a conta é editor (mais as seções logo abaixo); o administrador global, as seções logo abaixo da página inicial de cada site.
     *
     * @return list<Objeto>
     */
    public function areasParaFiltro(Usuario $u): array
    {
        if (!$u->ativo) {
            return [];
        }
        if (!$u->administrador) {
            $saida = $this->areasComoEditor($u);
            if ($saida === []) {
                return [];
            }
            // Além das seções da conta, as que estão logo abaixo delas e têm conteúdo (tudo isso a conta já revisa).
            $vistos = array_flip(array_map(static fn (Objeto $a): int => $a->id, $saida));
            $marcas = implode(',', array_fill(0, count($saida), '?'));
            $linhas = $this->conexao->fetchAllAssociative(
                "SELECT c.* FROM objeto c WHERE c.pai_id IN ($marcas) AND c.status <> 'apagado' AND EXISTS (SELECT 1 FROM objeto f WHERE f.pai_id = c.id) ORDER BY c.site_id, c.peso, c.titulo LIMIT " . self::LIMITE,
                array_map(static fn (Objeto $a): int => $a->id, $saida),
            );
            foreach ($this->objetos->hidratarLinhas($linhas) as $c) {
                if (!isset($vistos[$c->id])) {
                    $saida[] = $c;
                }
            }

            return $saida;
        }
        $saida = [];
        foreach ($this->conexao->fetchAllAssociative("SELECT f.* FROM objeto f JOIN objeto p ON p.id = f.pai_id WHERE p.pai_id IS NULL AND f.status <> 'apagado' ORDER BY f.site_id, f.peso, f.titulo LIMIT " . self::LIMITE) as $l) {
            $saida[] = $this->objetos->hidratarLinhas([$l])[0];
        }

        return $saida;
    }

    /**
     * "Meus envios": o que a conta enviou para revisão (qualquer estado atual) mais os rascunhos privados que ela criou e os seus
     * conteúdos pendentes ou devolvidos. Só o que a conta ainda pode ver.
     *
     * @return list<Objeto> os mais mexidos primeiro
     */
    public function meusEnvios(Usuario $u, int $limite = self::LIMITE): array
    {
        if (!$u->ativo) {
            return [];
        }
        $linhas = $this->conexao->fetchAllAssociative(
            "SELECT * FROM objeto WHERE status <> 'apagado' AND (id IN (SELECT objeto_id FROM revisao WHERE usuario_id = ? AND acao IN ('enviou', 'reenviou'))"
            . " OR (criado_por = ? AND status IN ('privado', 'submetido', 'rejeitado'))) ORDER BY atualizado_em DESC, id DESC LIMIT " . ($limite * 2),
            [$u->id, $u->id],
        );
        $saida = [];
        foreach ($this->objetos->hidratarLinhas($linhas) as $o) {
            if ($this->permissoes->pode($u, Acao::Ver, $o)) {
                $saida[] = $o;
                if (count($saida) >= $limite) {
                    break;
                }
            }
        }

        return $saida;
    }

    /** Quantos conteúdos da conta estão devolvidos, esperando ajuste (o aviso da tela inicial). */
    public function contarDevolvidos(Usuario $u): int
    {
        if (!$u->ativo) {
            return 0;
        }

        return (int) $this->conexao->fetchOne(
            "SELECT COUNT(*) FROM objeto WHERE status = 'rejeitado' AND (criado_por = ? OR id IN (SELECT objeto_id FROM revisao WHERE usuario_id = ? AND acao IN ('enviou', 'reenviou')))",
            [$u->id, $u->id],
        );
    }

    /** Passos de um conteúdo, do mais novo para o mais antigo, com o nome de quem os fez.
     *
     * @return list<array{acao: string, quando: string, quem: string, motivo: ?string}>
     */
    public function historico(int $objetoId, int $limite = 20): array
    {
        $saida = [];
        foreach ($this->conexao->fetchAllAssociative(
            'SELECT r.acao, r.quando, r.motivo, u.nome FROM revisao r LEFT JOIN usuario u ON u.id = r.usuario_id WHERE r.objeto_id = ? ORDER BY r.id DESC LIMIT ' . max(1, min(200, $limite)),
            [$objetoId],
        ) as $l) {
            $saida[] = ['acao' => (string) $l['acao'], 'quando' => (string) $l['quando'], 'quem' => $l['nome'] === null ? 'conta removida' : (string) $l['nome'], 'motivo' => $l['motivo'] === null ? null : (string) $l['motivo']];
        }

        return $saida;
    }

    /**
     * O último passo de cada ação dada, por conteúdo: `[objeto_id => ['acao', 'quando', 'quem', 'motivo']]`.
     *
     * @param list<int> $ids
     * @param list<string> $acoes
     * @return array<int, array{acao: string, quando: string, quem: string, motivo: ?string}>
     */
    public function ultimos(array $ids, array $acoes): array
    {
        if ($ids === [] || $acoes === []) {
            return [];
        }
        $saida = [];
        $marcas = implode(',', array_fill(0, count($acoes), '?'));
        foreach (array_chunk($ids, 200) as $lote) {
            $linhas = $this->conexao->fetchAllAssociative(
                'SELECT r.objeto_id, r.acao, r.quando, r.motivo, u.nome FROM revisao r LEFT JOIN usuario u ON u.id = r.usuario_id WHERE r.acao IN (' . $marcas . ') AND r.objeto_id IN (' . implode(',', array_fill(0, count($lote), '?')) . ') ORDER BY r.id',
                [...$acoes, ...$lote],
            );
            foreach ($linhas as $l) {
                $saida[(int) $l['objeto_id']] = ['acao' => (string) $l['acao'], 'quando' => (string) $l['quando'], 'quem' => $l['nome'] === null ? 'conta removida' : (string) $l['nome'], 'motivo' => $l['motivo'] === null ? null : (string) $l['motivo']];
            }
        }

        return $saida;
    }

    /** Quem fez o último envio pendente do conteúdo (sem registro, como nos envios antigos: quem o criou). */
    public function quemEnviou(Objeto $o): ?int
    {
        $id = $this->conexao->fetchOne("SELECT usuario_id FROM revisao WHERE objeto_id = ? AND acao IN ('enviou', 'reenviou') ORDER BY id DESC LIMIT 1", [$o->id]);

        return $id === false || $id === null ? $o->criadoPor : (int) $id;
    }

    /** O usuário pode retirar este envio? (é quem enviou e ainda pode editar) */
    public function podeRetirar(Usuario $u, Objeto $o): bool
    {
        return $o->status === 'submetido' && $this->quemEnviou($o) === $u->id && $this->permissoes->pode($u, Acao::Editar, $o);
    }

    // ---------------------------------------------------------------- apoio

    private function objeto(int $id): Objeto
    {
        $o = $this->objetos->porId($id);
        if ($o === null) {
            throw new ErroDeRevisao(ErroDeRevisao::NEGADO, 'Conteúdo inexistente');
        }

        return $o;
    }

    private function exigir(Usuario $quem, Acao $acao, Objeto $o): void
    {
        if (!$this->permissoes->pode($quem, $acao, $o)) {
            throw new ErroDeRevisao(ErroDeRevisao::NEGADO, 'Sem permissão para esta ação');
        }
    }

    private function mudar(Usuario $quem, Objeto $o, string $para, string $passo, ?int $versao, ?string $motivo = null): Objeto
    {
        try {
            $novo = $this->objetos->atualizar($o->id, ['status' => $para], 'revisão: ' . $passo, $versao, false, $quem->id);
        } catch (ConflitoDeVersao) {
            throw new ErroDeRevisao(ErroDeRevisao::CONFLITO, 'O conteúdo mudou depois que a tela foi aberta');
        }
        $this->conexao->insert('revisao', ['objeto_id' => $o->id, 'usuario_id' => $quem->id, 'acao' => $passo, 'motivo' => $motivo, 'quando' => gmdate('c')]);
        // O motivo fica só na tabela `revisao`: a auditoria guarda quem, o quê e de/para, nunca texto livre.
        $this->auditoria->registrar('revisao.' . $passo, 'usuario:' . $quem->id, $quem->id, $o->id, ['de' => $o->status, 'para' => $para]);

        return $novo;
    }

    /**
     * Seções em que a conta é editor (a seção e tudo abaixo dela).
     *
     * @return list<Objeto>
     */
    private function areasComoEditor(Usuario $u): array
    {
        $saida = [];
        foreach ($this->usuarios->acessosDe($u->id) as $a) {
            if ($a['perfil'] === Perfil::Editor && ($o = $this->objetos->porId($a['objeto_id'])) !== null && $o->status !== 'apagado') {
                $saida[] = $o;
            }
        }

        return $saida;
    }

    /** @param list<Objeto> $areas */
    private function coberto(array $areas, Objeto $o): bool
    {
        return $this->cobre($areas, $o->siteId, $o->caminho);
    }

    /** @param list<Objeto> $areas */
    private function cobre(array $areas, int $siteId, string $caminho): bool
    {
        foreach ($areas as $a) {
            if ($a->siteId === $siteId && str_starts_with($caminho, $a->caminho)) {
                return true;
            }
        }

        return false;
    }
}
