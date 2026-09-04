# SCCP Manager

FreePBX module for managing Cisco SCCP/Skinny IP phones via [chan-sccp](https://github.com/nortien/chan-sccp), without a licensed CallManager. Line assignments, speed dials, BLF buttons, phone models, softkeys — all from the FreePBX GUI.

**Repo:** [nortien/sccp_manager](https://github.com/nortien/sccp_manager)

## Requirements

| | FreePBX 16 | FreePBX 17 |
|---|---|---|
| Asterisk | 18 / 20 | 18 / 20 / 21 / 22 / 23 |
| PHP | 7.4.x | 8.2.x |
| Distro tested | CentOS/Sangoma sng7 | Debian 12 / Sangoma sng12 |

Also needs a reachable TFTP server for phone provisioning. The `chan-sccp` driver does **not** need to be installed first — the module installs it for you (see below).

## Installation

1. FreePBX → **Admin** → **Module Admin** → **Upload Modules**.
2. Paste this URL and upload:
   ```
   https://github.com/nortien/sccp_manager/releases/latest/download/sccp_manager.tar.gz
   ```
3. **Manage Local Modules** → **Sccp Manager** → **Install** → **Process**.

The release tarball ships precompiled `chan_sccp.so` binaries for every supported Asterisk version, so installing the module also installs the driver — no compiler and no internet access needed on the PBX itself.

If your Asterisk version isn't one we ship a binary for, the installer compiles the driver from source instead. If it can't get root to do that, it stops and prints the exact command to run by hand.

## Update

```bash
fwconsole ma upgrade sccp_manager
```

## Links

- [chan-sccp driver](https://github.com/nortien/chan-sccp)
- [Wiki](https://github.com/nortien/sccp_manager/wiki)

## Credits

Fork of [chan-sccp/sccp_manager](https://github.com/chan-sccp/sccp_manager), tracing back to [Cynjut/SCCP_Manager](https://github.com/Cynjut/SCCP_Manager) and [PhantomVl/sccp_manager](https://github.com/PhantomVl/sccp_manager).

## License

GPL — see `COPYING`.
