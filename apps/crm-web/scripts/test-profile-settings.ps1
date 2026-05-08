$ErrorActionPreference = "Stop"

$base = "http://127.0.0.1:8011"
$webRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$phpExe = Join-Path $env:LOCALAPPDATA "Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
$phpDir = Split-Path $phpExe -Parent

function Assert-True($condition, $message) {
    if (-not $condition) {
        throw "[FAIL] $message"
    }
    Write-Host "[OK] $message"
}

function Php($code) {
    $snippet = Join-Path $webRoot "data\profile-test-snippet.php"
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($snippet, "<?php`n" + $code, $utf8NoBom)
    Push-Location $webRoot
    try {
        & $phpExe -d "extension_dir=$phpDir\ext" -d "extension=pdo_sqlite" -d "extension=sqlite3" $snippet
    } finally {
        Pop-Location
        Remove-Item -LiteralPath $snippet -ErrorAction SilentlyContinue
    }
}

function Php-Text($code) {
    $result = Php $code
    if ($null -eq $result) {
        return ""
    }
    return ([string]::Join("", @($result))).Trim()
}

function Extract-Token($html) {
    return [regex]::Match($html, 'name="csrf_token" value="([^"]+)"').Groups[1].Value
}

function Login($username, $password) {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $login = Invoke-WebRequest -Uri "$base/index.php?page=login" -WebSession $session -UseBasicParsing
    $token = Extract-Token $login.Content
    Assert-True ($token.Length -gt 10) "$username CSRF token"
    Invoke-WebRequest -Uri "$base/index.php?page=login" -Method Post -Body @{
        csrf_token = $token
        username = $username
        password = $password
    } -WebSession $session -UseBasicParsing | Out-Null
    $profile = Invoke-WebRequest -Uri "$base/index.php?page=profile" -WebSession $session -UseBasicParsing
    Assert-True ($profile.Content -like "*profile-hero*") "$username login"
    return $session
}

function Post-Multipart($url, $fields, $fileField, $filePath, $session) {
    $boundary = "----BilnexProfileBoundary" + [Guid]::NewGuid().ToString("N")
    $request = [System.Net.HttpWebRequest]::Create($url)
    $request.Method = "POST"
    $request.AllowAutoRedirect = $false
    $request.CookieContainer = $session.Cookies
    $request.ContentType = "multipart/form-data; boundary=$boundary"

    $ms = New-Object System.IO.MemoryStream
    $encoding = [System.Text.Encoding]::UTF8
    foreach ($key in $fields.Keys) {
        $part = "--$boundary`r`nContent-Disposition: form-data; name=`"$key`"`r`n`r`n$($fields[$key])`r`n"
        $bytes = $encoding.GetBytes($part)
        $ms.Write($bytes, 0, $bytes.Length)
    }
    $fileName = [System.IO.Path]::GetFileName($filePath)
    $header = "--$boundary`r`nContent-Disposition: form-data; name=`"$fileField`"; filename=`"$fileName`"`r`nContent-Type: image/png`r`n`r`n"
    $headerBytes = $encoding.GetBytes($header)
    $ms.Write($headerBytes, 0, $headerBytes.Length)
    $fileBytes = [System.IO.File]::ReadAllBytes($filePath)
    $ms.Write($fileBytes, 0, $fileBytes.Length)
    $tailBytes = $encoding.GetBytes("`r`n--$boundary--`r`n")
    $ms.Write($tailBytes, 0, $tailBytes.Length)

    $payload = $ms.ToArray()
    $request.ContentLength = $payload.Length
    $stream = $request.GetRequestStream()
    $stream.Write($payload, 0, $payload.Length)
    $stream.Close()

    try {
        $response = $request.GetResponse()
    } catch {
        $response = $_.Exception.Response
        if (-not $response) {
            throw
        }
    }

    $status = [int]$response.StatusCode
    $location = $response.Headers["Location"]
    if ($status -ne 302 -and $status -ne 303) {
        $reader = New-Object System.IO.StreamReader($response.GetResponseStream())
        $content = $reader.ReadToEnd()
        $reader.Close()
        $response.Close()
        throw "[FAIL] profile photo upload redirects (status $status): $content"
    }
    $response.Close()
    Write-Host "[OK] profile photo upload redirects"
    if ($location.StartsWith("/")) {
        $location = "$base$location"
    }
    return Invoke-WebRequest -Uri $location -WebSession $session -UseBasicParsing
}

if (!(Test-Path $phpExe)) {
    throw "PHP 8.4 was not found at $phpExe"
}

Push-Location $webRoot
try {
    $env:CRM_COMPANY_SOURCE = "sqlite"
    & $phpExe -d "extension_dir=$phpDir\ext" -d "extension=pdo_sqlite" -d "extension=sqlite3" scripts\seed-test-data.php | Out-Null
    $existingAvatar = Php-Text "require 'app/bootstrap.php'; echo (string) db()->query(""SELECT avatar_path FROM users WHERE username = 'test_admin'"")->fetchColumn();"
    if ($existingAvatar) {
        Remove-Item -LiteralPath (Join-Path $webRoot $existingAvatar) -ErrorAction SilentlyContinue
    }
    Php @'
require 'app/bootstrap.php';
db()->exec("UPDATE users SET avatar_path = NULL WHERE username = 'test_admin'");
db()->prepare("UPDATE users SET password_hash = :password_hash WHERE username = 'test_admin'")
    ->execute([':password_hash' => password_hash('Test123!admin', PASSWORD_DEFAULT)]);
'@ | Out-Null
} finally {
    Pop-Location
}

$server = Start-Job -ArgumentList $phpExe, $phpDir, $webRoot -ScriptBlock {
    param($phpExe, $phpDir, $webRoot)
    Set-Location $webRoot
    $env:CRM_BASE_URL = ""
    $env:CRM_COMPANY_SOURCE = "sqlite"
    & $phpExe -d "extension_dir=$phpDir\ext" -d "extension=pdo_sqlite" -d "extension=sqlite3" -S 127.0.0.1:8011 -t $webRoot
}

$avatarPath = ""
$tempImage = Join-Path $webRoot "data\profile-upload-test.png"
try {
    Start-Sleep -Milliseconds 900
    Assert-True ((Invoke-WebRequest -Uri "$base/index.php?page=login" -UseBasicParsing).StatusCode -eq 200) "local server started"

    $session = Login "test_admin" "Test123!admin"
    $dashboard = Invoke-WebRequest -Uri "$base/index.php?page=dashboard" -WebSession $session -UseBasicParsing
    Assert-True ($dashboard.Content -like "*profile-menu*" -and $dashboard.Content -like "*profile-avatar*") "sidebar profile menu renders"

    $profile = Invoke-WebRequest -Uri "$base/index.php?page=profile" -WebSession $session -UseBasicParsing
    Assert-True ($profile.Content -like "*update_profile_photo*" -and $profile.Content -like "*change_password*") "profile forms render"

    $badToken = Extract-Token $profile.Content
    $badPassword = Invoke-WebRequest -Uri "$base/index.php?page=change_password" -Method Post -Body @{
        csrf_token = $badToken
        current_password = "wrong-password"
        new_password = "Temp123!profile"
        confirm_password = "Temp123!profile"
    } -WebSession $session -UseBasicParsing
    Assert-True ($badPassword.Content -like "*alert-danger*") "wrong current password is rejected"

    $profile = Invoke-WebRequest -Uri "$base/index.php?page=profile" -WebSession $session -UseBasicParsing
    $token = Extract-Token $profile.Content
    Invoke-WebRequest -Uri "$base/index.php?page=change_password" -Method Post -Body @{
        csrf_token = $token
        current_password = "Test123!admin"
        new_password = "Temp123!profile"
        confirm_password = "Temp123!profile"
    } -WebSession $session -UseBasicParsing | Out-Null
    Login "test_admin" "Temp123!profile" | Out-Null
    Assert-True $true "changed password can log in"

    $session = Login "test_admin" "Temp123!profile"
    $profile = Invoke-WebRequest -Uri "$base/index.php?page=profile" -WebSession $session -UseBasicParsing
    $token = Extract-Token $profile.Content
    Invoke-WebRequest -Uri "$base/index.php?page=change_password" -Method Post -Body @{
        csrf_token = $token
        current_password = "Temp123!profile"
        new_password = "Test123!admin"
        confirm_password = "Test123!admin"
    } -WebSession $session -UseBasicParsing | Out-Null
    Login "test_admin" "Test123!admin" | Out-Null
    Assert-True $true "password reset works"

    [Convert]::FromBase64String("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=") | Set-Content -Path $tempImage -Encoding Byte
    $session = Login "test_admin" "Test123!admin"
    $profile = Invoke-WebRequest -Uri "$base/index.php?page=profile" -WebSession $session -UseBasicParsing
    $token = Extract-Token $profile.Content
    $uploadResponse = Post-Multipart "$base/index.php?page=update_profile_photo" @{ csrf_token = $token } "avatar" $tempImage $session
    if ($uploadResponse.Content -like "*alert-danger*") {
        throw "[FAIL] profile photo upload returned an error"
    }

    $avatarPath = Php-Text "require 'app/bootstrap.php'; echo (string) db()->query(""SELECT avatar_path FROM users WHERE username = 'test_admin'"")->fetchColumn();"
    Assert-True ($avatarPath -like "data/profile-photos/user-*") "avatar path saved"
    Assert-True (Test-Path (Join-Path $webRoot $avatarPath)) "avatar file exists"

    $logoutProfile = Invoke-WebRequest -Uri "$base/index.php?page=profile" -WebSession $session -UseBasicParsing
    $logoutToken = Extract-Token $logoutProfile.Content
    Invoke-WebRequest -Uri "$base/index.php?page=logout" -Method Post -Body @{ csrf_token = $logoutToken } -WebSession $session -UseBasicParsing | Out-Null
    $afterLogout = Invoke-WebRequest -Uri "$base/index.php?page=dashboard" -WebSession $session -UseBasicParsing
    Assert-True ($afterLogout.Content -like "*login-panel*") "logout returns to login"
} finally {
    if ($avatarPath) {
        Remove-Item -LiteralPath (Join-Path $webRoot $avatarPath) -ErrorAction SilentlyContinue
    }
    Remove-Item -LiteralPath $tempImage -ErrorAction SilentlyContinue
    Push-Location $webRoot
    try {
        $cleanupCode = @'
require 'app/bootstrap.php';
db()->exec("UPDATE users SET avatar_path = NULL WHERE username = 'test_admin'");
db()->prepare("UPDATE users SET password_hash = :password_hash WHERE username = 'test_admin'")
    ->execute([':password_hash' => password_hash('Test123!admin', PASSWORD_DEFAULT)]);
'@
        Php $cleanupCode | Out-Null
    } finally {
        Pop-Location
    }
    Stop-Job $server -ErrorAction SilentlyContinue
    Remove-Job $server -Force -ErrorAction SilentlyContinue
}

Write-Host "Profile settings test completed."
