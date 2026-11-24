<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Caminhos;
use Pbl\Console\Conteudo\CodigosDeSaida;
use Pbl\Console\Conteudo\ConfirmacaoNecessaria;
use Pbl\Console\Conteudo\ResolvedorDeConta;
use Pbl\Console\Conteudo\ResolvedorDeSite;
use Pbl\Console\Conteudo\Resposta;
use Pbl\Console\Conteudo\Saida;
use Pbl\Console\Conteudo\UsoInvalido;
use Pbl\Conteudo\Servico\AcessoNegado;
use Pbl\Conteudo\Servico\ConteudoNaoEncontrado;
use Pbl\Conteudo\Servico\EntradaInvalida;
use Pbl\Conteudo\Servico\EstadoInvalido;
use Pbl\Manual\Carga;
use Pbl\Manual\Compilador;
use Pbl\Manual\Edicao;
use Pbl\Manual\ErroDeManual;
use Pbl\Servicos;
use Pbl\Versao;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/pbl manual-gerar` (ADR 0040): converte `docs/usuario` em `manual/gerado/` (etapa A, sem banco) e, sem `--so-compilar`,
 * carrega o resultado no site do manual (etapa B: idempotente, `--simular`, backup, preserva edições). Códigos de saída iguais aos de
 * `bin/pbl conteudo`: 0 ok, 2 uso, 3 não encontrado, 4 sem permissão, 5 conflito, 6 validação ou falta de `--confirmar`.
 */
#[AsCommand(name: 'manual-gerar', description: 'Gera o manual a partir de docs/usuario e carrega no site do manual (idempotente; --simular, --confirmar)')]
final class ManualGerarComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('so-compilar', null, InputOption::VALUE_NONE, 'Só compila para manual/gerado/ (sem banco)');
        $this->addOption('capturas', null, InputOption::VALUE_REQUIRED, 'Pasta com as capturas de tela <id>.png (geradas pela esteira de prévia)');
        $this->addOption('sem-capturas', null, InputOption::VALUE_NONE, 'Desenvolvimento: capturas ausentes viram quadro de texto com aviso');
        $this->addOption('versao', null, InputOption::VALUE_REQUIRED, 'Versão da release (da tag, sem o v); padrão: a versão instalada');
        $this->addOption('edicao', null, InputOption::VALUE_REQUIRED, 'auto (padrão: pela versão) | estavel | beta | <versão>', 'auto');
        $this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Site do manual: número ou domínio');
        $this->addOption('como', null, InputOption::VALUE_REQUIRED, 'Login da conta (de serviço) cujas permissões valem');
        $this->addOption('criar-site', null, InputOption::VALUE_REQUIRED, 'Cria o site do manual neste domínio, se ainda não existir (exige --confirmar)');
        $this->addOption('simular', null, InputOption::VALUE_NONE, 'Mostra o plano (criar, atualizar, preservar, despublicar) sem gravar');
        $this->addOption('confirmar', null, InputOption::VALUE_NONE, 'Grava de verdade (sem isto e sem --simular, nada é feito)');
        $this->addOption('forcar', null, InputOption::VALUE_NONE, 'Sobrescreve páginas editadas à mão (grava versão nova; o histórico fica)');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Saída em JSON (api=1)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $saida = new Saida($output, (bool) $input->getOption('json'), 'manual-gerar');
        try {
            $saida->sucesso($this->rodar($input));

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
        } catch (EstadoInvalido $e) {
            $saida->erro($e->codigo, $e->getMessage());

            return CodigosDeSaida::CONFLITO;
        } catch (EntradaInvalida $e) {
            $saida->erro('validacao', $e->getMessage(), ['campos' => $e->erros]);

            return CodigosDeSaida::VALIDACAO;
        } catch (ConfirmacaoNecessaria $e) {
            $saida->erro('confirmacao_necessaria', $e->getMessage());

            return CodigosDeSaida::VALIDACAO;
        } catch (ErroDeManual $e) {
            $saida->erro('manual_invalido', $e->getMessage());

            return CodigosDeSaida::VALIDACAO;
        } catch (\InvalidArgumentException $e) {
            $saida->erro('validacao', $e->getMessage());

            return CodigosDeSaida::VALIDACAO;
        } catch (\Throwable $e) {
            $saida->erro('erro', 'Erro inesperado (' . (new \ReflectionClass($e))->getShortName() . '). Nada foi registrado com o conteúdo.');

            return CodigosDeSaida::ERRO;
        }
    }

    private function rodar(InputInterface $in): Resposta
    {
        $raiz = $this->caminhos->raiz;
        $docs = $raiz . '/docs/usuario';
        if (!is_dir($docs)) {
            throw new UsoInvalido('A pasta docs/usuario não existe nesta instalação; rode o comando no repositório do projeto.');
        }
        $versao = $this->texto($in, 'versao') ?? Versao::ATUAL;
        $capturas = $this->texto($in, 'capturas');
        $sem = (bool) $in->getOption('sem-capturas');
        $compilador = new Compilador($docs, $raiz . '/manual/links-externos.json', $capturas, $sem);
        $pacote = $compilador->compilar();
        $pasta = $raiz . '/manual/gerado';
        $this->gravarPacote($pacote, $pasta, $capturas);
        $paginas = array_sum(array_map(static fn (array $c): int => count($c['paginas']), $pacote['capitulos']));
        $dados = ['capitulos' => count($pacote['capitulos']), 'paginas' => $paginas, 'pacote' => 'manual/gerado/manual.json', 'avisos' => $compilador->avisos()];
        $texto = sprintf("Manual compilado: %d capítulos, %d páginas (manual/gerado/manual.json).", $dados['capitulos'], $paginas);
        foreach ($dados['avisos'] as $aviso) {
            $texto .= "\nAviso: " . $aviso;
        }
        if ($in->getOption('so-compilar')) {
            return new Resposta($dados, $texto);
        }

        $simular = (bool) $in->getOption('simular');
        $confirmar = (bool) $in->getOption('confirmar');
        if (!$simular && !$confirmar) {
            throw new ConfirmacaoNecessaria('Vai carregar o manual no site. Nada foi feito; repita com --simular (para ver o plano) ou --confirmar (para gravar).');
        }
        $s = Servicos::doBancoInstalado($this->caminhos, 'cli');
        $quem = (new ResolvedorDeConta($s->usuarios))->resolver($this->texto($in, 'como'));
        $criar = $this->texto($in, 'criar-site');
        if ($criar !== null) {
            if ($simular) {
                throw new UsoInvalido('--criar-site grava; use com --confirmar (e sem --simular).');
            }
            $site = Carga::garantirSite($s, $criar);
        } else {
            $site = (new ResolvedorDeSite($s->sites))->resolver($this->texto($in, 'site'));
        }
        $carga = new Carga($s, $quem, $site);
        $resultados = [];
        foreach (Edicao::para((string) $in->getOption('edicao'), $versao) as $edicao) {
            if (!$simular && $capturas !== null) {
                $carga->copiarCapturas($pasta . '/capturas', $edicao);
            }
            $resultados[] = $carga->executar($pacote, $edicao, $simular, (bool) $in->getOption('forcar'));
        }
        $dados['edicoes'] = $resultados;
        foreach ($resultados as $r) {
            $texto .= sprintf("\n\nEdição %s%s: %s", $r['edicao'], $r['simulado'] ? ' (simulação)' : '', $r['alterado'] ? 'houve mudanças' : 'nada a alterar');
            foreach ($r['resumo'] as $acao => $n) {
                $texto .= sprintf("\n  %s: %d", $acao, $n);
            }
            foreach ($r['plano'] as $p) {
                if (!in_array($p['acao'], ['igual'], true) && $simular) {
                    $texto .= "\n    - {$p['acao']}: {$p['origem']}";
                }
            }
            foreach ($r['conflitos'] as $c) {
                $texto .= "\n  Preservado (editado no painel; use --forcar para sobrescrever): " . $c;
            }
        }

        return new Resposta($dados, $texto);
    }

    /** @param array<string, mixed> $pacote */
    private function gravarPacote(array $pacote, string $pasta, ?string $capturas): void
    {
        if (!is_dir($pasta . '/capturas') && !@mkdir($pasta . '/capturas', 0755, true) && !is_dir($pasta . '/capturas')) {
            throw new \RuntimeException('Não foi possível criar manual/gerado.');
        }
        file_put_contents($pasta . '/manual.json', Compilador::emJson($pacote), LOCK_EX);
        foreach ($pacote['capturas'] as $id) {
            if ($capturas !== null && is_file($capturas . '/' . $id . '.png')) {
                copy($capturas . '/' . $id . '.png', $pasta . '/capturas/' . $id . '.png');
            }
        }
    }

    private function texto(InputInterface $in, string $opcao): ?string
    {
        $v = $in->getOption($opcao);

        return is_string($v) && $v !== '' ? $v : null;
    }
}
