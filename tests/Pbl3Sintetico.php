<?php

declare(strict_types=1);

namespace Pbl\Testes;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Base do PBL3 inteiramente inventada, em SQLite, no esquema do PBL3 (EAV). Nenhum dado real: só o formato das tabelas.
 * `$nomes` troca nomes físicos (`['objeto' => 'obj']`) para provar o `--mapa-tabelas`.
 */
final class Pbl3Sintetico
{
    public readonly Connection $c;

    /** @param array<string, string> $nomes tabela lógica => nome físico */
    public function __construct(private readonly array $nomes = [], private readonly string $colunaBlobDoObjeto = 'cod_objeto')
    {
        $this->c = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $t = fn (string $l): string => $this->nomes[$l] ?? $l;
        $this->c->executeStatement("CREATE TABLE {$t('classe')} (cod_classe INTEGER PRIMARY KEY, nome TEXT, prefixo TEXT, descricao TEXT, temfilhos INTEGER)");
        $this->c->executeStatement("CREATE TABLE {$t('propriedade')} (cod_propriedade INTEGER PRIMARY KEY, cod_classe INTEGER, cod_tipodado INTEGER, cod_referencia_classe INTEGER, campo_ref TEXT, nome TEXT, posicao INTEGER, descricao TEXT, rotulo TEXT, obrigatorio INTEGER)");
        $this->c->executeStatement("CREATE TABLE {$t('objeto')} (cod_objeto INTEGER PRIMARY KEY, cod_pai INTEGER, cod_classe INTEGER, cod_usuario INTEGER, cod_pele INTEGER, cod_status INTEGER, titulo TEXT, descricao TEXT, data_publicacao INTEGER, data_validade INTEGER, apagado INTEGER DEFAULT 0, objetosistema INTEGER DEFAULT 0, peso INTEGER DEFAULT 0, url_amigavel TEXT, versao INTEGER DEFAULT 1, incluirmenu INTEGER DEFAULT 0)");
        foreach (['tbl_string', 'tbl_text', 'tbl_integer', 'tbl_float', 'tbl_date', 'tbl_boolean'] as $v) {
            $this->c->executeStatement("CREATE TABLE {$t($v)} (cod_objeto INTEGER, cod_propriedade INTEGER, valor TEXT)");
        }
        $this->c->executeStatement("CREATE TABLE {$t('tbl_objref')} (cod_objref INTEGER PRIMARY KEY, cod_objeto INTEGER, cod_propriedade INTEGER, valor INTEGER)");
        $this->c->executeStatement("CREATE TABLE {$t('tbl_blob')} (cod_blob INTEGER PRIMARY KEY, {$this->colunaBlobDoObjeto} INTEGER, cod_propriedade INTEGER, arquivo TEXT)");
        $this->c->executeStatement("CREATE TABLE {$t('versaoobjeto')} (cod_versaoobjeto INTEGER PRIMARY KEY, cod_objeto INTEGER, versao INTEGER, conteudo TEXT, data_criacao INTEGER, cod_usuario INTEGER)");
        $this->c->executeStatement("CREATE TABLE {$t('usuario')} (cod_usuario INTEGER PRIMARY KEY, nome TEXT, login TEXT, email TEXT, senha TEXT, valido INTEGER)");
        $this->c->executeStatement("CREATE TABLE {$t('perfil')} (cod_perfil INTEGER PRIMARY KEY, nome TEXT)");
        $this->c->executeStatement("CREATE TABLE {$t('usuarioxobjetoxperfil')} (cod_usuario INTEGER, cod_objeto INTEGER, cod_perfil INTEGER)");
        $this->c->executeStatement("CREATE TABLE {$t('pele')} (cod_pele INTEGER PRIMARY KEY, nome TEXT, prefixo TEXT, publica INTEGER)");
        $this->c->executeStatement("CREATE TABLE {$t('tag')} (cod_tag INTEGER PRIMARY KEY, nome_tag TEXT)");
        $this->c->executeStatement("CREATE TABLE {$t('tagxobjeto')} (cod_tagxobjeto INTEGER PRIMARY KEY, cod_tag INTEGER, cod_objeto INTEGER)");
    }

    private function t(string $logica): string
    {
        return $this->nomes[$logica] ?? $logica;
    }

    /** @param array<string, mixed> $linha */
    public function inserir(string $tabela, array $linha): void
    {
        $this->c->insert($this->t($tabela), $linha);
    }

    /**
     * O site inventado: pasta raiz, uma página, um produto (classe própria com todos os tipos de dado), imagem de produto,
     * lixeira, versões, tags, usuários e perfis. Devolve o número da pasta raiz (1).
     */
    public function popular(): void
    {
        $this->inserir('classe', ['cod_classe' => 1, 'nome' => 'Pasta', 'prefixo' => 'folder', 'descricao' => '', 'temfilhos' => 1]);
        $this->inserir('classe', ['cod_classe' => 2, 'nome' => 'Documento', 'prefixo' => 'document', 'descricao' => '', 'temfilhos' => 1]);
        $this->inserir('classe', ['cod_classe' => 3, 'nome' => 'Produto da loja', 'prefixo' => 'produto', 'descricao' => 'Item à venda', 'temfilhos' => 0]);
        $this->inserir('classe', ['cod_classe' => 4, 'nome' => 'Interlink', 'prefixo' => 'interlink', 'descricao' => '', 'temfilhos' => 0]);
        $this->inserir('propriedade', ['cod_propriedade' => 13, 'cod_classe' => 4, 'cod_tipodado' => 4, 'nome' => 'endereco', 'posicao' => 1, 'rotulo' => 'Objeto']);
        $this->inserir('propriedade', ['cod_propriedade' => 10, 'cod_classe' => 2, 'cod_tipodado' => 8, 'nome' => 'texto', 'posicao' => 1, 'rotulo' => 'Texto']);
        $this->inserir('propriedade', ['cod_propriedade' => 11, 'cod_classe' => 2, 'cod_tipodado' => 7, 'nome' => 'resumo', 'posicao' => 2, 'rotulo' => 'Resumo']);
        $this->inserir('propriedade', ['cod_propriedade' => 12, 'cod_classe' => 2, 'cod_tipodado' => 7, 'nome' => 'palavras', 'posicao' => 3, 'rotulo' => 'Palavras']);
        $tipos = ['nome' => 7, 'preco' => 5, 'estoque' => 4, 'lancamento' => 3, 'ativo' => 2, 'ficha' => 8, 'foto' => 1, 'manual' => 1, 'similar' => 6, 'descricao' => 7];
        $cod = 20;
        foreach ($tipos as $nome => $tipo) {
            $this->inserir('propriedade', ['cod_propriedade' => $cod, 'cod_classe' => 3, 'cod_tipodado' => $tipo, 'nome' => $nome, 'posicao' => $cod - 19, 'rotulo' => ucfirst($nome), 'obrigatorio' => $nome === 'nome' ? 1 : 0]);
            ++$cod;
        }
        $this->inserir('pele', ['cod_pele' => 1, 'nome' => 'Pele inventada', 'prefixo' => 'inventada', 'publica' => 1]);

        $this->objeto(1, 0, 1, 'Raiz inventada', 'raiz', ['status' => 2]);
        $this->objeto(2, 1, 2, 'Página um', 'pagina-um', ['status' => 2, 'peso' => 5, 'incluirmenu' => 1, 'pele' => 1, 'publicacao' => 20240131120000, 'validade' => 99999999999999]);
        $this->objeto(3, 1, 3, 'Caneca', 'loja/caneca', ['status' => 2, 'usuario' => 2]);
        $this->objeto(4, 2, 2, 'Subpágina', 'pagina-um', ['status' => 4]);
        $this->objeto(5, 1, 2, 'Rascunho antigo', 'rascunho', ['status' => 1, 'apagado' => 1]);
        $this->objeto(7, 1, 4, 'Atalho', 'atalho', ['status' => 2]);
        $this->inserir('tbl_integer', ['cod_objeto' => 7, 'cod_propriedade' => 13, 'valor' => '3']);
        $this->objeto(6, 99, 2, 'Órfão', 'orfao', ['status' => 2]);

        $this->inserir('tbl_text', ['cod_objeto' => 2, 'cod_propriedade' => 10, 'valor' => '<p>Olá <script>alert(1)</script>mundo</p>']);
        $this->inserir('tbl_string', ['cod_objeto' => 2, 'cod_propriedade' => 11, 'valor' => 'Um resumo']);
        $this->inserir('tbl_string', ['cod_objeto' => 2, 'cod_propriedade' => 12, 'valor' => 'solta']);
        $this->inserir('tbl_string', ['cod_objeto' => 3, 'cod_propriedade' => 20, 'valor' => 'Caneca azul']);
        $this->inserir('tbl_float', ['cod_objeto' => 3, 'cod_propriedade' => 21, 'valor' => '19.9']);
        $this->inserir('tbl_integer', ['cod_objeto' => 3, 'cod_propriedade' => 22, 'valor' => '7']);
        $this->inserir('tbl_date', ['cod_objeto' => 3, 'cod_propriedade' => 23, 'valor' => '20240229000000']);
        $this->inserir('tbl_boolean', ['cod_objeto' => 3, 'cod_propriedade' => 24, 'valor' => '1']);
        $this->inserir('tbl_text', ['cod_objeto' => 3, 'cod_propriedade' => 25, 'valor' => '<p>Ficha técnica</p>']);
        $this->inserir('tbl_string', ['cod_objeto' => 3, 'cod_propriedade' => 29, 'valor' => 'descrição própria']);
        $this->inserir('tbl_blob', ['cod_blob' => 1500, $this->colunaBlobDoObjeto => 3, 'cod_propriedade' => 26, 'arquivo' => 'caneca.png']);
        $this->inserir('tbl_blob', ['cod_blob' => 7, $this->colunaBlobDoObjeto => 3, 'cod_propriedade' => 27, 'arquivo' => 'Manual da Caneca.pdf']);
        $this->inserir('tbl_objref', ['cod_objref' => 1, 'cod_objeto' => 3, 'cod_propriedade' => 28, 'valor' => 2]);
        $this->inserir('tbl_objref', ['cod_objref' => 2, 'cod_objeto' => 2, 'cod_propriedade' => 28, 'valor' => 3]);

        $this->inserir('versaoobjeto', ['cod_versaoobjeto' => 1, 'cod_objeto' => 2, 'versao' => 1, 'conteudo' => '{"titulo":"Página zero"}', 'data_criacao' => 20240101080000, 'cod_usuario' => 2]);
        $this->inserir('versaoobjeto', ['cod_versaoobjeto' => 2, 'cod_objeto' => 2, 'versao' => 2, 'conteudo' => 'não é json', 'data_criacao' => 20240102080000, 'cod_usuario' => 2]);

        $this->inserir('tag', ['cod_tag' => 1, 'nome_tag' => 'verão']);
        $this->inserir('tag', ['cod_tag' => 2, 'nome_tag' => 'Oferta']);
        $this->inserir('tagxobjeto', ['cod_tagxobjeto' => 1, 'cod_tag' => 1, 'cod_objeto' => 3]);
        $this->inserir('tagxobjeto', ['cod_tagxobjeto' => 2, 'cod_tag' => 2, 'cod_objeto' => 3]);

        foreach ([1 => 'Administrador', 2 => 'Editor', 3 => 'Autor', 4 => 'Restrito', 5 => 'Militarizado'] as $id => $nome) {
            $this->inserir('perfil', ['cod_perfil' => $id, 'nome' => $nome]);
        }
        $this->inserir('usuario', ['cod_usuario' => 1, 'nome' => 'Ana Inventada', 'login' => 'ana', 'email' => 'ana@exemplo.invalid', 'senha' => md5('x'), 'valido' => 1]);
        $this->inserir('usuario', ['cod_usuario' => 2, 'nome' => 'Beto Inventado', 'login' => 'Beto Silva!', 'email' => 'sem-arroba', 'senha' => md5('y'), 'valido' => 1]);
        $this->inserir('usuario', ['cod_usuario' => 3, 'nome' => 'Cris Inventada', 'login' => 'cris', 'email' => 'cris@exemplo.invalid', 'senha' => md5('z'), 'valido' => 0]);
        $this->inserir('usuarioxobjetoxperfil', ['cod_usuario' => 1, 'cod_objeto' => 1, 'cod_perfil' => 1]);
        $this->inserir('usuarioxobjetoxperfil', ['cod_usuario' => 2, 'cod_objeto' => 2, 'cod_perfil' => 3]);
        $this->inserir('usuarioxobjetoxperfil', ['cod_usuario' => 2, 'cod_objeto' => 2, 'cod_perfil' => 4]);
        $this->inserir('usuarioxobjetoxperfil', ['cod_usuario' => 3, 'cod_objeto' => 3, 'cod_perfil' => 5]);
    }

    /** @param array{status?: int, apagado?: int, peso?: int, incluirmenu?: int, pele?: int, publicacao?: int, validade?: int, usuario?: int} $o */
    public function objeto(int $cod, int $pai, int $classe, string $titulo, string $url, array $o = []): void
    {
        $this->inserir('objeto', [
            'cod_objeto' => $cod, 'cod_pai' => $pai, 'cod_classe' => $classe, 'cod_usuario' => $o['usuario'] ?? 1, 'cod_pele' => $o['pele'] ?? 0,
            'cod_status' => $o['status'] ?? 2, 'titulo' => $titulo, 'descricao' => '', 'data_publicacao' => $o['publicacao'] ?? 0,
            'data_validade' => $o['validade'] ?? 0, 'apagado' => $o['apagado'] ?? 0, 'peso' => $o['peso'] ?? 0, 'url_amigavel' => $url,
            'incluirmenu' => $o['incluirmenu'] ?? 0,
        ]);
    }
}
