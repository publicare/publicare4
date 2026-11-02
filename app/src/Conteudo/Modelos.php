<?php

declare(strict_types=1);

namespace Pbl\Conteudo;

use Doctrine\DBAL\Connection;
use Pbl\Caminhos;

/**
 * Modelos opcionais de conteúdo (ADR 0021): `evento`, `faq`, `destaque` e `bloco`. O instalador oferece e a administração
 * (ou `bin/pbl modelos ativar`) liga depois; isso também traz as classes novas a instalações antigas.
 *
 * Cada modelo é um pacote: as **classes** (o contêiner da lista e o item), **conteúdo de exemplo** (só quando as classes
 * nascem agora) e os **moldes** extras da pele básica (`recursos/peles/basica`). Ativar é **idempotente** e nunca sobrescreve
 * o que a administração ajustou: classe que já existe não é tocada, conteúdo cujo endereço já existe não é recriado e molde
 * personalizado não é substituído (vira um aviso). O banco muda numa transação só; os arquivos de molde, depois dela.
 *
 * @phpstan-type Definicao array{nome: string, propriedades: list<array{nome: string, tipo: string, indexavel: bool, rotulo: string, obrigatorio?: bool}>, meta: array{descricao: string, aceita_filhos: bool, filhas: list<string>, fixa: bool}}
 * @phpstan-type Resultado array{classes: list<string>, exemplos: int, moldes: list<string>, avisos: list<string>}
 */
final class Modelos
{
    public const NOMES = ['evento', 'faq', 'destaque', 'bloco'];

    /** Moldes novos da pele básica que os modelos usam (copiados se faltarem). */
    private const MOLDES_NOVOS = ['agenda', 'bloco', 'bloco_aviso', 'bloco_rodape', 'destaque', 'evento', 'faixa_destaques', 'faq', 'perguntas'];

    /**
     * Moldes antigos da pele básica que ganharam ganchos para os modelos (aviso, faixa de destaques, rodapé, data do evento,
     * CSS). Só são trocados se estiverem **idênticos** a uma versão que o PBL4 já distribuiu (sha-256); molde alterado pela
     * administração fica como está e a ativação avisa o que acrescentar.
     */
    private const MOLDES_ATUALIZADOS = [
        'topo' => ['6fcc24bd07d864b635e8f28f831b0ce5614e56fd1cccc032eb6e5285ff1f1f3d'],
        'padrao' => ['faf850fdb9b204560607433b95b0b0225ebaa22a66a05618912a30f8de8208bc'],
        'item' => ['becc8791873fd7e2ef231d49fa72a91452e22c7a8cfa2ada5307caac5144a4e4'],
        'estilo' => ['b22d076390043f85b8cf1e53a7aced98b5324de833ea34b2b5a9498d890a9310'],
    ];

    private int $criados = 0;

    public function __construct(private readonly Connection $conexao, private readonly Caminhos $caminhos)
    {
    }

    /** @return array<string, array{nome: string, titulo: string, descricao: string, classes: list<string>, moldes: list<string>}> */
    public static function catalogo(): array
    {
        return [
            'evento' => [
                'nome' => 'evento', 'titulo' => 'Agenda de eventos',
                'descricao' => 'Eventos com data e hora de início e fim, local, imagem e link de inscrição. Cria a pasta "Agenda" (lista Próximos e Passados) com dois eventos de exemplo.',
                'classes' => ['agenda', 'evento'], 'moldes' => ['agenda', 'evento'],
            ],
            'faq' => [
                'nome' => 'faq', 'titulo' => 'Perguntas frequentes',
                'descricao' => 'Pergunta (título) e resposta formatada, com ordem pelo peso e categoria opcional. A página lista tudo em sanfona (details), agrupado por categoria.',
                'classes' => ['perguntas', 'faq'], 'moldes' => ['perguntas', 'faq'],
            ],
            'destaque' => [
                'nome' => 'destaque', 'titulo' => 'Destaques da página inicial',
                'descricao' => 'Banner com imagem, título, texto curto e link (interno ou externo). Até 3 destaques, em ordem de peso, aparecem numa faixa na página inicial.',
                'classes' => ['destaque'], 'moldes' => ['destaque', 'faixa_destaques'],
            ],
            'bloco' => [
                'nome' => 'bloco', 'titulo' => 'Blocos reutilizáveis',
                'descricao' => 'Trechos de HTML que aparecem em várias páginas: o bloco de endereço "bloco-rodape" vira o rodapé e o "bloco-aviso", uma faixa de aviso no topo do site.',
                'classes' => ['bloco'], 'moldes' => ['bloco', 'bloco_aviso', 'bloco_rodape'],
            ],
        ];
    }

    /** @return list<Definicao> */
    public static function definicoes(string $modelo): array
    {
        $seo = ClassesPadrao::camposDeSeo();
        $texto = static fn (string $nome, string $rotulo, bool $indexavel = false, bool $obrigatorio = false): array => ['nome' => $nome, 'tipo' => 'texto', 'indexavel' => $indexavel, 'rotulo' => $rotulo] + ($obrigatorio ? ['obrigatorio' => true] : []);

        return match ($modelo) {
            'evento' => [
                ['nome' => 'agenda', 'propriedades' => $seo,
                    'meta' => ['descricao' => 'Lista de eventos: mostra os próximos e os passados, em ordem de data.', 'aceita_filhos' => true, 'filhas' => ['evento'], 'fixa' => false]],
                ['nome' => 'evento', 'propriedades' => [
                    $texto('resumo', 'Resumo', true),
                    ['nome' => 'corpo', 'tipo' => 'html', 'indexavel' => false, 'rotulo' => 'Descrição'],
                    ['nome' => 'inicio', 'tipo' => 'data', 'indexavel' => true, 'rotulo' => 'Início (aaaa-mm-dd hh:mm)', 'obrigatorio' => true],
                    ['nome' => 'fim', 'tipo' => 'data', 'indexavel' => false, 'rotulo' => 'Fim (aaaa-mm-dd hh:mm)'],
                    $texto('local', 'Local', true),
                    ['nome' => 'imagem', 'tipo' => 'imagem', 'indexavel' => false, 'rotulo' => 'Imagem'],
                    $texto('link_inscricao', 'Link de inscrição (opcional)'),
                    ...$seo,
                ], 'meta' => ['descricao' => 'Evento com data, local e inscrição. Vive dentro de uma Agenda.', 'aceita_filhos' => true, 'filhas' => ['arquivo', 'imagem', 'link'], 'fixa' => false]],
            ],
            'faq' => [
                ['nome' => 'perguntas', 'propriedades' => $seo,
                    'meta' => ['descricao' => 'Página de perguntas frequentes: lista as perguntas em sanfona, agrupadas por categoria.', 'aceita_filhos' => true, 'filhas' => ['faq'], 'fixa' => false]],
                ['nome' => 'faq', 'propriedades' => [
                    ['nome' => 'resposta', 'tipo' => 'html', 'indexavel' => false, 'rotulo' => 'Resposta', 'obrigatorio' => true],
                    $texto('categoria', 'Categoria (opcional)', true),
                ], 'meta' => ['descricao' => 'Pergunta frequente: o título é a pergunta; a ordem vem do peso.', 'aceita_filhos' => false, 'filhas' => [], 'fixa' => false]],
            ],
            'destaque' => [
                ['nome' => 'destaque', 'propriedades' => [
                    ['nome' => 'imagem', 'tipo' => 'imagem', 'indexavel' => false, 'rotulo' => 'Imagem'],
                    $texto('texto', 'Texto curto'),
                    ['nome' => 'destino_interno', 'tipo' => 'ref', 'indexavel' => true, 'rotulo' => 'Conteúdo deste site (destino interno)'],
                    $texto('destino_externo', 'Endereço externo (http://, https:// ou /caminho)'),
                    ['nome' => 'nova_aba', 'tipo' => 'booleano', 'indexavel' => false, 'rotulo' => 'Abrir em nova aba'],
                ], 'meta' => ['descricao' => 'Banner da página inicial: imagem, título, texto curto e link interno ou externo (só um dos dois).', 'aceita_filhos' => false, 'filhas' => [], 'fixa' => false]],
            ],
            'bloco' => [
                ['nome' => 'bloco', 'propriedades' => [
                    ['nome' => 'corpo', 'tipo' => 'html', 'indexavel' => false, 'rotulo' => 'Conteúdo do bloco'],
                ], 'meta' => ['descricao' => 'Trecho de HTML reutilizável (rodapé, barra lateral, aviso) que os moldes chamam por endereço.', 'aceita_filhos' => false, 'filhas' => [], 'fixa' => false]],
            ],
            default => throw new \InvalidArgumentException("Modelo desconhecido: {$modelo}. Os modelos são: " . implode(', ', self::NOMES)),
        };
    }

    /** O modelo está ativo quando todas as classes dele existem. */
    public function ativo(string $modelo): bool
    {
        $classes = new ClasseRepositorio($this->conexao);
        foreach (self::definicoes($modelo) as $d) {
            if ($classes->porNome($d['nome']) === null) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public function ativos(): array
    {
        return array_values(array_filter(self::NOMES, fn (string $m): bool => $this->ativo($m)));
    }

    /**
     * Liga o modelo: cria as classes que faltam e, se **todas** faltavam, o conteúdo de exemplo no site; depois prepara a pele.
     *
     * @return Resultado
     */
    public function ativar(string $modelo, int $siteId, bool $exemplos = true): array
    {
        $definicoes = self::definicoes($modelo);
        $site = (new SiteRepositorio($this->conexao))->porId($siteId) ?? throw new \InvalidArgumentException("Site {$siteId} não existe");
        $this->criados = 0;
        $avisos = [];
        $criadas = [];
        $this->conexao->transactional(function () use ($definicoes, $modelo, $site, $exemplos, &$criadas, &$avisos): void {
            $classes = new ClasseRepositorio($this->conexao);
            ClassesPadrao::garantirFixas($classes);
            // Filhas primeiro: a classe-contêiner (agenda, perguntas) lista a filha, que precisa já existir.
            foreach (array_reverse($definicoes) as $d) {
                if ($classes->porNome($d['nome']) === null) {
                    $classes->criar($d['nome'], $d['propriedades'], $d['meta']);
                    $criadas[] = $d['nome'];
                }
            }
            if ($exemplos && count($criadas) === count($definicoes)) {
                $this->exemplos($modelo, $site);
            } elseif ($exemplos && $criadas !== []) {
                $avisos[] = 'Algumas classes deste modelo já existiam: o conteúdo de exemplo não foi criado.';
            }
        });
        $moldes = $this->prepararPele($site->pele, $avisos);

        return ['classes' => $criadas, 'exemplos' => $this->criados, 'moldes' => $moldes, 'avisos' => $avisos];
    }

    /**
     * Cria o objeto se o endereço ainda não existe no site (senão devolve o que está lá, sem alterar nada).
     *
     * @param array<string, string|int|float|bool> $dados
     * @param array{peso?: int, descricao?: string, no_menu?: bool, rotulo_menu?: string, data_publicacao?: string|null, data_validade?: string|null} $meta
     * @param (\Closure(int): array<string, string|int|float|bool>)|null $aposInserir
     */
    public function garantirObjeto(Site $site, string $classe, ?int $paiId, string $titulo, string $url, array $dados = [], string $status = 'publicado', array $meta = [], ?\Closure $aposInserir = null): Objeto
    {
        $objetos = new ObjetoRepositorio($this->conexao);
        $existente = $objetos->porUrl($site->id, $url);
        if ($existente !== null) {
            return $existente;
        }
        $c = (new ClasseRepositorio($this->conexao))->porNome($classe) ?? throw new \RuntimeException("Tipo {$classe} não existe");
        ++$this->criados;

        return $objetos->criar($site->id, $c, $paiId, $titulo, $url, $dados, $status, $meta, false, $aposInserir);
    }

    public function criados(): int
    {
        return $this->criados;
    }

    private function exemplos(string $modelo, Site $site): void
    {
        $raiz = $site->objetoRaizId;
        $dia = static fn (int $dias, string $hora): string => gmdate('Y-m-d', strtotime(sprintf('%+d days', $dias)) ?: time()) . ' ' . $hora;
        switch ($modelo) {
            case 'evento':
                $agenda = $this->garantirObjeto($site, 'agenda', $raiz, 'Agenda', 'agenda', [], 'publicado', ['peso' => 40, 'no_menu' => true, 'descricao' => 'Próximos eventos e eventos passados']);
                $this->garantirObjeto($site, 'evento', $agenda->id, 'Encontro de exemplo', 'encontro-de-exemplo', [
                    'resumo' => 'Um evento de exemplo para mostrar a agenda.',
                    'corpo' => '<p>Edite ou apague este evento pelo painel de administração.</p>',
                    'inicio' => $dia(14, '19:00:00'), 'fim' => $dia(14, '21:00:00'), 'local' => 'Auditório principal',
                ]);
                $this->garantirObjeto($site, 'evento', $agenda->id, 'Evento passado de exemplo', 'evento-passado-de-exemplo', [
                    'resumo' => 'Eventos que já aconteceram ficam na seção "Eventos passados".',
                    'inicio' => $dia(-30, '14:00:00'), 'local' => 'Sala 2',
                ]);
                break;
            case 'faq':
                $faq = $this->garantirObjeto($site, 'perguntas', $raiz, 'Perguntas frequentes', 'perguntas-frequentes', [], 'publicado', ['peso' => 50, 'no_menu' => true]);
                $this->garantirObjeto($site, 'faq', $faq->id, 'Como edito esta pergunta?', 'como-edito-esta-pergunta', [
                    'resposta' => '<p>Entre no painel de administração, abra a pergunta e altere o título (a pergunta) e a resposta.</p>', 'categoria' => 'Geral',
                ], 'publicado', ['peso' => 10]);
                $this->garantirObjeto($site, 'faq', $faq->id, 'Posso apagar as perguntas de exemplo?', 'posso-apagar-as-perguntas-de-exemplo', [
                    'resposta' => '<p>Sim. Elas só existem para mostrar como a página fica.</p>', 'categoria' => 'Geral',
                ], 'publicado', ['peso' => 20]);
                break;
            case 'destaque':
                $pasta = $this->garantirObjeto($site, 'pasta', $raiz, 'Destaques', 'destaques', [], 'publicado', ['peso' => 90, 'descricao' => 'Banners da página inicial']);
                $this->garantirObjeto($site, 'destaque', $pasta->id, 'Destaque de exemplo', 'destaque-de-exemplo', [
                    'texto' => 'Troque este texto e a imagem pelo painel de administração.',
                ] + ($raiz === null ? [] : ['destino_interno' => $raiz]), 'publicado', ['peso' => 10]);
                break;
            case 'bloco':
                $pasta = $this->garantirObjeto($site, 'pasta', $raiz, 'Blocos', 'blocos', [], 'publicado', ['peso' => 95, 'descricao' => 'Trechos reutilizáveis']);
                $this->garantirObjeto($site, 'bloco', $pasta->id, 'Rodapé', 'bloco-rodape', [
                    'corpo' => '<p>Rodapé de exemplo. Edite o bloco "Rodapé" pelo painel.</p>',
                ]);
                break;
            default:
                throw new \InvalidArgumentException("Modelo desconhecido: {$modelo}");
        }
    }

    /**
     * Copia os moldes novos que faltam e atualiza os antigos que ainda estão como a distribuição. Só para a pele básica.
     *
     * @param list<string> $avisos
     * @return list<string> moldes gravados
     */
    private function prepararPele(string $pele, array &$avisos): array
    {
        if ($pele !== 'basica') {
            $avisos[] = "A pele do site é \"{$pele}\", não a básica: copie os moldes do modelo de app/recursos/peles/basica para a sua pele (veja docs/usuario/modelos-e-demo.md).";

            return [];
        }
        $origem = $this->caminhos->app() . '/recursos/peles/basica';
        $destino = $this->caminhos->peles() . '/basica';
        if (!is_dir($destino) && !@mkdir($destino, 0775, true) && !is_dir($destino)) {
            $avisos[] = "Não foi possível criar {$destino}; copie os moldes dos modelos manualmente.";

            return [];
        }
        $gravados = [];
        foreach (self::MOLDES_NOVOS as $nome) {
            if (!is_file("{$destino}/{$nome}.pbl") && is_file("{$origem}/{$nome}.pbl") && @copy("{$origem}/{$nome}.pbl", "{$destino}/{$nome}.pbl")) {
                $gravados[] = $nome;
            }
        }
        foreach (self::MOLDES_ATUALIZADOS as $nome => $antigos) {
            $novo = "{$origem}/{$nome}.pbl";
            $atual = "{$destino}/{$nome}.pbl";
            if (!is_file($novo)) {
                continue;
            }
            if (!is_file($atual)) {
                @copy($novo, $atual) && $gravados[] = $nome;
                continue;
            }
            $hash = hash_file('sha256', $atual);
            if ($hash === hash_file('sha256', $novo)) {
                continue;
            }
            if (in_array($hash, $antigos, true)) {
                @copy($novo, $atual) && $gravados[] = $nome;
            } else {
                $avisos[] = "O molde \"{$nome}\" foi personalizado e não foi alterado. Para usar os modelos, copie dele os trechos novos de app/recursos/peles/basica/{$nome}.pbl.";
            }
        }

        return $gravados;
    }
}
