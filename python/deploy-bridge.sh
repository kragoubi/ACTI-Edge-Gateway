#!/bin/bash
# =============================================================================
# ACTI Edge Gateway — Interlock Bridge Deployment Script
# =============================================================================
#
# Run this on VM#2 (Ubuntu 22.04) to install the Python interlock bridge.
#
# Prerequisites:
#   - Ubuntu 22.04 LTS
#   - Python 3.10+
#   - lib_actilock.so installed at /usr/lib/lib_actilock.so
#   - Laravel backend running on port 8000
#
# Usage:
#   sudo bash deploy-bridge.sh
# =============================================================================

set -euo pipefail

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  ACTI Edge Gateway — Bridge Deployer  ${NC}"
echo -e "${GREEN}========================================${NC}"

# ── Check root ──
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}Error: Please run as root (sudo)${NC}"
    exit 1
fi

# ── Create directories ──
echo -e "${YELLOW}Creating directories...${NC}"
mkdir -p /opt/aeg/python
mkdir -p /var/log/aeg
mkdir -p /etc/aeg

# ── Copy files ──
echo -e "${YELLOW}Installing bridge files...${NC}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cp "$SCRIPT_DIR/interlock_bridge.py" /opt/aeg/python/
chmod +x /opt/aeg/python/interlock_bridge.py

# ── Create service user ──
echo -e "${YELLOW}Creating service user...${NC}"
if ! id -u aeg >/dev/null 2>&1; then
    useradd --system --shell /bin/false --home-dir /opt/aeg aeg
    echo -e "${GREEN}Created user: aeg${NC}"
else
    echo -e "${GREEN}User 'aeg' already exists${NC}"
fi

# ── Set permissions ──
echo -e "${YELLOW}Setting permissions...${NC}"
chown -R aeg:aeg /opt/aeg
chown -R aeg:aeg /var/log/aeg
chmod 755 /opt/aeg/python/interlock_bridge.py

# ── Check lib_actilock.so ──
echo -e "${YELLOW}Checking lib_actilock.so...${NC}"
if [ -f /usr/lib/lib_actilock.so ]; then
    echo -e "${GREEN}lib_actilock.so found at /usr/lib/lib_actilock.so${NC}"
    chmod 755 /usr/lib/lib_actilock.so
else
    echo -e "${RED}Warning: lib_actilock.so not found at /usr/lib/lib_actilock.so${NC}"
    echo -e "${YELLOW}Please install it before starting the bridge.${NC}"
fi

# ── Install systemd service ──
echo -e "${YELLOW}Installing systemd service...${NC}"
cp "$SCRIPT_DIR/aeg-interlock.service" /etc/systemd/system/
systemctl daemon-reload

# ── Configure .env.interlock ──
if [ ! -f /opt/aeg/.env.interlock ]; then
    echo -e "${YELLOW}Creating .env.interlock from template...${NC}"
    cp "$SCRIPT_DIR/.env.interlock.example" /opt/aeg/.env.interlock
    chown aeg:aeg /opt/aeg/.env.interlock
    chmod 600 /opt/aeg/.env.interlock
    echo -e "${YELLOW}Please edit /opt/aeg/.env.interlock with your configuration.${NC}"
else
    echo -e "${GREEN}.env.interlock already exists — skipping${NC}"
fi

# ── Enable and start service ──
echo -e "${YELLOW}Enabling service...${NC}"
systemctl enable aeg-interlock.service

echo -e "${YELLOW}Starting service...${NC}"
systemctl start aeg-interlock.service

# ── Check status ──
sleep 2
if systemctl is-active --quiet aeg-interlock.service; then
    echo -e "${GREEN}========================================${NC}"
    echo -e "${GREEN}  Bridge installed and running!         ${NC}"
    echo -e "${GREEN}========================================${NC}"
    echo ""
    echo "Status:"
    systemctl status aeg-interlock.service --no-pager
    echo ""
    echo "Logs:"
    echo "  journalctl -u aeg-interlock -f"
    echo ""
    echo "Configuration:"
    echo "  /opt/aeg/.env.interlock"
    echo ""
    echo "Test:"
    echo "  echo -n -e '\x02\x13\x17STATUS\`TEST_SFC\x03' | nc localhost 5000"
else
    echo -e "${RED}Error: Service failed to start${NC}"
    echo "Check logs: journalctl -u aeg-interlock -n 50"
    exit 1
fi
