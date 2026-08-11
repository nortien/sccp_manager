#!/bin/bash
#
# install-sccp-stack.sh
# Automated install of nortien/chan-sccp + nortien/sccp_manager on FreePBX.
# Detects your Asterisk version, package manager, and TFTP service manager,
# then builds/installs everything and verifies each step before continuing.
#
# Safe to re-run: existing checkouts are updated (git pull) instead of
# re-cloned, and idempotent steps (chan_skinny exclusion, TFTP enable) are
# skipped if already done.

set -euo pipefail

CHAN_SCCP_REPO="https://github.com/nortien/chan-sccp.git"
CHAN_SCCP_BRANCH="work"
SCCP_MANAGER_REPO="https://github.com/nortien/sccp_manager.git"
SCCP_MANAGER_BRANCH="work"
SRC_DIR="/usr/src"
FREEPBX_MODULES_DIR="/var/www/html/admin/modules"

log()  { echo -e "\n\033[1;34m==> $1\033[0m"; }
warn() { echo -e "\033[1;33m[WARN] $1\033[0m"; }
die()  { echo -e "\033[1;31m[FATAL] $1\033[0m"; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run this script as root."

# ---------------------------------------------------------------------------
log "Checking this is a FreePBX/Asterisk server"
command -v asterisk  >/dev/null 2>&1 || die "asterisk binary not found."
command -v fwconsole >/dev/null 2>&1 || die "fwconsole not found - is this actually a FreePBX server?"

ASTERISK_VERSION=$(asterisk -V | grep -oP 'Asterisk \K[0-9]+\.[0-9]+\.[0-9]+' || true)
ASTERISK_MAJOR=$(echo "$ASTERISK_VERSION" | cut -d. -f1)
[ -n "$ASTERISK_MAJOR" ] || die "Could not parse Asterisk version from 'asterisk -V'."
log "Detected Asterisk ${ASTERISK_VERSION} (major version: ${ASTERISK_MAJOR})"

if [ "$ASTERISK_MAJOR" -ne 20 ]; then
    warn "This fork has only been tested against Asterisk 20.x."
    warn "Detected major version ${ASTERISK_MAJOR} - continuing anyway, but the build may fail"
    warn "or (for Asterisk 21+/FreePBX 17) is a known-unsolved problem - see the Wiki's FreePBX-17-Notes page."
fi

# ---------------------------------------------------------------------------
log "Detecting package manager"
if command -v dnf >/dev/null 2>&1; then PKG=dnf
elif command -v yum >/dev/null 2>&1; then PKG=yum
else die "No supported package manager found (need yum or dnf)."
fi
log "Using: $PKG"

# ---------------------------------------------------------------------------
log "Installing build dependencies"
DEVEL_PKG="asterisk${ASTERISK_MAJOR}-devel"
if ! rpm -q "$DEVEL_PKG" >/dev/null 2>&1; then
    log "Installing ${DEVEL_PKG} (must match installed Asterisk exactly)"
    $PKG install -y "$DEVEL_PKG" autoconf automake gcc git gettext-devel \
        || die "Failed installing build deps - does ${DEVEL_PKG} exist in your repos? Check: $PKG search asterisk${ASTERISK_MAJOR}-devel"
else
    log "${DEVEL_PKG} already installed, skipping"
    $PKG install -y autoconf automake gcc git gettext-devel >/dev/null
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
log "Building chan-sccp for Asterisk ${ASTERISK_MAJOR}.0"
./tools/bootstrap.sh || die "bootstrap.sh failed (check for missing gettext-devel)"
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
    warn "No tftp.socket unit or /etc/xinetd.d/tftpd found."
    warn "Install a TFTP server manually - see the Wiki's Building and Installation Guide (TFTP section)."
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

# ---------------------------------------------------------------------------
log "Done."
echo ""
echo "Verify in the FreePBX GUI:"
echo "  - A new 'SCCP Connectivity' menu should be present."
echo "  - Go to SCCP Connectivity -> Server Config first (NTP, locale, IP range)."
echo "  - Then Phones Manager to add your first extension and device."
echo ""
echo "Full walkthrough and troubleshooting: https://github.com/nortien/sccp_manager/wiki"
