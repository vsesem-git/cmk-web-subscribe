<?php
require_once __DIR__ . '/config.php';
require_once BASE_DIR . '/lib/security.php';
send_security_headers();
secure_session_start();
$csrf = csrf_token();
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= h($csrf) ?>">
<title>Вебинары для подписчиков — <?= h(BRAND_SHORT) ?></title>
<link rel="stylesheet" href="<?= h(asset('assets/app.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('assets/redesign.css')) ?>">
</head>
<body>
<a class="skip-link" href="#main-content">Перейти к расписанию</a>

  <!-- ===== Экран входа ===== -->
  <div class="login-screen" id="login-screen">
    <div class="login-card">
      <div class="login-card__head">
        <div class="brand"><span class="brand__dot"></span><?= h(BRAND_SHORT) ?></div>
        <h2>Вход для подписчиков</h2>
        <p><?= h(BRAND_FULL) ?></p>
      </div>
      <div class="login-card__body">
        <div class="setup-note hidden" id="setup-note" role="status">
          <b>Требуется первичная настройка</b>
          <span>Создайте администратора на сервере командой <code>php scripts/create_admin.php</code>, затем обновите страницу.</span>
        </div>
        <form id="login-form">
          <label for="li-login">Логин</label>
          <input type="text" id="li-login" autocomplete="username" spellcheck="false" required>
          <label for="li-pass">Пароль</label>
          <input type="password" id="li-pass" autocomplete="current-password" required>
          <button class="login-btn" type="submit">Войти</button>
          <div class="login-err" id="login-err" role="alert" aria-live="polite"></div>
        </form>
      </div>
    </div>
  </div>

  <!-- ===== Приложение ===== -->
  <div id="app" class="hidden">
  <header class="topbar">
    <div class="topbar__inner">
      <div class="brand"><span class="brand__dot"></span><?= h(BRAND_SHORT) ?></div>
      <h1>Вебинары для подписчиков</h1>
      <p><?= h(BRAND_FULL) ?> — актуальное расписание онлайн‑семинаров.</p>
      <div class="userbar" id="userbar">
        <span class="who" id="who"></span>
        <button class="theme-toggle" id="theme-toggle" title="Светлая/тёмная тема" aria-label="Переключить тему">
          <svg id="theme-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
        </button>
        <button class="myviews" id="myviews" title="Мои просмотры" aria-label="Мои просмотры">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
        <button class="logout" id="logout" title="Выйти" aria-label="Выйти">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
        </button>
      </div>
      <button class="gear hidden" id="gear" title="Настройки" aria-label="Настройки">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      </button>
    </div>
  </header>

  <main class="wrap" id="main-content">
    <div class="toolbar">
      <label class="search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
        <input id="search" type="search" placeholder="Тема, лектор, дата (17.05.2026)" autocomplete="off" aria-label="Поиск по теме, лектору или дате">
      </label>
      <div class="sort-ctl">
        <span class="sort-label">Сортировка</span>
        <select id="sort-key" class="sort-select" aria-label="Сортировать вебинары">
          <option value="date">по дате</option>
          <option value="speaker">по лектору</option>
          <option value="title">по теме</option>
          <option value="price">по цене</option>
        </select>
        <button class="sort-dir" id="sort-dir" title="Сначала ближайшие" aria-label="Сначала ближайшие">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M6 11l6-6 6 6"/></svg>
        </button>
      </div>
      <button class="refresh-btn" id="mode-toggle" title="Таблица / карточки" aria-label="Режим отображения">
        <svg id="mode-icon-table" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M9 4v16"/></svg>
        <svg id="mode-icon-cards" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/></svg>
        <span id="mode-label">Таблица</span>
      </button>
      <button class="refresh-btn" id="refresh" title="Обновить список">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.5 9a9 9 0 0 1 14.9-3.4L23 10M1 14l4.6 4.4A9 9 0 0 0 20.5 15"/></svg>
        <span>Обновить</span>
      </button>
    </div>

    <div class="expired-note hidden" id="expired-note">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
      <span id="expired-text"></span>
    </div>
    <div class="soon-note hidden" id="soon-note">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
      <span id="soon-text"></span>
    </div>

    <!-- Период и направление в одной строке -->
    <div class="filters-row">
      <div class="periods" id="periods" role="group" aria-label="Фильтр по периоду"></div>
      <span class="filter-divider" aria-hidden="true"></span>
      <div class="category-filter" id="category-filter">
        <button class="category-toggle" id="category-toggle" type="button" aria-expanded="false" aria-controls="chips" aria-label="Открыть фильтр по направлениям">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"/></svg>
          <span>Направления</span>
          <span class="category-current" id="category-current">Все</span>
          <svg class="category-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div class="chips" id="chips" role="group" aria-label="Фильтр по направлению"></div>
      </div>
    </div>

    <!-- Краткие результаты и применение сохранённого фильтра -->
    <div class="results-toolbar">
      <div class="results-summary">
        <span class="results-count" id="results-count" role="status" aria-live="polite"></span>
        <button class="clear-filters hidden" id="clear-filters" type="button">Сбросить фильтры</button>
      </div>
      <label class="quick-presets hidden" id="quick-presets" for="preset-select">
        <span>Пресет</span>
        <select class="select" id="preset-select" aria-label="Применить сохранённый фильтр">
          <option value="">Сохранённые фильтры</option>
        </select>
      </label>
    </div>

    <div id="state-main" class="state hidden" role="status" aria-live="polite"></div>

    <!-- Блоки вебинаров (рендерятся из JS в зависимости от выбранного периода) -->
    <div id="panels" role="region" aria-label="Список вебинаров"></div>
  </main>

  <footer>© <?= date('Y') ?> <?= h(BRAND_FULL) ?> (<?= h(BRAND_SHORT) ?>) — вебинары для подписчиков</footer>
  </div><!-- /#app -->

  <?php require BASE_DIR . '/partials_modals.php'; ?>

<script src="<?= h(asset('assets/app.js')) ?>"></script>
</body>
</html>
