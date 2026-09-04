#!/bin/bash
#
# install-sccp-stack.sh
#
# Bootstraps chan-sccp (the C Asterisk driver) + sccp_manager (the FreePBX
# module) on top of an EXISTING FreePBX/Asterisk install. Driver install
# (precompiled-binary-first, fall back to compiling) lives in
# install-chan-sccp-driver.sh, called from here - that split lets
# sccp_manager's own install.php invoke the driver half by itself (via a
# narrow sudoers rule) without recursing into `fwconsole ma install
# sccp_manager`, which THIS script also calls, further down.
#
# Scope, deliberately: this does NOT install Asterisk or FreePBX themselves
# from a bare OS - that's a distinct, already-solved problem owned by
# Sangoma's own distro installer, and duplicating it here would cut against
# this project's own "Core design goal" (self-contained, never patches
# FreePBX core / host config). "Whole stack" here means everything THIS
# project owns: the driver + the module, on top of Asterisk/FreePBX that's
# already there. If that's not the scope you wanted, that's a call worth
# revisiting explicitly, not something to silently change here.
#
# Safe to re-run: existing checkouts are updated (git fetch + checkout) not
# re-cloned, and idempotent steps (chan_skinny exclusion, TFTP enable) are
# skipped if already done.
#
# Usage (invoke with `bash`, not `./` - `fwconsole chown` resets this
# file's executable bit every time it runs, since it treats everything
# under a module checkout as web-served PHP/assets, not a script meant to
# be run directly):
#   sudo bash install-sccp-stack.sh           # normal install/update
#   sudo bash install-sccp-stack.sh --dev     # also regenerate configure
#                                              # via tools/bootstrap.sh
#                                              # (only needed if you're
#                                              # editing configure.ac /
#                                              # Makefile.am yourself -
#                                              # not needed for a stock
#                                              # install, since chan-sccp
#                                              # ships a committed,
#                                              # pre-generated `configure`)

set -euo pipefail

SCCP_MANAGER_REPO="https://github.com/nortien/sccp_manager.git"
SCCP_MANAGER_BRANCH="stable"
FREEPBX_MODULES_DIR="/var/www/html/admin/modules"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

DEV_MODE=0
for arg in "$@"; do
    case "$arg" in
        --dev) DEV_MODE=1 ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

log()  { echo -e "\n\033[1;34m==> $1\033[0m"; }
warn() { echo -e "\033[1;33m[WARN] $1\033[0m"; }
die()  { echo -e "\033[1;31m[FATAL] $1\033[0m"; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run this script as root."

# ---------------------------------------------------------------------------
log "Installing/updating the chan-sccp driver"
DRIVER_ARGS=()
[ "$DEV_MODE" -eq 1 ] && DRIVER_ARGS+=(--dev)
bash "${SCRIPT_DIR}/install-chan-sccp-driver.sh" ${DRIVER_ARGS[@]+"${DRIVER_ARGS[@]}"}

# ---------------------------------------------------------------------------
log "Detecting package manager (for TFTP setup below)"
if command -v apt-get >/dev/null 2>&1; then
    PKG=apt
elif command -v dnf >/dev/null 2>&1; then
    PKG=dnf
elif command -v yum >/dev/null 2>&1; then
    PKG=yum
else
    die "No supported package manager found (need apt, dnf, or yum)."
fi

log "Setting up TFTP"
if systemctl list-unit-files 2>/dev/null | grep -q '^tftp\.socket'; then
    systemctl enable --now tftp.socket
    log "Enabled tftp.socket (systemd socket-activation)"
elif [ -f /etc/xinetd.d/tftpd ]; then
    sed -i 's/disable\s*=\s*yes/disable         = no/' /etc/xinetd.d/tftpd
    systemctl restart xinetd
    log "Enabled TFTP via xinetd"
else
    warn "No tftp.socket unit or /etc/xinetd.d/tftpd found - no TFTP server detected."
    case "$PKG" in
        apt) warn "Install one, e.g.: apt install tftpd-hpa" ;;
        dnf) warn "Install one, e.g.: dnf install tftp-server" ;;
        yum) warn "Install one, e.g.: yum install tftp-server" ;;
    esac
    warn "See the Wiki's Building and Installation Guide (TFTP section) for the rest of the setup."
fi

# ---------------------------------------------------------------------------
log "Fetching sccp_manager source"
if [ -d "${FREEPBX_MODULES_DIR}/sccp_manager/.git" ]; then
    log "Existing checkout found, updating"
    cd "${FREEPBX_MODULES_DIR}/sccp_manager"
    git fetch origin
    git checkout "$SCCP_MANAGER_BRANCH"
    git pull origin "$SCCP_MANAGER_BRANCH"
elif [ -d "${FREEPBX_MODULES_DIR}/sccp_manager" ]; then
    # Directory exists but isn't a git checkout - the common case is the
    # module having been installed via FreePBX's own Upload Module (GUI
    # tarball upload), which this project's own README documents as the
    # main install path. Turn it into a git checkout in place rather than
    # deleting whatever's already there.
    log "Non-git module directory found (likely a GUI tarball install), converting to a git checkout"
    cd "${FREEPBX_MODULES_DIR}/sccp_manager"
    git init -q
    git remote add origin "$SCCP_MANAGER_REPO"
    git fetch origin
    git checkout -f "$SCCP_MANAGER_BRANCH"
    git reset --hard "origin/$SCCP_MANAGER_BRANCH"
else
    git clone "$SCCP_MANAGER_REPO" "${FREEPBX_MODULES_DIR}/sccp_manager"
    cd "${FREEPBX_MODULES_DIR}/sccp_manager"
    git checkout "$SCCP_MANAGER_BRANCH"
fi

fwconsole chown

# ---------------------------------------------------------------------------
log "Installing sccp_manager module through FreePBX"
fwconsole ma install sccp_manager || die "sccp_manager install failed - see the output above (often means chan_skinny wasn't actually excluded - re-check /etc/asterisk/modules.conf)"

# fwconsole ma install doesn't reliably re-symlink module assets on every
# run (see this repo's own CLAUDE.md, "Known bug pattern:" missing
# /admin/assets/<module> symlink - found 2026-08-14 the hard way, in
# production, hours before this script was written). A plain `fwconsole
# reload` is what actually recreates it (Reload.class.php's
# symlink_assets()) - cheap and safe to always run once here rather than
# hoping ma install already did it.
log "Ensuring module asset symlinks are in place (fwconsole reload)"
fwconsole reload

# ---------------------------------------------------------------------------
log "Done."
echo ""
echo "Verify in the FreePBX GUI:"
echo "  - A new 'Sccp Connectivity' menu should be present."
echo "  - Go to Sccp Connectivity -> Settings -> Site Default Settings first (NTP, locale, IP range)."
echo "  - Then Sccp Connectivity -> Settings -> SCCP Phone to add your first extension and device."
echo ""
echo "Full walkthrough and troubleshooting: https://github.com/nortien/sccp_manager/wiki"
