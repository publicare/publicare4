<?php
/**
 * Instalador do Publicare 4 (etapa 1 de 2): um arquivo só, sem Composer e sem dependências.
 *
 * Envie este arquivo para a pasta do site (FTP ou painel), crie ao lado dele um arquivo VAZIO chamado
 * "instalar.liberar" (prova de que o servidor é seu) e abra este endereço no navegador. Ele confere o servidor,
 * baixa o pacote, VERIFICA a assinatura Ed25519 do manifesto com as chaves públicas que estão embutidas abaixo,
 * confere tamanho e SHA-256 do pacote, extrai com proteção e leva ao assistente (etapa 2). No fim, apaga a si mesmo.
 *
 * Segurança: as chaves de confiança só existem neste arquivo (nunca vêm de rede, parâmetro, variável de ambiente
 * ou arquivo); nada baixado é executado; só HTTPS com certificado verificado; nada é gravado fora desta pasta.
 *
 * Este arquivo é GERADO a partir de infra/instalador/instalar.php (bin/gerar-instalador): os blocos CONFIG e
 * TRANSPORTE entre os marcadores são reescritos a partir de Pbl\Atualizacao\Chaves. Sintaxe limitada ao PHP 7.4 de propósito,
 * para que um PHP antigo consiga mostrar "seu PHP é velho demais" em vez de um erro de sintaxe.
 */

//@CONFIG-INICIO
const PBLX_CHAVES_USO = ['862a04bc8a5c8166d31a691ff4416198ea251fb4da2a72197c53a4c0e6aa0ce8'];
const PBLX_CHAVES_RECUPERACAO = ['aa8837093423b48935588a6a6fcebf3e3f1a6c5a52cdb1cafe3a4c6e06a70411'];
const PBLX_URL_BASE = 'https://atualizacoes.publicare.org';
const PBLX_VERSAO_DICA = '0.1.0';
//@CONFIG-FIM

//@TRANSPORTE-INICIO

/** Transporte de produção: só HTTPS, certificado verificado, mesmo host, no máximo 3 redirecionamentos. */
function pblx_baixar(string $url, int $max, string $destino = ''): string
{
    $atual = $url;
    for ($salto = 0; $salto <= 3; $salto++) {
        pblx_url_permitida($atual, PBLX_URL_BASE);
        $r = function_exists('curl_init') ? pblx_http_curl($atual, $max, $destino) : pblx_http_fluxo($atual, $max, $destino);
        if ($r['status'] >= 300 && $r['status'] < 400 && $r['local'] !== '') {
            $atual = pblx_resolver($r['local'], $atual);
            continue;
        }
        if ($r['status'] !== 200) {
            throw new PblxErro('O servidor de atualizações respondeu com um erro (código ' . $r['status'] . '). Tente de novo em alguns minutos.');
        }

        return $r['corpo'];
    }
    throw new PblxErro('Redirecionamentos demais ao falar com o servidor de atualizações.');
}

/** @return array{status: int, local: string, corpo: string} */
function pblx_http_curl(string $url, int $max, string $destino): array
{
    $saida = $destino !== '' ? @fopen($destino, 'wb') : null;
    if ($destino !== '' && $saida === false) {
        throw new PblxErro('Não foi possível gravar na pasta do site.');
    }
    $corpo = '';
    $tamanho = 0;
    $local = '';
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_USERAGENT => 'publicare4-instalar.php',
        CURLOPT_HEADERFUNCTION => static function ($ch, string $linha) use (&$local): int {
            if (stripos($linha, 'location:') === 0) {
                $local = trim(substr($linha, 9));
            }

            return strlen($linha);
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, string $trecho) use (&$corpo, &$tamanho, $max, $saida): int {
            $tamanho += strlen($trecho);
            if ($tamanho > $max) {
                return 0; // aborta: maior que o permitido
            }
            if ($saida) {
                fwrite($saida, $trecho);
            } else {
                $corpo .= $trecho;
            }

            return strlen($trecho);
        },
    ]);
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        curl_setopt($c, CURLOPT_PROTOCOLS_STR, 'https');
    } elseif (defined('CURLPROTO_HTTPS')) {
        curl_setopt($c, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
    }
    $ok = curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    if ($saida) {
        fclose($saida);
    }
    if ($ok === false && !($status >= 300 && $status < 400)) {
        throw new PblxErro($tamanho > $max ? 'O arquivo baixado é maior que o permitido.' : 'Não foi possível conectar com segurança ao servidor de atualizações (confira a internet do servidor e se o certificado HTTPS é aceito).');
    }

    return ['status' => $status, 'local' => $local, 'corpo' => $corpo];
}

/** @return array{status: int, local: string, corpo: string} */
function pblx_http_fluxo(string $url, int $max, string $destino): array
{
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 60, 'user_agent' => 'publicare4-instalar.php'],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]]);
    $f = @fopen($url, 'rb', false, $ctx);
    if ($f === false) {
        throw new PblxErro('Não foi possível conectar com segurança ao servidor de atualizações (confira a internet do servidor e se o certificado HTTPS é aceito).');
    }
    $status = 0;
    $local = '';
    foreach (pblx_cabecalhos($f) as $linha) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linha, $m) === 1) {
            $status = (int) $m[1];
        } elseif (stripos($linha, 'location:') === 0) {
            $local = trim(substr($linha, 9));
        }
    }
    $saida = $destino !== '' ? @fopen($destino, 'wb') : null;
    $corpo = '';
    $lidos = 0;
    while (!feof($f)) {
        $trecho = fread($f, 65536);
        if ($trecho === false) {
            break;
        }
        $lidos += strlen($trecho);
        if ($lidos > $max) {
            fclose($f);
            throw new PblxErro('O arquivo baixado é maior que o permitido.');
        }
        if ($saida) {
            fwrite($saida, $trecho);
        } else {
            $corpo .= $trecho;
        }
    }
    fclose($f);
    if ($saida) {
        fclose($saida);
    }

    return ['status' => $status, 'local' => $local, 'corpo' => $corpo];
}

/**
 * @param resource $fluxo
 * @return list<string> cabeçalhos da resposta HTTP de um fluxo aberto com fopen
 */
function pblx_cabecalhos($fluxo): array
{
    $meta = stream_get_meta_data($fluxo);
    $saida = [];
    foreach (isset($meta['wrapper_data']) && is_array($meta['wrapper_data']) ? $meta['wrapper_data'] : [] as $linha) {
        if (is_string($linha)) {
            $saida[] = $linha;
        }
    }

    return $saida;
}
//@TRANSPORTE-FIM

class PblxErro extends RuntimeException
{
}

const PBLX_ITENS = ['app', 'vendor', 'bin', 'public', 'composer.json', 'composer.lock', '.htaccess', 'LICENSE', 'NOTICE'];
const PBLX_CONTROLE = 'PACOTE.json';
const PBLX_MAX_ARQUIVOS = 30000;
const PBLX_MAX_BYTES = 400000000;
const PBLX_MAX_ARQUIVO = 67108864;
const PBLX_MAX_PACOTE = 200000000;
const PBLX_POSSE = 'instalar.liberar';
const PBLX_EXTENSOES = [
    'pdo_sqlite' => 'o banco de dados SQLite (o padrão, que não precisa de servidor)',
    'mbstring' => 'textos com acentos',
    'sodium' => 'conferir a assinatura do pacote',
    'json' => 'leitura de dados',
    'ctype' => 'validação de textos',
    'dom' => 'limpeza do HTML dos campos de texto',
    'fileinfo' => 'identificar o tipo de um arquivo enviado',
];

// ---------------------------------------------------------------- requisitos

/** @return array<string, mixed> o que o servidor tem (separado de pblx_requisitos para dar para testar) */
function pblx_ambiente(string $dir): array
{
    $carregadas = [];
    foreach (array_merge(array_keys(PBLX_EXTENSOES), ['openssl', 'curl', 'zip']) as $nome) {
        $carregadas[$nome] = extension_loaded($nome);
    }

    return [
        'php' => PHP_VERSION,
        'extensoes' => $carregadas,
        'gravavel' => is_dir($dir) && is_writable($dir),
        'open_basedir' => (string) ini_get('open_basedir'),
        'dentro_open_basedir' => pblx_dentro_open_basedir($dir, (string) ini_get('open_basedir')),
        'allow_url_fopen' => (bool) ini_get('allow_url_fopen'),
    ];
}

function pblx_dentro_open_basedir(string $caminho, string $lista): bool
{
    if (trim($lista) === '') {
        return true;
    }
    $real = @realpath($caminho);
    $real = $real === false ? $caminho : $real;
    foreach (explode(PATH_SEPARATOR, $lista) as $permitida) {
        $permitida = trim($permitida);
        if ($permitida === '') {
            continue;
        }
        $p = @realpath($permitida);
        $p = rtrim($p === false ? $permitida : $p, '/\\');
        if ($real === $p || strpos($real, $p . DIRECTORY_SEPARATOR) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $amb
 * @return list<array{nome: string, ok: bool, obrigatorio: bool, detalhe: string}>
 */
function pblx_requisitos(array $amb): array
{
    $itens = [];
    $php = (string) $amb['php'];
    $ok = version_compare($php, '8.2.0', '>=');
    $itens[] = ['nome' => 'PHP 8.2 ou mais novo', 'ok' => $ok, 'obrigatorio' => true,
        'detalhe' => $ok ? "Este servidor tem o PHP {$php}." : "Este servidor tem o PHP {$php}, que é antigo demais. No painel da hospedagem procure \"versão do PHP\" e escolha 8.2 ou mais novo (de preferência 8.3).",
    ];
    /** @var array<string, bool> $ext */
    $ext = $amb['extensoes'];
    foreach (PBLX_EXTENSOES as $nome => $para) {
        $tem = !empty($ext[$nome]);
        $itens[] = ['nome' => "Extensão {$nome}", 'ok' => $tem, 'obrigatorio' => true,
            'detalhe' => $tem ? "Presente (para {$para})." : "Ausente: necessária para {$para}. No painel, procure \"extensões do PHP\" e ative \"{$nome}\"; se não houver, peça à hospedagem.",
        ];
    }
    $zip = !empty($ext['zip']);
    $itens[] = ['nome' => 'Extensão zip', 'ok' => $zip, 'obrigatorio' => false,
        'detalhe' => $zip ? 'Presente.' : 'Ausente, sem problema: este instalador traz um leitor de zip próprio. (Depois de instalado, a atualização automática pelo painel e o upload de documentos de escritório precisam da extensão zip; peça à hospedagem quando puder.)',
    ];
    $grav = !empty($amb['gravavel']);
    $itens[] = ['nome' => 'Escrita na pasta do site', 'ok' => $grav, 'obrigatorio' => true,
        'detalhe' => $grav ? 'O instalador consegue gravar nesta pasta.' : 'O PHP não pode gravar nesta pasta. No gerenciador de arquivos do painel (ou no FTP) dê permissão 755 ou 775 à pasta onde está o instalar.php.',
    ];
    $livre = !empty($amb['dentro_open_basedir']);
    $itens[] = ['nome' => 'Acesso do PHP a esta pasta (open_basedir)', 'ok' => $livre, 'obrigatorio' => true,
        'detalhe' => $livre ? 'Sem restrição que atrapalhe.' : 'A hospedagem limita as pastas que o PHP enxerga (open_basedir) e esta pasta está fora do limite. Coloque o site dentro da pasta do domínio ou peça à hospedagem para liberá-la.',
    ];
    $rede = !empty($ext['curl']) || (!empty($amb['allow_url_fopen']) && !empty($ext['openssl']));
    $itens[] = ['nome' => 'Conexão HTTPS para baixar o pacote (curl, ou allow_url_fopen com openssl)', 'ok' => $rede, 'obrigatorio' => true,
        'detalhe' => $rede ? 'Presente.' : 'O PHP não consegue baixar arquivos pela internet. Ative a extensão curl (ou a opção allow_url_fopen junto com a extensão openssl) no painel, ou peça à hospedagem. Sem isso, envie o pacote pelo caminho manual do guia de instalação.',
    ];

    return $itens;
}

/** @param list<array{nome: string, ok: bool, obrigatorio: bool, detalhe: string}> $itens */
function pblx_requisitos_ok(array $itens): bool
{
    foreach ($itens as $i) {
        if ($i['obrigatorio'] && !$i['ok']) {
            return false;
        }
    }

    return true;
}

// ---------------------------------------------------------------- rede, manifesto e assinatura

/** Só https, sem usuário/senha, e só o host do canal de atualizações. */
function pblx_url_permitida(string $url, string $base): void
{
    $u = parse_url($url);
    $b = parse_url($base);
    if (!is_array($u) || !is_array($b) || ($u['scheme'] ?? '') !== 'https' || isset($u['user']) || isset($u['pass'])
        || strtolower((string) ($u['host'] ?? '')) !== strtolower((string) ($b['host'] ?? '-')) || isset($u['port']) && (int) $u['port'] !== 443) {
        throw new PblxErro('Endereço recusado: só são aceitos endereços HTTPS do servidor oficial de atualizações.');
    }
}

function pblx_resolver(string $local, string $de): string
{
    if (preg_match('#^https?://#i', $local) === 1) {
        return $local;
    }
    $p = parse_url($de);
    $origem = 'https://' . ($p['host'] ?? '');
    if (strpos($local, '/') === 0) {
        return $origem . $local;
    }

    return $origem . rtrim(dirname((string) ($p['path'] ?? '/')), '/') . '/' . $local;
}

function pblx_verificar_assinatura(string $mensagem, string $assinaturaBase64): bool
{
    $sig = base64_decode(trim($assinaturaBase64), true);
    if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
        return false;
    }
    foreach (array_merge(PBLX_CHAVES_USO, PBLX_CHAVES_RECUPERACAO) as $chave) {
        if (preg_match('/^[0-9a-f]{64}$/D', $chave) !== 1) {
            continue;
        }
        $binaria = sodium_hex2bin($chave);
        if ($binaria !== '' && sodium_crypto_sign_verify_detached($sig, $mensagem, $binaria)) {
            return true;
        }
    }

    return false;
}

/**
 * Mesmas regras de Pbl\Atualizacao\Manifesto::analisar para os campos que o instalador usa. Chaves vindas no manifesto
 * (chaves.uso, chaves.revogadas) são IGNORADAS de propósito: trocar de chave exige um instalar.php novo.
 *
 * @return array{canal: string, versao: string, php_minimo: string, url: string, tamanho: int, sha256: string}
 */
function pblx_manifesto(string $json): array
{
    $d = json_decode($json, true);
    if (!is_array($d) || ($d['formato'] ?? null) !== 1) {
        throw new PblxErro('O manifesto de atualização veio em um formato desconhecido.');
    }
    $texto = static function (string $campo, int $max) use ($d): string {
        if (!isset($d[$campo]) || !is_string($d[$campo]) || strlen($d[$campo]) > $max) {
            throw new PblxErro('O manifesto de atualização está incompleto.');
        }

        return $d[$campo];
    };
    $semver = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';
    $versao = $texto('versao', 64);
    $canal = $texto('canal', 20);
    $minimo = $texto('php_minimo', 20);
    if (preg_match($semver, $versao) !== 1 || !in_array($canal, ['estavel', 'beta', 'teste'], true) || preg_match('/^\d+\.\d+(\.\d+)?$/D', $minimo) !== 1) {
        throw new PblxErro('O manifesto de atualização tem valores inválidos.');
    }
    $p = $d['pacote'] ?? null;
    if (!is_array($p) || !isset($p['url'], $p['tamanho'], $p['sha256']) || !is_string($p['url']) || !is_int($p['tamanho']) || !is_string($p['sha256'])
        || $p['tamanho'] <= 0 || $p['tamanho'] > PBLX_MAX_PACOTE || preg_match('/^[0-9a-f]{64}$/D', $p['sha256']) !== 1 || strlen($p['url']) > 500) {
        throw new PblxErro('O manifesto de atualização traz um pacote inválido.');
    }

    return ['canal' => $canal, 'versao' => $versao, 'php_minimo' => $minimo, 'url' => $p['url'], 'tamanho' => $p['tamanho'], 'sha256' => $p['sha256']];
}

/** O endereço do manifesto do canal ('beta' ou, para qualquer outro valor, o estável): o MESMO usado para baixar e gravado em atualizacao.json. */
function pblx_url_manifesto(string $canal): string
{
    return PBLX_URL_BASE . ($canal === 'beta' ? '/latest-beta.json' : '/latest.json');
}

/**
 * Baixa o manifesto do canal, CONFERE A ASSINATURA e só então baixa e confere o pacote.
 *
 * @return array{versao: string, zip: string}
 */
function pblx_baixar_pacote(string $canal, string $pastaTmp): array
{
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        throw new PblxErro('A extensão sodium do PHP não está ativa: sem ela não dá para conferir a assinatura.');
    }
    $urlManifesto = pblx_url_manifesto($canal);
    $manifesto = pblx_baixar($urlManifesto, 200000);
    $assinatura = pblx_baixar($urlManifesto . '.sig', 4096);
    if (!pblx_verificar_assinatura($manifesto, $assinatura)) {
        throw new PblxErro('A assinatura do manifesto NÃO confere com as chaves do Publicare. Nada foi instalado. Não use este pacote; avise a equipe do Publicare.');
    }
    $m = pblx_manifesto($manifesto);
    if ($m['canal'] !== ($canal === 'beta' ? 'beta' : 'estavel')) {
        throw new PblxErro('O manifesto não é do canal pedido. Nada foi instalado.');
    }
    if (version_compare(PHP_VERSION, $m['php_minimo'], '<')) {
        throw new PblxErro('Esta versão do Publicare precisa do PHP ' . $m['php_minimo'] . ' ou mais novo.');
    }
    $urlPacote = pblx_resolver($m['url'], $urlManifesto);
    pblx_url_permitida($urlPacote, PBLX_URL_BASE);
    $zip = $pastaTmp . '/pacote.zip';
    pblx_baixar($urlPacote, $m['tamanho'] + 1, $zip);
    $tamanho = @filesize($zip);
    $hash = @hash_file('sha256', $zip);
    if ($tamanho !== $m['tamanho']) {
        throw new PblxErro('O pacote baixado tem o tamanho errado. Nada foi instalado; tente de novo.');
    }
    if ($hash === false || !hash_equals($m['sha256'], $hash)) {
        throw new PblxErro('O pacote baixado não confere com o SHA-256 do manifesto assinado. Nada foi instalado; tente de novo.');
    }

    return ['versao' => $m['versao'], 'zip' => $zip];
}

// ---------------------------------------------------------------- zip (leitor próprio) e extração

function pblx_nome_valido(string $nome): string
{
    if ($nome === '' || strlen($nome) > 300 || preg_match('/[\x00-\x1F\x7F\\\\:]/', $nome) === 1 || $nome[0] === '/') {
        throw new PblxErro('O pacote traz um nome de arquivo inválido.');
    }
    $partes = explode('/', $nome);
    foreach ($partes as $parte) {
        if ($parte === '' || $parte === '.' || $parte === '..') {
            throw new PblxErro('O pacote traz um caminho inválido.');
        }
    }
    if (!in_array($partes[0], PBLX_ITENS, true)) {
        throw new PblxErro('O pacote traz algo fora do permitido.');
    }

    return $nome;
}

/** @return array<string, int> */
function pblx_desempacotar(string $formato, string $dados): array
{
    $r = @unpack($formato, $dados);
    if (!is_array($r)) {
        throw new PblxErro('O pacote tem um zip corrompido.');
    }

    return $r;
}

/** @return list<array{nome: string, metodo: int, crc: int, comp: int, desc: int, offset: int, atributos: int, feito: int, flags: int}> */
function pblx_zip_entradas(string $arquivo): array
{
    clearstatcache(true, $arquivo);
    $tam = (int) @filesize($arquivo);
    $f = @fopen($arquivo, 'rb');
    if ($tam < 22 || $f === false) {
        throw new PblxErro('O pacote não é um arquivo zip válido.');
    }
    $ler = min($tam, 65557);
    fseek($f, $tam - $ler);
    $cauda = (string) fread($f, $ler);
    $pos = strrpos($cauda, "PK\x05\x06");
    if ($pos === false || strlen($cauda) < $pos + 22) {
        fclose($f);
        throw new PblxErro('O pacote não é um arquivo zip válido.');
    }
    $e = pblx_desempacotar('vdisco/vdiscoCd/vnumDisco/vnum/VtamCd/VoffCd', substr($cauda, $pos + 4, 16));
    if ($e['disco'] !== 0 || $e['discoCd'] !== 0 || $e['num'] !== $e['numDisco'] || $e['num'] === 0xFFFF || $e['offCd'] === 0xFFFFFFFF
        || $e['num'] > PBLX_MAX_ARQUIVOS || $e['num'] < 2 || $e['offCd'] + $e['tamCd'] > $tam) {
        fclose($f);
        throw new PblxErro('O pacote tem uma estrutura de zip que o instalador não aceita.');
    }
    if ($e['tamCd'] < 46) {
        fclose($f);
        throw new PblxErro('O pacote tem o índice do zip corrompido.');
    }
    fseek($f, $e['offCd']);
    $cd = (string) fread($f, $e['tamCd']);
    fclose($f);
    $p = 0;
    $saida = [];
    $vistos = [];
    for ($i = 0; $i < $e['num']; $i++) {
        if (substr($cd, $p, 4) !== "PK\x01\x02" || strlen($cd) < $p + 46) {
            throw new PblxErro('O pacote tem o índice do zip corrompido.');
        }
        $h = pblx_desempacotar('vfeito/vprec/vflags/vmetodo/vhora/vdata/Vcrc/Vcomp/Vdesc/vnomeLen/vextraLen/vcomLen/vdisco/vattrInt/Vattr/Voffset', substr($cd, $p + 4, 42));
        $nome = substr($cd, $p + 46, $h['nomeLen']);
        $p += 46 + $h['nomeLen'] + $h['extraLen'] + $h['comLen'];
        if (strlen($nome) !== $h['nomeLen'] || $p > strlen($cd)) {
            throw new PblxErro('O pacote tem o índice do zip corrompido.');
        }
        if (isset($vistos[$nome])) {
            throw new PblxErro('O pacote repete um nome de arquivo.');
        }
        $vistos[$nome] = true;
        $saida[] = ['nome' => $nome, 'metodo' => $h['metodo'], 'crc' => $h['crc'], 'comp' => $h['comp'], 'desc' => $h['desc'], 'offset' => $h['offset'], 'atributos' => $h['attr'], 'feito' => $h['feito'], 'flags' => $h['flags']];
    }

    return $saida;
}

/** @param array{nome: string, metodo: int, crc: int, comp: int, desc: int, offset: int, atributos: int, feito: int, flags: int} $en */
function pblx_zip_ler(string $arquivo, array $en): string
{
    if (($en['flags'] & 1) !== 0 || !in_array($en['metodo'], [0, 8], true) || $en['desc'] > PBLX_MAX_ARQUIVO || $en['comp'] > PBLX_MAX_ARQUIVO) {
        throw new PblxErro('O pacote usa um recurso de zip que o instalador não aceita.');
    }
    if ($en['desc'] === 0) {
        return '';
    }
    if ($en['comp'] < 1) {
        throw new PblxErro('O pacote tem um arquivo corrompido.');
    }
    clearstatcache(true, $arquivo);
    $tam = (int) @filesize($arquivo);
    $f = @fopen($arquivo, 'rb');
    if ($f === false) {
        throw new PblxErro('Não foi possível ler o pacote.');
    }
    fseek($f, $en['offset']);
    $cab = (string) fread($f, 30);
    if (strlen($cab) !== 30 || substr($cab, 0, 4) !== "PK\x03\x04") {
        fclose($f);
        throw new PblxErro('O pacote tem um arquivo corrompido.');
    }
    $l = pblx_desempacotar('vnomeLen/vextraLen', substr($cab, 26, 4));
    $inicio = $en['offset'] + 30 + $l['nomeLen'] + $l['extraLen'];
    if ($inicio + $en['comp'] > $tam) {
        fclose($f);
        throw new PblxErro('O pacote tem um arquivo corrompido.');
    }
    fseek($f, $inicio);
    $bruto = (string) fread($f, $en['comp']);
    fclose($f);
    $dados = $en['metodo'] === 0 ? $bruto : @gzinflate($bruto, $en['desc']);
    if (!is_string($dados) || strlen($dados) !== $en['desc'] || (crc32($dados) & 0xFFFFFFFF) !== $en['crc']) {
        throw new PblxErro('O pacote tem um arquivo corrompido ou maior que o declarado.');
    }

    return $dados;
}

/**
 * Extrai o zip em $destino (pasta nova), recusando zip-slip, caminhos absolutos, links simbólicos, nomes fora de PBLX_ITENS e
 * excesso de arquivos/bytes; depois confere cada arquivo contra o PACOTE.json.
 */
function pblx_extrair(string $zip, string $destino, string $versaoEsperada): void
{
    $entradas = pblx_zip_entradas($zip);
    $controle = null;
    $total = 0;
    $obtidos = [];
    foreach ($entradas as $en) {
        $ehPasta = substr($en['nome'], -1) === '/';
        if ((($en['feito'] >> 8) === 3) && ((($en['atributos'] >> 16) & 0170000) === 0120000)) {
            throw new PblxErro('O pacote traz um link simbólico e foi recusado.');
        }
        if ($en['nome'] === PBLX_CONTROLE) {
            $controle = pblx_zip_ler($zip, $en);
            continue;
        }
        $limpo = pblx_nome_valido(rtrim($en['nome'], '/'));
        $alvo = $destino . '/' . $limpo;
        if ($ehPasta) {
            if (!is_dir($alvo) && !@mkdir($alvo, 0775, true) && !is_dir($alvo)) {
                throw new PblxErro('Não foi possível criar uma pasta do pacote.');
            }
            continue;
        }
        $total += $en['desc'];
        if ($total > PBLX_MAX_BYTES) {
            throw new PblxErro('O pacote descomprimido é maior que o permitido.');
        }
        $dados = pblx_zip_ler($zip, $en);
        $pasta = dirname($alvo);
        if (!is_dir($pasta) && !@mkdir($pasta, 0775, true) && !is_dir($pasta)) {
            throw new PblxErro('Não foi possível criar uma pasta do pacote.');
        }
        if (@file_put_contents($alvo, $dados) !== strlen($dados)) {
            throw new PblxErro('Não foi possível gravar um arquivo do pacote (confira o espaço em disco e as permissões).');
        }
        @chmod($alvo, strpos($limpo, 'bin/') === 0 ? 0755 : 0644);
        $obtidos[$limpo] = hash('sha256', $dados);
    }
    $d = $controle === null ? null : json_decode($controle, true);
    if (!is_array($d) || ($d['versao'] ?? null) !== $versaoEsperada || !is_array($d['arquivos'] ?? null)) {
        throw new PblxErro('O pacote não traz o PACOTE.json esperado para esta versão.');
    }
    if (count($d['arquivos']) !== count($obtidos)) {
        throw new PblxErro('O conteúdo do pacote não bate com a lista do PACOTE.json.');
    }
    foreach ($d['arquivos'] as $nome => $hash) {
        if (!is_string($hash) || !isset($obtidos[$nome]) || !hash_equals($hash, $obtidos[$nome])) {
            throw new PblxErro('Um arquivo do pacote não confere com o PACOTE.json.');
        }
    }
}

/** Apaga uma árvore sem seguir links simbólicos. */
function pblx_apagar(string $caminho): void
{
    if (is_link($caminho) || is_file($caminho)) {
        @unlink($caminho);

        return;
    }
    if (!is_dir($caminho)) {
        return;
    }
    foreach (scandir($caminho) ?: [] as $nome) {
        if ($nome !== '.' && $nome !== '..') {
            pblx_apagar($caminho . '/' . $nome);
        }
    }
    @rmdir($caminho);
}

/**
 * Move os itens extraídos para a pasta do site. Tudo ou nada: se um passo falhar, desfaz o que já moveu.
 *
 * @param callable|null $renomear só os testes passam outro (para simular falha no meio)
 */
function pblx_mover(string $extraido, string $dir, ?callable $renomear = null): void
{
    $renomear = $renomear ?? 'rename';
    $desfazer = [];
    try {
        foreach (PBLX_ITENS as $item) {
            if (!file_exists($extraido . '/' . $item)) {
                continue;
            }
            if (is_dir($extraido . '/' . $item) && file_exists($dir . '/' . $item)) {
                throw new PblxErro('A pasta "' . $item . '" já existe aqui. Este instalador só instala em uma pasta sem o Publicare.');
            }
            if (file_exists($dir . '/' . $item) || is_link($dir . '/' . $item)) {
                if (!$renomear($dir . '/' . $item, $dir . '/' . $item . '.antes-pbl4')) {
                    throw new PblxErro('Não foi possível guardar o arquivo "' . $item . '" que já existia.');
                }
                $desfazer[] = [$dir . '/' . $item . '.antes-pbl4', $dir . '/' . $item];
            }
            if (!$renomear($extraido . '/' . $item, $dir . '/' . $item)) {
                throw new PblxErro('Não foi possível colocar "' . $item . '" na pasta do site.');
            }
            $desfazer[] = [$dir . '/' . $item, $extraido . '/' . $item];
        }
    } catch (\Throwable $e) {
        foreach (array_reverse($desfazer) as $par) {
            @rename($par[0], $par[1]);
        }
        throw $e instanceof PblxErro ? $e : new PblxErro('A instalação foi interrompida e desfeita.');
    }
}

/**
 * Baixa, confere e instala o pacote na pasta do site. Em qualquer falha apaga o temporário e nada fica pela metade.
 *
 * @return array{versao: string, htaccess_antigo: bool}
 */
function pblx_instalar_pacote(string $dir, string $canal, ?callable $renomear = null): array
{
    if (is_file($dir . '/dados/config/instalado.lock')) {
        throw new PblxErro('Este site já está instalado.');
    }
    foreach (['app', 'vendor', 'bin', 'public'] as $pasta) {
        if (file_exists($dir . '/' . $pasta)) {
            throw new PblxErro('A pasta "' . $pasta . '" já existe aqui. Este instalador só instala em uma pasta sem o Publicare.');
        }
    }
    $tmp = $dir . '/.pbl-instalar-tmp-' . bin2hex(random_bytes(8));
    if (!@mkdir($tmp, 0700)) {
        throw new PblxErro('Não foi possível criar a pasta temporária (confira a permissão de escrita).');
    }
    try {
        $p = pblx_baixar_pacote($canal, $tmp);
        mkdir($tmp . '/x', 0700);
        pblx_extrair($p['zip'], $tmp . '/x', $p['versao']);
        $tinha = file_exists($dir . '/.htaccess');
        pblx_mover($tmp . '/x', $dir, $renomear);

        return ['versao' => $p['versao'], 'htaccess_antigo' => $tinha];
    } finally {
        pblx_apagar($tmp);
    }
}

// ---------------------------------------------------------------- layout, exposição e conclusão

/** 'hestia' (public_html com private/ ao lado), 'public' (esta pasta é a public/ de um projeto) ou 'raiz'. */
function pblx_layout(string $dir): string
{
    $nome = basename($dir);
    if ($nome === 'public' && is_file(dirname($dir) . '/app/src/Versao.php')) {
        return 'public';
    }
    if ($nome === 'public_html' && is_dir(dirname($dir) . '/private')) {
        return 'hestia';
    }

    return 'raiz';
}

/**
 * @param array<string, int|null> $status código HTTP de cada endereço interno testado (null = não deu para testar)
 * @return 'exposto'|'protegido'|'incerto'
 */
function pblx_decidir_exposicao(array $status): string
{
    $incerto = false;
    foreach ($status as $codigo) {
        if ($codigo === null) {
            $incerto = true;
        } elseif ($codigo >= 200 && $codigo < 300) {
            return 'exposto';
        }
    }

    return $incerto ? 'incerto' : 'protegido';
}

/** Pergunta ao próprio site se as pastas internas abrem pelo navegador. Só o código HTTP importa (nada do conteúdo é usado). */
function pblx_sondar(string $urlBase): string
{
    $status = [];
    foreach (['/app/src/Versao.php', '/dados/instalar.liberar'] as $caminho) {
        $status[$caminho] = null;
        $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 8],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $r = @fopen($urlBase . $caminho, 'rb', false, $ctx);
        if ($r !== false) {
            foreach (pblx_cabecalhos($r) as $linha) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linha, $m) === 1) {
                    $status[$caminho] = (int) $m[1];
                }
            }
            fclose($r);
        }
    }

    return pblx_decidir_exposicao($status);
}

/** O conteúdo de dados/.htaccess (o mesmo de Caminhos::protegerDados): nega tudo ao servidor web. */
const PBLX_HTACCESS_DADOS = "# Gerado pelo PBL4: nada daqui é público.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";

/**
 * Cria dados/ (0711: o servidor web atravessa a pasta para ler dados/.htaccess, mas ninguém lista; as subpastas ficam 0750 e o
 * banco e o segredo 0600) e passa a prova de posse para dados/instalar.liberar (o que o assistente web exige).
 */
function pblx_preparar_dados(string $dir, bool $moverPosse): void
{
    $dados = $dir . '/dados';
    if (!is_dir($dados) && !@mkdir($dados, 0711, true) && !is_dir($dados)) {
        throw new PblxErro('Não foi possível criar a pasta dados/.');
    }
    @chmod($dados, 0711);
    foreach (['config', 'cache', 'uploads', 'peles'] as $sub) {
        if (!is_dir($dados . '/' . $sub)) {
            @mkdir($dados . '/' . $sub, 0750, true);
        }
    }
    // Sem este arquivo a proteção por .htaccess só valeria depois do assistente; com umask restritivo ele nasceria ilegível ao Apache.
    if (!is_file($dados . '/.htaccess') && @file_put_contents($dados . '/.htaccess', PBLX_HTACCESS_DADOS) !== false) {
        @chmod($dados . '/.htaccess', 0644);
    }
    if ($moverPosse && is_file($dir . '/' . PBLX_POSSE) && !@rename($dir . '/' . PBLX_POSSE, $dados . '/' . PBLX_POSSE)) {
        throw new PblxErro('Não foi possível preparar a prova de posse para o assistente.');
    }
    if (!is_file($dados . '/' . PBLX_POSSE) && $moverPosse) {
        @touch($dados . '/' . PBLX_POSSE);
    }
}

/**
 * O servidor web (outro usuário, em hospedagem comum) consegue ler dados/.htaccess? Precisa do bit "outros" de leitura no arquivo
 * e de travessia (x) em dados/ (ou o mesmo grupo, o que não dá para ver daqui: nesse caso o aviso pode ser ignorado).
 *
 * @return bool|null null = não há .htaccess (ou não deu para ler o modo): nada a dizer
 */
function pblx_htaccess_dados_legivel(string $dir): ?bool
{
    $arquivo = $dir . '/dados/.htaccess';
    $modoArquivo = is_file($arquivo) ? @fileperms($arquivo) : false;
    $modoPasta = @fileperms($dir . '/dados');
    if ($modoArquivo === false || $modoPasta === false) {
        return null;
    }

    return ($modoArquivo & 0004) !== 0 && ($modoPasta & 0001) !== 0;
}

/** O aviso (HTML) da página de conclusão; vazio se o .htaccess de dados/ é legível ou não há o que dizer. */
function pblx_aviso_htaccess(string $dir): string
{
    if (pblx_htaccess_dados_legivel($dir) !== false) {
        return '';
    }

    return '<p class="av">⚠ O servidor web talvez não consiga ler <code>dados/.htaccess</code> (a pasta <code>dados/</code> ou o arquivo não é legível por outros usuários). No Apache isso gera o erro <code>AH00529</code> em cada pedido e a proteção de <code>dados/</code> pode não valer. '
        . 'Corrija com <code>chmod 711 dados</code> e <code>chmod 644 dados/.htaccess</code> (ou entregue <code>dados/</code> ao grupo do servidor web). O mais seguro é a raiz do site ser a pasta <code>public/</code>.</p>';
}

/**
 * Grava dados/config/atualizacao.json com o endereço e o canal que a instalação usou para baixar, para a tela Atualização já
 * nascer configurada. Não sobrescreve um arquivo existente (o dono pode ter ajustado). Só URL e canal: nada secreto.
 */
function pblx_gravar_atualizacao(string $dir, string $canal): void
{
    $pasta = $dir . '/dados/config';
    $arquivo = $pasta . '/atualizacao.json';
    if (is_file($arquivo)) {
        return;
    }
    if (!is_dir($pasta) && !@mkdir($pasta, 0750, true) && !is_dir($pasta)) {
        throw new PblxErro('Não foi possível criar a pasta dados/config/.');
    }
    $json = json_encode(['url' => pblx_url_manifesto($canal), 'canal' => $canal === 'beta' ? 'beta' : 'estavel', 'verificar' => true], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($arquivo, $json . "\n", LOCK_EX) === false) {
        throw new PblxErro('Não foi possível gravar dados/config/atualizacao.json.');
    }
    @chmod($arquivo, 0640);
}

// ---------------------------------------------------------------- páginas (web)

function pblx_h(string $t): string
{
    return htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pblx_pagina(string $titulo, string $corpo, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . pblx_h($titulo) . ' · Publicare 4</title><style>'
        . 'body{font:16px/1.5 system-ui,sans-serif;margin:0;background:#f4f5f7;color:#1c2330}main{max-width:44rem;margin:0 auto;padding:1rem}'
        . 'h1{font-size:1.5rem}.cartao{background:#fff;border:1px solid #d5d9e0;border-radius:8px;padding:1rem;margin:1rem 0}'
        . 'li{margin:.5rem 0}.ok{color:#146c2e}.no{color:#b3261e}.av{color:#8a5a00}code{background:#eef0f3;padding:0 .25rem;border-radius:4px;word-break:break-all}'
        . '.erro{background:#fdecea;border:1px solid #b3261e;padding:.75rem;border-radius:6px}.botao{font:inherit;background:#1d4ed8;color:#fff;border:0;border-radius:6px;padding:.75rem 1.25rem;min-height:44px;cursor:pointer;text-decoration:none;display:inline-block}'
        . '</style></head><body><main><h1>' . pblx_h($titulo) . '</h1>' . $corpo . '</main></body></html>';
}

/** @param list<array{nome: string, ok: bool, obrigatorio: bool, detalhe: string}> $itens */
function pblx_html_requisitos(array $itens): string
{
    $h = '<div class="cartao"><ul>';
    foreach ($itens as $i) {
        $classe = $i['ok'] ? 'ok' : ($i['obrigatorio'] ? 'no' : 'av');
        $marca = $i['ok'] ? '✔ Certo' : ($i['obrigatorio'] ? '✘ Corrigir' : '⚠ Aviso');
        $h .= '<li><strong class="' . $classe . '">' . $marca . '</strong> · <strong>' . pblx_h($i['nome']) . '</strong><br>' . pblx_h($i['detalhe']) . '</li>';
    }

    return $h . '</ul></div>';
}

function pblx_ja_instalado(string $dir): bool
{
    return is_file($dir . '/dados/config/instalado.lock');
}

function pblx_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

/** Cookie + campo oculto (sem sessão), comparados com hash_equals. */
function pblx_csrf_token(): string
{
    $t = isset($_COOKIE['pblx_csrf']) && is_string($_COOKIE['pblx_csrf']) && preg_match('/^[0-9a-f]{32}$/D', $_COOKIE['pblx_csrf']) === 1 ? $_COOKIE['pblx_csrf'] : bin2hex(random_bytes(16));
    setcookie('pblx_csrf', $t, ['path' => '/', 'secure' => pblx_https(), 'httponly' => true, 'samesite' => 'Strict']);

    return $t;
}

function pblx_csrf_confere(string $cookie, string $campo): bool
{
    return preg_match('/^[0-9a-f]{32}$/D', $cookie) === 1 && hash_equals($cookie, $campo);
}

/** O host (com porta, se houver) de uma URL de Origin/Referer, em minúsculas; null se não for uma URL com host (ex.: "null"). */
function pblx_host_da_url(string $url): ?string
{
    $h = $url === '' ? null : parse_url($url, PHP_URL_HOST);
    if (!is_string($h) || $h === '') {
        return null;
    }
    $porta = parse_url($url, PHP_URL_PORT);

    return strtolower($h . ($porta !== null && $porta !== false ? ':' . $porta : ''));
}

/**
 * Origin ou Referer precisa ser o mesmo host do site. Origin com host manda (de outro site, recusa mesmo que o Referer confira);
 * Origin vazio ou "null" (o navegador manda isso com certas políticas de Referrer) cai no Referer.
 */
function pblx_origem_confere(string $host, string $origin, string $referer): bool
{
    $esperado = strtolower($host);
    $deOrigin = pblx_host_da_url($origin);
    if ($deOrigin !== null) {
        return $deOrigin === $esperado;
    }

    return pblx_host_da_url($referer) === $esperado && $esperado !== '';
}

function pblx_web(string $dir): void
{
    @set_time_limit(300);
    $mem = (string) ini_get('memory_limit');
    if ($mem !== '-1' && (int) $mem > 0 && (int) $mem < 256 && substr(strtoupper($mem), -1) === 'M') {
        @ini_set('memory_limit', '256M');
    }
    if (pblx_ja_instalado($dir)) {
        pblx_pagina('Este site já está instalado', '<div class="cartao"><p>Por segurança, o instalador não roda em um site já instalado. <strong>Apague o arquivo <code>instalar.php</code></strong> da pasta do site.</p></div>', 403);

        return;
    }
    $itens = pblx_requisitos(pblx_ambiente($dir));
    if (!pblx_requisitos_ok($itens)) {
        pblx_pagina('Confira o servidor', pblx_html_requisitos($itens) . '<p class="erro">Corrija os itens marcados com ✘ e recarregue esta página.</p>');

        return;
    }
    $emDados = is_file($dir . '/dados/' . PBLX_POSSE);
    $posse = is_file($dir . '/' . PBLX_POSSE) || $emDados;
    $extraido = is_file($dir . '/app/src/Versao.php');
    if (!$posse) {
        pblx_pagina('Falta uma confirmação', pblx_html_requisitos($itens) . '<div class="cartao"><h2>Prove que o servidor é seu</h2><p>Para ninguém mais poder instalar no seu lugar, crie um arquivo <strong>vazio</strong> chamado <code>' . PBLX_POSSE
            . '</code> na mesma pasta onde está o <code>instalar.php</code> (pelo FTP ou pelo gerenciador de arquivos) e recarregue esta página.</p></div>');

        return;
    }
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($metodo !== 'POST') {
        $token = pblx_csrf_token();
        $beta = $extraido ? '' : '<p><label><input type="checkbox" name="canal" value="beta"> Usar a versão <strong>beta</strong> (de teste; só marque se alguém do Publicare pediu).</label></p>';
        $texto = $extraido ? '<p>O pacote já foi colocado nesta pasta, mas falta conferir se as pastas internas estão protegidas.</p>' : '<p>Vou baixar a versão estável mais recente (a dica era ' . pblx_h(PBLX_VERSAO_DICA) . '; vale a que o servidor oficial assinar), conferir a assinatura e instalar. Pode levar alguns minutos.</p>';
        pblx_pagina('Instalar o Publicare 4', pblx_html_requisitos($itens) . '<div class="cartao">' . $texto . '<form method="post"><input type="hidden" name="csrf" value="' . pblx_h($token) . '">'
            . '<input type="hidden" name="acao" value="' . ($extraido ? 'continuar' : 'instalar') . '">' . $beta . '<button class="botao" type="submit">' . ($extraido ? 'Conferir e continuar' : 'Baixar e instalar') . '</button></form></div>');

        return;
    }
    $cookie = isset($_COOKIE['pblx_csrf']) && is_string($_COOKIE['pblx_csrf']) ? $_COOKIE['pblx_csrf'] : '';
    $campo = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
    if (!pblx_csrf_confere($cookie, $campo) || !pblx_origem_confere((string) ($_SERVER['HTTP_HOST'] ?? ''), (string) ($_SERVER['HTTP_ORIGIN'] ?? ''), (string) ($_SERVER['HTTP_REFERER'] ?? ''))) {
        pblx_pagina('Pedido recusado', '<p class="erro">Não foi possível confirmar que o pedido veio desta página. Volte, recarregue e tente de novo.</p>', 403);

        return;
    }
    $acao = isset($_POST['acao']) && is_string($_POST['acao']) ? $_POST['acao'] : '';
    try {
        $versao = '';
        $canal = 'estavel';
        if ($acao === 'instalar' && !$extraido) {
            $canal = (isset($_POST['canal']) && $_POST['canal'] === 'beta') ? 'beta' : 'estavel';
            $versao = pblx_instalar_pacote($dir, $canal)['versao'];
        } elseif ($acao !== 'continuar' || !$extraido) {
            throw new PblxErro('Pedido inesperado. Recarregue a página.');
        }
        pblx_preparar_dados($dir, true);
        if ($acao === 'instalar') {
            pblx_gravar_atualizacao($dir, $canal);
        }
        $esquema = pblx_https() ? 'https://' : 'http://';
        $exposicao = pblx_sondar($esquema . (string) ($_SERVER['HTTP_HOST'] ?? ''));
        $layout = pblx_layout($dir);
        pblx_concluir_web($dir, $versao, $exposicao, $layout);
    } catch (PblxErro $e) {
        pblx_pagina('Não foi possível instalar', '<p class="erro">' . pblx_h($e->getMessage()) . '</p><p>Nada ficou pela metade. Corrija o que a mensagem pede e <a href="">tente de novo</a>.</p>', 500);
    } catch (\Throwable $e) {
        pblx_pagina('Não foi possível instalar', '<p class="erro">Ocorreu um erro inesperado. Nada foi instalado. Confira o espaço em disco e as permissões e tente de novo.</p>', 500);
    }
}

function pblx_concluir_web(string $dir, string $versao, string $exposicao, string $layout): void
{
    if ($exposicao === 'exposto') {
        pblx_pagina('Falta proteger as pastas internas', '<div class="cartao"><p class="erro"><strong>Atenção:</strong> as pastas internas (como <code>app/</code> e <code>dados/</code>) abrem pelo navegador neste servidor. Isso é perigoso: o assistente NÃO será liberado até corrigir.</p>'
            . '<ol><li><strong>Melhor:</strong> no painel da hospedagem, mude a "raiz do documento" (pasta do site) deste domínio para a subpasta <code>public/</code>.</li>'
            . '<li>Em servidor nginx, bloqueie <code>app/</code>, <code>dados/</code>, <code>vendor/</code> e <code>bin/</code> (veja o guia de instalação).</li>'
            . '<li>Em Apache, a hospedagem precisa permitir <code>.htaccess</code> (AllowOverride).</li></ol><p>Depois recarregue esta página e use "Conferir e continuar".</p></div>', 200);

        return;
    }
    $aviso = pblx_aviso_htaccess($dir);
    if ($exposicao === 'incerto') {
        $aviso .= '<p class="av">⚠ Não consegui conferir sozinho se as pastas internas estão protegidas. Depois de instalar, abra <code>/dados/</code> e <code>/app/</code> no navegador: devem dar "proibido" ou "não encontrado". O ideal é a raiz do site ser a pasta <code>public/</code>.</p>';
    }
    if ($layout === 'hestia') {
        $aviso .= '<p class="av">⚠ Painel Hestia/VestaCP detectado: o mais seguro é colocar o Publicare em <code>private/</code> e apontar <code>public_html</code> para a pasta <code>public/</code> dele (veja "Hestia" no guia de instalação).</p>';
    }
    $aviso .= '<p>Como a raiz do site é esta pasta, o arquivo <code>.htaccess</code> do pacote protege as pastas internas e manda tudo para <code>public/</code>. Se puder, aponte a raiz do site para <code>public/</code>: é mais seguro.</p>';
    @unlink(__FILE__);
    $apagou = !is_file(__FILE__);
    $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    pblx_pagina('Pacote instalado', '<div class="cartao"><p class="ok"><strong>✔ Pacote ' . pblx_h($versao === '' ? '' : $versao . ' ') . 'conferido (assinatura e SHA-256) e instalado.</strong></p>' . $aviso
        . ($apagou ? '<p>O <code>instalar.php</code> já se apagou.</p>' : '<p class="erro"><strong>Apague agora o arquivo <code>instalar.php</code></strong> da pasta do site (não foi possível apagá-lo sozinho).</p>')
        . '<p><a class="botao" href="' . pblx_h($base . '/_pbl/instalar') . '">Continuar: configurar o site (etapa 2)</a></p></div>');
}

// ---------------------------------------------------------------- terminal

/** @param list<string> $args */
function pblx_cli(string $dir, array $args): int
{
    $canal = in_array('--beta', $args, true) ? 'beta' : 'estavel';
    $linha = static function (string $t): void {
        fwrite(STDOUT, $t . "\n");
    };
    if (pblx_ja_instalado($dir)) {
        $linha('Este site já está instalado. Nada a fazer.');

        return 1;
    }
    $itens = pblx_requisitos(pblx_ambiente($dir));
    foreach ($itens as $i) {
        if (!$i['ok']) {
            $linha(($i['obrigatorio'] ? '[X] ' : '[!] ') . $i['nome'] . ': ' . $i['detalhe']);
        }
    }
    if (!pblx_requisitos_ok($itens)) {
        return 1;
    }
    try {
        $linha('Baixando e conferindo a assinatura (canal ' . $canal . ')...');
        $r = pblx_instalar_pacote($dir, $canal);
        pblx_preparar_dados($dir, false);
        pblx_gravar_atualizacao($dir, $canal);
        $linha('Pacote ' . $r['versao'] . ' conferido e instalado.');
        if (pblx_htaccess_dados_legivel($dir) === false) {
            $linha('[!] O servidor web talvez não consiga ler dados/.htaccess (erro AH00529 no Apache). Use: chmod 711 dados && chmod 644 dados/.htaccess');
        }
        @unlink(__FILE__);

        return 0;
    } catch (PblxErro $e) {
        fwrite(STDERR, 'Erro: ' . $e->getMessage() . "\n");

        return 1;
    }
}

// Só roda quando o arquivo é aberto diretamente (nunca quando incluído por outro script).
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    if (PHP_SAPI === 'cli') {
        exit(pblx_cli(__DIR__, array_values(array_slice($_SERVER['argv'] ?? [], 1))));
    }
    if (version_compare(PHP_VERSION, '8.2.0', '<')) {
        pblx_pagina('Seu PHP é antigo demais', '<p class="erro">Este servidor tem o PHP ' . pblx_h(PHP_VERSION) . '. O Publicare 4 precisa do PHP 8.2 ou mais novo: no painel da hospedagem procure "versão do PHP" e escolha 8.2 ou mais novo (de preferência 8.3).</p>', 500);
    } else {
        pblx_web(__DIR__);
    }
}
