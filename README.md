# Компас

Личное приложение для денег, привычек и задач с советником, который помогает управлять деньгами:
считает, сколько можно потратить сегодня, предупреждает о перерасходе и отвечает, стоит ли покупать.

- Android-приложение, веб и Telegram-бот на одном сервере.
- Сервер: Laravel 13 + MariaDB (хостинг AHost). Клиент: Vue 3 + TypeScript + Tailwind, Android через Capacitor.
- План и решения: [docs/PLAN.md](docs/PLAN.md). Выкладка на хостинг: [docs/DEPLOY.md](docs/DEPLOY.md).

## Приложение на телефон

APK собирает GitHub Actions, Android Studio не нужна.

1. Один раз: в настройках репозитория (Settings → Secrets and variables → Actions → Variables) создать переменную `API_URL` с адресом сервера, например `https://kompas.example.uz`.
2. Открыть Actions → Android → последний зелёный запуск, внизу в Artifacts скачать `kompas-apk-…` и распаковать.
3. Отправить `kompas.apk` на телефон и открыть. Android попросит разрешить установку из этого источника.

Пока в секретах нет ключа подписи (`ANDROID_KEYSTORE_BASE64`, `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`), собирается отладочная версия: перед установкой новой старую нужно удалить. С ключом новые версии ставятся поверх.
