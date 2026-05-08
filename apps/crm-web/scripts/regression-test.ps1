$ErrorActionPreference = "Stop"

$base = "http://127.0.0.1:8000"
$phpExe = Join-Path $env:LOCALAPPDATA "Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
$phpDir = Split-Path $phpExe -Parent

function Assert-True($condition, $message) {
    if (-not $condition) {
        throw "[FAIL] $message"
    }
    Write-Host "[OK] $message"
}

function Php($code) {
    & $phpExe -d "extension_dir=$phpDir\ext" -d "extension=pdo_sqlite" -d "extension=sqlite3" -r $code
}

function Get-Status($url, $session) {
    $request = [System.Net.HttpWebRequest]::Create($url)
    $request.Method = "GET"
    $request.AllowAutoRedirect = $false
    $request.CookieContainer = $session.Cookies
    try {
        $rawResponse = $request.GetResponse()
    } catch {
        $rawResponse = $_.Exception.Response
        if (-not $rawResponse -and $_.Exception.InnerException) {
            $rawResponse = $_.Exception.InnerException.Response
        }
        if (-not $rawResponse) {
            throw
        }
    }
    $reader = New-Object System.IO.StreamReader($rawResponse.GetResponseStream())
    $content = $reader.ReadToEnd()
    $reader.Close()
    $result = @{
        Status = [int]$rawResponse.StatusCode
        Content = $content
        Location = $rawResponse.Headers["Location"]
        Uri = $rawResponse.ResponseUri.AbsoluteUri
    }
    $rawResponse.Close()
    return $result
}

function Extract-Token($html) {
    return [regex]::Match($html, 'name="csrf_token" value="([^"]+)"').Groups[1].Value
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
        if (-not $rawResponse -and $_.Exception.InnerException) {
            $rawResponse = $_.Exception.InnerException.Response
        }
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
    return [pscustomobject]@{ Content = $content; BaseResponse = [pscustomobject]@{ ResponseUri = $responseUri } }
}

function Login($username, $password) {
    $session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $login = Invoke-WebRequest -Uri "$base/index.php?page=login" -WebSession $session -UseBasicParsing
    $token = Extract-Token $login.Content
    Assert-True ($token.Length -gt 10) "$username login token"
    Invoke-WebRequest -Uri "$base/index.php?page=login" -Method Post -Body @{ csrf_token = $token; username = $username; password = $password } -WebSession $session -UseBasicParsing | Out-Null
    $dashboard = Invoke-WebRequest -Uri "$base/index.php?page=dashboard" -WebSession $session -UseBasicParsing
    Assert-True ($dashboard.Content -like "*Dashboard*") "$username login"
    return $session
}

function Cleanup-RegressionData() {
    Php @'
require 'app/bootstrap.php';
db()->exec("DELETE FROM companies WHERE source IN ('Regression test', 'Smoke test')");
db()->exec("DELETE FROM users WHERE username LIKE 'reg_%'");
'@ | Out-Null
}

& $phpExe -d "extension_dir=$phpDir\ext" -d "extension=pdo_sqlite" -d "extension=sqlite3" scripts\seed-test-data.php | Out-Null
Cleanup-RegressionData

$assets = @(
    "/assets/app.css",
    "/assets/brand/bilnex-logo.jpg",
    "/assets/brand/bilnex-platform.webp",
    "/assets/brand/bilnex-office.webp"
)
foreach ($asset in $assets) {
    Assert-True ((Invoke-WebRequest -Uri "$base$asset" -UseBasicParsing).StatusCode -eq 200) "asset $asset"
}

$anonymous = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$anonDashboard = Get-Status "$base/index.php?page=dashboard" $anonymous
Assert-True ($anonDashboard.Status -eq 302 -and $anonDashboard.Location -like "*page=login*") "anon dashboard login'e yÃ¶nlenir"
$badLogin = Post-And-Follow "$base/index.php?page=login" @{ csrf_token = (Extract-Token (Invoke-WebRequest -Uri "$base/index.php?page=login" -WebSession $anonymous -UseBasicParsing).Content); username = "bad"; password = "bad" } $anonymous
Assert-True ($badLogin.Content -like "*alert-danger*") "hatalÄ± login mesajÄ±"

$sessions = @{
    admin = Login "test_admin" "Test123!admin"
    manager = Login "test_yonetici" "Test123!yonetici"
    channel = Login "test_kanal" "Test123!kanal"
    sales = Login "test_satis" "Test123!satis"
}

$pages = @("dashboard", "companies", "followups", "opportunities", "reports", "company_form", "opportunity_form")
foreach ($role in $sessions.Keys) {
    foreach ($page in $pages) {
        $status = Get-Status "$base/index.php?page=$page" $sessions[$role]
        Assert-True ($status.Status -eq 200) "$role GET $page"
    }
}
Assert-True ((Get-Status "$base/index.php?page=users" $sessions.admin).Status -eq 200) "admin users GET"
foreach ($role in @("manager", "channel", "sales")) {
    Assert-True ((Get-Status "$base/index.php?page=users" $sessions[$role]).Status -eq 403) "$role users forbidden"
}

$navPages = @("dashboard", "users", "companies", "followups", "opportunities", "reports", "company_form")
foreach ($page in $navPages) {
    $status = Get-Status "$base/index.php?page=$page" $sessions.admin
    Assert-True (($status.Status -eq 200 -or $status.Status -eq 302)) "admin nav/button $page"
}
$logoutStatus = Get-Status "$base/index.php?page=logout" $sessions.manager
Assert-True ($logoutStatus.Status -eq 302) "logout link yÃ¶nlenir"

$adminUsers = Invoke-WebRequest -Uri "$base/index.php?page=users" -WebSession $sessions.admin -UseBasicParsing
$userToken = Extract-Token $adminUsers.Content
$stamp = Get-Date -Format "yyyyMMddHHmmss"
$regUser = "reg_user_$stamp"
$createdUserPage = Post-And-Follow "$base/index.php?page=save_user" @{
    csrf_token = $userToken
    id = 0
    full_name = "Regression User"
    username = $regUser
    password = "Reg123!test"
    role = "satis"
    active = "on"
} $sessions.admin
Assert-True ($createdUserPage.Content -like "*$regUser*") "admin kullanÄ±cÄ± oluÅŸturur"
$dupToken = Extract-Token $createdUserPage.Content
$dupPage = Post-And-Follow "$base/index.php?page=save_user" @{
    csrf_token = $dupToken
    id = 0
    full_name = "Regression User Duplicate"
    username = $regUser
    password = "Reg123!test"
    role = "satis"
    active = "on"
} $sessions.admin
Assert-True ($dupPage.Content -like "*alert-danger*") "duplicate kullanÄ±cÄ± engellenir"
$forbiddenCreate = Get-Status "$base/index.php?page=save_user" $sessions.sales
Assert-True ($forbiddenCreate.Status -eq 403 -or $forbiddenCreate.Status -eq 404) "non-admin kullanÄ±cÄ± POST endpointi GET ile iÅŸlem yapmaz"

$salesCompanyForm = Invoke-WebRequest -Uri "$base/index.php?page=company_form" -WebSession $sessions.sales -UseBasicParsing
$companyToken = Extract-Token $salesCompanyForm.Content
$companyName = "Regression Firma $stamp"
$companyPage = Post-And-Follow "$base/index.php?page=save_company" @{
    csrf_token = $companyToken
    id = 0
    name = $companyName
    account_type = "Son KullanÄ±cÄ±"
    contact_person = "Regression Yetkili"
    phone = "05551112233"
    email = "regression@example.test"
    city = "Ä°stanbul"
    district = "KadÄ±kÃ¶y"
    address = "Regression adres"
    status = "Yeni kayÄ±t"
    source = "Regression test"
    next_followup_date = (Get-Date).AddDays(1).ToString("yyyy-MM-dd")
    description = "Regression aÃ§Ä±klama"
} $sessions.sales
$companyId = [regex]::Match($companyPage.BaseResponse.ResponseUri.AbsoluteUri, 'id=(\d+)').Groups[1].Value
Assert-True ($companyId -and $companyPage.Content -like "*$companyName*") "satÄ±ÅŸÃ§Ä± firma oluÅŸturur"

$editToken = Extract-Token (Invoke-WebRequest -Uri "$base/index.php?page=company_form&id=$companyId" -WebSession $sessions.sales -UseBasicParsing).Content
$updatedCompanyName = "$companyName GÃ¼ncel"
$updatedCompany = Post-And-Follow "$base/index.php?page=save_company" @{
    csrf_token = $editToken
    id = $companyId
    name = $updatedCompanyName
    account_type = "Ä°ÅŸ OrtaÄŸÄ±"
    contact_person = "Regression Yetkili 2"
    phone = "05554445566"
    email = "regression2@example.test"
    city = "Ankara"
    district = "Ã‡ankaya"
    address = "Regression adres 2"
    status = "Takipte"
    source = "Regression test"
    next_followup_date = (Get-Date).AddDays(3).ToString("yyyy-MM-dd")
    description = "Regression aÃ§Ä±klama gÃ¼ncel"
} $sessions.sales
Assert-True ($updatedCompany.Content -like "*$updatedCompanyName*") "firma dÃ¼zenlenir"

$interactionToken = Extract-Token $updatedCompany.Content
$interactionNote = "Regression gÃ¶rÃ¼ÅŸme $stamp"
$afterInteraction = Post-And-Follow "$base/index.php?page=save_interaction" @{
    csrf_token = $interactionToken
    company_id = $companyId
    interaction_date = (Get-Date).ToString("yyyy-MM-dd")
    type = "WhatsApp"
    result = "Demo istiyor"
    next_followup_date = (Get-Date).AddDays(4).ToString("yyyy-MM-dd")
    note = $interactionNote
} $sessions.sales
Assert-True ($afterInteraction.Content -like "*$interactionNote*") "gÃ¶rÃ¼ÅŸme notu eklenir"

$oppForm = Invoke-WebRequest -Uri "$base/index.php?page=opportunity_form&company_id=$companyId" -WebSession $sessions.sales -UseBasicParsing
$oppToken = Extract-Token $oppForm.Content
$product = "Regression Urun $stamp"
Post-And-Follow "$base/index.php?page=save_opportunity" @{
    csrf_token = $oppToken
    id = 0
    company_id = $companyId
    product_service = $product
    estimated_amount = "34567,89"
    stage = "Teklif verildi"
    expected_close_date = (Get-Date).AddDays(12).ToString("yyyy-MM-dd")
    note = "Regression fÄ±rsat"
} $sessions.sales | Out-Null
$oppList = Invoke-WebRequest -Uri "$base/index.php?page=opportunities&q=$([System.Net.WebUtility]::UrlEncode($product))" -WebSession $sessions.sales -UseBasicParsing
Assert-True ($oppList.Content -like "*$product*") "satÄ±ÅŸ fÄ±rsatÄ± oluÅŸturulur ve aranÄ±r"
$oppId = [regex]::Match($oppList.Content, 'opportunity_form(?:&amp;|&)id=(\d+)').Groups[1].Value
Assert-True ($oppId) "fÄ±rsat dÃ¼zenle linki var"
$oppEditForm = Invoke-WebRequest -Uri "$base/index.php?page=opportunity_form&id=$oppId" -WebSession $sessions.sales -UseBasicParsing
$oppEditToken = Extract-Token $oppEditForm.Content
$productUpdated = "$product Guncel"
Post-And-Follow "$base/index.php?page=save_opportunity" @{
    csrf_token = $oppEditToken
    id = $oppId
    company_id = $companyId
    product_service = $productUpdated
    estimated_amount = "45678"
    stage = "KazanÄ±ldÄ±"
    expected_close_date = (Get-Date).AddDays(15).ToString("yyyy-MM-dd")
    note = "Regression fÄ±rsat gÃ¼ncel"
} $sessions.sales | Out-Null
$oppUpdated = Invoke-WebRequest -Uri "$base/index.php?page=opportunities&q=$([System.Net.WebUtility]::UrlEncode($productUpdated))" -WebSession $sessions.sales -UseBasicParsing
Assert-True ($oppUpdated.Content -like "*$productUpdated*") "satÄ±ÅŸ fÄ±rsatÄ± dÃ¼zenlenir"

$filterUrls = @(
    "$base/index.php?page=companies&q=Regression&status=Takipte&date_filter=month",
    "$base/index.php?page=followups&q=Regression&status=Takipte&date_filter=week",
    "$base/index.php?page=opportunities&q=Regression&stage=KazanÄ±ldÄ±&date_filter=custom&date_from=$((Get-Date).ToString('yyyy-MM-dd'))&date_to=$((Get-Date).AddDays(30).ToString('yyyy-MM-dd'))",
    "$base/index.php?page=reports&date_filter=month"
)
foreach ($url in $filterUrls) {
    $resp = Get-Status $url $sessions.sales
    Assert-True ($resp.Status -eq 200) "filtre URL $url"
}

$missingCsrf = Get-Status "$base/index.php?page=save_company" $sessions.sales
Assert-True ($missingCsrf.Status -eq 404) "GET save_company iÅŸlem yapmaz"
$badCsrf = Post-And-Follow "$base/index.php?page=save_company" @{ csrf_token = "bad"; id = 0; name = "CSRF Bad" } $sessions.sales
Assert-True ($badCsrf.Content -like "*Oturum doÄŸrulama*" -or $badCsrf.Content -like "*Oturum do*") "bad CSRF reddedilir"

$channelCompanies = Invoke-WebRequest -Uri "$base/index.php?page=companies" -WebSession $sessions.channel -UseBasicParsing
$channelCompanyId = [regex]::Match($channelCompanies.Content, 'company_view(?:&amp;|&)id=(\d+)').Groups[1].Value
$salesForeignCompany = Get-Status "$base/index.php?page=company_view&id=$channelCompanyId" $sessions.sales
Assert-True ($salesForeignCompany.Status -eq 403) "sales baska personelin firma kartini goremez"

$channelOpps = Invoke-WebRequest -Uri "$base/index.php?page=opportunities" -WebSession $sessions.channel -UseBasicParsing
$foreignOppId = [regex]::Match($channelOpps.Content, 'opportunity_form(?:&amp;|&)id=(\d+)').Groups[1].Value
Assert-True ($foreignOppId) "foreign fÄ±rsat linki okunur"
$ownCompanyId = $companyId
$hijackForm = Invoke-WebRequest -Uri "$base/index.php?page=opportunity_form&id=$oppId" -WebSession $sessions.sales -UseBasicParsing
$hijackToken = Extract-Token $hijackForm.Content
$beforeHijack = Php "require 'app/bootstrap.php'; echo db()->query('SELECT company_id FROM opportunities WHERE id = $foreignOppId')->fetchColumn();"
$hijack = Post-And-Follow "$base/index.php?page=save_opportunity" @{
    csrf_token = $hijackToken
    id = $foreignOppId
    company_id = $ownCompanyId
    product_service = "Hijack attempt"
    estimated_amount = "1"
    stage = "Kaybedildi"
    expected_close_date = (Get-Date).AddDays(1).ToString("yyyy-MM-dd")
    note = "must fail"
} $sessions.sales
$afterHijack = Php "require 'app/bootstrap.php'; echo db()->query('SELECT company_id FROM opportunities WHERE id = $foreignOppId')->fetchColumn();"
Assert-True ($beforeHijack -eq $afterHijack) "sales baÅŸka fÄ±rsatÄ± id deÄŸiÅŸtirerek gÃ¼ncelleyemez"

Cleanup-RegressionData
Write-Host "Regression test tamamlandÄ±."
