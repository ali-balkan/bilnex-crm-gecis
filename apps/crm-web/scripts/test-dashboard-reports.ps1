$ErrorActionPreference = "Stop"

$base = "http://127.0.0.1:8012"
$webRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$phpExe = Join-Path $env:LOCALAPPDATA "Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"
$phpDir = Split-Path $phpExe -Parent
$source = "Dashboard report test"

function Assert-True($condition, $message) {
    if (-not $condition) {
        throw "[FAIL] $message"
    }
    Write-Host "[OK] $message"
}

function Php($code) {
    $snippet = Join-Path $webRoot "data\dashboard-report-test-snippet.php"
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
    $dashboard = Invoke-WebRequest -Uri "$base/index.php?page=dashboard" -WebSession $session -UseBasicParsing
    Assert-True ($dashboard.Content -like "*Dashboard*") "$username login"
    return $session
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
    return [pscustomobject]@{ Content = $content; BaseResponse = [pscustomobject]@{ ResponseUri = $responseUri } }
}

function Cleanup-TestData() {
    Php @"
require 'app/bootstrap.php';
`$pdo = db();
`$companyIds = `$pdo->prepare("SELECT id FROM companies WHERE source = :source");
`$companyIds->execute([':source' => '$source']);
`$ids = `$companyIds->fetchAll(PDO::FETCH_COLUMN);
if (`$ids) {
    `$placeholders = implode(',', array_fill(0, count(`$ids), '?'));
    `$pdo->prepare("DELETE FROM interactions WHERE company_id IN (`$placeholders)")->execute(`$ids);
    `$pdo->prepare("DELETE FROM opportunities WHERE company_id IN (`$placeholders)")->execute(`$ids);
    `$pdo->prepare("DELETE FROM tasks WHERE company_id IN (`$placeholders)")->execute(`$ids);
    `$pdo->prepare("DELETE FROM companies WHERE id IN (`$placeholders)")->execute(`$ids);
}
`$pdo->prepare("DELETE FROM tasks WHERE title LIKE 'DashRpt %'")->execute();
`$pdo->prepare("DELETE FROM opportunities WHERE product_service LIKE 'DashRpt %'")->execute();
"@ | Out-Null
}

function Seed-Users() {
    Push-Location $webRoot
    try {
        & $phpExe -d "extension_dir=$phpDir\ext" -d "extension=pdo_sqlite" -d "extension=sqlite3" scripts\seed-test-data.php | Out-Null
    } finally {
        Pop-Location
    }
    Cleanup-TestData
    Php @'
require 'app/bootstrap.php';
db()->exec("DELETE FROM companies WHERE source = 'Test verisi'");
'@ | Out-Null
}

if (!(Test-Path $phpExe)) {
    throw "PHP 8.4 was not found at $phpExe"
}

Seed-Users

$server = Start-Job -ArgumentList $phpExe, $phpDir, $webRoot -ScriptBlock {
    param($phpExe, $phpDir, $webRoot)
    Set-Location $webRoot
    $env:CRM_BASE_URL = ""
    $env:CRM_COMPANY_SOURCE = "sqlite"
    & $phpExe -d "extension_dir=$phpDir\ext" -d "extension=pdo_sqlite" -d "extension=sqlite3" -S 127.0.0.1:8012 -t $webRoot
}

try {
    Start-Sleep -Milliseconds 900
    Assert-True ((Invoke-WebRequest -Uri "$base/index.php?page=login" -UseBasicParsing).StatusCode -eq 200) "local server started"

    $adminSession = Login "test_admin" "Test123!admin"
    $managerSession = Login "test_yonetici" "Test123!yonetici"
    $salesSession = Login "test_satis" "Test123!satis"

    $salesId = Php-Text "require 'app/bootstrap.php'; echo db()->query(""SELECT id FROM users WHERE username = 'test_satis'"")->fetchColumn();"
    $adminId = Php-Text "require 'app/bootstrap.php'; echo db()->query(""SELECT id FROM users WHERE username = 'test_admin'"")->fetchColumn();"
    $today = Get-Date
    $yesterday = "1900-01-01"
    $tomorrow = $today.AddDays(1).ToString("yyyy-MM-dd")
    $stamp = Get-Date -Format "yyyyMMddHHmmss"

    $companyName = "DashRpt Sales Company $stamp"
    $companyForm = Invoke-WebRequest -Uri "$base/index.php?page=company_form" -WebSession $salesSession -UseBasicParsing
    $companyToken = Extract-Token $companyForm.Content
    $companyPage = Post-And-Follow "$base/index.php?page=save_company" @{
        csrf_token = $companyToken
        id = 0
        name = $companyName
        account_type = "Hedef Bayi"
        contact_person = "DashRpt Yetkili"
        phone = "05550001122"
        email = "dashrpt$stamp@example.test"
        city = "İstanbul"
        district = "Kadıköy"
        address = "DashRpt test adresi"
        status = "Takipte"
        source = $source
        description = "Dashboard report test company"
    } $salesSession
    $companyId = [regex]::Match($companyPage.BaseResponse.ResponseUri.AbsoluteUri, 'id=(\d+)').Groups[1].Value
    Assert-True ($companyId -and $companyPage.Content -like "*$companyName*") "sales company entry saved"

    $interactionNote = "DashRpt Sales Note $stamp"
    $interactionToken = Extract-Token $companyPage.Content
    $interactionPage = Post-And-Follow "$base/index.php?page=save_interaction" @{
        csrf_token = $interactionToken
        company_id = $companyId
        interaction_date = $today.ToString("yyyy-MM-dd")
        type = "Telefon"
        result = "Demo istiyor"
        note = $interactionNote
    } $salesSession
    $interactionSaved = Php-Text "require 'app/bootstrap.php'; echo db()->query(""SELECT COUNT(*) FROM interactions WHERE note = '$interactionNote'"")->fetchColumn();"
    Assert-True ($interactionSaved -eq "1") "sales interaction entry saved"

    $taskForm = Invoke-WebRequest -Uri "$base/index.php?page=followups" -WebSession $salesSession -UseBasicParsing
    $taskToken = Extract-Token $taskForm.Content
    $overdueTask = "DashRpt Overdue Sales Task $stamp"
    Post-And-Follow "$base/index.php?page=save_task" @{
        csrf_token = $taskToken
        id = 0
        company_id = $companyId
        title = $overdueTask
        assigned_to = $salesId
        due_date = $yesterday
        description = "Dashboard report overdue task"
    } $salesSession | Out-Null

    $taskForm = Invoke-WebRequest -Uri "$base/index.php?page=followups" -WebSession $salesSession -UseBasicParsing
    $taskToken = Extract-Token $taskForm.Content
    $futureTask = "DashRpt Future Sales Task $stamp"
    Post-And-Follow "$base/index.php?page=save_task" @{
        csrf_token = $taskToken
        id = 0
        company_id = $companyId
        title = $futureTask
        assigned_to = $salesId
        due_date = $tomorrow
        description = "Dashboard report future task"
    } $salesSession | Out-Null
    $taskCount = Php-Text "require 'app/bootstrap.php'; echo db()->query(""SELECT COUNT(*) FROM tasks WHERE title IN ('$overdueTask', '$futureTask')"")->fetchColumn();"
    Assert-True ($taskCount -eq "2") "sales task entries saved"

    $oppForm = Invoke-WebRequest -Uri "$base/index.php?page=opportunity_form&company_id=$companyId" -WebSession $salesSession -UseBasicParsing
    $oppToken = Extract-Token $oppForm.Content
    $openProduct = "DashRpt Open Product $stamp"
    Post-And-Follow "$base/index.php?page=save_opportunity" @{
        csrf_token = $oppToken
        id = 0
        company_id = $companyId
        product_service = $openProduct
        estimated_amount = "999999999"
        stage = "Teklif verildi"
        expected_close_date = $tomorrow
        note = "Dashboard report open opportunity"
    } $salesSession | Out-Null

    $oppForm = Invoke-WebRequest -Uri "$base/index.php?page=opportunity_form&company_id=$companyId" -WebSession $salesSession -UseBasicParsing
    $oppToken = Extract-Token $oppForm.Content
    $wonProduct = "DashRpt Won Product $stamp"
    Post-And-Follow "$base/index.php?page=save_opportunity" @{
        csrf_token = $oppToken
        id = 0
        company_id = $companyId
        product_service = $wonProduct
        estimated_amount = "54321"
        stage = "Kazanıldı"
        expected_close_date = $tomorrow
        note = "Dashboard report won opportunity"
    } $salesSession | Out-Null
    $oppCount = Php-Text "require 'app/bootstrap.php'; echo db()->query(""SELECT COUNT(*) FROM opportunities WHERE product_service IN ('$openProduct', '$wonProduct')"")->fetchColumn();"
    Assert-True ($oppCount -eq "2") "sales opportunity entries saved"

    Php @"
require 'app/bootstrap.php';
`$pdo = db();
`$adminId = (int) $adminId;
`$insertCompany = `$pdo->prepare("INSERT INTO companies (name, account_type, status, source, responsible_user_id, created_by, created_at) VALUES (:name, :account_type, :status, :source, :responsible_user_id, :created_by, CURRENT_TIMESTAMP)");
`$insertCompany->execute([
    ':name' => 'DashRpt Admin Company $stamp',
    ':account_type' => 'Müşteri',
    ':status' => 'Takipte',
    ':source' => '$source',
    ':responsible_user_id' => `$adminId,
    ':created_by' => `$adminId,
]);
`$companyId = (int) `$pdo->lastInsertId();
`$pdo->prepare("INSERT INTO interactions (company_id, user_id, interaction_date, type, result, note) VALUES (:company_id, :user_id, :interaction_date, :type, :result, :note)")
    ->execute([':company_id' => `$companyId, ':user_id' => `$adminId, ':interaction_date' => date('Y-m-d'), ':type' => 'Telefon', ':result' => 'Olumlu', ':note' => 'DashRpt Admin Note $stamp']);
`$pdo->prepare("INSERT INTO tasks (company_id, title, assigned_by, assigned_to, due_date, status) VALUES (:company_id, :title, :assigned_by, :assigned_to, :due_date, :status)")
    ->execute([':company_id' => `$companyId, ':title' => 'DashRpt Admin Task $stamp', ':assigned_by' => `$adminId, ':assigned_to' => `$adminId, ':due_date' => '$yesterday', ':status' => task_statuses()[0]]);
`$pdo->prepare("INSERT INTO opportunities (company_id, salesperson_id, product_service, estimated_amount, stage, expected_close_date, note) VALUES (:company_id, :salesperson_id, :product_service, :estimated_amount, :stage, :expected_close_date, :note)")
    ->execute([':company_id' => `$companyId, ':salesperson_id' => `$adminId, ':product_service' => 'DashRpt Admin Product $stamp', ':estimated_amount' => 999999998, ':stage' => 'Teklif verildi', ':expected_close_date' => '$tomorrow', ':note' => 'Admin scoped opportunity']);
"@ | Out-Null
    $adminTaskSaved = Php-Text "require 'app/bootstrap.php'; echo db()->query(""SELECT COUNT(*) FROM tasks WHERE title = 'DashRpt Admin Task $stamp'"")->fetchColumn();"
    $adminOppSaved = Php-Text "require 'app/bootstrap.php'; echo db()->query(""SELECT COUNT(*) FROM opportunities WHERE product_service = 'DashRpt Admin Product $stamp'"")->fetchColumn();"
    Assert-True ($adminTaskSaved -eq "1" -and $adminOppSaved -eq "1") "admin scoped test data saved"

    $adminDashboard = Invoke-WebRequest -Uri "$base/index.php?page=dashboard" -WebSession $adminSession -UseBasicParsing
    Assert-True ($adminDashboard.Content -like "*$overdueTask*") "admin dashboard shows sales task"
    Assert-True ($adminDashboard.Content -like "*$openProduct*") "admin dashboard shows sales opportunity"
    Assert-True ($adminDashboard.Content -like "*DashRpt Admin Task $stamp*") "admin dashboard shows admin task"
    Assert-True ($adminDashboard.Content -like "*DashRpt Admin Product $stamp*") "admin dashboard shows admin opportunity"

    $managerDashboard = Invoke-WebRequest -Uri "$base/index.php?page=dashboard" -WebSession $managerSession -UseBasicParsing
    Assert-True ($managerDashboard.Content -like "*$overdueTask*" -and $managerDashboard.Content -like "*$openProduct*") "manager dashboard shows subordinate sales data"
    Assert-True ($managerDashboard.Content -notlike "*DashRpt Admin Task $stamp*" -and $managerDashboard.Content -notlike "*DashRpt Admin Product $stamp*") "manager dashboard hides admin-only data"

    $salesDashboard = Invoke-WebRequest -Uri "$base/index.php?page=dashboard" -WebSession $salesSession -UseBasicParsing
    Assert-True ($salesDashboard.Content -like "*$overdueTask*" -and $salesDashboard.Content -like "*$futureTask*" -and $salesDashboard.Content -like "*$openProduct*") "sales dashboard shows own entries"
    Assert-True ($salesDashboard.Content -notlike "*DashRpt Admin Task $stamp*" -and $salesDashboard.Content -notlike "*DashRpt Admin Product $stamp*") "sales dashboard hides admin data"

    $adminReports = Invoke-WebRequest -Uri "$base/index.php?page=reports&date_filter=today" -WebSession $adminSession -UseBasicParsing
    Assert-True ($adminReports.Content -like "*$interactionNote*" -and $adminReports.Content -like "*DashRpt Admin Note $stamp*") "admin reports show all interaction entries"
    Assert-True ($adminReports.Content -like "*$overdueTask*" -and $adminReports.Content -like "*DashRpt Admin Task $stamp*") "admin reports show overdue task entries"
    Assert-True ($adminReports.Content -like "*Teklif verildi*" -and $adminReports.Content -like "*Kazanıldı*") "admin reports show opportunity stages"

    $managerReports = Invoke-WebRequest -Uri "$base/index.php?page=reports&date_filter=today" -WebSession $managerSession -UseBasicParsing
    Assert-True ($managerReports.Content -like "*$interactionNote*" -and $managerReports.Content -like "*$overdueTask*") "manager reports show subordinate entries"
    Assert-True ($managerReports.Content -notlike "*DashRpt Admin Note $stamp*" -and $managerReports.Content -notlike "*DashRpt Admin Task $stamp*") "manager reports hide admin-only entries"

    $salesReports = Invoke-WebRequest -Uri "$base/index.php?page=reports&date_filter=today" -WebSession $salesSession -UseBasicParsing
    Assert-True ($salesReports.Content -like "*$interactionNote*" -and $salesReports.Content -like "*$overdueTask*") "sales reports show own entries"
    Assert-True ($salesReports.Content -notlike "*DashRpt Admin Note $stamp*" -and $salesReports.Content -notlike "*DashRpt Admin Task $stamp*") "sales reports hide admin entries"
} finally {
    Cleanup-TestData
    Stop-Job $server -ErrorAction SilentlyContinue
    Remove-Job $server -Force -ErrorAction SilentlyContinue
}

Write-Host "Dashboard and reports test completed."
