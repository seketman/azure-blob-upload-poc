#!/usr/bin/env bash
# Provisions the storage account and container, creates a create-only stored
# access policy, issues a SAS bound to it and writes the result to .env.
set -euo pipefail

RESOURCE_GROUP="${RESOURCE_GROUP:-rg-upload-poc}"
LOCATION="${LOCATION:-brazilsouth}"
SAS_DAYS="${SAS_DAYS:-30}"
POLICY_NAME="${POLICY_NAME:-upload-policy}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$REPO_ROOT/.env"

case "$SAS_DAYS" in
  '' | 0* | *[!0-9]*) echo "SAS_DAYS must be a positive integer" >&2; exit 1 ;;
esac

for tool in az openssl; do
  command -v "$tool" >/dev/null 2>&1 || { echo "Required tool not found: $tool" >&2; exit 1; }
done

# BSD date (macOS) and GNU date use different flags for date arithmetic.
if EXPIRY="$(date -u -v+"${SAS_DAYS}"d '+%Y-%m-%dT%H:%MZ' 2>/dev/null)"; then
  :
else
  EXPIRY="$(date -u -d "+${SAS_DAYS} days" '+%Y-%m-%dT%H:%MZ')"
fi

echo "Creating resource group $RESOURCE_GROUP in $LOCATION..."
az group create --name "$RESOURCE_GROUP" --location "$LOCATION" --output none

echo "Deploying infra/main.bicep..."
OUTPUTS="$(az deployment group create \
  --resource-group "$RESOURCE_GROUP" \
  --name upload-poc \
  --template-file "$SCRIPT_DIR/main.bicep" \
  --query '[properties.outputs.storageAccountName.value, properties.outputs.containerName.value]' \
  --output tsv)"
STORAGE_ACCOUNT="$(printf '%s\n' "$OUTPUTS" | sed -n '1p')"
STORAGE_CONTAINER="$(printf '%s\n' "$OUTPUTS" | sed -n '2p')"

if [ -z "$STORAGE_ACCOUNT" ] || [ -z "$STORAGE_CONTAINER" ]; then
  echo "Could not read the deployment outputs" >&2
  exit 1
fi

# Passed through the environment so the key never shows up in a process list.
export AZURE_STORAGE_ACCOUNT="$STORAGE_ACCOUNT"
AZURE_STORAGE_KEY="$(az storage account keys list \
  --resource-group "$RESOURCE_GROUP" \
  --account-name "$STORAGE_ACCOUNT" \
  --query '[0].value' --output tsv)"
export AZURE_STORAGE_KEY

# A stored access policy is a data-plane object, so it cannot be declared in
# the Bicep template. "c" = create only: no overwrite, read, list or delete.
echo "Creating stored access policy $POLICY_NAME..."
# "policy show" exits 0 for a missing policy, so existence is read from the list.
EXISTING_POLICIES="$(az storage container policy list \
  --container-name "$STORAGE_CONTAINER" --query 'keys(@)' --output tsv)"
if printf '%s\n' "$EXISTING_POLICIES" | tr '\t' '\n' | grep -Fxq "$POLICY_NAME"; then
  az storage container policy update \
    --container-name "$STORAGE_CONTAINER" --name "$POLICY_NAME" \
    --permissions c --expiry "$EXPIRY" --output none
else
  az storage container policy create \
    --container-name "$STORAGE_CONTAINER" --name "$POLICY_NAME" \
    --permissions c --expiry "$EXPIRY" --output none
fi

SAS_TOKEN="$(az storage container generate-sas \
  --name "$STORAGE_CONTAINER" \
  --policy-name "$POLICY_NAME" \
  --https-only \
  --output tsv)"
unset AZURE_STORAGE_KEY

if [ -z "$SAS_TOKEN" ]; then
  echo "SAS generation returned an empty token" >&2
  exit 1
fi

# Keep the API key stable across re-deployments.
API_KEY=""
if [ -f "$ENV_FILE" ]; then
  API_KEY="$(sed -n 's/^UPLOAD_API_KEY=//p' "$ENV_FILE" | head -n 1 | tr -d "'\"")"
fi
if [ -z "$API_KEY" ]; then
  API_KEY="$(openssl rand -hex 24)"
fi

umask 077
cat > "$ENV_FILE" <<ENV
STORAGE_ACCOUNT=$STORAGE_ACCOUNT
STORAGE_CONTAINER=$STORAGE_CONTAINER
STORAGE_SAS_TOKEN='$SAS_TOKEN'
UPLOAD_MAX_BYTES=10485760
UPLOAD_ALLOWED_MIME=application/pdf,image/png,image/jpeg
UPLOAD_API_KEY=$API_KEY
ENV
chmod 600 "$ENV_FILE"

echo
echo "Storage account: $STORAGE_ACCOUNT"
echo "Container:       $STORAGE_CONTAINER"
echo "SAS expires:     $EXPIRY (re-run this script to renew)"
echo "Configuration written to .env (contains secrets; not committed)."
echo "Note: a new stored access policy can take about 30 seconds to become effective."
