<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Caminhos;
use Pbl\Console\Conteudo\AcaoDeConteudo;
use Pbl\Console\Conteudo\AnexarAcao;
use Pbl\Console\Conteudo\CodigosDeSaida;
use Pbl\Console\Conteudo\ConfirmacaoNecessaria;
use Pbl\Console\Conteudo\Contexto;
use Pbl\Console\Conteudo\CriarAcao;
use Pbl\Console\Conteudo\DespublicarAcao;
use Pbl\Console\Conteudo\EditarAcao;
use Pbl\Console\Conteudo\Entrada;
use Pbl\Console\Conteudo\EntradaPadrao;
use Pbl\Console\Conteudo\EnviarAcao;
use Pbl\Console\Conteudo\HistoricoAcao;
use Pbl\Console\Conteudo\LixeiraAcao;
use Pbl\Console\Conteudo\ListarAcao;
use Pbl\Console\Conteudo\MoverAcao;
use Pbl\Console\Conteudo\PublicarAcao;
use Pbl\Console\Conteudo\RepublicarAcao;
use Pbl\Console\Conteudo\ResolvedorDeConta;
use Pbl\Console\Conteudo\ResolvedorDeSite;
use Pbl\Console\Conteudo\RestaurarVersaoAcao;
use Pbl\Console\Conteudo\RetirarAcao;
use Pbl\Console\Conteudo\Saida;
use Pbl\Console\Conteudo\UsoInvalido;
use Pbl\Console\Conteudo\VerAcao;
use Pbl\Conteudo\ConflitoDeVersao;
use Pbl\Conteudo\Servico\AcessoNegado;
use Pbl\Conteudo\Servico\ConteudoNaoEncontrado;
use Pbl\Conteudo\Servico\EntradaInvalida;
use Pbl\Conteudo\Servico\EstadoInvalido;
use Pbl\Servicos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/pbl conteudo <ação> [id|caminho]` (ADR 0033): editar conteúdo por linha de comando, para agentes e scripts. Toda ação
 * passa pelo `ServicoDeConteudo` com a conta de `--como`. Saída `--json` estável (api=1) ou texto; códigos de saída em
 * {@see CodigosDeSaida}. Nenhuma opção recebe segredo; texto longo vai por `--json=-` (stdin). Nada é registrado em log.
 */
#[AsCommand(name: 'conteudo', description: 'Edita conteúdo por linha de comando: listar, ver, historico, editar, criar, publicar, despublicar, enviar, retirar, mover, lixeira, restaurar-versao, imagem, arquivo, republicar')]
final class ConteudoComando extends Command
{
    /** @var array<string, AcaoDeConteudo> */
    private array $acoes = [];

    public function __construct(private readonly Caminhos $caminhos, private readonly Entrada $entrada = new EntradaPadrao())
    {
        parent::__construct();
        foreach ([new ListarAcao(), new VerAcao(), new HistoricoAcao(), new EditarAcao(), new CriarAcao(), new PublicarAcao(), new DespublicarAcao(),
            new EnviarAcao(), new RetirarAcao(), new MoverAcao(), new LixeiraAcao(), new RestaurarVersaoAcao(), new AnexarAcao('imagem'), new AnexarAcao('arquivo'), new RepublicarAcao()] as $acao) {
            $this->acoes[$acao->nome()] = $acao;
        }
    }

    protected function configure(): void
    {
        $this->addArgument('acao', InputArgument::REQUIRED, implode(' | ', array_keys($this->acoes)));
        $this->addArgument('alvo', InputArgument::OPTIONAL, 'Conteúdo: número (id) ou endereço no site (/ = página inicial)');
        $this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Site: número ou domínio (obrigatório se houver mais de um)');
        $this->addOption('como', null, InputOption::VALUE_REQUIRED, 'Login da conta cujas permissões valem');
        $this->addOption('json', null, InputOption::VALUE_OPTIONAL, 'Saída em JSON (api=1). --json=- também lê o conteúdo a gravar do stdin. Ponha depois dos argumentos.', false);
        $this->addOption('campo', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'nome=valor (repetível); texto longo ou sensível: use --json=-');
        $this->addOption('versao', null, InputOption::VALUE_REQUIRED, 'Versão que você viu (obrigatória em mudanças)');
        $this->addOption('publicar', null, InputOption::VALUE_NONE, 'editar: grava e publica');
        $this->addOption('enviar-revisao', null, InputOption::VALUE_NONE, 'editar: grava e envia para revisão');
        $this->addOption('confirmar', null, InputOption::VALUE_NONE, 'Confirma operação destrutiva (mover, lixeira, restaurar-versao, republicar)');
        $this->addOption('pai', null, InputOption::VALUE_REQUIRED, 'criar: onde criar; listar: de quem listar os filhos');
        $this->addOption('tipo', null, InputOption::VALUE_REQUIRED, 'criar: classe; listar: filtro por classe');
        $this->addOption('para', null, InputOption::VALUE_REQUIRED, 'mover: novo pai; restaurar-versao: número da versão');
        $this->addOption('arvore', null, InputOption::VALUE_NONE, 'listar: toda a subárvore');
        $this->addOption('estado', null, InputOption::VALUE_REQUIRED, 'listar: privado | submetido | rejeitado | publicado | apagado');
        $this->addOption('busca', null, InputOption::VALUE_REQUIRED, 'listar: texto a buscar');
        $this->addOption('limite', null, InputOption::VALUE_REQUIRED, 'listar: máximo de itens (padrão 50, teto 200)');
        $this->addOption('arquivo', null, InputOption::VALUE_REQUIRED, 'imagem/arquivo: caminho do arquivo local');
        $this->addOption('alt', null, InputOption::VALUE_REQUIRED, 'imagem: texto alternativo (obrigatório)');
        $this->addOption('orcamento', null, InputOption::VALUE_REQUIRED, 'republicar: segundos de trabalho (padrão 25, teto 120)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $nome = (string) $input->getArgument('acao');
        $alvo = $input->getArgument('alvo');
        $alvo = is_string($alvo) ? $alvo : null;
        $jsonOpcao = $input->getOption('json');
        // `--json 12` faz o Symfony tomar o "12" como valor da opção: devolve ao argumento que faltava.
        if (is_string($jsonOpcao) && $jsonOpcao !== '-' && $jsonOpcao !== '') {
            $alvo ??= $jsonOpcao;
            $jsonOpcao = null;
        }
        $json = $jsonOpcao !== false;
        $saida = new Saida($output, $json, $nome);
        try {
            $acao = $this->acoes[$nome] ?? throw new UsoInvalido('Ação desconhecida. Use: ' . implode(', ', array_keys($this->acoes)) . '.');
            $s = Servicos::doBancoInstalado($this->caminhos, 'cli');
            $quem = (new ResolvedorDeConta($s->usuarios))->resolver($this->texto($input, 'como'));
            $site = (new ResolvedorDeSite($s->sites))->resolver($this->texto($input, 'site'));
            $contexto = new Contexto($s, $quem, $site, $input, $this->entrada, $alvo, $json, $jsonOpcao === '-');
            $saida->sucesso($acao->executar($contexto));

            return CodigosDeSaida::SUCESSO;
        } catch (UsoInvalido $e) {
            $saida->erro('uso', $e->getMessage());

            return CodigosDeSaida::USO;
        } catch (ConteudoNaoEncontrado $e) {
            $saida->erro('nao_encontrado', $e->getMessage());

            return CodigosDeSaida::NAO_ENCONTRADO;
        } catch (AcessoNegado $e) {
            $saida->erro('sem_permissao', $e->getMessage());

            return CodigosDeSaida::SEM_PERMISSAO;
        } catch (ConflitoDeVersao $e) {
            $saida->erro('conflito_de_versao', 'O conteúdo foi alterado depois da versão informada; veja de novo com "ver".', ['versao_atual' => $e->atual]);

            return CodigosDeSaida::CONFLITO;
        } catch (EstadoInvalido $e) {
            $saida->erro($e->codigo, $e->getMessage());

            return CodigosDeSaida::CONFLITO;
        } catch (EntradaInvalida $e) {
            $saida->erro('validacao', $e->getMessage(), ['campos' => $e->erros]);

            return CodigosDeSaida::VALIDACAO;
        } catch (ConfirmacaoNecessaria $e) {
            $saida->erro('confirmacao_necessaria', $e->getMessage());

            return CodigosDeSaida::VALIDACAO;
        } catch (\Throwable $e) {
            // Nunca repete a mensagem da exceção: ela pode trazer pedaço de SQL ou de conteúdo.
            $saida->erro('erro', 'Erro inesperado (' . (new \ReflectionClass($e))->getShortName() . '). Nada foi registrado com o conteúdo.');

            return CodigosDeSaida::ERRO;
        }
    }

    private function texto(InputInterface $input, string $opcao): ?string
    {
        $v = $input->getOption($opcao);

        return is_string($v) ? $v : null;
    }
}
