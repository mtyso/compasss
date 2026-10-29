# Выкладка Компаса на AHost

Как Компас попадает на хостинг AHost (cPanel, MariaDB 11.4, без root, composer и node) и как его обновлять. Решение D13 в [PLAN.md](PLAN.md).

## Как это устроено

```
пуш в main  ->  GitHub Actions «Release» собирает ветку release  ->  сервер: bash deploy/deploy-git.sh
```

- **Ветка `release`** собирается сама при каждом пуше в `main` (`.github/workflows/release.yml`). В ней только то, что нужно серверу: Laravel без тестов, `vendor/` без dev-пакетов и собранное приложение в `public/`. На компьютере для выкладки ничего ставить не нужно.
- **На сервер ничего не попадает само.** Новая версия появляется на сайте только после команды `bash deploy/deploy-git.sh` в cPanel Terminal.
- **`.env` и `storage/`** живут только на сервере, в ветке их нет, обновление их не трогает.

Где что лежит на сервере:

```
~/apps/kompas/            код (вне веб-корня): рабочая копия ветки release, .env, storage
~/apps/kompas/public/     веб-корень сайта
~/backups/kompas/         дампы базы перед миграциями (predeploy/), вне сайта
```

Очередь и расписание работают от одного cron раз в минуту (`schedule:run`), долгих процессов нет. Сессии и кэш в базе.

## Первая установка

Делается один раз. Пароли базы и токен бота сразу кладите в менеджер паролей: ни в чат, ни в файлы репозитория они не попадают.

### 1. Код в main

Слить PR этапа 1 в `main`. Через пару минут в GitHub → Actions → Release должен быть зелёный запуск, а в списке веток появится `release`.

### 2. cPanel

1. **Domains → Create A New Domain:** отдельный домен (сейчас это `compasss.uz`) или поддомен, например `kompas.<ваш-домен>`. Галочку «Share document root» снять, Document Root: `apps/kompas/public`. Новому домену нужны NS хостинга (`dns1.ahost.uz`, `dns2.ahost.uz`) у регистратора; когда домен откроется, сертификат выпускает AutoSSL (SSL/TLS Status → Run AutoSSL). Переключателя Force HTTPS Redirect на аккаунте может не быть: тогда http → https делает `public/.htaccess` из репозитория, для другого домена поправьте в нём условие `compasss\.uz`.
2. **PHP 8.3** (`alt-php83`), не 8.4: на AHost `alt-php84` принудительно грузит расширения `psr` и `xdebug`, выключить их нельзя, и Laravel с `psr` падает. Версию домену ставит **MultiPHP Manager**, но только после шага 4: cPanel вписывает свой обработчик в `public/.htaccess`, а первый checkout этот файл перезаписывает (дальше скрипт обновления сохраняет блоки cPanel сам). В **Select PHP Version → Extensions** для 8.3 включены `bcmath`, `intl`, `pdo_mysql`, `mbstring`, `curl`, `openssl`, `fileinfo`, `dom`, `xml`, `tokenizer`, `ctype`. Скрипт обновления сам проверит их и скажет, чего не хватает.
3. **MySQL Databases:** база `kompas` и пользователь `kompas` (cPanel добавит префикс аккаунта, например `user_kompas`), пользователю **ALL PRIVILEGES** на эту базу.
4. **Тикет в поддержку AHost**, если анти-бот защита отвечает приложению и Telegram страницей проверки. Проверить снаружи после шага 8: `https://<домен>/api/me` должен отвечать JSON `Unauthenticated`, а `telegram:webhook-info` — без ошибки. На `compasss.uz` в октябре 2026 тикет не понадобился. Текст тикета:

   > Здравствуйте! На поддомене kompas.<ваш-домен> работает API мобильного приложения и вебхук Telegram-бота. Анти-бот защита отвечает на эти запросы страницей проверки, из-за этого приложение и бот не работают. Пожалуйста, отключите проверку для путей /api/* и /telegram/webhook на этом поддомене. Спасибо!

### 3. Ключ GitHub для сервера

В cPanel → Terminal:

```bash
mkdir -p ~/.ssh && chmod 700 ~/.ssh
ssh-keygen -t ed25519 -f ~/.ssh/kompas_deploy -N "" -C kompas-deploy
cat ~/.ssh/kompas_deploy.pub
```

Строку `ssh-ed25519 ...` добавить в GitHub: репозиторий `mtyso/compasss` → Settings → Deploy keys → Add deploy key, название `ahost`, галочку «Allow write access» **не** ставить. Потом:

```bash
cat >> ~/.ssh/config <<'EOF'
Host github-kompas
    HostName github.com
    User git
    IdentityFile ~/.ssh/kompas_deploy
    IdentitiesOnly yes
    StrictHostKeyChecking accept-new
EOF
chmod 600 ~/.ssh/config
ssh -T -o ConnectTimeout=10 git@github-kompas
```

Ответ должен быть `Hi mtyso/compasss! You've successfully authenticated...`. Своё имя хоста `github-kompas` нужно, чтобы ключ не спорил с ключами других сайтов на этом аккаунте (у каждого репозитория свой ключ). Если ответа нет (таймаут), в этом блоке заменить `HostName github.com` на две строки `HostName ssh.github.com` и `Port 443` и повторить.

### 4. Код на сервер

```bash
PHP=/opt/alt/php83/usr/bin/php
$PHP -v                                     # 8.3.x; /usr/local/bin/php на AHost в cron не работает
mkdir -p ~/apps/kompas && cd ~/apps/kompas
git init -q
git config core.fileMode false
git remote add origin git@github-kompas:mtyso/compasss.git
git fetch origin release
git checkout -f -B release origin/release
cat RELEASE.txt                             # из какого коммита main собрана версия
mkdir -p ~/backups/kompas && chmod 700 ~/backups/kompas
```

### 5. `.env`

```bash
cd ~/apps/kompas
cp deploy/.env.production.example .env
chmod 600 .env
nano .env
```

Заполнить:

- `APP_URL` — адрес сайта с `https://`;
- `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` — из шага 2.3;
- `BACKUP_PATH` — `/home/<пользователь cPanel>/backups/kompas` (полный путь даёт `echo ~/backups/kompas`);
- блок Telegram — по [BOT.md](BOT.md), раздел «Настройка»: бот в @BotFather, токен вписывается только сюда, свой Telegram ID в `ALLOWED_TELEGRAM_IDS`.

`APP_KEY` оставить пустым и выполнить:

```bash
$PHP artisan key:generate --force           # только при первой установке
```

### 6. База и кэши

По одной команде: хостинг ограничивает процессор аккаунта, длинная цепочка может прерваться.

```bash
$PHP artisan migrate --force
$PHP artisan optimize
$PHP artisan rates:fetch                    # проверка доступа к cbu.uz: должна напечатать курс USD
chmod -R u+rwX,g+rX,o-rwx storage bootstrap/cache
```

Если `migrate` прервался на первой установке, повторить `$PHP artisan migrate:fresh --force` (база пустая, терять нечего).

### 7. Cron

cPanel → Cron Jobs → Once Per Minute, команда (путь целиком, `~` в cron не раскрывается):

```
/opt/alt/php83/usr/bin/php /home/<пользователь cPanel>/apps/kompas/artisan schedule:run >> /dev/null 2>&1
```

Через пару минут `$PHP artisan schedule:list` покажет время следующих запусков.

### 8. Бот

```bash
$PHP artisan telegram:setup
```

Команда ставит вебхук, кнопку «Компас» и список команд (подробно в [BOT.md](BOT.md)). Повторять после смены адреса или токена; после любой правки `.env` сначала `$PHP artisan optimize`. `$PHP artisan telegram:webhook-info` показывает адрес вебхука и последнюю ошибку доставки. Если бот до этого работал на компьютере через `telegram:poll`, остановите его: при вебхуке Telegram не отдаёт обновления в poll.

### 9. Проверка

- `https://<домен>/up` отвечает страницей «Application up», а `https://<домен>/` показывает экран входа.
- «Войти через Telegram» открывает бота, после «Войти» приложение просит придумать PIN.
- Боту `такси 32к`: приходит карточка, трата видна в приложении.

### 10. Приложение на телефон

1. GitHub → Settings → Secrets and variables → Actions → Variables → New repository variable: `API_URL` = `https://compasss.uz` (адрес сайта).
2. Actions → Android → Run workflow (ветка `main`). Через 3–4 минуты в запуске, внизу в Artifacts, скачать `kompas-apk-…`, распаковать и поставить `kompas.apk` на телефон. Подробнее — [README](../README.md#приложение-на-телефон).

### 11. Пуш-уведомления на телефон (Firebase)

Необязательно: без этого шага все уведомления приходят в бот, как раньше. Firebase бесплатен (D17, D36 в [PLAN.md](PLAN.md)).

1. [console.firebase.google.com](https://console.firebase.google.com) → **Create a project**, название `kompas`, Google Analytics не нужен.
2. В проекте: значок Android (**Add app**), Android package name — ровно `uz.kompas.app`, остальное пропустить. Скачать `google-services.json`.
3. Файл — в секрет GitHub: на компьютере перевести его в base64 и скопировать. Windows: открыть PowerShell, перейти в папку с файлом (`cd $HOME\Downloads`) и выполнить `[Convert]::ToBase64String([IO.File]::ReadAllBytes((Resolve-Path .\google-services.json))) | Set-Clipboard`; macOS: `base64 -i ~/Downloads/google-services.json | pbcopy`. Если браузер сохранил файл как `google-services (1).json`, взять это имя. Затем GitHub → Settings → Secrets and variables → Actions → Secrets → New repository secret: `GOOGLE_SERVICES_JSON_BASE64`, вставить строку. Сам файл в репозиторий не кладётся.
4. Ключ сервера: в Firebase → значок шестерёнки → **Project settings → Service accounts → Generate new private key**. Скачается JSON с закрытым ключом: это пароль от отправки пушей, в чат и в репозиторий он не попадает. Загрузить его на сервер через cPanel → File Manager в `~/apps/kompas/storage/app/private/` под именем `firebase-service-account.json` и в cPanel → Terminal:

   ```bash
   cd ~/apps/kompas
   chmod 600 storage/app/private/firebase-service-account.json
   $PHP artisan optimize
   ```

   Обновления сайта файл не трогают: `storage/` живёт только на сервере. На компьютере скачанный ключ после загрузки лучше удалить.
5. Actions → Android → Run workflow и поставить новый APK (шаг 10.2): только в сборке с секретом из пункта 3 работает переключатель телефона.
6. Проверка: в приложении Настройки → «Уведомления» → включить «На этот телефон», разрешить уведомления, затем «Прислать проверочное» — через несколько секунд приходит «Уведомления работают». Там же «Куда присылать»: Telegram, телефон или оба.

Если ключ отозвали или удалили в Firebase, сервер это заметит при первой отправке и на час вернёт все уведомления в бот (потом попробует снова). Так же он поступает, если Firebase 10 минут не отвечает. Новый ключ: снова пункт 4, а затем «Прислать проверочное» — после удачной проверки уведомления сразу снова идут на телефон.

## Обновление

После пуша в `main` дождаться зелёного запуска Actions → Release, потом в cPanel → Terminal:

```bash
cd ~/apps/kompas && bash deploy/deploy-git.sh
```

Скрипт:

1. проверяет PHP 8.3 и его расширения, `.env` и что на сервере никто не правил файлы кода;
2. забирает ветку `release`; если версия та же, ничего не делает;
3. если в новой версии есть миграции, сначала снимает дамп базы в `~/backups/kompas/predeploy/` (хранит 5 последних);
4. выключает сайт на время обновления, ставит новый код, выполняет миграции, обновляет кэши, включает сайт;
5. проверяет, что `/up` и `/` отвечают, и печатает команду отката.

Параметры: `--force` выложить ту же версию заново, `--no-backup` без дампа (если дамп сняли сами), `--no-down` не выключать сайт. Справка: `bash deploy/deploy-git.sh --help`.

## Если что-то пошло не так

- **Упала миграция.** Скрипт сам вернул прежний код, сайт оставлен выключенным, путь к дампу напечатан. Восстановить базу и включить сайт:

  ```bash
  cd ~/apps/kompas
  gunzip -c ~/backups/kompas/predeploy/<файл>.sql.gz | mariadb -u <DB_USERNAME> -p <DB_DATABASE>    # спросит пароль базы
  /opt/alt/php83/usr/bin/php artisan up
  ```

  Дамп можно загрузить и через phpMyAdmin → Import, он принимает `.sql.gz`.
- **Сайт не ответил после обновления без миграций.** Скрипт сам вернул прежний код. Причину ищите в `storage/logs/laravel-<дата>.log`.
- **Вернуть прежнюю версию руками.** В конце каждого обновления скрипт печатает готовую команду отката (`git reset --hard <прежний коммит>` и кэши). Миграции назад сами не откатываются: базу возвращает дамп.
- **«На сервере изменены файлы кода».** Кто-то правил файлы прямо на сервере. Ветка `release` перезаписывается целиком, поэтому правки делаются в репозитории. Выбросить серверные правки: `git checkout -- .` и запустить скрипт ещё раз.
- **Приложение или бот получают страницу проверки вместо ответа.** Анти-бот защита: тикет из шага 2.4.
- **Пуши не приходят.** «Прислать проверочное» говорит, в чём дело: «На сервере ещё не настроен Firebase» — нет файла ключа из шага 11.4 или после загрузки не выполнен `optimize`; «Firebase не принял уведомление» — смотреть `storage/logs/laravel-<дата>.log`, строки `FCM` и `Push is off`: там причина. `SENDER_ID_MISMATCH` значит, что ключ сервера (шаг 11.4) и `google-services.json` в приложении (шаг 11.3) из разных проектов Firebase: скачать оба из одного проекта и пересобрать APK. `did not take a message` с `cURL error` — сервер не может достучаться до Google, это вопрос в поддержку AHost. Если переключатель телефона серый с подписью «Заработают в следующей сборке приложения…», APK собран без секрета `GOOGLE_SERVICES_JSON_BASE64`. Пока пуши не работают, всё приходит в бот.
