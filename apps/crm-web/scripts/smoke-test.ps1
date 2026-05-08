$ErrorActionPreference = "Stop"

$base = "http://127.0.0.1:8000"
$phpExe = Join-Path $env:LOCALAPPDATA "Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
$phpDir = Split-Path $phpExe -Parent

function Assert-True($condition, $message) {
    if (-not $condition) {
        throw $message
    }
    Write-Host "[OK] $message"
}

function Get-Status($url, $session) {
    try {
        $response = Invoke-WebRequest -Uri $url -WebSession $session -UseBasicParsing -MaximumRedirection 0 -ErrorAction Stop
        return @{ Status = [int]$response.StatusCode; Content = $response.Content }
    } catch {
        if ($_.Exception.Response) {
            $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
            return @{ Status = [int]$_.Exception.Response.StatusCode; Content = $reader.ReadToEnd() }
        }
        throw
    }
}

function Login($username, $password) {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $login = Invoke-WebRequest -Uri "$base/index.php?page=login" -WebSession $session -UseBasicParsing
    $token = [regex]::Match($login.Content, 'name="csrf_token" value="([^"]+)"').Groups[1].Value
    Assert-True ($token.Length -gt 10) "$username için CSRF token alındı"
    $body = @{
        csrf_token = $token
        username = $username
        password = $password
    }
    Invoke-WebRequest -Uri "$base/index.php?page=login" -Method Post -Body $body -WebSession $session -UseBasicParsing | Out-Null
    $dashboard = Invoke-WebRequest -Uri "$base/index.php?page=dashboard" -WebSession $session -UseBasicParsing
    Assert-True ($dashboard.Content -match "Dashboard") "$username login başarılı"
    return $session
}

function Extract-Token($html) {
    return [regex]::Match($html, 'name="csrf_token" value="([^"]+)"').Groups[1].Value
}

function Cleanup-SmokeData() {
    & $phpExe -d "extension_dir=$phpDir\ext" -d "extension=pdo_sqlite" -d "extension=sqlite3" "scripts\cleanup-smoke-data.php" | Out-Null
}

function Post-And-Follow($url, $body, $session) {
    $pairs = foreach ($key in $body.Keys) {
        [System.Net.WebUtility]::UrlEncode([string]$key) + "=" + [System.Net.WebUtility]::UrlEncode([string]$body[$key])
    }
    $payload = [string]::Join("&", $pairs)
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($payload)
    $request = [System.Net.HttpWebRequest]::Create($url)
    $request.Method = "POST"
    $request.AllowAutoRedirect = $false
    $request.CookieContainer = $session.Cookies
    $request.ContentType = "application/x-www-form-urlencoded; charset=utf-8"
    $request.ContentLength = $bytes.Length
    $stream = $request.GetRequestStream()
    $stream.Write($bytes, 0, $bytes.Length)
    $stream.Close()

    try {
        $rawResponse = $request.GetResponse()
    } catch {
        $rawResponse = $_.Exception.Response
        if (-not $rawResponse) {
            throw
        }
    }

    $status = [int]$rawResponse.StatusCode
    if ($status -eq 302 -or $status -eq 303) {
        $location = $rawResponse.Headers["Location"]
        $rawResponse.Close()
        if ($location.StartsWith("/")) {
            $location = "$base$location"
        }
        return Invoke-WebRequest -Uri $location -WebSession $session -UseBasicParsing
    }

    $reader = New-Object System.IO.StreamReader($rawResponse.GetResponseStream())
    $content = $reader.ReadToEnd()
    $reader.Close()
    $responseUri = $rawResponse.ResponseUri
    $rawResponse.Close()
    return [pscustomobject]@{
        Content = $content
        BaseResponse = [pscustomobject]@{ ResponseUri = $responseUri }
    }
}

Cleanup-SmokeData

Assert-True ((Invoke-WebRequest -Uri "$base/assets/app.css" -UseBasicParsing).StatusCode -eq 200) "CSS dosyası yükleniyor"
Assert-True ((Invoke-WebRequest -Uri "$base/assets/brand/bilnex-logo.jpg" -UseBasicParsing).StatusCode -eq 200) "Logo dosyası yükleniyor"

$users = @(
    @{ Username = "test_admin"; Password = "Test123!admin"; CanUsers = $true; ExpectedVisible = "Test Test Saha" },
    @{ Username = "test_yonetici"; Password = "Test123!yonetici"; CanUsers = $false; ExpectedVisible = "Test Test Saha" },
    @{ Username = "test_kanal"; Password = "Test123!kanal"; CanUsers = $false; ExpectedVisible = "Test Test Bayi Kanal"; Hidden = "Test Test Saha" },
    @{ Username = "test_satis"; Password = "Test123!satis"; CanUsers = $false; ExpectedVisible = "Test Test Saha"; Hidden = "Test Test Bayi Kanal" }
)

$sessions = @{}
foreach ($u in $users) {
    $session = Login $u.Username $u.Password
    $sessions[$u.Username] = $session

    $companies = Invoke-WebRequest -Uri "$base/index.php?page=companies" -WebSession $session -UseBasicParsing
    Assert-True ($companies.Content -like "*$($u.ExpectedVisible)*") "$($u.Username) kendi kapsamındaki firmayı görüyor"
    if ($u.Hidden) {
        Assert-True ($companies.Content -notlike "*$($u.Hidden)*") "$($u.Username) başka personelin firmasını görmüyor"
    }

    foreach ($page in @("followups", "opportunities", "reports")) {
        $response = Get-Status "$base/index.php?page=$page" $session
        Assert-True ($response.Status -eq 200) "$($u.Username) $page sayfasına erişiyor"
    }

    $usersPage = Get-Status "$base/index.php?page=users" $session
    if ($u.CanUsers) {
        Assert-True ($usersPage.Status -eq 200 -and $usersPage.Content -match "test_admin") "$($u.Username) kullanıcı yönetimine erişiyor"
    } else {
        Assert-True ($usersPage.Status -eq 403) "$($u.Username) kullanıcı yönetimine erişemiyor"
    }
}

$kanalCompanies = Invoke-WebRequest -Uri "$base/index.php?page=companies" -WebSession $sessions["test_kanal"] -UseBasicParsing
$salesCompanies = Invoke-WebRequest -Uri "$base/index.php?page=companies" -WebSession $sessions["test_satis"] -UseBasicParsing
$kanalCompanyId = [regex]::Match($kanalCompanies.Content, 'company_view(?:&amp;|&)id=(\d+)').Groups[1].Value
$salesCompanyId = [regex]::Match($salesCompanies.Content, 'company_view(?:&amp;|&)id=(\d+)').Groups[1].Value
Assert-True ($kanalCompanyId -and $salesCompanyId) "Firma kartı linkleri okunuyor"

$salesForeign = Get-Status "$base/index.php?page=company_view&id=$kanalCompanyId" $sessions["test_satis"]
Assert-True ($salesForeign.Status -eq 403) "Satışçı başka personelin firma kartına erişemiyor"

$managerForeign = Get-Status "$base/index.php?page=company_view&id=$salesCompanyId" $sessions["test_yonetici"]
Assert-True ($managerForeign.Status -eq 200 -and $managerForeign.Content -match "Firma") "Yönetici tüm firma kartlarına erişiyor"

$salesSession = $sessions["test_satis"]
$stamp = Get-Date -Format "yyyyMMddHHmmss"
$companyName = "Smoke Firma $stamp"
$companyForm = Invoke-WebRequest -Uri "$base/index.php?page=company_form" -WebSession $salesSession -UseBasicParsing
$companyToken = Extract-Token $companyForm.Content
$companyResponse = Post-And-Follow "$base/index.php?page=save_company" @{
    csrf_token = $companyToken
    id = 0
    name = $companyName
    contact_person = "Smoke Yetkili"
    phone = "05550000000"
    email = "smoke$stamp@example.test"
    city = "Istanbul"
    district = "Kadikoy"
    address = "Smoke test adresi"
    status = "Yeni kayıt"
    source = "Smoke test"
    next_followup_date = (Get-Date).AddDays(1).ToString("yyyy-MM-dd")
    description = "Smoke test firma kaydi"
} $salesSession
$companyUrl = $companyResponse.BaseResponse.ResponseUri.AbsoluteUri
$createdCompanyId = [regex]::Match($companyUrl, 'id=(\d+)').Groups[1].Value
Assert-True ($createdCompanyId -and $companyResponse.Content -like "*$companyName*") "Satışçı firma oluşturabiliyor"

$interactionToken = Extract-Token $companyResponse.Content
$interactionResponse = Post-And-Follow "$base/index.php?page=save_interaction" @{
    csrf_token = $interactionToken
    company_id = $createdCompanyId
    interaction_date = (Get-Date).ToString("yyyy-MM-dd")
    type = "Telefon"
    result = "Olumlu"
    next_followup_date = (Get-Date).AddDays(2).ToString("yyyy-MM-dd")
    note = "Smoke gorusme notu $stamp"
} $salesSession
Assert-True ($interactionResponse.Content -like "*Smoke gorusme notu $stamp*") "Görüşme notu ekleniyor"

$opportunityForm = Invoke-WebRequest -Uri "$base/index.php?page=opportunity_form&company_id=$createdCompanyId" -WebSession $salesSession -UseBasicParsing
$opportunityToken = Extract-Token $opportunityForm.Content
$productName = "Smoke Urun $stamp"
Post-And-Follow "$base/index.php?page=save_opportunity" @{
    csrf_token = $opportunityToken
    id = 0
    company_id = $createdCompanyId
    product_service = $productName
    estimated_amount = "25000"
    stage = "Yeni fırsat"
    expected_close_date = (Get-Date).AddDays(10).ToString("yyyy-MM-dd")
    note = "Smoke satis firsati $stamp"
} $salesSession | Out-Null
$opportunitiesAfter = Invoke-WebRequest -Uri "$base/index.php?page=opportunities&q=$productName" -WebSession $salesSession -UseBasicParsing
Assert-True ($opportunitiesAfter.Content -like "*$productName*") "Satış fırsatı oluşturuluyor"

Cleanup-SmokeData

Write-Host "Smoke test tamamlandı."
