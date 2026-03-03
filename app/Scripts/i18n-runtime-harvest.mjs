#!/usr/bin/env node

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from '@playwright/test';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const projectRoot = path.resolve(__dirname, '..', '..');
const outputDir = path.join(projectRoot, 'storage', 'i18n');
const outputPath = path.join(outputDir, 'runtime-findings.json');

const baseUrl = process.env.WBGL_BASE_URL || 'http://localhost:8181';
const storageState = process.env.WBGL_PLAYWRIGHT_STORAGE_STATE || '';
const headless = process.env.WBGL_HEADLESS !== '0';
const maxRecordsPerPage = Number.parseInt(process.env.WBGL_I18N_MAX_PER_PAGE || '3000', 10);

const defaultRoutes = [
  '/index.php',
  '/views/batches.php',
  '/views/settings.php',
  '/views/users.php',
  '/views/statistics.php',
];

const routes = parseRoutes(process.env.WBGL_I18N_URLS, defaultRoutes);

await fs.mkdir(outputDir, { recursive: true });

const payload = {
  generated_at: new Date().toISOString(),
  base_url: baseUrl,
  routes,
  summary: {
    total_routes: routes.length,
    successful_routes: 0,
    failed_routes: 0,
    total_records: 0,
    untranslated_records: 0,
  },
  pages: [],
  errors: [],
};

let browser;
try {
  browser = await chromium.launch({ headless });
  const contextOptions = {};
  if (storageState) {
    contextOptions.storageState = storageState;
  }
  const context = await browser.newContext(contextOptions);

  for (const route of routes) {
    const targetUrl = toAbsoluteUrl(baseUrl, route);
    const page = await context.newPage();
    try {
      await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 45000 });
      await page.waitForTimeout(1200);

      const harvested = await page.evaluate(({ maxRecords }) => {
        function normalizeText(value) {
          return String(value || '').replace(/\s+/g, ' ').trim();
        }

        function hasHumanLetters(value) {
          return /[\u0600-\u06FFA-Za-z]/.test(value);
        }

        function isIgnoredRuntimeToken(text, nodePath = '') {
          const normalizedText = String(text || '').trim();
          const normalizedPath = String(nodePath || '');

          // UI badge short codes that are intentionally dynamic (e.g. AR/AUTO/SYS).
          if (/^[A-Z]{2,4}$/.test(normalizedText)) {
            if (/wbgl-(lang|direction|theme)-current/.test(normalizedPath)) {
              return true;
            }
          }

          return false;
        }

        function isVisible(element) {
          if (!element) {
            return false;
          }
          if (element.closest('[hidden], [aria-hidden="true"]')) {
            return false;
          }
          const style = window.getComputedStyle(element);
          if (!style || style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) === 0) {
            return false;
          }
          const rect = element.getBoundingClientRect();
          return rect.width > 0 && rect.height > 0;
        }

        function cssPath(element) {
          if (!(element instanceof Element)) {
            return '';
          }
          const parts = [];
          let node = element;
          let depth = 0;
          while (node && depth < 6) {
            let part = node.tagName.toLowerCase();
            if (node.id) {
              part += `#${node.id}`;
              parts.unshift(part);
              break;
            }
            const className = String(node.className || '')
              .trim()
              .split(/\s+/)
              .filter(Boolean)
              .slice(0, 1)
              .join('.');
            if (className) {
              part += `.${className}`;
            }
            const parent = node.parentElement;
            if (parent) {
              const siblings = Array.from(parent.children).filter((item) => item.tagName === node.tagName);
              if (siblings.length > 1) {
                part += `:nth-of-type(${siblings.indexOf(node) + 1})`;
              }
            }
            parts.unshift(part);
            node = parent;
            depth += 1;
          }
          return parts.join(' > ');
        }

        function hasI18nBinding(element, attrName = '') {
          if (!(element instanceof Element)) {
            return false;
          }
          if (element.closest('[data-i18n]')) {
            return true;
          }
          if (attrName === 'placeholder' && element.closest('[data-i18n-placeholder]')) {
            return true;
          }
          if (attrName === 'title' && element.closest('[data-i18n-title]')) {
            return true;
          }
          if (attrName === 'content' && element.closest('[data-i18n-content]')) {
            return true;
          }
          return false;
        }

        const items = [];
        const seen = new Set();

        function pushItem(record) {
          const key = `${record.kind}|${record.attr || ''}|${record.text}|${record.node_path}`;
          if (seen.has(key)) {
            return;
          }
          seen.add(key);
          items.push(record);
        }

        const root = document.body || document.documentElement;
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        let node = walker.nextNode();
        while (node) {
          const parent = node.parentElement;
          if (parent && !parent.closest('script,style,noscript,svg,canvas,head') && isVisible(parent)) {
            const text = normalizeText(node.nodeValue);
            if (text.length >= 2 && hasHumanLetters(text)) {
              const nodePath = cssPath(parent);
              if (isIgnoredRuntimeToken(text, nodePath)) {
                node = walker.nextNode();
                continue;
              }
              pushItem({
                kind: 'text',
                attr: null,
                text,
                node_path: nodePath,
                has_i18n_binding: hasI18nBinding(parent),
              });
            }
          }
          if (items.length >= maxRecords) {
            break;
          }
          node = walker.nextNode();
        }

        const attributes = ['title', 'placeholder', 'aria-label', 'content'];
        for (const attr of attributes) {
          const nodes = document.querySelectorAll(`[${attr}]`);
          for (const element of nodes) {
            if (!(element instanceof Element) || !isVisible(element)) {
              continue;
            }
            const value = normalizeText(element.getAttribute(attr));
            if (value.length < 2 || !hasHumanLetters(value)) {
              continue;
            }
            const nodePath = cssPath(element);
            if (isIgnoredRuntimeToken(value, nodePath)) {
              continue;
            }
            pushItem({
              kind: 'attr',
              attr,
              text: value,
              node_path: nodePath,
              has_i18n_binding: hasI18nBinding(element, attr),
            });
            if (items.length >= maxRecords) {
              break;
            }
          }
          if (items.length >= maxRecords) {
            break;
          }
        }

        const untranslated = items.filter((item) => item.has_i18n_binding === false).length;
        return {
          href: window.location.href,
          records: items,
          stats: {
            total: items.length,
            untranslated,
          },
        };
      }, { maxRecords: maxRecordsPerPage });

      payload.pages.push({
        route,
        url: harvested.href,
        stats: harvested.stats,
        records: harvested.records,
      });
      payload.summary.successful_routes += 1;
      payload.summary.total_records += harvested.stats.total;
      payload.summary.untranslated_records += harvested.stats.untranslated;
      console.log(`HARVEST_OK: ${route} | total=${harvested.stats.total} | untranslated=${harvested.stats.untranslated}`);
    } catch (error) {
      const message = String(error && error.message ? error.message : error);
      payload.summary.failed_routes += 1;
      payload.errors.push({
        route,
        url: targetUrl,
        error: message,
      });
      console.error(`HARVEST_FAIL: ${route} | ${message}`);
    } finally {
      await page.close();
    }
  }

  await context.close();
} catch (error) {
  const message = String(error && error.message ? error.message : error);
  payload.summary.failed_routes = routes.length;
  payload.errors.push({
    route: '*',
    url: baseUrl,
    error: message,
  });
  console.error(`RUNTIME_HARVEST_FATAL: ${message}`);
} finally {
  if (browser) {
    await browser.close();
  }
}

payload.generated_at = new Date().toISOString();
await fs.writeFile(outputPath, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');

console.log(`RUNTIME_FINDINGS_JSON: ${outputPath}`);
console.log(`ROUTES_SUCCESS: ${payload.summary.successful_routes}/${payload.summary.total_routes}`);
console.log(`TOTAL_RECORDS: ${payload.summary.total_records}`);
console.log(`UNTRANSLATED_RECORDS: ${payload.summary.untranslated_records}`);

if (payload.summary.successful_routes === 0) {
  process.exitCode = 1;
}

function parseRoutes(raw, fallback) {
  if (!raw || String(raw).trim() === '') {
    return fallback;
  }
  const values = String(raw)
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean);
  return values.length ? values : fallback;
}

function toAbsoluteUrl(base, route) {
  try {
    return new URL(route, base).toString();
  } catch {
    return `${base.replace(/\/+$/, '')}/${String(route).replace(/^\/+/, '')}`;
  }
}
