<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

/**
 * Pacote mínimo de tipos de conteúdo que o instalador cria (fase 5l, ADR 0015).
 *
 * Seis são **fixos** (`pasta`, `pagina`, `formulario`, `arquivo`, `imagem`, `link`): o sistema conta com eles, então não se
 * apagam, não se renomeiam e não perdem as propriedades da base (`nomesDaBase`). Só `noticia` e `mensagem_contato` (o envio do
 * formulário de exemplo) são pontos de partida que se apagam como qualquer tipo. Nomes de campo seguem a regra de sempre: `titulo`, `descricao` e `url` são campos do sistema
 * (reservados), por isso "descrição" de pasta/arquivo/link é o campo `descricao` do próprio conteúdo e o endereço externo de
 * `link` se chama `destino_externo`, não `url`.
 *
 * `formulario` vem da migração 11 (e é marcada fixa pela 16): `garantir` não a recria.
 */
final class ClassesPadrao
{
    public const FIXAS = ['pasta', 'pagina', 'formulario', 'arquivo', 'imagem', 'link'];

    /** Propriedades de base das classes fixas: nunca removidas nem arquivadas. `pasta` só usa campos do sistema. */
    private const BASE = [
        'pasta' => [],
        'pagina' => ['resumo', 'corpo'],
        'formulario' => ['corpo', 'destino', 'classe_envio', 'campos', 'mensagem', 'botao', 'fechado'],
        'arquivo' => ['arquivo'],
        'imagem' => ['imagem', 'legenda', 'texto_alternativo'],
        'link' => ['destino_interno', 'destino_externo', 'nova_aba'],
    ];

    /** @return list<string> */
    public static function nomesDaBase(string $classe): array
    {
        return self::BASE[$classe] ?? [];
    }

    /** @return list<array{nome: string, tipo: string, indexavel: bool, rotulo: string}> */
    public static function camposDeSeo(): array
    {
        return [
            ['nome' => 'seo_titulo', 'tipo' => 'texto', 'indexavel' => false, 'rotulo' => 'Título para buscadores (opcional)'],
            ['nome' => 'seo_descricao', 'tipo' => 'texto', 'indexavel' => false, 'rotulo' => 'Descrição para buscadores (opcional)'],
            ['nome' => 'seo_imagem', 'tipo' => 'imagem', 'indexavel' => false, 'rotulo' => 'Imagem de compartilhamento (opcional)'],
            ['nome' => 'seo_nao_indexar', 'tipo' => 'booleano', 'indexavel' => false, 'rotulo' => 'Pedir aos buscadores que não indexem esta página'],
        ];
    }

    /**
     * As classes do pacote, na ordem de criação (uma classe só aponta para as que vêm antes dela).
     *
     * @return list<array{nome: string, propriedades: list<array{nome: string, tipo: string, indexavel: bool, rotulo: string, obrigatorio?: bool}>, meta: array{descricao: string, aceita_filhos: bool, filhas: list<string>, fixa: bool}}>
     */
    public static function definicoes(): array
    {
        $seo = self::camposDeSeo();

        return [
            [
                'nome' => 'pasta',
                'propriedades' => $seo,
                'meta' => ['descricao' => 'Agrupa outros conteúdos (seções, listas, caixas de entrada). Aceita qualquer tipo de filho.', 'aceita_filhos' => true, 'filhas' => [], 'fixa' => true],
            ],
            [
                'nome' => 'pagina',
                'propriedades' => [
                    ['nome' => 'resumo', 'tipo' => 'texto', 'indexavel' => true, 'rotulo' => 'Resumo'],
                    ['nome' => 'corpo', 'tipo' => 'html', 'indexavel' => false, 'rotulo' => 'Texto'],
                    ...$seo,
                ],
                'meta' => ['descricao' => 'Página de texto. Também serve de página inicial do site.', 'aceita_filhos' => true, 'filhas' => [], 'fixa' => true],
            ],
            [
                'nome' => 'arquivo',
                'propriedades' => [
                    ['nome' => 'arquivo', 'tipo' => 'arquivo', 'indexavel' => false, 'rotulo' => 'Arquivo', 'obrigatorio' => true],
                ],
                'meta' => ['descricao' => 'Arquivo para baixar (PDF, planilha, documento). A descrição é o campo descrição do conteúdo.', 'aceita_filhos' => false, 'filhas' => [], 'fixa' => true],
            ],
            [
                'nome' => 'imagem',
                'propriedades' => [
                    ['nome' => 'imagem', 'tipo' => 'imagem', 'indexavel' => false, 'rotulo' => 'Imagem', 'obrigatorio' => true],
                    ['nome' => 'legenda', 'tipo' => 'texto', 'indexavel' => false, 'rotulo' => 'Legenda'],
                    ['nome' => 'texto_alternativo', 'tipo' => 'texto', 'indexavel' => false, 'rotulo' => 'Texto alternativo (para leitores de tela)'],
                ],
                'meta' => ['descricao' => 'Imagem avulsa, para galerias e para ilustrar outros conteúdos.', 'aceita_filhos' => false, 'filhas' => [], 'fixa' => true],
            ],
            [
                'nome' => 'link',
                'propriedades' => [
                    ['nome' => 'destino_interno', 'tipo' => 'ref', 'indexavel' => true, 'rotulo' => 'Conteúdo deste site (destino interno)'],
                    ['nome' => 'destino_externo', 'tipo' => 'texto', 'indexavel' => false, 'rotulo' => 'Endereço externo (http, https, mailto ou tel)'],
                    ['nome' => 'nova_aba', 'tipo' => 'booleano', 'indexavel' => false, 'rotulo' => 'Abrir em nova aba'],
                ],
                'meta' => ['descricao' => 'Atalho para um conteúdo do site (a URL acompanha o conteúdo) ou para um endereço externo: preencha só um dos dois destinos. A descrição é o campo descrição do conteúdo.', 'aceita_filhos' => false, 'filhas' => [], 'fixa' => true],
            ],
            [
                'nome' => 'noticia',
                'propriedades' => [
                    ['nome' => 'resumo', 'tipo' => 'texto', 'indexavel' => true, 'rotulo' => 'Resumo'],
                    ['nome' => 'corpo', 'tipo' => 'html', 'indexavel' => false, 'rotulo' => 'Texto'],
                    ['nome' => 'imagem_destaque', 'tipo' => 'imagem', 'indexavel' => false, 'rotulo' => 'Imagem de destaque'],
                    ...$seo,
                ],
                'meta' => ['descricao' => 'Notícia ou artigo. Aceita imagens, arquivos e links como filhos.', 'aceita_filhos' => true, 'filhas' => ['arquivo', 'imagem', 'link'], 'fixa' => false],
            ],
            [
                'nome' => 'mensagem_contato',
                'propriedades' => [
                    ['nome' => 'nome', 'tipo' => 'texto', 'indexavel' => false, 'rotulo' => 'Nome', 'obrigatorio' => true],
                    ['nome' => 'email', 'tipo' => 'texto', 'indexavel' => false, 'rotulo' => 'E-mail', 'obrigatorio' => true],
                    ['nome' => 'mensagem', 'tipo' => 'html', 'indexavel' => false, 'rotulo' => 'Mensagem', 'obrigatorio' => true],
                ],
                'meta' => ['descricao' => 'Mensagem enviada pelo formulário de contato do site de exemplo.', 'aceita_filhos' => false, 'filhas' => [], 'fixa' => false],
            ],
        ];
    }

    /**
     * Cria as classes do pacote que ainda não existem (as que já existem não são tocadas). `formulario` não é criada aqui: vem
     * da migração 11.
     *
     * @return list<string> nomes das classes criadas
     */
    public static function garantir(ClasseRepositorio $classes): array
    {
        $criadas = [];
        foreach (self::definicoes() as $d) {
            if ($classes->porNome($d['nome']) !== null) {
                continue;
            }
            $classes->criar($d['nome'], $d['propriedades'], $d['meta']);
            $criadas[] = $d['nome'];
        }

        return $criadas;
    }
}
