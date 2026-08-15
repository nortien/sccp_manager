#!/bin/bash
#
# install-sccp-stack.sh
#
# Bootstraps chan-sccp (the C Asterisk driver) + sccp_manager (the FreePBX
# module) on top of an EXISTING FreePBX/Asterisk install. Detects your
# Asterisk major version and package manager (apt or dnf/yum - Sangoma's
# own repos use the same devel-package naming on both: asterisk<N>-devel),
# builds/installs chan-sccp, then hands off to FreePBX's own module
# installer (fwconsole ma install, which runs sccp_manager's install.php)
# for the module half.
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

CHAN_SCCP_REPO="https://github.com/nortien/chan-sccp.git"
CHAN_SCCP_BRANCH="work"
SCCP_MANAGER_REPO="https://github.com/nortien/sccp_manager.git"
SCCP_MANAGER_BRANCH="stable"
SRC_DIR="/usr/src"
FREEPBX_MODULES_DIR="/var/www/html/admin/modules"

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
log "Checking this is a FreePBX/Asterisk server"
command -v asterisk  >/dev/null 2>&1 || die "asterisk binary not found. This script installs chan-sccp + sccp_manager on top of an existing FreePBX/Asterisk install - it does not install Asterisk or FreePBX themselves (that's your distro's job, e.g. Sangoma's own installer)."
command -v fwconsole >/dev/null 2>&1 || die "fwconsole not found - is this actually a FreePBX server?"

ASTERISK_VERSION=$(asterisk -V | grep -oP 'Asterisk \K[0-9]+\.[0-9]+\.[0-9]+' || true)
ASTERISK_MAJOR=$(echo "$ASTERISK_VERSION" | cut -d. -f1)
[ -n "$ASTERISK_MAJOR" ] || die "Could not parse Asterisk version from 'asterisk -V'."
log "Detected Asterisk ${ASTERISK_VERSION} (major version: ${ASTERISK_MAJOR})"

# chan-sccp's configure.ac currently supports MIN_ASTERISK_VERSION=106,
# MAX_ASTERISK_VERSION=123 for its *auto-detect* path - but we always pass
# --with-asterisk-version explicitly below, which takes chan-sccp's manual
# override path and bypasses that ceiling entirely (see chan-sccp's own
# CLAUDE.md, "Build" section, 2026-08-14 correction note). So this is just
# an informational heads-up, not a hard gate.
case "$ASTERISK_MAJOR" in
    20|22|23) : ;;  # the two combos this fork actually documents supporting (16/20, 17/23), plus 22 added alongside 123 support
    *)
        warn "This fork has mainly been exercised against Asterisk 20.x/22.x/23.x."
        warn "Detected major version ${ASTERISK_MAJOR} - continuing anyway (--with-asterisk-version bypasses chan-sccp's own version ceiling), but the build may need configure.ac's MAX_ASTERISK_VERSION bumped first if this is newer than chan-sccp has ever seen - see chan-sccp/CLAUDE.md 'Known gotchas'."
        ;;
esac

# ---------------------------------------------------------------------------
log "Detecting package manager"
if command -v apt-get >/dev/null 2>&1; then
    PKG=apt
elif command -v dnf >/dev/null 2>&1; then
    PKG=dnf
elif command -v yum >/dev/null 2>&1; then
    PKG=yum
else
    die "No supported package manager found (need apt, dnf, or yum)."
fi
log "Using: $PKG"

pkg_installed() {
    case "$PKG" in
        apt) dpkg -s "$1" >/dev/null 2>&1 ;;
        dnf|yum) rpm -q "$1" >/dev/null 2>&1 ;;
    esac
}

pkg_install() {
    case "$PKG" in
        apt) DEBIAN_FRONTEND=noninteractive apt-get install -y "$@" ;;
        dnf) dnf install -y "$@" ;;
        yum) yum install -y "$@" ;;
    esac
}

# ---------------------------------------------------------------------------
log "Installing build dependencies"
# Sangoma's own package repos (both the dnf/yum ones for sng7/CentOS and the
# apt ones for sng12/Debian - verified directly against this project's own
# sng12 dev box) use the SAME devel-package naming convention on both sides:
# asterisk<major>-devel. Only the package-manager invocation differs.
DEVEL_PKG="asterisk${ASTERISK_MAJOR}-devel"

if [ "$PKG" = apt ]; then
    log "Refreshing apt package lists"
    apt-get update -qq
fi

if ! pkg_installed "$DEVEL_PKG"; then
    log "Installing ${DEVEL_PKG} (must match installed Asterisk exactly)"
    pkg_install "$DEVEL_PKG" gcc make git \
        || die "Failed installing build deps - does ${DEVEL_PKG} exist in your repos? For Sangoma boxes this comes from the same repo Asterisk itself did; check your Asterisk/FreePBX repo config if it's missing."
else
    log "${DEVEL_PKG} already installed, skipping"
    pkg_install gcc make git >/dev/null
fi

if [ "$DEV_MODE" -eq 1 ]; then
    log "--dev requested: also installing autoconf/automake/gettext for tools/bootstrap.sh"
    case "$PKG" in
        apt) pkg_install autoconf automake gettext ;;
        dnf|yum) pkg_install autoconf automake gettext-devel ;;
    esac
fi

# ---------------------------------------------------------------------------
log "Fetching chan-sccp source"
if [ -d "${SRC_DIR}/chan-sccp/.git" ]; then
    log "Existing checkout found, updating"
    cd "${SRC_DIR}/chan-sccp"
    git fetch origin
    git checkout "$CHAN_SCCP_BRANCH"
    git pull origin "$CHAN_SCCP_BRANCH"
else
    git clone "$CHAN_SCCP_REPO" "${SRC_DIR}/chan-sccp"
    cd "${SRC_DIR}/chan-sccp"
    git checkout "$CHAN_SCCP_BRANCH"
fi

# ---------------------------------------------------------------------------
if [ "$DEV_MODE" -eq 1 ]; then
    log "--dev: regenerating configure via tools/bootstrap.sh"
    ./tools/bootstrap.sh || die "bootstrap.sh failed (check for missing gettext/autopoint)"
else
    log "Using chan-sccp's committed, pre-generated ./configure (no autoreconf needed for a stock install)"
    [ -x ./configure ] || die "./configure not found/executable - either re-run with --dev, or this checkout is unexpectedly missing its committed configure script."
fi

log "Building chan-sccp for Asterisk ${ASTERISK_MAJOR}.0"
# Flags match the exact command verified and documented in chan-sccp's own
# CLAUDE.md "Build" section - keep this in sync if that ever changes.
./configure --with-asterisk-version="${ASTERISK_MAJOR}.0" \
    --enable-conference --enable-advanced-functions \
    --enable-distributed-devicestate --enable-video \
    || die "./configure failed"
make -j"$(nproc)" || die "make failed"
make install || die "make install failed"

# ---------------------------------------------------------------------------
log "Excluding chan_skinny.so (required for chan-sccp to fully initialize)"
if grep -qE '^\s*noload\s*=\s*chan_skinny\.so' /etc/asterisk/modules.conf 2>/dev/null; then
    log "Already excluded, skipping"
else
    echo "noload = chan_skinny.so" >> /etc/asterisk/modules.conf
    log "Added noload entry"
fi

log "Restarting Asterisk (full restart - never hot-swap a freshly built .so)"
fwconsole restart

log "Verifying chan-sccp initialized correctly"
sleep 3
if asterisk -rx "sccp show version" 2>/dev/null | grep -q "Skinny Client Control Protocol"; then
    log "chan-sccp is running and fully initialized"
else
    die "chan-sccp did not initialize. Check: asterisk -rx \"module show like sccp\" and grep chan_skinny /var/log/asterisk/full"
fi

# ---------------------------------------------------------------------------
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
