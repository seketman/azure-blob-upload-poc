// Dedicated storage account with one container that allows anonymous read of
// individual blobs (no anonymous listing).
targetScope = 'resourceGroup'

@description('Azure region for the storage account.')
param location string = resourceGroup().location

@description('Name of the publicly readable container.')
param containerName string = 'public'

var storageAccountName = 'stupload${uniqueString(resourceGroup().id)}'

resource storageAccount 'Microsoft.Storage/storageAccounts@2023-05-01' = {
  name: storageAccountName
  location: location
  kind: 'StorageV2'
  sku: {
    name: 'Standard_LRS'
  }
  properties: {
    accessTier: 'Hot'
    // Account-level switch required before any container can be made public.
    allowBlobPublicAccess: true
    minimumTlsVersion: 'TLS1_2'
    supportsHttpsTrafficOnly: true
    // Needed to sign the service SAS used by the uploader.
    allowSharedKeyAccess: true
  }
}

resource blobService 'Microsoft.Storage/storageAccounts/blobServices@2023-05-01' = {
  parent: storageAccount
  name: 'default'
}

resource container 'Microsoft.Storage/storageAccounts/blobServices/containers@2023-05-01' = {
  parent: blobService
  name: containerName
  properties: {
    publicAccess: 'Blob'
  }
}

output storageAccountName string = storageAccount.name
output containerName string = container.name
output blobEndpoint string = storageAccount.properties.primaryEndpoints.blob
