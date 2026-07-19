<#
.SYNOPSIS
    Create / update / read a Confluence Cloud page via the REST API v2.

    Fallback for the read-only Atlassian Rovo MCP connector: the connector exposes
    no createConfluencePage/updateConfluencePage tools, so page publishing (e.g. the
    epic-review module docs) goes through the REST API with a personal API token —
    the same token-over-REST pattern /release-version uses for GitHub Releases.

.DESCRIPTION
    Credentials are read from environment variables, falling back to the repo-root
    .env.local (gitignored, alongside GITHUB_PERSONAL_ACCESS_TOKEN):
        CONFLUENCE_EMAIL      = poczta@leszekkoziatek.pl
        CONFLUENCE_API_TOKEN  = <token from id.atlassian.com -> Security -> API tokens>
        CONFLUENCE_BASE       = https://honemanager.atlassian.net/wiki   (default)

    Space HomeManager (key H): spaceId 1572867, home 1573107, hub 46661633.

.EXAMPLE
    # Validate auth without side effects (GET the space home)
    ./scripts/confluence-page.ps1 -Action test

.EXAMPLE
    # Create a page under the hub, body from a storage-format (XHTML) file
    ./scripts/confluence-page.ps1 -Action create -Title "Moduł: Request-id" `
        -ParentId 46661633 -BodyFile ./scratch/reqid.storage.html

.EXAMPLE
    # Update an existing page (version auto-incremented)
    ./scripts/confluence-page.ps1 -Action update -PageId 12345678 `
        -BodyFile ./scratch/reqid.storage.html -VersionMessage "HMAI-367 epic review"
#>

[CmdletBinding()]
param(
    [ValidateSet('test', 'create', 'update', 'get', 'delete')]
    [string]$Action = 'test',

    [string]$Title,
    [string]$BodyFile,
    [string]$PageId,
    [string]$ParentId,
    [string]$SpaceId = '1572867',
    [string]$Representation = 'storage',
    [string]$VersionMessage = 'Updated via confluence-page.ps1',

    [string]$EnvFile = "$PSScriptRoot/../.env.local"
)

$ErrorActionPreference = 'Stop'

function Get-Setting {
    param([string]$Name, [hashtable]$FromFile, [string]$Default)

    $value = [Environment]::GetEnvironmentVariable($Name)
    if ([string]::IsNullOrWhiteSpace($value) -and $FromFile.ContainsKey($Name)) {
        $value = $FromFile[$Name]
    }
    if ([string]::IsNullOrWhiteSpace($value)) {
        $value = $Default
    }
    return $value
}

# Parse KEY=VALUE lines from .env.local (ignores comments/blanks).
$fileSettings = @{}
if (Test-Path $EnvFile) {
    foreach ($line in [IO.File]::ReadAllLines($EnvFile)) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$') {
            $fileSettings[$Matches[1]] = $Matches[2].Trim().Trim('"')
        }
    }
}

$email = Get-Setting 'CONFLUENCE_EMAIL' $fileSettings ''
$token = Get-Setting 'CONFLUENCE_API_TOKEN' $fileSettings ''
$base  = Get-Setting 'CONFLUENCE_BASE' $fileSettings 'https://honemanager.atlassian.net/wiki'

if ([string]::IsNullOrWhiteSpace($email) -or [string]::IsNullOrWhiteSpace($token)) {
    throw "Missing CONFLUENCE_EMAIL / CONFLUENCE_API_TOKEN (set them in env or $EnvFile)."
}

$authBytes  = [Text.Encoding]::UTF8.GetBytes("$email`:$token")
$authHeader = 'Basic ' + [Convert]::ToBase64String($authBytes)
$headers    = @{ Authorization = $authHeader; Accept = 'application/json' }

function Invoke-Confluence {
    param([string]$Method, [string]$Path, $BodyObject)

    $uri = "$base$Path"
    if ($null -eq $BodyObject) {
        return Invoke-RestMethod -Method $Method -Uri $uri -Headers $headers
    }

    $json  = $BodyObject | ConvertTo-Json -Depth 12 -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($json)
    return Invoke-RestMethod -Method $Method -Uri $uri -Headers $headers `
        -ContentType 'application/json; charset=utf-8' -Body $bytes
}

switch ($Action) {

    'test' {
        $id = if ($PageId) { $PageId } else { '1573107' }  # space H home
        $page = Invoke-Confluence 'GET' "/api/v2/pages/$id"
        Write-Host "AUTH OK as $email"
        Write-Host "Page $($page.id): '$($page.title)' (version $($page.version.number))"
    }

    'get' {
        if (-not $PageId) { throw "-PageId is required for 'get'." }
        $page = Invoke-Confluence 'GET' "/api/v2/pages/$($PageId)?body-format=$Representation"
        Write-Host "Page $($page.id): '$($page.title)' (version $($page.version.number))"
        Write-Output $page.body.$Representation.value
    }

    'create' {
        if (-not $Title)    { throw "-Title is required for 'create'." }
        if (-not $BodyFile) { throw "-BodyFile is required for 'create'." }
        if (-not (Test-Path $BodyFile)) { throw "BodyFile not found: $BodyFile" }

        $value = [IO.File]::ReadAllText((Resolve-Path $BodyFile), [Text.Encoding]::UTF8)
        $body = @{
            spaceId = $SpaceId
            status  = 'current'
            title   = $Title
            body    = @{ representation = $Representation; value = $value }
        }
        if ($ParentId) { $body.parentId = $ParentId }

        $page = Invoke-Confluence 'POST' '/api/v2/pages' $body
        Write-Host "CREATED page $($page.id): '$($page.title)'"
        Write-Host "URL: $base$($page._links.webui)"
    }

    'update' {
        if (-not $PageId)   { throw "-PageId is required for 'update'." }
        if (-not $BodyFile) { throw "-BodyFile is required for 'update'." }
        if (-not (Test-Path $BodyFile)) { throw "BodyFile not found: $BodyFile" }

        $current = Invoke-Confluence 'GET' "/api/v2/pages/$PageId"
        $nextVersion = [int]$current.version.number + 1
        $newTitle = if ($Title) { $Title } else { $current.title }
        $value = [IO.File]::ReadAllText((Resolve-Path $BodyFile), [Text.Encoding]::UTF8)

        $body = @{
            id      = $PageId
            status  = 'current'
            title   = $newTitle
            body    = @{ representation = $Representation; value = $value }
            version = @{ number = $nextVersion; message = $VersionMessage }
        }

        $page = Invoke-Confluence 'PUT' "/api/v2/pages/$PageId" $body
        Write-Host "UPDATED page $($page.id): '$($page.title)' -> version $($page.version.number)"
        Write-Host "URL: $base$($page._links.webui)"
    }

    'delete' {
        if (-not $PageId) { throw "-PageId is required for 'delete'." }
        Invoke-Confluence 'DELETE' "/api/v2/pages/$PageId" | Out-Null
        Write-Host "DELETED page $PageId (moved to trash)"
    }
}
