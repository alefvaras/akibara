#!/usr/bin/env node
/**
 * Latencia del autocompletado, medida desde fuera como la paga el cliente.
 *
 * Sirve para comparar versiones del buscador (antes y después) con las MISMAS
 * consultas, en el espejo o en producción. No escribe nada.
 *
 * Trampas ya medidas y evitadas (2026-09-14):
 * - el suggest limita a 60/min por IP: se reparte entre dos clientes con IP
 *   distinta (contenedor cron y contenedor web) y cada uno va bajo el tope;
 * - un 429 no es latencia del buscador: se cuenta aparte y no entra en p50/p95;
 * - `kill()` sobre `docker compose exec` no mata el bucle de dentro: no hay
 *   bucles, cada muestra es una llamada.
 *
 * Uso:
 *   node tools/akibara-releases/business-qa/qa-buscador-latencia.mjs          # espejo
 *   AKB_PROD=1 node …/qa-buscador-latencia.mjs                               # producción (una IP, 50 muestras)
 */
import { execFileSync } from 'node:child_process';

const PROD = '1' === process.env.AKB_PROD;
const CWD = '/Users/alefvaras/Documents/akibara';
const CONSULTAS = ['naruto', 'one piece', 'berserk', 'chainsaw', 'jujutsu', 'dragon ball', 'spy family', 'frieren', 'kimetsu', 'blue lock'];
const MUESTRAS_POR_CLIENTE = PROD ? 50 : 45;

/** Una llamada: devuelve [código, segundos]. */
const llamada = (cliente, q) => {
  const url = `/wp-json/akibara/v1/search/suggest?q=${encodeURIComponent(q)}`;
  if (PROD) {
    const out = execFileSync('curl', ['-s', '-o', '/dev/null', '-w', '%{http_code} %{time_total}', '-A', 'AkibaraQA', `https://akibara.cl${url}&lat=${Math.random()}`], { encoding: 'utf8' });
    return out.trim().split(' ').map(Number);
  }
  const destino = 'cron' === cliente ? 'http://wordpress' : 'http://127.0.0.1';
  const out = execFileSync('docker', ['compose', 'exec', '-T', cliente, 'curl', '-s', '-o', '/dev/null', '-w', '%{http_code} %{time_total}', '-H', 'Host: localhost:8080', `${destino}${url}`], { cwd: CWD, encoding: 'utf8' });
  return out.trim().split(' ').map(Number);
};

const percentil = (lista, p) => {
  if (! lista.length) { return NaN; }
  const orden = [...lista].sort((a, b) => a - b);
  return orden[Math.min(orden.length - 1, Math.floor((p / 100) * orden.length))];
};

const clientes = PROD ? ['host'] : ['cron', 'wordpress'];
const tiempos = [];
const codigos = {};
for (let i = 0; i < MUESTRAS_POR_CLIENTE; i += 1) {
  for (const cliente of clientes) {
    const [codigo, s] = llamada(cliente, CONSULTAS[i % CONSULTAS.length]);
    codigos[codigo] = (codigos[codigo] || 0) + 1;
    if (200 === codigo) { tiempos.push(s * 1000); }
  }
  // Bajo el tope de 60/min por IP incluso sumando el tiempo de cada llamada.
  await new Promise((r) => setTimeout(r, 1100));
}

const ms = (v) => `${Math.round(v)} ms`;
console.log(`${PROD ? 'producción' : 'espejo'} · ${tiempos.length} respuestas 200 · códigos ${JSON.stringify(codigos)}`);
console.log(`p50 ${ms(percentil(tiempos, 50))} · p90 ${ms(percentil(tiempos, 90))} · p95 ${ms(percentil(tiempos, 95))} · máx ${ms(Math.max(...tiempos))}`);
const noOk = Object.entries(codigos).filter(([c]) => '200' !== c).reduce((a, [, n]) => a + n, 0);
if (noOk) { console.log(`aviso: ${noOk} respuestas no-200 (no entran en los percentiles)`); }
