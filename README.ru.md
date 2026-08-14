# SCCP Manager — версия для FreePBX 16/17 · Asterisk 20/23

**Рабочий, пропатченный форк, который реально запускается на современном Asterisk.**

[![License: GPL](https://img.shields.io/badge/license-GPL-blue.svg)](https://github.com/nortien/sccp_manager/blob/stable/COPYING)
[![chan-sccp](https://img.shields.io/badge/driver-chan--sccp%204.4-informational.svg)](https://github.com/nortien/chan-sccp)
[![Asterisk](https://img.shields.io/badge/Asterisk-20.x%20%7C%2023.x-orange.svg)]()
[![FreePBX](https://img.shields.io/badge/FreePBX-16%20%7C%2017-orange.svg)]()
[![Documentation](https://img.shields.io/badge/docs-wiki-blue.svg)](https://github.com/nortien/sccp_manager/wiki)

Телефоны Cisco по протоколу SCCP (Skinny), подключённые к FreePBX без лицензированного CallManager. Это веб-часть настройки — линии, speed dial, кнопки BLF, модели телефонов, софткеи — всё управляется прямо из FreePBX, а не правкой конфигов руками.

---

## Коротко о сути

Любая публичная сборка этого инструмента — и драйвер, и GUI — останавливается на Asterisk 18. Под Asterisk 20 её никто не обновлял. Мы обновили: пропатчили определение версии в драйвере, добавили корректную ветку кода под Asterisk 20 и перепроверили всю установку от начала до конца на реальном сервере FreePBX 16. Потом пошли дальше и сделали то же самое для **FreePBX 17 / Asterisk 23 / PHP 8.2** — новая мажорная версия на другом дистрибутиве (Debian 12) и пакетном менеджере (apt), которая потребовала и реального фикса сборки (Asterisk 21+ убрал пару полей канала, которые использовал драйвер), и полного прохода по совместимости GUI-модуля с PHP 8.2. Оба окружения работают из одной ветки `stable` в обоих репозиториях. Всё, что написано здесь, отражает именно это — а не документацию оригинального проекта с механической заменой ссылок.

Полные заметки по сборке, каждая ошибка, с которой мы столкнулись, и точное решение каждой — в **[Wiki](https://github.com/nortien/sccp_manager/wiki)**. Этот README ориентирует вас в проекте; Wiki доводит до рабочей установки. Детали по Asterisk 23 / PHP 8.2 — в **[Wiki → FreePBX 17 / Asterisk 23 Notes](https://github.com/nortien/sccp_manager/wiki/FreePBX-17-Notes)**.

## Что в этом репозитории, а что нет

Здесь лежит **модуль FreePBX** — PHP/GUI-слой. Он общается с реальным драйвером протокола через Asterisk Manager Interface (AMI), а сам драйвер — в отдельном репозитории: **[nortien/chan-sccp](https://github.com/nortien/chan-sccp)**. Нужны оба; этот модуль бесполезен, пока драйвер не собран и не загружен.

| | Этот репозиторий | Сопутствующий репозиторий |
|---|---|---|
| Что это | Модуль GUI FreePBX (PHP) | Драйвер канала Asterisk (C) |
| Версия | `15.1.1` | `4.4` |
| Способ установки | `fwconsole ma install` | `./configure && make install` |

## Зачем вообще понадобился этот форк?

Коротко: внутренний маркер версии Asterisk изменился с `8.0.0` на `9.0.0` где-то между 19 и 20 версией, а сборочный скрипт драйвера умел искать только `8.0.0`. Из-за этого на Asterisk 20 драйвер молча ошибочно определял себя как гораздо более старый, несовместимый Asterisk 17 и собирался против неправильных внутренних API — без ошибок, без предупреждений, просто либо не компилировался чисто, либо вёл себя неправильно в рантайме.

Мы исправили это на уровне конфигурации сборки, не трогая саму телефонную логику — обработка протокола SCCP осталась оригинальным кодом апстрима, мы просто научили его правильно распознавать реальный Asterisk, на котором он работает. Полный технический разбор: **[Wiki → Patches](https://github.com/nortien/sccp_manager/wiki/Patches)**.

## Требования

Два поддерживаемых окружения, оба из одной ветки `stable` в обоих репозиториях:

| | FreePBX 16 | FreePBX 17 |
|---|---|---|
| Asterisk | 20.x (протестировано на 20.17.0) | 23.x (протестировано на 23.4.1) |
| PHP | 7.4.x — потолок самого FreePBX 16, не наш | 8.2.x (протестировано на 8.2.33) |
| Дистрибутив | CentOS/Sangoma sng7 | Debian 12 / Sangoma sng12 |
| Пакетный менеджер | yum/dnf | apt |

В любом случае также нужны:
- Собранный и загруженный [nortien/chan-sccp](https://github.com/nortien/chan-sccp)
- Доступный TFTP-сервер для провижининга прошивок/конфигов телефонов

## Быстрый старт

Это полный путь от чистой установки Sangoma FreePBX 16 с уже запущенным Asterisk. Выполняется от root.

```bash
# --- Зависимости для сборки ---
yum install -y asterisk20-devel autoconf automake gcc git gettext-devel

# --- Драйвер: chan-sccp ---
cd /usr/src
git clone https://github.com/nortien/chan-sccp.git
cd chan-sccp
git checkout stable   # или помеченный релиз, например v4.4

./tools/bootstrap.sh
./configure --with-asterisk-version=20.0 \
  --enable-conference --enable-advanced-functions \
  --enable-distributed-devicestate --enable-video
make -j2
make install

# chan-sccp не инициализируется полностью, пока загружен chan_skinny.so — исключаем его
echo "noload = chan_skinny.so" >> /etc/asterisk/modules.conf
fwconsole restart

# проверка — должна вывести реальную строку версии, а не "No such command"
asterisk -rx "sccp show version"

# --- GUI: sccp_manager ---
git clone https://github.com/nortien/sccp_manager.git /var/www/html/admin/modules/sccp_manager
cd /var/www/html/admin/modules/sccp_manager
git checkout stable   # или помеченный релиз, например v15.0.1

fwconsole chown

# --- TFTP (провижининг телефонов) ---
systemctl enable --now tftp.socket

fwconsole ma install sccp_manager
```

Путь выше — для yum/CentOS-варианта FreePBX 16. На **FreePBX 17 / Debian 12** общая схема та же, но отличается пакетный менеджер и пара флагов (apt вместо yum, `--with-asterisk-version=23.0` вместо `20.0`) — точные отличия и заметки по совместимости с PHP 8.2 см. в **[Wiki → FreePBX 17 / Asterisk 23 Notes](https://github.com/nortien/sccp_manager/wiki/FreePBX-17-Notes)**.

Если `fwconsole ma install` всё же останавливается с ошибкой `chan-sccp not found` — почти всегда это значит, что исключение `chan_skinny.so` выше не сработало, см. страницу Troubleshooting в Wiki.

**[Страница Building and Installation Guide в Wiki](https://github.com/nortien/sccp_manager/wiki/Building-and-Installation-Guide)** содержит тот же путь с пояснениями к каждому шагу, плюс пару граблей, которые не стоит впихивать сюда — прежде всего, особенность уровня доверия GPG-ключа, если захотите самостоятельно подписать модуль, чтобы убрать предупреждение FreePBX "Unsigned Module".

## Обновление

Подтяните новый коммит или тег, затем заново запустите установщик — он сам обработает миграции БД:

```bash
cd /var/www/html/admin/modules/sccp_manager
git pull origin stable      # или: git fetch --tags && git checkout vX.Y.Z
fwconsole chown
fwconsole ma install sccp_manager
```

Если обновлялся и драйвер — сначала пересоберите и переустановите **его**, затем сделайте полный рестарт Asterisk, прежде чем трогать модуль — никогда не делайте "горячую" замену `chan_sccp.so` (см. Wiki → Troubleshooting):

```bash
cd /usr/src/chan-sccp
git pull origin stable
./tools/bootstrap.sh
./configure --with-asterisk-version=20.0 --enable-conference --enable-advanced-functions --enable-distributed-devicestate --enable-video
make -j2
make install
fwconsole restart
```

## Переверстка GUI

Слой рендеринга форм (`sccpManClasses/formcreate.class.php`) был переписан, чтобы единообразно соответствовать нативной вёрстке FreePBX, попутно исправив реальные баги, которые маскировала старая вёрстка (переключатель "Customise/restore", который незаметно замораживал дефолты chan-sccp в БД при любом сохранении формы; скачивание прошивок не туда из-за режима `tftp_rewrite`; иконки, которые вообще не отображались под темой Bootstrap 4 в FreePBX 17), и в целом убрав визуальные несостыковки, накопившиеся за годы. Теперь это часть `stable`.

## Откуда это взялось

Этот проект стоит на плечах чужой работы и не существовал бы без неё. Концепция восходит к [Cynjut/SCCP_Manager](https://github.com/Cynjut/SCCP_Manager), дальше её развивал [PhantomVl](https://github.com/PhantomVl/sccp_manager), позже проект поддерживался под организацией [chan-sccp](https://github.com/chan-sccp/sccp_manager) вместе с драйвером [chan-sccp/chan-sccp](https://github.com/chan-sccp/chan-sccp) — вся заслуга за оригинальный дизайн, реализацию протокола и годы доработок принадлежит им. Этот форк — прямое продолжение этой линии: тот же код, та же лицензия, пропатчено конкретно для того, чтобы продолжать работать на версиях Asterisk, до которых у оригинальных мейнтейнеров пока не дошли руки.

## Лицензия

GPL, как и у апстрима. См. `COPYING`, если файл присутствует в репозитории.
