const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const page = read('index.php');
const app = read('assets/app.js');
const styles = read('assets/redesign.css');
const settings = read('partials_modals.php');

test('period and direction filters share one row, with a mobile directions control', () => {
  assert.match(page, /class="filters-row"[\s\S]*?id="periods"[\s\S]*?id="category-toggle"[\s\S]*?id="chips"/);
  assert.match(page, /id="category-toggle"[^>]*aria-expanded="false"[^>]*aria-controls="chips"/);
  assert.match(page, /id="category-toggle"[\s\S]*?Направления/);
  assert.match(styles, /\.filters-row\{display:flex/);
  assert.match(styles, /\.category-toggle\{display:inline-flex/);
  assert.match(styles, /\.category-filter\.is-open \.chips\{display:flex\}/);
});

test('search matches topic, lecturer, and localized or numeric dates across all query terms', () => {
  const start = app.indexOf('function webinarSearchText(w)');
  const end = app.indexOf('function matchesCat(w)', start);
  assert.notEqual(start, -1);
  assert.notEqual(end, -1);
  const searchSource = app.slice(start, end);
  const makeSearch = new Function('MONTHS', 'MONTHS_NOMINATIVE', `${searchSource}; let searchTerm = ''; return { search(w, term) { searchTerm = term; return matchesSearch(w); } };`);
  const search = makeSearch(
    ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'],
    ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'],
  );
  const webinar = {
    title: 'Правила оказания платных медицинских услуг',
    speaker: 'Кадыров Ф.Н.',
    date: '2026-05-17',
    _d: { full: '17 мая 2026 г.', day: '17', month: 'мая', year: '2026' },
  };
  assert.equal(search.search(webinar, 'платных 17.05.2026'), true);
  assert.equal(search.search(webinar, 'кадыров 2026-05'), true);
  assert.equal(search.search(webinar, '17 май 2026'), true);
  assert.equal(search.search(webinar, '18.05.2026'), false);
});

test('saved filter presets are selected above the list and managed in View settings', () => {
  assert.match(page, /id="quick-presets"[\s\S]*?id="preset-select"/);
  assert.doesNotMatch(page, /id="presets-bar"/);
  assert.match(settings, /Сохранённые фильтры каталога[\s\S]*?id="preset-name"[\s\S]*?id="preset-list"/);
  assert.match(app, /function buildPresetSelect\(\)/);
  assert.match(app, /function buildPresetSettings\(\)/);
  assert.match(app, /function saveCurrentPreset\(\)/);
});

test('mobile webinar actions keep the primary action visible and shorten secondary labels', () => {
  assert.match(app, /class="btn btn--watch"[^>]*>[\s\S]*?class="btn-label"/);
  assert.match(app, /class="btn-label btn-label--short"/);
  assert.match(styles, /\.actions \.btn--watch\{flex:1 0 100%/);
  assert.match(styles, /\.actions \.btn-label--long\{display:none\}/);
});
