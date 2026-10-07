#!/usr/bin/env bash
# Removes every Azure resource created by deploy.sh.
set -euo pipefail

RESOURCE_GROUP="${RESOURCE_GROUP:-rg-upload-poc}"

echo "Deleting resource group $RESOURCE_GROUP..."
az group delete --name "$RESOURCE_GROUP"
echo "Done. The SAS token in .env is no longer valid."
