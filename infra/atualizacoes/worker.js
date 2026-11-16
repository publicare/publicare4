// Endpoint de atualizações do Publicare 4: um proxy estático na frente dos GitHub Releases (ADR 0019, docs/tecnica/atualizacao.md).
// O cliente (BaixadorHttps) recusa redirecionamento para outro host: este Worker segue o redirecionamento do GitHub NO SERVIDOR e
// devolve manifesto, assinatura e pacote sob o MESMO host. Ele não é de confiança: a assinatura Ed25519 do manifesto e o SHA-256 do
// pacote são conferidos pela instalação.
//
// Rotas (só GET e HEAD):
//   /latest.json, /latest.json.sig            -> assets da última release ESTÁVEL
//   /latest-beta.json, /latest-beta.json.sig  -> assets da última PRÉ-LANÇAMENTO publicada
//   /pacotes/pbl4-<versão>.zip                -> asset da release v<versão>
//   /instalar.php, /instalar.sh               -> instaladores da última release ESTÁVEL (servidos como text/plain: nunca executados aqui)

const NOME_PACOTE = /^pbl4-(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)\.zip$/;
const CANAIS = {
  '/latest.json': 'estavel',
  '/latest.json.sig': 'estavel',
  '/latest-beta.json': 'beta',
  '/latest-beta.json.sig': 'beta',
};
const INSTALADORES = ['/instalar.php', '/instalar.sh'];
const PADRAO_REPO = /^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/;

function resposta(corpo, status, extra = {}) {
  return new Response(corpo, { status, headers: { 'X-Content-Type-Options': 'nosniff', ...extra } });
}

/** Tag da última pré-lançamento publicada (a API devolve a mais recente primeiro). Sem token são 60 req/h por IP: por isso o cache. */
async function tagDoBeta(repo, env, ttl) {
  const cabecalhos = { Accept: 'application/vnd.github+json', 'User-Agent': 'publicare4-atualizacoes' };
  if (env.GITHUB_TOKEN) cabecalhos.Authorization = `Bearer ${env.GITHUB_TOKEN}`; // opcional, secret do Worker (nunca no wrangler.toml)
  const r = await fetch(`https://api.github.com/repos/${repo}/releases?per_page=30`, {
    headers: cabecalhos,
    cf: { cacheTtl: ttl, cacheEverything: true },
  });
  if (!r.ok) return null;
  const lista = await r.json();
  const achada = Array.isArray(lista) ? lista.find((x) => x && x.prerelease === true && x.draft === false && /^v[0-9][0-9A-Za-z.-]*$/.test(x.tag_name)) : null;
  return achada ? achada.tag_name : null;
}

async function origem(url, ttl, cacheControl, tipo = 'application/octet-stream') {
  const r = await fetch(url, {
    redirect: 'follow', // segue o redirecionamento do GitHub no servidor
    headers: { 'User-Agent': 'publicare4-atualizacoes' }, // não repassa cookies nem cabeçalhos do cliente
    cf: { cacheTtl: ttl, cacheEverything: true },
  });
  if (r.status === 404) return resposta('Não encontrado', 404);
  if (!r.ok) return resposta('Origem indisponível', 502);
  const h = { 'Cache-Control': cacheControl, 'Content-Type': tipo };
  const tamanho = r.headers.get('Content-Length');
  if (tamanho) h['Content-Length'] = tamanho;
  return resposta(r.body, 200, h);
}

export default {
  async fetch(request, env) {
    if (request.method !== 'GET' && request.method !== 'HEAD') return resposta('Método não permitido', 405, { Allow: 'GET, HEAD' });
    const repo = env.GITHUB_REPO;
    if (typeof repo !== 'string' || !PADRAO_REPO.test(repo)) return resposta('Worker mal configurado', 500);
    const segundos = Math.max(60, Math.min(1800, parseInt(env.CACHE_MANIFESTO_SEGUNDOS, 10) || 300));
    const caminho = new URL(request.url).pathname;
    const base = `https://github.com/${repo}/releases`;
    const manifesto = `public, max-age=${segundos}`;

    let alvo = null;
    if (Object.hasOwn(CANAIS, caminho)) {
      const arquivo = caminho.slice(1);
      if (CANAIS[caminho] === 'estavel') {
        alvo = `${base}/latest/download/${arquivo}`;
      } else {
        const tag = await tagDoBeta(repo, env, segundos);
        if (!tag) return resposta('Não encontrado', 404);
        alvo = `${base}/download/${tag}/${arquivo}`;
      }
      return origem(alvo, segundos, manifesto, arquivo.endsWith('.json') ? 'application/json' : 'text/plain');
    }
    if (INSTALADORES.includes(caminho)) {
      // text/plain + nosniff: o navegador mostra/baixa o arquivo, e o Worker nunca o interpreta. O SHA-256 vem no latest.json assinado.
      return origem(`${base}/latest/download/${caminho.slice(1)}`, segundos, manifesto, 'text/plain; charset=utf-8');
    }
    if (caminho.startsWith('/pacotes/')) {
      const arquivo = caminho.slice('/pacotes/'.length);
      const m = NOME_PACOTE.exec(arquivo);
      if (!m) return resposta('Não encontrado', 404); // também barra "..", "/" e "%"
      return origem(`${base}/download/v${m[1]}/${arquivo}`, 31536000, 'public, max-age=31536000, immutable');
    }
    return resposta('Não encontrado', 404);
  },
};
