# SCCP Manager (nortien fork — Asterisk 20 / FreePBX 16)

| [English](https://github.com/nortien/sccp_manager/blob/work/README.md) | [Russian](https://github.com/nortien/sccp_manager/blob/work/README.ru.md) | [Wiki](https://github.com/nortien/sccp_manager/wiki) |

This is a private fork of the original [chan-sccp/sccp_manager](https://github.com/chan-sccp/sccp_manager) FreePBX module, patched to work with **Asterisk 20** and **FreePBX 16**, which are not supported by the upstream project out of the box.

The idea of creating this module is borrowed from [Cynjut/SCCP_Manager](https://github.com/Cynjut/SCCP_Manager), and was further developed and managed by PhantomVl ([PhantomVl/sccp_manager](https://github.com/PhantomVl/sccp_manager)), who has been unavailable for some time. The project was later maintained under [chan-sccp/sccp_manager](https://github.com/chan-sccp/sccp_manager), alongside the [chan-sccp/chan-sccp](https://github.com/chan-sccp/chan-sccp) channel driver itself.

This fork exists because, as of writing, neither upstream project builds or runs correctly against Asterisk 20 (see [Why this fork exists](#why-this-fork-exists) below). We patched both the channel driver and this GUI module to work, and we track our own version numbers (`chan-sccp` at `4.4`, `sccp_manager` at `15.0.1`) separately from upstream.

## Companion repository

This module requires the companion driver: **[nortien/chan-sccp](https://github.com/nortien/chan-sccp)** (our patched fork of chan-sccp, branch `work`). It will not work with the stock upstream driver on Asterisk 20 — see the Wiki for why.

## Why this fork exists

- Upstream `chan-sccp`'s `./configure` version-detection heuristic doesn't recognize Asterisk 20 (it looks for a literal `AMI_VERSION "8.0.0"` string; Asterisk 20 reports `"9.0.0"`), so it silently falls back to a very old, incorrect code path (`pbx_impl/ast117`).
- We patched `chan-sccp` to add a proper `pbx_impl/ast120` implementation and to detect Asterisk 20 explicitly via `--with-asterisk-version=20.0`.
- Upstream `sccp_manager`'s installer correctly detects Asterisk 20 once the driver above is fixed — no patches were needed on the PHP side beyond version/URL bookkeeping.
- FreePBX 17 / Asterisk 21 support is a known, currently **unresolved** upstream issue ([chan-sccp/chan-sccp#618](https://github.com/chan-sccp/chan-sccp/issues/618)) — we plan to tackle that separately; see the Wiki.

Full technical details, the exact patch diffs, and every gotcha we hit are documented on the **[Wiki](https://github.com/nortien/sccp_manager/wiki)** — start there if you're setting this up on a new server.

## Requirements

- FreePBX 16 (tested), PHP 7.4.x (FreePBX 16's own requirement — do not use PHP 8.x)
- Asterisk 20.x (tested against 20.17.0)
- [nortien/chan-sccp](https://github.com/nortien/chan-sccp) built and loaded (see its README / our Wiki)
- PHP `zip` extension (`php-zip` / `phpX.Y-zip` depending on distro)
- A TFTP server for phone provisioning (see Wiki — socket-activated `tftp.socket` on RHEL/CentOS-based distros)

## Quick install (see Wiki for the full walkthrough)

```bash
# 1. Build and install the driver first (see nortien/chan-sccp README)

# 2. Clone this module into FreePBX's modules directory
cd /var/www/html/admin/modules/
git clone https://github.com/nortien/sccp_manager.git sccp_manager
cd sccp_manager
git checkout work   # or a specific vX.Y.Z tag

# 3. Fix ownership, then install through FreePBX
fwconsole chown
fwconsole ma install sccp_manager
```

If the installer stops with `chan-sccp not found`, it almost always means `chan_skinny.so` is still loaded and blocking chan-sccp's initialization — see the Wiki's Troubleshooting page.

## Documentation

All setup steps, patches, and troubleshooting notes (TFTP setup, module signing, disabling DAHDi/IAX2, the `chan_skinny` conflict, the hot-swap `.so` crash, etc.) are on the **[GitHub Wiki](https://github.com/nortien/sccp_manager/wiki)**.

## License

GPL — see [COPYING](https://github.com/nortien/sccp_manager/blob/work/COPYING) if present, or the original project's license.
