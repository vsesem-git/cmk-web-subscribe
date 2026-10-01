<?php /* Модальные окна приложения */ ?>

<!-- ===== Настройки (админ) ===== -->
<div class="modal-overlay" id="modal-overlay" aria-hidden="true">
  <div class="modal modal--wide" id="modal-box" role="dialog" aria-modal="true">
    <div class="modal__head">
      <h3>Настройки</h3>
      <button class="modal__close" id="modal-close" aria-label="Закрыть">&times;</button>
    </div>
    <div class="tabs" id="tabs">
      <button class="tab active" data-tab="smtp">SMTP / Почта</button>
      <button class="tab" data-tab="features">Функции</button>
      <button class="tab" data-tab="view">Вид</button>
      <button class="tab" data-tab="source">Источник данных</button>
      <button class="tab" data-tab="users">Пользователи</button>
      <button class="tab" data-tab="add">Добавить пользователей</button>
      <button class="tab" data-tab="logs">Журналы</button>
      <button class="tab" data-tab="config">Конфигурация</button>
      <button class="tab" data-tab="help">Формат JSON</button>
    </div>
    <div class="modal__body">

      <!-- SMTP -->
      <div class="tabpane active" id="pane-smtp">
        <div class="hintbox">Укажите параметры вашего почтового сервера. Пароль хранится на сервере и не отдаётся в браузер.</div>
        <div class="form-grid">
          <div class="fld full"><label>SMTP-хост</label><input class="input" id="sm-host" placeholder="smtp.yandex.ru"></div>
          <div class="fld"><label>Порт</label><input class="input" id="sm-port" type="number" value="465"></div>
          <div class="fld"><label>Шифрование</label>
            <select class="select" id="sm-secure"><option value="ssl">SSL</option><option value="tls">STARTTLS</option><option value="none">Без шифрования</option></select>
          </div>
          <div class="fld"><label>Логин SMTP</label><input class="input" id="sm-user" autocomplete="off"></div>
          <div class="fld"><label>Пароль SMTP</label><input class="input" id="sm-pass" type="password" autocomplete="new-password" placeholder="••••••"></div>
          <div class="fld"><label>Email отправителя</label><input class="input" id="sm-from" placeholder="noreply@vsesem.ru"></div>
          <div class="fld"><label>Имя отправителя</label><input class="input" id="sm-name" value="ЦМК-Подписка"></div>
        </div>
        <div class="smtp-test">
          <div class="fld" style="flex:1"><label>Проверка: отправить тестовое письмо на</label><input class="input" id="sm-test-to" type="email" placeholder="you@example.com"></div>
          <button class="mbtn" id="btn-smtp-test" type="button">Проверить SMTP</button>
        </div>
        <div class="prev-err" id="smtp-err"></div>
        <div class="mail-ok" id="smtp-ok"></div>
        <div class="modal__foot" style="margin:16px -24px -24px;">
          <button class="mbtn mbtn--primary" id="save-smtp">Сохранить настройки почты</button>
        </div>
      </div>

      <!-- Функции -->
      <div class="tabpane" id="pane-features">
        <div class="hintbox">Включайте и отключайте возможности системы. Отключённые функции скрываются у пользователей и не работают на сервере.</div>
        <div class="feat-list" id="feat-list">
          <label class="feat"><span><b>Логирование входов</b><small>запись попыток входа (успех/ошибка, IP)</small></span><input type="checkbox" data-feat="log_login"></label>
          <label class="feat"><span><b>Логирование просмотров</b><small>фиксировать открытие вебинаров</small></span><input type="checkbox" data-feat="log_views"></label>
          <label class="feat"><span><b>Логирование писем</b><small>запись отправленных писем</small></span><input type="checkbox" data-feat="log_mail"></label>
          <label class="feat"><span><b>Кнопка «Отправить доступ на email»</b><small>у вебинаров</small></span><input type="checkbox" data-feat="btn_access"></label>
          <label class="feat"><span><b>Кнопка «Отправить приглашение»</b><small>у вебинаров</small></span><input type="checkbox" data-feat="btn_invite"></label>
          <label class="feat"><span><b>Раздел «Мои просмотры»</b><small>личная история у пользователей</small></span><input type="checkbox" data-feat="my_views"></label>
          <label class="feat"><span><b>Таймер обратного отсчёта</b><small>колонка «До начала»</small></span><input type="checkbox" data-feat="timer"></label>
          <label class="feat"><span><b>Значок «просмотрен»</b><small>отметка на открытых вебинарах</small></span><input type="checkbox" data-feat="viewed_badge"></label>
          <label class="feat"><span><b>Предупреждение об окончании подписки</b><small>плашка за 14 дней</small></span><input type="checkbox" data-feat="expiry_warn"></label>
          <label class="feat"><span><b>Блок «Прошедшие вебинары»</b><small>показывать историю вебинаров</small></span><input type="checkbox" id="feat-showpast"></label>
        </div>
        <div class="modal__foot" style="margin:16px -24px -24px;">
          <button class="mbtn mbtn--primary" id="save-features">Сохранить функции</button>
        </div>
      </div>

      <!-- Вид -->
      <div class="tabpane" id="pane-view">
        <div class="hintbox">Настройки внешнего вида применяются для всех пользователей. Пресет задаёт размеры шрифта и плотность одним кликом; ниже можно тонко настроить.</div>

        <h4 class="cf-title">Пресет вида</h4>
        <div class="seg-choice" id="preset-seg">
          <button data-preset="compact">Компактный</button>
          <button data-preset="normal">Обычный</button>
          <button data-preset="large">Крупный</button>
        </div>

        <div class="view-row" style="margin-top:16px">
          <span class="vlabel">Плотность строк</span>
          <div class="seg-choice" id="density-seg">
            <button data-density="compact">Компактно</button>
            <button data-density="normal">Обычно</button>
            <button data-density="comfortable">Просторно</button>
          </div>
        </div>
        <div class="view-row">
          <span class="vlabel">Тема оформления</span>
          <div class="seg-choice" id="theme-seg">
            <button data-theme="light">Светлая</button>
            <button data-theme="dark">Тёмная</button>
          </div>
        </div>
        <div class="view-row">
          <span class="vlabel">Час начала вебинаров</span>
          <input class="input" type="number" min="0" max="23" id="vw-start-hour" style="width:90px"> <span class="hint-inline">для таймера «До начала» (если у вебинара нет своего времени)</span>
        </div>
        <div class="view-row">
          <span class="vlabel">Режим отображения</span>
          <div class="seg-choice" id="mode-seg">
            <button data-mode="table">Таблица</button>
            <button data-mode="cards">Карточки</button>
          </div>
        </div>
        <div class="view-row">
          <span class="vlabel">Показывать по</span>
          <input class="input" type="number" min="0" max="500" id="vw-page-size" style="width:90px"> <span class="hint-inline">строк (0 = все сразу, иначе кнопка «Показать ещё»)</span>
        </div>
        <div class="view-row">
          <span class="vlabel">Формат даты</span>
          <input class="input" id="vw-date-format" style="width:200px" placeholder="D MMMM YYYY">
          <span class="hint-inline">D, DD, M, MM, MMMM, YYYY, YY</span>
        </div>

        <h4 class="cf-title">Колонки: порядок и видимость</h4>
        <div class="hintbox">Перетаскивайте строки, чтобы менять порядок. Галочка — показывать колонку. Подпись можно переименовать.</div>
        <div class="col-config" id="col-config"></div>

        <h4 class="cf-title">Сопоставление полей JSON</h4>
        <div class="hintbox">Если во внешнем JSON поля названы иначе — укажите здесь их имена. Слева — что нужно системе, справа — как называется в вашем файле.</div>
        <div class="colfonts" id="fieldmap-box"></div>

        <h4 class="cf-title">Цвета и подписи категорий</h4>
        <div class="hintbox">Переопределите цвет и/или подпись направления (пусто = по умолчанию).</div>
        <div class="cat-config" id="cat-config"></div>

        <h4 class="cf-title">Размер шрифта по столбцам (px)</h4>
        <div class="hintbox">Меняйте значения — таблица под окном обновится сразу (живой предпросмотр). «Тема» авто-уменьшается, если текст не помещается.</div>
        <div class="colfonts">
          <label class="cf"><span>Дата</span><input type="number" min="10" max="28" id="cf-date" data-live></label>
          <label class="cf"><span>Лектор</span><input type="number" min="10" max="28" id="cf-speaker" data-live></label>
          <label class="cf"><span>До начала</span><input type="number" min="10" max="28" id="cf-timer" data-live></label>
          <label class="cf"><span>Тема</span><input type="number" min="10" max="28" id="cf-title" data-live></label>
          <label class="cf"><span>Цена</span><input type="number" min="10" max="28" id="cf-price" data-live></label>
        </div>

        <h4 class="cf-title">Ширина столбцов (px, 0 = авто)</h4>
        <div class="colfonts">
          <label class="cf"><span>Дата</span><input type="number" min="0" max="600" id="cw-date" data-livew></label>
          <label class="cf"><span>Лектор</span><input type="number" min="0" max="600" id="cw-speaker" data-livew></label>
          <label class="cf"><span>До начала</span><input type="number" min="0" max="600" id="cw-timer" data-livew></label>
          <label class="cf"><span>Тема</span><input type="number" min="0" max="600" id="cw-title" data-livew></label>
          <label class="cf"><span>Цена</span><input type="number" min="0" max="600" id="cw-price" data-livew></label>
          <label class="cf"><span>Действия</span><input type="number" min="0" max="600" id="cw-action" data-livew></label>
        </div>
        <div class="hintbox" style="margin-top:10px">💡 Колонки можно менять и мышкой — потяните за правую границу заголовка (как в Excel). Новая ширина сохранится автоматически.</div>

        <div class="modal__foot" style="margin:18px -24px -24px;">
          <button class="mbtn mbtn--ghost" id="view-reset">Сбросить к стандартным</button>
          <button class="mbtn mbtn--primary" id="save-view">Сохранить вид</button>
        </div>
      </div>

      <!-- Источник данных -->
      <div class="tabpane" id="pane-source">
        <div class="field">
          <label for="src-url">Ссылка на источник данных (JSON)</label>
          <input type="url" id="src-url" class="input" placeholder="https://…/webinars.json" spellcheck="false">
          <div class="hint">Если поле пустое — используется локальный файл data/webinars.json на сервере.</div>
        </div>
        <div class="field">
          <label class="chk"><input type="checkbox" id="show-past"> Показывать блок «Прошедшие вебинары»</label>
        </div>
        <div class="modal__foot" style="margin:16px -24px -24px;">
          <button class="mbtn mbtn--primary" id="save-source">Сохранить</button>
        </div>
      </div>

      <!-- Пользователи -->
      <div class="tabpane" id="pane-users">
        <div class="adm-toolbar">
          <input type="search" class="input grow" id="user-search" placeholder="Поиск по логину или организации…">
          <button class="mbtn mbtn--primary" id="new-user-btn">+ Новый пользователь</button>
        </div>
        <div class="utable-wrap">
          <table class="utable">
            <thead><tr><th>Логин</th><th>Организация</th><th>Email</th><th>Роль</th><th>Категории</th><th>Действует до</th><th></th></tr></thead>
            <tbody id="users-tbody"></tbody>
          </table>
        </div>
      </div>

      <!-- Массовое добавление -->
      <div class="tabpane" id="pane-add">
        <div id="add-step-input">
          <div class="hintbox">
            Одна организация — одна строка. Формат:
            <code>Организация; Направление; Дата окончания; Email(необязательно)</code><br>
            Пример:<br>
            <code>ООО ЦМК; Здравоохранение; 31.12.2026; info@cmk.ru</code><br>
            <code>ООО РОГА И КОПЫТА; ЖКХ; 31.12.2026</code><br>
            Направления: ЖКХ, ЗДРАВ, ЭЛЕКТРО, ЭКОЛОГИЯ, СТРОИТЕЛЬСТВО, ЗЕМЛЯ, ГОЗ, ГАЗ, Все.
            Логин (4 буквы) и пароль (6 символов) сгенерируются автоматически.
          </div>
          <textarea class="ta" id="bulk-input" placeholder="ООО ЦМК; Здравоохранение; 31.12.2026&#10;ООО РОГА И КОПЫТА; ЖКХ; 31.12.2026"></textarea>
          <div class="modal__foot" style="margin:16px -24px -24px;">
            <button class="mbtn mbtn--primary" id="bulk-check">Проверить →</button>
          </div>
        </div>
        <div id="add-step-review" class="hidden">
          <div class="prev-warn">Проверьте данные перед созданием. Всё ли верно?</div>
          <div id="bulk-errors"></div>
          <div class="utable-wrap">
            <table class="utable">
              <thead><tr><th>#</th><th>Организация</th><th>Направление</th><th>Действует до</th><th>Email</th><th>Логин</th><th>Пароль</th></tr></thead>
              <tbody id="review-tbody"></tbody>
            </table>
          </div>
          <div class="modal__foot" style="margin:16px -24px -24px;">
            <button class="mbtn mbtn--ghost" id="bulk-back">← Назад, исправить</button>
            <button class="mbtn mbtn--primary" id="bulk-confirm">Всё верно — создать</button>
          </div>
        </div>
      </div>

      <!-- Журналы -->
      <div class="tabpane" id="pane-logs">
        <div class="log-stats" id="log-stats"></div>

        <div class="retention-box">
          <label class="chk" style="margin-bottom:10px"><input type="checkbox" id="ret-enabled"> Авто‑очистка логов</label>
          <div class="ret-row">
            <div class="fld"><label>Хранить не дольше (дней)</label><input class="input" id="ret-days" type="number" min="0" placeholder="180"></div>
            <div class="fld"><label>Не более строк на файл</label><input class="input" id="ret-lines" type="number" min="0" placeholder="20000"></div>
            <div class="fld" style="align-self:end"><button class="mbtn" id="ret-save" type="button">Сохранить</button></div>
            <div class="fld" style="align-self:end"><button class="mbtn" id="ret-prune" type="button">Очистить сейчас</button></div>
          </div>
          <div class="hint">Очистка запускается автоматически (не чаще раза в сутки) и удаляет записи старше N дней или сверх лимита строк. «0» = без ограничения.</div>
          <div class="mail-ok" id="ret-msg"></div>
        </div>

        <div class="adm-toolbar">
          <div class="seg" id="log-seg">
            <button class="seg-btn active" data-log="views">Просмотры</button>
            <button class="seg-btn" data-log="login">Входы</button>
            <button class="seg-btn" data-log="mail">Письма</button>
          </div>
          <button class="mbtn" id="log-export">Экспорт в CSV</button>
          <button class="mbtn" id="log-clear">Очистить этот лог</button>
        </div>
        <div class="utable-wrap">
          <table class="utable"><thead id="log-thead"></thead><tbody id="log-tbody"></tbody></table>
        </div>
      </div>

      <!-- Конфигурация: экспорт / импорт -->
      <div class="tabpane" id="pane-config">
        <div class="cfg-block">
          <h4>Экспорт конфигурации</h4>
          <p class="cfg-desc">Скачать все настройки, пользователей и список вебинаров одним файлом — для резервной копии или переноса на другой сервер.</p>
          <label class="chk" style="margin-bottom:12px"><input type="checkbox" id="exp-smtp-pass"> Включить пароль SMTP в открытом виде <span class="hint-inline">(по умолчанию — нет, безопаснее)</span></label>
          <div><button class="mbtn mbtn--primary" id="btn-export">Скачать файл конфигурации</button></div>
        </div>

        <div class="cfg-block">
          <h4>Импорт конфигурации</h4>
          <p class="cfg-desc">Загрузите ранее скачанный файл. Выберите, какие разделы применить. Импорт заменяет выбранные данные — сделайте экспорт текущей конфигурации перед этим.</p>
          <div class="cfg-parts">
            <label class="chk"><input type="checkbox" id="imp-settings" checked> Настройки (SMTP, функции, авто‑очистка)</label>
            <label class="chk"><input type="checkbox" id="imp-users" checked> Пользователи</label>
            <label class="chk"><input type="checkbox" id="imp-webinars"> Список вебинаров</label>
          </div>
          <div class="cfg-file">
            <input type="file" id="imp-file" accept="application/json,.json">
            <button class="mbtn mbtn--primary" id="btn-import">Импортировать</button>
          </div>
          <div class="prev-warn" style="margin-top:12px">Внимание: если снять галочку «Пользователи», текущие пользователи не изменятся. В импортируемом файле обязателен хотя бы один администратор.</div>
          <div class="prev-err" id="imp-err"></div>
          <div class="mail-ok" id="imp-ok"></div>
        </div>
      </div>

      <!-- Формат JSON (справка для редактора внешнего файла) -->
      <div class="tabpane" id="pane-help">
        <div class="hintbox">Справочник по структуре файла вебинаров (<code>webinars.json</code> или внешний URL). Поля можно переименовать во вкладке «Вид → Сопоставление полей».</div>

        <h4 class="cf-title">Структура файла</h4>
        <p class="cfg-desc">Файл — это массив объектов (по одному на вебинар) либо объект вида <code>{ "webinars": [ ... ] }</code>.</p>

        <h4 class="cf-title">Поля вебинара</h4>
        <div class="help-table-wrap">
          <table class="help-table">
            <thead><tr><th>Поле</th><th>Тип</th><th>Обяз.</th><th>Описание</th></tr></thead>
            <tbody>
              <tr><td class="mono">id</td><td>число/строка</td><td>да</td><td>Уникальный идентификатор вебинара. Используется в ссылке кабинета и логах просмотров.</td></tr>
              <tr><td class="mono">date</td><td>строка</td><td>да</td><td>Дата в формате <code>ГГГГ-ММ-ДД</code>, например <code>2026-08-05</code>.</td></tr>
              <tr><td class="mono">time</td><td>строка</td><td>нет</td><td>Время начала <code>ЧЧ:ММ</code>, например <code>14:00</code>. Если не указано — берётся общий «час начала» из настроек (по умолчанию 10:00).</td></tr>
              <tr><td class="mono">speaker</td><td>строка</td><td>да</td><td>Лектор, например <code>Кадыров Ф.Н.</code></td></tr>
              <tr><td class="mono">title</td><td>строка</td><td>да</td><td>Тема вебинара.</td></tr>
              <tr><td class="mono">price</td><td>число</td><td>нет</td><td>Цена без подписки в рублях. <code>0</code> или отсутствует → показывается «Бесплатно».</td></tr>
              <tr><td class="mono">direction</td><td>строка</td><td>нет</td><td>Направление (категория). Значения ниже. Пусто → «ПРОЧЕЕ».</td></tr>
              <tr><td class="mono">link_participant</td><td>URL</td><td>да</td><td>Ссылка на страницу участника — там кнопки «смотреть онлайн», «запись», «материалы». На неё ведут кнопки «Смотреть вебинар/запись» и письма.</td></tr>
            </tbody>
          </table>
        </div>

        <h4 class="cf-title">Значения поля «direction»</h4>
        <div class="help-table-wrap">
          <table class="help-table">
            <thead><tr><th>Значение</th><th>Категория</th></tr></thead>
            <tbody>
              <tr><td class="mono">ЖКХ</td><td>ЖКХ</td></tr>
              <tr><td class="mono">ЗДРАВ</td><td>Здравоохранение</td></tr>
              <tr><td class="mono">ЭЛЕКТРО</td><td>Электроэнергетика</td></tr>
              <tr><td class="mono">ЭКОЛОГИЯ</td><td>Экология</td></tr>
              <tr><td class="mono">СТРОИТЕЛЬСТВО</td><td>Строительство</td></tr>
              <tr><td class="mono">ЗЕМЛЯ</td><td>Земля</td></tr>
              <tr><td class="mono">ГОЗ</td><td>Госрегулирование</td></tr>
              <tr><td class="mono">ГАЗ</td><td>Газ</td></tr>
            </tbody>
          </table>
        </div>
        <p class="cfg-desc">Принимаются также ключи латиницей: <code>zhkh, zdrav, electro, eco, build, land, goz, gas</code>. Регистр не важен.</p>

        <h4 class="cf-title">Пример файла</h4>
        <pre class="help-code" id="help-json-example"></pre>
        <div class="modal__foot" style="margin:16px -24px -24px;">
          <button class="mbtn" id="help-copy">Скопировать пример</button>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ===== Создание/редактирование пользователя ===== -->
<div class="modal-overlay" id="user-overlay" aria-hidden="true" style="z-index:120">
  <div class="modal" role="dialog" aria-modal="true">
    <div class="modal__head">
      <h3 id="user-modal-title">Новый пользователь</h3>
      <button class="modal__close" id="user-modal-close" aria-label="Закрыть">&times;</button>
    </div>
    <div class="modal__body">
      <div class="form-grid">
        <div class="fld"><label>Логин</label><input class="input" id="ef-login" spellcheck="false"></div>
        <div class="fld"><label>Пароль <span class="hint-inline">(пусто = не менять)</span></label>
          <div style="display:flex;gap:8px"><input class="input" id="ef-pass" spellcheck="false"><button class="mbtn" id="ef-genpass" type="button" title="Сгенерировать">⟳</button></div>
        </div>
        <div class="fld full"><label>Организация</label><input class="input" id="ef-org"></div>
        <div class="fld full"><label>Email</label><input class="input" id="ef-email" type="email" placeholder="user@example.com"></div>
        <div class="fld"><label>Роль</label><select class="select" id="ef-role"><option value="user">Пользователь</option><option value="admin">Администратор</option></select></div>
        <div class="fld"><label>Действует до</label><input class="input" type="date" id="ef-expires"></div>
        <div class="fld full"><label>Доступные направления</label><div class="catcheck" id="ef-cats"></div></div>
      </div>
      <div class="prev-err" id="ef-err"></div>
    </div>
    <div class="modal__foot">
      <button class="mbtn" id="ef-cancel">Отмена</button>
      <button class="mbtn mbtn--primary" id="ef-save">Сохранить</button>
    </div>
  </div>
</div>

<!-- ===== Письмо: доступ / приглашение ===== -->
<div class="modal-overlay" id="mail-overlay" aria-hidden="true" style="z-index:130">
  <div class="modal modal--wide" role="dialog" aria-modal="true">
    <div class="modal__head">
      <h3 id="mail-modal-title">Отправить письмо</h3>
      <button class="modal__close" id="mail-modal-close" aria-label="Закрыть">&times;</button>
    </div>
    <div class="modal__body">
      <div class="form-grid" style="grid-template-columns:1fr auto;align-items:end;margin-bottom:14px">
        <div class="fld"><label>Email получателя</label><input class="input" id="mail-to" type="email" placeholder="recipient@example.com"></div>
        <div class="fld"><label>&nbsp;</label><button class="mbtn mbtn--primary" id="mail-send">Отправить</button></div>
      </div>
      <div class="fld"><label>Тема письма</label><input class="input" id="mail-subject" readonly></div>
      <div class="mail-preview-label">Предпросмотр письма:</div>
      <iframe id="mail-preview" class="mail-preview" title="Предпросмотр письма"></iframe>
      <div class="prev-err" id="mail-err"></div>
      <div class="mail-ok" id="mail-ok"></div>
    </div>
  </div>
</div>

<!-- ===== Мои просмотры (пользователь) / просмотры пользователя (админ) ===== -->
<div class="modal-overlay" id="views-overlay" aria-hidden="true" style="z-index:125">
  <div class="modal modal--wide" role="dialog" aria-modal="true">
    <div class="modal__head">
      <h3 id="views-title">Мои просмотры</h3>
      <button class="modal__close" id="views-close" aria-label="Закрыть">&times;</button>
    </div>
    <div class="modal__body">
      <div class="views-meta" id="views-meta"></div>
      <div class="utable-wrap">
        <table class="utable">
          <thead><tr><th>Тема вебинара</th><th>Открытий</th><th>Последний просмотр</th></tr></thead>
          <tbody id="views-tbody"></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Тост -->
<div class="toast" id="toast"></div>
