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

function compact_money($amount): string
{
    $amount = (float) $amount;
    $absAmount = abs($amount);

    if ($absAmount >= 1000000) {
        return number_format($amount / 1000000, 1, ',', '.') . ' Mn TL';
    }

    if ($absAmount >= 1000) {
        return number_format($amount / 1000, 0, ',', '.') . ' Bin TL';
    }

    return money($amount);
}

function pct($value, $max): int
{
    $max = (float) $max;
    if ($max <= 0) {
        return 0;
    }
    return max(2, min(100, (int) round(((float) $value / $max) * 100)));
}

function compact_metric_rows(array $rows, string $labelKey, string $valueKey, int $visibleRows = 6, string $otherLabel = 'Diğer'): array
{
    if (count($rows) <= $visibleRows) {
        return $rows;
    }

    $visibleCount = max(1, $visibleRows - 1);
    $topRows = array_slice($rows, 0, $visibleCount);
    $otherTotal = 0;

    foreach (array_slice($rows, $visibleCount) as $row) {
        $otherTotal += (float) ($row[$valueKey] ?? 0);
    }

    if ($otherTotal > 0) {
        $otherRow = $rows[0] ?? [];
        $otherRow[$labelKey] = $otherLabel;
        $otherRow[$valueKey] = $otherTotal;
        $otherRow['_is_other'] = true;
        $topRows[] = $otherRow;
    }

    return $topRows;
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

function render_tax_office_picker_field(string $taxOffice = '', string $taxOfficeCode = ''): void
{
    $label = $taxOffice !== '' ? $taxOffice . ($taxOfficeCode !== '' ? ' (' . $taxOfficeCode . ')' : '') : 'Vergi dairesi seçin';
    ?>
    <div class="form-field">
        <label>Vergi dairesi</label>
        <input type="hidden" name="tax_office_code" value="<?= e($taxOfficeCode) ?>" data-tax-office-code>
        <div class="field-action-row tax-office-picker" data-tax-office-picker>
            <input class="readonly-input" name="tax_office" value="<?= e($taxOffice) ?>" data-tax-office-name placeholder="<?= e($label) ?>" required readonly aria-readonly="true">
            <button class="btn small" type="button" data-open-tax-office-picker>Ara / seç</button>
        </div>
    </div>
    <?php
}

function render_tax_office_dialog(): void
{
    ?>
    <dialog class="modal sql-customer-dialog" id="tax-office-dialog" data-search-url="<?= e(app_url('tax_office_search')) ?>">
        <div class="modal-head">
            <strong>Vergi dairesi seç</strong>
            <button class="btn small" type="button" data-close-dialog>Kapat</button>
        </div>
        <div class="modal-body sql-customer-search">
            <div class="sql-search-row">
                <input type="search" data-tax-office-query placeholder="İl, ilçe, kod veya vergi dairesi ara..." autocomplete="off">
                <button class="btn primary" type="button" data-tax-office-search>Ara</button>
            </div>
            <div class="sql-customer-results" data-tax-office-results>Liste GİB verisinden yerel olarak okunuyor. Aramak için yazın.</div>
        </div>
    </dialog>
    <?php
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
    echo '<div class="trend-line' . (!$rows ? ' empty-chart' : '') . '" style="--points:' . max(1, count($rows)) . '">';
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

function render_print_kpi(string $label, $value, string $hint = '', string $tone = ''): void
{
    echo '<article class="print-kpi ' . e($tone) . '"><span>' . e($label) . '</span><strong>' . e($value) . '</strong>';
    if ($hint !== '') {
        echo '<small>' . e($hint) . '</small>';
    }
    echo '</article>';
}

function render_print_bar_chart(array $rows, string $labelKey, string $valueKey, int $limit = 5, string $empty = 'Veri yok'): void
{
    $rows = compact_metric_rows($rows, $labelKey, $valueKey, $limit);
    $max = 0;
    foreach ($rows as $row) {
        $max = max($max, (float) ($row[$valueKey] ?? 0));
    }

    echo '<div class="print-bars">';
    foreach ($rows as $row) {
        $value = (float) ($row[$valueKey] ?? 0);
        echo '<div class="print-bar-row">';
        echo '<div class="print-bar-label"><span>' . e($row[$labelKey] ?? '-') . '</span><strong>' . e((int) $value) . '</strong></div>';
        echo '<div class="print-bar-track"><span style="width:' . pct($value, $max) . '%"></span></div>';
        echo '</div>';
    }
    if (!$rows) {
        echo '<p class="print-empty">' . e($empty) . '</p>';
    }
    echo '</div>';
}

function render_print_donut_report(array $rows, string $labelKey, string $valueKey, int $limit = 6): void
{
    $rows = compact_metric_rows($rows, $labelKey, $valueKey, $limit);
    $total = array_sum(array_map(static fn($row) => (float) ($row[$valueKey] ?? 0), $rows));
    $colors = ['#236ee9', '#19bd86', '#ffb23f', '#ff5d6d', '#7256e8', '#35c7de', '#90a4bc'];
    $segments = [];
    $start = 0.0;

    if ($total > 0) {
        foreach ($rows as $index => $row) {
            $value = (float) ($row[$valueKey] ?? 0);
            $end = $start + (($value / $total) * 100);
            $color = $colors[$index % count($colors)];
            $segments[] = $color . ' ' . round($start, 2) . '% ' . round($end, 2) . '%';
            $start = $end;
        }
    }

    $style = $segments ? 'background: conic-gradient(' . implode(', ', $segments) . ')' : '';
    echo '<div class="print-donut-wrap">';
    echo '<div class="print-donut" style="' . e($style) . '" data-total="' . e((int) $total) . '"></div>';
    echo '<div class="print-donut-legend">';
    foreach ($rows as $index => $row) {
        $value = (float) ($row[$valueKey] ?? 0);
        $share = $total > 0 ? round(($value / $total) * 100) : 0;
        echo '<div><i style="background:' . e($colors[$index % count($colors)]) . '"></i><span>' . e($row[$labelKey] ?? '-') . '</span><strong>' . e((int) $value) . '</strong><small>%' . e($share) . '</small></div>';
    }
    if (!$rows) {
        echo '<p class="print-empty">Veri yok</p>';
    }
    echo '</div></div>';
}

function render_print_trend_chart(array $rows, string $labelKey, string $valueKey): void
{
    $max = 0;
    foreach ($rows as $row) {
        $max = max($max, (float) ($row[$valueKey] ?? 0));
    }

    echo '<div class="print-trend">';
    foreach ($rows as $row) {
        $rawLabel = (string) ($row[$labelKey] ?? '');
        $timestamp = strtotime($rawLabel);
        $label = $timestamp ? date('d.m', $timestamp) : substr($rawLabel, -5);
        $value = (float) ($row[$valueKey] ?? 0);
        echo '<div class="print-trend-point"><strong>' . e((int) $value) . '</strong><i style="height:' . pct($value, $max) . '%"></i><span>' . e($label) . '</span></div>';
    }
    if (!$rows) {
        echo '<p class="print-empty">Veri yok</p>';
    }
    echo '</div>';
}

function render_print_pipeline(array $rows): void
{
    $max = 0;
    foreach ($rows as $row) {
        $max = max($max, (float) ($row['amount'] ?? 0), (float) ($row['total'] ?? 0));
    }

    echo '<div class="print-funnel">';
    foreach ($rows as $row) {
        $amount = (float) ($row['amount'] ?? 0);
        $valueForWidth = $amount > 0 ? $amount : (float) ($row['total'] ?? 0);
        echo '<div class="print-funnel-row" style="--w:' . pct($valueForWidth, $max) . '">';
        echo '<span>' . e($row['stage'] ?? '-') . '</span>';
        echo '<strong>' . e((int) ($row['total'] ?? 0)) . ' adet</strong>';
        echo '<small>' . e(compact_money($amount)) . '</small>';
        echo '</div>';
    }
    if (!$rows) {
        echo '<p class="print-empty">Satış fırsatı yok.</p>';
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
        <link rel="icon" href="<?= e(rtrim(app_config('base_url'), '/')) ?>/assets/brand/bilnex-logo.svg">
        <link rel="stylesheet" href="<?= e(rtrim(app_config('base_url'), '/')) ?>/assets/app.css?v=<?= e((string) @filemtime(__DIR__ . '/assets/app.css')) ?>">
    </head>
    <body class="login-page">
        <main class="login-shell">
            <section class="login-hero" aria-label="Bilnex CRM">
                <div class="login-brand-lockup">
                    <img src="<?= e(rtrim(app_config('base_url'), '/')) ?>/assets/brand/bilnex-logo.svg" alt="Bilnex Yazılım Çözümleri">
                    <span>Bilnex Yazılım Çözümleri</span>
                </div>
                <div class="login-hero-copy">
                    <span>İş Ortakları CRM</span>
                    <h1>Bayi kanalını tek merkezden yönetin</h1>
                    <p>Cariler, görüşmeler, takipler ve satış fırsatları için kurumsal kontrol paneli.</p>
                </div>
                <div class="login-metrics" aria-hidden="true">
                    <div><strong>SQL</strong><span>Canlı cari kaynağı</span></div>
                    <div><strong>CRM</strong><span>Takip ve fırsat akışı</span></div>
                    <div><strong>Rapor</strong><span>Anlık kanal görünümü</span></div>
                </div>
            </section>
            <section class="login-panel">
                <div class="login-panel-head">
                    <img class="login-logo" src="<?= e(rtrim(app_config('base_url'), '/')) ?>/assets/brand/bilnex-logo.svg" alt="Bilnex Yazılım Çözümleri">
                    <div>
                        <span>Güvenli giriş</span>
                        <h2>Hesabınıza giriş yapın</h2>
                    </div>
                </div>
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
                <p class="login-footnote">Bilnex İş Ortakları CRM</p>
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
        'interactions' => '<path d="M4 5a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v7a3 3 0 0 1-3 3h-4.7L7 20v-5a3 3 0 0 1-3-3V5Zm4 2v2h8V7H8Zm0 4h5V9H8v2Z"/>',
        'followups' => '<path d="M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm3 4H6v2h2V8Zm2 0v2h8V8h-8Zm-2 5H6v2h2v-2Zm2 0v2h8v-2h-8Z"/>',
        'opportunities' => '<path d="M4 4h16v4H4V4Zm0 6h10v4H4v-4Zm0 6h16v4H4v-4Zm12-6h4v4h-4v-4Z"/>',
        'reports' => '<path d="M4 19h16v2H4v-2Zm2-2V9h3v8H6Zm5 0V3h3v14h-3Zm5 0v-6h3v6h-3Z"/>',
    ];
    $path = $paths[$target] ?? '<path d="M4 4h16v16H4z"/>';
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $path . '</svg>';
}

function user_initials(array $user): string
{
    $name = trim((string) ($user['full_name'] ?? $user['username'] ?? ''));
    $parts = preg_split('/\s+/', $name) ?: [];
    $letters = '';
    foreach (array_slice(array_filter($parts), 0, 2) as $part) {
        $letters .= function_exists('mb_substr')
            ? mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8')
            : strtoupper(substr($part, 0, 1));
    }

    return $letters !== '' ? $letters : 'U';
}

function user_avatar_url(array $user): ?string
{
    $path = trim(str_replace('\\', '/', (string) ($user['avatar_path'] ?? '')));
    if ($path === '' || !preg_match('#^data/profile-photos/[A-Za-z0-9._-]+$#', $path)) {
        return null;
    }

    $fullPath = __DIR__ . '/' . $path;
    if (!is_file($fullPath)) {
        return null;
    }

    return rtrim(app_config('base_url'), '/') . '/' . $path . '?v=' . filemtime($fullPath);
}

function render_user_avatar(array $user, string $class = 'profile-avatar'): string
{
    $url = user_avatar_url($user);
    $classes = preg_replace('/[^A-Za-z0-9_ -]/', '', $class) ?: 'profile-avatar';
    if ($url !== null) {
        return '<span class="' . e($classes) . '"><img src="' . e($url) . '" alt="' . e($user['full_name'] ?? 'Profil') . '"></span>';
    }

    return '<span class="' . e($classes) . '" aria-hidden="true">' . e(user_initials($user)) . '</span>';
}

function save_standard_profile_avatar(string $sourcePath, string $destinationPath): void
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        throw new RuntimeException('Sunucuda görsel küçültme desteği eksik. GD eklentisi gerekli.');
    }

    $bytes = file_get_contents($sourcePath);
    if ($bytes === false) {
        throw new RuntimeException('Profil fotoğrafı okunamadı.');
    }

    $source = @imagecreatefromstring($bytes);
    if (!$source) {
        throw new RuntimeException('Profil fotoğrafı işlenemedi.');
    }

    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($sourcePath);
        $orientation = (int) ($exif['Orientation'] ?? 1);
        if (in_array($orientation, [3, 6, 8], true)) {
            $angle = $orientation === 3 ? 180 : ($orientation === 6 ? 270 : 90);
            $rotated = imagerotate($source, $angle, 0);
            if ($rotated) {
                imagedestroy($source);
                $source = $rotated;
            }
        }
    }

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    if ($sourceWidth <= 0 || $sourceHeight <= 0) {
        imagedestroy($source);
        throw new RuntimeException('Profil fotoğrafı ölçüleri okunamadı.');
    }

    $size = 256;
    $cropSize = min($sourceWidth, $sourceHeight);
    $sourceX = (int) floor(($sourceWidth - $cropSize) / 2);
    $sourceY = (int) floor(($sourceHeight - $cropSize) / 2);

    $avatar = imagecreatetruecolor($size, $size);
    if (!$avatar) {
        imagedestroy($source);
        throw new RuntimeException('Profil fotoğrafı hazırlanamadı.');
    }
    $background = imagecolorallocate($avatar, 255, 255, 255);
    imagefilledrectangle($avatar, 0, 0, $size, $size, $background);
    imagecopyresampled($avatar, $source, 0, 0, $sourceX, $sourceY, $size, $size, $cropSize, $cropSize);

    $saved = imagejpeg($avatar, $destinationPath, 82);
    imagedestroy($avatar);
    imagedestroy($source);

    if (!$saved) {
        throw new RuntimeException('Profil fotoğrafı küçültülmüş olarak kaydedilemedi.');
    }
}

function render_header(string $title): void
{
    $user = current_user();
    $flash = flash();
    $currentPage = preg_replace('/[^a-z0-9_-]/i', '', $_GET['page'] ?? 'dashboard') ?: 'dashboard';
    [$openTaskScopeSql, $openTaskScopeParams] = task_visibility_condition('t');
    $openTaskCount = (int) scalar("SELECT COUNT(*) FROM tasks t WHERE t.status = 'Açık'{$openTaskScopeSql}", $openTaskScopeParams);
    $nav = [
        ['dashboard', 'Dashboard'],
        ['companies', 'Cariler'],
        ['interactions', 'Görüşme Ekle'],
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
        <link rel="icon" href="<?= e(rtrim(app_config('base_url'), '/')) ?>/assets/brand/bilnex-logo.svg">
        <link rel="stylesheet" href="<?= e(rtrim(app_config('base_url'), '/')) ?>/assets/app.css?v=<?= e((string) @filemtime(__DIR__ . '/assets/app.css')) ?>">
    </head>
    <body class="page-<?= e($currentPage) ?>">
        <div class="app-shell">
            <aside class="sidebar">
                <a class="brand" href="<?= e(app_url()) ?>">
                    <span class="brand-logo-wrap"><img src="<?= e(rtrim(app_config('base_url'), '/')) ?>/assets/brand/bilnex-logo.svg" alt="Bilnex"></span>
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
                <details class="user-box profile-menu">
                    <summary>
                        <?= render_user_avatar($user) ?>
                        <span class="profile-summary-text">
                            <strong><?= e($user['full_name']) ?></strong>
                            <small><?= e(role_label($user['role'])) ?></small>
                        </span>
                        <span class="profile-chevron" aria-hidden="true">v</span>
                    </summary>
                    <div class="profile-menu-panel">
                        <a href="<?= e(app_url('profile')) ?>">Profil ayarları</a>
                        <a href="<?= e(app_url('profile')) ?>#password-section">Şifre değiştir</a>
                        <form method="post" action="<?= e(app_url('logout')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit">Çıkış</button>
                        </form>
                    </div>
                </details>
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
        <script src="<?= e(rtrim(app_config('base_url'), '/')) ?>/assets/app.js?v=<?= e((string) @filemtime(__DIR__ . '/assets/app.js')) ?>" defer></script>
    </body>
    </html>
    <?php
}

function filter_bar(string $target, array $extra = [], bool $includeDate = true, array $hidden = []): void
{
    $dateFilter = $_GET['date_filter'] ?? '';
    ?>
    <form class="filters<?= $includeDate ? '' : ' compact-filters' ?>" method="get">
        <input type="hidden" name="page" value="<?= e($target) ?>">
        <?php foreach ($hidden as $name => $value): ?>
            <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
        <?php endforeach; ?>
        <input name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Ara...">
        <?php foreach ($extra as $name => $options): ?>
            <select name="<?= e($name) ?>">
                <option value=""><?= e($options['_label']) ?></option>
                <?php foreach ($options['items'] as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= selected($_GET[$name] ?? '', $value) ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endforeach; ?>
        <?php if ($includeDate): ?>
            <select name="date_filter">
                <option value="">Tüm tarihler</option>
                <option value="today"<?= selected($dateFilter, 'today') ?>>Bugün</option>
                <option value="week"<?= selected($dateFilter, 'week') ?>>Bu hafta</option>
                <option value="month"<?= selected($dateFilter, 'month') ?>>Bu ay</option>
                <option value="custom"<?= selected($dateFilter, 'custom') ?>>Özel aralık</option>
            </select>
            <input type="date" name="date_from" value="<?= e($_GET['date_from'] ?? '') ?>">
            <input type="date" name="date_to" value="<?= e($_GET['date_to'] ?? '') ?>">
        <?php endif; ?>
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
    $name = trim((string) ($company['name'] ?? ''));
    $type = trim((string) ($company['account_type'] ?? ''));
    return $name . ($type !== '' ? ' - ' . $type : '');
}

function company_display_label(array $company): string
{
    $name = trim((string) ($company['name'] ?? ''));
    $type = trim((string) ($company['account_type'] ?? ''));
    return $name . ($type !== '' ? ' - ' . $type : '');
}

function company_id_from_lookup(string $value): int
{
    $value = trim($value);
    if (preg_match('/^(\d+)\s*\|/', $value, $match)) {
        return (int) $match[1];
    }
    return 0;
}

function date_filter_presets(string $target, array $extra = []): void
{
    $presets = [
        '' => 'Tümü',
        'today' => 'Bugün',
        'week' => 'Bu hafta',
        'month' => 'Bu ay',
    ];
    $active = (string) ($_GET['date_filter'] ?? '');
    echo '<div class="period-tabs">';
    foreach ($presets as $value => $label) {
        $params = $extra;
        if ($value !== '') {
            $params['date_filter'] = $value;
        }
        $class = $active === $value ? 'active' : '';
        echo '<a class="' . e($class) . '" href="' . e(app_url($target, $params)) . '">' . e($label) . '</a>';
    }
    echo '</div>';
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

if ($page === 'sql_customer_search') {
    header('Content-Type: application/json; charset=utf-8');
    $query = trim((string) ($_GET['q'] ?? ''));
    if (company_source() !== 'sqlserver') {
        echo json_encode(['items' => [], 'error' => 'Cari kaynağı SQL Server değil.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (strlen($query) < 2) {
        echo json_encode(['items' => [], 'error' => null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $items = array_map(static function (array $row): array {
        return [
            'id' => (int) ($row['sql_customer_id'] ?? $row['id']),
            'label' => company_display_label($row),
            'name' => (string) ($row['name'] ?? ''),
            'code' => (string) ($row['account_code'] ?? ''),
            'type' => (string) ($row['account_type'] ?? ''),
            'meta' => trim((string) (($row['contact_person'] ?? '') . ' ' . ($row['city'] ?? '') . ' ' . ($row['district'] ?? ''))),
        ];
    }, sql_customer_rows_for_company_list(20, null, 0, $query));
    $error = bilnex_customer_reader()->lastError();
    echo json_encode(['items' => $items, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($page === 'tax_office_search') {
    header('Content-Type: application/json; charset=utf-8');
    $query = trim((string) ($_GET['q'] ?? ''));
    $items = array_map(static function (array $item): array {
        return [
            'city' => (string) ($item['city'] ?? ''),
            'district' => (string) ($item['district'] ?? ''),
            'code' => (string) ($item['code'] ?? ''),
            'name' => (string) ($item['name'] ?? ''),
            'label' => (string) ($item['label'] ?? tax_office_label($item)),
        ];
    }, tax_office_search($query, 50));

    echo json_encode([
        'items' => $items,
        'source' => tax_office_payload()['source'] ?? 'GİB vergi daireleri listesi',
        'document_date' => tax_office_payload()['document_date'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($page === 'company_lookup_search') {
    header('Content-Type: application/json; charset=utf-8');
    $query = trim((string) ($_GET['q'] ?? ''));
    $queryLength = function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query);
    if ($queryLength < 3) {
        echo json_encode(['items' => [], 'error' => null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (company_source() === 'sqlserver') {
        $items = array_map(static function (array $row): array {
            return [
                'company_id' => 0,
                'sql_customer_id' => (int) ($row['sql_customer_id'] ?? $row['id']),
                'label' => company_display_label($row),
                'name' => (string) ($row['name'] ?? ''),
                'code' => (string) ($row['account_code'] ?? ''),
                'type' => (string) ($row['account_type'] ?? ''),
                'meta' => trim((string) (($row['city'] ?? '') . ' ' . ($row['district'] ?? ''))),
            ];
        }, sql_customer_rows_for_company_list(18, null, 0, $query));
        echo json_encode(['items' => $items, 'error' => bilnex_customer_reader()->lastError()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $where = ' WHERE (c.name LIKE :q OR c.account_code LIKE :q OR c.tax_no LIKE :q OR c.contact_person LIKE :q OR c.phone LIKE :q OR c.city LIKE :q)';
    $params = [':q' => '%' . $query . '%'];
    [$scopeSql, $scopeParams] = owned_company_condition('c');
    $where .= $scopeSql;
    $params += $scopeParams;
    $items = array_map(static function (array $row): array {
        return [
            'company_id' => (int) $row['id'],
            'sql_customer_id' => (int) ($row['sql_customer_id'] ?? 0),
            'label' => company_display_label($row),
            'name' => (string) ($row['name'] ?? ''),
            'code' => (string) ($row['account_code'] ?? ''),
            'type' => (string) ($row['account_type'] ?? ''),
            'meta' => trim((string) (($row['contact_person'] ?? '') . ' ' . ($row['city'] ?? '') . ' ' . ($row['district'] ?? ''))),
        ];
    }, rows("SELECT c.id, c.sql_customer_id, c.name, c.account_code, c.account_type, c.contact_person, c.city, c.district FROM companies c {$where} ORDER BY CASE WHEN c.account_code = :exact THEN 0 WHEN c.name = :exact THEN 1 WHEN c.account_code LIKE :prefix THEN 2 WHEN c.name LIKE :prefix THEN 3 ELSE 4 END, c.name LIMIT 18", $params + [':exact' => $query, ':prefix' => $query . '%']));

    echo json_encode(['items' => $items, 'error' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($page === 'update_profile_photo') {
        $user = current_user();
        $file = $_FILES['avatar'] ?? null;
        if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash('Profil fotoğrafı seçin.', 'danger');
            redirect_to('profile');
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            flash('Profil fotoğrafı yüklenemedi. Dosya boyutunu ve formatını kontrol edin.', 'danger');
            redirect_to('profile');
        }
        if ((int) ($file['size'] ?? 0) > 20 * 1024 * 1024) {
            flash('Profil fotoğrafı en fazla 20 MB olabilir. Yüklenen fotoğraf otomatik küçültülür.', 'danger');
            redirect_to('profile');
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $imageInfo = $tmpPath !== '' && is_file($tmpPath) ? @getimagesize($tmpPath) : false;
        $mime = '';
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $tmpPath !== '' && is_file($tmpPath) ? (string) $finfo->file($tmpPath) : '';
        }
        if ($mime === '' && is_array($imageInfo) && !empty($imageInfo['mime'])) {
            $mime = (string) $imageInfo['mime'];
        }
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime]) || $imageInfo === false) {
            flash('Sadece JPG, PNG veya WebP profil fotoğrafı yükleyin.', 'danger');
            redirect_to('profile');
        }

        $uploadDir = __DIR__ . '/data/profile-photos';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            flash('Profil fotoğrafı klasörü oluşturulamadı.', 'danger');
            redirect_to('profile');
        }

        $filename = 'user-' . (int) $user['id'] . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.jpg';
        $destination = $uploadDir . '/' . $filename;
        try {
            save_standard_profile_avatar($tmpPath, $destination);
        } catch (Throwable $exception) {
            if (is_file($destination)) {
                @unlink($destination);
            }
            flash($exception->getMessage(), 'danger');
            redirect_to('profile');
        }

        $relativePath = 'data/profile-photos/' . $filename;
        db()->prepare('UPDATE users SET avatar_path = :avatar_path WHERE id = :id')->execute([
            ':avatar_path' => $relativePath,
            ':id' => (int) $user['id'],
        ]);

        $oldPath = trim(str_replace('\\', '/', (string) ($user['avatar_path'] ?? '')));
        $oldFullPath = __DIR__ . '/' . $oldPath;
        if (preg_match('#^data/profile-photos/[A-Za-z0-9._-]+$#', $oldPath) && is_file($oldFullPath) && $oldFullPath !== $destination) {
            @unlink($oldFullPath);
        }

        flash('Profil fotoğrafı 256x256 piksel standart boyuta küçültülerek güncellendi.');
        redirect_to('profile');
    }

    if ($page === 'change_password') {
        $user = current_user();
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($newPassword === '' || strlen($newPassword) < 8) {
            flash('Yeni şifre en az 8 karakter olmalı.', 'danger');
            redirect_to('profile');
        }
        if ($newPassword !== $confirmPassword) {
            flash('Yeni şifre ve tekrar alanı aynı olmalı.', 'danger');
            redirect_to('profile');
        }
        if (!password_verify($currentPassword, (string) $user['password_hash'])) {
            flash('Mevcut şifre hatalı.', 'danger');
            redirect_to('profile');
        }

        db()->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id')->execute([
            ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':id' => (int) $user['id'],
        ]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        flash('Şifre güncellendi.');
        redirect_to('profile');
    }

    if ($page === 'save_task') {
        $taskId = (int) ($_POST['id'] ?? 0);
        $existingTask = null;
        if ($taskId > 0 && !user_can_access_task($taskId)) {
            http_response_code(403);
            exit('Bu işi düzenleme yetkiniz yok.');
        }
        if ($taskId > 0) {
            $existingTask = rows('SELECT * FROM tasks WHERE id = :id', [':id' => $taskId])[0] ?? null;
            if (!$existingTask) {
                http_response_code(404);
                exit('İş bulunamadı.');
            }
        }
        $assignedTo = (int) ($_POST['assigned_to'] ?? 0);
        $validAssignee = (int) scalar('SELECT COUNT(*) FROM users WHERE id = :id AND active = 1', [':id' => $assignedTo]);
        $title = trim($_POST['title'] ?? '');
        $companyId = (int) ($_POST['company_id'] ?? 0);
        $sqlCustomerId = (int) ($_POST['sql_customer_id'] ?? 0);
        if ($sqlCustomerId > 0 && company_source() === 'sqlserver') {
            $syncedCompanyId = ensure_local_company_for_sql_customer($sqlCustomerId, (int) current_user()['id']);
            if (!$syncedCompanyId) {
                flash('SQL cari bulunamadı veya okunamadı. Lütfen cari aramasından tekrar seçin.', 'danger');
                redirect_to('followups');
            }
            $companyId = $syncedCompanyId;
        } elseif ($companyId > 0) {
            require_company_access($companyId);
            $sqlCustomerId = company_sql_customer_id($companyId) ?? 0;
        } else {
            $companyId = null;
        }
        if ($title === '' || $validAssignee === 0) {
            flash('İş başlığı ve atanacak personel zorunludur.', 'danger');
            redirect_to('followups', $taskId > 0 ? ['edit_task' => $taskId] : []);
        }
        $status = $_POST['status'] ?? 'Açık';
        if (!in_array($status, task_statuses(), true)) {
            $status = 'Açık';
        }
        if ($taskId > 0 && $status === 'Tamamlandı' && $existingTask && !can_complete_task($existingTask)) {
            http_response_code(403);
            exit('Bu işi tamamlama yetkiniz yok.');
        }
        $data = [
            ':company_id' => $companyId,
            ':sql_customer_id' => $sqlCustomerId > 0 ? $sqlCustomerId : null,
            ':title' => $title,
            ':description' => trim($_POST['description'] ?? ''),
            ':assigned_to' => $assignedTo,
            ':due_date' => $_POST['due_date'] ?: null,
            ':status' => $status,
        ];
        if ($taskId > 0) {
            $completedSet = $status === 'Tamamlandı' ? ', completed_at = COALESCE(completed_at, CURRENT_TIMESTAMP)' : ', completed_at = NULL, completion_note = NULL';
            $data[':id'] = $taskId;
            db()->prepare("UPDATE tasks SET company_id = :company_id, sql_customer_id = :sql_customer_id, title = :title, description = :description, assigned_to = :assigned_to, due_date = :due_date, status = :status, updated_at = CURRENT_TIMESTAMP{$completedSet} WHERE id = :id")->execute($data);
            flash('İş güncellendi.');
        } else {
            $data[':assigned_by'] = current_user()['id'];
            db()->prepare('INSERT INTO tasks (company_id, sql_customer_id, title, description, assigned_by, assigned_to, due_date, status) VALUES (:company_id, :sql_customer_id, :title, :description, :assigned_by, :assigned_to, :due_date, :status)')->execute($data);
            flash('İş atandı.');
        }
        redirect_to('followups');
    }

    if ($page === 'complete_task') {
        $taskId = (int) ($_POST['id'] ?? 0);
        $task = rows('SELECT * FROM tasks WHERE id = :id', [':id' => $taskId])[0] ?? null;
        if (!$task) {
            http_response_code(404);
            exit('İş bulunamadı.');
        }
        if (!can_complete_task($task)) {
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

    if ($page === 'delete_task') {
        flash('Takip listesi kayıtları silinmez. Gerekirse işi düzenleyin veya Tamamlandı durumuna alın.', 'danger');
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
            ':role' => normalize_role($_POST['role'] ?? ROLE_SALES),
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
        $returnTo = ($_POST['return_to'] ?? '') === 'opportunity_form' ? 'opportunity_form' : '';
        $companyFormParams = $returnTo !== '' ? ['return_to' => $returnTo] : [];
        if ($id > 0) {
            require_company_access($id);
        }
        $existingSqlCustomerId = null;
        $existingAccountCode = '';
        $existingBalanceAmount = 0.0;
        $existingBalanceSide = '';
        $existingSource = '';
        if ($id > 0) {
            $existingCompany = rows('SELECT sql_customer_id, account_code, balance_amount, balance_side, source FROM companies WHERE id = :id', [':id' => $id])[0] ?? [];
            $currentSqlCustomerId = (int) ($existingCompany['sql_customer_id'] ?? 0);
            $existingSqlCustomerId = $currentSqlCustomerId > 0 ? $currentSqlCustomerId : null;
            $existingAccountCode = (string) ($existingCompany['account_code'] ?? '');
            $existingBalanceAmount = (float) ($existingCompany['balance_amount'] ?? 0);
            $existingBalanceSide = (string) ($existingCompany['balance_side'] ?? '');
            $existingSource = (string) ($existingCompany['source'] ?? '');
        }
        $responsible = (int) ($_POST['responsible_user_id'] ?? current_user()['id']);
        if (!can_view_all()) {
            $responsible = (int) current_user()['id'];
        }
        $submittedAccountType = trim((string) ($_POST['account_type'] ?? ''));
        $submittedTaxNo = phone_digits($_POST['tax_no'] ?? '');
        $submittedTaxOffice = trim((string) ($_POST['tax_office'] ?? ''));
        $submittedTaxOfficeCode = trim((string) ($_POST['tax_office_code'] ?? ''));
        $taxOffice = tax_office_find($submittedTaxOfficeCode, $submittedTaxOffice);
        if ($taxOffice) {
            $submittedTaxOffice = (string) ($taxOffice['name'] ?? $submittedTaxOffice);
            $submittedTaxOfficeCode = (string) ($taxOffice['code'] ?? $submittedTaxOfficeCode);
        }
        $data = [
            ':name' => trim($_POST['name'] ?? ''),
            ':sql_customer_id' => $existingSqlCustomerId,
            ':account_type' => normalize_company_account_type($submittedAccountType !== '' ? $submittedAccountType : 'Hedef Bayi'),
            ':account_code' => $existingAccountCode,
            ':contact_person' => trim($_POST['contact_person'] ?? ''),
            ':phone' => trim($_POST['phone'] ?? ''),
            ':email' => trim($_POST['email'] ?? ''),
            ':city' => trim($_POST['city'] ?? ''),
            ':district' => trim($_POST['district'] ?? ''),
            ':address' => trim($_POST['address'] ?? ''),
            ':tax_no' => $submittedTaxNo,
            ':tax_office' => $submittedTaxOffice,
            ':tax_office_code' => $submittedTaxOfficeCode,
            ':balance_amount' => array_key_exists('balance_amount', $_POST) ? (float) str_replace(',', '.', $_POST['balance_amount']) : $existingBalanceAmount,
            ':balance_side' => array_key_exists('balance_side', $_POST) ? trim($_POST['balance_side']) : $existingBalanceSide,
            ':status' => $_POST['status'] ?? 'Yeni kayıt',
            ':source' => trim($_POST['source'] ?? ($existingSource !== '' ? $existingSource : 'CRM manuel kayıt')),
            ':responsible_user_id' => $responsible,
            ':description' => trim($_POST['description'] ?? ''),
        ];
        if (!in_array($data[':status'], company_statuses(), true)) {
            $data[':status'] = 'Yeni kayıt';
        }
        if ($data[':name'] === '') {
            flash('Cari adı zorunludur.', 'danger');
            redirect_to('company_form', $id > 0 ? ['id' => $id] + $companyFormParams : $companyFormParams);
        }
        if (!in_array(strlen($data[':tax_no']), [10, 11], true)) {
            flash('Vergi no zorunludur ve 10 ya da 11 haneli olmalıdır.', 'danger');
            redirect_to('company_form', $id > 0 ? ['id' => $id] + $companyFormParams : $companyFormParams);
        }
        if (!$taxOffice) {
            flash('Vergi dairesi zorunludur. Lütfen listedeki GİB vergi dairelerinden birini seçin.', 'danger');
            redirect_to('company_form', $id > 0 ? ['id' => $id] + $companyFormParams : $companyFormParams);
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
                    'tax_office' => $data[':tax_office'],
                    'description' => $data[':description'],
                ]);
                $data[':sql_customer_id'] = (int) $created['id'];
                $data[':account_code'] = (string) $created['code'];
                $data[':source'] = 'SQL Server Customer';
                $data[':created_by'] = current_user()['id'];
                db()->prepare('INSERT INTO companies (name, sql_customer_id, account_type, account_code, contact_person, phone, email, city, district, address, tax_no, tax_office, tax_office_code, balance_amount, balance_side, status, source, responsible_user_id, description, created_by) VALUES (:name, :sql_customer_id, :account_type, :account_code, :contact_person, :phone, :email, :city, :district, :address, :tax_no, :tax_office, :tax_office_code, :balance_amount, :balance_side, :status, :source, :responsible_user_id, :description, :created_by)')->execute($data);
                $id = (int) db()->lastInsertId();
                flash('Cari SQL Server kaydına yazıldı. Cari kodu: ' . $created['code']);
                if ($returnTo === 'opportunity_form') {
                    redirect_to('opportunity_form', ['company_id' => $id]);
                }
                redirect_to('companies', ['account_type' => $data[':account_type']]);
            } catch (Throwable $exception) {
                error_log('[Bilnex CRM] SQL Server Customer write failed: ' . $exception->getMessage());
                flash('Cari SQL Server kaydına yazılamadı: ' . $exception->getMessage(), 'danger');
                redirect_to('company_form', $companyFormParams);
            }
        }
        if ($id > 0) {
            $data[':id'] = $id;
            db()->prepare('UPDATE companies SET name = :name, sql_customer_id = :sql_customer_id, account_type = :account_type, account_code = :account_code, contact_person = :contact_person, phone = :phone, email = :email, city = :city, district = :district, address = :address, tax_no = :tax_no, tax_office = :tax_office, tax_office_code = :tax_office_code, balance_amount = :balance_amount, balance_side = :balance_side, status = :status, source = :source, responsible_user_id = :responsible_user_id, description = :description, updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute($data);
            flash('Cari kartı güncellendi.');
        } else {
            $data[':created_by'] = current_user()['id'];
            db()->prepare('INSERT INTO companies (name, sql_customer_id, account_type, account_code, contact_person, phone, email, city, district, address, tax_no, tax_office, tax_office_code, balance_amount, balance_side, status, source, responsible_user_id, description, created_by) VALUES (:name, :sql_customer_id, :account_type, :account_code, :contact_person, :phone, :email, :city, :district, :address, :tax_no, :tax_office, :tax_office_code, :balance_amount, :balance_side, :status, :source, :responsible_user_id, :description, :created_by)')->execute($data);
            $id = (int) db()->lastInsertId();
            flash('Cari kartı oluşturuldu.');
        }
        if ($returnTo === 'opportunity_form') {
            redirect_to('opportunity_form', ['company_id' => $id]);
        }
        redirect_to('company_view', ['id' => $id]);
    }

    if ($page === 'save_sql_company') {
        if (company_source() !== 'sqlserver') {
            flash('SQL Server cari kaynağı aktif değil.', 'danger');
            redirect_to('companies');
        }

        $sqlCustomerId = (int) ($_POST['sql_customer_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $submittedAccountType = trim((string) ($_POST['account_type'] ?? ''));
        $accountType = normalize_company_account_type($submittedAccountType !== '' ? $submittedAccountType : 'Hedef Bayi');
        $submittedTaxNo = phone_digits($_POST['tax_no'] ?? '');
        $submittedTaxOffice = trim((string) ($_POST['tax_office'] ?? ''));
        $submittedTaxOfficeCode = trim((string) ($_POST['tax_office_code'] ?? ''));
        $taxOffice = tax_office_find($submittedTaxOfficeCode, $submittedTaxOffice);
        if ($taxOffice) {
            $submittedTaxOffice = (string) ($taxOffice['name'] ?? $submittedTaxOffice);
            $submittedTaxOfficeCode = (string) ($taxOffice['code'] ?? $submittedTaxOfficeCode);
        }
        if ($sqlCustomerId <= 0 || $name === '') {
            flash('Cari adı zorunludur.', 'danger');
            redirect_to('sql_company_form', $sqlCustomerId > 0 ? ['id' => $sqlCustomerId] : []);
        }

        if (!in_array(strlen($submittedTaxNo), [10, 11], true)) {
            flash('Vergi no zorunludur ve 10 ya da 11 haneli olmalıdır.', 'danger');
            redirect_to('sql_company_form', ['id' => $sqlCustomerId]);
        }
        if (!$taxOffice) {
            flash('Vergi dairesi zorunludur. Lütfen listedeki GİB vergi dairelerinden birini seçin.', 'danger');
            redirect_to('sql_company_form', ['id' => $sqlCustomerId]);
        }

        try {
            bilnex_customer_writer()->updateCustomer($sqlCustomerId, [
                'name' => $name,
                'customer_type_id' => company_account_type_sql_id($accountType) ?? 14,
                'contact_person' => trim((string) ($_POST['contact_person'] ?? '')),
                'phone' => trim((string) ($_POST['phone'] ?? '')),
                'email' => trim((string) ($_POST['email'] ?? '')),
                'city' => trim((string) ($_POST['city'] ?? '')),
                'district' => trim((string) ($_POST['district'] ?? '')),
                'address' => trim((string) ($_POST['address'] ?? '')),
                'tax_no' => $submittedTaxNo,
                'tax_office' => $submittedTaxOffice,
                'description' => trim((string) ($_POST['description'] ?? '')),
            ]);
            ensure_local_company_for_sql_customer($sqlCustomerId, (int) current_user()['id']);
            flash('SQL cari kaydı güncellendi.');
            redirect_to('companies', ['q' => $name]);
        } catch (Throwable $exception) {
            error_log('[Bilnex CRM] SQL Server Customer update failed: ' . $exception->getMessage());
            flash('SQL cari kaydı güncellenemedi: ' . $exception->getMessage(), 'danger');
            redirect_to('sql_company_form', ['id' => $sqlCustomerId]);
        }
    }

    if ($page === 'save_interaction') {
        $id = (int) ($_POST['id'] ?? 0);
        $companyId = (int) ($_POST['company_id'] ?? 0);
        $existingInteraction = null;
        if ($id > 0) {
            [$editInteractionScopeSql, $editInteractionScopeParams] = interaction_visibility_condition('i');
            $existingInteraction = rows("SELECT i.* FROM interactions i WHERE i.id = :id{$editInteractionScopeSql}", $editInteractionScopeParams + [':id' => $id])[0] ?? null;
            if (!$existingInteraction) {
                http_response_code(404);
                exit('Görüşme kaydı bulunamadı veya düzenleme yetkiniz yok.');
            }
        }
        if ($companyId <= 0) {
            $companyId = company_id_from_lookup($_POST['company_lookup'] ?? '');
        }
        $sqlCustomerId = (int) ($_POST['sql_customer_id'] ?? 0);
        if ($sqlCustomerId > 0 && company_source() === 'sqlserver') {
            $syncedCompanyId = ensure_local_company_for_sql_customer($sqlCustomerId, (int) current_user()['id']);
            if (!$syncedCompanyId) {
                flash('SQL cari bulunamadı veya okunamadı. Lütfen cari aramasından tekrar seçin.', 'danger');
                redirect_to('interactions');
            }
            $companyId = $syncedCompanyId;
        }
        if ($companyId <= 0) {
            flash('Görüşme kaydı için cari seçin.', 'danger');
            redirect_to('interactions');
        }
        require_company_access($companyId);
        $result = $_POST['result'] ?? 'Tekrar aranacak';
        $nextFollowupDate = ($_POST['next_followup_date'] ?? '') ?: null;
        $data = [
            ':company_id' => $companyId,
            ':sql_customer_id' => company_sql_customer_id($companyId),
            ':interaction_date' => ($_POST['interaction_date'] ?? '') ?: date('Y-m-d'),
            ':type' => $_POST['type'] ?? 'Telefon',
            ':result' => $result,
            ':note' => trim($_POST['note'] ?? ''),
            ':next_followup_date' => $nextFollowupDate,
        ];
        if ($id > 0) {
            $data[':id'] = $id;
            db()->prepare('UPDATE interactions SET company_id = :company_id, sql_customer_id = :sql_customer_id, interaction_date = :interaction_date, type = :type, result = :result, note = :note, next_followup_date = :next_followup_date WHERE id = :id')->execute($data);
        } else {
            $data[':user_id'] = current_user()['id'];
            db()->prepare('INSERT INTO interactions (company_id, sql_customer_id, user_id, interaction_date, type, result, note, next_followup_date) VALUES (:company_id, :sql_customer_id, :user_id, :interaction_date, :type, :result, :note, :next_followup_date)')->execute($data);
        }
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
        flash($id > 0 ? 'Görüşme kaydı güncellendi.' : 'Görüşme notu eklendi.');
        if (($_POST['return_to'] ?? '') === 'interactions') {
            redirect_to('interactions', ['date_filter' => 'today']);
        }
        redirect_to('company_view', ['id' => $companyId]);
    }

    if ($page === 'save_opportunity') {
        $id = (int) ($_POST['id'] ?? 0);
        $companyId = (int) ($_POST['company_id'] ?? 0);
        $sqlCustomerId = (int) ($_POST['sql_customer_id'] ?? 0);
        $existing = null;
        if ($id > 0) {
            $existing = rows('SELECT company_id, salesperson_id FROM opportunities WHERE id = :id', [':id' => $id])[0] ?? null;
            if (!$existing) {
                http_response_code(404);
                exit('Satış fırsatı bulunamadı.');
            }
            if (!user_can_access_opportunity($id)) {
                http_response_code(403);
                exit('Bu satış fırsatını düzenleme yetkiniz yok.');
            }
            require_company_access((int) $existing['company_id']);
        }
        if ($companyId <= 0 && $sqlCustomerId > 0 && company_source() === 'sqlserver') {
            $syncedCompanyId = null;
            try {
                $syncedCompanyId = ensure_local_company_for_sql_customer($sqlCustomerId, (int) current_user()['id']);
            } catch (Throwable $exception) {
                error_log('SQL customer sync failed while saving opportunity: ' . $exception->getMessage());
            }
            if (!$syncedCompanyId) {
                if (!$existing) {
                    flash('SQL cari bulunamadı veya okunamadı. Lütfen cari aramasından tekrar seçin.', 'danger');
                    redirect_to('opportunity_form', []);
                }
            } else {
                $companyId = $syncedCompanyId;
            }
        }
        if ($companyId <= 0) {
            $companyId = company_id_from_lookup($_POST['company_lookup'] ?? '');
        }
        if ($companyId <= 0 && $existing) {
            $companyId = (int) $existing['company_id'];
        }
        if ($companyId <= 0) {
            flash('İlgili cari seçimi zorunludur.', 'danger');
            redirect_to('opportunity_form', $id > 0 ? ['id' => $id] : []);
        }
        require_company_access($companyId);
        $sqlCustomerId = company_sql_customer_id($companyId) ?? ($sqlCustomerId > 0 ? $sqlCustomerId : null);
        $salesperson = (int) ($_POST['salesperson_id'] ?? current_user()['id']);
        if (!can_view_all()) {
            $salesperson = (int) current_user()['id'];
        }
        $data = [
            ':company_id' => $companyId,
            ':sql_customer_id' => $sqlCustomerId,
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

    if ($page === 'update_opportunity_stage') {
        header('Content-Type: application/json; charset=utf-8');
        $id = (int) ($_POST['id'] ?? 0);
        $stage = (string) ($_POST['stage'] ?? '');
        if ($id <= 0 || !in_array($stage, opportunity_stages(), true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Geçersiz fırsat veya aşama.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!user_can_access_opportunity($id)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Bu fırsatı güncelleme yetkiniz yok.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        db()->prepare('UPDATE opportunities SET stage = :stage, updated_at = CURRENT_TIMESTAMP WHERE id = :id')->execute([
            ':stage' => $stage,
            ':id' => $id,
        ]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($page === 'delete_opportunity') {
        flash('Satış fırsatları silinmez. Gerekirse aşamasını güncelleyin.', 'danger');
        redirect_to('opportunities');
    }
}

if ($page === 'dashboard') {
    render_header('Dashboard');
    $today = date('Y-m-d');
    $weekStart = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
    $monthStart = date('Y-m-01');
    if (can_view_all()) {
        [$dashboardOppScopeSql, $dashboardOppScopeParams] = opportunity_visibility_condition('o');
        [$dashboardInteractionScopeSql, $dashboardInteractionScopeParams] = interaction_visibility_condition('i');
        $weekCount = (int) scalar('SELECT COUNT(*) FROM interactions i WHERE date(i.interaction_date) >= :week' . $dashboardInteractionScopeSql, $dashboardInteractionScopeParams + [':week' => $weekStart]);
        $wonAmount = (float) scalar('SELECT COALESCE(SUM(o.estimated_amount), 0) FROM opportunities o WHERE o.stage = "Kazanıldı"' . $dashboardOppScopeSql, $dashboardOppScopeParams);
        $openAmount = (float) scalar('SELECT COALESCE(SUM(o.estimated_amount), 0) FROM opportunities o WHERE o.stage NOT IN ("Kazanıldı", "Kaybedildi")' . $dashboardOppScopeSql, $dashboardOppScopeParams);
        $conversionWon = (int) scalar('SELECT COUNT(*) FROM opportunities o WHERE o.stage = "Kazanıldı"' . $dashboardOppScopeSql, $dashboardOppScopeParams);
        $conversionClosed = (int) scalar('SELECT COUNT(*) FROM opportunities o WHERE o.stage IN ("Kazanıldı", "Kaybedildi")' . $dashboardOppScopeSql, $dashboardOppScopeParams);
        $conversionRate = $conversionClosed > 0 ? round(($conversionWon / $conversionClosed) * 100) . '%' : '0%';
        if (company_source() === 'sqlserver') {
            $totalCompanyCount = bilnex_customer_reader()->countActiveCustomers();
        } else {
            [$dashboardCompanyScopeSql, $dashboardCompanyScopeParams] = owned_company_condition('c');
            $totalCompanyCount = scalar('SELECT COUNT(*) FROM companies c WHERE 1 = 1' . $dashboardCompanyScopeSql, $dashboardCompanyScopeParams);
        }
        [$dashboardTaskScopeSql, $dashboardTaskScopeParams] = task_visibility_condition('t');
        $dashboardOpenTaskCount = scalar("SELECT COUNT(*) FROM tasks t WHERE t.status = 'Açık'{$dashboardTaskScopeSql}", $dashboardTaskScopeParams);
        $dashboardOverdueTaskCount = scalar("SELECT COUNT(*) FROM tasks t WHERE t.status = 'Açık'{$dashboardTaskScopeSql} AND t.due_date IS NOT NULL AND date(t.due_date) < :today", $dashboardTaskScopeParams + [':today' => $today]);
        $openOpportunityCount = scalar('SELECT COUNT(*) FROM opportunities o WHERE o.stage NOT IN ("Kazanıldı", "Kaybedildi")' . $dashboardOppScopeSql, $dashboardOppScopeParams);
        $cards = [
            ['Bugünkü Görüşmeler', scalar('SELECT COUNT(*) FROM interactions i WHERE date(i.interaction_date) = :today' . $dashboardInteractionScopeSql, $dashboardInteractionScopeParams + [':today' => $today]), 'Bu hafta: ' . $weekCount, 'stat-blue'],
            ['Toplam Cari', number_format((float) $totalCompanyCount, 0, ',', '.'), company_source() === 'sqlserver' ? 'SQL Customer kaynağı' : 'Tüm kayıtlar', 'stat-violet'],
            ['Açık Görevler', $dashboardOpenTaskCount, 'Geciken: ' . $dashboardOverdueTaskCount, 'stat-red'],
            ['Açık Fırsatlar', $openOpportunityCount, 'Toplam tutar: ' . money($openAmount), 'stat-cyan'],
            ['Kazanılan Satış', $conversionWon, 'Toplam tutar: ' . money($wonAmount), 'stat-green'],
        ];
        $cardLinks = [
            app_url('interactions', ['date_filter' => 'today']),
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

        $dashboardTasks = rows("SELECT t.*, assigner.full_name assigned_by_name, assignee.full_name assigned_to_name FROM tasks t LEFT JOIN users assigner ON assigner.id = t.assigned_by LEFT JOIN users assignee ON assignee.id = t.assigned_to WHERE t.status = 'Açık'{$dashboardTaskScopeSql} ORDER BY COALESCE(t.due_date, '9999-12-31'), t.created_at DESC LIMIT 10", $dashboardTaskScopeParams);

        if (company_source() === 'sqlserver') {
            $statusRows = array_map(static function (array $row): array {
                return [
                    'status' => $row['CustomerTypeName'] ?: sql_customer_type_label((int) ($row['CustomerTypeId'] ?? 0)),
                    'total' => (int) ($row['total'] ?? 0),
                ];
            }, bilnex_customer_reader()->countActiveCustomersByType());
        } else {
            [$dashboardCompanyStatusScopeSql, $dashboardCompanyStatusScopeParams] = owned_company_condition('c');
            $statusRows = rows('SELECT c.status, COUNT(*) total FROM companies c WHERE 1 = 1' . $dashboardCompanyStatusScopeSql . ' GROUP BY c.status ORDER BY total DESC', $dashboardCompanyStatusScopeParams);
        }
        $dashboardStatusRows = compact_metric_rows($statusRows, 'status', 'total', 6);
        $pipeline = rows('SELECT o.stage, COUNT(*) total, COALESCE(SUM(o.estimated_amount), 0) amount FROM opportunities o WHERE 1 = 1' . $dashboardOppScopeSql . ' GROUP BY o.stage ORDER BY CASE o.stage WHEN "Yeni fırsat" THEN 1 WHEN "Görüşme yapılıyor" THEN 2 WHEN "Teklif verildi" THEN 3 WHEN "Sözleşme bekleniyor" THEN 4 WHEN "Kazanıldı" THEN 5 WHEN "Kaybedildi" THEN 6 ELSE 7 END', $dashboardOppScopeParams);
        $trend = rows('SELECT date(i.interaction_date) day, COUNT(*) total FROM interactions i WHERE date(i.interaction_date) >= date(:today, "-6 day")' . $dashboardInteractionScopeSql . ' GROUP BY date(i.interaction_date) ORDER BY day', $dashboardInteractionScopeParams + [':today' => $today]);
        $overdueTasks = rows("SELECT t.*, c.name company_name, assigner.full_name assigned_by_name, assignee.full_name assigned_to_name FROM tasks t LEFT JOIN companies c ON c.id = t.company_id LEFT JOIN users assigner ON assigner.id = t.assigned_by LEFT JOIN users assignee ON assignee.id = t.assigned_to WHERE t.status = 'Açık'{$dashboardTaskScopeSql} AND t.due_date IS NOT NULL AND date(t.due_date) < :today ORDER BY t.due_date ASC, t.created_at DESC LIMIT 8", $dashboardTaskScopeParams + [':today' => $today]);
        $openOpps = rows('SELECT o.id, o.product_service, o.estimated_amount, o.stage, o.expected_close_date, c.name company_name, u.full_name salesperson_name FROM opportunities o JOIN companies c ON c.id = o.company_id LEFT JOIN users u ON u.id = o.salesperson_id WHERE o.stage NOT IN ("Kazanıldı", "Kaybedildi")' . $dashboardOppScopeSql . ' ORDER BY o.estimated_amount DESC LIMIT 8', $dashboardOppScopeParams);

        echo '<section class="dashboard-grid dashboard-main-grid">';
        echo '<article class="panel chart-panel"><div class="section-title"><h2>Görüşme Trendi</h2><a class="btn small" href="' . e(app_url('interactions', ['date_filter' => 'week'])) . '">Son 7 gün</a></div>';
        render_trend_chart($trend, 'day', 'total');
        echo '</article>';

        echo '<article class="panel chart-panel dashboard-status-panel"><div class="section-title"><h2>Bayi Durum Dağılımı</h2><a class="btn small" href="' . e(app_url('companies')) . '">Detaylar</a></div><div class="analytics-split">';
        render_donut_chart($dashboardStatusRows, 'status', 'total');
        render_linked_bar_list($dashboardStatusRows, 'status', 'total', static fn($row) => !empty($row['_is_other']) ? app_url('companies') : app_url('companies', ['account_type' => $row['status']]));
        echo '</div>';
        echo '</article>';

        echo '<article class="panel chart-panel wide-panel"><div class="section-title"><h2>Satış Pipeline</h2><a class="btn small" href="' . e(app_url('opportunities')) . '">Fırsatlar</a></div>';
        echo '<div class="pipeline">';
        foreach ($pipeline as $row) {
            $stageClass = match ($row['stage'] ?? '') {
                'Görüşme yapılıyor' => 'stage-meeting',
                'Teklif verildi' => 'stage-offer',
                'Sözleşme bekleniyor' => 'stage-contract',
                'Kazanıldı' => 'stage-won',
                'Kaybedildi' => 'stage-lost',
                default => 'stage-new',
            };
            echo '<div class="pipeline-step ' . e($stageClass) . '"><span>' . e($row['stage']) . '</span><strong>' . e($row['total']) . '</strong><small>' . e(money($row['amount'])) . '</small></div>';
        }
        if (!$pipeline) {
            echo '<p class="muted">Satış fırsatı yok.</p>';
        }
        echo '</div></article>';
        echo '</section>';

        echo '<section class="dashboard-list-grid">';
        echo '<article class="panel dashboard-list-card"><div class="section-title"><h2>Yaklaşan Görevler</h2><a class="btn small" href="' . e(app_url('followups')) . '">Tüm Görevler</a></div><div class="mini-card-list">';
        foreach (array_slice($dashboardTasks, 0, 4) as $task) {
            echo '<a class="mini-card" href="' . e(app_url('followups')) . '"><strong>' . e($task['title']) . '</strong><span>Atayan: ' . e($task['assigned_by_name'] ?: '-') . ' · Atanan: ' . e($task['assigned_to_name'] ?: '-') . '</span><small>Termin: ' . e($task['due_date'] ?: '-') . '</small></a>';
        }
        if (!$dashboardTasks) {
            echo '<p class="muted">Açık görev yok.</p>';
        }
        echo '</div></article>';

        echo '<article class="panel dashboard-list-card"><div class="section-title"><h2>Geciken Görevler</h2><a class="btn small" href="' . e(app_url('followups', ['date_filter' => 'custom', 'date_to' => $today])) . '">Tüm Gecikenler</a></div><div class="mini-card-list">';
        foreach ($overdueTasks as $task) {
            $taskHref = app_url('followups', ['edit_task' => $task['id']]);
            echo '<a class="mini-card late" href="' . e($taskHref) . '"><strong>' . e($task['title']) . '</strong><span>' . e($task['company_name'] ?: 'Cari seçilmedi') . ' · Atanan: ' . e($task['assigned_to_name'] ?: '-') . '</span><small>Termin: ' . e($task['due_date']) . '</small></a>';
        }
        if (!$overdueTasks) {
            echo '<p class="muted">Geciken görev yok.</p>';
        }
        echo '</div></article>';

        echo '<article class="panel dashboard-list-card"><div class="section-title"><h2>Açık Fırsatlar</h2><a class="btn small" href="' . e(app_url('opportunities')) . '">Tüm Fırsatlar</a></div><div class="mini-card-list">';
        foreach (array_slice($openOpps, 0, 4) as $row) {
            echo '<a class="mini-card" href="' . e(app_url('opportunity_form', ['id' => $row['id']])) . '"><strong>' . e($row['company_name']) . '</strong><span>' . e($row['product_service']) . ' · ' . e($row['stage']) . '</span><small>' . e(money($row['estimated_amount'])) . '</small></a>';
        }
        if (!$openOpps) {
            echo '<p class="muted">Açık fırsat yok.</p>';
        }
        echo '</div></article>';

        echo '<article class="panel dashboard-list-card"><div class="section-title"><h2>Hatırlatmalar</h2><a class="btn small" href="' . e(app_url('reports')) . '">Raporlar</a></div><div class="reminder-list">';
        echo '<a href="' . e(app_url('followups', ['status' => 'Açık'])) . '"><strong>' . e($dashboardOpenTaskCount) . '</strong><span>açık görev takip bekliyor</span></a>';
        echo '<a href="' . e(app_url('followups', ['date_filter' => 'custom', 'date_to' => $today, 'status' => 'Açık'])) . '"><strong>' . e($dashboardOverdueTaskCount) . '</strong><span>görev gecikmiş görünüyor</span></a>';
        echo '<a href="' . e(app_url('opportunities', ['stage_group' => 'open'])) . '"><strong>' . e($openOpportunityCount) . '</strong><span>açık satış fırsatı var</span></a>';
        echo '</div></article>';
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
        echo '<a class="btn primary" href="' . e(app_url('interactions')) . '">Görüşme ekle</a>';
        echo '<a class="btn" href="' . e(app_url('company_form')) . '">Yeni cari</a>';
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

if ($page === 'profile') {
    $user = current_user();
    render_header('Profil Ayarları');
    ?>
    <section class="panel profile-hero">
        <?= render_user_avatar($user, 'profile-avatar large') ?>
        <div>
            <h2><?= e($user['full_name']) ?></h2>
            <p class="muted"><?= e($user['username']) ?> · <?= e(role_label($user['role'])) ?></p>
        </div>
    </section>
    <section class="grid-two profile-settings-grid">
        <form class="panel stack" method="post" action="<?= e(app_url('update_profile_photo')) ?>" enctype="multipart/form-data">
            <div>
                <h2>Profil Fotoğrafı</h2>
                <p class="muted">JPG, PNG veya WebP yükleyin. Fotoğraf otomatik 256x256 piksel standart avatar olarak küçültülür.</p>
            </div>
            <?= csrf_field() ?>
            <label>Fotoğraf seç
                <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required>
            </label>
            <button class="btn primary" type="submit">Fotoğrafı kaydet</button>
        </form>

        <form class="panel stack" id="password-section" method="post" action="<?= e(app_url('change_password')) ?>">
            <div>
                <h2>Şifre Değiştir</h2>
                <p class="muted">Şifre değişikliğinden sonra mevcut oturumunuz açık kalır.</p>
            </div>
            <?= csrf_field() ?>
            <label>Mevcut şifre <input name="current_password" type="password" autocomplete="current-password" required></label>
            <label>Yeni şifre <input name="new_password" type="password" autocomplete="new-password" minlength="8" required></label>
            <label>Yeni şifre tekrar <input name="confirm_password" type="password" autocomplete="new-password" minlength="8" required></label>
            <button class="btn primary" type="submit">Şifreyi güncelle</button>
        </form>
    </section>
    <section class="panel profile-session">
        <div>
            <h2>Oturum</h2>
            <p class="muted">Bu cihazdaki CRM oturumunu güvenli şekilde kapatır.</p>
        </div>
        <form method="post" action="<?= e(app_url('logout')) ?>">
            <?= csrf_field() ?>
            <button class="btn danger" type="submit">Çıkış yap</button>
        </form>
    </section>
    <?php
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
                        <option value="<?= e($value) ?>"<?= selected(normalize_role($edit['role'] ?? ROLE_SALES), $value) ?>><?= e($label) ?></option>
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
        $where .= ' AND (c.name LIKE :q OR c.account_code LIKE :q OR c.tax_no LIKE :q OR c.tax_office LIKE :q OR c.contact_person LIKE :q OR c.phone LIKE :q OR c.email LIKE :q OR c.city LIKE :q)';
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
    if (!$usingSqlServerCompanies && !empty($_GET['responsible_user_id']) && can_view_all()) {
        $where .= ' AND c.responsible_user_id = :responsible_user_id';
        $params[':responsible_user_id'] = (int) $_GET['responsible_user_id'];
    }
    if (!$usingSqlServerCompanies) {
        apply_date_filter($where, $params, 'c.created_at', $_GET);
    }
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
    if (!$usingSqlServerCompanies && can_view_all()) {
        $extras['responsible_user_id'] = ['_label' => 'Sorumlu', 'items' => $userOptions];
    }
    filter_bar('companies', $extras, !$usingSqlServerCompanies);
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
    <section class="panel company-list-panel">
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
        <div class="table-wrap companies-table-wrap"><table class="companies-table <?= $usingSqlServerCompanies ? 'companies-table-sql' : 'companies-table-local' ?>">
            <thead><tr><th>Cari kodu</th><th>Cari</th><th>Cari türü</th><th>Yetkili</th><th>Telefon</th><th>İl</th><?php if (!$usingSqlServerCompanies): ?><th>Durum</th><th>Sorumlu</th><?php endif; ?><th>Düzenle</th></tr></thead>
            <tbody>
            <?php foreach ($companies as $row): ?>
                <tr<?= !$usingSqlServerCompanies ? ' data-href="' . e(app_url('company_view', ['id' => $row['id']])) . '"' : '' ?>>
                    <td class="company-code-cell"><?= e($row['account_code'] ?: '-') ?></td>
                    <td class="company-name-cell">
                        <?php if ($usingSqlServerCompanies): ?>
                            <strong><?= e($row['name']) ?></strong>
                        <?php else: ?>
                            <a href="<?= e(app_url('company_view', ['id' => $row['id']])) ?>"><?= e($row['name']) ?></a>
                        <?php endif; ?>
                        <?php if (!empty($row['tax_no'])): ?><small>Vergi no: <?= e($row['tax_no']) ?></small><?php endif; ?>
                        <?php if (!empty($row['tax_office'])): ?><small>Vergi dairesi: <?= e($row['tax_office']) ?></small><?php endif; ?>
                    </td>
                    <td><span class="badge soft"><?= e($row['account_type'] ?? 'İş Ortağı') ?></span></td>
                    <td><?= e($row['contact_person'] ?: '-') ?></td>
                    <td><?php if ($row['phone']): ?><a href="tel:<?= e($row['phone']) ?>"><?= e($row['phone']) ?></a><?php else: ?>-<?php endif; ?></td>
                    <td class="location-cell">
                        <span><?= e($row['city'] ?: '-') ?></span>
                        <?php if (!empty($row['district'])): ?><small><?= e($row['district']) ?></small><?php endif; ?>
                    </td>
                    <?php if (!$usingSqlServerCompanies): ?><td><span class="badge"><?= e($row['status']) ?></span></td><?php endif; ?>
                    <?php if (!$usingSqlServerCompanies): ?><td><?= e($row['responsible_name'] ?: '-') ?></td><?php endif; ?>
                    <td class="actions-cell">
                        <a class="btn small" href="<?= e($usingSqlServerCompanies ? app_url('sql_company_form', ['id' => $row['sql_customer_id'] ?: $row['id']]) : app_url('company_form', ['id' => $row['id']])) ?>">Düzenle</a>
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

if ($page === 'sql_company_form') {
    if (company_source() !== 'sqlserver') {
        redirect_to('companies');
    }
    $sqlCustomerId = (int) ($_GET['id'] ?? 0);
    $rawCustomer = $sqlCustomerId > 0 ? bilnex_customer_reader()->findById($sqlCustomerId) : null;
    $readError = bilnex_customer_reader()->lastError();
    $company = $rawCustomer ? sql_customer_row_to_company_row($rawCustomer) : null;
    render_header($company ? 'SQL Cari Düzenle' : 'SQL Cari Bulunamadı');
    if (!$company): ?>
        <div class="alert alert-danger">
            SQL cari kaydı bulunamadı.
            <?php if ($readError): ?><br><small><?= e($readError) ?></small><?php endif; ?>
        </div>
        <a class="btn" href="<?= e(app_url('companies')) ?>">Carilere dön</a>
    <?php else:
        $selectedAccountType = $company['account_type'] ?? 'Hedef Bayi';
        ?>
        <form class="panel form-grid" method="post" action="<?= e(app_url('save_sql_company')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="sql_customer_id" value="<?= e($company['sql_customer_id']) ?>">
            <label>Cari kodu <input class="readonly-input" value="<?= e($company['account_code'] ?: '-') ?>" readonly aria-readonly="true"></label>
            <label>Cari adı <input name="name" value="<?= e($company['name']) ?>" required></label>
            <label>Cari türü
                <select name="account_type">
                    <?php foreach (company_account_types() as $type): ?>
                        <option value="<?= e($type) ?>"<?= selected($selectedAccountType, $type) ?>><?= e($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Yetkili kişi <input name="contact_person" value="<?= e($company['contact_person']) ?>"></label>
            <label>Vergi no <input name="tax_no" value="<?= e($company['tax_no']) ?>" inputmode="numeric" required></label>
            <?php render_tax_office_picker_field($company['tax_office'] ?? '', $company['tax_office_code'] ?? ''); ?>
            <label>Telefon <input name="phone" value="<?= e($company['phone']) ?>"></label>
            <label>E-posta <input type="email" name="email" value="<?= e($company['email']) ?>"></label>
            <label>İl <input name="city" value="<?= e($company['city']) ?>"></label>
            <label>İlçe <input name="district" value="<?= e($company['district']) ?>"></label>
            <label class="wide">Adres <textarea name="address"><?= e($company['address']) ?></textarea></label>
            <label class="wide">Açıklama <textarea name="description"><?= e($company['description'] ?? '') ?></textarea></label>
            <div class="actions wide">
                <a class="btn" href="<?= e(app_url('companies')) ?>">Vazgeç</a>
                <button class="btn primary" type="submit">SQL kaydını güncelle</button>
            </div>
        </form>
        <?php render_tax_office_dialog(); ?>
    <?php endif;
    render_footer();
    exit;
}

if ($page === 'company_form') {
    $id = (int) ($_GET['id'] ?? 0);
    $company = null;
    $usesSqlCustomerWrite = company_source() === 'sqlserver';
    $returnTo = ($_GET['return_to'] ?? '') === 'opportunity_form' ? 'opportunity_form' : '';
    if ($id > 0) {
        require_company_access($id);
        $company = rows('SELECT * FROM companies WHERE id = :id', [':id' => $id])[0] ?? null;
    }
    $selectedAccountType = $company['account_type'] ?? 'Hedef Bayi';
    render_header($company ? 'Cari Düzenle' : 'Yeni Cari');
    ?>
    <form class="panel form-grid" method="post" action="<?= e(app_url('save_company')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($company['id'] ?? 0) ?>">
        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        <input type="hidden" name="sql_customer_id" value="<?= e($company['sql_customer_id'] ?? '') ?>">
        <?php if ($returnTo === 'opportunity_form'): ?>
            <div class="alert wide">Cari kaydedilince Yeni Satış Fırsatı ekranına seçili olarak dönecek.</div>
        <?php endif; ?>
        <label>Cari adı <input name="name" value="<?= e($company['name'] ?? '') ?>" required></label>
        <label>Cari kodu <input class="readonly-input" name="account_code" value="<?= e($company['account_code'] ?? '') ?>" placeholder="Kaydedince otomatik atanır" readonly aria-readonly="true"></label>
        <label>Cari türü
            <select name="account_type">
                <?php foreach (company_account_types() as $type): ?>
                    <option value="<?= e($type) ?>"<?= selected($selectedAccountType, $type) ?>><?= e($type) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Yetkili kişi <input name="contact_person" value="<?= e($company['contact_person'] ?? '') ?>"></label>
        <label>Vergi no <input name="tax_no" value="<?= e($company['tax_no'] ?? '') ?>" inputmode="numeric" required></label>
        <?php render_tax_office_picker_field($company['tax_office'] ?? '', $company['tax_office_code'] ?? ''); ?>
        <label>Telefon <input name="phone" value="<?= e($company['phone'] ?? '') ?>"></label>
        <label>E-posta <input type="email" name="email" value="<?= e($company['email'] ?? '') ?>"></label>
        <label>İl <input name="city" value="<?= e($company['city'] ?? '') ?>"></label>
        <label>İlçe <input name="district" value="<?= e($company['district'] ?? '') ?>"></label>
        <label class="wide">Adres <textarea name="address"><?= e($company['address'] ?? '') ?></textarea></label>
        <?php if (!$usesSqlCustomerWrite): ?>
            <label>Durum
                <select name="status">
                    <?php foreach (company_statuses() as $status): ?>
                        <option value="<?= e($status) ?>"<?= selected($company['status'] ?? 'Yeni kayıt', $status) ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
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
    <?php render_tax_office_dialog(); ?>
    <?php
    render_footer();
    exit;
}

if ($page === 'interactions') {
    render_header('Görüşme Ekle');
    $today = date('Y-m-d');
    $currentUser = current_user();
    $usingSqlCustomerPicker = company_source() === 'sqlserver';
    $interactionCompanies = [];
    if (!$usingSqlCustomerPicker) {
        $companyWhere = ' WHERE 1 = 1';
        $companyParams = [];
        [$companyScopeSql, $companyScopeParams] = owned_company_condition('c');
        $companyWhere .= $companyScopeSql;
        $companyParams += $companyScopeParams;
        $interactionCompanies = rows("SELECT c.id, c.name, c.account_code, c.account_type, c.sql_customer_id, c.contact_person, c.phone, c.city, c.district FROM companies c {$companyWhere} ORDER BY c.name LIMIT 600", $companyParams);
    }

    $interactionScopeSql = '';
    $interactionScopeParams = [];
    $interactionScopeWhere = ' WHERE 1 = 1';
    $interactionScopeUsers = active_users();
    $allowedInteractionUserIds = array_map(static fn($user) => (int) $user['id'], $interactionScopeUsers);
    $selectedInteractionUserId = (int) ($_GET['interaction_user_id'] ?? 0);
    if ($selectedInteractionUserId > 0 && !in_array($selectedInteractionUserId, $allowedInteractionUserIds, true)) {
        $selectedInteractionUserId = 0;
    }
    $selectedInteractionUser = null;
    if ($selectedInteractionUserId > 0) {
        foreach ($interactionScopeUsers as $scopeUser) {
            if ((int) $scopeUser['id'] === $selectedInteractionUserId) {
                $selectedInteractionUser = $scopeUser;
                break;
            }
        }
    }
    $interactionUserWhere = $selectedInteractionUserId > 0 ? ' AND i.user_id = :selected_interaction_user_id' : '';
    $interactionUserParams = $selectedInteractionUserId > 0 ? [':selected_interaction_user_id' => $selectedInteractionUserId] : [];
    $totalVisibleInteractions = (int) scalar("SELECT COUNT(*) FROM interactions i {$interactionScopeWhere}{$interactionUserWhere}", $interactionScopeParams + $interactionUserParams);
    $todayInteractions = (int) scalar("SELECT COUNT(*) FROM interactions i {$interactionScopeWhere}{$interactionUserWhere} AND date(i.interaction_date) = :today", $interactionScopeParams + $interactionUserParams + [':today' => $today]);
    [$weekFrom, $weekTo] = date_filter_range(['date_filter' => 'week']);
    [$monthFrom, $monthTo] = date_filter_range(['date_filter' => 'month']);
    $weekInteractions = (int) scalar("SELECT COUNT(*) FROM interactions i {$interactionScopeWhere}{$interactionUserWhere} AND date(i.interaction_date) BETWEEN :week_from AND :week_to", $interactionScopeParams + $interactionUserParams + [':week_from' => $weekFrom, ':week_to' => $weekTo]);
    $monthInteractions = (int) scalar("SELECT COUNT(*) FROM interactions i {$interactionScopeWhere}{$interactionUserWhere} AND date(i.interaction_date) BETWEEN :month_from AND :month_to", $interactionScopeParams + $interactionUserParams + [':month_from' => $monthFrom, ':month_to' => $monthTo]);

    $listWhere = $interactionScopeWhere . $interactionUserWhere;
    $listParams = $interactionScopeParams + $interactionUserParams;
    if (!empty($_GET['q'])) {
        $listWhere .= ' AND (c.name LIKE :interaction_q OR c.account_code LIKE :interaction_q OR c.contact_person LIKE :interaction_q OR c.phone LIKE :interaction_q OR i.note LIKE :interaction_q OR i.type LIKE :interaction_q OR i.result LIKE :interaction_q OR u.full_name LIKE :interaction_q)';
        $listParams[':interaction_q'] = '%' . trim((string) $_GET['q']) . '%';
    }
    if (!empty($_GET['type'])) {
        $listWhere .= ' AND i.type = :interaction_type';
        $listParams[':interaction_type'] = (string) $_GET['type'];
    }
    if (!empty($_GET['result'])) {
        $listWhere .= ' AND i.result = :interaction_result';
        $listParams[':interaction_result'] = (string) $_GET['result'];
    }
    apply_date_filter($listWhere, $listParams, 'i.interaction_date', $_GET);

    $visibleInteractions = rows("SELECT i.*, c.name company_name, c.account_code company_account_code, c.account_type company_account_type, c.contact_person, c.phone, c.city, c.district, u.full_name user_name, u.role user_role FROM interactions i JOIN companies c ON c.id = i.company_id LEFT JOIN users u ON u.id = i.user_id {$listWhere} ORDER BY date(i.interaction_date) DESC, i.created_at DESC LIMIT 80", $listParams);
    $resultSummary = rows("SELECT i.result, COUNT(*) total FROM interactions i JOIN companies c ON c.id = i.company_id LEFT JOIN users u ON u.id = i.user_id {$listWhere} GROUP BY i.result ORDER BY total DESC", $listParams);
    $typeSummary = rows("SELECT i.type, COUNT(*) total FROM interactions i JOIN companies c ON c.id = i.company_id LEFT JOIN users u ON u.id = i.user_id {$listWhere} GROUP BY i.type ORDER BY total DESC", $listParams);
    $lastInteractionDate = scalar("SELECT MAX(i.interaction_date) FROM interactions i {$interactionScopeWhere}{$interactionUserWhere}", $interactionScopeParams + $interactionUserParams);
    $editInteractionId = (int) ($_GET['edit_interaction'] ?? 0);
    $editInteraction = null;
    if ($editInteractionId > 0) {
        $editInteraction = rows("SELECT i.*, c.name company_name, c.account_type company_account_type, c.sql_customer_id company_sql_customer_id, c.contact_person, c.phone, c.city, c.district FROM interactions i JOIN companies c ON c.id = i.company_id WHERE i.id = :id{$interactionScopeSql}", $interactionScopeParams + [':id' => $editInteractionId])[0] ?? null;
        if (!$editInteraction) {
            flash('Düzenlenecek görüşme kaydı bulunamadı.', 'danger');
            redirect_to('interactions');
        }
    }
    ?>
    <section class="interaction-hero panel">
        <div>
            <span class="eyebrow">Hızlı görüşme kaydı</span>
            <h2>Cariyi bul, görüşmeyi yaz, geçmişi aynı ekranda takip et</h2>
            <p>Yeni kayıtlar dashboard, raporlar ve cari kartlarına anında yansır. Görünürlük kullanıcının rol kapsamına göre otomatik uygulanır.</p>
        </div>
        <div class="interaction-hero-stats">
            <a href="<?= e(app_url('interactions', ['date_filter' => 'today', 'interaction_user_id' => $selectedInteractionUserId])) ?>"><strong><?= e($todayInteractions) ?></strong><span>Bugün</span></a>
            <a href="<?= e(app_url('interactions', ['date_filter' => 'week', 'interaction_user_id' => $selectedInteractionUserId])) ?>"><strong><?= e($weekInteractions) ?></strong><span>Bu hafta</span></a>
            <a href="<?= e(app_url('interactions', ['date_filter' => 'month', 'interaction_user_id' => $selectedInteractionUserId])) ?>"><strong><?= e($monthInteractions) ?></strong><span>Bu ay</span></a>
        </div>
    </section>

    <section class="interaction-layout">
        <form class="panel form-grid interaction-form" method="post" action="<?= e(app_url('save_interaction')) ?>">
            <div class="wide section-title compact-title">
                <h2><?= $editInteraction ? 'Görüşmeyi düzenle' : 'Yeni görüşme ekle' ?></h2>
                <span class="muted"><?= e($currentUser['full_name'] ?? '') ?> adına kaydedilecek</span>
            </div>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($editInteraction['id'] ?? 0) ?>">
            <input type="hidden" name="return_to" value="interactions">
            <?php if ($usingSqlCustomerPicker): ?>
                <label class="wide">Görüşme yapılan cari
                    <input type="hidden" name="company_id" value="<?= e($editInteraction['company_id'] ?? '') ?>">
                    <input type="hidden" name="sql_customer_id" value="<?= e($editInteraction['sql_customer_id'] ?? $editInteraction['company_sql_customer_id'] ?? '') ?>" data-sql-customer-id>
                    <div class="sql-customer-picker strong-picker" data-sql-customer-picker data-empty-label="Listeden cari seçin">
                        <button class="lookup-button" type="button" data-open-sql-customer-picker><span data-sql-customer-label><?= e($editInteraction ? company_display_label(['name' => $editInteraction['company_name'] ?? '', 'account_type' => $editInteraction['company_account_type'] ?? '']) : 'Listeden cari seçin') ?></span></button>
                        <button class="btn small" type="button" data-clear-sql-customer>Temizle</button>
                    </div>
                </label>
            <?php else: ?>
                <label class="wide" for="interaction_company_lookup">Görüşme yapılan cari
                    <input type="hidden" name="company_id" value="<?= e($editInteraction['company_id'] ?? '') ?>">
                    <input id="interaction_company_lookup" name="company_lookup" list="interaction_company_options" value="<?= e($editInteraction ? company_display_label(['name' => $editInteraction['company_name'] ?? '', 'account_type' => $editInteraction['company_account_type'] ?? '']) : '') ?>" required placeholder="Firma adı veya tür yazın..." autocomplete="off">
                    <datalist id="interaction_company_options">
                        <?php foreach ($interactionCompanies as $company): ?>
                            <option value="<?= e(company_lookup_label($company)) ?>"><?= e(trim(($company['contact_person'] ?? '') . ' ' . ($company['phone'] ?? '') . ' ' . ($company['city'] ?? ''))) ?></option>
                        <?php endforeach; ?>
                    </datalist>
                </label>
            <?php endif; ?>
            <label>Görüşme tarihi <input type="date" name="interaction_date" value="<?= e($editInteraction['interaction_date'] ?? $today) ?>" required></label>
            <label>Görüşme türü
                <select name="type">
                    <?php foreach (interaction_types() as $type): ?>
                        <option value="<?= e($type) ?>"<?= selected($editInteraction['type'] ?? 'Telefon', $type) ?>><?= e($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Görüşme sonucu
                <select name="result">
                    <?php foreach (interaction_results() as $result): ?>
                        <option value="<?= e($result) ?>"<?= selected($editInteraction['result'] ?? 'Olumlu', $result) ?>><?= e($result) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Sonraki takip tarihi <small class="muted">Opsiyonel</small><input type="date" name="next_followup_date" value="<?= e($editInteraction['next_followup_date'] ?? '') ?>"></label>
            <label class="wide">Görüşme notu <textarea name="note" required placeholder="Görüşmenin kısa özeti, talep, itiraz veya sonraki aksiyonu yazın..."><?= e($editInteraction['note'] ?? '') ?></textarea></label>
            <div class="actions wide">
                <button class="btn primary" type="submit"><?= $editInteraction ? 'Görüşmeyi güncelle' : 'Görüşmeyi kaydet' ?></button>
                <?php if ($editInteraction): ?><a class="btn" href="<?= e(app_url('interactions')) ?>">Vazgeç</a><?php endif; ?>
                <a class="btn" href="<?= e(app_url('reports', ['date_filter' => 'today'])) ?>">Bugünün raporu</a>
            </div>
        </form>

        <aside class="panel interaction-side">
            <div class="section-title compact-title">
                <h2>Hızlı okuma</h2>
                <span class="muted"><?= e($totalVisibleInteractions) ?> görünür kayıt</span>
            </div>
            <?php if (count($interactionScopeUsers) > 1): ?>
                <form class="mini-filter-form" method="get">
                    <input type="hidden" name="page" value="interactions">
                    <input type="hidden" name="q" value="<?= e($_GET['q'] ?? '') ?>">
                    <input type="hidden" name="type" value="<?= e($_GET['type'] ?? '') ?>">
                    <input type="hidden" name="result" value="<?= e($_GET['result'] ?? '') ?>">
                    <input type="hidden" name="date_filter" value="<?= e($_GET['date_filter'] ?? '') ?>">
                    <label>Kullanıcı görüşmeleri
                        <select name="interaction_user_id" onchange="this.form.submit()">
                            <option value="0"<?= selected($selectedInteractionUserId, 0) ?>>Tüm kullanıcılar</option>
                            <?php foreach ($interactionScopeUsers as $scopeUser): ?>
                                <option value="<?= e($scopeUser['id']) ?>"<?= selected($selectedInteractionUserId, $scopeUser['id']) ?>><?= e($scopeUser['full_name']) ?> - <?= e(role_label($scopeUser['role'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
            <?php endif; ?>
            <div class="interaction-mini-charts">
                <div>
                    <h3>Sonuç dağılımı</h3>
                    <?php render_bar_list($resultSummary, 'result', 'total'); ?>
                </div>
            </div>
        </aside>
    </section>

    <?php if ($usingSqlCustomerPicker): ?>
        <dialog class="modal sql-customer-dialog" id="sql-customer-dialog" data-search-url="<?= e(app_url('sql_customer_search')) ?>">
            <div class="modal-head">
                <h2>Görüşme yapılacak cariyi seç</h2>
                <button class="btn small" type="button" data-close-dialog>Kapat</button>
            </div>
            <div class="modal-body sql-customer-search">
                <div class="sql-search-row">
                    <input type="search" data-sql-customer-query placeholder="Cari adı veya vergi no ara..." autocomplete="off">
                    <button class="btn primary" type="button" data-sql-customer-search>Ara</button>
                </div>
                <div class="sql-customer-results" data-sql-customer-results>Arama için en az 2 karakter yazın.</div>
            </div>
        </dialog>
    <?php endif; ?>

    <section class="panel interaction-history-panel">
        <div class="section-title">
            <div>
                <h2>Görüşme geçmişi</h2>
                <span class="muted">Son 80 kayıt listelenir. Daha dar sonuç için arama ve dönem filtrelerini kullanın.</span>
            </div>
            <?php date_filter_presets('interactions', array_filter([
                'q' => $_GET['q'] ?? '',
                'type' => $_GET['type'] ?? '',
                'result' => $_GET['result'] ?? '',
                'interaction_user_id' => $selectedInteractionUserId,
            ], static fn($value) => $value !== '')); ?>
        </div>
        <form class="interaction-filters" method="get">
            <input type="hidden" name="page" value="interactions">
            <input type="hidden" name="interaction_user_id" value="<?= e($selectedInteractionUserId) ?>">
            <input name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Cari, not, personel veya telefon ara...">
            <select name="type">
                <option value="">Tüm görüşme türleri</option>
                <?php foreach (interaction_types() as $type): ?>
                    <option value="<?= e($type) ?>"<?= selected($_GET['type'] ?? '', $type) ?>><?= e($type) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="result">
                <option value="">Tüm sonuçlar</option>
                <?php foreach (interaction_results() as $result): ?>
                    <option value="<?= e($result) ?>"<?= selected($_GET['result'] ?? '', $result) ?>><?= e($result) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="date_filter">
                <option value="">Tüm tarihler</option>
                <option value="today"<?= selected($_GET['date_filter'] ?? '', 'today') ?>>Bugün</option>
                <option value="week"<?= selected($_GET['date_filter'] ?? '', 'week') ?>>Bu hafta</option>
                <option value="month"<?= selected($_GET['date_filter'] ?? '', 'month') ?>>Bu ay</option>
            </select>
            <button class="btn primary" type="submit">Listele</button>
        </form>
        <div class="table-wrap compact-table-wrap">
            <table class="compact-table interaction-table">
                <thead>
                    <tr><th>Tarih</th><th>Cari</th><th>Tür</th><th>Sonuç</th><th>Not</th><th>Takip</th><th>Kaydeden</th><th>Aksiyon</th></tr>
                </thead>
                <tbody>
                <?php foreach ($visibleInteractions as $item): ?>
                    <tr>
                        <td><strong><?= e($item['interaction_date']) ?></strong></td>
                        <td><a href="<?= e(app_url('company_view', ['id' => $item['company_id']])) ?>"><?= e($item['company_name']) ?></a><small><?= e(trim(($item['contact_person'] ?? '') . ' ' . ($item['phone'] ?? '')) ?: '') ?></small></td>
                        <td><?= e($item['type']) ?></td>
                        <td><span class="badge soft"><?= e($item['result']) ?></span></td>
                        <td class="truncate-cell"><?= e($item['note']) ?></td>
                        <td><?= e($item['next_followup_date'] ?: '-') ?></td>
                        <td><?= e($item['user_name'] ?? '-') ?></td>
                        <td><a class="btn small" href="<?= e(app_url('interactions', ['edit_interaction' => $item['id']])) ?>">Düzenle</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$visibleInteractions): ?>
                    <tr><td colspan="8" class="empty-state">Bu filtrelerle görüşme kaydı bulunamadı.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
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
    [$companyOppScopeSql, $companyOppScopeParams] = opportunity_visibility_condition('o');
    $opps = rows('SELECT o.*, u.full_name salesperson_name FROM opportunities o LEFT JOIN users u ON u.id = o.salesperson_id WHERE o.company_id = :id' . $companyOppScopeSql . ' ORDER BY o.updated_at DESC', $companyOppScopeParams + [':id' => $id]);
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
                <dt>Cari kodu</dt><dd><?= e($company['account_code'] ?? '') ?></dd>
                <dt>Vergi no</dt><dd><?= e($company['tax_no'] ?? '') ?></dd>
                <dt>Vergi dairesi</dt><dd><?= e($company['tax_office'] ?? '') ?></dd>
                <dt>Bakiye</dt><dd><?= e(money($company['balance_amount'] ?? 0)) ?> <?= e($company['balance_side'] ?? '') ?></dd>
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
    $usingSqlCustomerPicker = company_source() === 'sqlserver';
    $taskCompanies = [];
    if (!$usingSqlCustomerPicker) {
        $companyWhere = ' WHERE 1 = 1';
        $companyParams = [];
        if (!can_view_all()) {
            $companyWhere .= ' AND (responsible_user_id = :uid OR created_by = :uid)';
            $companyParams[':uid'] = current_user()['id'];
        }
        $taskCompanies = rows("SELECT id, name, account_code, sql_customer_id FROM companies {$companyWhere} ORDER BY name LIMIT 500", $companyParams);
    }
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
        <?php if ($usingSqlCustomerPicker): ?>
            <label class="wide">İlgili cari <small class="muted">Opsiyonel</small>
                <input type="hidden" name="company_id" value="">
                <input type="hidden" name="sql_customer_id" data-sql-customer-id>
                <div class="sql-customer-picker" data-sql-customer-picker>
                    <button class="lookup-button" type="button" data-open-sql-customer-picker><span data-sql-customer-label>Cari seçmeden de kaydedebilirsiniz</span></button>
                    <button class="btn small" type="button" data-clear-sql-customer>Temizle</button>
                </div>
            </label>
        <?php else: ?>
            <label>İlgili cari
                <select name="company_id">
                    <option value="">Bağlantı yok</option>
                    <?php foreach ($taskCompanies as $company): ?>
                        <option value="<?= e($company['id']) ?>"><?= e(company_lookup_label($company)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <label class="wide">Açıklama <textarea name="description"></textarea></label>
        <div class="actions wide">
            <button class="btn primary" type="submit">İşi ata</button>
            <a class="btn" href="<?= e(app_url('followups')) ?>">Takip listesine dön</a>
        </div>
    </form>
    <?php if ($usingSqlCustomerPicker): ?>
        <dialog class="modal sql-customer-dialog" id="sql-customer-dialog" data-search-url="<?= e(app_url('sql_customer_search')) ?>">
            <div class="modal-head">
                <h2>SQL cariden seç</h2>
                <button class="btn small" type="button" data-close-dialog>Kapat</button>
            </div>
            <div class="modal-body sql-customer-search">
                <div class="sql-search-row">
                    <input type="search" data-sql-customer-query placeholder="Cari adı, kodu veya vergi no ara..." autocomplete="off">
                    <button class="btn primary" type="button" data-sql-customer-search>Ara</button>
                </div>
                <div class="sql-customer-results" data-sql-customer-results>Arama için en az 2 karakter yazın.</div>
            </div>
        </dialog>
    <?php endif; ?>
    <?php
    render_footer();
    exit;
}

if ($page === 'followups') {
    render_header('Takip Listesi');
    $taskUsers = active_users();
    $today = date('Y-m-d');
    $currentUserId = (int) current_user()['id'];
    $usingSqlCustomerPicker = company_source() === 'sqlserver';
    $taskCompanies = [];
    if (!$usingSqlCustomerPicker) {
        $taskCompanyWhere = ' WHERE 1 = 1';
        $taskCompanyParams = [];
        [$taskCompanyScopeSql, $taskCompanyScopeParams] = owned_company_condition('c');
        $taskCompanyWhere .= $taskCompanyScopeSql;
        $taskCompanyParams += $taskCompanyScopeParams;
        $taskCompanies = rows("SELECT c.id, c.name, c.account_code, c.account_type, c.sql_customer_id FROM companies c {$taskCompanyWhere} ORDER BY c.name LIMIT 300", $taskCompanyParams);
    }
    $editTaskId = (int) ($_GET['edit_task'] ?? 0);
    $editTask = null;
    if ($editTaskId > 0) {
        if (!user_can_access_task($editTaskId)) {
            http_response_code(403);
            exit('Bu işi düzenleme yetkiniz yok.');
        }
        $editTask = rows('SELECT t.*, c.name company_name, c.account_code company_account_code FROM tasks t LEFT JOIN companies c ON c.id = t.company_id WHERE t.id = :id', [':id' => $editTaskId])[0] ?? null;
        if (!$editTask) {
            http_response_code(404);
            exit('İş bulunamadı.');
        }
    }

    [$taskScopeSql, $taskScopeParams] = task_visibility_condition('t');
    $scopeWhere = ' WHERE 1 = 1' . $taskScopeSql;
    $taskScopeUsers = task_scope_users();
    $taskRoleOptions = role_options();
    $taskScopeRoles = [];
    foreach ($taskScopeUsers as $scopeUser) {
        $scopeRole = normalize_role($scopeUser['role'] ?? '');
        if (isset($taskRoleOptions[$scopeRole])) {
            $taskScopeRoles[$scopeRole] = $taskRoleOptions[$scopeRole];
        }
    }
    $selectedTaskRole = normalize_role($_GET['task_role'] ?? '');
    if ($selectedTaskRole !== '' && !array_key_exists($selectedTaskRole, $taskScopeRoles)) {
        $selectedTaskRole = '';
    }
    $allowedTaskUserIds = array_map(static fn($user) => (int) $user['id'], $taskScopeUsers);
    $selectedTaskUserId = (int) ($_GET['task_user_id'] ?? $currentUserId);
    if (!in_array($selectedTaskUserId, $allowedTaskUserIds, true)) {
        $selectedTaskUserId = $currentUserId;
    }
    $selectedTaskUser = null;
    foreach ($taskScopeUsers as $scopeUser) {
        if ((int) $scopeUser['id'] === $selectedTaskUserId) {
            $selectedTaskUser = $scopeUser;
            break;
        }
    }
    $taskFilterWhere = '';
    $taskFilterParams = [];
    $assignedToFilterWhere = '';
    $assignedByFilterWhere = '';
    $taskFilterLabel = $selectedTaskUser['full_name'] ?? 'Seçili kullanıcı';
    if ($selectedTaskRole !== '') {
        $rolePlaceholders = [];
        foreach (role_database_values([$selectedTaskRole]) as $index => $roleValue) {
            $param = ':task_filter_role_' . $index;
            $rolePlaceholders[] = $param;
            $taskFilterParams[$param] = $roleValue;
        }
        $roleInSql = implode(', ', $rolePlaceholders);
        $taskFilterWhere = " AND EXISTS (SELECT 1 FROM users task_filter_user WHERE task_filter_user.id IN (t.assigned_by, t.assigned_to) AND task_filter_user.role IN ({$roleInSql}))";
        $assignedToFilterWhere = " AND EXISTS (SELECT 1 FROM users task_filter_to WHERE task_filter_to.id = t.assigned_to AND task_filter_to.role IN ({$roleInSql})) AND t.assigned_by <> t.assigned_to";
        $assignedByFilterWhere = " AND EXISTS (SELECT 1 FROM users task_filter_by WHERE task_filter_by.id = t.assigned_by AND task_filter_by.role IN ({$roleInSql})) AND t.assigned_by <> t.assigned_to";
        $taskFilterLabel = ($taskRoleOptions[$selectedTaskRole] ?? role_label($selectedTaskRole)) . ' rolü';
    } else {
        $taskFilterWhere = ' AND (t.assigned_by = :selected_task_user_id OR t.assigned_to = :selected_task_user_id)';
        $taskFilterParams = [':selected_task_user_id' => $selectedTaskUserId];
        $assignedToFilterWhere = ' AND t.assigned_to = :selected_task_user_id AND t.assigned_by <> :selected_task_user_id';
        $assignedByFilterWhere = ' AND t.assigned_by = :selected_task_user_id AND t.assigned_to <> :selected_task_user_id';
    }
    $openTaskTotal = (int) scalar("SELECT COUNT(*) FROM tasks t {$scopeWhere}{$taskFilterWhere} AND t.status = 'Açık'", $taskScopeParams + $taskFilterParams);
    $assignedToSelectedTotal = (int) scalar("SELECT COUNT(*) FROM tasks t {$scopeWhere}{$assignedToFilterWhere} AND t.status = 'Açık'", $taskScopeParams + $taskFilterParams);
    $assignedBySelectedTotal = (int) scalar("SELECT COUNT(*) FROM tasks t {$scopeWhere}{$assignedByFilterWhere} AND t.status = 'Açık'", $taskScopeParams + $taskFilterParams);
    $overdueTaskParams = $taskScopeParams + $taskFilterParams + [':today' => $today];
    $overdueTaskTotal = (int) scalar("SELECT COUNT(*) FROM tasks t {$scopeWhere}{$taskFilterWhere} AND t.status = 'Açık' AND t.due_date IS NOT NULL AND date(t.due_date) < :today", $overdueTaskParams);
    $taskFilterHidden = $selectedTaskRole !== '' ? ['task_role' => $selectedTaskRole] : ['task_user_id' => $selectedTaskUserId];
    $taskFilterQuery = [];
    foreach (['q', 'status', 'date_filter', 'date_from', 'date_to'] as $filterName) {
        if (isset($_GET[$filterName]) && $_GET[$filterName] !== '') {
            $taskFilterQuery[$filterName] = $_GET[$filterName];
        }
    }
    $visibleTaskRoleOptions = [];
    foreach ($taskRoleOptions as $roleValue => $roleLabel) {
        if (array_key_exists($roleValue, $taskScopeRoles)) {
            $visibleTaskRoleOptions[$roleValue] = $roleLabel;
        }
    }

    ?>
    <section class="task-command panel">
        <div>
            <span class="eyebrow">Takip ve iş atama</span>
            <h2>İşleri tek listeden yönet</h2>
            <p>Takip gir, işi bir personele ata ve atadığın iş üst yetkilide olsa bile sürecini buradan izle.</p>
        </div>
        <div class="task-stats">
            <a href="<?= e(app_url('followups', ['status' => 'Açık'] + $taskFilterHidden)) ?>"><strong><?= e($openTaskTotal) ?></strong><span>Açık iş</span></a>
            <a href="<?= e(app_url('followups', ['status' => 'Açık'] + $taskFilterHidden)) ?>"><strong><?= e($assignedToSelectedTotal) ?></strong><span>Atanan</span></a>
            <a href="<?= e(app_url('followups', ['status' => 'Açık'] + $taskFilterHidden)) ?>"><strong><?= e($assignedBySelectedTotal) ?></strong><span>Atadığı</span></a>
            <a href="<?= e(app_url('followups', ['date_filter' => 'custom', 'date_to' => $today, 'status' => 'Açık'] + $taskFilterHidden)) ?>"><strong><?= e($overdueTaskTotal) ?></strong><span>Geciken</span></a>
        </div>
    </section>

    <details class="task-entry-toggle"<?= $editTask ? ' open' : '' ?>>
        <summary>
            <span>
                <strong><?= $editTask ? 'İşi düzenle' : 'Takip gir / iş ata' ?></strong>
                <small><?= $editTask ? 'Seçili takip kaydını güncelleyin.' : 'Yeni takip veya iş ataması ekleyin.' ?></small>
            </span>
            <span class="summary-action"><?= $editTask ? 'Açık' : 'Aç' ?></span>
        </summary>
    <section class="grid-two task-workspace">
        <form class="panel form-grid task-create-panel" method="post" action="<?= e(app_url('save_task')) ?>">
            <div class="wide section-title compact-title">
                <h2><?= $editTask ? 'İşi düzenle' : 'Takip gir / iş ata' ?></h2>
                <span class="muted"><?= $editTask ? 'Başlık, atanan kişi, termin ve durumu güncelle.' : 'Herkes herkese iş atayabilir.' ?></span>
            </div>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e($editTask['id'] ?? 0) ?>">
            <label>İş konusu <input name="title" value="<?= e($editTask['title'] ?? '') ?>" required placeholder="Örn. Bayi evraklarını kontrol et"></label>
            <label>Atanacak kişi
                <select name="assigned_to" required>
                    <?php foreach ($taskUsers as $user): ?>
                        <option value="<?= e($user['id']) ?>"<?= selected($editTask['assigned_to'] ?? $currentUserId, $user['id']) ?>><?= e($user['full_name']) ?> · <?= e(role_label($user['role'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Termin tarihi <input type="date" name="due_date" value="<?= e($editTask['due_date'] ?? '') ?>"></label>
            <?php if ($usingSqlCustomerPicker): ?>
                <label class="wide">İlgili cari <small class="muted">Opsiyonel</small>
                    <input type="hidden" name="company_id" value="<?= e($editTask['company_id'] ?? '') ?>">
                    <input type="hidden" name="sql_customer_id" value="<?= e($editTask['sql_customer_id'] ?? '') ?>" data-sql-customer-id>
                    <div class="sql-customer-picker" data-sql-customer-picker>
                        <button class="lookup-button" type="button" data-open-sql-customer-picker><span data-sql-customer-label><?= e(!empty($editTask['company_name']) ? $editTask['company_name'] : 'Cari seçmeden de kaydedebilirsiniz') ?></span></button>
                        <button class="btn small" type="button" data-clear-sql-customer>Temizle</button>
                    </div>
                </label>
            <?php else: ?>
                <label>İlgili cari
                    <select name="company_id">
                        <option value="">Bağlantı yok</option>
                        <?php foreach ($taskCompanies as $company): ?>
                            <option value="<?= e($company['id']) ?>"<?= selected($editTask['company_id'] ?? '', $company['id']) ?>><?= e(company_lookup_label($company)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <?php if ($editTask): ?>
                <label>Durum
                    <select name="status">
                        <?php foreach (task_statuses() as $status): ?>
                            <option value="<?= e($status) ?>"<?= selected($editTask['status'], $status) ?>><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endif; ?>
            <label class="wide">Açıklama <textarea name="description" placeholder="Beklenen aksiyon, not veya takip detayı"><?= e($editTask['description'] ?? '') ?></textarea></label>
            <div class="actions wide">
                <?php if ($editTask): ?><a class="btn" href="<?= e(app_url('followups')) ?>">Yeni takip gir</a><?php endif; ?>
                <button class="btn primary" type="submit"><?= $editTask ? 'İşi güncelle' : 'Kaydet ve ata' ?></button>
            </div>
        </form>

        <article class="panel task-rules-panel">
            <div class="section-title compact-title">
                <h2>Görünürlük</h2>
            </div>
            <div class="role-flow" aria-label="Role göre takip filtresi">
                <?php foreach ($visibleTaskRoleOptions as $roleValue => $roleLabel): ?>
                    <a class="<?= e($selectedTaskRole === $roleValue ? 'active' : '') ?>" href="<?= e(app_url('followups', $taskFilterQuery + ['task_role' => $roleValue])) ?>"><?= e($roleLabel) ?></a>
                <?php endforeach; ?>
            </div>
            <p class="muted">Bayi Kanal Uzmanı ve Saha Satış yalnızca kendilerine atanan veya kendilerinin atadığı işleri görür. Üst roller kendi kapsamındaki ekip işlerini de takip eder.</p>
            <?php if (count($taskScopeUsers) > 1): ?>
                <form class="mini-filter-form" method="get">
                    <input type="hidden" name="page" value="followups">
                    <input type="hidden" name="q" value="<?= e($_GET['q'] ?? '') ?>">
                    <input type="hidden" name="status" value="<?= e($_GET['status'] ?? '') ?>">
                    <input type="hidden" name="date_filter" value="<?= e($_GET['date_filter'] ?? '') ?>">
                    <input type="hidden" name="date_from" value="<?= e($_GET['date_from'] ?? '') ?>">
                    <input type="hidden" name="date_to" value="<?= e($_GET['date_to'] ?? '') ?>">
                    <label>Kullanıcı takipleri
                        <select name="task_user_id" onchange="this.form.submit()">
                            <?php foreach ($taskScopeUsers as $scopeUser): ?>
                                <option value="<?= e($scopeUser['id']) ?>"<?= selected($selectedTaskUserId, $scopeUser['id']) ?>><?= e($scopeUser['full_name']) ?> - <?= e(role_label($scopeUser['role'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </form>
            <?php endif; ?>
            <div class="legend-list">
                <span><i class="legend-dot mine"></i>Başkası tarafından atanan</span>
                <span><i class="legend-dot delegated"></i>Başkasına atadığı iş</span>
                <span><i class="legend-dot overdue-dot"></i>Geciken</span>
            </div>
        </article>
    </section>
    </details>
    <?php if ($usingSqlCustomerPicker): ?>
        <dialog class="modal sql-customer-dialog" id="sql-customer-dialog" data-search-url="<?= e(app_url('sql_customer_search')) ?>">
            <div class="modal-head">
                <h2>SQL cariden seç</h2>
                <button class="btn small" type="button" data-close-dialog>Kapat</button>
            </div>
            <div class="modal-body sql-customer-search">
                <div class="sql-search-row">
                    <input type="search" data-sql-customer-query placeholder="Cari adı, kodu veya vergi no ara..." autocomplete="off">
                    <button class="btn primary" type="button" data-sql-customer-search>Ara</button>
                </div>
                <div class="sql-customer-results" data-sql-customer-results>Arama için en az 2 karakter yazın.</div>
            </div>
        </dialog>
    <?php endif; ?>

    <?php
        $listWhere = $scopeWhere . $taskFilterWhere;
        $listParams = $taskScopeParams + $taskFilterParams;
        if (!empty($_GET['q'])) {
            $listWhere .= ' AND (t.title LIKE :q OR t.description LIKE :q OR c.name LIKE :q OR CAST(t.sql_customer_id AS TEXT) LIKE :q OR assigner.full_name LIKE :q OR assignee.full_name LIKE :q)';
            $listParams[':q'] = '%' . $_GET['q'] . '%';
        }
        if (!empty($_GET['status'])) {
            $listWhere .= ' AND t.status = :status';
            $listParams[':status'] = $_GET['status'];
        }
        apply_date_filter($listWhere, $listParams, 't.due_date', $_GET);
        filter_bar('followups', [
            'status' => ['_label' => 'Durum', 'items' => array_combine(task_statuses(), task_statuses())],
        ], true, $taskFilterHidden);
        $items = rows("SELECT t.*, c.name company_name, c.account_code company_account_code, assigner.full_name assigned_by_name, assignee.full_name assigned_to_name, assigner.role assigned_by_role, assignee.role assigned_to_role FROM tasks t LEFT JOIN companies c ON c.id = t.company_id LEFT JOIN users assigner ON assigner.id = t.assigned_by LEFT JOIN users assignee ON assignee.id = t.assigned_to {$listWhere} ORDER BY CASE t.status WHEN 'Açık' THEN 0 ELSE 1 END, CASE WHEN t.due_date IS NOT NULL AND date(t.due_date) < :sort_today AND t.status = 'Açık' THEN 0 ELSE 1 END, COALESCE(t.due_date, '9999-12-31'), t.created_at DESC", $listParams + [':sort_today' => $today]);
    ?>
    <section class="panel task-list-panel">
        <div class="section-title">
            <h2>İş listesi</h2>
            <span class="muted"><?= e($openTaskTotal) ?> açık iş · <?= e($taskFilterLabel) ?></span>
        </div>
        <div class="table-wrap task-table-wrap">
            <table class="task-table">
                <thead>
                    <tr>
                        <th>İş</th>
                        <th>Cari</th>
                        <th>Atayan</th>
                        <th>Atanan</th>
                        <th>Termin</th>
                        <th>Durum</th>
                        <th>Aksiyon</th>
                    </tr>
                </thead>
                <tbody>
            <?php foreach ($items as $row): ?>
                <?php
                    $isOpen = $row['status'] === 'Açık';
                    $isOverdue = $isOpen && $row['due_date'] && strtotime((string) $row['due_date']) < strtotime($today);
                    $taskContextUserId = $selectedTaskRole !== '' ? $currentUserId : $selectedTaskUserId;
                    $isSelfAssigned = (int) $row['assigned_to'] === $taskContextUserId && (int) $row['assigned_by'] === $taskContextUserId;
                    $isAssignedToMe = (int) $row['assigned_to'] === $taskContextUserId && !$isSelfAssigned;
                    $isAssignedByMe = (int) $row['assigned_by'] === $taskContextUserId && !$isSelfAssigned;
                    $cardClasses = ['task-row'];
                    if ($isAssignedToMe) {
                        $cardClasses[] = 'assigned-to-me';
                    }
                    if ($isAssignedByMe) {
                        $cardClasses[] = 'assigned-by-me';
                    }
                    if ($isOverdue) {
                        $cardClasses[] = 'overdue-task';
                    }
                    if (!$isOpen) {
                        $cardClasses[] = 'completed-task';
                    }
                    $contextLabel = $isSelfAssigned ? 'Açık takip' : ($isAssignedToMe ? 'Başkasından atanan iş' : ($isAssignedByMe ? 'Başkasına atadığı iş' : 'Ekip işi'));
                    $companyDisplay = $row['company_name'] ?: '-';
                ?>
                <tr class="<?= e(implode(' ', $cardClasses)) ?>">
                    <td class="task-title-cell">
                        <span class="task-context"><?= e($contextLabel) ?></span>
                        <strong><?= e($row['title']) ?></strong>
                        <?php if ($row['description']): ?><small><?= e($row['description']) ?></small><?php endif; ?>
                    </td>
                    <td><?= e($companyDisplay) ?></td>
                    <td><?= e($row['assigned_by_name'] ?? 'Silinmiş kullanıcı') ?><small><?= e(role_label($row['assigned_by_role'] ?? '')) ?></small></td>
                    <td><?= e($row['assigned_to_name'] ?? 'Silinmiş kullanıcı') ?><small><?= e(role_label($row['assigned_to_role'] ?? '')) ?></small></td>
                    <td><?= e($row['due_date'] ?: '-') ?><?php if ($isOverdue): ?><span class="badge danger">Gecikmiş</span><?php endif; ?></td>
                    <td><span class="badge <?= $row['status'] === 'Tamamlandı' ? 'success' : 'soft' ?>"><?= e($row['status']) ?></span></td>
                    <td class="task-actions-cell">
                        <a class="btn small" href="<?= e(app_url('followups', ['edit_task' => $row['id']])) ?>">Düzenle</a>
                        <?php if ($row['status'] !== 'Tamamlandı' && can_complete_task($row)): ?>
                            <form method="post" action="<?= e(app_url('complete_task')) ?>" class="inline-form task-complete-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= e($row['id']) ?>">
                                <input name="completion_note" placeholder="Tamamlama açıklaması" aria-label="Tamamlama açıklaması">
                                <button class="btn small" type="submit">Tamamla</button>
                            </form>
                        <?php elseif ($row['status'] === 'Tamamlandı'): ?>
                            <span class="muted">Tamamlandı: <?= e($row['completed_at'] ?: '-') ?></span>
                            <?php if (!empty($row['completion_note'])): ?><small><?= e($row['completion_note']) ?></small><?php endif; ?>
                        <?php else: ?>
                            <span class="muted">Tamamlama yetkisi atanan kişide.</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!$items): ?>
                <p class="empty-state">Bu filtrelerle görünen iş kaydı yok.</p>
            <?php endif; ?>
        </div>
    </section>
    <?php
    render_footer();
    exit;
}

if ($page === 'followups_calendar_legacy') {
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
                            <?php if (!empty($task['company_name'])): ?><small><?= e($task['company_name']) ?></small><?php endif; ?>
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
                        <option value="<?= e($company['id']) ?>"><?= e(company_lookup_label($company)) ?></option>
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
                <thead><tr><th>İş</th><th>İlgili cari</th><th>Atayan</th><th>Atanan</th><th>Termin</th><th>Durum</th><th>Aksiyon</th></tr></thead>
                <tbody>
                <?php foreach ($items as $row): ?>
                    <tr class="<?= $row['status'] === 'Açık' && $row['due_date'] && strtotime($row['due_date']) < strtotime(date('Y-m-d')) ? 'overdue' : '' ?>">
                        <td><strong><?= e($row['title']) ?></strong><?php if ($row['description']): ?><small><?= e($row['description']) ?></small><?php endif; ?></td>
                        <td><?= e($row['company_name'] ?? '-') ?></td>
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
    [$opportunityScopeSql, $opportunityScopeParams] = opportunity_visibility_condition('o');
    $where .= $opportunityScopeSql;
    $params += $opportunityScopeParams;
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
    $viewMode = (string) ($_GET['view'] ?? 'kanban');
    if (!in_array($viewMode, ['kanban', 'list'], true)) {
        $viewMode = 'kanban';
    }
    apply_date_filter($where, $params, 'o.expected_close_date', $_GET);
    filter_bar('opportunities', [
        'account_type' => ['_label' => 'Cari türü', 'items' => array_combine(company_account_types(), company_account_types())],
        'stage_group' => ['_label' => 'Fırsat durumu', 'items' => ['open' => 'Açık fırsatlar']],
        'stage' => ['_label' => 'Aşama', 'items' => array_combine(opportunity_stages(), opportunity_stages())],
    ], true, ['view' => $viewMode]);
    $items = rows("SELECT o.*, c.name company_name, c.account_type company_account_type, c.sql_customer_id company_sql_customer_id, u.full_name salesperson_name FROM opportunities o JOIN companies c ON c.id = o.company_id LEFT JOIN users u ON u.id = o.salesperson_id {$where} ORDER BY o.updated_at DESC", $params);
    $itemsByStage = [];
    foreach ($items as $item) {
        $itemsByStage[$item['stage']][] = $item;
    }
    $viewParams = $_GET;
    unset($viewParams['page']);
    $kanbanViewParams = $viewParams + ['view' => 'kanban'];
    $listViewParams = $viewParams + ['view' => 'list'];
    $kanbanViewParams['view'] = 'kanban';
    $listViewParams['view'] = 'list';
    ?>
    <div class="toolbar">
        <a class="btn primary" href="<?= e(app_url('opportunity_form')) ?>">Yeni satış fırsatı</a>
        <div class="view-switch" aria-label="Görünüm seçimi">
            <a class="<?= e($viewMode === 'kanban' ? 'active' : '') ?>" href="<?= e(app_url('opportunities', $kanbanViewParams)) ?>">Kanban</a>
            <a class="<?= e($viewMode === 'list' ? 'active' : '') ?>" href="<?= e(app_url('opportunities', $listViewParams)) ?>">Liste</a>
        </div>
        <?php if ($viewMode === 'list'): ?>
            <button class="btn" type="button" data-export-table="#opportunities-table" data-filename="satis-firsatlari.csv">CSV indir</button>
        <?php endif; ?>
    </div>
    <?php if ($viewMode === 'kanban'): ?>
        <section class="panel">
            <div class="section-title"><h2>Kanban görünümü</h2><span class="muted">Aşamaya göre açık ve kapanan fırsatlar</span></div>
            <div class="kanban-board" data-opportunity-kanban data-update-url="<?= e(app_url('update_opportunity_stage')) ?>" data-csrf-token="<?= e(csrf_token()) ?>">
                <?php foreach (opportunity_stages() as $stage): ?>
                    <section class="kanban-column" data-kanban-stage="<?= e($stage) ?>">
                        <h3><?= e($stage) ?> <span class="badge soft"><?= e(count($itemsByStage[$stage] ?? [])) ?></span></h3>
                        <?php foreach (array_slice($itemsByStage[$stage] ?? [], 0, 6) as $card): ?>
                            <a class="kanban-card" href="<?= e(app_url('opportunity_form', ['id' => $card['id']])) ?>" draggable="true" data-opportunity-card data-opportunity-id="<?= e($card['id']) ?>" data-current-stage="<?= e($stage) ?>">
                                <strong><?= e($card['company_name']) ?></strong>
                                <span><?= e($card['product_service']) ?></span>
                                <small><?= e(money($card['estimated_amount'])) ?> · <?= e($card['expected_close_date'] ?: '-') ?></small>
                            </a>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        </section>
    <?php else: ?>
    <section class="panel">
        <div class="section-title"><h2>Liste görünümü</h2><span class="muted"><?= e(count($items)) ?> fırsat</span></div>
        <div class="table-wrap"><table class="opportunities-table" id="opportunities-table">
            <thead><tr><th>Cari</th><th>Cari türü</th><th>Satışçı</th><th>Ürün / hizmet</th><th>Tutar</th><th>Aşama</th><th>Kapanış</th><th>Aksiyon</th></tr></thead>
            <tbody>
            <?php foreach ($items as $row): ?>
                <tr>
                    <td><a href="<?= e(app_url('company_view', ['id' => $row['company_id']])) ?>"><?= e($row['company_name']) ?></a></td>
                    <td><span class="badge soft"><?= e($row['company_account_type'] ?? 'İş Ortağı') ?></span></td>
                    <td><?= e($row['salesperson_name']) ?></td>
                    <td><?= e($row['product_service']) ?></td>
                    <td><?= e(money($row['estimated_amount'])) ?></td>
                    <td><?= e($row['stage']) ?></td>
                    <td><?= e($row['expected_close_date']) ?></td>
                    <td class="actions-cell">
                        <a class="btn small" href="<?= e(app_url('opportunity_form', ['id' => $row['id']])) ?>">Düzenle</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
    <?php endif; ?>
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
            if (!user_can_access_opportunity($id)) {
                http_response_code(403);
                exit('Bu satış fırsatını düzenleme yetkiniz yok.');
            }
            require_company_access((int) $opp['company_id']);
        }
    }
    $selectedCompanyId = (int) ($opp['company_id'] ?? ($_GET['company_id'] ?? 0));
    $selectedCompany = null;
    if ($selectedCompanyId > 0 && user_can_access_company($selectedCompanyId)) {
        $selectedCompany = rows('SELECT c.id, c.sql_customer_id, c.name, c.account_code, c.account_type FROM companies c WHERE c.id = :id', [
            ':id' => $selectedCompanyId,
        ])[0] ?? null;
    }
    render_header($opp ? 'Fırsat Düzenle' : 'Yeni Satış Fırsatı');
    ?>
    <form class="panel form-grid" method="post" action="<?= e(app_url('save_opportunity')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= e($opp['id'] ?? 0) ?>">
        <div class="form-field company-autocomplete-field" data-company-autocomplete data-search-url="<?= e(app_url('company_lookup_search')) ?>">
            <label for="company_lookup">İlgili cari</label>
            <div class="field-action-row">
                <div class="company-autocomplete-box">
                    <input type="hidden" name="company_id" value="<?= e($selectedCompany['id'] ?? '') ?>" data-company-id>
                    <input type="hidden" name="sql_customer_id" value="<?= e($selectedCompany['sql_customer_id'] ?? '') ?>" data-company-sql-id>
                    <input id="company_lookup" name="company_lookup" value="<?= e($selectedCompany ? company_display_label($selectedCompany) : '') ?>" placeholder="En az 3 harf yazın; firma adı, telefon veya il ara..." required autocomplete="off" data-company-search-input>
                    <div class="company-autocomplete-results" data-company-search-results hidden></div>
                </div>
                <a class="btn" href="<?= e(app_url('company_form', ['return_to' => 'opportunity_form'])) ?>">Yeni cari ekle</a>
            </div>
            <small class="field-hint">3 karakterden sonra arama otomatik başlar. Listeden cari seçince fırsat bu cariye bağlanır.</small>
        </div>
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
    $usingSqlServerCompanies = company_source() === 'sqlserver';
    $interactionWhere = ' WHERE 1 = 1';
    [$interactionScopeSql, $interactionScopeParams] = interaction_visibility_condition('i');
    $interactionWhere .= $interactionScopeSql;
    $interactionParams = $interactionScopeParams;
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
    [$staffScopeSql, $staffScopeParams] = user_visibility_condition('u');
    $staffWhere .= $staffScopeSql;
    $staffParams = $staffScopeParams;
    if ($reportFrom !== '') {
        $staffJoin .= ' AND date(i.interaction_date) >= :staff_date_from';
        $staffParams[':staff_date_from'] = $reportFrom;
    }
    if ($reportTo !== '') {
        $staffJoin .= ' AND date(i.interaction_date) <= :staff_date_to';
        $staffParams[':staff_date_to'] = $reportTo;
    }
    $staff = rows("SELECT u.full_name, COUNT(i.id) total FROM users u {$staffJoin} {$staffWhere} GROUP BY u.id ORDER BY total DESC, u.full_name", $staffParams);
    $daily = rows("SELECT i.interaction_date, c.name company_name, c.account_type company_account_type, u.full_name user_name, i.type, i.result, i.note FROM interactions i JOIN companies c ON c.id = i.company_id LEFT JOIN users u ON u.id = i.user_id {$interactionWhere} ORDER BY i.interaction_date DESC LIMIT 100", $interactionParams);
    $companyScope = ' WHERE 1 = 1';
    [$companyScopeSql, $companyScopeParams] = owned_company_condition('c');
    $companyScope .= $companyScopeSql;
    $companyParams = $companyScopeParams;
    if ($selectedAccountType !== '') {
        $companyScope .= ' AND c.account_type = :company_account_type';
        $companyParams[':company_account_type'] = $selectedAccountType;
    }
    $statusReport = rows("SELECT c.status, COUNT(*) total FROM companies c {$companyScope} GROUP BY c.status ORDER BY total DESC", $companyParams);
    if ($usingSqlServerCompanies) {
        $typeReport = array_map(static function (array $row): array {
            return [
                'account_type' => $row['CustomerTypeName'] ?: sql_customer_type_label((int) ($row['CustomerTypeId'] ?? 0)),
                'total' => (int) ($row['total'] ?? 0),
            ];
        }, bilnex_customer_reader()->countActiveCustomersByType());
        if ($selectedAccountType !== '') {
            $typeReport = array_values(array_filter($typeReport, static fn(array $row): bool => normalize_company_account_type($row['account_type'] ?? '') === $selectedAccountType));
        }
    } else {
        $typeReport = rows("SELECT c.account_type, COUNT(*) total FROM companies c {$companyScope} GROUP BY c.account_type ORDER BY total DESC", $companyParams);
    }
    $oppWhere = ' WHERE 1 = 1';
    [$reportOppScopeSql, $reportOppScopeParams] = opportunity_visibility_condition('o');
    $oppWhere .= $reportOppScopeSql;
    $oppParams = $reportOppScopeParams;
    if ($selectedAccountType !== '') {
        $oppWhere .= ' AND EXISTS (SELECT 1 FROM companies c WHERE c.id = o.company_id AND c.account_type = :opp_account_type)';
        $oppParams[':opp_account_type'] = $selectedAccountType;
    }
    $oppReport = rows("SELECT o.stage, COUNT(*) total, COALESCE(SUM(o.estimated_amount), 0) amount FROM opportunities o {$oppWhere} GROUP BY o.stage ORDER BY total DESC", $oppParams);
    $taskWhere = " WHERE t.status = 'Açık'";
    [$reportTaskScopeSql, $reportTaskScopeParams] = task_visibility_condition('t');
    $taskWhere .= $reportTaskScopeSql;
    $taskParams = $reportTaskScopeParams;
    if ($selectedAccountType !== '') {
        $taskWhere .= ' AND EXISTS (SELECT 1 FROM companies c WHERE c.id = t.company_id AND c.account_type = :task_account_type)';
        $taskParams[':task_account_type'] = $selectedAccountType;
    }
    $overdue = rows("SELECT t.id, t.title, t.due_date, c.name company_name, c.account_type, assignee.full_name assigned_to_name FROM tasks t LEFT JOIN companies c ON c.id = t.company_id LEFT JOIN users assignee ON assignee.id = t.assigned_to {$taskWhere} AND t.due_date IS NOT NULL AND date(t.due_date) < :today ORDER BY t.due_date LIMIT 12", $taskParams + [':today' => $today]);
    $interactionTrend = rows("SELECT date(i.interaction_date) day, COUNT(*) total FROM interactions i {$interactionWhere} GROUP BY date(i.interaction_date) ORDER BY day DESC LIMIT 14", $interactionParams);
    $interactionTrend = array_reverse($interactionTrend);
    $resultReport = rows("SELECT i.result, COUNT(*) total FROM interactions i {$interactionWhere} GROUP BY i.result ORDER BY total DESC", $interactionParams);
    $totalInteractions = (int) scalar("SELECT COUNT(*) FROM interactions i {$interactionWhere}", $interactionParams);
    $totalCompanies = $usingSqlServerCompanies
        ? bilnex_customer_reader()->countActiveCustomers($selectedAccountType !== '' ? company_account_type_sql_id($selectedAccountType) : null)
        : (int) scalar("SELECT COUNT(*) FROM companies c {$companyScope}", $companyParams);
    $totalOverdue = (int) scalar("SELECT COUNT(*) FROM tasks t {$taskWhere} AND t.due_date IS NOT NULL AND date(t.due_date) < :today", $taskParams + [':today' => $today]);
    $openOppCount = (int) scalar("SELECT COUNT(*) FROM opportunities o {$oppWhere} AND o.stage NOT IN ('Kazanıldı', 'Kaybedildi')", $oppParams);
    $wonAmount = (float) scalar("SELECT COALESCE(SUM(o.estimated_amount), 0) FROM opportunities o {$oppWhere} AND o.stage = 'Kazanıldı'", $oppParams);
    $lostAmount = (float) scalar("SELECT COALESCE(SUM(o.estimated_amount), 0) FROM opportunities o {$oppWhere} AND o.stage = 'Kaybedildi'", $oppParams);
    $openOppAmount = (float) scalar("SELECT COALESCE(SUM(o.estimated_amount), 0) FROM opportunities o {$oppWhere} AND o.stage NOT IN ('Kazanıldı', 'Kaybedildi')", $oppParams);
    $wonCount = (int) scalar("SELECT COUNT(*) FROM opportunities o {$oppWhere} AND o.stage = 'Kazanıldı'", $oppParams);
    $closedCount = (int) scalar("SELECT COUNT(*) FROM opportunities o {$oppWhere} AND o.stage IN ('Kazanıldı', 'Kaybedildi')", $oppParams);
    $totalOpenTasks = (int) scalar("SELECT COUNT(*) FROM tasks t {$taskWhere}", $taskParams);
    $winRate = $closedCount > 0 ? round(($wonCount / $closedCount) * 100) . '%' : '0%';
    $stageOrder = array_flip(opportunity_stages());
    usort($oppReport, static fn(array $left, array $right): int => ($stageOrder[$left['stage'] ?? ''] ?? 99) <=> ($stageOrder[$right['stage'] ?? ''] ?? 99));
    $companySourceLabel = $usingSqlServerCompanies ? 'SQL Server dbo.Customer' : 'CRM veritabanı';
    $reportUser = current_user();
    ?>
    <section class="print-report-cover" aria-hidden="true">
        <div class="print-brand">
            <img src="<?= e(rtrim(app_config('base_url'), '/')) ?>/assets/brand/bilnex-logo.svg" alt="">
            <div>
                <strong>Bilnex İş Ortakları CRM</strong>
                <span>Performans ve satış raporu</span>
            </div>
        </div>
        <div class="print-meta">
            <span>Rapor dönemi</span>
            <strong><?= e($periodLabel) ?></strong>
            <small><?= e(date('d.m.Y H:i')) ?></small>
        </div>
    </section>
    <section class="print-report-sheet" aria-label="PDF raporu">
        <header class="print-report-header">
            <div class="print-report-brand">
                <img src="<?= e(rtrim(app_config('base_url'), '/')) ?>/assets/brand/bilnex-logo.svg" alt="Bilnex">
                <div>
                    <strong>İş Ortakları CRM</strong>
                    <span>Yönetim performans raporu</span>
                </div>
            </div>
            <div class="print-report-title">
                <span>Rapor dönemi</span>
                <h2><?= e($periodLabel) ?></h2>
                <small><?= e(date('d.m.Y H:i')) ?> tarihinde <?= e($reportUser['full_name']) ?> tarafından alındı</small>
            </div>
            <div class="print-report-source">
                <span>Veri kaynağı</span>
                <strong><?= e($companySourceLabel) ?></strong>
                <small><?= e($selectedAccountType !== '' ? $selectedAccountType : 'Tüm cari türleri') ?></small>
            </div>
        </header>
        <section class="print-kpi-grid">
            <?php
                render_print_kpi('Görüşme', $totalInteractions, 'Seçili dönem aktivitesi', 'tone-blue');
                render_print_kpi('Cari', number_format((float) $totalCompanies, 0, ',', '.'), $companySourceLabel, 'tone-violet');
                render_print_kpi('Açık iş', $totalOpenTasks, 'Geciken: ' . $totalOverdue, $totalOverdue > 0 ? 'tone-red' : 'tone-green');
                render_print_kpi('Açık fırsat', $openOppCount, compact_money($openOppAmount), 'tone-cyan');
                render_print_kpi('Kazanılan', compact_money($wonAmount), 'Oran: ' . $winRate, 'tone-green');
                render_print_kpi('Kaybedilen', compact_money($lostAmount), 'Kapanan fırsatlar', 'tone-orange');
            ?>
        </section>
        <section class="print-report-main">
            <article class="print-card print-card-large">
                <div class="print-card-head"><h3>Cari türü dağılımı</h3><span><?= e(number_format((float) $totalCompanies, 0, ',', '.')) ?> kayıt</span></div>
                <?php render_print_donut_report($typeReport, 'account_type', 'total', 6); ?>
            </article>
            <article class="print-card">
                <div class="print-card-head"><h3>Görüşme trendi</h3><span>Son 14 gün</span></div>
                <?php render_print_trend_chart($interactionTrend, 'day', 'total'); ?>
            </article>
            <article class="print-card">
                <div class="print-card-head"><h3>Satış pipeline</h3><span><?= e(compact_money($openOppAmount + $wonAmount + $lostAmount)) ?></span></div>
                <?php render_print_pipeline($oppReport); ?>
            </article>
            <article class="print-card">
                <div class="print-card-head"><h3>Personel performansı</h3><span>Aktivite</span></div>
                <?php render_print_bar_chart($staff, 'full_name', 'total', 5); ?>
            </article>
            <article class="print-card">
                <div class="print-card-head"><h3>Cari takip durumu</h3><span>Dağılım</span></div>
                <?php render_print_bar_chart($statusReport, 'status', 'total', 5); ?>
            </article>
            <article class="print-card">
                <div class="print-card-head"><h3>Görüşme sonuçları</h3><span>Sonuç özeti</span></div>
                <?php render_print_bar_chart($resultReport, 'result', 'total', 5); ?>
            </article>
            <article class="print-card print-risk-card">
                <div class="print-card-head"><h3>Geciken takipler</h3><span><?= e($totalOverdue) ?> açık risk</span></div>
                <div class="print-risk-list">
                    <?php foreach (array_slice($overdue, 0, 5) as $task): ?>
                        <div>
                            <strong><?= e($task['title']) ?></strong>
                            <span><?= e($task['company_name'] ?? 'Cari seçilmedi') ?> · <?= e($task['assigned_to_name'] ?? '-') ?></span>
                            <small><?= e($task['due_date']) ?></small>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$overdue): ?><p class="print-empty">Geciken takip bulunmuyor.</p><?php endif; ?>
                </div>
            </article>
        </section>
        <footer class="print-report-footer">
            <span>Bilnex İş Ortakları CRM</span>
            <strong>Profesyonel özet rapor</strong>
            <span>PDF çıktısı için A4 yatay tasarlanmıştır.</span>
        </footer>
    </section>
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
            <div class="table-wrap"><table><thead><tr><th>İş</th><th>Cari</th><th>Cari türü</th><th>Termin</th><th>Atanan</th></tr></thead><tbody>
                <?php foreach ($overdue as $r): ?><tr class="overdue"><td><a href="<?= e(app_url('followups', ['edit_task' => $r['id']])) ?>"><?= e($r['title']) ?></a></td><td><?= e($r['company_name'] ?? 'Cari seçilmedi') ?></td><td><?= e($r['account_type'] ?? '-') ?></td><td><?= e($r['due_date']) ?></td><td><?= e($r['assigned_to_name'] ?? '-') ?></td></tr><?php endforeach; ?>
                <?php if (!$overdue): ?><tr><td colspan="5" class="muted">Geciken görev yok.</td></tr><?php endif; ?>
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
