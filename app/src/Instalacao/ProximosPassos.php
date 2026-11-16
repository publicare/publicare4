<?php

declare(strict_types=1);

namespace Pbl\Instalacao;

use Pbl\Admin\Pagina;
use Pbl\Caminhos;

/** A tela final do assistente: o que fazer depois de instalado (cron, cópia de segurança, atualização, login). */
final class ProximosPassos
{
    public static function html(Caminhos $caminhos, string $login, string $aviso, ?string $raizDoSite, bool $seguro): string
    {
        $cron = '* * * * * php ' . $caminhos->raiz . '/bin/pbl cron >/dev/null 2>&1';
        $publica = $caminhos->raiz . '/public';
        $raiz = $raizDoSite === null || $raizDoSite === '' ? null : (@realpath($raizDoSite) ?: $raizDoSite);
        if ($raiz === $publica) {
            $layout = '<li class="pbl-ok">✔ A raiz do site já é a pasta <code>public/</code>: é o arranjo mais seguro.</li>';
        } elseif ($raiz === $caminhos->raiz) {
            $layout = '<li>A raiz do seu site é a pasta inteira do Publicare. O arquivo <code>.htaccess</code> protege as pastas internas, mas só no Apache. '
                . '<strong>Se puder, aponte a raiz do site para a pasta <code>public/</code></strong> (no painel: "raiz do documento"). Depois abra <code>/dados/</code> no navegador: deve dar "proibido" ou "não encontrado".</li>';
        } else {
            $layout = '<li>Confirme que só a pasta <code>public/</code> é a raiz do site no servidor e que <code>/dados/</code> não abre no navegador.</li>';
        }

        return Pagina::alerta('success', 'Tudo pronto: o Publicare 4 foi instalado.') . $aviso
            . '<h2 class="h5 mt-4">Como entrar</h2><ol>'
            . '<li>Abra <a href="/_pbl/entrar">Entrar na administração</a> e use o login <strong>' . Pagina::h($login) . '</strong> e a senha que você acabou de criar.</li>'
            . '<li><a href="/">Ver o site</a>: já vem com páginas de exemplo (Sobre, Notícias e Contato) que você pode editar ou apagar.</li></ol>'
            . '<h2 class="h5 mt-4">Próximos passos</h2><ol>'
            . '<li><strong>Agende o cron</strong> (publicação agendada, limpezas e avisos). No painel da hospedagem, em "Tarefas agendadas" ou "Cron", a cada minuto: <pre class="pbl-cron"><code>' . Pagina::h($cron) . '</code></pre>'
            . 'Se o <code>php</code> não for encontrado, use o caminho completo (por exemplo <code>/usr/bin/php8.3</code>).</li>'
            . '<li><strong>Cópia de segurança:</strong> tudo o que é seu fica na pasta <code>dados/</code> (banco, arquivos enviados, peles). Copie essa pasta de vez em quando. Antes de cada atualização o Publicare também guarda uma cópia do banco em <code>dados/backups/</code>.</li>'
            . '<li><strong>Atualizar:</strong> entre na administração, abra <em>Atualização</em> e clique para conferir; ou, pelo terminal, <code>php bin/pbl atualizar</code>. A atualização é assinada e confere tudo antes de trocar o código.</li>'
            . $layout
            . ($seguro ? '' : '<li>Use <strong>HTTPS</strong> no endereço do site: o login só fica totalmente protegido assim. Se o site fica atrás de um proxy (como o Cloudflare), crie <code>dados/config/rede.json</code> (veja o guia de instalação).</li>')
            . '<li>Guarde a senha em um gerenciador de senhas. O Publicare não consegue recuperá-la (no terminal: <code>php bin/pbl conta-senha ' . Pagina::h($login) . '</code>).</li></ol>';
    }
}
