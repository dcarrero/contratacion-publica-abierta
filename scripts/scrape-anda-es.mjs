#!/usr/bin/env node

/**
 * Scrape Andalucía PDC ElasticSearch API using headless browser.
 *
 * Strategy: the API proxy limits from+size to 10K and ignores search_after/range.
 * But match filters on perfilContratante.codigo DO work.
 * We partition the 857K records by perfil contratante (552 perfiles, avg ~1500 each).
 *
 * Usage:
 *   node scripts/scrape-anda-es.mjs
 *   node scripts/scrape-anda-es.mjs --resume     # skip already downloaded perfiles
 *
 * Output: storage/app/anda-enrichment/anda-all.json (consolidated)
 *         storage/app/anda-enrichment/progress.json  (resume state)
 */

import puppeteer from 'puppeteer';
import fs from 'fs';
import path from 'path';

const PORTAL_URL = 'https://www.juntadeandalucia.es/haciendayadministracionpublica/apl/pdc-front-publico/perfiles-licitaciones/contratos-menores';
const ES_PERFILES = '/haciendayadministracionpublica/apl/pdc-front-publico/elastic/sirec_pdc_perfiles_contratante/_search?pretty';
const ES_EXPEDIENTES = '/haciendayadministracionpublica/apl/pdc-front-publico/elastic/sirec_pdc_expedientes/_search?pretty';
const OUTPUT_DIR = 'storage/app/anda-enrichment';
const OUTPUT_FILE = path.join(OUTPUT_DIR, 'anda-all.json');
const PROGRESS_FILE = path.join(OUTPUT_DIR, 'progress.json');
const PAGE_SIZE = 10000;
const DELAY_MS = 800;
const SESSION_REFRESH_EVERY = 120; // refresh session every N requests
const MAX_RETRIES = 3;

async function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

function loadProgress() {
  try {
    return JSON.parse(fs.readFileSync(PROGRESS_FILE, 'utf-8'));
  } catch {
    return { completedPerfiles: [], records: [], seenIds: {} };
  }
}

function saveProgress(progress) {
  fs.writeFileSync(PROGRESS_FILE, JSON.stringify({
    completedPerfiles: progress.completedPerfiles,
    recordCount: progress.records.length,
    seenIdCount: Object.keys(progress.seenIds).length,
    lastUpdate: new Date().toISOString(),
  }));
  // Save records incrementally
  fs.writeFileSync(OUTPUT_FILE, JSON.stringify(progress.records));
}

async function navigateToPortal(page) {
  console.log('  [session] Navigating to portal...');
  await page.goto(PORTAL_URL, { waitUntil: 'networkidle2', timeout: 60000 });
  await sleep(2000);
  console.log('  [session] Ready');
}

async function queryES(page, endpoint, body, retryCount = 0) {
  try {
    const result = await page.evaluate(async (ep, bodyStr) => {
      try {
        const resp = await fetch(ep, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: bodyStr,
        });
        if (!resp.ok) return { error: `HTTP ${resp.status}` };
        return await resp.json();
      } catch (e) {
        return { error: e.message };
      }
    }, endpoint, JSON.stringify(body));

    if (result.error) {
      if (retryCount < MAX_RETRIES) {
        console.warn(`    Retry ${retryCount + 1}/${MAX_RETRIES}: ${result.error}`);
        if (result.error.includes('50') || result.error.includes('40') || result.error.includes('Failed')) {
          await navigateToPortal(page);
        }
        await sleep(2000 * (retryCount + 1));
        return queryES(page, endpoint, body, retryCount + 1);
      }
      throw new Error(`Query failed: ${result.error}`);
    }
    return result;
  } catch (err) {
    if (retryCount < MAX_RETRIES) {
      console.warn(`    Exception retry ${retryCount + 1}: ${err.message}`);
      await sleep(3000);
      return queryES(page, endpoint, body, retryCount + 1);
    }
    throw err;
  }
}

function extractRecord(hit) {
  const s = hit._source;
  const record = {
    id: s.idExpediente,
    exp: s.numeroExpediente,
    fecha: s.fechaPublicacion,
  };
  // Also extract adjudicacion date if available and no fechaPublicacion
  if (!s.fechaPublicacion && s.adjudicaciones?.[0]?.fechaFormalizacion) {
    record.fechaAdj = s.adjudicaciones[0].fechaFormalizacion;
  }
  return record;
}

async function downloadPerfil(page, codigo, progress) {
  const query = {
    query: {
      bool: {
        must: [[{ match: { 'perfilContratante.codigo': codigo } }]],
        must_not: [],
        should: [],
      },
    },
    sort: [{ idExpediente: 'desc' }],
    track_total_hits: true,
    size: PAGE_SIZE,
    from: 0,
  };

  const result = await queryES(page, ES_EXPEDIENTES, query);
  const total = result.hits?.total?.value || result.hits?.total || 0;
  const hits = result.hits?.hits || [];

  let added = 0;
  for (const hit of hits) {
    const id = hit._source?.idExpediente;
    if (id && !progress.seenIds[id]) {
      progress.seenIds[id] = 1;
      progress.records.push(extractRecord(hit));
      added++;
    }
  }

  if (total > PAGE_SIZE) {
    // Need sub-partitioning by codigoProcedimiento
    const procedures = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'];
    for (const proc of procedures) {
      const subQuery = {
        query: {
          bool: {
            must: [
              [{ match: { 'perfilContratante.codigo': codigo } }],
              [{ match: { codigoProcedimiento: proc } }],
            ],
            must_not: [],
            should: [],
          },
        },
        sort: [{ idExpediente: 'desc' }],
        track_total_hits: true,
        size: PAGE_SIZE,
        from: 0,
      };

      await sleep(DELAY_MS);
      const subResult = await queryES(page, ES_EXPEDIENTES, subQuery);
      const subTotal = subResult.hits?.total?.value || 0;
      const subHits = subResult.hits?.hits || [];

      for (const hit of subHits) {
        const id = hit._source?.idExpediente;
        if (id && !progress.seenIds[id]) {
          progress.seenIds[id] = 1;
          progress.records.push(extractRecord(hit));
          added++;
        }
      }

      if (subTotal > PAGE_SIZE) {
        console.warn(`    WARNING: ${codigo}/proc=${proc} has ${subTotal} records (truncated to ${PAGE_SIZE})`);
      }
    }
  }

  return { total, added };
}

async function main() {
  fs.mkdirSync(OUTPUT_DIR, { recursive: true });

  console.log('='.repeat(60));
  console.log('Andalucia PDC ElasticSearch Scraper (by perfil)');
  console.log('='.repeat(60));

  // Load progress
  const progress = loadProgress();
  const resuming = progress.completedPerfiles.length > 0;
  if (resuming) {
    console.log(`Resuming: ${progress.completedPerfiles.length} perfiles done, ${progress.records.length} records`);
  }

  // Launch browser
  const browser = await puppeteer.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
  });
  const page = await browser.newPage();
  await page.setUserAgent(
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
  );

  try {
    await navigateToPortal(page);

    // Get total count
    const countResult = await queryES(page, ES_EXPEDIENTES, {
      query: { match_all: {} },
      track_total_hits: true,
      size: 0,
    });
    const totalInIndex = countResult.hits?.total?.value || 0;
    console.log(`\nTotal records in index: ${totalInIndex.toLocaleString()}`);

    // Get all perfil codes
    const perfilesResult = await queryES(page, ES_PERFILES, {
      size: 10000,
      query: { match_all: {} },
    });
    const perfilCodes = perfilesResult.hits.hits
      .map(h => h._source.code)
      .filter(Boolean);
    console.log(`Perfil contratante codes: ${perfilCodes.length}`);

    // Filter out already completed
    const completedSet = new Set(progress.completedPerfiles);
    const remaining = perfilCodes.filter(c => !completedSet.has(c));
    console.log(`Remaining to download: ${remaining.length}`);
    console.log('');

    let requestCount = 0;
    const startTime = Date.now();

    for (let i = 0; i < remaining.length; i++) {
      const codigo = remaining[i];

      // Periodic session refresh
      if (requestCount > 0 && requestCount % SESSION_REFRESH_EVERY === 0) {
        await navigateToPortal(page);
      }

      try {
        const { total, added } = await downloadPerfil(page, codigo, progress);
        requestCount++;

        const pct = ((progress.completedPerfiles.length + 1) / perfilCodes.length * 100).toFixed(1);
        const elapsed = ((Date.now() - startTime) / 1000).toFixed(0);
        console.log(
          `[${pct}%] ${codigo}: ${total} total, +${added} new ` +
          `(${progress.records.length.toLocaleString()} total, ${elapsed}s)`
        );

        // Mark completed
        progress.completedPerfiles.push(codigo);

        // Save progress every 20 perfiles
        if ((progress.completedPerfiles.length) % 20 === 0) {
          saveProgress(progress);
          console.log(`  [saved] ${progress.records.length.toLocaleString()} records`);
        }

        await sleep(DELAY_MS);
      } catch (err) {
        console.error(`  ERROR on ${codigo}: ${err.message}`);
        // Save progress and continue
        saveProgress(progress);
        // Try refreshing session
        try { await navigateToPortal(page); } catch {}
        await sleep(5000);
      }
    }

    // Final save
    saveProgress(progress);

    const elapsed = ((Date.now() - startTime) / 1000).toFixed(1);
    const uniqueIds = Object.keys(progress.seenIds).length;
    console.log('\n' + '='.repeat(60));
    console.log(`Done! ${progress.records.length.toLocaleString()} records (${uniqueIds.toLocaleString()} unique IDs)`);
    console.log(`Time: ${elapsed}s, Requests: ~${requestCount}`);
    console.log(`Output: ${OUTPUT_FILE}`);
    console.log('='.repeat(60));
  } catch (err) {
    console.error('Fatal error:', err.message);
    saveProgress(progress);
    process.exit(1);
  } finally {
    await browser.close();
  }
}

main();
