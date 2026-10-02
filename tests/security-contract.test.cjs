const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const api = read('api/index.php');
const storage = read('lib/storage.php');
const mail = read('api/mail.php');
const settings = read('api/settings.php');
const users = read('api/users.php');
const security = read('lib/security.php');

test('API actions are explicitly method-gated; all mutations use POST', () => {
  const registry = api.match(/\$actionMethods\s*=\s*\[(.*?)\n\];/s);
  assert.ok(registry, 'action method registry exists');
  const methods = new Map(
    [...registry[1].matchAll(/^\s*'([^']+)'\s*=>\s*\[([^\]]+)\],?\s*$/gm)]
      .map(([, action, value]) => [action, [...value.matchAll(/'([A-Z]+)'/g)].map((m) => m[1])]),
  );
  const mutations = [
    'login', 'logout', 'webinars_refresh', 'log_view', 'user_save', 'user_delete',
    'users_bulk_preview', 'users_bulk_create', 'settings_save', 'features_save',
    'colfonts_save', 'colwidths_save', 'view_save', 'columns_save', 'fieldmap_save',
    'catoverrides_save', 'presets_save', 'retention_save', 'logs_prune_now', 'logs_clear',
    'config_import', 'smtp_test', 'mail_preview', 'mail_send',
  ];
  for (const action of mutations) assert.deepEqual(methods.get(action), ['POST'], `${action} must be POST`);
  assert.ok(methods.size >= mutations.length);
  assert.ok(api.indexOf('require_method($method, $actionMethods)') < api.indexOf('read_json_body()'));
  assert.ok(api.indexOf('csrf_check($token)') < api.indexOf('switch ($action)'));
});

test('webinar category, subscription, and active status are enforced server-side', () => {
  assert.match(storage, /function webinars_visible_to_user\(/);
  assert.match(storage, /!user_can_access_webinar\(\$user, \$webinar\)/);
  assert.match(storage, /function user_can_access_webinar\(/);
  assert.match(storage, /user_subscription_valid\(\$user\)/);
  assert.match(api, /'webinars'\s*=>\s*webinars_visible_to_user\(\$user\)/);
  assert.match(api, /webinar_find_for_user\(\$body\['webinar_id'\]\s*\?\?\s*null,\s*\$user\)/);
  assert.match(mail, /webinar_find_for_user\(\$body\['webinar_id'\]\s*\?\?\s*null,\s*current_user\(\)\)/);
  assert.match(users, /function norm_cats\(/);
  assert.match(users, /function valid_new_password\(/);
  assert.match(storage, /function user_public\([\s\S]*?array_intersect_key\(/);
});

test('external webinar sources reject private IPs and redirects', () => {
  assert.match(storage, /FILTER_FLAG_NO_PRIV_RANGE\s*\|\s*FILTER_FLAG_NO_RES_RANGE/);
  assert.match(storage, /function remote_source_url_valid\(/);
  assert.match(storage, /CURLOPT_FOLLOWLOCATION\s*=>\s*false/);
  assert.match(storage, /CURLOPT_SSL_VERIFYPEER\s*=>\s*true/);
  assert.match(storage, /strlen\(\$body\)\s*\+\s*strlen\(\$chunk\)\s*>\s*\$maxBytes/);
});

test('settings responses expose only an SMTP password-set flag', () => {
  const getAction = settings.match(/case 'settings_get':([\s\S]*?)case 'settings_save':/);
  assert.ok(getAction);
  assert.match(getAction[1], /'pass_set'\s*=>\s*!empty\(\$smtp\['pass'\]\)/);
  assert.doesNotMatch(getAction[1], /'pass'\s*=>\s*\$smtp\['pass'\]/);
  assert.match(settings, /\['ssl', 'tls'\]/);
});

test('runtime data, source directories, and first-run bootstrap are protected', () => {
  const rootHtaccess = read('.htaccess');
  assert.match(rootHtaccess, /RewriteRule.*data\|lib\|vendor\|scripts/);
  assert.match(read('data/.htaccess'), /Require all denied/);
  assert.match(read('lib/.htaccess'), /Require all denied/);
  assert.match(read('vendor/.htaccess'), /Require all denied/);
  assert.match(read('scripts/.htaccess'), /Require all denied/);
  assert.match(read('.gitignore'), /\/data\/users\.json/);
  const bootstrap = read('scripts/create_admin.php');
  assert.match(bootstrap, /PHP_SAPI\s*!==\s*'cli'/);
  assert.match(bootstrap, /не будет его перезаписывать/);
  assert.match(bootstrap, /if \(users_all\(\)\)/);
});

test('search and sort controls have useful accessible names', () => {
  const page = read('index.php');
  const app = read('assets/app.js');
  assert.match(page, /id="search"[^>]*aria-label="Поиск по теме, лектору или дате"/);
  assert.match(page, /id="sort-key"[^>]*aria-label="Сортировать вебинары"/);
  assert.match(page, /id="sort-dir"[^>]*aria-label="[^"]+"/);
  assert.match(app, /setAttribute\("aria-label", "Порядок сортировки: " \+ sd\.title\)/);
});

test('session defenses, security headers, and secure password generation remain present', () => {
  const config = read('config.php');
  assert.match(config, /session\.use_strict_mode/);
  assert.match(config, /'samesite'\s*=>\s*'Strict'/i);
  assert.match(security, /hash_equals\(/);
  assert.match(security, /Content-Security-Policy/);
  const app = read('assets/app.js');
  assert.match(app, /crypto\.getRandomValues/);
  assert.match(app, /safeRandomInt|secureRandomInt/);
  assert.match(app, /rel="noopener noreferrer"/);
});
