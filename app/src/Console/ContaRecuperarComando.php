<?php

declare(strict_types=1);

namespace Pbl\Console;

use Pbl\Caminhos;
use Pbl\Servicos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Recuperação para o operador quando o servidor não tem SMTP (ou para enviar por e-mail com `--email`). Emite um link de uso único
 * (30 min). O link é um segredo: aparece só aqui, uma vez, e nunca vai ao log nem à auditoria. Nunca imprime senha.
 */
#[AsCommand(name: 'conta-recuperar', description: 'Emite o link único de redefinição de senha de uma conta (imprime uma vez, ou --email envia)')]
final class ContaRecuperarComando extends Command
{
    public function __construct(private readonly Caminhos $caminhos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('login', InputArgument::REQUIRED, 'Login da conta');
        $this->addOption('email', null, InputOption::VALUE_NONE, 'Envia o link ao e-mail da conta em vez de imprimir');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $s = Servicos::doBancoInstalado($this->caminhos, 'cli');
            $login = (string) $input->getArgument('login');
            $u = $s->usuarios->porLogin($login) ?? throw new \InvalidArgumentException("Usuário não encontrado: {$login}");
            if (!$u->ativo || $u->ehServico() || $s->usuarios->convitePendente($u->id)) {
                throw new \InvalidArgumentException('A conta não pode recuperar a senha (inativa, de serviço ou com convite pendente)');
            }
            $emails = $s->emailsDeConta();
            if (!$emails->temBase()) {
                throw new \InvalidArgumentException('Configure o domínio principal do site: os links usam esse endereço (ou use conta-senha --gerar)');
            }
            $link = $s->recuperacao()->emitirLinkParaOperador($u);
            if ($input->getOption('email')) {
                if (!$emails->habilitado()) {
                    throw new \InvalidArgumentException('O envio de e-mail não está configurado; rode sem --email e entregue o link');
                }
                $token = substr($link, (int) strrpos($link, '/') + 1);
                $emails->recuperacao($u, $token);
                $output->writeln("Link enviado ao e-mail de {$u->login}.");

                return Command::SUCCESS;
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln('Link de uso único (vale 30 minutos; é um segredo, entregue só à pessoa da conta):');
        $output->writeln($link);

        return Command::SUCCESS;
    }
}
