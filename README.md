# SCCP Manager — FreePBX 16/17 · Asterisk 20/23 Edition

**A working, patched fork that actually runs on modern Asterisk.**

[![License: GPL](https://img.shields.io/badge/license-GPL-blue.svg)](https://github.com/nortien/sccp_manager/blob/stable/COPYING)
[![chan-sccp](https://img.shields.io/badge/driver-chan--sccp%204.4-informational.svg)](https://github.com/nortien/chan-sccp)
[![Asterisk](https://img.shields.io/badge/Asterisk-20.x%20%7C%2023.x-orange.svg)]()
[![FreePBX](https://img.shields.io/badge/FreePBX-16%20%7C%2017-orange.svg)]()
[![Documentation](https://img.shields.io/badge/docs-wiki-blue.svg)](https://github.com/nortien/sccp_manager/wiki)

Cisco IP phones (SCCP/Skinny) talking to FreePBX, without a licensed CallManager. This is the web GUI half of the setup — line assignments, speed dials, BLF buttons, phone models, softkeys, all managed from FreePBX itself instead of hand-edited config files.

---

## The short version

Every public build of this tool — the driver and the GUI alike — stops at Asterisk 18. Nobody had updated it for Asterisk 20. So we did: patched the driver's version detection, added a proper code path for Asterisk 20, and re-tested the whole install end to end on a real FreePBX 16 box. Then we went further and did the same for **FreePBX 17 / Asterisk 23 / PHP 8.2** — a newer major on a different distro (Debian 12) and package manager (apt), which needed both a real build fix (Asterisk 21+ removed a couple of channel-field APIs the driver used) and a full PHP 8.2 compatibility pass on the GUI module. Both environments work from the same `stable` branch in both repos. Everything here reflects that — not the upstream project's docs with a find-and-replace.

Full build notes, every error message we hit, and the exact fix for each one — plus the complete general reference (features, hardware, config options, dialplan, provisioning) — live on the **[Wiki](https://github.com/nortien/sccp_manager/wiki)**. This README gets you oriented and installed; the Wiki is where you go for everything after that. The Asterisk 23 / PHP 8.2 specifics are on **[Wiki → FreePBX 17 / Asterisk 23 Notes](https://github.com/nortien/sccp_manager/wiki/FreePBX-17-Notes)**.

## What's in this repo, and what isn't

This repo is the **FreePBX module** — the PHP/GUI layer. It talks to the actual protocol driver over Asterisk's Manager Interface (AMI), which lives in a separate repo: **[nortien/chan-sccp](https://github.com/nortien/chan-sccp)**. You need both; this one is useless without the driver compiled and loaded first.

| | This repo | Companion repo |
|---|---|---|
| What it is | FreePBX GUI module (PHP) | Asterisk channel driver (C) |
| Version | `15.1.1` | `4.4` |
| Install method | `fwconsole ma install` | `./configure && make install` |

## Why does this fork exist at all?

Short version: Asterisk's internal version marker changed from `8.0.0` to `9.0.0` somewhere around version 19–20, and the driver's build script only knew to look for `8.0.0`. So on Asterisk 20 it silently mis-detected itself as a much older, incompatible Asterisk 17 and built against the wrong internal APIs — no error, no warning, just a driver that either wouldn't compile cleanly or would misbehave at runtime.

We fixed that at the build-configuration level rather than touching the actual telephony logic, so the SCCP protocol handling itself is untouched upstream code — we just taught it to correctly recognize the Asterisk it's actually running on. Full technical writeup: **[Wiki → Patches](https://github.com/nortien/sccp_manager/wiki/Patches)**.

## Requirements

Two supported environments, both from the same `stable` branch in both repos:

| | FreePBX 16 | FreePBX 17 |
|---|---|---|
| Asterisk | 20.x (tested against 20.17.0) | 23.x (tested against 23.4.1) |
| PHP | 7.4.x — FreePBX 16's own ceiling, not ours | 8.2.x (tested against 8.2.33) |
| Distro tested on | CentOS/Sangoma sng7 | Debian 12 / Sangoma sng12 |
| Package manager | yum/dnf | apt |

Either way you also need:
- [nortien/chan-sccp](https://github.com/nortien/chan-sccp) compiled and loaded first
- A reachable TFTP server for phone firmware/config provisioning

## Getting started

This is the complete flow, starting from a clean Sangoma FreePBX 16 install with Asterisk already running. Run everything as root.

```bash
# --- Build dependencies ---
yum install -y asterisk20-devel autoconf automake gcc git gettext-devel

# --- Driver: chan-sccp ---
cd /usr/src
git clone https://github.com/nortien/chan-sccp.git
cd chan-sccp
git checkout stable   # or a tagged release, e.g. v4.4

./tools/bootstrap.sh
./configure --with-asterisk-version=20.0 \
  --enable-conference --enable-advanced-functions \
  --enable-distributed-devicestate --enable-video
make -j2
make install

# chan-sccp won't fully initialize while chan_skinny.so is loaded — exclude it
echo "noload = chan_skinny.so" >> /etc/asterisk/modules.conf
fwconsole restart

# sanity check — should print a real version string, not "No such command"
asterisk -rx "sccp show version"

# --- GUI: sccp_manager ---
git clone https://github.com/nortien/sccp_manager.git /var/www/html/admin/modules/sccp_manager
cd /var/www/html/admin/modules/sccp_manager
git checkout stable   # or a tagged release, e.g. v15.0.1

fwconsole chown

# --- TFTP (phone provisioning) ---
systemctl enable --now tftp.socket

fwconsole ma install sccp_manager
```

The flow above is for the yum/CentOS-based FreePBX 16 path. On **FreePBX 17 / Debian 12**, the shape is the same but the package manager and a couple of flags differ (apt instead of yum, `--with-asterisk-version=23.0` instead of `20.0`) — see **[Wiki → FreePBX 17 / Asterisk 23 Notes](https://github.com/nortien/sccp_manager/wiki/FreePBX-17-Notes)** for the exact diffs and the PHP 8.2 compatibility notes.

If `fwconsole ma install` still stops with `chan-sccp not found`, it almost always means the `chan_skinny.so` exclusion above didn't take — see the Wiki's Troubleshooting page.

The **[Wiki's Building & Installation Guide](https://github.com/nortien/sccp_manager/wiki/Building-and-Installation-Guide)** covers the same flow with explanations for each step, plus a couple of gotchas not worth cramming in here — most notably a GPG trust-level quirk if you want to self-sign the module to clear FreePBX's "Unsigned Module" warning.

## Updating

Pull a newer commit or tag, then re-run the installer — it handles DB migrations on its own:

```bash
cd /var/www/html/admin/modules/sccp_manager
git pull origin stable      # or: git fetch --tags && git checkout vX.Y.Z
fwconsole chown
fwconsole ma install sccp_manager
```

If the driver was also updated, rebuild and reinstall **it first**, then do a full Asterisk restart before touching the module — never hot-swap `chan_sccp.so` (see Wiki → Troubleshooting):

```bash
cd /usr/src/chan-sccp
git pull origin stable
./tools/bootstrap.sh
./configure --with-asterisk-version=20.0 --enable-conference --enable-advanced-functions --enable-distributed-devicestate --enable-video
make -j2
make install
fwconsole restart
```

## GUI rewrite

The form-rendering layer (`sccpManClasses/formcreate.class.php`) was reworked to match FreePBX's own native layout conventions consistently, fixing several real bugs the old layout was masking (a customise/restore toggle that silently froze chan-sccp defaults into the DB on any save, firmware downloads landing in the wrong TFTP path depending on `tftp_rewrite` mode, icons that rendered as nothing under FreePBX 17's Bootstrap 4 theme), and generally cleaning up visual inconsistencies accumulated over the years. This is now part of `stable`.

## Where this came from

This project stands on the work of others, and wouldn't exist without it. The concept traces back to [Cynjut/SCCP_Manager](https://github.com/Cynjut/SCCP_Manager), was carried forward by [PhantomVl](https://github.com/PhantomVl/sccp_manager), and was later maintained under the [chan-sccp](https://github.com/chan-sccp/sccp_manager) organization alongside the [chan-sccp/chan-sccp](https://github.com/chan-sccp/chan-sccp) driver itself — all the credit for the original design, protocol implementation, and years of feature work belongs to them. This fork is a direct continuation of that lineage: same codebase, same license, patched specifically to keep working on Asterisk versions the original maintainers hadn't gotten to yet.

## License

GPL, same as upstream. See `COPYING` if present in this repo.
