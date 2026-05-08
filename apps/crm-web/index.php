<?php

require_once __DIR__ . '/app/bootstrap.php';

$page = $_GET['page'] ?? 'dashboard';
verify_csrf();

function scalar(string $sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function rows(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function money($amount): string
{
    return number_format((float) $amount, 2, ',', '.') . ' TL';
}

function pct($value, $max): int
{
    $max = (float) $max;
    if ($max <= 0) {
        return 0;
    }
    return max(2, min(100, (int) round(((float) $value / $max) * 100)));
}

function dashboard_card(string $label, $value, string $hint = '', string $tone = '', string $href = ''): void
{
    $tag = $href !== '' ? 'a' : 'article';
    $hrefAttr = $href !== '' ? ' href="' . e($href) . '"' : '';
    echo '<' . $tag . $hrefAttr . ' class="stat ' . e($tone) . '"><span>' . e($label) . '</span><strong>' . e($value) . '</strong>';
    if ($hint !== '') {
        echo '<small>' . e($hint) . '</small>';
    }
    echo '</' . $tag . '>';
}

function render_bar_list(array $rows, string $labelKey, string $valueKey, string $empty = 'Veri yok'): void
{
    $max = 0;
    foreach ($rows as $row) {
        $max = max($max, (float) $row[$valueKey]);
    }
    echo '<div class="bar-list">';
    foreach ($rows as $row) {
        $value = (float) $row[$valueKey];
        echo '<div class="bar-row">';
        echo '<div class="bar-meta"><span>' . e($row[$labelKey]) . '</span><strong>' . e($value) . '</strong></div>';
        echo '<div class="bar-track"><span style="width:' . pct($value, $max) . '%"></span></div>';
        echo '</div>';
    }
    if (!$rows) {
        echo '<p class="muted">' . e($empty) . '</p>';
    }
    echo '</div>';
}

function render_linked_bar_list(array $rows, string $labelKey, string $valueKey, callable $hrefForRow, string $empty = 'Veri yok'): void
{
    $max = 0;
    foreach ($rows as $row) {
        $max = max($max, (float) $row[$valueKey]);
    }
    echo '<div class="bar-list">';
    foreach ($rows as $row) {
        $value = (float) $row[$valueKey];
        $href = (string) $hrefForRow($row);
        echo '<a class="bar-row" href="' . e($href) . '">';
        echo '<div class="bar-meta"><span>' . e($row[$labelKey]) . '</span><strong>' . e($value) . '</strong></div>';
        echo '<div class="bar-track"><span style="width:' . pct($value, $max) . '%"></span></div>';
        echo '</a>';
    }
    if (!$rows) {
        echo '<p class="muted">' . e($empty) . '</p>';
    }
    echo '</div>';
}

function render_donut_chart(array $rows, string $labelKey, string $valueKey): void
{
    $total = array_sum(array_map(static fn($row) => (float) $row[$valueKey], $rows));
    $first = $total > 0 && isset($rows[0]) ? round(((float) $rows[0][$valueKey] / $total) * 100) : 0;
    $second = $total > 0 && isset($rows[1]) ? $first + round(((float) $rows[1][$valueKey] / $total) * 100) : $first;
    echo '<div class="donut-chart" style="--p1:' . e($first) . ';--p2:' . e($second) . '" data-total="' . e((int) $total) . '"></div>';
}

function render_trend_chart(array $rows, string $labelKey, string $valueKey): void
{
    $max = 0;
    foreach ($rows as $row) {
        $max = max($max, (float) $row[$valueKey]);
    }
    echo '<div class="trend-line" style="--points:' . max(1, count($rows)) . '">';
    foreach ($rows as $row) {
        echo '<div class="trend-point" title="' . e($row[$labelKey] . ': ' . $row[$valueKey]) . '"><i style="--value:' . pct($row[$valueKey], $max) . '"></i><span>' . e(substr((string) $row[$labelKey], 5)) . '</span></div>';
    }
    if (!$rows) {
        echo '<p class="muted">Veri yok</p>';
    }
    echo '</div>';
}

function render_amount_bar_list(array $rows, string $labelKey, string $valueKey, string $amountKey): void
{
    $max = 0;
    foreach ($rows as $row) {
        $max = max($max, (float) $row[$amountKey]);
    }
    echo '<div class="bar-list">';
    foreach ($rows as $row) {
        $amount = (float) $row[$amountKey];
        echo '<div class="bar-row">';
        echo '<div class="bar-meta"><span>' . e($row[$labelKey]) . ' <em>' . e($row[$valueKey]) . ' adet</em></span><strong>' . e(money($amount)) . '</strong></div>';
        echo '<div class="bar-track accent"><span style="width:' . pct($amount, $max) . '%"></span></div>';
        echo '</div>';
    }
    if (!$rows) {
        echo '<p class="muted">Veri yok</p>';
    }
    echo '</div>';
}

function render_login(): void
{
    $flash = flash();
    ?>
    <!doctype html>
    <html lang="tr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Giriş | <?= e(app_config('app_name')) ?></title>
        <link rel="icon" href="<?= e(rtrim(app_config('base_url'), '/')) ?>/assets/brand/bilnex-logo.jpg">
        <link rel="stylesheet" href="<?= e(rtrim(app_config('base_url'), '/')) ?>/assets/app.css">
    </head>
    <body class="login-page">
        <main class="login-shell">
            <section class="login-panel">
                <img class="login-logo" src="<?= e(rtrim(app_config('base_url'), '/')) ?>/assets/brand/bilnex-logo.jpg" alt="Bilnex Yazılım Çözümleri">
                <h1>İş Ortakları CRM</h1>
                <p>Bilnex Yazılım Çözümleri bayi takip paneli</p>
                <?php if ($flash): ?>
                    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
                <?php endif; ?>
                <form method="post" action="<?= e(app_url('login')) ?>" class="stack">
                    <?= csrf_field() ?>
                    <label>Kullanıcı adı
                        <input name="username" autocomplete="username" required autofocus>
                    </label>
                    <label>Şifre
                        <input name="password" type="password" autocomplete="current-password" required>
                    </label>
                    <button class="btn primary" type="submit">Giriş yap</button>
                </form>
            </section>
            <section class="login-visual" aria-hidden="true">
                <img src="<?= e(rtrim(app_config('base_url'), '/')) ?>/assets/brand/bilnex-platform.webp" alt="">
            </section>
        </main>
    </body>
    </html>
    <?php
}

function nav_icon(string $target): string
{
    $paths = [
        'dashboard' => '<path d="M3 13h8V3H3v10Zm0 8h8v-6H3v6Zm10 0h8V11h-8v10Zm0-18v6h8V3h-8Z"/>',
        'users' => '<path d="M16 11a4 4 0 1 0-3.3-6.3A5 5 0 0 1 16 11Zm-8 0a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-6 1.7-6 3.8V20h12v-3.2C14 14.7 11.3 13 8 13Zm8 0c-.7 0-1.4.1-2 .3 1.2.9 2 2.1 2 3.5V20h6v-3.2c0-2.1-2.7-3.8-6-3.8Z"/>',
        'companies' => '<path d="M4 21V5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v16H4Zm4-12h2V7H8v2Zm0 4h2v-2H8v2Zm0 4h2v-2H8v2Zm4-8h2V7h-2v2Zm0 4h2v-2h-2v2Zm0 4h2v-2h-2v2Zm7 4v-9h1a2 2 0 0 1 2 2v7h-3Z"/>',
        'followups' => '<path d="M7 2h2v3h6V2h2v3h2a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2V2Zm12 8H5v9h14v-9Zm-9 6 6-6 1.4 1.4L10 18l-3.4-3.4L8 13.2l2 2Z"/>',
        'opportunities' => '<path d="M4 4h16v4H4V4Zm0 6h10v4H4v-4Zm0 6h16v4H4v-4Zm12-6h4v4h-4v-4Z"/>',
        'reports' => '<path d="M4 19h16v2H4v-2Zm2-2V9h3v8H6Zm5 0V3h3v14h-3Zm5 0v-6h3v6h-3Z"/>',
    ];
    $path = $paths[$target] ?? '<path d="M4 4h16v16H4z"/>';
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $path . '</svg>';
}

function render_header(string $title): void
{
    $user = current_user();
    $flash = flash();
    $openTaskCount = can_view_all()
        ? (int) scalar("SELECT COUNT(*) FROM tasks WHERE status = 'Açık'")
        : (int) scalar("SELECT COUNT(*) FROM tasks WHERE status = 'Açık' AND (assigned_to = :uid OR assigned_by = :uid)", [':uid' => $user['id']]);
    $nav = [
        ['dashboard', 'Dashboard'],
        ['companies', 'Cariler'],
        ['followups', 'Takip Listesi'],
        ['opportunities', 'Satış Fırsatları'],
        ['reports', 'Raporlar'],
    ];
    if (can_manage_users()) {
        array_splice($nav, 1, 0, [['users', 'Kullanıcılar']]);
    }
    ?>
    <!doctype html>
    <html lang="tr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> | <?= e(app_config('app_name')) ?></title>
        <link rel="icon" href="<?= e(rtrim(app_config('base_url'), '/')) ?>/assets/brand/bilnex-logo.jpg">
        <link rel="stylesheet" href="<?= e(rtrim(app_config('base_url'), '/')) ?>/assets/app.css">
    </head>
    <body>
        <div class="app-shell">
            <aside class="sidebar">
                <a class="brand" href="<?= e(app_url()) ?>">
                    <span class="brand-logo-wrap"><img src="<?= e(rtrim(app_config('base_url'), '/')) ?>/assets/brand/bilnex-logo-sidebar.jpg" alt="Bilnex"></span>
                    <span>
                        <strong>İş Ortakları CRM</strong>
                        <small>İş Ortakları</small>
                    </span>
                </a>
                <nav>
                    <?php foreach ($nav as [$target, $label]): ?>
                        <a class="<?= ($_GET['page'] ?? 'dashboard') === $target ? 'active' : '' ?>" href="<?= e(app_url($target)) ?>"><span class="nav-icon"><?= nav_icon($target) ?></span><span><?= e($label) ?></span></a>
                    <?php endforeach; ?>
                </nav>
                <div class="user-box">
                    <strong><?= e($user['full_name']) ?></strong>
                    <span><?= e(role_label($user['role'])) ?></span>
                    <a href="<?= e(app_url('logout')) ?>">Çıkış</a>
                </div>
            </aside>
            <main class="content">
                <header class="topbar">
                    <div>
                        <h1><?= e($title) ?></h1>
                        <p><?= e(date('d.m.Y')) ?></p>
                    </div>
                    <div class="topbar-actions">
                        <a class="notification-pill" href="<?= e(app_url('followups', ['status' => 'Açık'])) ?>">Açık iş <strong><?= e($openTaskCount) ?></strong></a>
                        <a class="btn" href="<?= e(app_url('company_form')) ?>">Yeni cari</a>
                    </div>
                </header>
                <?php if ($flash): ?>
                    <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
                <?php endif; ?>
    <?php
}

function render_footer(): void
{
    ?>
            </main>
        </div>
        <script>
            document.querySelectorAll('table').forEach((table) => {
                const labels = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent.trim());
                table.querySelectorAll('tbody tr').forEach((row) => {
                    Array.from(row.children).forEach((cell, index) => {
                        if (cell.tagName === 'TD' && labels[index] && !cell.dataset.label) {
                            cell.dataset.label = labels[index];
                        }
                    });
                });
            });
        </script>
        <script src="<?= e(rtrim(app_config('base_url'), '/')) ?>/assets/app.js" defer></script>
    </body>
    </html>
    <?php
}

function filter_bar(string $target, array $extra = []): void
{
    $dateFilter = $_GET['date_filter'] ?? '';
    ?>
    <form class="filters" method="get">
        <input type="hidden" name="page" value="<?= e($target) ?>">
        <input name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Ara...">
        <?php foreach ($extra as $name => $options): ?>
            <select name="<?= e($name) ?>">
                <option value=""><?= e($options['_label']) ?></option>
                <?php foreach ($options['items'] as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= selected($_GET[$name] ?? '', $value) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endforeach; ?>
        <select name="date_filter">
            <option value="">Tüm tarihler</option>
            <option value="today"<?= selected($dateFilter, 'today') ?>>Bugün</option>
            <option value="week"<?= selected($dateFilter, 'week') ?>>Bu hafta</option>
            <option value="month"<?= selected($dateFilter, 'month') ?>>Bu ay</option>
            <option value="custom"<?= selected($dateFilter, 'custom') ?>>Özel aralık</option>
        </select>
        <input type="date" name="date_from" value="<?= e($_GET['date_from'] ?? '') ?>">
        <input type="date" name="date_to" value="<?= e($_GET['date_to'] ?? '') ?>">
        <button class="btn" type="submit">Filtrele</button>
    </form>
    <?php
}

function render_pagination(string $target, int $pageNumber, int $perPage, int $total): void
{
    $pages = max(1, (int) ceil($total / max(1, $perPage)));
    if ($pages <= 1) {
        return;
    }
    $currentParams = $_GET;
    $currentParams['page'] = $target;
    echo '<nav class="pagination" aria-label="Sayfalama">';
    for ($i = max(1, $pageNumber - 2); $i <= min($pages, $pageNumber + 2); $i++) {
        $currentParams['p'] = $i;
        $class = $i === $pageNumber ? 'btn small current' : 'btn small';
        echo '<a class="' . e($class) . '" href="' . e(app_url($target, $currentParams)) . '">' . e($i) . '</a>';
    }
    echo '</nav>';
}

function normalize_import_key(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    $value = strtr($value, [
        'Ç' => 'c', 'Ğ' => 'g', 'İ' => 'i', 'I' => 'i', 'Ö' => 'o', 'Ş' => 's', 'Ü' => 'u',
        'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'i' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
    ]);
    $value = strtolower($value);
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? $value;
}

function import_column_map(): array
{
    return [
        'name' => ['firma', 'firmaadi', 'bayi', 'bayifirma', 'sirket', 'unvan', 'company', 'name'],
        'account_type' => ['carituru', 'caritipi', 'musteritipi', 'hesaptipi', 'tur', 'tip', 'accounttype', 'customertype'],
        'contact_person' => ['yetkili', 'yetkilikisi', 'ilgilikisi', 'contact', 'contactperson'],
        'phone' => ['telefon', 'tel', 'gsm', 'cep', 'phone'],
        'email' => ['eposta', 'email', 'mail'],
        'city' => ['il', 'sehir', 'city'],
        'district' => ['ilce', 'district'],
        'address' => ['adres', 'address'],
        'status' => ['durum', 'status'],
        'source' => ['kaynak', 'source'],
        'responsible' => ['sorumlu', 'sorumlupersonel', 'responsible', 'salesperson'],
        'next_followup_date' => ['sonrakitakip', 'takiptarihi', 'sonrakitakiptarihi', 'nextfollowupdate'],
        'description' => ['aciklama', 'not', 'notlar', 'description'],
    ];
}

function canonical_import_row(array $headers, array $row): array
{
    $lookup = [];
    foreach (import_column_map() as $field => $aliases) {
        foreach ($aliases as $alias) {
            $lookup[$alias] = $field;
        }
    }

    $data = [];
    foreach ($headers as $index => $header) {
        $field = $lookup[normalize_import_key((string) $header)] ?? null;
        if ($field) {
            $data[$field] = trim((string) ($row[$index] ?? ''));
        }
    }
    return $data;
}

function import_date_value($value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (is_numeric($value)) {
        $timestamp = ((float) $value - 25569) * 86400;
        if ($timestamp > 0) {
            return gmdate('Y-m-d', (int) round($timestamp));
        }
    }
    $value = str_replace(['.', '/'], '-', $value);
    foreach (['Y-m-d', 'd-m-Y', 'd-m-y'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date instanceof DateTimeImmutable) {
            return $date->format('Y-m-d');
        }
    }
    $time = strtotime($value);
    return $time ? date('Y-m-d', $time) : null;
}

function import_text_encoding(string $value): string
{
    if ($value === '' || preg_match('//u', $value)) {
        return $value;
    }
    if (function_exists('iconv')) {
        $converted = @iconv('Windows-1254', 'UTF-8//IGNORE', $value);
        if ($converted !== false) {
            return $converted;
        }
    }
    return $value;
}

function read_csv_import_rows(string $path): array
{
    $sample = (string) file_get_contents($path, false, null, 0, 4096);
    $delimiter = ',';
    $bestCount = 0;
    foreach ([',', ';', "\t"] as $candidate) {
        $count = substr_count($sample, $candidate);
        if ($count > $bestCount) {
            $bestCount = $count;
            $delimiter = $candidate;
        }
    }

    $handle = fopen($path, 'rb');
    if (!$handle) {
        return [];
    }
    $rows = [];
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $rows[] = array_map(fn($cell) => import_text_encoding((string) $cell), $row);
    }
    fclose($handle);
    return $rows;
}

function xlsx_cell_column(string $reference): int
{
    preg_match('/^[A-Z]+/i', $reference, $match);
    $letters = strtoupper($match[0] ?? 'A');
    $number = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $number = ($number * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $number - 1);
}

function xlsx_text_nodes(SimpleXMLElement $node): string
{
    $parts = [];
    foreach ($node->xpath('.//*[local-name()="t"]') ?: [] as $text) {
        $parts[] = (string) $text;
    }
    return implode('', $parts);
}

function read_xlsx_import_rows(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('XLSX okuyabilmek için PHP zip eklentisi aktif olmalıdır. Excel dosyasını CSV UTF-8 olarak kaydedip yükleyebilirsiniz.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Excel dosyası açılamadı.');
    }

    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sharedRoot = simplexml_load_string($sharedXml);
        if ($sharedRoot instanceof SimpleXMLElement) {
            foreach ($sharedRoot->xpath('//*[local-name()="si"]') ?: [] as $item) {
                $shared[] = xlsx_text_nodes($item);
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Excel dosyasında ilk çalışma sayfası bulunamadı.');
    }

    $sheet = simplexml_load_string($sheetXml);
    if (!$sheet instanceof SimpleXMLElement) {
        throw new RuntimeException('Excel sayfası okunamadı.');
    }
    $rows = [];
    foreach ($sheet->xpath('//*[local-name()="row"]') ?: [] as $rowNode) {
        $row = [];
        foreach ($rowNode->xpath('./*[local-name()="c"]') ?: [] as $cell) {
            $attrs = $cell->attributes();
            $index = xlsx_cell_column((string) ($attrs['r'] ?? 'A'));
            $type = (string) ($attrs['t'] ?? '');
            $valueNode = $cell->xpath('./*[local-name()="v"]')[0] ?? null;
            $value = $valueNode !== null ? (string) $valueNode : '';
            if ($type === 's') {
                $value = $shared[(int) $value] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = xlsx_text_nodes($cell);
            }
            $row[$index] = $value;
        }
        if ($row) {
            ksort($row);
            $rows[] = $row;
        }
    }
    return $rows;
}

function read_import_rows(array $file): array
{
    $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if ($extension === 'csv') {
        return read_csv_import_rows($file['tmp_name']);
    }
    if ($extension === 'xlsx') {
        return read_xlsx_import_rows($file['tmp_name']);
    }
    throw new RuntimeException('Sadece .xlsx veya .csv dosyası yükleyin.');
}

function find_responsible_user_id(?string $value, int $fallback): int
{
    if (!can_view_all() || trim((string) $value) === '') {
        return $fallback;
    }
    $stmt = db()->prepare('SELECT id FROM users WHERE active = 1 AND (full_name = :value OR username = :value) LIMIT 1');
    $stmt->execute([':value' => trim((string) $value)]);
    return (int) ($stmt->fetchColumn() ?: $fallback);
}

function company_lookup_label(array $company): string
{
    $code = trim((string) ($company['account_code'] ?? ''));
    $name = trim((string) ($company['name'] ?? ''));
    $type = trim((string) ($company['account_type'] ?? ''));
    $prefix = $code !== '' ? $code . ' - ' : '';
    return (int) $company['id'] . ' | ' . $prefix . $name . ($type !== '' ? ' - ' . $type : '');
}

function company_id_from_lookup(string $value): int
{
    $value = trim($value);
    if (preg_match('/^(\d+)\s*\|/', $value, $match)) {
        return (int) $match[1];
    }
    return 0;
}

if ($page === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $stmt = db()->prepare('SELECT * FROM users WHERE username = :username AND active = 1');
        $stmt->execute([':username' => trim($_POST['username'] ?? '')]);
        $user = $stmt->fetch();
        if ($user && password_verify($_POST['password'] ?? '', $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            redirect_to('dashboard');
        }
        flash('Kullanıcı adı veya şifre hatalı.', 'danger');
        redirect_to('login');
    }
    render_login();
    exit;
}

if ($page === 'logout') {
    session_destroy();
    redirect_to('login');
}

require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($page === 'save_task') {
        $assignedTo = (int) ($_POST['assigned_to'] ?? 0);
        $validAssignee = (int) scalar('SELECT COUNT(*) FROM users WHERE id = :id AND active = 1', [':id' => $assignedTo]);
        $title = trim($_POST['title'] ?? '');
        $companyId = (int) ($_POST['company_id'] ?? 0);
        if ($companyId > 0) {
            require_company_access($companyId);
        } else {
            $companyId = null;
        }
        if ($title === '' || $validAssignee === 0) {
            flash('İş başlığı ve atanacak personel zorunludur.', 'danger');
            redirect_to('task_form');
        }
        db()->prepare('INSERT INTO tasks (company_id, sql_customer_id, title, description, assigned_by, assigned_to, due_date, status) VALUES (:company_id, :sql_customer_id, :title, :description, :assigned_by, :assigned_to, :due_date, :status)')->execute([
            ':company_id' => $companyId,
            ':sql_customer_id' => $companyId ? company_sql_customer_id($companyId) : null,
            ':title' => $title,
            ':description' => trim($_POST['description'] ?? ''),
            ':assigned_by' => current_user()['id'],
            ':assigned_to' => $assignedTo,
            ':due_date' => $_POST['due_date'] ?: null,
            ':status' => 'Açık',
        ]);
        flash('İş atandı.');
        redirect_to('followups');
    }

    if ($page === 'complete_task') {
        $taskId = (int) ($_POST['id'] ?? 0);
        $task = rows('SELECT * FROM tasks WHERE id = :id', [':id' => $taskId])[0] ?? null;
        if (!$task) {
            http_response_code(404);
            exit('İş bulunamadı.');
        }
        if (!can_view_all() && (int) $task['assigned_to'] !== (int) current_user()['id']) {
            http_response_code(403);
            exit('Bu işi tamamlama yetkiniz yok.');
        }
        db()->prepare("UPDATE tasks SET status = 'Tamamlandı', completion_note = :completion_note, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id")->execute([
            ':completion_note' => trim($_POST['completion_note'] ?? ''),
            ':id' => $taskId,
        ]);
        flash('İş tamamlandı.');
        redirect_to('followups');
    }

    if ($page === 'save_user') {
        if (!can_manage_users()) {
            http_response_code(403);
            exit('Yetkisiz işlem.');
        }
        $id = (int) ($_POST['id'] ?? 0);
        $data = [
            ':username' => trim($_POST['username'] ?? ''),
            ':full_name' => trim($_POST['full_name'] ?? ''),
            ':role' => $_POST['role'] ?? ROLE_SALES,
            ':active' => isset($_POST['active']) ? 1 : 0,
        ];
        if (!array_key_exists($data[':role'], role_options())) {
            $data[':role'] = ROLE_SALES;
        }
        if ($data[':username'] === '' || $data[':full_name'] === '') {
            flash('Ad soyad ve kullanıcı adı zorunludur.', 'danger');
            redirect_to('users', $id > 0 ? ['id' => $id] : []);
        }
        $taken = (int) scalar('SELECT COUNT(*) FROM users WHERE username = :username AND id <> :id', [
            ':username' => $data[':username'],
            ':id' => $id,
        ]);
        if ($taken > 0) {
            flash('Bu kullanıcı adı zaten kullanılıyor.', 'danger');
            redirect_to('users', $id > 0 ? ['id' => $id] : []);
        }
        if ($id > 0) {
            $sql = 'UPDATE users SET username = :username, full_name = :full_name, role = :role, active = :active';
            if (!empty($_POST['password'])) {
                $sql .= ', password_hash = :password_hash';
                $data[':password_hash'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id = :id';
            $data[':id'] = $id;
            db()->prepare($sql)->execute($data);
            flash('Kullanıcı güncellendi.');
        } else {
            if (empty($_POST['password'])) {
                flash('Yeni kullanıcı için şifre zorunludur.', 'danger');
                redirect_to('users');
            }
            $data[':password_hash'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
            db()->prepare('INSERT INTO users (username, password_hash, full_name, role, active) VALUES (:username, :password_hash, :full_name, :role, :active)')->execute($data);
            flash('Kullanıcı oluşturuldu.');
        }
        redirect_to('users');
    }

    if ($page === 'import_companies') {
        flash('Excelden cari aktarımı kaldırıldı. Yeni carileri Cariler ekranından tek tek açın.', 'danger');
        redirect_to('companies');
    }

    if ($page === 'save_company') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            require_company_access($id);
        }
        $responsible = (int) ($_POST['responsible_user_id'] ?? current_user()['id']);
        if (!can_view_all()) {
            $responsible = (int) current_user()['id'];
        }
        $data = [
            ':name' => trim($_POST['name'] ?? ''),
            ':sql_customer_id' => ((int) ($_POST['sql_customer_id'] ?? 0)) > 0 ? (int) $_POST['sql_customer_id'] : null,
            ':account_type' => normalize_company_account_type($_POST['account_type'] ?? ''),
            ':account_code' => trim($_POST['account_code'] ?? ''),
            ':contact_person' => trim($_POST['contact_person'] ?? ''),
            ':phone' => trim($_POST['phone'] ?? ''),
            ':email' => trim($_POST['email'] ?? ''),
            ':city' => trim($_POST['city'] ?? ''),
            ':district' => trim($_POST['district'] ?? ''),
            ':address' => trim($_POST['address'] ?? ''),
            ':tax_no' => trim($_POST['tax_no'] ?? ''),
            ':balance_amount' => (float) str_replace(',', '.', $_POST['balance_amount'] ?? 0),
            ':balance_side' => trim($_POST['balance_side'] ?? ''),
            ':status' => $_POST['status'] ?? 'Yeni kayıt',
            ':source' => trim($_POST['source'] ?? ''),
            ':responsible_user_id' => $responsible,
            ':description' => trim($_POST['description'] ?? ''),
        ];
        if (!in_array($data[':status'], company_statuses(), true)) {
            $data[':status'] = 'Yeni kayıt';
        }
        if ($data[':name'] === '') {
            flash('Cari adı zorunludur.', 'danger');
            redirect_to('company_form', $id > 0 ? ['id' => $id] : []);
        }
        if (company_source() === 'sqlserver' && $id === 0) {
            try {
                $created = bilnex_customer_writer()->createCustomer([
                    'name' => $data[':name'],
                    'customer_type_id' => company_account_type_sql_id($data[':account_type']) ?? 16,
                    'contact_person' => $data[':contact_person'],
                    'phone' => $data[':phone'],
                    'email' => $data[':email'],
                    'city' => $data[':city'],
                    'district' => $data[':district'],
                    'address' => $data[':address'],
                    'tax_no' => $data[':tax_no'],
                    'description' => $data[':description'],
                ]);
                flash('Cari SQL Server kaydına yazıldı. Cari kodu: ' . $created['code']);
                redirect_to('companies', ['account_type' => $data[':account_type']]);
            } catch (Throwable $exception) {
                error_log('[Bilnex CRM] SQL Server Customer write failed: ' . $exception->getMessage());
                flash('Cari SQL Server kaydına yazılamadı: ' . $exception->getMessage(), 'danger');
                redirect_to('company_form');
            }
        }
        if ($id > 0) {
            $data[':id'] = $id;
            db()->prepare('UPDATE companies SET name = :name, sql_customer_id = :sql_customer_id, account_type = :account_type, account_code = :account_code, contact_person = :contact_person, phone = :phone, email = :email, city = :city, district = :district, address = :address, tax_no = :tax_no, balance_amount = :balance_amount, balance_side = :balance_side, status = :status, source = :source, responsible_user_id = :responsible_user_id, description = :description, updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute($data);
            flash('Cari kartı güncellendi.');
        } else {
            $data[':created_by'] = current_user()['id'];
            db()->prepare('INSERT INTO companies (name, sql_customer_id, account_type, account_code, contact_person, phone, email, city, district, address, tax_no, balance_amount, balance_side, status, source, responsible_user_id, description, created_by) VALUES (:name, :sql_customer_id, :account_type, :account_code, :contact_person, :phone, :email, :city, :district, :address, :tax_no, :balance_amount, :balance_side, :status, :source, :responsible_user_id, :description, :created_by)')->execute($data);
            $id = (int) db()->lastInsertId();
            flash('Cari kartı oluşturuldu.');
        }
        redirect_to('company_view', ['id' => $id]);
    }

    if ($page === 'save_interaction') {
        $companyId = (int) ($_POST['company_id'] ?? 0);
        require_company_access($companyId);
        $result = $_POST['result'] ?? 'Tekrar aranacak';
        $nextFollowupDate = $_POST['next_followup_date'] ?: null;
        $data = [
            ':company_id' => $companyId,
            ':sql_customer_id' => company_sql_customer_id($companyId),
            ':user_id' => current_user()['id'],
            ':interaction_date' => $_POST['interaction_date'] ?: date('Y-m-d'),
            ':type' => $_POST['type'] ?? 'Telefon',
            ':result' => $result,
            ':note' => trim($_POST['note'] ?? ''),
            ':next_followup_date' => $nextFollowupDate,
        ];
        db()->prepare('INSERT INTO interactions (company_id, sql_customer_id, user_id, interaction_date, type, result, note, next_followup_date) VALUES (:company_id, :sql_customer_id, :user_id, :interaction_date, :type, :result, :note, :next_followup_date)')->execute($data);
        if (company_source() !== 'sqlserver') {
            db()->prepare("UPDATE companies SET next_followup_date = COALESCE(:next_followup_date, next_followup_date), status = CASE WHEN status = 'Yeni kayıt' THEN 'Görüşüldü' ELSE status END, updated_at = CURRENT_TIMESTAMP WHERE id = :company_id")->execute([
                ':next_followup_date' => $data[':next_followup_date'],
                ':company_id' => $companyId,
            ]);
            $statusByResult = [
                'Olumlu' => 'Görüşüldü',
                'Olumsuz' => 'Olumsuz',
                'Teklif istiyor' => 'Teklif bekliyor',
                'Ulaşılamadı' => 'Ulaşılamadı',
            ];
            $companyStatus = $statusByResult[$result] ?? null;
            $closesFollowup = $nextFollowupDate === null && in_array($result, ['Olumlu', 'Olumsuz'], true);
            if ($closesFollowup) {
                db()->prepare('UPDATE companies SET next_followup_date = NULL, status = COALESCE(:status, status), updated_at = CURRENT_TIMESTAMP WHERE id = :company_id')->execute([
                    ':status' => $result === 'Olumlu' ? null : $companyStatus,
                    ':company_id' => $companyId,
                ]);
            } elseif ($companyStatus !== null && $result !== 'Olumlu') {
                db()->prepare('UPDATE companies SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :company_id')->execute([
                    ':status' => $companyStatus,
                    ':company_id' => $companyId,
                ]);
            }
        }
        flash('Görüşme notu eklendi.');
        redirect_to('company_view', ['id' => $companyId]);
    }

    if ($page === 'save_opportunity') {
        $id = (int) ($_POST['id'] ?? 0);
        $companyId = (int) ($_POST['company_id'] ?? 0);
        if ($companyId <= 0) {
            $companyId = company_id_from_lookup($_POST['company_lookup'] ?? '');
        }
        if ($id > 0) {
            $existing = rows('SELECT company_id, salesperson_id FROM opportunities WHERE id = :id', [':id' => $id])[0] ?? null;
            if (!$existing) {
                http_response_code(404);
                exit('Satış fırsatı bulunamadı.');
            }
            if (!can_view_all() && (int) $existing['salesperson_id'] !== (int) current_user()['id']) {
                http_response_code(403);
                exit('Bu satış fırsatını düzenleme yetkiniz yok.');
            }
            require_company_access((int) $existing['company_id']);
        }
        if ($companyId <= 0) {
            flash('İlgili cari seçimi zorunludur.', 'danger');
            redirect_to('opportunity_form', $id > 0 ? ['id' => $id] : []);
        }
        require_company_access($companyId);
        $salesperson = (int) ($_POST['salesperson_id'] ?? current_user()['id']);
        if (!can_view_all()) {
            $salesperson = (int) current_user()['id'];
        }
        $data = [
            ':company_id' => $companyId,
            ':sql_customer_id' => company_sql_customer_id($companyId),
            ':salesperson_id' => $salesperson,
            ':product_service' => trim($_POST['product_service'] ?? ''),
            ':estimated_amount' => (float) str_replace(',', '.', $_POST['estimated_amount'] ?? 0),
            ':stage' => $_POST['stage'] ?? 'Yeni fırsat',
            ':expected_close_date' => $_POST['expected_close_date'] ?: null,
            ':note' => trim($_POST['note'] ?? ''),
        ];
        if (!in_array($data[':stage'], opportunity_stages(), true)) {
            $data[':stage'] = 'Yeni fırsat';
        }
        if ($data[':product_service'] === '') {
            flash('Ürün / hizmet alanı zorunludur.', 'danger');
            redirect_to('opportunity_form', $id > 0 ? ['id' => $id] : ['company_id' => $companyId]);
        }
        if ($id > 0) {
            $data[':id'] = $id;
            db()->prepare('UPDATE opportunities SET company_id = :company_id, sql_customer_id = :sql_customer_id, salesperson_id = :salesperson_id, product_service = :product_service, estimated_amount = :estimated_amount, stage = :stage, expected_close_date = :expected_close_date, note = :note, updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute($data);
            flash('Satış fırsatı güncellendi.');
        } else {
            db()->prepare('INSERT INTO opportunities (company_id, sql_customer_id, salesperson_id, product_service, estimated_amount, stage, expected_close_date, note) VALUES (:company_id, :sql_customer_id, :salesperson_id, :product_service, :estimated_amount, :stage, :expected_close_date, :note)')->execute($data);
            flash('Satış fırsatı oluşturuldu.');
        }
        redirect_to('opportunities');
    }
}

if ($page === 'dashboard') {
    render_header('Dashboard');
    $today = date('Y-m-d');
    $weekStart = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
    $monthStart = date('Y-m-01');
    if (can_view_all()) {
        $lastWeekStart = (new DateTimeImmutable('monday last week'))->format('Y-m-d');
        $lastWeekEnd = (new DateTimeImmutable('sunday last week'))->format('Y-m-d');
        $weekCount = (int) scalar('SELECT COUNT(*) FROM interactions WHERE date(interaction_date) >= :week', [':week' => $weekStart]);
        $lastWeekCount = (int) scalar('SELECT COUNT(*) FROM interactions WHERE date(interaction_date) BETWEEN :start AND :end', [':start' => $lastWeekStart, ':end' => $lastWeekEnd]);
        $wonAmount = (float) scalar('SELECT COALESCE(SUM(estimated_amount), 0) FROM opportunities WHERE stage = "Kazanıldı"');
        $openAmount = (float) scalar('SELECT COALESCE(SUM(estimated_amount), 0) FROM opportunities WHERE stage NOT IN ("Kazanıldı", "Kaybedildi")');
        $conversionWon = (int) scalar('SELECT COUNT(*) FROM opportunities WHERE stage = "Kazanıldı"');
        $conversionClosed = (int) scalar('SELECT COUNT(*) FROM opportunities WHERE stage IN ("Kazanıldı", "Kaybedildi")');
        $conversionRate = $conversionClosed > 0 ? round(($conversionWon / $conversionClosed) * 100) . '%' : '0%';
        $totalCompanyCount = company_source() === 'sqlserver'
            ? bilnex_customer_reader()->countActiveCustomers()
            : scalar('SELECT COUNT(*) FROM companies');
        $cards = [
            ['Bugünkü görüşme', scalar('SELECT COUNT(*) FROM interactions WHERE date(interaction_date) = :today', [':today' => $today]), 'Günlük aktivite', ''],
            ['Bu haftaki görüşme', $weekCount, 'Geçen hafta: ' . $lastWeekCount, ''],
            ['Toplam bayi adayı', $totalCompanyCount, company_source() === 'sqlserver' ? 'SQL Customer kaynağı' : 'Tüm kayıtlar', ''],
            ['Açık işler', scalar("SELECT COUNT(*) FROM tasks WHERE status = 'Açık'"), 'Atanan işler', 'danger-stat'],
            ['Açık fırsat', scalar('SELECT COUNT(*) FROM opportunities WHERE stage NOT IN ("Kazanıldı", "Kaybedildi")'), money($openAmount), ''],
            ['Kazanılan satış', money($wonAmount), 'Kapanış oranı: ' . $conversionRate, 'success-stat'],
        ];
        $cardLinks = [
            app_url('reports', ['date_filter' => 'today']),
            app_url('reports', ['date_filter' => 'week']),
            app_url('companies'),
            app_url('followups', ['status' => 'Açık']),
            app_url('opportunities', ['stage_group' => 'open']),
            app_url('opportunities', ['stage' => 'Kazanıldı']),
        ];
        echo '<section class="stats-grid">';
        foreach ($cards as $index => [$label, $value, $hint, $tone]) {
            dashboard_card($label, $value, $hint, $tone, $cardLinks[$index] ?? '');
        }
        echo '</section>';

        $dashboardTasks = rows("SELECT t.*, assigner.full_name assigned_by_name, assignee.full_name assigned_to_name FROM tasks t LEFT JOIN users assigner ON assigner.id = t.assigned_by LEFT JOIN users assignee ON assignee.id = t.assigned_to WHERE t.status = 'Açık' ORDER BY COALESCE(t.due_date, '9999-12-31'), t.created_at DESC LIMIT 10");
        echo '<section class="panel"><div class="section-title"><h2>Takip edilecek işler</h2><a class="btn small" href="' . e(app_url('followups')) . '">İş listesi</a></div><div class="table-wrap"><table><thead><tr><th>İş</th><th>Atayan</th><th>Atanan</th><th>Termin</th></tr></thead><tbody>';
        foreach ($dashboardTasks as $task) {
            echo '<tr><td><strong>' . e($task['title']) . '</strong></td><td>' . e($task['assigned_by_name']) . '</td><td>' . e($task['assigned_to_name']) . '</td><td>' . e($task['due_date']) . '</td></tr>';
        }
        if (!$dashboardTasks) {
            echo '<tr><td colspan="4" class="muted">Açık iş yok.</td></tr>';
        }
        echo '</tbody></table></div></section>';

        $staff = rows('SELECT u.full_name, COUNT(i.id) total FROM users u LEFT JOIN interactions i ON i.user_id = u.id AND date(i.interaction_date) >= :week WHERE u.active = 1 GROUP BY u.id ORDER BY total DESC, u.full_name LIMIT 8', [':week' => $weekStart]);
        if (company_source() === 'sqlserver') {
            $statusRows = array_map(static function (array $row): array {
                return [
                    'status' => $row['CustomerTypeName'] ?: sql_customer_type_label((int) ($row['CustomerTypeId'] ?? 0)),
                    'total' => (int) ($row['total'] ?? 0),
                ];
            }, bilnex_customer_reader()->countActiveCustomersByType());
        } else {
            $statusRows = rows('SELECT status, COUNT(*) total FROM companies GROUP BY status ORDER BY total DESC');
        }
        $pipeline = rows('SELECT stage, COUNT(*) total, COALESCE(SUM(estimated_amount), 0) amount FROM opportunities GROUP BY stage ORDER BY CASE stage WHEN "Yeni fırsat" THEN 1 WHEN "Görüşme yapılıyor" THEN 2 WHEN "Teklif verildi" THEN 3 WHEN "Sözleşme bekleniyor" THEN 4 WHEN "Kazanıldı" THEN 5 WHEN "Kaybedildi" THEN 6 ELSE 7 END');
        $trend = rows('SELECT date(interaction_date) day, COUNT(*) total FROM interactions WHERE date(interaction_date) >= date(:today, "-6 day") GROUP BY date(interaction_date) ORDER BY day', [':today' => $today]);
        $overdueRows = rows('SELECT c.id, c.name, c.next_followup_date, u.full_name responsible_name FROM companies c LEFT JOIN users u ON u.id = c.responsible_user_id WHERE c.next_followup_date IS NOT NULL AND date(c.next_followup_date) < :today ORDER BY c.next_followup_date ASC LIMIT 8', [':today' => $today]);
        $openOpps = rows('SELECT o.id, o.product_service, o.estimated_amount, o.stage, o.expected_close_date, c.name company_name, u.full_name salesperson_name FROM opportunities o JOIN companies c ON c.id = o.company_id LEFT JOIN users u ON u.id = o.salesperson_id WHERE o.stage NOT IN ("Kazanıldı", "Kaybedildi") ORDER BY o.estimated_amount DESC LIMIT 8');

        echo '<section class="dashboard-grid">';
        echo '<article class="panel chart-panel"><div class="section-title"><h2>Personel bazlı haftalık görüşme</h2><a class="btn small" href="' . e(app_url('reports', ['date_filter' => 'week'])) . '">Raporu aç</a></div>';
        render_bar_list($staff, 'full_name', 'total');
        echo '</article>';

        echo '<article class="panel chart-panel"><div class="section-title"><h2>Cari türü dağılımı</h2><a class="btn small" href="' . e(app_url('companies')) . '">Liste</a></div><div class="analytics-split">';
        render_donut_chart($statusRows, 'status', 'total');
        render_linked_bar_list($statusRows, 'status', 'total', static fn($row) => app_url('companies', ['account_type' => $row['status']]));
        echo '</div>';
        echo '</article>';

        echo '<article class="panel chart-panel wide-panel"><div class="section-title"><h2>Satış pipeline</h2><a class="btn small" href="' . e(app_url('opportunities')) . '">Fırsatlar</a></div>';
        echo '<div class="pipeline">';
        foreach ($pipeline as $row) {
            echo '<div class="pipeline-step"><span>' . e($row['stage']) . '</span><strong>' . e($row['total']) . '</strong><small>' . e(money($row['amount'])) . '</small></div>';
        }
        if (!$pipeline) {
            echo '<p class="muted">Satış fırsatı yok.</p>';
        }
        echo '</div></article>';

        echo '<article class="panel chart-panel"><h2>Son 7 gün görüşme trendi</h2>';
        render_trend_chart($trend, 'day', 'total');
        echo '</article>';

        echo '<article class="panel chart-panel"><h2>Satış aşaması tutarları</h2>';
        render_amount_bar_list($pipeline, 'stage', 'total', 'amount');
        echo '</article>';
        echo '</section>';

        echo '<section class="grid-two">';
        echo '<article class="panel"><div class="section-title"><h2>Geciken takipler</h2><a class="btn small" href="' . e(app_url('followups', ['date_filter' => 'custom', 'date_to' => $today])) . '">Takipleri aç</a></div><div class="table-wrap"><table><thead><tr><th>Cari</th><th>Sorumlu</th><th>Takip tarihi</th></tr></thead><tbody>';
        foreach ($overdueRows as $row) {
            echo '<tr class="overdue"><td><a href="' . e(app_url('company_view', ['id' => $row['id']])) . '">' . e($row['name']) . '</a></td><td>' . e($row['responsible_name']) . '</td><td>' . e($row['next_followup_date']) . '</td></tr>';
        }
        if (!$overdueRows) {
            echo '<tr><td colspan="3" class="muted">Geciken takip yok.</td></tr>';
        }
        echo '</tbody></table></div></article>';

        echo '<article class="panel"><div class="section-title"><h2>En yüksek açık fırsatlar</h2><a class="btn small" href="' . e(app_url('opportunities')) . '">Tümünü gör</a></div><div class="table-wrap"><table><thead><tr><th>Cari</th><th>Fırsat</th><th>Aşama</th><th>Tutar</th></tr></thead><tbody>';
        foreach ($openOpps as $row) {
            echo '<tr><td>' . e($row['company_name']) . '<small>' . e($row['salesperson_name']) . '</small></td><td>' . e($row['product_service']) . '</td><td>' . e($row['stage']) . '</td><td>' . e(money($row['estimated_amount'])) . '</td></tr>';
        }
        if (!$openOpps) {
            echo '<tr><td colspan="4" class="muted">Açık fırsat yok.</td></tr>';
        }
        echo '</tbody></table></div></article>';
        echo '</section>';
    } else {
        $uid = current_user()['id'];
        $myPipeline = rows('SELECT stage, COUNT(*) total, COALESCE(SUM(estimated_amount), 0) amount FROM opportunities WHERE salesperson_id = :uid GROUP BY stage ORDER BY total DESC', [':uid' => $uid]);
        $myTrend = rows('SELECT date(interaction_date) day, COUNT(*) total FROM interactions WHERE user_id = :uid AND date(interaction_date) >= date(:today, "-6 day") GROUP BY date(interaction_date) ORDER BY day', [':uid' => $uid, ':today' => $today]);
        $todayFollowups = rows('SELECT id, name, contact_person, phone, status, next_followup_date FROM companies WHERE (responsible_user_id = :uid OR created_by = :uid) AND date(next_followup_date) = :today ORDER BY name LIMIT 6', [':uid' => $uid, ':today' => $today]);
        $overdueFollowups = rows('SELECT id, name, contact_person, phone, status, next_followup_date FROM companies WHERE (responsible_user_id = :uid OR created_by = :uid) AND next_followup_date IS NOT NULL AND date(next_followup_date) < :today ORDER BY next_followup_date LIMIT 6', [':uid' => $uid, ':today' => $today]);
        $priorityFollowups = $overdueFollowups ?: $todayFollowups;
        $myOpenOpps = rows('SELECT o.id, o.company_id, o.product_service, o.estimated_amount, o.stage, o.expected_close_date, c.name company_name FROM opportunities o JOIN companies c ON c.id = o.company_id WHERE o.salesperson_id = :uid AND o.stage NOT IN ("Kazanıldı", "Kaybedildi") ORDER BY COALESCE(o.expected_close_date, "9999-12-31"), o.estimated_amount DESC LIMIT 6', [':uid' => $uid]);
        $lastInteraction = scalar('SELECT MAX(interaction_date) FROM interactions WHERE user_id = :uid', [':uid' => $uid]);
        $cards = [
            ['Bana atanan işler', scalar("SELECT COUNT(*) FROM tasks WHERE assigned_to = :uid AND status = 'Açık'", [':uid' => $uid])],
            ['Atadığım işler', scalar("SELECT COUNT(*) FROM tasks WHERE assigned_by = :uid AND status = 'Açık'", [':uid' => $uid])],
            ['Açık fırsatlarım', scalar('SELECT COUNT(*) FROM opportunities WHERE salesperson_id = :uid AND stage NOT IN ("Kazanıldı", "Kaybedildi")', [':uid' => $uid])],
            ['Bu ay görüşmelerim', scalar('SELECT COUNT(*) FROM interactions WHERE user_id = :uid AND date(interaction_date) >= :month', [':uid' => $uid, ':month' => $monthStart])],
        ];
        echo '<section class="field-hero panel">';
        echo '<div><span class="eyebrow">Hızlı çalışma alanı</span><h2>Bugün odaklanman gereken işler</h2><p>Takiplerini, yeni firma girişini ve satış fırsatlarını tek ekrandan yönet.</p></div>';
        echo '<div class="quick-actions">';
        echo '<a class="btn primary" href="' . e(app_url('company_form')) . '">Yeni cari</a>';
        echo '<a class="btn" href="' . e(app_url('followups')) . '">İş listesi</a>';
        echo '<a class="btn" href="' . e(app_url('opportunity_form')) . '">Yeni fırsat</a>';
        echo '</div></section>';
        $assignedToMe = rows("SELECT t.*, u.full_name assigned_by_name FROM tasks t LEFT JOIN users u ON u.id = t.assigned_by WHERE t.assigned_to = :uid AND t.status = 'Açık' ORDER BY COALESCE(t.due_date, '9999-12-31'), t.created_at DESC LIMIT 6", [':uid' => $uid]);
        $assignedByMe = rows("SELECT t.*, u.full_name assigned_to_name FROM tasks t LEFT JOIN users u ON u.id = t.assigned_to WHERE t.assigned_by = :uid AND t.status = 'Açık' ORDER BY COALESCE(t.due_date, '9999-12-31'), t.created_at DESC LIMIT 6", [':uid' => $uid]);
        echo '<section class="grid-two">';
        echo '<article class="panel focus-card"><div class="section-title"><h2>Bana atanan işler</h2><a class="btn small" href="' . e(app_url('followups')) . '">Aç</a></div><div class="mini-card-list">';
        foreach ($assignedToMe as $task) {
            echo '<a class="mini-card" href="' . e(app_url('followups')) . '"><strong>' . e($task['title']) . '</strong><span>Atayan: ' . e($task['assigned_by_name']) . '</span><small>Termin: ' . e($task['due_date'] ?: '-') . '</small></a>';
        }
        if (!$assignedToMe) {
            echo '<p class="muted">Bana atanmış açık iş yok.</p>';
        }
        echo '</div></article>';
        echo '<article class="panel focus-card"><div class="section-title"><h2>Atadığım işler</h2><a class="btn small" href="' . e(app_url('followups')) . '">Aç</a></div><div class="mini-card-list">';
        foreach ($assignedByMe as $task) {
            echo '<a class="mini-card" href="' . e(app_url('followups')) . '"><strong>' . e($task['title']) . '</strong><span>Atanan: ' . e($task['assigned_to_name']) . '</span><small>Termin: ' . e($task['due_date'] ?: '-') . '</small></a>';
        }
        if (!$assignedByMe) {
            echo '<p class="muted">Atadığım açık iş yok.</p>';
        }
        echo '</div></article></section>';
        echo '<section class="stats-grid">';
        foreach ($cards as [$label, $value]) {
            echo '<article class="stat"><span>' . e($label) . '</span><strong>' . e($value) . '</strong></article>';
        }
        echo '</section>';
        echo '<section class="field-focus-grid">';
        echo '<article class="panel focus-card"><div class="section-title"><h2>Öncelikli takipler</h2><a class="btn small" href="' . e(app_url('followups')) . '">Tüm takipler</a></div><div class="mini-card-list">';
        foreach ($priorityFollowups as $row) {
            $isLate = strtotime($row['next_followup_date']) < strtotime($today);
            echo '<a class="mini-card ' . ($isLate ? 'late' : '') . '" href="' . e(app_url('company_view', ['id' => $row['id']])) . '">';
            echo '<strong>' . e($row['name']) . '</strong><span>' . e($row['contact_person']) . ' · ' . e($row['phone']) . '</span><small>' . e($row['status']) . ' · Takip: ' . e($row['next_followup_date']) . '</small></a>';
        }
        if (!$priorityFollowups) {
            echo '<p class="muted">Bugün veya geçmiş tarihli takip yok.</p>';
        }
        echo '</div></article>';
        echo '<article class="panel focus-card"><div class="section-title"><h2>Açık fırsatlarım</h2><a class="btn small" href="' . e(app_url('opportunities')) . '">Tüm fırsatlar</a></div><div class="mini-card-list">';
        foreach ($myOpenOpps as $row) {
            echo '<a class="mini-card" href="' . e(app_url('opportunity_form', ['id' => $row['id']])) . '">';
            echo '<strong>' . e($row['company_name']) . '</strong><span>' . e($row['product_service']) . ' · ' . e($row['stage']) . '</span><small>' . e(money($row['estimated_amount'])) . ' · Kapanış: ' . e($row['expected_close_date']) . '</small></a>';
        }
        if (!$myOpenOpps) {
            echo '<p class="muted">Açık satış fırsatı yok.</p>';
        }
        echo '</div></article>';
        echo '<article class="panel focus-card compact-focus"><h2>Kısa durum</h2><dl class="meta"><dt>Son görüşme</dt><dd>' . e($lastInteraction ?: 'Yok') . '</dd><dt>Öneri</dt><dd>' . ($overdueFollowups ? 'Önce geciken takipleri kapatın.' : 'Bugünkü takipleri tamamlayın.') . '</dd></dl></article>';
        echo '</section>';
        echo '<section class="dashboard-grid compact-dashboard">';
        echo '<article class="panel chart-panel"><h2>Fırsat aşamalarım</h2>';
        render_amount_bar_list($myPipeline, 'stage', 'total', 'amount');
        echo '</article>';
        echo '<article class="panel chart-panel"><h2>Son 7 gün görüşmelerim</h2>';
        render_bar_list($myTrend, 'day', 'total');
        echo '</article>';
        echo '</section>';
        $recent = rows('SELECT id, name, status, next_followup_date FROM companies WHERE responsible_user_id = :uid OR created_by = :uid ORDER BY created_at DESC LIMIT 8', [':uid' => $uid]);
        echo '<section class="panel"><h2>Son eklediğim cariler</h2><div class="table-wrap"><table><thead><tr><th>Cari</th><th>Durum</th><th>Takip</th></tr></thead><tbody>';
        foreach ($recent as $row) {
            echo '<tr><td><a href="' . e(app_url('company_view', ['id' => $row['id']])) . '">' . e($row['name']) . '</a></td><td>' . e($row['status']) . '</td><td>' . e($row['next_followup_date']) . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }
    render_footer();
    exit;
}

if ($page === 'users') {
    if (!can_manage_users()) {
        http_response_code(403);
        exit('Yetkisiz işlem.');
    }
    $edit = null;
    if (!empty($_GET['id'])) {
        $edit = rows('SELECT * FROM users WHERE id = :id', [':id' => (int) $_GET['id']])[0] ?? null;
    }
    render_header('Kullanıcı Yönetimi');
    ?>
    <section class="grid-two">
        <form class="panel stack" method="post" action="<?= e(app_url('save_user')) ?>">
            <h2><?= $edit ? 'Kullanıcı düzenle' : 'Yeni kullanıcı' ?></h2>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($edit['id'] ?? 0) ?>">
            <label>Ad soyad <input name="full_name" value="<?= e($edit['full_name'] ?? '') ?>" required></label>
            <label>Kullanıcı adı <input name="username" value="<?= e($edit['username'] ?? '') ?>" required></label>
            <label>Şifre <input name="password" type="password" placeholder="<?= $edit ? 'Değişmeyecekse boş bırakın' : '' ?>"></label>
            <label>Rol
                <select name="role">
                    <?php foreach (role_options() as $value => $label): ?>
                        <option value="<?= e($value) ?>"<?= selected($edit['role'] ?? ROLE_SALES, $value) ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="check"><input type="checkbox" name="active"<?= checked($edit['active'] ?? true) ?>> Aktif</label>
            <button class="btn primary" type="submit">Kaydet</button>
        </form>
        <section class="panel">
            <h2>Kullanıcılar</h2>
            <form class="filters compact" method="get">
                <input type="hidden" name="page" value="users">
                <input name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Kullanıcı ara...">
                <button class="btn" type="submit">Ara</button>
            </form>
            <?php
                $userWhere = '';
                $userParams = [];
                if (!empty($_GET['q'])) {
                    $userWhere = ' WHERE full_name LIKE :q OR username LIKE :q';
                    $userParams[':q'] = '%' . $_GET['q'] . '%';
                }
                $userPage = max(1, (int) ($_GET['p'] ?? 1));
                $userPerPage = 25;
                $userOffset = ($userPage - 1) * $userPerPage;
                $userTotal = (int) scalar("SELECT COUNT(*) FROM users {$userWhere}", $userParams);
                $userRows = rows("SELECT * FROM users {$userWhere} ORDER BY created_at DESC LIMIT {$userPerPage} OFFSET {$userOffset}", $userParams);
            ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Ad</th><th>Kullanıcı adı</th><th>Rol</th><th>Durum</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($userRows as $row): ?>
                    <tr data-href="<?= e(app_url('users', ['id' => $row['id']])) ?>">
                        <td><?= e($row['full_name']) ?></td>
                        <td><?= e($row['username']) ?></td>
                        <td><?= e(role_label($row['role'])) ?></td>
                        <td><?= $row['active'] ? 'Aktif' : 'Pasif' ?></td>
                        <td><a class="btn small" href="<?= e(app_url('users', ['id' => $row['id']])) ?>">Düzenle</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php render_pagination('users', $userPage, $userPerPage, $userTotal); ?>
        </section>
    </section>
    <?php
    render_footer();
    exit;
}

if ($page === 'companies') {
    render_header('Cariler');
    $usingSqlServerCompanies = company_source() === 'sqlserver';
    $where = ' WHERE 1 = 1';
    $params = [];
    [$scopeSql, $scopeParams] = owned_company_condition('c');
    $where .= $scopeSql;
    $params += $scopeParams;
    if (!empty($_GET['q'])) {
        $where .= ' AND (c.name LIKE :q OR c.account_code LIKE :q OR c.tax_no LIKE :q OR c.contact_person LIKE :q OR c.phone LIKE :q OR c.email LIKE :q OR c.city LIKE :q)';
        $params[':q'] = '%' . $_GET['q'] . '%';
    }
    if (!$usingSqlServerCompanies && !empty($_GET['status'])) {
        $where .= ' AND c.status = :status';
        $params[':status'] = $_GET['status'];
    }
    if (!empty($_GET['account_type'])) {
        $where .= ' AND c.account_type = :account_type';
        $params[':account_type'] = normalize_company_account_type($_GET['account_type']);
    }
    if (!empty($_GET['responsible_user_id']) && can_view_all()) {
        $where .= ' AND c.responsible_user_id = :responsible_user_id';
        $params[':responsible_user_id'] = (int) $_GET['responsible_user_id'];
    }
    apply_date_filter($where, $params, 'c.created_at', $_GET);
    $users = active_users();
    $userOptions = [];
    foreach ($users as $u) {
        $userOptions[$u['id']] = $u['full_name'];
    }
    $extras = [
        'account_type' => ['_label' => 'Cari türü', 'items' => array_combine(company_account_types(), company_account_types())],
    ];
    if (!$usingSqlServerCompanies) {
        $extras['status'] = ['_label' => 'Durum', 'items' => array_combine(company_statuses(), company_statuses())];
    }
    if (can_view_all()) {
        $extras['responsible_user_id'] = ['_label' => 'Sorumlu', 'items' => $userOptions];
    }
    filter_bar('companies', $extras);
    $pageNumber = max(1, (int) ($_GET['p'] ?? 1));
    $perPage = (int) ($_GET['per_page'] ?? 100);
    if (!in_array($perPage, [50, 100, 250, 500], true)) {
        $perPage = 100;
    }
    $offset = ($pageNumber - 1) * $perPage;
    $sqlCustomerReader = $usingSqlServerCompanies ? bilnex_customer_reader() : null;
    $sqlCustomerTypeId = $usingSqlServerCompanies && !empty($_GET['account_type'])
        ? company_account_type_sql_id($_GET['account_type'])
        : null;
    $companyTotal = $usingSqlServerCompanies
        ? 0
        : (int) scalar("SELECT COUNT(*) FROM companies c {$where}", $params);
    $companies = $usingSqlServerCompanies
        ? sql_customer_rows_for_company_list($perPage, $sqlCustomerTypeId, $offset, (string) ($_GET['q'] ?? ''))
        : rows("SELECT c.*, u.full_name responsible_name FROM companies c LEFT JOIN users u ON u.id = c.responsible_user_id {$where} ORDER BY c.updated_at DESC LIMIT {$perPage} OFFSET {$offset}", $params);
    $sqlCustomerReadError = $sqlCustomerReader instanceof CustomerReadRepository ? $sqlCustomerReader->lastError() : null;
    if ($usingSqlServerCompanies && !$sqlCustomerReadError && $sqlCustomerReader instanceof CustomerReadRepository) {
        $activeCustomerTotal = $sqlCustomerReader->countActiveCustomers($sqlCustomerTypeId, (string) ($_GET['q'] ?? ''));
        $sqlCustomerReadError = $sqlCustomerReader->lastError();
        if (!$sqlCustomerReadError) {
            $companyTotal = $activeCustomerTotal;
        }
    }
    ?>
    <?php if ($usingSqlServerCompanies): ?>
        <div class="alert">Cari listesi SQL Server dbo.Customer kaynağından okunuyor. Yeni cari kayıtları SQL Server'a yazılır.</div>
        <?php if ($sqlCustomerReadError): ?>
            <div class="alert alert-danger">
                SQL Server Customer kaynagi okunamadi. Coolify ortam degiskenlerinde BILNEX_SQL_SERVER, BILNEX_SQL_DATABASE, kullanici ve sifreyi kontrol edin.
                <?php if (can_manage_users()): ?>
                    <br><small><?= e($sqlCustomerReadError) ?></small>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <section class="panel">
        <div class="section-title">
            <div>
                <h2>Cari listesi</h2>
                <p class="muted"><?= e($companyTotal) ?> kayıttan <?= e(count($companies)) ?> kayıt gösteriliyor.</p>
            </div>
            <form class="inline-form" method="get">
                <?php foreach ($_GET as $name => $value): ?>
                    <?php if (!in_array($name, ['page', 'per_page', 'p'], true)): ?><input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>"><?php endif; ?>
                <?php endforeach; ?>
                <input type="hidden" name="page" value="companies">
                <select name="per_page" aria-label="Sayfa başına kayıt">
                    <?php foreach ([50, 100, 250, 500] as $size): ?>
                        <option value="<?= e($size) ?>"<?= selected($perPage, $size) ?>><?= e($size) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn small" type="submit">Uygula</button>
            </form>
        </div>
        <div class="table-wrap"><table class="companies-table">
            <thead><tr><th>Cari kodu</th><th>Cari</th><th>Cari türü</th><th>Yetkili</th><th>Telefon</th><th>İl</th><?php if (!$usingSqlServerCompanies): ?><th>Durum</th><?php endif; ?><th>Sorumlu</th><th>Sonraki takip</th><th>Aksiyon</th></tr></thead>
            <tbody>
            <?php foreach ($companies as $row): ?>
                <tr<?= !$usingSqlServerCompanies ? ' data-href="' . e(app_url('company_view', ['id' => $row['id']])) . '"' : '' ?>>
                    <td><?= e($row['account_code'] ?? '') ?></td>
                    <td>
                        <?php if ($usingSqlServerCompanies): ?>
                            <?= e($row['name']) ?>
                        <?php else: ?>
                            <a href="<?= e(app_url('company_view', ['id' => $row['id']])) ?>"><?= e($row['name']) ?></a>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge soft"><?= e($row['account_type'] ?? 'İş Ortağı') ?></span></td>
                    <td><?= e($row['contact_person']) ?></td>
                    <td><?php if ($row['phone']): ?><a href="tel:<?= e($row['phone']) ?>"><?= e($row['phone']) ?></a><?php endif; ?></td>
                    <td><?= e($row['city']) ?></td>
                    <?php if (!$usingSqlServerCompanies): ?><td><span class="badge"><?= e($row['status']) ?></span></td><?php endif; ?>
                    <td><?= e($row['responsible_name']) ?></td>
                    <td><?= e($row['next_followup_date']) ?></td>
                    <td>
                        <?php if ($usingSqlServerCompanies): ?>
                            <span class="badge soft">Sadece okuma</span>
                        <?php else: ?>
                            <a class="btn small" href="<?= e(app_url('company_view', ['id' => $row['id']])) ?>">Görüşme ekle</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php render_pagination('companies', $pageNumber, $perPage, $companyTotal); ?>
    </section>
    <?php
    render_footer();
    exit;
}

if ($page === 'company_form') {
    $id = (int) ($_GET['id'] ?? 0);
    $company = null;
    $usesSqlCustomerWrite = company_source() === 'sqlserver';
    if ($id > 0) {
        require_company_access($id);
        $company = rows('SELECT * FROM companies WHERE id = :id', [':id' => $id])[0] ?? null;
    }
    render_header($company ? 'Cari Düzenle' : 'Yeni Cari');
    ?>
    <form class="panel form-grid" method="post" action="<?= e(app_url('save_company')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($company['id'] ?? 0) ?>">
        <label>Cari adı <input name="name" value="<?= e($company['name'] ?? '') ?>" required></label>
        <label>SQL Customer Id <input name="sql_customer_id" inputmode="numeric" value="<?= e($company['sql_customer_id'] ?? '') ?>" placeholder="Bilnex Customer.Id"></label>
        <label>Cari türü
            <select name="account_type">
                <?php foreach (company_account_types() as $type): ?>
                    <option value="<?= e($type) ?>"<?= selected($company['account_type'] ?? 'İş Ortağı', $type) ?>><?= e($type) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Yetkili kişi <input name="contact_person" value="<?= e($company['contact_person'] ?? '') ?>"></label>
        <label>Cari kodu <input name="account_code" value="<?= e($company['account_code'] ?? '') ?>"></label>
        <label>Vergi no <input name="tax_no" value="<?= e($company['tax_no'] ?? '') ?>"></label>
        <label>Telefon <input name="phone" value="<?= e($company['phone'] ?? '') ?>"></label>
        <label>E-posta <input type="email" name="email" value="<?= e($company['email'] ?? '') ?>"></label>
        <label>İl <input name="city" value="<?= e($company['city'] ?? '') ?>"></label>
        <label>İlçe <input name="district" value="<?= e($company['district'] ?? '') ?>"></label>
        <label class="wide">Adres <textarea name="address"><?= e($company['address'] ?? '') ?></textarea></label>
        <label>Bakiye <input name="balance_amount" inputmode="decimal" value="<?= e($company['balance_amount'] ?? '') ?>"></label>
        <label>B/A <input name="balance_side" value="<?= e($company['balance_side'] ?? '') ?>"></label>
        <?php if (!$usesSqlCustomerWrite): ?>
            <label>Durum
                <select name="status">
                    <?php foreach (company_statuses() as $status): ?>
                        <option value="<?= e($status) ?>"<?= selected($company['status'] ?? 'Yeni kayıt', $status) ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <label>Kaynak <input name="source" value="<?= e($company['source'] ?? '') ?>"></label>
        <label>Sorumlu personel
            <select name="responsible_user_id"<?= can_view_all() ? '' : ' disabled' ?>>
                <?php foreach (active_users() as $user): ?>
                    <option value="<?= e($user['id']) ?>"<?= selected($company['responsible_user_id'] ?? current_user()['id'], $user['id']) ?>><?= e($user['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="wide">Açıklama <textarea name="description"><?= e($company['description'] ?? '') ?></textarea></label>
        <div class="actions wide">
            <button class="btn primary" type="submit">Kaydet</button>
            <?php if ($company): ?><a class="btn" href="<?= e(app_url('company_view', ['id' => $company['id']])) ?>">Kartı aç</a><?php endif; ?>
        </div>
    </form>
    <?php
    render_footer();
    exit;
}

if ($page === 'company_view') {
    $id = (int) ($_GET['id'] ?? 0);
    require_company_access($id);
    $company = rows('SELECT c.*, u.full_name responsible_name FROM companies c LEFT JOIN users u ON u.id = c.responsible_user_id WHERE c.id = :id', [':id' => $id])[0] ?? null;
    if (!$company) {
        http_response_code(404);
        exit('Cari bulunamadı.');
    }
    $usesSqlCustomerWrite = company_source() === 'sqlserver';
    render_header($company['name']);
    $interactions = rows('SELECT i.*, u.full_name user_name FROM interactions i LEFT JOIN users u ON u.id = i.user_id WHERE i.company_id = :id ORDER BY i.interaction_date DESC, i.created_at DESC', [':id' => $id]);
    $opps = rows('SELECT o.*, u.full_name salesperson_name FROM opportunities o LEFT JOIN users u ON u.id = o.salesperson_id WHERE o.company_id = :id ORDER BY o.updated_at DESC', [':id' => $id]);
    ?>
    <section class="detail-head">
        <div>
            <?php if (!$usesSqlCustomerWrite): ?><span class="badge"><?= e($company['status']) ?></span><?php endif; ?>
            <span class="badge soft"><?= e($company['account_type'] ?? 'İş Ortağı') ?></span>
            <p><?= e($company['contact_person']) ?> · <?= e($company['phone']) ?> · <?= e($company['email']) ?></p>
            <p><?= e(trim($company['city'] . ' ' . $company['district'])) ?> <?= e($company['address']) ?></p>
        </div>
        <a class="btn" href="<?= e(app_url('company_form', ['id' => $company['id']])) ?>">Düzenle</a>
    </section>
    <section class="grid-two">
        <div class="panel">
            <h2>Kart bilgileri</h2>
            <dl class="meta">
                <dt>Cari türü</dt><dd><?= e($company['account_type'] ?? 'İş Ortağı') ?></dd>
                <dt>SQL Customer Id</dt><dd><?= e($company['sql_customer_id'] ?? '-') ?></dd>
                <dt>Cari kodu</dt><dd><?= e($company['account_code'] ?? '') ?></dd>
                <dt>Vergi no</dt><dd><?= e($company['tax_no'] ?? '') ?></dd>
                <dt>Bakiye</dt><dd><?= e(money($company['balance_amount'] ?? 0)) ?> <?= e($company['balance_side'] ?? '') ?></dd>
                <dt>Kaynak</dt><dd><?= e($company['source']) ?></dd>
                <dt>Sorumlu</dt><dd><?= e($company['responsible_name']) ?></dd>
                <dt>Sonraki takip</dt><dd><?= e($company['next_followup_date']) ?></dd>
                <dt>Açıklama</dt><dd><?= nl2br(e($company['description'])) ?></dd>
            </dl>
        </div>
        <form class="panel stack" method="post" action="<?= e(app_url('save_interaction')) ?>">
            <h2>Görüşme notu ekle</h2>
            <?= csrf_field() ?>
            <input type="hidden" name="company_id" value="<?= e($company['id']) ?>">
            <label>Görüşme tarihi <input type="date" name="interaction_date" value="<?= e(date('Y-m-d')) ?>" required></label>
            <label>Görüşme türü <select name="type"><?php foreach (interaction_types() as $type): ?><option value="<?= e($type) ?>"><?= e($type) ?></option><?php endforeach; ?></select></label>
            <label>Görüşme sonucu <select name="result"><?php foreach (interaction_results() as $result): ?><option value="<?= e($result) ?>"><?= e($result) ?></option><?php endforeach; ?></select></label>
            <label>Sonraki takip tarihi <input type="date" name="next_followup_date"></label>
            <label>Görüşme notu <textarea name="note"></textarea></label>
            <button class="btn primary" type="submit">Görüşmeyi kaydet</button>
        </form>
    </section>
    <section class="panel">
        <h2>Görüşme geçmişi</h2>
        <div class="timeline">
            <?php foreach ($interactions as $item): ?>
                <article>
                    <strong><?= e($item['interaction_date']) ?> · <?= e($item['type']) ?> · <?= e($item['result']) ?></strong>
                    <span><?= e($item['user_name']) ?><?= $item['next_followup_date'] ? ' · Takip: ' . e($item['next_followup_date']) : '' ?></span>
                    <p><?= nl2br(e($item['note'])) ?></p>
                </article>
            <?php endforeach; ?>
            <?php if (!$interactions): ?><p class="muted">Henüz görüşme kaydı yok.</p><?php endif; ?>
        </div>
    </section>
    <section class="panel">
        <div class="section-title"><h2>Satış fırsatları</h2><a class="btn small" href="<?= e(app_url('opportunity_form', ['company_id' => $company['id']])) ?>">Yeni fırsat</a></div>
        <div class="table-wrap"><table><thead><tr><th>Ürün / hizmet</th><th>Satışçı</th><th>Tutar</th><th>Aşama</th><th>Kapanış</th></tr></thead><tbody>
            <?php foreach ($opps as $opp): ?>
                <tr><td><?= e($opp['product_service']) ?></td><td><?= e($opp['salesperson_name']) ?></td><td><?= e(money($opp['estimated_amount'])) ?></td><td><?= e($opp['stage']) ?></td><td><?= e($opp['expected_close_date']) ?></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
    </section>
    <?php
    render_footer();
    exit;
}

if ($page === 'task_form') {
    render_header('İş Ata');
    $users = active_users();
    $companyWhere = ' WHERE 1 = 1';
    $companyParams = [];
    if (!can_view_all()) {
        $companyWhere .= ' AND (responsible_user_id = :uid OR created_by = :uid)';
        $companyParams[':uid'] = current_user()['id'];
    }
    $taskCompanies = rows("SELECT id, name, account_code, sql_customer_id FROM companies {$companyWhere} ORDER BY name LIMIT 500", $companyParams);
    ?>
    <form class="panel form-grid" method="post" action="<?= e(app_url('save_task')) ?>">
        <h2 class="wide">İş ata</h2>
        <?= csrf_field() ?>
        <label>İş başlığı <input name="title" required placeholder="Yapılacak işi yazın"></label>
        <label>Atanan personel
            <select name="assigned_to" required>
                <?php foreach ($users as $user): ?>
                    <option value="<?= e($user['id']) ?>"<?= selected(current_user()['id'], $user['id']) ?>><?= e($user['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Termin tarihi <input type="date" name="due_date"></label>
        <label>İlgili cari
            <select name="company_id">
                <option value="">Bağlantı yok</option>
                <?php foreach ($taskCompanies as $company): ?>
                    <option value="<?= e($company['id']) ?>"><?= e(company_lookup_label($company)) ?><?= $company['sql_customer_id'] ? ' · SQL #' . e($company['sql_customer_id']) : '' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="wide">Açıklama <textarea name="description"></textarea></label>
        <div class="actions wide">
            <button class="btn primary" type="submit">İşi ata</button>
            <a class="btn" href="<?= e(app_url('followups')) ?>">Takvime dön</a>
        </div>
    </form>
    <?php
    render_footer();
    exit;
}

if ($page === 'followups') {
    render_header('Takip Listesi');
    $taskUsers = active_users();
    $taskCompanyWhere = ' WHERE 1 = 1';
    $taskCompanyParams = [];
    [$taskScopeSql, $taskScopeParams] = owned_company_condition('c');
    $taskCompanyWhere .= $taskScopeSql;
    $taskCompanyParams += $taskScopeParams;
    $taskCompanies = rows("SELECT c.id, c.name, c.account_code, c.account_type, c.sql_customer_id FROM companies c {$taskCompanyWhere} ORDER BY c.name LIMIT 300", $taskCompanyParams);
    $monthInput = $_GET['month'] ?? date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $monthInput)) {
        $monthInput = date('Y-m');
    }
    $monthStartDate = DateTimeImmutable::createFromFormat('!Y-m-d', $monthInput . '-01') ?: new DateTimeImmutable('first day of this month');
    $calendarStart = $monthStartDate->modify('monday this week');
    $calendarEnd = $monthStartDate->modify('last day of this month')->modify('sunday this week');
    $prevMonth = $monthStartDate->modify('-1 month')->format('Y-m');
    $nextMonth = $monthStartDate->modify('+1 month')->format('Y-m');

    $where = " WHERE t.status = 'Açık' AND t.due_date IS NOT NULL AND date(t.due_date) BETWEEN :calendar_start AND :calendar_end";
    $params = [
        ':calendar_start' => $calendarStart->format('Y-m-d'),
        ':calendar_end' => $calendarEnd->format('Y-m-d'),
    ];
    if (!can_view_all()) {
        $where .= ' AND (t.assigned_by = :uid OR t.assigned_to = :uid)';
        $params[':uid'] = current_user()['id'];
    }
    $calendarTasks = rows("SELECT t.*, c.name company_name, c.account_code company_account_code, assigner.full_name assigned_by_name, assignee.full_name assigned_to_name FROM tasks t LEFT JOIN companies c ON c.id = t.company_id LEFT JOIN users assigner ON assigner.id = t.assigned_by LEFT JOIN users assignee ON assignee.id = t.assigned_to {$where} ORDER BY t.due_date, t.created_at", $params);
    $tasksByDate = [];
    foreach ($calendarTasks as $task) {
        $tasksByDate[$task['due_date']][] = $task;
    }
    ?>
    <section class="panel">
        <div class="section-title">
            <h2>İş takvimi</h2>
            <div class="calendar-actions">
                <a class="btn" href="<?= e(app_url('followups', ['month' => $prevMonth])) ?>">Önceki</a>
                <a class="btn" href="<?= e(app_url('followups')) ?>">Bu ay</a>
                <a class="btn" href="<?= e(app_url('followups', ['month' => $nextMonth])) ?>">Sonraki</a>
                <button class="btn primary" type="button" data-open-dialog="#task-dialog">İş ata</button>
            </div>
        </div>
        <h3 class="calendar-title"><?= e($monthStartDate->format('m.Y')) ?></h3>
        <div class="task-calendar">
            <?php foreach (['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'] as $dayName): ?>
                <div class="calendar-weekday"><?= e($dayName) ?></div>
            <?php endforeach; ?>
            <?php for ($day = $calendarStart; $day <= $calendarEnd; $day = $day->modify('+1 day')): ?>
                <?php $dateKey = $day->format('Y-m-d'); ?>
                <article class="calendar-day <?= $day->format('Y-m') !== $monthInput ? 'muted-day' : '' ?> <?= $dateKey === date('Y-m-d') ? 'today' : '' ?>">
                    <strong><?= e($day->format('d')) ?></strong>
                    <?php foreach ($tasksByDate[$dateKey] ?? [] as $task): ?>
                        <div class="calendar-task">
                            <span><?= e($task['title']) ?></span>
                            <?php if (!empty($task['company_name'])): ?><small><?= e($task['company_name']) ?><?= $task['sql_customer_id'] ? ' · SQL #' . e($task['sql_customer_id']) : '' ?></small><?php endif; ?>
                            <small>Atayan: <?= e($task['assigned_by_name']) ?> · Atanan: <?= e($task['assigned_to_name']) ?></small>
                            <?php if ((int) $task['assigned_to'] === (int) current_user()['id']): ?>
                                <form method="post" action="<?= e(app_url('complete_task')) ?>" class="calendar-complete">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e($task['id']) ?>">
                                    <input name="completion_note" placeholder="Tamamlama açıklaması">
                                    <button class="btn small" type="submit">Tamamla</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </article>
            <?php endfor; ?>
        </div>
    </section>

    <dialog class="modal" id="task-dialog">
        <div class="modal-head">
            <h2>İş ata</h2>
            <button class="btn small" type="button" data-close-dialog>Kapat</button>
        </div>
        <form class="modal-body form-grid" method="post" action="<?= e(app_url('save_task')) ?>">
            <?= csrf_field() ?>
            <label>Konu <input name="title" required></label>
            <label>Sorumlu
                <select name="assigned_to" required>
                    <?php foreach ($taskUsers as $user): ?>
                        <option value="<?= e($user['id']) ?>"<?= selected(current_user()['id'], $user['id']) ?>><?= e($user['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Termin tarihi <input type="date" name="due_date"></label>
            <label>İlgili cari
                <select name="company_id">
                    <option value="">Bağlantı yok</option>
                    <?php foreach ($taskCompanies as $company): ?>
                        <option value="<?= e($company['id']) ?>"><?= e(company_lookup_label($company)) ?><?= $company['sql_customer_id'] ? ' · SQL #' . e($company['sql_customer_id']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="wide">Açıklama <textarea name="description"></textarea></label>
            <div class="actions wide"><button class="btn primary" type="submit">İşi ata</button></div>
        </form>
    </dialog>

    <?php if (can_view_all()): ?>
        <?php
            $listWhere = ' WHERE 1 = 1';
            $listParams = [];
            if (!empty($_GET['q'])) {
                $listWhere .= ' AND (t.title LIKE :q OR t.description LIKE :q OR assigner.full_name LIKE :q OR assignee.full_name LIKE :q)';
                $listParams[':q'] = '%' . $_GET['q'] . '%';
            }
            if (!empty($_GET['status'])) {
                $listWhere .= ' AND t.status = :status';
                $listParams[':status'] = $_GET['status'];
            }
            apply_date_filter($listWhere, $listParams, 't.due_date', $_GET);
            filter_bar('followups', [
                'status' => ['_label' => 'Durum', 'items' => array_combine(task_statuses(), task_statuses())],
            ]);
            $items = rows("SELECT t.*, c.name company_name, c.account_code company_account_code, assigner.full_name assigned_by_name, assignee.full_name assigned_to_name FROM tasks t LEFT JOIN companies c ON c.id = t.company_id LEFT JOIN users assigner ON assigner.id = t.assigned_by LEFT JOIN users assignee ON assignee.id = t.assigned_to {$listWhere} ORDER BY CASE t.status WHEN 'Açık' THEN 0 ELSE 1 END, COALESCE(t.due_date, '9999-12-31'), t.created_at DESC", $listParams);
        ?>
        <section class="panel">
            <div class="section-title"><h2>İş listesi</h2><span class="muted">Tüm atayan / atanan işler</span></div>
            <div class="table-wrap"><table>
                <thead><tr><th>İş</th><th>İlgili cari</th><th>SQL Customer</th><th>Atayan</th><th>Atanan</th><th>Termin</th><th>Durum</th><th>Aksiyon</th></tr></thead>
                <tbody>
                <?php foreach ($items as $row): ?>
                    <tr class="<?= $row['status'] === 'Açık' && $row['due_date'] && strtotime($row['due_date']) < strtotime(date('Y-m-d')) ? 'overdue' : '' ?>">
                        <td><strong><?= e($row['title']) ?></strong><?php if ($row['description']): ?><small><?= e($row['description']) ?></small><?php endif; ?></td>
                        <td><?= e($row['company_name'] ?? '-') ?></td>
                        <td><?= e($row['sql_customer_id'] ?? '-') ?></td>
                        <td><?= e($row['assigned_by_name'] ?? 'Silinmiş kullanıcı') ?></td>
                        <td><?= e($row['assigned_to_name'] ?? 'Silinmiş kullanıcı') ?></td>
                        <td><?= e($row['due_date']) ?></td>
                        <td><span class="badge <?= $row['status'] === 'Tamamlandı' ? 'success' : 'soft' ?>"><?= e($row['status']) ?></span></td>
                        <td>
                            <?php if ($row['status'] !== 'Tamamlandı'): ?>
                                <form method="post" action="<?= e(app_url('complete_task')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                    <input name="completion_note" placeholder="Tamamlama açıklaması" aria-label="Tamamlama açıklaması">
                                    <button class="btn small" type="submit">Tamamla</button>
                                </form>
                            <?php else: ?>
                                <span class="muted"><?= e($row['completed_at'] ?: '-') ?></span>
                                <?php if (!empty($row['completion_note'])): ?><small><?= e($row['completion_note']) ?></small><?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?><tr><td colspan="8" class="muted">İş kaydı yok.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </section>
    <?php endif; ?>
    <?php
    render_footer();
    exit;
}

if ($page === 'followups_inline_legacy') {
    render_header('Takip Listesi');
    $where = ' WHERE 1 = 1';
    $params = [];
    if (!can_view_all()) {
        $where .= ' AND (t.assigned_by = :uid OR t.assigned_to = :uid)';
        $params[':uid'] = current_user()['id'];
    }
    if (!empty($_GET['q'])) {
        $where .= ' AND (t.title LIKE :q OR t.description LIKE :q OR assigner.full_name LIKE :q OR assignee.full_name LIKE :q)';
        $params[':q'] = '%' . $_GET['q'] . '%';
    }
    if (!empty($_GET['status'])) {
        $where .= ' AND t.status = :status';
        $params[':status'] = $_GET['status'];
    }
    apply_date_filter($where, $params, 't.due_date', $_GET);
    filter_bar('followups', [
        'status' => ['_label' => 'Durum', 'items' => array_combine(task_statuses(), task_statuses())],
    ]);
    $items = rows("SELECT t.*, assigner.full_name assigned_by_name, assignee.full_name assigned_to_name FROM tasks t LEFT JOIN users assigner ON assigner.id = t.assigned_by LEFT JOIN users assignee ON assignee.id = t.assigned_to {$where} ORDER BY CASE t.status WHEN 'Açık' THEN 0 ELSE 1 END, COALESCE(t.due_date, '9999-12-31'), t.created_at DESC", $params);
    $users = active_users();
    ?>
    <form class="panel form-grid" method="post" action="<?= e(app_url('save_task')) ?>">
        <h2 class="wide">İş ata</h2>
        <?= csrf_field() ?>
        <label>İş başlığı <input name="title" required placeholder="Yapılacak işi yazın"></label>
        <label>Atanan personel
            <select name="assigned_to" required>
                <?php foreach ($users as $user): ?>
                    <option value="<?= e($user['id']) ?>"<?= selected(current_user()['id'], $user['id']) ?>><?= e($user['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Termin tarihi <input type="date" name="due_date"></label>
        <label class="wide">Açıklama <textarea name="description"></textarea></label>
        <div class="actions wide"><button class="btn primary" type="submit">İşi ata</button></div>
    </form>
    <section class="panel">
        <div class="section-title"><h2>İş listesi</h2><span class="muted"><?= can_view_all() ? 'Tüm atayan / atanan işler' : 'Atadığım ve bana atanan işler' ?></span></div>
        <div class="table-wrap"><table>
            <thead><tr><th>İş</th><th>Atayan</th><th>Atanan</th><th>Termin</th><th>Durum</th><th>Aksiyon</th></tr></thead>
            <tbody>
            <?php foreach ($items as $row): ?>
                <tr class="<?= $row['status'] === 'Açık' && $row['due_date'] && strtotime($row['due_date']) < strtotime(date('Y-m-d')) ? 'overdue' : '' ?>">
                    <td><strong><?= e($row['title']) ?></strong><?php if ($row['description']): ?><small><?= e($row['description']) ?></small><?php endif; ?></td>
                    <td><?= e($row['assigned_by_name'] ?? 'Silinmiş kullanıcı') ?></td>
                    <td><?= e($row['assigned_to_name'] ?? 'Silinmiş kullanıcı') ?></td>
                    <td><?= e($row['due_date']) ?></td>
                    <td><span class="badge <?= $row['status'] === 'Tamamlandı' ? 'success' : 'soft' ?>"><?= e($row['status']) ?></span></td>
                    <td>
                        <?php if ($row['status'] !== 'Tamamlandı' && (can_view_all() || (int) $row['assigned_to'] === (int) current_user()['id'])): ?>
                            <form method="post" action="<?= e(app_url('complete_task')) ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                <input name="completion_note" placeholder="Tamamlama açıklaması" aria-label="Tamamlama açıklaması">
                                <button class="btn small" type="submit">Tamamla</button>
                            </form>
                        <?php else: ?>
                            <span class="muted"><?= e($row['completed_at'] ?: '-') ?></span>
                            <?php if (!empty($row['completion_note'])): ?><small><?= e($row['completion_note']) ?></small><?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?><tr><td colspan="6" class="muted">İş kaydı yok.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
    <?php
    render_footer();
    exit;
}

if ($page === 'followups_legacy') {
    render_header('Takip Listesi');
    $where = ' WHERE c.next_followup_date IS NOT NULL';
    $params = [];
    if (!can_view_all()) {
        $where .= ' AND c.responsible_user_id = :followup_user_id';
        $params[':followup_user_id'] = current_user()['id'];
    }
    if (!empty($_GET['q'])) {
        $where .= ' AND (c.name LIKE :q OR c.contact_person LIKE :q OR c.phone LIKE :q)';
        $params[':q'] = '%' . $_GET['q'] . '%';
    }
    if (!empty($_GET['status'])) {
        $where .= ' AND c.status = :status';
        $params[':status'] = $_GET['status'];
    }
    if (!empty($_GET['account_type'])) {
        $where .= ' AND c.account_type = :account_type';
        $params[':account_type'] = normalize_company_account_type($_GET['account_type']);
    }
    apply_date_filter($where, $params, 'c.next_followup_date', $_GET);
    filter_bar('followups', [
        'account_type' => ['_label' => 'Cari türü', 'items' => array_combine(company_account_types(), company_account_types())],
        'status' => ['_label' => 'Durum', 'items' => array_combine(company_statuses(), company_statuses())],
    ]);
    $items = rows("SELECT c.*, u.full_name responsible_name FROM companies c LEFT JOIN users u ON u.id = c.responsible_user_id {$where} ORDER BY c.next_followup_date ASC", $params);
    ?>
    <section class="panel">
        <div class="table-wrap"><table>
            <thead><tr><th>Takip tarihi</th><th>Cari</th><th>Cari türü</th><th>Yetkili</th><th>Telefon</th><th>Durum</th><th>Sorumlu</th><th>Aksiyon</th></tr></thead>
            <tbody>
            <?php foreach ($items as $row): ?>
                <tr class="<?= strtotime($row['next_followup_date']) < strtotime(date('Y-m-d')) ? 'overdue' : '' ?>">
                    <td><?= e($row['next_followup_date']) ?></td>
                    <td><a href="<?= e(app_url('company_view', ['id' => $row['id']])) ?>"><?= e($row['name']) ?></a></td>
                    <td><span class="badge soft"><?= e($row['account_type'] ?? 'İş Ortağı') ?></span></td>
                    <td><?= e($row['contact_person']) ?></td>
                    <td><?php if ($row['phone']): ?><a href="tel:<?= e($row['phone']) ?>"><?= e($row['phone']) ?></a><?php endif; ?></td>
                    <td><?= e($row['status']) ?></td>
                    <td><?= e($row['responsible_name']) ?></td>
                    <td><a class="btn small" href="<?= e(app_url('company_view', ['id' => $row['id']])) ?>">Not ekle</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php
    render_footer();
    exit;
}

if ($page === 'opportunities') {
    render_header('Satış Fırsatları');
    $where = ' WHERE 1 = 1';
    $params = [];
    if (!can_view_all()) {
        $where .= ' AND o.salesperson_id = :uid';
        $params[':uid'] = current_user()['id'];
    }
    if (!empty($_GET['q'])) {
        $where .= ' AND (c.name LIKE :q OR o.product_service LIKE :q OR o.note LIKE :q)';
        $params[':q'] = '%' . $_GET['q'] . '%';
    }
    if (!empty($_GET['stage'])) {
        $where .= ' AND o.stage = :stage';
        $params[':stage'] = $_GET['stage'];
    }
    if (($_GET['stage_group'] ?? '') === 'open') {
        $where .= ' AND o.stage NOT IN ("Kazanıldı", "Kaybedildi")';
    }
    if (!empty($_GET['account_type'])) {
        $where .= ' AND c.account_type = :account_type';
        $params[':account_type'] = normalize_company_account_type($_GET['account_type']);
    }
    apply_date_filter($where, $params, 'o.expected_close_date', $_GET);
    filter_bar('opportunities', [
        'account_type' => ['_label' => 'Cari türü', 'items' => array_combine(company_account_types(), company_account_types())],
        'stage_group' => ['_label' => 'Fırsat durumu', 'items' => ['open' => 'Açık fırsatlar']],
        'stage' => ['_label' => 'Aşama', 'items' => array_combine(opportunity_stages(), opportunity_stages())],
    ]);
    $items = rows("SELECT o.*, c.name company_name, c.account_type company_account_type, c.sql_customer_id company_sql_customer_id, u.full_name salesperson_name FROM opportunities o JOIN companies c ON c.id = o.company_id LEFT JOIN users u ON u.id = o.salesperson_id {$where} ORDER BY o.updated_at DESC", $params);
    $itemsByStage = [];
    foreach ($items as $item) {
        $itemsByStage[$item['stage']][] = $item;
    }
    ?>
    <div class="toolbar">
        <a class="btn primary" href="<?= e(app_url('opportunity_form')) ?>">Yeni satış fırsatı</a>
        <button class="btn" type="button" data-export-table="#opportunities-table" data-filename="satis-firsatlari.csv">CSV indir</button>
    </div>
    <section class="panel">
        <div class="section-title"><h2>Kanban görünümü</h2><span class="muted">Aşamaya göre açık ve kapanan fırsatlar</span></div>
        <div class="kanban-board">
            <?php foreach (opportunity_stages() as $stage): ?>
                <section class="kanban-column">
                    <h3><?= e($stage) ?> <span class="badge soft"><?= e(count($itemsByStage[$stage] ?? [])) ?></span></h3>
                    <?php foreach (array_slice($itemsByStage[$stage] ?? [], 0, 6) as $card): ?>
                        <a class="kanban-card" href="<?= e(app_url('opportunity_form', ['id' => $card['id']])) ?>">
                            <strong><?= e($card['company_name']) ?></strong>
                            <span><?= e($card['product_service']) ?></span>
                            <small><?= e(money($card['estimated_amount'])) ?> · <?= e($card['expected_close_date'] ?: '-') ?></small>
                        </a>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="panel">
        <div class="table-wrap"><table class="opportunities-table" id="opportunities-table">
            <thead><tr><th>Cari</th><th>Cari türü</th><th>SQL Customer</th><th>Satışçı</th><th>Ürün / hizmet</th><th>Tutar</th><th>Aşama</th><th>Kapanış</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $row): ?>
                <tr>
                    <td><a href="<?= e(app_url('company_view', ['id' => $row['company_id']])) ?>"><?= e($row['company_name']) ?></a></td>
                    <td><span class="badge soft"><?= e($row['company_account_type'] ?? 'İş Ortağı') ?></span></td>
                    <td><?= e($row['sql_customer_id'] ?? $row['company_sql_customer_id'] ?? '-') ?></td>
                    <td><?= e($row['salesperson_name']) ?></td>
                    <td><?= e($row['product_service']) ?></td>
                    <td><?= e(money($row['estimated_amount'])) ?></td>
                    <td><?= e($row['stage']) ?></td>
                    <td><?= e($row['expected_close_date']) ?></td>
                    <td><a class="btn small" href="<?= e(app_url('opportunity_form', ['id' => $row['id']])) ?>">Düzenle</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php
    render_footer();
    exit;
}

if ($page === 'opportunity_form') {
    $id = (int) ($_GET['id'] ?? 0);
    $opp = null;
    if ($id > 0) {
        $opp = rows('SELECT * FROM opportunities WHERE id = :id', [':id' => $id])[0] ?? null;
        if ($opp) {
            if (!can_view_all() && (int) $opp['salesperson_id'] !== (int) current_user()['id']) {
                http_response_code(403);
                exit('Bu satış fırsatını düzenleme yetkiniz yok.');
            }
            require_company_access((int) $opp['company_id']);
        }
    }
    $where = ' WHERE 1 = 1';
    $params = [];
    [$scopeSql, $scopeParams] = owned_company_condition('c');
    $where .= $scopeSql;
    $params += $scopeParams;
    $companies = rows("SELECT c.id, c.name, c.account_code, c.account_type FROM companies c {$where} ORDER BY c.name", $params);
    $selectedCompanyId = (int) ($opp['company_id'] ?? ($_GET['company_id'] ?? 0));
    $selectedCompany = null;
    if ($selectedCompanyId > 0) {
        foreach ($companies as $company) {
            if ((int) $company['id'] === $selectedCompanyId) {
                $selectedCompany = $company;
                break;
            }
        }
    }
    render_header($opp ? 'Fırsat Düzenle' : 'Yeni Satış Fırsatı');
    ?>
    <form class="panel form-grid" method="post" action="<?= e(app_url('save_opportunity')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($opp['id'] ?? 0) ?>">
        <label>İlgili cari
            <input name="company_lookup" list="company_options" value="<?= e($selectedCompany ? company_lookup_label($selectedCompany) : '') ?>" placeholder="Cari kodu veya firma adı yazın..." required autocomplete="off">
            <datalist id="company_options">
                <?php foreach ($companies as $company): ?>
                    <option value="<?= e(company_lookup_label($company)) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </label>
        <label>Satışçı
            <select name="salesperson_id"<?= can_view_all() ? '' : ' disabled' ?>>
                <?php foreach (active_users() as $user): ?>
                    <option value="<?= e($user['id']) ?>"<?= selected($opp['salesperson_id'] ?? current_user()['id'], $user['id']) ?>><?= e($user['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Ürün / hizmet <input name="product_service" value="<?= e($opp['product_service'] ?? '') ?>" required></label>
        <label>Tahmini tutar <input name="estimated_amount" inputmode="decimal" value="<?= e($opp['estimated_amount'] ?? '') ?>"></label>
        <label>Aşama
            <select name="stage">
                <?php foreach (opportunity_stages() as $stage): ?>
                    <option value="<?= e($stage) ?>"<?= selected($opp['stage'] ?? 'Yeni fırsat', $stage) ?>><?= e($stage) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Beklenen kapanış tarihi <input type="date" name="expected_close_date" value="<?= e($opp['expected_close_date'] ?? '') ?>"></label>
        <label class="wide">Not <textarea name="note"><?= e($opp['note'] ?? '') ?></textarea></label>
        <div class="actions wide"><button class="btn primary" type="submit">Kaydet</button></div>
    </form>
    <?php
    render_footer();
    exit;
}

if ($page === 'reports') {
    render_header('Raporlar');
    $today = date('Y-m-d');
    $selectedAccountType = !empty($_GET['account_type']) ? normalize_company_account_type($_GET['account_type']) : '';
    $interactionWhere = ' WHERE 1 = 1';
    $interactionParams = [];
    if (!can_view_all()) {
        $interactionWhere .= ' AND i.user_id = :uid';
        $interactionParams[':uid'] = current_user()['id'];
    }
    if ($selectedAccountType !== '') {
        $interactionWhere .= ' AND EXISTS (SELECT 1 FROM companies c WHERE c.id = i.company_id AND c.account_type = :interaction_account_type)';
        $interactionParams[':interaction_account_type'] = $selectedAccountType;
    }
    apply_date_filter($interactionWhere, $interactionParams, 'i.interaction_date', $_GET);
    filter_bar('reports', [
        'account_type' => ['_label' => 'Cari türü', 'items' => array_combine(company_account_types(), company_account_types())],
    ]);
    [$reportFrom, $reportTo] = date_filter_range($_GET);
    $periodLabel = 'Tüm zamanlar';
    if ($reportFrom !== '' || $reportTo !== '') {
        $periodLabel = trim(($reportFrom ?: 'Başlangıç') . ' - ' . ($reportTo ?: 'Bugün'));
    }
    $staffJoin = 'LEFT JOIN interactions i ON i.user_id = u.id';
    $staffWhere = ' WHERE u.active = 1';
    $staffParams = [];
    if ($reportFrom !== '') {
        $staffJoin .= ' AND date(i.interaction_date) >= :staff_date_from';
        $staffParams[':staff_date_from'] = $reportFrom;
    }
    if ($reportTo !== '') {
        $staffJoin .= ' AND date(i.interaction_date) <= :staff_date_to';
        $staffParams[':staff_date_to'] = $reportTo;
    }
    if (!can_view_all()) {
        $staffWhere .= ' AND u.id = :staff_uid';
        $staffParams[':staff_uid'] = current_user()['id'];
    }
    $staff = rows("SELECT u.full_name, COUNT(i.id) total FROM users u {$staffJoin} {$staffWhere} GROUP BY u.id ORDER BY total DESC, u.full_name", $staffParams);
    $daily = rows("SELECT i.interaction_date, c.name company_name, c.account_type company_account_type, u.full_name user_name, i.type, i.result, i.note FROM interactions i JOIN companies c ON c.id = i.company_id LEFT JOIN users u ON u.id = i.user_id {$interactionWhere} ORDER BY i.interaction_date DESC LIMIT 100", $interactionParams);
    $companyScope = ' WHERE 1 = 1';
    $companyParams = [];
    if (!can_view_all()) {
        $companyScope .= ' AND (c.responsible_user_id = :company_report_user_id OR c.created_by = :company_report_user_id)';
        $companyParams[':company_report_user_id'] = current_user()['id'];
    }
    if ($selectedAccountType !== '') {
        $companyScope .= ' AND c.account_type = :company_account_type';
        $companyParams[':company_account_type'] = $selectedAccountType;
    }
    $statusReport = rows("SELECT c.status, COUNT(*) total FROM companies c {$companyScope} GROUP BY c.status ORDER BY total DESC", $companyParams);
    $typeReport = rows("SELECT c.account_type, COUNT(*) total FROM companies c {$companyScope} GROUP BY c.account_type ORDER BY total DESC", $companyParams);
    $oppWhere = ' WHERE 1 = 1';
    $oppParams = [];
    if (!can_view_all()) {
        $oppWhere .= ' AND o.salesperson_id = :uid';
        $oppParams[':uid'] = current_user()['id'];
    }
    if ($selectedAccountType !== '') {
        $oppWhere .= ' AND EXISTS (SELECT 1 FROM companies c WHERE c.id = o.company_id AND c.account_type = :opp_account_type)';
        $oppParams[':opp_account_type'] = $selectedAccountType;
    }
    $oppReport = rows("SELECT o.stage, COUNT(*) total, COALESCE(SUM(o.estimated_amount), 0) amount FROM opportunities o {$oppWhere} GROUP BY o.stage ORDER BY total DESC", $oppParams);
    $overdue = rows("SELECT c.id, c.name, c.account_type, c.next_followup_date, u.full_name responsible_name FROM companies c LEFT JOIN users u ON u.id = c.responsible_user_id {$companyScope} AND c.next_followup_date IS NOT NULL AND date(c.next_followup_date) < :today ORDER BY c.next_followup_date LIMIT 12", $companyParams + [':today' => $today]);
    $interactionTrend = rows("SELECT date(i.interaction_date) day, COUNT(*) total FROM interactions i {$interactionWhere} GROUP BY date(i.interaction_date) ORDER BY day DESC LIMIT 14", $interactionParams);
    $interactionTrend = array_reverse($interactionTrend);
    $resultReport = rows("SELECT i.result, COUNT(*) total FROM interactions i {$interactionWhere} GROUP BY i.result ORDER BY total DESC", $interactionParams);
    $totalInteractions = (int) scalar("SELECT COUNT(*) FROM interactions i {$interactionWhere}", $interactionParams);
    $totalCompanies = (int) scalar("SELECT COUNT(*) FROM companies c {$companyScope}", $companyParams);
    $totalOverdue = (int) scalar("SELECT COUNT(*) FROM companies c {$companyScope} AND c.next_followup_date IS NOT NULL AND date(c.next_followup_date) < :today", $companyParams + [':today' => $today]);
    $openOppCount = (int) scalar("SELECT COUNT(*) FROM opportunities o {$oppWhere} AND o.stage NOT IN ('Kazanıldı', 'Kaybedildi')", $oppParams);
    $wonAmount = (float) scalar("SELECT COALESCE(SUM(o.estimated_amount), 0) FROM opportunities o {$oppWhere} AND o.stage = 'Kazanıldı'", $oppParams);
    $lostAmount = (float) scalar("SELECT COALESCE(SUM(o.estimated_amount), 0) FROM opportunities o {$oppWhere} AND o.stage = 'Kaybedildi'", $oppParams);
    $wonCount = (int) scalar("SELECT COUNT(*) FROM opportunities o {$oppWhere} AND o.stage = 'Kazanıldı'", $oppParams);
    $closedCount = (int) scalar("SELECT COUNT(*) FROM opportunities o {$oppWhere} AND o.stage IN ('Kazanıldı', 'Kaybedildi')", $oppParams);
    $winRate = $closedCount > 0 ? round(($wonCount / $closedCount) * 100) . '%' : '0%';
    ?>
    <section class="report-hero panel">
        <div>
            <span class="eyebrow">Rapor dönemi</span>
            <h2><?= e($periodLabel) ?></h2>
            <p>Aktivite, bayi durumu, takip riski ve satış fırsatlarını aynı ekranda okuyun.</p>
        </div>
        <div class="report-actions">
            <a class="btn" href="<?= e(app_url('reports', ['date_filter' => 'today'])) ?>">Bugün</a>
            <a class="btn" href="<?= e(app_url('reports', ['date_filter' => 'week'])) ?>">Bu hafta</a>
            <a class="btn" href="<?= e(app_url('reports', ['date_filter' => 'month'])) ?>">Bu ay</a>
            <button class="btn" type="button" onclick="window.print()">PDF yazdır</button>
            <button class="btn" type="button" data-export-table="#daily-report-table" data-filename="bilnex-rapor.csv">CSV indir</button>
        </div>
    </section>
    <section class="stats-grid">
        <?php
            dashboard_card('Toplam görüşme', $totalInteractions, 'Seçili dönem', '');
            dashboard_card('Cari', $totalCompanies, can_view_all() ? 'Tüm erişilebilir kayıtlar' : 'Sorumlu olduğum kayıtlar', '');
            dashboard_card('Geciken takip', $totalOverdue, 'Öncelikli aksiyon', $totalOverdue > 0 ? 'danger-stat' : 'success-stat');
            dashboard_card('Açık fırsat', $openOppCount, 'Devam eden satışlar', '');
            dashboard_card('Kazanılan satış', money($wonAmount), 'Kapanış oranı: ' . $winRate, 'success-stat');
            dashboard_card('Kaybedilen satış', money($lostAmount), 'Öğrenme alanı', 'danger-stat');
        ?>
    </section>
    <section class="dashboard-grid">
        <article class="panel chart-panel">
            <div class="section-title"><h2>Personel performansı</h2><a class="btn small" href="<?= e(app_url('reports', ['date_filter' => 'month'])) ?>">Bu ay</a></div>
            <?php render_bar_list($staff, 'full_name', 'total'); ?>
        </article>
        <article class="panel chart-panel">
            <h2>Görüşme sonuçları</h2>
            <?php render_bar_list($resultReport, 'result', 'total'); ?>
        </article>
        <article class="panel chart-panel">
            <h2>Cari takip raporu</h2>
            <?php render_bar_list($statusReport, 'status', 'total'); ?>
        </article>
        <article class="panel chart-panel">
            <h2>Cari türü dağılımı</h2>
            <div class="analytics-split">
                <?php render_donut_chart($typeReport, 'account_type', 'total'); ?>
                <?php render_linked_bar_list($typeReport, 'account_type', 'total', static fn($row) => app_url('companies', ['account_type' => $row['account_type']])); ?>
            </div>
        </article>
        <article class="panel chart-panel">
            <h2>Günlük görüşme trendi</h2>
            <?php render_trend_chart($interactionTrend, 'day', 'total'); ?>
        </article>
        <article class="panel chart-panel wide-panel">
            <div class="section-title"><h2>Satış fırsatı raporu</h2><a class="btn small" href="<?= e(app_url('opportunities')) ?>">Fırsatları aç</a></div>
            <?php render_amount_bar_list($oppReport, 'stage', 'total', 'amount'); ?>
        </article>
    </section>
    <section class="grid-two">
        <article class="panel">
            <div class="section-title"><h2>Geciken takip aksiyonları</h2><a class="btn small" href="<?= e(app_url('followups', ['date_filter' => 'custom', 'date_to' => $today])) ?>">Takip listesi</a></div>
            <div class="table-wrap"><table><thead><tr><th>Cari</th><th>Cari türü</th><th>Takip</th><th>Sorumlu</th></tr></thead><tbody>
                <?php foreach ($overdue as $r): ?><tr class="overdue"><td><a href="<?= e(app_url('company_view', ['id' => $r['id']])) ?>"><?= e($r['name']) ?></a></td><td><?= e($r['account_type'] ?? 'İş Ortağı') ?></td><td><?= e($r['next_followup_date']) ?></td><td><?= e($r['responsible_name']) ?></td></tr><?php endforeach; ?>
                <?php if (!$overdue): ?><tr><td colspan="4" class="muted">Geciken takip yok.</td></tr><?php endif; ?>
            </tbody></table></div>
        </article>
        <article class="panel">
            <h2>Günlük görüşme listesi</h2>
            <div class="table-wrap"><table id="daily-report-table"><thead><tr><th>Tarih</th><th>Cari</th><th>Cari türü</th><th>Personel</th><th>Tür</th><th>Sonuç</th><th>Not</th></tr></thead><tbody>
                <?php foreach ($daily as $r): ?><tr><td><?= e($r['interaction_date']) ?></td><td><?= e($r['company_name']) ?></td><td><?= e($r['company_account_type'] ?? 'İş Ortağı') ?></td><td><?= e($r['user_name']) ?></td><td><?= e($r['type']) ?></td><td><?= e($r['result']) ?></td><td><?= e($r['note']) ?></td></tr><?php endforeach; ?>
                <?php if (!$daily): ?><tr><td colspan="7" class="muted">Seçili dönemde görüşme yok.</td></tr><?php endif; ?>
            </tbody></table></div>
        </article>
    </section>
    <?php
    render_footer();
    exit;
}

http_response_code(404);
render_header('Sayfa bulunamadı');
echo '<section class="panel"><p>Aradığınız sayfa bulunamadı.</p></section>';
render_footer();
