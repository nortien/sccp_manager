# SCCP Manager (форк nortien — Asterisk 20 / FreePBX 16)

| [English](https://github.com/nortien/sccp_manager/blob/work/README.md) | [Русский](https://github.com/nortien/sccp_manager/blob/work/README.ru.md) | [Wiki](https://github.com/nortien/sccp_manager/wiki) |

Это приватный форк оригинального модуля FreePBX [chan-sccp/sccp_manager](https://github.com/chan-sccp/sccp_manager), пропатченный для работы с **Asterisk 20** и **FreePBX 16** — версиями, которые оригинальный проект "из коробки" не поддерживает.

Идея создания этого модуля позаимствована у [Cynjut/SCCP_Manager](https://github.com/Cynjut/SCCP_Manager), дальше проект развивал и поддерживал PhantomVl ([PhantomVl/sccp_manager](https://github.com/PhantomVl/sccp_manager)), который на какое-то время стал недоступен. Позже проект поддерживался под [chan-sccp/sccp_manager](https://github.com/chan-sccp/sccp_manager), вместе с самим драйвером канала [chan-sccp/chan-sccp](https://github.com/chan-sccp/chan-sccp).

Этот форк существует потому, что на момент написания ни один из оригинальных проектов не собирается и не работает корректно на Asterisk 20 (подробности — в разделе [Почему появился этот форк](#почему-появился-этот-форк) ниже). Мы пропатчили и сам драйвер канала, и этот GUI-модуль, и теперь ведём собственную нумерацию версий (`chan-sccp` — `4.4`, `sccp_manager` — `15.0.1`), отдельно от апстрима.

## Сопутствующий репозиторий

Этот модуль требует драйвер-компаньон: **[nortien/chan-sccp](https://github.com/nortien/chan-sccp)** (наш пропатченный форк chan-sccp, ветка `work`). Модуль не заработает со стандартным оригинальным драйвером на Asterisk 20 — подробности в Wiki.

## Почему появился этот форк

- Эвристика определения версии в `./configure` оригинального `chan-sccp` не распознаёт Asterisk 20 (она ищет буквальную строку `AMI_VERSION "8.0.0"`, а Asterisk 20 сообщает `"9.0.0"`), поэтому молча откатывается на очень старую, неправильную ветку кода (`pbx_impl/ast117`).
- Мы пропатчили `chan-sccp`, добавив полноценную реализацию `pbx_impl/ast120` и явное распознавание Asterisk 20 через флаг `--with-asterisk-version=20.0`.
- Установщик оригинального `sccp_manager` сам корректно определяет Asterisk 20, как только исправлен драйвер выше — на стороне PHP патчи не потребовались, кроме обновления версии и ссылок.
- Поддержка FreePBX 17 / Asterisk 21 — известная, пока **нерешённая** проблема апстрима ([chan-sccp/chan-sccp#618](https://github.com/chan-sccp/chan-sccp/issues/618)) — планируем разобраться с ней отдельно, см. Wiki.

Все технические детали, точные диффы патчей и все грабли, на которые мы наступили, задокументированы в **[Wiki](https://github.com/nortien/sccp_manager/wiki)** — начните оттуда, если разворачиваете это на новом сервере.

## Требования

- FreePBX 16 (протестировано), PHP 7.4.x (это требование самого FreePBX 16 — не используйте PHP 8.x)
- Asterisk 20.x (протестировано на 20.17.0)
- Собранный и загруженный [nortien/chan-sccp](https://github.com/nortien/chan-sccp) (см. его README / нашу Wiki)
- PHP-расширение `zip` (`php-zip` / `phpX.Y-zip` в зависимости от дистрибутива)
- TFTP-сервер для провижининга телефонов (см. Wiki — на RHEL/CentOS-подобных дистрибутивах это `tftp.socket` с socket-activation)

## Быстрая установка (полную пошаговую инструкцию см. в Wiki)

```bash
# 1. Сначала соберите и установите драйвер (см. README nortien/chan-sccp)

# 2. Склонируйте этот модуль в директорию модулей FreePBX
cd /var/www/html/admin/modules/
git clone https://github.com/nortien/sccp_manager.git sccp_manager
cd sccp_manager
git checkout work   # или конкретный тег vX.Y.Z

# 3. Поправьте владельца файлов и установите через FreePBX
fwconsole chown
fwconsole ma install sccp_manager
```

Если установщик останавливается с ошибкой `chan-sccp not found`, почти всегда это значит, что `chan_skinny.so` всё ещё загружен и блокирует инициализацию chan-sccp — см. страницу Troubleshooting в Wiki.

## Документация

Все шаги настройки, патчи и заметки по устранению неполадок (настройка TFTP, подпись модуля, отключение DAHDi/IAX2, конфликт с `chan_skinny`, краш при "горячей" замене `.so`-файла и т.д.) — в **[GitHub Wiki](https://github.com/nortien/sccp_manager/wiki)**.

## Лицензия

GPL — см. [COPYING](https://github.com/nortien/sccp_manager/blob/work/COPYING), если файл присутствует, либо лицензию оригинального проекта.
