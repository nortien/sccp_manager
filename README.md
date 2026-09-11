# SCCP Manager

FreePBX module that runs Cisco SCCP ("Skinny") desk phones through the [chan-sccp](https://github.com/nortien/chan-sccp) driver, with no Cisco CallManager involved. Phones, lines, buttons, speed dials with busy lamps (BLF), softkey sets, phone models and the per-phone configuration files (`SEP<MAC>.cnf.xml`, fetched by the phone over TFTP) are all managed from the FreePBX GUI. New to the terms? The wiki has a [glossary](https://github.com/nortien/sccp_manager/wiki/Glossary).

**Current release: 17.1.0**, bundling chan-sccp 4.4.0 · Documentation: [wiki](https://github.com/nortien/sccp_manager/wiki)

## Requirements

| | FreePBX 16 | FreePBX 17 |
|---|---|---|
| Asterisk | 18, 20 | 18, 20, 21, 22, 23 |
| PHP | 7.4 | 8.2 |
| Distribution | Sangoma sng7: FreePBX 16 on a CentOS 7 base | Sangoma sng12: FreePBX 17 on a Debian 12 base |

Also needed: the TFTP server that FreePBX ships (the phones fetch firmware and configuration from it; the installer switches it on), a root shell on the PBX once to install the driver, and phones running Cisco's SCCP firmware rather than the SIP one.

## Install

1. **Upload the module.** Admin → Module Admin → Upload Modules → *Download (From Web)*, paste this link and upload:

   ```
   https://github.com/nortien/sccp_manager/releases/latest/download/sccp_manager.tar.gz
   ```

2. **Install the driver**, once, in a shell on the PBX (over SSH) as root:

   ```bash
   sudo bash /var/www/html/admin/modules/sccp_manager/scripts/install-chan-sccp-driver.sh
   ```

   The script uses the binary bundled in the tarball for your Asterisk version, so the PBX needs no compiler and no internet access. It excludes `chan_skinny.so`, switches on the TFTP server FreePBX ships if it is off (FreePBX 16 ships it disabled), writes a placeholder `sccp.conf` if there is none yet (the module replaces it), restarts Asterisk and checks that `sccp show version` answers. If no bundled binary fits, it downloads one from the latest chan-sccp release, and compiles from source as the last resort.

3. **Install the module.** Module Admin → Manage Local Modules → SCCP Manager → Install → Process, or from a shell:

   ```bash
   fwconsole ma install sccp_manager
   ```

   A new **SCCP Connectivity** menu appears with Server Config, System Parameters and Phones Manager.

If you install the module before the driver, the installer stops with the exact command to run and asks you to install again afterwards.

### Everything from one command

On a box with internet access, this installs the driver, enables TFTP, checks the module out from git and installs it:

```bash
curl -fsSLO https://raw.githubusercontent.com/nortien/sccp_manager/stable/scripts/install-sccp-stack.sh
sudo bash install-sccp-stack.sh
```

Re-running it updates both parts. Details, including the optional sudoers rule that lets the module install the driver by itself, are on the wiki [Installation](https://github.com/nortien/sccp_manager/wiki/Installation) page.

## First steps

1. **SCCP Connectivity → Server Config**: on *Site Default Settings* check the TFTP path, NTP, locale and the network ranges your phones are on. Submit, then Apply Config.
2. **System Parameters → SCCP Model information**: enable the phone models you own. *Update Files from Provisioner* fetches firmware, locales and ringtones from GitHub; it works only when *Get data files from Provision* is set to Yes on the Site Default Settings tab.
3. **Phones Manager**: create an SCCP extension, then add the phone by its MAC address and assign the extension to a line.
4. Point the phones at the PBX with DHCP option 150 or a manual TFTP address, and reset them.

The wiki [Getting started](https://github.com/nortien/sccp_manager/wiki/Getting-Started) page walks through a first phone; [Phone provisioning](https://github.com/nortien/sccp_manager/wiki/Phone-Provisioning) covers firmware and TFTP.

## Update

Upload the new release tarball the same way as for the first install and choose Upgrade in Module Admin, or:

```bash
fwconsole ma upgrade sccp_manager
```

The module upgrade does not replace the driver. To move the driver to the bundled version, run the driver installer again (step 2 above); it restarts Asterisk.

## Links

- [chan-sccp driver](https://github.com/nortien/chan-sccp)
- [Wiki](https://github.com/nortien/sccp_manager/wiki): installation, GUI guide, configuration reference, troubleshooting
- [Issues](https://github.com/nortien/sccp_manager/issues)

## Credits

Fork of [chan-sccp/sccp_manager](https://github.com/chan-sccp/sccp_manager), which traces back to [Cynjut/SCCP_Manager](https://github.com/Cynjut/SCCP_Manager) and [PhantomVl/sccp_manager](https://github.com/PhantomVl/sccp_manager). Provisioning files come from [nortien/provision_sccp](https://github.com/nortien/provision_sccp), a fork of dkgroot/provision_sccp.

## License

GNU General Public License, as the upstream module.
