<?php

declare(strict_types=1);

namespace Pbl\Importacao;

use Doctrine\DBAL\Connection;
use Pbl\Acesso\Perfil;
use Pbl\Arquivos\ErroDeUpload;
use Pbl\Busca\Texto;
use Pbl\Conteudo\Objeto;
use Pbl\Conteudo\TagRepositorio;
use Pbl\Servicos;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Importa uma base do PBL3 para uma instalação do PBL4 (ADR 0027). Lê a origem só em lotes, grava o destino numa transação por
 * lote e registra cada id importado em `importacao_pbl3`: rodar de novo (mesma `fonte`) pula o que já veio e continua do resto.
 * Em `simular` faz exatamente o mesmo caminho dentro de uma transação que é desfeita no fim, sem copiar arquivo nenhum.
 */
final class Importador
{
    private const ATOR = 'importacao';
    private const TIPOS_DE_VALOR = ['tbl_string', 'tbl_text', 'tbl_integer', 'tbl_float', 'tbl_date', 'tbl_boolean'];

    private readonly Connection $destino;
    private readonly MapaDeIds $ids;
    private readonly \DateTimeZone $fuso;
    private int $siteId = 0;
    /** @var list<int> objetos do PBL3 que são a raiz do que foi importado (para o perfil Administrador virar administrador global) */
    private array $raizes = [];
    /** @var array<int, string> */
    private array $peles = [];
    private ?string $hashInutilizavel = null;
    /** Um link do PBL3 que aponta para outro objeto só conhece o alvo na segunda passada; até lá ele guarda este endereço, trocado ao resolver. */
    private const DESTINO_PROVISORIO = 'https://importado.invalid/';

    public function __construct(
        private readonly Servicos $servicos,
        private readonly OrigemPbl3 $origem,
        private readonly Opcoes $opcoes,
        private readonly Relatorio $relatorio,
    ) {
        $this->destino = $servicos->conexao();
        $this->ids = new MapaDeIds($this->destino, $opcoes->fonte);
        try {
            $this->fuso = new \DateTimeZone($opcoes->fuso);
        } catch (\Exception) {
            throw new \InvalidArgumentException("Fuso horário inválido: {$opcoes->fuso}");
        }
    }

    public function executar(): Relatorio
    {
        if (!$this->destino->createSchemaManager()->tablesExist(['importacao_pbl3'])) {
            throw new \RuntimeException('A instalação não tem a tabela do importador: rode `bin/pbl migrar` antes.');
        }
        foreach (MapaDeTabelas::OBRIGATORIAS as $tabela) {
            if (!$this->origem->existe($tabela)) {
                throw new \RuntimeException("A base de origem não tem a tabela {$tabela} (confira --mapa-tabelas)");
            }
        }
        if ($this->opcoes->uploads !== null && !is_dir($this->opcoes->uploads)) {
            throw new \InvalidArgumentException("A pasta de arquivos não existe: {$this->opcoes->uploads}");
        }
        $this->siteId = $this->resolverSite();
        if ($this->opcoes->simular) {
            $this->destino->beginTransaction();
        }
        try {
            $this->fases();
        } catch (\Throwable $e) {
            if ($this->opcoes->simular && $this->destino->isTransactionActive()) {
                $this->destino->rollBack();
            }
            throw $e;
        }
        if ($this->opcoes->simular) {
            $this->destino->rollBack();
        }

        return $this->relatorio;
    }

    private function fases(): void
    {
        $this->peles = $this->origem->peles();
        $plano = $this->destino->transactional(fn (): PlanoDeClasses => PlanoDeClasses::montar($this->origem, $this->servicos->classes, $this->ids, $this->relatorio));
        foreach ($plano->descartadas as $cod => $nome) {
            $this->relatorio->avisar('propriedade do PBL3 sem lugar no PBL4 (descartada)', $nome);
        }
        $this->importarUsuarios();
        $this->importarObjetos($plano);
        $this->resolverReferencias($plano);
        $this->conceder();
        $this->servicos->auditoria->registrar('importacao.pbl3', 'cli', null, null, [
            'fonte' => $this->opcoes->fonte,
            'simulacao' => $this->opcoes->simular,
            'objetos' => $this->relatorio->total('objetos importados'),
            'usuarios' => $this->relatorio->total('usuários importados'),
            'avisos' => $this->relatorio->totalDeAvisos(),
        ]);
    }

    private function resolverSite(): int
    {
        $sites = $this->servicos->sites->todos();
        if ($this->opcoes->siteId !== null) {
            foreach ($sites as $s) {
                if ($s->id === $this->opcoes->siteId) {
                    return $s->id;
                }
            }
            throw new \InvalidArgumentException("O site {$this->opcoes->siteId} não existe nesta instalação");
        }
        if (count($sites) !== 1) {
            throw new \InvalidArgumentException('A instalação tem ' . count($sites) . ' sites: informe --site=ID');
        }

        return $sites[0]->id;
    }

    // ---------------------------------------------------------------- usuários

    private function importarUsuarios(): void
    {
        if (!$this->origem->existe('usuario')) {
            $this->relatorio->avisar('tabela usuario ausente na origem (nenhuma conta importada)');

            return;
        }
        $apos = 0;
        do {
            $linhas = $this->origem->usuarios($apos, $this->opcoes->lote);
            if ($linhas === []) {
                break;
            }
            $apos = (int) end($linhas)['cod_usuario'];
            $this->destino->transactional(function () use ($linhas): void {
                $mapeados = $this->ids->varios(MapaDeIds::USUARIO, array_map(static fn (array $l): int => (int) $l['cod_usuario'], $linhas));
                foreach ($linhas as $l) {
                    $cod = (int) $l['cod_usuario'];
                    if (isset($mapeados[$cod])) {
                        $this->relatorio->contar('usuários já importados');
                        continue;
                    }
                    $this->criarUsuario($cod, $l);
                }
            });
        } while (count($linhas) === $this->opcoes->lote);
    }

    /** @param array<string, mixed> $l */
    private function criarUsuario(int $cod, array $l): void
    {
        $original = (string) ($l['login'] ?? '');
        $login = Conversao::login($original);
        if ($login === '') {
            $login = 'usuario' . $cod;
        }
        $base = $login;
        for ($i = 2; $this->destino->fetchOne('SELECT 1 FROM usuario WHERE login = ?', [$login]) !== false; ++$i) {
            $login = substr($base, 0, 60) . '-' . $i;
        }
        if ($login !== mb_strtolower(trim($original))) {
            $this->relatorio->avisar('login ajustado (inválido ou repetido)', "usuário {$cod}");
        }
        $email = trim($this->utf8((string) ($l['email'] ?? '')));
        if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = $login . '@importado.invalid';
            $this->relatorio->avisar('e-mail ausente ou inválido (trocado por um marcador)', "usuário {$cod}");
        }
        $nome = mb_substr(trim($this->utf8((string) ($l['nome'] ?? ''))), 0, 200);
        $ativo = !array_key_exists('valido', $l) || (int) $l['valido'] === 1;
        $this->destino->insert('usuario', [
            'login' => $login, 'email' => $email, 'nome' => $nome === '' ? $login : $nome, 'senha_hash' => $this->hashInutilizavel(),
            'administrador' => 0, 'ativo' => $ativo ? 1 : 0, 'criado_em' => gmdate('c'),
        ]);
        $novo = (int) $this->destino->lastInsertId();
        $this->ids->gravar(MapaDeIds::USUARIO, $cod, $novo);
        $this->servicos->auditoria->registrar('usuario.importar', 'cli', $novo, null, ['usuario' => $login, 'ativo' => $ativo]);
        $this->relatorio->contar('usuários importados');
        $this->relatorio->contar('contas que exigem redefinição de senha');
        if (!$ativo) {
            $this->relatorio->contar('usuários importados inativos');
        }
    }

    /** Hash que ninguém consegue satisfazer: o segredo que o gerou é descartado na hora. As contas importadas entram com a senha a redefinir. */
    private function hashInutilizavel(): string
    {
        return $this->hashInutilizavel ??= password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    }

    // ---------------------------------------------------------------- objetos

    private function importarObjetos(PlanoDeClasses $plano): void
    {
        if ($this->opcoes->raiz !== null) {
            $raizes = $this->origem->objetosPorIds([$this->opcoes->raiz]);
            if ($raizes === []) {
                throw new \InvalidArgumentException("O objeto {$this->opcoes->raiz} não existe na origem");
            }
        } else {
            $raizes = $this->origem->raizes();
            if (count($raizes) > 1) {
                $this->relatorio->avisar('mais de um objeto de topo na origem (todos importados sob o mesmo pai)', (string) count($raizes));
            }
        }
        if ($raizes === []) {
            $this->relatorio->avisar('a origem não tem nenhum objeto de topo');

            return;
        }
        $fronteira = [];
        foreach ($raizes as $r) {
            $this->raizes[] = (int) $r['cod_objeto'];
            foreach ($this->importarLote([$r], true, $plano) as $cod) {
                $fronteira[] = $cod;
            }
        }
        $vistos = array_fill_keys($fronteira, true);
        while ($fronteira !== []) {
            $proxima = [];
            foreach (array_chunk($fronteira, 500) as $pais) {
                $apos = 0;
                do {
                    $linhas = $this->origem->filhos($pais, $apos, $this->opcoes->lote);
                    if ($linhas === []) {
                        break;
                    }
                    $apos = (int) end($linhas)['cod_objeto'];
                    foreach ($this->importarLote($linhas, false, $plano) as $cod) {
                        if (!isset($vistos[$cod])) {
                            $vistos[$cod] = true;
                            $proxima[] = $cod;
                        }
                    }
                } while (count($linhas) === $this->opcoes->lote);
            }
            $fronteira = $proxima;
        }
    }

    /**
     * @param list<array<string, mixed>> $linhas
     * @return list<int> objetos do PBL3 que existem no destino depois deste lote (os filhos deles são o próximo nível)
     */
    private function importarLote(array $linhas, bool $raizes, PlanoDeClasses $plano): array
    {
        $codigos = array_map(static fn (array $l): int => (int) $l['cod_objeto'], $linhas);
        $mapeados = $this->ids->varios(MapaDeIds::OBJETO, $codigos);
        $novos = array_values(array_diff($codigos, array_keys($mapeados)));
        $valores = [];
        $blobs = [];
        $tags = [];
        $versoes = [];
        if ($novos !== []) {
            foreach (self::TIPOS_DE_VALOR as $t) {
                foreach ($this->origem->valores($t, $novos) as $v) {
                    $valores[(int) $v['cod_objeto']][] = ['tabela' => $t, 'propriedade' => (int) $v['cod_propriedade'], 'valor' => $v['valor']];
                }
            }
            foreach ($this->origem->valores('tbl_blob', $novos) as $b) {
                $blobs[(int) $b['cod_objeto']][] = $b;
            }
            $tags = $this->origem->tagsDosObjetos($novos);
            foreach ($this->origem->versoes($novos) as $v) {
                $versoes[(int) $v['cod_objeto']][] = $v;
            }
        }
        $usuarios = $this->ids->varios(MapaDeIds::USUARIO, array_values(array_filter(array_map(static fn (array $l): int => (int) ($l['cod_usuario'] ?? 0), $linhas))));
        $pais = $raizes ? [] : $this->ids->varios(MapaDeIds::OBJETO, array_values(array_unique(array_map(static fn (array $l): int => (int) ($l['cod_pai'] ?? 0), $linhas))));
        $existentes = [];
        $this->destino->transactional(function () use ($linhas, $raizes, $plano, $mapeados, $valores, $blobs, $tags, $versoes, $usuarios, $pais, &$existentes): void {
            foreach ($linhas as $l) {
                $cod = (int) $l['cod_objeto'];
                if (isset($mapeados[$cod])) {
                    $this->relatorio->contar('objetos já importados');
                    $existentes[] = $cod;
                    continue;
                }
                $pai = $raizes ? $this->paiDaRaiz() : ($pais[(int) ($l['cod_pai'] ?? 0)] ?? null);
                if (!$raizes && $pai === null) {
                    $this->relatorio->avisar('objeto não importado (o pai não foi importado)', "objeto {$cod}");
                    continue;
                }
                if ($this->opcoes->semLixeira && (int) ($l['apagado'] ?? 0) === 1) {
                    $this->relatorio->contar('objetos da lixeira ignorados (--sem-lixeira)');
                    continue;
                }
                try {
                    $novo = $this->destino->transactional(fn (): ?int => $this->criarObjeto($l, $pai, $plano, $valores[$cod] ?? [], $blobs[$cod] ?? [], $tags[$cod] ?? [], $versoes[$cod] ?? [], $usuarios));
                } catch (\Throwable $e) {
                    $this->relatorio->avisar('objeto não importado (' . $this->resumoDoErro($e) . ')', "objeto {$cod}");
                    continue;
                }
                if ($novo !== null) {
                    $existentes[] = $cod;
                }
            }
        });

        return $existentes;
    }

    private function paiDaRaiz(): ?int
    {
        return $this->opcoes->paiDestino ?? $this->servicos->sites->porId($this->siteId)?->objetoRaizId;
    }

    /**
     * @param array<string, mixed> $l
     * @param list<array{tabela: string, propriedade: int, valor: mixed}> $valores
     * @param list<array<string, mixed>> $blobs
     * @param list<string> $tags
     * @param list<array<string, mixed>> $versoes
     * @param array<int, int> $usuarios
     */
    private function criarObjeto(array $l, ?int $pai, PlanoDeClasses $plano, array $valores, array $blobs, array $tags, array $versoes, array $usuarios): ?int
    {
        $cod = (int) $l['cod_objeto'];
        $codClasse = (int) ($l['cod_classe'] ?? 0);
        if (!isset($plano->classes[$codClasse])) {
            $this->relatorio->avisar('objeto não importado (classe desconhecida)', "objeto {$cod}");

            return null;
        }
        $classe = $plano->classes[$codClasse]['classe'];
        $dados = [];
        $meta = [];
        foreach ($valores as $v) {
            $p = $v['propriedade'];
            if (isset($plano->menu[$p])) {
                $meta['no_menu'] = $this->booleano($v['valor']);
                continue;
            }
            $destino = $plano->propriedades[$p] ?? null;
            if ($destino === null) {
                $this->relatorio->contar('valores de propriedades descartadas');
                continue;
            }
            if (array_key_exists($destino['nome'], $dados)) {
                $this->relatorio->avisar('propriedade com vários valores (só o primeiro foi importado)', $classe->nome . '.' . $destino['nome']);
                continue;
            }
            $convertido = $this->converter($destino['tipo'], $v['valor']);
            if ($convertido !== null) {
                $dados[$destino['nome']] = $convertido;
            }
        }
        if ($classe->nome === 'link' && !isset($dados['destino_externo']) && !isset($dados['destino_interno'])) {
            $dados['destino_externo'] = self::DESTINO_PROVISORIO;
        }
        $titulo = mb_substr(trim($this->utf8((string) ($l['titulo'] ?? ''))), 0, 300);
        if ($titulo === '') {
            $titulo = '(sem título)';
            $this->relatorio->avisar('objeto sem título', "objeto {$cod}");
        }
        $status = (int) ($l['apagado'] ?? 0) === 1 ? 'apagado' : (Conversao::STATUS[(int) ($l['cod_status'] ?? 0)] ?? null);
        if ($status === null) {
            $status = 'privado';
            $this->relatorio->avisar('estado desconhecido (importado como privado)', "objeto {$cod}");
        }
        $meta['peso'] = (int) ($l['peso'] ?? 0);
        $descricao = mb_substr(trim($this->utf8((string) ($l['descricao'] ?? ''))), 0, 2000);
        if ($descricao !== '') {
            $meta['descricao'] = $descricao;
        }
        if (array_key_exists('incluirmenu', $l)) {
            $meta['no_menu'] = $this->booleano($l['incluirmenu']);
        }
        $meta['data_publicacao'] = Conversao::vigencia($l['data_publicacao'] ?? null, $this->fuso);
        $meta['data_validade'] = Conversao::vigencia($l['data_validade'] ?? null, $this->fuso);
        $codPele = (int) ($l['cod_pele'] ?? 0);
        if ($codPele > 0) {
            $pele = $this->opcoes->peles[$this->peles[$codPele] ?? ''] ?? null;
            if ($pele !== null) {
                $meta['pele'] = $pele;
            } else {
                $this->relatorio->avisar('pele do PBL3 sem equivalente (use --pele)', $this->peles[$codPele] ?? "pele {$codPele}");
            }
        }
        $autor = $usuarios[(int) ($l['cod_usuario'] ?? 0)] ?? null;
        if ($autor !== null) {
            $meta['criado_por'] = $autor;
        }
        $nomesDeTags = $this->tags($tags, $cod);
        if ($nomesDeTags !== []) {
            $meta['tags'] = $nomesDeTags;
        }
        $url = $this->urlLivre((string) ($l['url_amigavel'] ?? ''), $titulo, $cod, $pai === null);
        $objeto = $this->servicos->objetos->criar($this->siteId, $classe, $pai, $titulo, $url, $dados, $status, $meta, false, $this->anexos($blobs, $plano, $autor));
        $this->ids->gravar(MapaDeIds::OBJETO, $cod, $objeto->id);
        $this->relatorio->contar('objetos importados');
        $this->relatorio->contar('objetos importados: ' . $classe->nome);
        if ($pai === null) {
            $this->servicos->sites->definirRaiz($this->siteId, $objeto->id);
        }
        $this->importarVersoes($objeto, $versoes, $usuarios);

        return $objeto->id;
    }

    /**
     * Os arquivos do objeto, copiados para o armazenamento do PBL4 depois que o objeto existe (precisam do id do dono). Um arquivo
     * que falta ou é recusado vira aviso: o objeto entra mesmo assim.
     *
     * @param list<array<string, mixed>> $blobs
     */
    private function anexos(array $blobs, PlanoDeClasses $plano, ?int $autor): ?\Closure
    {
        if ($blobs === []) {
            return null;
        }

        return function (int $objetoId) use ($blobs, $plano, $autor): array {
            $extra = [];
            foreach ($blobs as $b) {
                $codBlob = (int) $b['cod_blob'];
                $p = $plano->propriedades[(int) $b['cod_propriedade']] ?? null;
                if ($p === null || !in_array($p['tipo'], ['imagem', 'arquivo'], true)) {
                    $this->relatorio->contar('arquivos de propriedades descartadas');
                    continue;
                }
                if (isset($extra[$p['nome']])) {
                    $this->relatorio->avisar('propriedade com vários arquivos (só o primeiro foi importado)', $p['nome']);
                    continue;
                }
                $nome = (string) ($b['arquivo'] ?? '');
                if ($this->opcoes->uploads === null) {
                    $this->relatorio->avisar('arquivo não importado (falta --uploads)', "blob {$codBlob}");
                    continue;
                }
                $caminho = Conversao::caminhoDoBlob($this->opcoes->uploads, $codBlob, $nome);
                if (!is_file($caminho)) {
                    $this->relatorio->avisar('arquivo do PBL3 não encontrado em disco', "blob {$codBlob}");
                    continue;
                }
                if ($this->opcoes->simular) {
                    $this->relatorio->contar('arquivos que seriam importados');
                    $extra[$p['nome']] = $this->arquivoDeSimulacao($objetoId, $p, $nome);
                    continue;
                }
                $id = $this->enviarArquivo($caminho, $nome, $p, $objetoId, $autor, $codBlob);
                if ($id !== null) {
                    $extra[$p['nome']] = $id;
                }
            }

            return $extra;
        };
    }

    /**
     * Na simulação o arquivo não é copiado, mas a validação do objeto exige uma linha de `arquivo`: cria uma, desfeita junto com
     * a transação da simulação, para que objetos com arquivo obrigatório não sejam reprovados à toa.
     *
     * @param array{nome: string, tipo: string, tipo3: string} $p
     */
    private function arquivoDeSimulacao(int $objetoId, array $p, string $nome): int
    {
        $ext = strtolower(pathinfo(str_replace('\\', '/', $nome), PATHINFO_EXTENSION));
        $this->destino->insert('arquivo', [
            'site_id' => $this->siteId, 'objeto_id' => $objetoId, 'propriedade' => $p['nome'], 'chave' => 'simulacao-' . $objetoId . '-' . $p['nome'],
            'nome' => 'simulacao', 'extensao' => $ext, 'mime' => 'application/octet-stream', 'tamanho' => 0, 'sha256' => str_repeat('0', 64),
            'largura' => null, 'altura' => null, 'sanitizada' => 0, 'criado_em' => gmdate('c'), 'criado_por' => null,
        ]);

        return (int) $this->destino->lastInsertId();
    }

    /** @param array{nome: string, tipo: string, tipo3: string} $p */
    private function enviarArquivo(string $caminho, string $nome, array $p, int $objetoId, ?int $autor, int $codBlob): ?int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pbl3');
        if ($tmp === false || !copy($caminho, $tmp)) {
            $this->relatorio->avisar('arquivo do PBL3 não pôde ser lido', "blob {$codBlob}");

            return null;
        }
        try {
            $arquivo = $this->servicos->uploads()->enviar(new UploadedFile($tmp, basename(str_replace('\\', '/', $nome)), null, null, true), ['nome' => $p['nome'], 'tipo' => $p['tipo']], $this->siteId, $objetoId, $autor);
            $this->relatorio->contar('arquivos importados');

            return $arquivo->id;
        } catch (ErroDeUpload $e) {
            $this->relatorio->avisar('arquivo recusado pelo PBL4: ' . $e->getMessage(), "blob {$codBlob}");

            return null;
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    /**
     * Guarda os instantâneos de `versaoobjeto` que têm o formato esperado (JSON com `titulo`) como versões antigas do objeto; o que
     * foi criado agora vira a última versão. Formato desconhecido só é contado.
     *
     * @param list<array<string, mixed>> $versoes
     * @param array<int, int> $usuarios
     */
    private function importarVersoes(Objeto $objeto, array $versoes, array $usuarios): void
    {
        $relatorio = $this->relatorio;
        $reconhecidas = [];
        foreach ($versoes as $v) {
            $relatorio->contar('versões lidas');
            $j = json_decode($this->utf8((string) ($v['conteudo'] ?? '')), true);
            if (is_array($j) && is_string($j['titulo'] ?? null) && trim($j['titulo']) !== '') {
                $reconhecidas[] = [$v, $j];
            } else {
                $relatorio->contar('versões em formato não reconhecido (descartadas)');
            }
        }
        $k = count($reconhecidas);
        $this->destino->update('objeto_versao', ['versao' => $k + 1, 'motivo' => 'importação do PBL3'], ['objeto_id' => $objeto->id, 'versao' => 1]);
        if ($k === 0) {
            return;
        }
        $this->destino->update('objeto', ['versao' => $k + 1], ['id' => $objeto->id]);
        foreach ($reconhecidas as $i => [$v, $j]) {
            $quando = Conversao::vigencia($v['data_criacao'] ?? null, $this->fuso) ?? gmdate('c');
            $this->destino->insert('objeto_versao', [
                'objeto_id' => $objeto->id, 'versao' => $i + 1, 'titulo' => mb_substr(trim($j['titulo']), 0, 300), 'url_amigavel' => $objeto->urlAmigavel,
                'status' => $objeto->status, 'dados' => '{}', 'peso' => $objeto->peso, 'descricao' => $objeto->descricao === '' ? null : $objeto->descricao,
                'data_publicacao' => $objeto->dataPublicacao, 'data_validade' => $objeto->dataValidade, 'pai_id' => $objeto->paiId,
                'motivo' => 'versão do PBL3 (só o título foi preservado)', 'usuario_id' => $usuarios[(int) ($v['cod_usuario'] ?? 0)] ?? null, 'criado_em' => $quando,
            ]);
            $relatorio->contar('versões importadas');
        }
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    private function tags(array $tags, int $cod): array
    {
        $limpas = [];
        foreach ($tags as $t) {
            $t = mb_substr(trim($this->utf8($t)), 0, TagRepositorio::MAX_NOME);
            if ($t !== '' && !in_array(mb_strtolower($t), array_map('mb_strtolower', $limpas), true)) {
                $limpas[] = $t;
            }
        }
        if (count($limpas) > TagRepositorio::MAX_POR_OBJETO) {
            $this->relatorio->avisar('objeto com mais tags do que o PBL4 aceita (as excedentes foram descartadas)', "objeto {$cod}");
            $limpas = array_slice($limpas, 0, TagRepositorio::MAX_POR_OBJETO);
        }

        return $limpas;
    }

    /** Endereço do objeto: o do PBL3 normalizado, sem repetir nenhum que o site já use (nem que tenha sido de outro objeto). */
    private function urlLivre(string $url, string $titulo, int $cod, bool $topoDoSite): string
    {
        $base = Conversao::endereco($this->utf8($url), $titulo);
        if ($base === '' && !$topoDoSite) {
            $base = 'objeto-' . $cod;
        }
        $candidata = $base;
        for ($i = 2; $this->urlOcupada($candidata); ++$i) {
            $candidata = ($base === '' ? 'objeto-' . $cod : $base) . '-' . $i;
            $this->relatorio->contar('endereços repetidos renomeados');
        }

        return $candidata;
    }

    private function urlOcupada(string $url): bool
    {
        return $this->destino->fetchOne('SELECT 1 FROM objeto WHERE site_id = ? AND url_amigavel = ?', [$this->siteId, $url]) !== false
            || $this->destino->fetchOne('SELECT 1 FROM url_historico WHERE site_id = ? AND url_amigavel = ?', [$this->siteId, $url]) !== false;
    }

    private function converter(string $tipo, mixed $valor): string|int|float|null
    {
        switch ($tipo) {
            case 'numero':
                if (!is_numeric($valor)) {
                    return null;
                }

                return str_contains((string) $valor, '.') || is_float($valor) ? (float) $valor : (int) $valor;
            case 'booleano':
                return $this->booleano($valor) ? 1 : 0;
            case 'data':
                return Conversao::dataDeCampo($valor);
            case 'ref':
            case 'imagem':
            case 'arquivo':
                return null;
            default:
                $t = $this->utf8((string) $valor);

                return $t === '' ? null : $t;
        }
    }

    private function booleano(mixed $valor): bool
    {
        return in_array(is_string($valor) ? strtolower(trim($valor)) : $valor, [1, true, '1', 't', 'true', 'y', 's', 'sim'], true);
    }

    /** O PBL3 em MySQL é muitas vezes Latin-1: o que não é UTF-8 válido é lido como Latin-1. */
    private function utf8(string $texto): string
    {
        if (mb_check_encoding($texto, 'UTF-8')) {
            return $texto;
        }
        $this->relatorio->contar('textos convertidos de Latin-1');

        return mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1');
    }

    private function resumoDoErro(\Throwable $e): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? ''), 0, 120);
    }

    // ---------------------------------------------------------------- referências

    /**
     * Segunda passada: as referências entre objetos (`tbl_objref`) só se resolvem depois de importar tudo, porque o alvo pode vir
     * depois da origem. Percorre o mapa de ids (por isso também funciona numa retomada) e troca o id do PBL3 pelo do PBL4.
     */
    private function resolverReferencias(PlanoDeClasses $plano): void
    {
        if (!$this->origem->existe('tbl_objref')) {
            return;
        }
        $apos = 0;
        do {
            $pagina = $this->ids->pagina(MapaDeIds::OBJETO, $apos, $this->opcoes->lote);
            if ($pagina === []) {
                break;
            }
            $apos = end($pagina)['id'];
            $destinoDe = array_column($pagina, 'destino', 'origem');
            $linhas = $this->origem->valores('tbl_objref', array_keys($destinoDe));
            foreach ($this->origem->valores('tbl_integer', array_keys($destinoDe)) as $r) {
                if (($plano->propriedades[(int) $r['cod_propriedade']]['tipo'] ?? null) === 'ref') {
                    $linhas[] = $r;
                }
            }
            $alvos = $this->ids->varios(MapaDeIds::OBJETO, array_values(array_filter(array_map(static fn (array $r): int => (int) $r['valor'], $linhas))));
            $this->destino->transactional(function () use ($linhas, $destinoDe, $alvos, $plano): void {
                $feitas = [];
                foreach ($linhas as $r) {
                    $propriedade = $plano->propriedades[(int) $r['cod_propriedade']] ?? null;
                    $dono = $destinoDe[(int) $r['cod_objeto']] ?? null;
                    if ($propriedade === null || $propriedade['tipo'] !== 'ref' || $dono === null) {
                        $this->relatorio->contar('referências em propriedades descartadas');
                        continue;
                    }
                    $chave = $dono . ':' . $propriedade['nome'];
                    if (isset($feitas[$chave])) {
                        continue;
                    }
                    $alvo = $alvos[(int) $r['valor']] ?? null;
                    if ($alvo === null) {
                        $this->relatorio->avisar('referência para objeto que não foi importado (descartada)', 'objeto ' . (int) $r['cod_objeto']);
                        continue;
                    }
                    $feitas[$chave] = true;
                    $this->gravarReferencia($dono, $propriedade['nome'], $alvo);
                    $this->relatorio->contar('referências resolvidas');
                }
            });
        } while (count($pagina) === $this->opcoes->lote);
    }

    private function gravarReferencia(int $objetoId, string $nome, int $alvo): void
    {
        $linha = $this->destino->fetchAssociative('SELECT dados, versao FROM objeto WHERE id = ?', [$objetoId]);
        if ($linha === false) {
            return;
        }
        $dados = json_decode((string) $linha['dados'], true);
        $dados = is_array($dados) ? $dados : [];
        $dados[$nome] = $alvo;
        $provisorio = $nome === 'destino_interno' && ($dados['destino_externo'] ?? null) === self::DESTINO_PROVISORIO;
        if ($provisorio) {
            unset($dados['destino_externo']);
            $this->destino->delete('objeto_indice', ['objeto_id' => $objetoId, 'chave' => 'destino_externo']);
        }
        $this->destino->update('objeto', ['dados' => json_encode($dados, JSON_THROW_ON_ERROR)], ['id' => $objetoId]);
        $this->destino->delete('objeto_indice', ['objeto_id' => $objetoId, 'chave' => $nome]);
        $this->destino->insert('objeto_indice', ['objeto_id' => $objetoId, 'chave' => $nome, 'texto' => null, 'texto_norm' => null, 'numero' => (float) $alvo]);
        $versao = $this->destino->fetchAssociative('SELECT id, dados FROM objeto_versao WHERE objeto_id = ? AND versao = ?', [$objetoId, $linha['versao']]);
        if ($versao !== false) {
            $d = json_decode((string) $versao['dados'], true);
            $d = is_array($d) ? $d : [];
            $d[$nome] = $alvo;
            if ($provisorio) {
                unset($d['destino_externo']);
            }
            $this->destino->update('objeto_versao', ['dados' => json_encode($d, JSON_THROW_ON_ERROR)], ['id' => $versao['id']]);
        }
    }

    // ---------------------------------------------------------------- acessos

    /** Perfis do PBL3 viram perfis por subárvore; o Administrador na raiz importada vira administrador global. */
    private function conceder(): void
    {
        if (!$this->origem->existe('usuarioxobjetoxperfil')) {
            return;
        }
        $perfis = array_map(static fn (string $n): string => Texto::normalizar($n), $this->origem->perfis());
        $apos = 0;
        do {
            $pagina = $this->ids->pagina(MapaDeIds::USUARIO, $apos, $this->opcoes->lote);
            if ($pagina === []) {
                break;
            }
            $apos = end($pagina)['id'];
            $usuarioDe = array_column($pagina, 'destino', 'origem');
            $acessos = $this->origem->acessosDe(array_keys($usuarioDe));
            $objetos = $this->ids->varios(MapaDeIds::OBJETO, array_map(static fn (array $a): int => (int) $a['cod_objeto'], $acessos));
            $this->destino->transactional(function () use ($acessos, $usuarioDe, $objetos, $perfis): void {
                $fortes = [];
                foreach ($acessos as $a) {
                    $nome = $perfis[(int) $a['cod_perfil']] ?? '';
                    $cod = (int) $a['cod_objeto'];
                    $usuario = $usuarioDe[(int) $a['cod_usuario']] ?? null;
                    $objeto = $objetos[$cod] ?? null;
                    if ($usuario === null) {
                        continue;
                    }
                    if ($objeto === null) {
                        $this->relatorio->avisar('acesso a objeto fora do que foi importado (descartado)', "objeto {$cod}");
                        continue;
                    }
                    $perfil = match ($nome) {
                        'administrador' => in_array($cod, $this->raizes, true) ? 'admin' : Perfil::Editor,
                        'editor' => Perfil::Editor,
                        'autor' => Perfil::Autor,
                        'restrito' => Perfil::Leitor,
                        default => null,
                    };
                    if ($perfil === null) {
                        $this->relatorio->avisar('perfil do PBL3 sem equivalente (acesso descartado)', $nome === '' ? 'sem nome' : $nome);
                        continue;
                    }
                    $anterior = $fortes[$usuario][$objeto] ?? null;
                    if ($anterior === null || $this->forca($perfil) > $this->forca($anterior)) {
                        $fortes[$usuario][$objeto] = $perfil;
                    }
                }
                foreach ($fortes as $usuario => $porObjeto) {
                    foreach ($porObjeto as $objeto => $perfil) {
                        if ($perfil === 'admin') {
                            $this->destino->update('usuario', ['administrador' => 1], ['id' => $usuario]);
                            $this->relatorio->contar('administradores globais');
                            continue;
                        }
                        $this->servicos->usuarios->conceder($usuario, $objeto, $perfil, self::ATOR);
                        $this->relatorio->contar('acessos concedidos por subárvore');
                    }
                }
            });
        } while (count($pagina) === $this->opcoes->lote);
    }

    private function forca(Perfil|string $perfil): int
    {
        return $perfil instanceof Perfil ? $perfil->forca() : 99;
    }
}
