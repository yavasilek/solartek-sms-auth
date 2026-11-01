param(
    [ValidateSet('Locate','Test','Publish')][string]$Action='Locate',
    [string]$Message=''
)
$ErrorActionPreference='Stop'
$moduleRoot=Split-Path -Parent $PSScriptRoot
if($Action -eq 'Locate'){Write-Output $moduleRoot;exit 0}
Push-Location $moduleRoot
try{
    & python scripts/test.py
    if($LASTEXITCODE -ne 0){throw 'Module checks failed'}
    & python scripts/build.py
    if($LASTEXITCODE -ne 0){throw 'Module build failed'}
    if($Action -eq 'Test'){exit 0}
    if([string]::IsNullOrWhiteSpace($Message)){throw 'Pass -Message with a concrete description of the change'}
    $branch=& git branch --show-current
    if($branch -ne 'main'){throw 'Merge the reviewed change into main before publishing; no automatic conflict resolution or force-push'}
    & git add -- plugin scripts tests .github README.md AGENTS.md LICENSE .gitignore
    if($LASTEXITCODE -ne 0){throw 'git add failed'}
    & git diff --cached --quiet
    if($LASTEXITCODE -ne 0){& git commit -m $Message;if($LASTEXITCODE -ne 0){throw 'git commit failed'}}
    & git push origin main
    if($LASTEXITCODE -ne 0){throw 'Push failed; preserve remote changes and resolve before retrying'}
    Write-Output 'Published main. Wait for GitHub Actions and verify the release before reporting completion.'
}finally{Pop-Location}
