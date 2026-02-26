<?php
// MatchRun Admin Dashboard
// Configure your Supabase credentials here
define('SUPABASE_URL', 'https://YOUR_PROJECT.supabase.co');
define('SUPABASE_KEY', 'YOUR_SERVICE_ROLE_KEY');
define('ADMIN_PASSWORD', '290212');

session_start();

// ─── Login ───────────────────────────────────────────────────────────
if (!isset($_SESSION['admin_logged_in'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === ADMIN_PASSWORD) {
            $_SESSION['admin_logged_in'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $login_error = 'Senha incorreta';
        }
    }
    if (!isset($_SESSION['admin_logged_in'])) {
        ?>
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>MatchRun Admin - Login</title>
            <style>
                *{margin:0;padding:0;box-sizing:border-box}
                body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:linear-gradient(135deg,#FF6B35 0%,#E55A2B 100%);display:flex;justify-content:center;align-items:center;min-height:100vh}
                .login-box{background:#fff;padding:40px;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,.2);width:100%;max-width:400px}
                .login-box h1{text-align:center;font-size:28px;color:#FF6B35;margin-bottom:6px}
                .login-box p{text-align:center;color:#666;font-size:14px;margin-bottom:28px}
                label{display:block;font-weight:600;color:#333;margin-bottom:8px}
                input[type=password]{width:100%;padding:12px;border:1px solid #ddd;border-radius:8px;font-size:16px}
                input[type=password]:focus{outline:none;border-color:#FF6B35;box-shadow:0 0 0 3px rgba(255,107,53,.1)}
                button{width:100%;padding:12px;margin-top:16px;background:#FF6B35;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:16px}
                button:hover{background:#E55A2B}
                .err{background:#fee;color:#c33;padding:12px;border-radius:8px;margin-bottom:16px;font-size:14px}
            </style>
        </head>
        <body>
            <div class="login-box">
                <h1>MatchRun Admin</h1>
                <p>Dashboard de Moderacao</p>
                <?php if (isset($login_error)) echo '<div class="err">' . htmlspecialchars($login_error) . '</div>'; ?>
                <form method="POST">
                    <label for="password">Senha do Admin</label>
                    <input type="password" id="password" name="password" required autofocus>
                    <button type="submit">Entrar</button>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// ─── Logout ──────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ─── Supabase helpers ────────────────────────────────────────────────
function supabase_request($path, $method = 'GET', $body = null) {
    $url = SUPABASE_URL . '/rest/v1/' . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $headers = [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Prefer: return=representation',
    ];
    if ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['body' => $response, 'code' => $http_code, 'error' => $err];
}

// Test Supabase connection (returns array with status + debug info)
function check_supabase_connection() {
    $res = supabase_request('profiles?select=id&limit=1');
    $ok = $res['code'] >= 200 && $res['code'] < 300 && empty($res['error']);
    return [
        'connected' => $ok,
        'http_code' => $res['code'],
        'error'     => $res['error'],
        'hint'      => !$ok ? ($res['error'] ?: 'HTTP ' . $res['code'] . ' - Verifique SUPABASE_URL e SUPABASE_KEY (use service_role key)') : '',
    ];
}

// Get ALL users
function get_all_users() {
    $res = supabase_request('profiles?select=*&order=created_at.desc');
    if ($res['code'] >= 200 && $res['code'] < 300) {
        return json_decode($res['body'], true) ?? [];
    }
    return [];
}

// Get ALL reports (no JOINs to avoid PostgREST ambiguity with multiple FKs to profiles)
function get_all_reports() {
    $res = supabase_request('reports?select=*&order=created_at.desc');
    if ($res['code'] >= 200 && $res['code'] < 300) {
        return json_decode($res['body'], true) ?? [];
    }
    return [];
}

// Get report count per user from pre-fetched reports
function get_report_counts($all_reports) {
    $counts = [];
    foreach ($all_reports as $row) {
        $uid = $row['reported_user_id'];
        $counts[$uid] = ($counts[$uid] ?? 0) + 1;
    }
    return $counts;
}

// Get reports for a specific user from pre-fetched data, enriched with reporter + message
function get_reports_for_user($user_id, $all_reports, $all_users_map) {
    $user_reports = [];
    foreach ($all_reports as $report) {
        if ($report['reported_user_id'] === $user_id) {
            // Enrich with reporter info from profiles
            $reporter_id = $report['reporter_id'] ?? null;
            $report['reporter'] = $all_users_map[$reporter_id] ?? null;

            // Fetch reported message if message_id exists
            if (!empty($report['message_id'])) {
                $msg_res = supabase_request('run_messages?id=eq.' . urlencode($report['message_id']) . '&select=id,content,created_at&limit=1');
                if ($msg_res['code'] >= 200 && $msg_res['code'] < 300) {
                    $msgs = json_decode($msg_res['body'], true) ?? [];
                    $report['message'] = $msgs[0] ?? null;
                }
            }

            // Fetch run info if run_id exists
            if (!empty($report['run_id'])) {
                $run_res = supabase_request('runs?id=eq.' . urlencode($report['run_id']) . '&select=id,title&limit=1');
                if ($run_res['code'] >= 200 && $run_res['code'] < 300) {
                    $runs = json_decode($run_res['body'], true) ?? [];
                    $report['run'] = $runs[0] ?? null;
                }
            }

            $user_reports[] = $report;
        }
    }
    return $user_reports;
}

// Deactivate user
function deactivate_user($user_id) {
    $data = json_encode(['name' => '[CONTA DESATIVADA]']);
    $res = supabase_request('profiles?id=eq.' . urlencode($user_id), 'PATCH', $data);
    return $res['code'] >= 200 && $res['code'] < 300;
}

// Translate report reason to Portuguese
function translate_reason($reason) {
    $map = [
        'offensive_content' => 'Conteudo Ofensivo',
        'harassment'        => 'Assedio',
        'racism'            => 'Racismo',
        'sexual_content'    => 'Conteudo Sexual',
        'violence'          => 'Violencia',
        'spam'              => 'Spam',
        'fake_profile'      => 'Perfil Falso',
        'other'             => 'Outro',
    ];
    return $map[$reason] ?? $reason;
}

// Translate report status
function translate_status($status) {
    $map = [
        'pending'      => 'Pendente',
        'reviewed'     => 'Revisado',
        'action_taken' => 'Acao Tomada',
        'dismissed'    => 'Descartado',
    ];
    return $map[$status] ?? $status;
}

function status_color($status) {
    $map = [
        'pending'      => '#f59e0b',
        'reviewed'     => '#3b82f6',
        'action_taken' => '#ef4444',
        'dismissed'    => '#6b7280',
    ];
    return $map[$status] ?? '#999';
}

// ─── Handle POST actions ─────────────────────────────────────────────
$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'deactivate_user' && !empty($_POST['user_id'])) {
        if (deactivate_user($_POST['user_id'])) {
            $success = 'Conta desativada com sucesso!';
        } else {
            $error = 'Erro ao desativar conta.';
        }
    }
    if ($_POST['action'] === 'update_report_status' && !empty($_POST['report_id']) && !empty($_POST['new_status'])) {
        $data = json_encode(['status' => $_POST['new_status']]);
        $res = supabase_request('reports?id=eq.' . urlencode($_POST['report_id']), 'PATCH', $data);
        if ($res['code'] >= 200 && $res['code'] < 300) {
            $success = 'Status da denuncia atualizado!';
        } else {
            $error = 'Erro ao atualizar status da denuncia.';
        }
    }
}

// ─── Fetch data ──────────────────────────────────────────────────────
$supabase_status = check_supabase_connection();
$supabase_connected = $supabase_status['connected'];
$all_users = get_all_users();
$all_reports = get_all_reports();
$report_counts = get_report_counts($all_reports);

// Build a map of user_id => profile for quick lookups (reporter info)
$all_users_map = [];
foreach ($all_users as $u) {
    $all_users_map[$u['id']] = $u;
}

$current_user_view = $_GET['user_id'] ?? null;
$current_user_data = null;
$current_user_reports = [];

if ($current_user_view) {
    $current_user_data = $all_users_map[$current_user_view] ?? null;
    if ($current_user_data) {
        $current_user_reports = get_reports_for_user($current_user_view, $all_reports, $all_users_map);
    }
}

$total_users = count($all_users);
$total_reported = count($report_counts);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MatchRun Admin Dashboard</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#f0f2f5;color:#333}

        /* ── Header ── */
        .header{background:linear-gradient(135deg,#FF6B35,#E55A2B);color:#fff;padding:16px 24px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 10px rgba(0,0,0,.12)}
        .header h1{font-size:22px}
        .header-right{display:flex;align-items:center;gap:16px}
        .connection-badge{display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.15);padding:6px 14px;border-radius:20px;font-size:13px;font-weight:600}
        .status-dot{width:12px;height:12px;border-radius:50%;display:inline-block}
        .status-dot.green{background:#22c55e;box-shadow:0 0 6px #22c55e}
        .status-dot.red{background:#ef4444;box-shadow:0 0 6px #ef4444}
        .logout-btn{background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.3);padding:6px 14px;border-radius:6px;cursor:pointer;font-size:13px;text-decoration:none;transition:background .2s}
        .logout-btn:hover{background:rgba(255,255,255,.35)}

        /* ── Stats bar ── */
        .stats-bar{max-width:1500px;margin:20px auto 0;padding:0 24px;display:flex;gap:16px;flex-wrap:wrap}
        .stat-card{background:#fff;border-radius:10px;padding:16px 24px;box-shadow:0 1px 4px rgba(0,0,0,.06);flex:1;min-width:180px}
        .stat-card .label{font-size:12px;color:#888;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
        .stat-card .value{font-size:28px;font-weight:700;color:#FF6B35}

        /* ── Layout ── */
        .container{max-width:1500px;margin:20px auto 0;padding:0 24px 24px;display:grid;grid-template-columns:380px 1fr;gap:20px}

        /* ── Sidebar ── */
        .sidebar{background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.06);overflow:hidden;max-height:calc(100vh - 200px);display:flex;flex-direction:column}
        .sidebar-header{padding:14px 16px;border-bottom:1px solid #eee;font-weight:600;color:#FF6B35;font-size:14px;flex-shrink:0;display:flex;justify-content:space-between;align-items:center}
        .sidebar-search{padding:10px 16px;border-bottom:1px solid #eee;flex-shrink:0}
        .sidebar-search input{width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:13px}
        .sidebar-search input:focus{outline:none;border-color:#FF6B35}
        .sidebar-list{overflow-y:auto;flex:1}
        .user-card{padding:12px 16px;border-bottom:1px solid #f5f5f5;cursor:pointer;transition:background .15s;display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit}
        .user-card:hover{background:#fafafa}
        .user-card.active{background:#FFF5F0;border-left:4px solid #FF6B35}
        .user-avatar{width:44px;height:44px;border-radius:50%;object-fit:cover;flex-shrink:0}
        .user-avatar-placeholder{width:44px;height:44px;border-radius:50%;background:#FF6B35;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;flex-shrink:0}
        .user-info{flex:1;min-width:0}
        .user-name{font-weight:600;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .user-email{font-size:12px;color:#888;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .report-badge{background:#ef4444;color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px;flex-shrink:0}
        .no-report-badge{background:#22c55e;color:#fff;font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;flex-shrink:0}

        /* ── Content ── */
        .content{background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.06);padding:28px;min-height:calc(100vh - 200px);overflow-y:auto;max-height:calc(100vh - 200px)}
        .empty-state{text-align:center;padding:80px 20px;color:#aaa}
        .empty-state .icon{font-size:48px;margin-bottom:12px}

        /* ── User detail ── */
        .detail-header{display:flex;gap:20px;align-items:flex-start;margin-bottom:28px;padding-bottom:20px;border-bottom:1px solid #eee}
        .detail-avatar{width:90px;height:90px;border-radius:50%;object-fit:cover;flex-shrink:0}
        .detail-avatar-placeholder{width:90px;height:90px;border-radius:50%;background:#FF6B35;color:#fff;display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:700;flex-shrink:0}
        .detail-info h2{font-size:20px;margin-bottom:6px}
        .detail-info p{color:#666;font-size:13px;margin-bottom:3px}
        .detail-info p strong{color:#444}

        /* ── Reports section ── */
        .section-title{font-size:16px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:8px}
        .report-card{background:#f9fafb;padding:16px;border-radius:8px;margin-bottom:12px;border-left:4px solid #FF6B35}
        .report-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px}
        .reason-tag{background:#FF6B35;color:#fff;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600}
        .status-tag{padding:3px 10px;border-radius:12px;font-size:11px;font-weight:600;color:#fff}
        .report-date{font-size:11px;color:#999}
        .report-desc{font-size:13px;color:#555;line-height:1.5;margin:8px 0}
        .reporter-info{font-size:13px;color:#777;background:#fff;padding:8px 12px;border-radius:6px;display:flex;align-items:center;gap:8px;margin-top:8px}
        .reporter-avatar{width:26px;height:26px;border-radius:50%;object-fit:cover}
        .reporter-avatar-placeholder{width:26px;height:26px;border-radius:50%;background:#FF6B35;color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700}

        .no-reports-box{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:20px;text-align:center;color:#16a34a;font-size:14px}

        /* ── Status update form ── */
        .status-form{display:inline-flex;align-items:center;gap:6px;margin-left:8px}
        .status-form select{padding:3px 6px;border:1px solid #ddd;border-radius:4px;font-size:11px}
        .status-form button{padding:3px 8px;background:#FF6B35;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:11px}

        /* ── Deactivate ── */
        .danger-zone{background:#fef2f2;padding:20px;border-radius:8px;margin-top:28px;border:1px solid #fecaca}
        .danger-zone h3{color:#dc2626;margin-bottom:8px;font-size:15px}
        .danger-zone p{font-size:13px;color:#666;margin-bottom:12px}
        .btn-deactivate{background:#dc2626;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;transition:background .2s}
        .btn-deactivate:hover{background:#b91c1c}

        /* ── Messages ── */
        .msg-success{background:#f0fdf4;color:#16a34a;padding:10px 14px;border-radius:6px;margin-bottom:16px;border-left:4px solid #22c55e;font-size:13px}
        .msg-error{background:#fef2f2;color:#dc2626;padding:10px 14px;border-radius:6px;margin-bottom:16px;border-left:4px solid #ef4444;font-size:13px}

        @media(max-width:900px){
            .container{grid-template-columns:1fr}
            .sidebar{max-height:350px}
            .content{max-height:none}
            .stats-bar{padding:0 16px}
            .container{padding:0 16px 16px}
        }
    </style>
</head>
<body>
    <!-- ── Header ── -->
    <div class="header">
        <h1>MatchRun - Painel Admin</h1>
        <div class="header-right">
            <div class="connection-badge">
                <span class="status-dot <?php echo $supabase_connected ? 'green' : 'red'; ?>"></span>
                Supabase <?php echo $supabase_connected ? 'Conectado' : 'Desconectado'; ?>
            </div>
            <a href="?logout=1" class="logout-btn">Sair</a>
        </div>
    </div>

    <!-- ── Stats ── -->
    <div class="stats-bar">
        <div class="stat-card">
            <div class="label">Total de Usuarios</div>
            <div class="value"><?php echo $total_users; ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Usuarios Denunciados</div>
            <div class="value" style="color:#ef4444"><?php echo $total_reported; ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Usuarios Limpos</div>
            <div class="value" style="color:#22c55e"><?php echo $total_users - $total_reported; ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Status Supabase</div>
            <div class="value" style="font-size:16px;color:<?php echo $supabase_connected ? '#22c55e' : '#ef4444'; ?>">
                <?php echo $supabase_connected ? 'Online' : 'Offline'; ?>
            </div>
            <?php if (!$supabase_connected && !empty($supabase_status['hint'])): ?>
                <div style="font-size:11px;color:#ef4444;margin-top:4px"><?php echo htmlspecialchars($supabase_status['hint']); ?></div>
            <?php endif; ?>
        </div>
        <div class="stat-card">
            <div class="label">Total Denuncias</div>
            <div class="value" style="color:#f59e0b"><?php echo count($all_reports); ?></div>
        </div>
    </div>

    <!-- ── Main Layout ── -->
    <div class="container">
        <!-- Sidebar: All Users -->
        <div class="sidebar">
            <div class="sidebar-header">
                <span>Todos os Usuarios (<?php echo $total_users; ?>)</span>
            </div>
            <div class="sidebar-search">
                <input type="text" id="searchInput" placeholder="Buscar por nome ou email..." oninput="filterUsers()">
            </div>
            <div class="sidebar-list" id="userList">
                <?php if (empty($all_users)): ?>
                    <div style="padding:20px;text-align:center;color:#999;font-size:13px">
                        <?php echo $supabase_connected ? 'Nenhum usuario encontrado' : 'Sem conexao com Supabase'; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($all_users as $user):
                        $uid = $user['id'];
                        $reports_count = $report_counts[$uid] ?? 0;
                        $is_active = ($current_user_view === $uid);
                        $name = $user['name'] ?? '';
                        $email = $user['email'] ?? '';
                    ?>
                        <a href="?user_id=<?php echo urlencode($uid); ?>"
                           class="user-card <?php echo $is_active ? 'active' : ''; ?>"
                           data-name="<?php echo strtolower(htmlspecialchars($name)); ?>"
                           data-email="<?php echo strtolower(htmlspecialchars($email)); ?>">
                            <?php if (!empty($user['avatar_url'])): ?>
                                <img src="<?php echo htmlspecialchars($user['avatar_url']); ?>" class="user-avatar" alt="">
                            <?php else: ?>
                                <div class="user-avatar-placeholder"><?php echo strtoupper(mb_substr($name, 0, 1) ?: '?'); ?></div>
                            <?php endif; ?>
                            <div class="user-info">
                                <div class="user-name"><?php echo htmlspecialchars($name ?: '(sem nome)'); ?></div>
                                <div class="user-email"><?php echo htmlspecialchars($email); ?></div>
                            </div>
                            <?php if ($reports_count > 0): ?>
                                <span class="report-badge"><?php echo $reports_count; ?> den.</span>
                            <?php else: ?>
                                <span class="no-report-badge">OK</span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Content: User Details -->
        <div class="content">
            <?php if ($success): ?>
                <div class="msg-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="msg-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (!$current_user_view || !$current_user_data): ?>
                <div class="empty-state">
                    <div class="icon">&#128100;</div>
                    <h2>Selecione um usuario</h2>
                    <p>Clique em um usuario na lista para ver os detalhes e denuncias</p>
                </div>
            <?php else: ?>
                <!-- User Header -->
                <div class="detail-header">
                    <?php if (!empty($current_user_data['avatar_url'])): ?>
                        <img src="<?php echo htmlspecialchars($current_user_data['avatar_url']); ?>" class="detail-avatar" alt="">
                    <?php else: ?>
                        <div class="detail-avatar-placeholder">
                            <?php echo strtoupper(mb_substr($current_user_data['name'] ?? '', 0, 1) ?: '?'); ?>
                        </div>
                    <?php endif; ?>
                    <div class="detail-info">
                        <h2><?php echo htmlspecialchars($current_user_data['name'] ?? '(sem nome)'); ?></h2>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($current_user_data['email'] ?? ''); ?></p>
                        <p><strong>Bio:</strong> <?php echo htmlspecialchars($current_user_data['bio'] ?? 'Nenhuma'); ?></p>
                        <p><strong>Strava:</strong> <?php echo !empty($current_user_data['strava_url']) ? '<a href="' . htmlspecialchars($current_user_data['strava_url']) . '" target="_blank" style="color:#FF6B35">Ver perfil</a>' : 'Nao vinculado'; ?></p>
                        <p><strong>Membro desde:</strong> <?php echo date('d/m/Y', strtotime($current_user_data['created_at'])); ?></p>
                        <p><strong>ID:</strong> <code style="font-size:11px;background:#f5f5f5;padding:2px 6px;border-radius:4px"><?php echo htmlspecialchars($current_user_data['id']); ?></code></p>
                    </div>
                </div>

                <!-- Reports Section -->
                <?php $rc = count($current_user_reports); ?>
                <div class="section-title">
                    Denuncias Recebidas
                    <?php if ($rc > 0): ?>
                        <span style="background:#ef4444;color:#fff;padding:2px 10px;border-radius:10px;font-size:12px"><?php echo $rc; ?></span>
                    <?php else: ?>
                        <span style="background:#22c55e;color:#fff;padding:2px 10px;border-radius:10px;font-size:12px">0</span>
                    <?php endif; ?>
                </div>

                <?php if ($rc === 0): ?>
                    <div class="no-reports-box">
                        Nenhuma denuncia contra este usuario. Tudo certo!
                    </div>
                <?php else: ?>
                    <?php foreach ($current_user_reports as $report): ?>
                        <div class="report-card">
                            <div class="report-top">
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                    <span class="reason-tag"><?php echo htmlspecialchars(translate_reason($report['reason'] ?? '')); ?></span>
                                    <span class="status-tag" style="background:<?php echo status_color($report['status'] ?? 'pending'); ?>">
                                        <?php echo htmlspecialchars(translate_status($report['status'] ?? 'pending')); ?>
                                    </span>
                                    <!-- Inline status update -->
                                    <form method="POST" class="status-form" style="display:inline-flex">
                                        <input type="hidden" name="action" value="update_report_status">
                                        <input type="hidden" name="report_id" value="<?php echo htmlspecialchars($report['id']); ?>">
                                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($current_user_view); ?>">
                                        <select name="new_status">
                                            <option value="pending" <?php echo ($report['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>Pendente</option>
                                            <option value="reviewed" <?php echo ($report['status'] ?? '') === 'reviewed' ? 'selected' : ''; ?>>Revisado</option>
                                            <option value="action_taken" <?php echo ($report['status'] ?? '') === 'action_taken' ? 'selected' : ''; ?>>Acao Tomada</option>
                                            <option value="dismissed" <?php echo ($report['status'] ?? '') === 'dismissed' ? 'selected' : ''; ?>>Descartado</option>
                                        </select>
                                        <button type="submit">Salvar</button>
                                    </form>
                                </div>
                                <span class="report-date"><?php echo date('d/m/Y H:i', strtotime($report['created_at'])); ?></span>
                            </div>

                            <?php if (!empty($report['description'])): ?>
                                <div class="report-desc">
                                    <strong>Detalhes:</strong> <?php echo htmlspecialchars($report['description']); ?>
                                </div>
                            <?php endif; ?>

                            <?php if (isset($report['message']) && is_array($report['message']) && !empty($report['message']['content'])): ?>
                                <div style="background:#fff4e6;border:1px solid #fed7aa;border-radius:6px;padding:12px;margin:8px 0">
                                    <div style="font-size:11px;color:#c2410c;font-weight:600;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px">Mensagem Reportada</div>
                                    <div style="font-size:13px;color:#9a3412;line-height:1.5;font-style:italic">"<?php echo htmlspecialchars($report['message']['content']); ?>"</div>
                                    <div style="font-size:10px;color:#c2410c;margin-top:6px">Enviada em <?php echo date('d/m/Y H:i', strtotime($report['message']['created_at'])); ?></div>
                                </div>
                            <?php endif; ?>

                            <?php if (isset($report['run']) && is_array($report['run']) && !empty($report['run']['title'])): ?>
                                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:8px 12px;margin:8px 0;font-size:12px;color:#1e40af">
                                    <strong>Corrida:</strong> <?php echo htmlspecialchars($report['run']['title']); ?>
                                </div>
                            <?php endif; ?>

                            <div class="reporter-info">
                                <strong>Denunciado por:</strong>
                                <?php if (isset($report['reporter']) && is_array($report['reporter'])): ?>
                                    <?php if (!empty($report['reporter']['avatar_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($report['reporter']['avatar_url']); ?>" class="reporter-avatar" alt="">
                                    <?php else: ?>
                                        <div class="reporter-avatar-placeholder"><?php echo strtoupper(mb_substr($report['reporter']['name'] ?? '', 0, 1) ?: '?'); ?></div>
                                    <?php endif; ?>
                                    <span><?php echo htmlspecialchars($report['reporter']['name'] ?? 'Usuario'); ?></span>
                                    <span style="color:#aaa;font-size:11px">(<?php echo htmlspecialchars($report['reporter']['email'] ?? ''); ?>)</span>
                                <?php else: ?>
                                    <span>Usuario removido</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Danger Zone -->
                <div class="danger-zone">
                    <h3>Zona de Perigo</h3>
                    <p>Ao desativar esta conta, o nome do usuario sera alterado para "[CONTA DESATIVADA]" e ele nao podera mais usar o app normalmente.</p>
                    <form method="POST" onsubmit="return confirm('Tem certeza que deseja DESATIVAR a conta de <?php echo htmlspecialchars(addslashes($current_user_data['name'] ?? '')); ?>?');">
                        <input type="hidden" name="action" value="deactivate_user">
                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($current_user_view); ?>">
                        <button type="submit" class="btn-deactivate">Desativar Conta</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function filterUsers() {
        var query = document.getElementById('searchInput').value.toLowerCase();
        var cards = document.querySelectorAll('#userList .user-card');
        cards.forEach(function(card) {
            var name = card.getAttribute('data-name') || '';
            var email = card.getAttribute('data-email') || '';
            card.style.display = (name.indexOf(query) !== -1 || email.indexOf(query) !== -1) ? '' : 'none';
        });
    }
    </script>
</body>
</html>
