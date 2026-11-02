<?php

declare(strict_types=1);

namespace Pbl\Banco\Migracoes;

use Doctrine\DBAL\Connection;
use Pbl\Banco\Migracao;

/**
 * Formulários públicos declarativos (fase 6a, ADR 0013): cria o tipo de conteúdo `formulario`, cuja definição (seção de destino,
 * tipo do envio, campos expostos, mensagem) é só dado. **Não muda o esquema**: acrescenta uma linha em `classe`, então a versão
 * anterior do código a trata como qualquer outro tipo (ADR 0006). Se a instalação já tem um tipo com esse nome, nada é tocado.
 */
final class M011ClasseFormulario implements Migracao
{
    public function versao(): int
    {
        return 11;
    }

    public function aplicar(Connection $conexao): void
    {
        if ($conexao->fetchOne('SELECT id FROM classe WHERE nome = ?', ['formulario']) !== false) {
            return;
        }
        $propriedades = [
            ['nome' => 'corpo', 'tipo' => 'html', 'indexavel' => false, 'rotulo' => 'Texto antes do formulário'],
            ['nome' => 'destino', 'tipo' => 'ref', 'indexavel' => true, 'rotulo' => 'Seção que recebe os envios', 'obrigatorio' => true],
            ['nome' => 'classe_envio', 'tipo' => 'texto', 'indexavel' => false, 'rotulo' => 'Tipo de conteúdo de cada envio', 'obrigatorio' => true],
            ['nome' => 'campos', 'tipo' => 'texto', 'indexavel' => false, 'rotulo' => 'Campos do formulário (nomes separados por vírgula)', 'obrigatorio' => true],
            ['nome' => 'mensagem', 'tipo' => 'texto', 'indexavel' => false, 'rotulo' => 'Mensagem depois de enviar'],
            ['nome' => 'botao', 'tipo' => 'texto', 'indexavel' => false, 'rotulo' => 'Texto do botão'],
            ['nome' => 'fechado', 'tipo' => 'booleano', 'indexavel' => false, 'rotulo' => 'Fechado (não recebe envios)'],
        ];
        $conexao->insert('classe', [
            'nome' => 'formulario',
            'propriedades' => json_encode($propriedades, JSON_THROW_ON_ERROR),
            'descricao' => 'Formulário público: cada envio vira um conteúdo privado do tipo indicado, dentro da seção de destino.',
            'aceita_filhos' => 0,
            'filhas' => null,
            'versao' => 1,
        ]);
    }
}
