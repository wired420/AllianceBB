#!/bin/sh
# Creates .env with random secrets. Run once: ./setup.sh alpha.example.com beta.example.com you@example.com
set -e
cd "$(dirname "$0")"
if [ -f .env ]; then echo ".env already exists; edit it by hand."; exit 1; fi
if [ $# -ne 3 ]; then echo "Usage: ./setup.sh <alpha-domain> <beta-domain> <email>"; exit 1; fi
rand() { openssl rand -hex 32; }
cat > .env <<ENV
ALPHA_DOMAIN=$1
BETA_DOMAIN=$2
ACME_EMAIL=$3
DB_PASSWORD=$(rand)
INSTALL_TOKEN=$(openssl rand -hex 8)
ALPHA_MERCURE_KEY=$(rand)
BETA_MERCURE_KEY=$(rand)
ENV
chmod 600 .env
echo "Wrote .env. Your install token is: $(grep INSTALL_TOKEN .env | cut -d= -f2)"
echo "Next: docker compose up -d --build"
