#!/bin/bash
#
# install-chan-sccp-driver.sh
#
# Installs/updates ONLY the chan-sccp driver (chan_sccp.so) on top of an
# EXISTING FreePBX/Asterisk install: tries a precompiled binary first, falls
# back to compiling from source. Does NOT touch sccp_manager (the FreePBX
# module) in any way.
#
# This is deliberately split out of install-sccp-stack.sh so it can be
# invoked safely from sccp_manager's own install.php (via a narrow sudoers
# NOPASSWD rule limited to this exact script) when the GUI module install
# finds no driver loaded - install-sccp-stack.sh itself ends by calling
# `fwconsole ma install sccp_manager`, so install.php can never shell out to
# THAT script without recursing into itself.
#
# Usage (invoke with `bash`, not `./` - `fwconsole chown` resets this file's
# executable bit every time it runs):
#   sudo bash install-chan-sccp-driver.sh           # normal install/update
#   sudo bash install-chan-sccp-driver.sh --dev     # also regenerate configure
#                                                    # via tools/bootstrap.sh
#
# Non-interactive by default (no TTY, e.g. invoked from install.php): if no
# precompiled binary matches, it just compiles from source without asking.
# Run interactively in a real terminal and it'll ask before compiling.

set -euo pipefail

CHAN_SCCP_REPO="https://github.com/nortien/chan-sccp.git"
CHAN_SCCP_BRANCH="stable"
SRC_DIR="/usr/src"
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
log "Checking this is a FreePBX/Asterisk server"
command -v asterisk  >/dev/null 2>&1 || die "asterisk binary not found. This script installs chan-sccp on top of an existing FreePBX/Asterisk install - it does not install Asterisk or FreePBX themselves (that's your distro's job, e.g. Sangoma's own installer)."
command -v fwconsole >/dev/null 2>&1 || die "fwconsole not found - is this actually a FreePBX server?"

ASTERISK_VERSION=$(asterisk -V | grep -oP 'Asterisk \K[0-9]+\.[0-9]+\.[0-9]+' || true)
ASTERISK_MAJOR=$(echo "$ASTERISK_VERSION" | cut -d. -f1)
[ -n "$ASTERISK_MAJOR" ] || die "Could not parse Asterisk version from 'asterisk -V'."
log "Detected Asterisk ${ASTERISK_VERSION} (major version: ${ASTERISK_MAJOR})"

# chan-sccp 4.4.0 compiles against Asterisk 13 through 23 and its configure
# detects the installed version on its own. The precompiled binaries and the
# stand tests cover the majors FreePBX 16/17 actually ship; anything else is
# an informational heads-up, not a hard gate.
case "$ASTERISK_MAJOR" in
    18|20|21|22|23) : ;;
    *)
        warn "This fork is built and tested against Asterisk 18/20/21/22/23 (FreePBX 16 and 17)."
        warn "Detected major version ${ASTERISK_MAJOR} - continuing anyway; chan-sccp's configure accepts 13 through 23, newer majors need the bound in autoconf/asterisk.m4 raised first."
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

# ---------------------------------------------------------------------------
log "Checking for a precompiled chan_sccp.so"
# CI (see chan-sccp's .github/workflows/build-release.yml) publishes
# chan_sccp-ast<major>-<distro tag>.so binaries as GitHub release assets for
# combos it can actually verify against real hardware. If one matches this
# box exactly, installing it skips the compiler/devel-headers step entirely.
# Anything that doesn't match falls straight through to the existing
# compile-from-source flow below - never guess a "close enough" binary.
ASTMODDIR=$(asterisk -rx "core show settings" 2>/dev/null | sed -n 's/^\s*Module directory:\s*//p')
[ -n "$ASTMODDIR" ] || ASTMODDIR="/usr/lib/asterisk/modules"

detect_distro_tag() {
    [ -f /etc/os-release ] || { echo ""; return; }
    . /etc/os-release
    # Two shapes seen on real Sangoma boxes, both verified against live
    # installs (don't "simplify" this to one branch):
    #   sng7  (CentOS-based):  ID="sangoma", VERSION_ID="7"
    #   sng12 (Debian-based):  ID=debian, VERSION_ID="12", NAME="Sangoma ..."
    # An earlier version only matched the Debian shape, so sng7 boxes always
    # fell through to compiling even when a matching binary existed.
    if [ "${ID:-}" = "sangoma" ]; then
        echo "sng${VERSION_ID%%.*}"
    elif [ "${ID:-}" = "debian" ] && grep -qi 'sangoma' /etc/os-release 2>/dev/null; then
        echo "sng${VERSION_ID%%.*}"
    else
        echo ""
    fi
}
DISTRO_TAG=$(detect_distro_tag)
PRECOMPILED_INSTALLED=0

install_so() {
    # Shared final step for both the bundled and the downloaded path.
    local src="$1" label="$2"
    file "$src" | grep -qi 'ELF' || { warn "${label} is not a valid ELF binary, ignoring it"; return 1; }
    install -o root -g root -m 755 "$src" "${ASTMODDIR}/chan_sccp.so"
    log "Installed ${label} to ${ASTMODDIR}/chan_sccp.so"
    return 0
}

try_install_bundled() {
    # Preferred source: a binary shipped inside this module's own release
    # tarball (drivers/ next to this script's parent). PBXes very often sit
    # on an isolated network with no route to github.com, so a bundled copy
    # is the only precompiled path that works there at all.
    local bundled="${SCRIPT_DIR}/../drivers/chan_sccp-ast${ASTERISK_MAJOR}-${1}.so"
    [ -f "$bundled" ] || return 1
    log "Found bundled binary: $(basename "$bundled")"
    install_so "$bundled" "bundled $(basename "$bundled")"
}

try_install_precompiled() {
    local asset_name="chan_sccp-ast${ASTERISK_MAJOR}-${1}.so"
    log "Looking for release asset: ${asset_name}"
    local release_json
    release_json=$(curl -fsSL "https://api.github.com/repos/nortien/chan-sccp/releases/latest" 2>/dev/null) || return 1
    local asset_url
    asset_url=$(echo "$release_json" | grep -oE '"browser_download_url"[[:space:]]*:[[:space:]]*"[^"]*'"${asset_name}"'"' | grep -oE 'https://[^"]*') || true
    [ -n "$asset_url" ] || return 1

    log "Found matching binary, downloading: ${asset_url}"
    local tmp_so
    tmp_so=$(mktemp)
    curl -fsSL -o "$tmp_so" "$asset_url" || { rm -f "$tmp_so"; return 1; }
    if install_so "$tmp_so" "downloaded ${asset_name}"; then
        rm -f "$tmp_so"
        return 0
    fi
    rm -f "$tmp_so"
    return 1
}

# Order matters: bundled first (works offline), then download, then compile.
if [ -n "$DISTRO_TAG" ]; then
    if try_install_bundled "$DISTRO_TAG"; then
        PRECOMPILED_INSTALLED=1
    elif try_install_precompiled "$DISTRO_TAG"; then
        PRECOMPILED_INSTALLED=1
    else
        log "No precompiled binary for Asterisk ${ASTERISK_MAJOR}/${DISTRO_TAG}, bundled or published - will compile from source instead."
    fi
else
    log "Distro not recognized as one we publish binaries for - will compile from source instead."
fi

if [ "$PRECOMPILED_INSTALLED" -eq 0 ] && [ -t 0 ]; then
    read -r -p "Compile chan-sccp from source now? [Y/n] " REPLY
    case "$REPLY" in
        [nN]*) die "Aborted - no precompiled binary available and compiling declined." ;;
        *) : ;;
    esac
fi

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

if [ "$PRECOMPILED_INSTALLED" -eq 1 ]; then
    log "Skipping compiler/devel-headers and source build - precompiled binary already installed"
else

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
# configure would find the version by itself; the explicit pin keeps the build
# tied to the Asterisk this script measured with 'asterisk -V' even on a box
# with several sets of headers installed. Feature flags match the CI build
# (.github/workflows/build-release.yml in chan-sccp) - keep them in sync.
./configure --with-asterisk-version="${ASTERISK_MAJOR}.0" \
    --enable-conference --enable-advanced-functions \
    --enable-distributed-devicestate --enable-video \
    || die "./configure failed"
make -j"$(nproc)" || die "make failed"
make install || die "make install failed"

fi  # PRECOMPILED_INSTALLED

# ---------------------------------------------------------------------------
log "Excluding chan_skinny.so (required for chan-sccp to fully initialize)"
if grep -qE '^\s*noload\s*=\s*chan_skinny\.so' /etc/asterisk/modules.conf 2>/dev/null; then
    log "Already excluded, skipping"
else
    echo "noload = chan_skinny.so" >> /etc/asterisk/modules.conf
    log "Added noload entry"
fi

# The module's installer probes the TFTP server for real (writes a file and
# fetches it over tftp) and stops if the service is down, and FreePBX 16 ships
# its tftp-server disabled. This is the only step in the tarball install that
# runs as root, so switch the shipped server on here when it exists but is
# off; a box without one gets a hint and is otherwise left alone.
if systemctl list-unit-files 2>/dev/null | grep -q '^tftp\.socket'; then
    if ! systemctl is-active --quiet tftp.socket; then
        log "Enabling the shipped TFTP server (tftp.socket) - the module installer needs it running"
        systemctl enable --now tftp.socket || warn "Could not enable tftp.socket - enable your TFTP server before installing the module"
    fi
elif systemctl list-unit-files 2>/dev/null | grep -q '^tftpd-hpa\.service'; then
    if ! systemctl is-active --quiet tftpd-hpa; then
        log "Enabling the shipped TFTP server (tftpd-hpa) - the module installer needs it running"
        systemctl enable --now tftpd-hpa || warn "Could not enable tftpd-hpa - enable your TFTP server before installing the module"
    fi
elif [ -f /etc/xinetd.d/tftpd ] && grep -qE 'disable\s*=\s*yes' /etc/xinetd.d/tftpd; then
    log "Enabling the shipped TFTP server (xinetd tftpd) - the module installer needs it running"
    sed -i 's/disable\s*=\s*yes/disable         = no/' /etc/xinetd.d/tftpd
    systemctl restart xinetd 2>/dev/null || service xinetd restart 2>/dev/null || true
else
    warn "No TFTP server found (tftp.socket, tftpd-hpa or xinetd tftpd) - install one before installing the module"
fi

# chan-sccp refuses to load without /etc/asterisk/sccp.conf ("Config file
# 'sccp.conf' not found, aborting"), and on a box where the module has not
# been installed yet there is none - which made the verification below fail
# on every fresh install. Leave a two-line placeholder; sccp_manager's
# installer reads any existing file and rewrites it in full, so nothing here
# survives the module install. hotline_enabled=no keeps the driver from
# registering unknown phones in the meantime (the driver's own default is on).
SCCP_CONF=/etc/asterisk/sccp.conf
if [ ! -e "$SCCP_CONF" ]; then
    log "No ${SCCP_CONF} yet - writing a placeholder so the driver can start (sccp_manager replaces it on install)"
    printf '%s\n' \
        '[general]' \
        '; placeholder written by install-chan-sccp-driver.sh: the driver does not load without this file.' \
        '; SCCP Manager rewrites it in full when the module is installed.' \
        'hotline_enabled = no' > "$SCCP_CONF"
    chown asterisk:asterisk "$SCCP_CONF" 2>/dev/null || true
    chmod 0664 "$SCCP_CONF"
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
