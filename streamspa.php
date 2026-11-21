<?php
/**
 * streamspa.php — StreamLive SPA
 * Build 20261122011500
 */
declare(strict_types=1);

define('SPA_BUILD', '20261121161138');

// ——— РАННИЙ JSON до любых include (не зависит от db/auth/aes) ———
$__spa_api = isset($_GET['api']) ? (string)$_GET['api'] : (isset($_POST['api']) ? (string)$_POST['api'] : '');
if ($__spa_api === 'version' || $__spa_api === 'ping') {
  while (ob_get_level() > 0) { @ob_end_clean(); }
  @ini_set('display_errors', '0');
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-SPA-File: streamspa.php');
    header('X-SPA-Build: ' . SPA_BUILD);
  }
  echo json_encode([
    'ok' => true,
    'build' => SPA_BUILD,
    'api' => $__spa_api,
    'file' => basename(__FILE__),
    'dir' => __DIR__,
    'time' => date('c'),
    'min_build' => '20261121013000',
    'runtime' => ['php_version' => PHP_VERSION, 'php_int_size' => PHP_INT_SIZE, 'timestamp_64bit' => (PHP_INT_SIZE >= 8), 'limit_time_storage' => 'mysql_datetime_9999'],
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$__spa_is_api = ($__spa_api !== '');
// Совместимость зеркал: необязательное обновление не блокирует API старого клиента.
if ($__spa_is_api) {
  while (ob_get_level() > 0) { @ob_end_clean(); }
  ob_start();
  @ini_set('display_errors', '0');
  @ini_set('html_errors', '0');
  error_reporting(E_ALL);
} else {
  @ini_set('display_errors', '0');
  error_reporting(E_ALL);
}

// Подключения StreamLive — в try, чтобы API не отдал HTML при фатале include
try {
  require_once __DIR__ . '/includes/db.php';
  require_once __DIR__ . '/includes/functions.php';
  require_once __DIR__ . '/includes/auth.php';
} catch (Throwable $e) {
  if ($__spa_is_api) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'include: '.$e->getMessage(), 'build' => SPA_BUILD]);
    exit;
  }
  throw $e;
}
function spa_ensure_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try { $pdo = db(); } catch (Throwable $e) { return; }
  $ddl = [
    "CREATE TABLE IF NOT EXISTS spa_login_streak (
      user_id INT NOT NULL PRIMARY KEY,
      last_login_date DATE NULL,
      streak_days INT NOT NULL DEFAULT 0,
      subscription_until DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_presence (
      user_id INT NOT NULL PRIMARY KEY,
      tab_name VARCHAR(64) NOT NULL DEFAULT '',
      activity VARCHAR(128) NOT NULL DEFAULT '',
      game VARCHAR(128) NOT NULL DEFAULT '',
      detail VARCHAR(255) NOT NULL DEFAULT '',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_favorites (
      user_id INT NOT NULL,
      target_type VARCHAR(32) NOT NULL,
      target_id INT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id, target_type, target_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_cinema_access (
      user_id INT NOT NULL,
      kp_id VARCHAR(32) NOT NULL,
      granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      expires_at DATETIME NOT NULL,
      PRIMARY KEY (user_id, kp_id),
      INDEX (user_id, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_planner_items (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      title VARCHAR(180) NOT NULL,
      note VARCHAR(500) NOT NULL DEFAULT '',
      due_at DATETIME NULL,
      is_done TINYINT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX (user_id, is_done, due_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_media_continue (
      user_id INT NOT NULL,
      item_type VARCHAR(24) NOT NULL,
      item_id VARCHAR(64) NOT NULL,
      item_json MEDIUMTEXT NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,item_type,item_id),
      INDEX (user_id,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_media_history (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      item_type VARCHAR(24) NOT NULL,
      item_id VARCHAR(64) NOT NULL,
      title VARCHAR(180) NOT NULL DEFAULT '',
      viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX (user_id,viewed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_user_services (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      name VARCHAR(100) NOT NULL,
      url VARCHAR(1000) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX (user_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_user_preferences (
      user_id INT NOT NULL PRIMARY KEY,
      prefs_json TEXT NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    /* native_stories — таблица движка StreamLive, не создаём свою */
    "CREATE TABLE IF NOT EXISTS spa_server_playlists (
      id INT AUTO_INCREMENT PRIMARY KEY,
      server_id INT NOT NULL,
      user_id INT NOT NULL,
      title VARCHAR(120) NOT NULL,
      tracks_json MEDIUMTEXT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX (server_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_msg_reactions (
      id INT AUTO_INCREMENT PRIMARY KEY,
      scope VARCHAR(16) NOT NULL,
      msg_id INT NOT NULL,
      user_id INT NOT NULL,
      emoji VARCHAR(16) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq (scope, msg_id, user_id, emoji),
      INDEX (scope, msg_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS spa_danmaku (
      id INT AUTO_INCREMENT PRIMARY KEY,
      target_type VARCHAR(16) NOT NULL,
      target_id INT NOT NULL,
      user_id INT NOT NULL,
      text VARCHAR(120) NOT NULL,
      color VARCHAR(16) NOT NULL DEFAULT '#ffffff',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX (target_type, target_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_daily_limits (
      user_id INT NOT NULL,
      day_date DATE NOT NULL,
      profiles INT NOT NULL DEFAULT 0,
      channels INT NOT NULL DEFAULT 0,
      videos INT NOT NULL DEFAULT 0,
      cinemas INT NOT NULL DEFAULT 0,
      music INT NOT NULL DEFAULT 0,
      profiles_blocked_until DATETIME NULL DEFAULT NULL,
      channels_blocked_until DATETIME NULL DEFAULT NULL,
      videos_blocked_until DATETIME NULL DEFAULT NULL,
      cinemas_blocked_until DATETIME NULL DEFAULT NULL,
      music_blocked_until DATETIME NULL DEFAULT NULL,
      PRIMARY KEY (user_id, day_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_youtube_daily_usage (user_id INT NOT NULL, day_date DATE NOT NULL, searches INT NOT NULL DEFAULT 0, PRIMARY KEY(user_id,day_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_content_reactions (user_id INT NOT NULL,target_type VARCHAR(16) NOT NULL,target_id BIGINT NOT NULL,vote TINYINT NOT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(user_id,target_type,target_id),INDEX(target_type,target_id,vote)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_service_daily_usage (user_id INT NOT NULL, service_key VARCHAR(24) NOT NULL, day_date DATE NOT NULL, used_units INT NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY(user_id,service_key,day_date), INDEX(day_date,service_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_vault_usage (user_id INT NOT NULL PRIMARY KEY, used_actions INT NOT NULL DEFAULT 0, window_started_at DATETIME NULL, blocked_until DATETIME NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_youtube_search_charges (charge_id CHAR(32) NOT NULL PRIMARY KEY, user_id INT NOT NULL, day_date DATE NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'charged', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX (user_id,day_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_server_score (
      server_id INT NOT NULL PRIMARY KEY,
      xp INT NOT NULL DEFAULT 0,
      ach_count INT NOT NULL DEFAULT 0,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_server_xp_awards (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      server_id INT NOT NULL,
      user_id INT NOT NULL,
      award_key VARCHAR(100) NOT NULL,
      points INT NOT NULL DEFAULT 0,
      award_type VARCHAR(16) NOT NULL DEFAULT 'quest',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_server_user_award (server_id,user_id,award_key),
      INDEX (server_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_profile_xp_awards (
      id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, award_key VARCHAR(120) NOT NULL, points INT NOT NULL DEFAULT 0, award_type VARCHAR(20) NOT NULL DEFAULT 'daily', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_profile_award(user_id,award_key), INDEX(user_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_profile_daily_claims (
      user_id INT NOT NULL, day_key DATE NOT NULL, task_id VARCHAR(40) NOT NULL, points INT NOT NULL DEFAULT 0, claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(user_id,day_key,task_id), INDEX(user_id,day_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_servers (
      id INT AUTO_INCREMENT PRIMARY KEY,
      owner_id INT NOT NULL,
      name VARCHAR(64) NOT NULL,
      invite_code VARCHAR(24) NOT NULL,
      description VARCHAR(255) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_code (invite_code),
      INDEX (owner_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_server_members (
      server_id INT NOT NULL,
      user_id INT NOT NULL,
      role VARCHAR(16) NOT NULL DEFAULT 'member',
      joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (server_id, user_id),
      UNIQUE KEY uq_user_one (user_id),
      INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_server_messages (
      id INT AUTO_INCREMENT PRIMARY KEY,
      server_id INT NOT NULL,
      user_id INT NOT NULL,
      body VARCHAR(500) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX (server_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_server_roles (
      id INT AUTO_INCREMENT PRIMARY KEY,
      server_id INT NOT NULL,
      name VARCHAR(48) NOT NULL,
      permissions_json TEXT NOT NULL,
      position INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX (server_id, position)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_server_member_roles (
      server_id INT NOT NULL,
      user_id INT NOT NULL,
      role_id INT NOT NULL,
      PRIMARY KEY (server_id,user_id),
      INDEX (role_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_server_apps (
      id INT AUTO_INCREMENT PRIMARY KEY,
      server_id INT NOT NULL,
      name VARCHAR(80) NOT NULL,
      base_url VARCHAR(1000) NOT NULL,
      description VARCHAR(255) NOT NULL DEFAULT '',
      icon VARCHAR(24) NOT NULL DEFAULT '🔌',
      display_mode VARCHAR(12) NOT NULL DEFAULT 'open',
      created_by INT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX (server_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_server_webhooks (
      id INT AUTO_INCREMENT PRIMARY KEY,
      server_id INT NOT NULL,
      name VARCHAR(80) NOT NULL,
      endpoint_url VARCHAR(1000) NOT NULL,
      events_json TEXT NOT NULL,
      active TINYINT NOT NULL DEFAULT 0,
      created_by INT NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX (server_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_server_welcome (server_id INT NOT NULL PRIMARY KEY, enabled TINYINT NOT NULL DEFAULT 1, message VARCHAR(500) NOT NULL DEFAULT 'Добро пожаловать, {user}!', rules_url VARCHAR(1000) NOT NULL DEFAULT '', updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_server_badges (id INT AUTO_INCREMENT PRIMARY KEY, server_id INT NOT NULL, code VARCHAR(40) NOT NULL, title VARCHAR(80) NOT NULL, description VARCHAR(255) NOT NULL DEFAULT '', icon VARCHAR(16) NOT NULL DEFAULT '🏅', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_server_badge(server_id,code), INDEX(server_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_server_user_badges (server_id INT NOT NULL, user_id INT NOT NULL, badge_id INT NOT NULL, awarded_by INT NOT NULL, awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(server_id,user_id,badge_id), INDEX(server_id,user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_server_collections (id INT AUTO_INCREMENT PRIMARY KEY, server_id INT NOT NULL, user_id INT NOT NULL, title VARCHAR(100) NOT NULL, description VARCHAR(255) NOT NULL DEFAULT '', items_json MEDIUMTEXT NOT NULL, is_shared TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX(server_id,is_shared,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_server_tickets (id BIGINT AUTO_INCREMENT PRIMARY KEY, server_id INT NOT NULL, user_id INT NOT NULL, subject VARCHAR(120) NOT NULL, body TEXT NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'open', staff_reply TEXT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX(server_id,status,id), INDEX(user_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_server_polls (id BIGINT AUTO_INCREMENT PRIMARY KEY, server_id INT NOT NULL, user_id INT NOT NULL, question VARCHAR(255) NOT NULL, options_json TEXT NOT NULL, ends_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX(server_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_server_poll_votes (poll_id BIGINT NOT NULL, server_id INT NOT NULL, user_id INT NOT NULL, option_index INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(poll_id,user_id), INDEX(server_id,poll_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_server_events (id BIGINT AUTO_INCREMENT PRIMARY KEY, server_id INT NOT NULL, user_id INT NOT NULL, title VARCHAR(120) NOT NULL, description TEXT NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NULL, location VARCHAR(180) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX(server_id,starts_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_server_event_rsvps (event_id BIGINT NOT NULL, server_id INT NOT NULL, user_id INT NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'going', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(event_id,user_id), INDEX(server_id,event_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS spa_server_audit (id BIGINT AUTO_INCREMENT PRIMARY KEY, server_id INT NOT NULL, actor_id INT NOT NULL DEFAULT 0, action VARCHAR(48) NOT NULL, details VARCHAR(500) NOT NULL DEFAULT '', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX(server_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  ];
  foreach ($ddl as $sql) {
    try { $pdo->exec($sql); } catch (Throwable $e) {}
  }
  // Declarative service layout: safe JSON manifest, never executable JS.
  try { $pdo->exec("ALTER TABLE spa_server_apps ADD COLUMN manifest_json MEDIUMTEXT NULL"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE spa_server_apps ADD COLUMN service_type VARCHAR(16) NOT NULL DEFAULT 'link'"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE spa_server_apps ADD COLUMN is_published TINYINT NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE spa_server_apps ADD COLUMN published_at DATETIME NULL"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE spa_server_apps ADD COLUMN install_count INT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE spa_server_apps ADD COLUMN platform VARCHAR(16) NOT NULL DEFAULT 'universal'"); } catch (Throwable $e) {}
  try { $pdo->exec("CREATE TABLE IF NOT EXISTS spa_server_app_creation_log (id BIGINT AUTO_INCREMENT PRIMARY KEY, server_id INT NOT NULL, user_id INT NOT NULL, app_id INT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX(server_id,user_id,created_at), INDEX(app_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
  try { $pdo->exec("CREATE TABLE IF NOT EXISTS spa_server_app_quota_locks (server_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY(server_id,user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
  try { $pdo->exec("CREATE TABLE IF NOT EXISTS spa_server_app_installs (server_id INT NOT NULL, app_id INT NOT NULL, user_id INT NOT NULL, installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(server_id,app_id,user_id), INDEX(server_id,user_id,installed_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
  try { $pdo->exec("CREATE TABLE IF NOT EXISTS spa_server_app_reviews (server_id INT NOT NULL, app_id INT NOT NULL, user_id INT NOT NULL, rating TINYINT NOT NULL, review VARCHAR(500) NOT NULL DEFAULT '', updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY(server_id,app_id,user_id), INDEX(server_id,app_id,rating)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

  // last_active для кика 30 дней
  try { $pdo->exec("ALTER TABLE spa_server_members ADD COLUMN last_active DATETIME NULL DEFAULT NULL"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE spa_daily_limits ADD COLUMN cinemas INT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE spa_daily_limits ADD COLUMN music INT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
  foreach (['profiles_blocked_until','channels_blocked_until','videos_blocked_until','cinemas_blocked_until','music_blocked_until'] as $col) {
    try { $pdo->exec("ALTER TABLE spa_daily_limits ADD COLUMN `{$col}` DATETIME NULL DEFAULT NULL"); } catch (Throwable $e) {}
  }
  try { $pdo->exec("ALTER TABLE spa_servers ADD COLUMN kp_api_key VARCHAR(128) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE spa_servers ADD COLUMN youtube_api_key VARCHAR(128) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE spa_servers ADD COLUMN tmdb_api_key VARCHAR(128) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE spa_servers ADD COLUMN imdb_enabled TINYINT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE spa_servers ADD COLUMN imdb_api_key VARCHAR(128) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE spa_servers ADD COLUMN default_badge_id INT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE spa_servers ADD COLUMN inactive_kick_days INT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE spa_server_messages ADD COLUMN is_system TINYINT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}

}

// если API — любые фатальные проблемы в schema не должны отдавать HTML
try {
  spa_ensure_schema();
} catch (Throwable $e) {
  if (!empty($__spa_is_api)) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'schema: '.$e->getMessage()]);
    exit;
  }
}


function spa_json($data, int $code = 200): void {
  // выкинуть любой случайный вывод до JSON (warnings, echo из include)
  while (ob_get_level() > 0) {
    @ob_end_clean();
  }
  if (!headers_sent()) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-SPA-File: streamspa.php');
    header('X-SPA-Build: ' . SPA_BUILD);
  }
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/** API secrets: AES-256-GCM at rest. The master key is generated automatically
 * outside DOCUMENT_ROOT on first use, or can be supplied through the environment.
 * Legacy shared keys are encrypted in a deployment migration before API routing.
 */
function spa_api_master_key(): string {
  static $cached = null;
  if ($cached !== null) return $cached;
  $candidate = trim((string)getenv('STREAMLIVE_API_KEY_SECRET'));
  if ($candidate === '') {
    // Search both the private directory above DOCUMENT_ROOT and the directory
    // containing streamspa.php. Shared hosting often cannot write above web-root,
    // and users commonly upload the helper beside streamspa.php.
    $docRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $scriptDir = realpath(__DIR__) ?: __DIR__;
    $secretDirs = [];
    if ($docRoot && ($scriptDir === $docRoot || strpos($scriptDir, rtrim($docRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) === 0)) {
      $secretDirs[] = dirname($docRoot);
    } else {
      $secretDirs[] = dirname(__DIR__);
    }
    $secretDirs[] = __DIR__;
    $secretDirs = array_values(array_unique($secretDirs));
    $secretCandidates = [];
    foreach ($secretDirs as $dir) {
      $secretCandidates[] = $dir . DIRECTORY_SEPARATOR . 'streamlive-secrets.php';
      $secretCandidates[] = $dir . DIRECTORY_SEPARATOR . 'StreamLive_streamlive-secrets.php';
      $secretCandidates[] = $dir . DIRECTORY_SEPARATOR . '.streamlive-secrets.php';
    }
    $secretFile = '';
    foreach ($secretCandidates as $candidateFile) {
      if (is_file($candidateFile) && !is_link($candidateFile)) { $secretFile = $candidateFile; break; }
    }
    if ($secretFile === '') {
      // Prefer a private path above the web root; fall back to the app directory
      // when shared hosting denies writes to the parent directory.
      $writeDirs = $secretDirs;
      foreach ($writeDirs as $writeDir) {
        if (!is_dir($writeDir) || !is_writable($writeDir)) continue;
        $secretFile = $writeDir . DIRECTORY_SEPARATOR . '.streamlive-secrets.php';
        try {
          $generated = bin2hex(random_bytes(32));
          $contents = "<?php\n// Private StreamLive encryption key. Do not edit or delete.\nif (PHP_SAPI !== 'cli' && isset(\$_SERVER['SCRIPT_FILENAME']) && realpath((string)\$_SERVER['SCRIPT_FILENAME']) === __FILE__) { http_response_code(404); exit; }\nreturn ['api_key_encryption_key' => '" . $generated . "'];\n";
          $fh = @fopen($secretFile, 'x');
          if ($fh) {
            @chmod($secretFile, 0600);
            $written = fwrite($fh, $contents);
            fflush($fh);
            fclose($fh);
            @chmod($secretFile, 0600);
            if ($written === false || $written !== strlen($contents)) { @unlink($secretFile); $secretFile = ''; continue; }
            break;
          }
          if (is_file($secretFile)) break;
          $secretFile = '';
        } catch (Throwable $e) {
          error_log('StreamLive API key encryption setup: ' . $e->getMessage());
          $secretFile = '';
        }
      }
    }
    if ($secretFile !== '' && is_file($secretFile) && is_readable($secretFile) && !is_link($secretFile)) {
      try {
        $cfg = require $secretFile;
        if (is_array($cfg)) $candidate = trim((string)($cfg['api_key_encryption_key'] ?? ''));
      } catch (Throwable $e) { $candidate = ''; }
    }
  }
  if (preg_match('/^[a-f0-9]{64}$/i', $candidate)) $candidate = hex2bin($candidate) ?: '';
  if (strlen($candidate) !== 32) {
    $cached = '';
    return $cached;
  }
  $cached = $candidate;
  return $cached;
}
function spa_api_key_encrypt(string $plain): string {
  if ($plain === '') return '';
  if (!function_exists('openssl_encrypt')) throw new RuntimeException('На сервере PHP недоступно OpenSSL.');
  $master = spa_api_master_key();
  if ($master === '') throw new RuntimeException('Не настроен отдельный ключ шифрования StreamLive.');
  $iv = random_bytes(12); $tag = '';
  $cipher = openssl_encrypt($plain, 'aes-256-gcm', $master, OPENSSL_RAW_DATA, $iv, $tag, 'StreamLive/API-keys/v1', 16);
  if ($cipher === false || strlen($tag) !== 16) throw new RuntimeException('Не удалось зашифровать API-ключ.');
  return 'enc:v1:' . base64_encode($iv . $tag . $cipher);
}
function spa_api_key_decrypt(string $stored): string {
  if ($stored === '') return '';
  if (strncmp($stored, 'enc:v1:', 7) !== 0) return $stored; // legacy plaintext; caller migrates it
  if (!function_exists('openssl_decrypt')) throw new RuntimeException('На сервере PHP недоступно OpenSSL.');
  $master = spa_api_master_key();
  if ($master === '') throw new RuntimeException('Не настроен отдельный ключ шифрования StreamLive.');
  $raw = base64_decode(substr($stored, 7), true);
  if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Зашифрованный API-ключ повреждён.');
  $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $master, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'StreamLive/API-keys/v1');
  if ($plain === false) throw new RuntimeException('Не удалось расшифровать API-ключ: проверь главный секрет шифрования.');
  return $plain;
}
function spa_server_api_key(int $serverId, string $column): string {
  if (!in_array($column, ['kp_api_key', 'youtube_api_key', 'tmdb_api_key', 'imdb_api_key'], true)) throw new InvalidArgumentException('Недопустимое поле ключа.');
  $pdo = db();
  $q = $pdo->prepare("SELECT `{$column}` FROM spa_servers WHERE id=? LIMIT 1");
  $q->execute([$serverId]);
  $stored = trim((string)($q->fetchColumn() ?: ''));
  if ($stored === '') return '';
  $plain = spa_api_key_decrypt($stored);
  if (strncmp($stored, 'enc:v1:', 7) !== 0) {
    if (strlen($plain) > 60 || !preg_match($column === 'imdb_api_key' ? '/^[A-Za-z0-9]{8,60}$/D' : '/^[A-Za-z0-9_-]{16,60}$/D', $plain)) throw new RuntimeException('Старый API-ключ не подходит под формат безопасного хранения.');
    $encrypted = spa_api_key_encrypt($plain);
    $u = $pdo->prepare("UPDATE spa_servers SET `{$column}`=? WHERE id=? AND `{$column}`=?");
    $u->execute([$encrypted, $serverId, $stored]);
  }
  return $plain;
}
function spa_save_server_api_key(int $serverId, string $column, string $plain): void {
  if (!in_array($column, ['kp_api_key', 'youtube_api_key', 'tmdb_api_key', 'imdb_api_key'], true)) throw new InvalidArgumentException('Недопустимое поле ключа.');
  $plain = trim($plain);
  if ($plain !== '' && !preg_match($column === 'imdb_api_key' ? '/^[A-Za-z0-9]{8,60}$/D' : '/^[A-Za-z0-9_-]{16,60}$/D', $plain)) throw new InvalidArgumentException($column === 'imdb_api_key' ? 'IMDb/OMDb key должен содержать 8–60 латинских букв и цифр.' : 'Формат API-ключа недопустим. Разрешены 16–60 символов: латиница, цифры, дефис и подчёркивание.');
  $stored = $plain === '' ? '' : spa_api_key_encrypt($plain);
  db()->prepare("UPDATE spa_servers SET `{$column}`=? WHERE id=?")->execute([$stored, $serverId]);
}

/** Encrypt ALL pre-existing shared keys in one automatic deployment migration.
 * Runs on the first request after the new PHP file is deployed, not when a key
 * happens to be used. Compare-and-swap prevents overwriting concurrent changes.
 */
function spa_migrate_legacy_server_api_keys(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  try {
    $pdo = db();
    $rows = $pdo->query("SELECT id,kp_api_key,youtube_api_key,tmdb_api_key,imdb_api_key FROM spa_servers WHERE (kp_api_key<>'' AND LEFT(kp_api_key,7)<>'enc:v1:') OR (youtube_api_key<>'' AND LEFT(youtube_api_key,7)<>'enc:v1:') OR (tmdb_api_key<>'' AND LEFT(tmdb_api_key,7)<>'enc:v1:') OR (imdb_api_key<>'' AND LEFT(imdb_api_key,7)<>'enc:v1:')")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return;
    // Generate/read the master secret before touching any database value.
    if (spa_api_master_key() === '') throw new RuntimeException('Не удалось автоматически создать главный секрет вне публичной папки. Проверь права записи каталога над web-root.');
    foreach ($rows as $row) {
      $id = (int)($row['id'] ?? 0);
      if ($id <= 0) continue;
      foreach (['kp_api_key','youtube_api_key','tmdb_api_key','imdb_api_key'] as $column) {
        $plain = trim((string)($row[$column] ?? ''));
        if ($plain === '' || strncmp($plain, 'enc:v1:', 7) === 0) continue;
        $encrypted = spa_api_key_encrypt($plain);
        if (strlen($encrypted) > 128) throw new RuntimeException('Старый API-ключ превышает поддерживаемую длину зашифрованного поля.');
        $st = $pdo->prepare("UPDATE spa_servers SET `{$column}`=? WHERE id=? AND `{$column}`=?");
        $st->execute([$encrypted, $id, $plain]);
      }
    }
  } catch (Throwable $e) {
    // Do not interrupt the SPA and never write key material to logs.
    error_log('StreamLive API key migration: ' . $e->getMessage());
  }
}
function spa_kp_http_json(string $path, array $params, string $apiKey): array {
  $url = 'https://kinopoiskapiunofficial.tech' . $path;
  if ($params) $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
  $body = false; $status = 0;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    if ($ch !== false) {
      curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>18, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_HTTPHEADER=>['Accept: application/json','X-API-KEY: '.$apiKey], CURLOPT_USERAGENT=>'StreamLive-SPA/Kinopoisk']);
      $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    }
  } else {
    $ctx = stream_context_create(['http'=>['method'=>'GET','timeout'=>18,'ignore_errors'=>true,'header'=>"Accept: application/json\r\nX-API-KEY: {$apiKey}\r\nUser-Agent: StreamLive-SPA/Kinopoisk\r\n"],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $body = @file_get_contents($url, false, $ctx);
    if (isset($http_response_header) && is_array($http_response_header)) foreach ($http_response_header as $h) if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $h, $m)) $status=(int)$m[1];
  }
  if ($body === false || $body === '') return ['ok'=>false,'http'=>$status,'error'=>'Не удалось связаться с API Кинопоиска.'];
  $data = json_decode((string)$body, true);
  if (!is_array($data)) return ['ok'=>false,'http'=>$status,'error'=>'Кинопоиск вернул некорректный ответ.'];
  if ($status < 200 || $status >= 300) return ['ok'=>false,'http'=>$status,'error'=>'Кинопоиск API HTTP '.$status];
  return ['ok'=>true,'http'=>$status,'data'=>$data];
}
function spa_require_same_origin_post(): void {
  $hostHeader = (string)($_SERVER['HTTP_HOST'] ?? '');
  $host = strtolower((string)(parse_url('https://' . $hostHeader, PHP_URL_HOST) ?? ''));
  $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
  if ($origin !== '') {
    $originHost = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?? ''));
    if ($originHost === '' || $host === '' || !hash_equals($host, $originHost)) spa_json(['ok'=>false,'error'=>'Запрос отклонён проверкой источника.'], 403);
  }
  $fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
  if ($fetchSite === 'cross-site') spa_json(['ok'=>false,'error'=>'Межсайтовый запрос запрещён.'], 403);
}

// Automatic one-time-per-request migration: legacy shared keys are encrypted immediately after deployment.
spa_migrate_legacy_server_api_keys();

function spa_avatar(array $u, int $size = 96): string {
  if (function_exists('user_avatar_url')) return user_avatar_url($u, $size);
  $a = trim((string)($u['avatar'] ?? ''));
  if ($a !== '' && $a !== 'null') {
    if ($a[0] === '/' || preg_match('#^https?://#i', $a)) return $a;
    return '/' . ltrim($a, '/');
  }
  return "https://www.gravatar.com/avatar/?d=identicon&s={$size}";
}

function spa_user_public(array $u): array {
  return [
    'id' => (int)$u['id'],
    'username' => (string)($u['username'] ?? ''),
    'avatar' => spa_avatar($u, 128),
    'role' => (string)($u['role'] ?? 'user'),
    'is_verified' => !empty($u['is_verified']),
    'created_at' => (string)($u['created_at'] ?? ''),
  ];
}

function spa_has_sub(int $userId): bool {
  $st = db()->prepare('SELECT subscription_until FROM spa_login_streak WHERE user_id=?');
  $st->execute([$userId]);
  $until = $st->fetchColumn();
  return $until && strtotime((string)$until) > time();
}

function spa_touch_streak(int $userId): array {
  $pdo = db();
  $today = (new DateTime('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
  $stmt = $pdo->prepare('SELECT * FROM spa_login_streak WHERE user_id = ?');
  $stmt->execute([$userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $streak = 0;
  $until = null;
  if (!$row) {
    $streak = 1;
    $pdo->prepare('INSERT INTO spa_login_streak (user_id, last_login_date, streak_days) VALUES (?,?,?)')
      ->execute([$userId, $today, 1]);
  } else {
    $last = (string)($row['last_login_date'] ?? '');
    $streak = (int)($row['streak_days'] ?? 0);
    $until = $row['subscription_until'] ?? null;
    if ($last !== $today) {
      $yesterday = (new DateTime('now', new DateTimeZone('Europe/Moscow')))->modify('-1 day')->format('Y-m-d');
      $streak = ($last === $yesterday) ? $streak + 1 : 1;
      if ($streak >= 14) {
        $base = new DateTime('now', new DateTimeZone('Europe/Moscow'));
        if ($until && strtotime((string)$until) > time()) {
          $base = new DateTime((string)$until, new DateTimeZone('Europe/Moscow'));
        }
        $base->modify('+30 days');
        $until = $base->format('Y-m-d H:i:s');
        $streak = 0;
      }
      $pdo->prepare('UPDATE spa_login_streak SET last_login_date=?, streak_days=?, subscription_until=? WHERE user_id=?')
        ->execute([$today, $streak, $until, $userId]);
    }
  }
  return [
    'streak_days' => $streak,
    'streak_need' => 14,
    'subscription_until' => $until,
    'subscription_active' => $until && strtotime((string)$until) > time(),
  ];
}

function spa_get_streak(int $userId): array {
  $stmt = db()->prepare('SELECT * FROM spa_login_streak WHERE user_id = ?');
  $stmt->execute([$userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
  $until = $row['subscription_until'] ?? null;
  return [
    'streak_days' => (int)($row['streak_days'] ?? 0),
    'streak_need' => 14,
    'subscription_until' => $until,
    'subscription_active' => $until && strtotime((string)$until) > time(),
    'last_login_date' => $row['last_login_date'] ?? null,
  ];
}

/** freemium: 1 profile / 1 channel / 1 video per day without sub */

function spa_server_code(): string {
  $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $out = '';
  for ($i = 0; $i < 12; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
  return $out;
}

function spa_user_server(int $userId): ?array {
  try {
    $st = db()->prepare('SELECT s.*, m.role FROM spa_server_members m JOIN spa_servers s ON s.id = m.server_id WHERE m.user_id = ? LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  } catch (Throwable $e) { return null; }
}

function spa_server_can(int $serverId, int $userId, string $permission): bool {
  try {
    $st = db()->prepare('SELECT m.role, mr.role_id, r.permissions_json FROM spa_server_members m LEFT JOIN spa_server_member_roles mr ON mr.server_id=m.server_id AND mr.user_id=m.user_id LEFT JOIN spa_server_roles r ON r.id=mr.role_id AND r.server_id=m.server_id WHERE m.server_id=? AND m.user_id=? LIMIT 1');
    $st->execute([$serverId,$userId]); $row=$st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    if (($row['role'] ?? '') === 'owner') return true;
    $perms=json_decode((string)($row['permissions_json'] ?? ''),true);
    return is_array($perms) && !empty($perms[$permission]);
  } catch (Throwable $e) { return false; }
}

function spa_server_audit(int $serverId, int $actorId, string $action, string $details = ''): void {
  try { db()->prepare('INSERT INTO spa_server_audit(server_id,actor_id,action,details) VALUES(?,?,?,?)')->execute([$serverId,$actorId,$action,mb_substr($details,0,500)]); } catch (Throwable $e) {}
}
function spa_server_require(string $permission = ''): array {
  global $user;
  if (!$user) spa_json(['ok'=>false,'error'=>'Требуется вход'],401);
  $srv=spa_user_server((int)$user['id']);
  if (!$srv) spa_json(['ok'=>false,'error'=>'Ты не состоишь в сервере'],403);
  if ($permission !== '' && !spa_server_can((int)$srv['id'],(int)$user['id'],$permission)) spa_json(['ok'=>false,'error'=>'Недостаточно прав'],403);
  return $srv;
}

function spa_server_member_count(int $serverId): int {
  try {
    $st = db()->prepare('SELECT COUNT(*) FROM spa_server_members WHERE server_id = ?');
    $st->execute([$serverId]);
    return (int)$st->fetchColumn();
  } catch (Throwable $e) { return 0; }
}

/**
 * Лимит без подписки (акция как Black Russia X2):
 * - без сервера: 1
 * - в сервере: 1 × N участников (2 → ×2, 3 → ×3 …)
 * - от 5 участников: +10 бонус
 * - сб/вс (МСК): множитель акции ×2
 * - макс 80
 */
function spa_promo_multiplier(): int {
  $dow = (int)(new DateTime('now', new DateTimeZone('Europe/Moscow')))->format('N'); // 6,7 = weekend
  return ($dow >= 6) ? 2 : 1;
}

function spa_server_boost_units(int $userId): int {
  $srv = spa_user_server($userId);
  if (!$srv) return 0;
  $n = max(1, spa_server_member_count((int)$srv['id']));
  // Те же действующие уровни серверных бафов, что используются для контентных квот.
  $bonus = max(0, $n - 1);
  if ($n >= 3) $bonus += 2;
  if ($n >= 5) $bonus += 10;
  if ($n >= 10) $bonus += 15;
  // Бонус владельца считается бафом сервера и действует на всех участников.
  if ((int)($srv['owner_id'] ?? 0) > 0) $bonus += 3;
  return $bonus;
}

function spa_daily_quota(int $userId): int {
  if (spa_has_sub($userId)) return 999;
  $srv = spa_user_server($userId);
  if (!$srv) return 1;
  $q = 1 + spa_server_boost_units($userId);
  $q *= spa_promo_multiplier();
  return min(120, $q);
}

function spa_vault_quota_status(int $userId): array {
  $sub = spa_has_sub($userId);
  $limit = $sub ? 999999 : spa_daily_quota($userId);
  try {
    $st = db()->prepare('SELECT used_actions,window_started_at,blocked_until FROM spa_vault_usage WHERE user_id=? LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    return ['used'=>0,'quota'=>$limit,'remaining'=>$sub?999999:$limit,'subscription'=>$sub,'server_boost'=>spa_server_boost_units($userId),'blocked_until'=>null,'retry_seconds'=>0,'limited'=>false,'available'=>false];
  }
  $used = max(0,(int)($row['used_actions'] ?? 0));
  $blocked = (string)($row['blocked_until'] ?? '');
  $start = (string)($row['window_started_at'] ?? '');
  $remainingSeconds = $blocked !== '' ? max(0,strtotime($blocked)-time()) : 0;
  if (($remainingSeconds <= 0 && $blocked !== '') || ($start !== '' && strtotime($start)>0 && (time()-strtotime($start))>=86400)) { $used = 0; $remainingSeconds = 0; }
  return ['used'=>$used,'quota'=>$limit,'remaining'=>$sub?999999:max(0,$limit-$used),'subscription'=>$sub,'server_boost'=>spa_server_boost_units($userId),'blocked_until'=>$remainingSeconds>0?$blocked:null,'retry_seconds'=>$remainingSeconds,'limited'=>!$sub && $remainingSeconds>0];
}

function spa_vault_action_charge(int $userId, string $actionType): array {
  if (!in_array($actionType,['upload','open','download'],true)) return ['ok'=>false,'error'=>'Неизвестное действие хранилища'];
  $pdo=db();$sub=spa_has_sub($userId);$limit=$sub?999999:spa_daily_quota($userId);$pdo->beginTransaction();
  try {
    $pdo->prepare('INSERT IGNORE INTO spa_vault_usage(user_id,used_actions,window_started_at,blocked_until) VALUES(?,0,NULL,NULL)')->execute([$userId]);
    $q=$pdo->prepare('SELECT used_actions,window_started_at,blocked_until FROM spa_vault_usage WHERE user_id=? FOR UPDATE');$q->execute([$userId]);$row=$q->fetch(PDO::FETCH_ASSOC)?:[];
    $now=time();$blocked=(string)($row['blocked_until']??'');$start=(string)($row['window_started_at']??'');$used=max(0,(int)($row['used_actions']??0));
    if($blocked!=='' && strtotime($blocked)>$now){$left=strtotime($blocked)-$now;$pdo->commit();return ['ok'=>false,'limited'=>true,'error'=>'Лимит действий хранилища исчерпан. Повторная попытка будет доступна через 24 часа.','used'=>$used,'quota'=>$limit,'remaining'=>0,'retry_seconds'=>$left,'retry_at'=>$blocked];}
    if($start==='' || strtotime($start)<=0 || ($now-strtotime($start))>=86400){$used=0;$start=date('Y-m-d H:i:s',$now);$blocked='';}
    if(!$sub && $used >= $limit){$until=date('Y-m-d H:i:s',strtotime($start)+86400);$pdo->prepare('UPDATE spa_vault_usage SET blocked_until=?,window_started_at=?,used_actions=? WHERE user_id=?')->execute([$until,$start,$used,$userId]);$pdo->commit();return ['ok'=>false,'limited'=>true,'error'=>'Лимит действий хранилища исчерпан. Доступ восстановится через 24 часа.','used'=>$used,'quota'=>$limit,'remaining'=>0,'retry_seconds'=>max(0,strtotime($until)-$now),'retry_at'=>$until];}
    $used++;$until=(!$sub && $used >= $limit)?date('Y-m-d H:i:s',strtotime($start)+86400):null;
    $pdo->prepare('UPDATE spa_vault_usage SET used_actions=?,window_started_at=?,blocked_until=? WHERE user_id=?')->execute([$used,$start,$until,$userId]);$pdo->commit();
    return ['ok'=>true,'action'=>$actionType,'used'=>$used,'quota'=>$limit,'remaining'=>$sub?999999:max(0,$limit-$used),'subscription'=>$sub,'server_boost'=>spa_server_boost_units($userId),'blocked_until'=>$until,'retry_seconds'=>$until?max(0,strtotime($until)-$now):0];
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();return ['ok'=>false,'error'=>'Не удалось списать действие хранилища'];}
}

function spa_storage_quota_bytes(int $userId): int {
  $baseGb = spa_has_sub($userId) ? 100 : 10;
  // Каждый активный уровень серверного бафа добавляет 1 ГБ; выходные не удваивают хранилище.
  $bonusGb = spa_server_boost_units($userId);
  return ($baseGb + $bonusGb) * 1024 * 1024 * 1024;
}

function spa_quota_info(int $userId): array {
  $srv = spa_user_server($userId);
  $n = $srv ? spa_server_member_count((int)$srv['id']) : 0;
  $promo = spa_promo_multiplier();
  return [
    'quota' => spa_daily_quota($userId),
    'members' => $n,
    'in_server' => (bool)$srv,
    'promo_x' => $promo,
    'promo_active' => $promo > 1,
    'bonus_5' => $n >= 5,
    'has_sub' => spa_has_sub($userId),
  ];
}

/** Лимиты будущих встроенных сервисов. Единицы списываются только при реальном действии сервиса. */
function spa_service_quota_config(): array {
  return [
    'tmdb' => ['title'=>'TMDB','unit'=>'запросов','base'=>40,'per_boost'=>5,'max'=>200],
    'imdb' => ['title'=>'IMDb','unit'=>'запросов','base'=>40,'per_boost'=>5,'max'=>200],
  ];
}

function spa_service_quota(int $userId, string $service): array {
  $cfg = spa_service_quota_config();
  if (!isset($cfg[$service])) return ['ok'=>false,'error'=>'Неизвестный сервис'];
  $sub = spa_has_sub($userId);
  $boost = spa_server_boost_units($userId);
  $limit = $sub ? 999999 : min((int)$cfg[$service]['max'], (int)$cfg[$service]['base'] + $boost * (int)$cfg[$service]['per_boost']);
  $q = db()->prepare('SELECT used_units FROM spa_service_daily_usage WHERE user_id=? AND service_key=? AND day_date=?');
  $q->execute([$userId, $service, date('Y-m-d')]);
  $used = max(0, (int)($q->fetchColumn() ?: 0));
  return ['ok'=>true,'service'=>$service,'title'=>$cfg[$service]['title'],'unit'=>$cfg[$service]['unit'],'used'=>$used,'quota'=>$limit,'remaining'=>$sub?999999:max(0,$limit-$used),'limited'=>!$sub && $used >= $limit,'subscription'=>$sub,'server_boost'=>$boost,'reset_type'=>'midnight'];
}

function spa_service_quota_charge(int $userId, string $service, int $units = 1): array {
  $cfg = spa_service_quota_config();
  if (!isset($cfg[$service])) return ['ok'=>false,'error'=>'Неизвестный сервис'];
  $units = max(1, min(500, $units));
  $pdo = db();
  $sub = spa_has_sub($userId);
  $boost = spa_server_boost_units($userId);
  $limit = $sub ? 999999 : min((int)$cfg[$service]['max'], (int)$cfg[$service]['base'] + $boost * (int)$cfg[$service]['per_boost']);
  $day = date('Y-m-d');
  $pdo->beginTransaction();
  try {
    $pdo->prepare('INSERT IGNORE INTO spa_service_daily_usage(user_id,service_key,day_date,used_units) VALUES(?,?,?,0)')->execute([$userId,$service,$day]);
    $st = $pdo->prepare('SELECT used_units FROM spa_service_daily_usage WHERE user_id=? AND service_key=? AND day_date=? FOR UPDATE');
    $st->execute([$userId,$service,$day]);
    $used = max(0,(int)$st->fetchColumn());
    if (!$sub && ($used + $units) > $limit) {
      $pdo->commit();
      return ['ok'=>false,'limited'=>true,'error'=>'Дневной лимит сервиса исчерпан. Серверные бафы увеличивают квоту.','used'=>$used,'quota'=>$limit,'remaining'=>max(0,$limit-$used),'server_boost'=>$boost];
    }
    $newUsed = $used + $units;
    $pdo->prepare('UPDATE spa_service_daily_usage SET used_units=? WHERE user_id=? AND service_key=? AND day_date=?')->execute([$newUsed,$userId,$service,$day]);
    $pdo->commit();
    return ['ok'=>true,'used'=>$newUsed,'quota'=>$limit,'remaining'=>$sub?999999:max(0,$limit-$newUsed),'subscription'=>$sub,'server_boost'=>$boost];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    return ['ok'=>false,'error'=>'Не удалось проверить лимит сервиса'];
  }
}

function spa_server_sys_msg(int $serverId, int $actorUserId, string $body): void {
  try {
    db()->prepare('INSERT INTO spa_server_messages (server_id, user_id, body, is_system) VALUES (?,?,?,1)')
      ->execute([$serverId, $actorUserId, $body]);
  } catch (Throwable $e) {
    try {
      db()->prepare('INSERT INTO spa_server_messages (server_id, user_id, body) VALUES (?,?,?)')
        ->execute([$serverId, $actorUserId, $body]);
    } catch (Throwable $e2) {}
  }
}

function spa_server_touch(int $userId): void {
  try {
    db()->prepare('UPDATE spa_server_members SET last_active = NOW() WHERE user_id = ?')->execute([$userId]);
  } catch (Throwable $e) {}
}

/** Автокик неактивных участников: только если владелец включил срок 30/60/90 дней. По умолчанию выключен. */
function spa_server_prune_inactive(?int $serverId = null): void {
  try {
    $sql = "SELECT m.server_id, m.user_id, u.username, s.inactive_kick_days FROM spa_server_members m
      JOIN spa_servers s ON s.id=m.server_id
      LEFT JOIN users u ON u.id = m.user_id
      WHERE m.role <> 'owner' AND s.inactive_kick_days IN (30,60,90)
        AND (
          (m.last_active IS NOT NULL AND m.last_active < DATE_SUB(NOW(), INTERVAL s.inactive_kick_days DAY))
          OR (m.last_active IS NULL AND m.joined_at < DATE_SUB(NOW(), INTERVAL s.inactive_kick_days DAY))
        )";
    $params = [];
    if ($serverId) {
      $sql .= " AND m.server_id = ?";
      $params[] = $serverId;
    }
    $st = db()->prepare($sql);
    $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $sid = (int)$row['server_id'];
      $uid = (int)$row['user_id'];
      $name = (string)($row['username'] ?? ('id'.$uid));
      db()->prepare('DELETE FROM spa_server_members WHERE server_id=? AND user_id=?')->execute([$sid, $uid]);
      spa_server_sys_msg($sid, $uid, $name . ' покинул(а) сервер (неактив 30+ дней)');
    }
  } catch (Throwable $e) {}
}

function spa_channel_blocked(int $userId, int $channelId): bool {
  if ($channelId <= 0 || $userId <= 0) return false;
  $tries = [
    "SELECT 1 FROM channel_bans WHERE channel_id=? AND user_id=? LIMIT 1",
    "SELECT 1 FROM channel_blocks WHERE channel_id=? AND user_id=? LIMIT 1",
    "SELECT 1 FROM channel_banned_users WHERE channel_id=? AND user_id=? LIMIT 1",
    "SELECT 1 FROM bans WHERE channel_id=? AND user_id=? LIMIT 1",
  ];
  foreach ($tries as $sql) {
    try {
      $st = db()->prepare($sql);
      $st->execute([$channelId, $userId]);
      if ($st->fetchColumn()) return true;
    } catch (Throwable $e) {}
  }
  return false;
}


function spa_limit_status(int $userId, string $kind): array {
  if (spa_has_sub($userId)) {
    return ['ok' => true, 'limited' => false, 'remaining' => 999, 'subscription' => true, 'quota' => 999];
  }
  $map = [
    'profile' => ['count' => 'profiles', 'until' => 'profiles_blocked_until'],
    'channel' => ['count' => 'channels', 'until' => 'channels_blocked_until'],
    'video' => ['count' => 'videos', 'until' => 'videos_blocked_until'],
    'cinema' => ['count' => 'cinemas', 'until' => 'cinemas_blocked_until'],
    'music' => ['count' => 'music', 'until' => 'music_blocked_until'],
  ];
  if (!isset($map[$kind])) return ['ok' => true, 'limited' => false, 'remaining' => 999, 'quota' => 999];
  $quota = spa_daily_quota($userId);
  $keyDate = '2000-01-01';
  $pdo = db();
  $pdo->prepare('INSERT IGNORE INTO spa_daily_limits (user_id, day_date, profiles, channels, videos, cinemas, music) VALUES (?,?,0,0,0,0,0)')->execute([$userId, $keyDate]);
  $cfg = $map[$kind];
  $untilCol = $cfg['until'];
  $sql = 'SELECT `' . $cfg['count'] . '` AS used, `' . $untilCol . '` AS blocked_until, '
    . 'CASE WHEN `' . $untilCol . '` IS NOT NULL AND `' . $untilCol . '` > NOW() '
    . 'THEN GREATEST(0,TIMESTAMPDIFF(SECOND,NOW(),`' . $untilCol . '`)) ELSE 0 END AS cooldown_left '
    . 'FROM spa_daily_limits WHERE user_id=? AND day_date=? LIMIT 1';
  $st = $pdo->prepare($sql);
  $st->execute([$userId, $keyDate]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $left = max(0, (int)($row['cooldown_left'] ?? 0));
  if ($left > 0) {
    return ['ok' => false, 'limited' => true, 'remaining' => 0, 'quota' => $quota, 'subscription' => false,
      'retry_at' => (string)$row['blocked_until'], 'retry_seconds' => $left, 'time_storage' => 'datetime'];
  }
  $expired = !empty($row['blocked_until']);
  $used = $expired ? 0 : (int)($row['used'] ?? 0);
  // Cooldowns are stored/calculated as MySQL DATETIME (years 1000–9999), never as a 32-bit Unix timestamp.
  if ($used >= $quota) {
    $pdo->prepare('UPDATE spa_daily_limits SET `' . $untilCol . '`=DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE user_id=? AND day_date=?')
      ->execute([$userId, $keyDate]);
    $q = $pdo->prepare('SELECT `' . $untilCol . '` AS retry_at, GREATEST(0,TIMESTAMPDIFF(SECOND,NOW(),`' . $untilCol . '`)) AS retry_seconds FROM spa_daily_limits WHERE user_id=? AND day_date=?');
    $q->execute([$userId, $keyDate]); $until = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['ok' => false, 'limited' => true, 'remaining' => 0, 'quota' => $quota, 'subscription' => false,
      'retry_at' => (string)($until['retry_at'] ?? ''), 'retry_seconds' => max(0,(int)($until['retry_seconds'] ?? 86400)), 'time_storage' => 'datetime'];
  }
  return ['ok' => true, 'limited' => false, 'remaining' => max(0, $quota - $used), 'quota' => $quota, 'subscription' => false];
}

function spa_check_limit(int $userId, string $kind): array {
  if (spa_has_sub($userId)) {
    return ['ok' => true, 'limited' => false, 'remaining' => 999, 'subscription' => true, 'quota' => 999];
  }
  $map = [
    'profile' => ['count' => 'profiles', 'until' => 'profiles_blocked_until', 'label' => 'профилей'],
    'channel' => ['count' => 'channels', 'until' => 'channels_blocked_until', 'label' => 'каналов'],
    'video' => ['count' => 'videos', 'until' => 'videos_blocked_until', 'label' => 'видео'],
    'cinema' => ['count' => 'cinemas', 'until' => 'cinemas_blocked_until', 'label' => 'фильмов и сериалов'],
    'music' => ['count' => 'music', 'until' => 'music_blocked_until', 'label' => 'запусков музыки'],
  ];
  if (!isset($map[$kind])) return ['ok' => true, 'limited' => false];
  $cfg = $map[$kind]; $col = $cfg['count']; $untilCol = $cfg['until']; $quota = spa_daily_quota($userId);
  $pdo = db(); $ownTx = !$pdo->inTransaction();
  if ($ownTx) $pdo->beginTransaction();
  $finish = function(array $result) use ($pdo, $ownTx): array {
    if ($ownTx && $pdo->inTransaction()) $pdo->commit();
    return $result;
  };
  $keyDate = '2000-01-01';
  $pdo->prepare('INSERT IGNORE INTO spa_daily_limits (user_id, day_date, profiles, channels, videos, cinemas, music) VALUES (?,?,0,0,0,0,0)')->execute([$userId, $keyDate]);
  $sql = 'SELECT `' . $col . '` AS used, `' . $untilCol . '` AS blocked_until, '
    . 'CASE WHEN `' . $untilCol . '` IS NOT NULL AND `' . $untilCol . '` > NOW() '
    . 'THEN GREATEST(0,TIMESTAMPDIFF(SECOND,NOW(),`' . $untilCol . '`)) ELSE 0 END AS cooldown_left '
    . 'FROM spa_daily_limits WHERE user_id=? AND day_date=? LIMIT 1 FOR UPDATE';
  $st = $pdo->prepare($sql); $st->execute([$userId, $keyDate]); $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $left = max(0, (int)($row['cooldown_left'] ?? 0));
  if ($left > 0) {
    return $finish(['ok'=>false,'limited'=>true,'remaining'=>0,'quota'=>$quota,'subscription'=>false,
      'retry_at'=>(string)$row['blocked_until'],'retry_seconds'=>$left,'time_storage'=>'datetime',
      'message'=>'Лимит исчерпан. Доступ восстановится через 24 часа после достижения лимита (' . (string)$row['blocked_until'] . ').']);
  }
  // Истёкшая дата сбрасывает только конкретную категорию.
  if (!empty($row['blocked_until'])) {
    $pdo->prepare("UPDATE spa_daily_limits SET `{$col}`=0, `{$untilCol}`=NULL WHERE user_id=? AND day_date=?")->execute([$userId, $keyDate]);
    $used = 0;
  } else { $used = (int)($row['used'] ?? 0); }
  if ($used >= $quota) {
    $pdo->prepare("UPDATE spa_daily_limits SET `{$untilCol}`=DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE user_id=? AND day_date=?")->execute([$userId, $keyDate]);
    $q=$pdo->prepare("SELECT `{$untilCol}` AS retry_at, GREATEST(0,TIMESTAMPDIFF(SECOND,NOW(),`{$untilCol}`)) AS retry_seconds FROM spa_daily_limits WHERE user_id=? AND day_date=?");
    $q->execute([$userId,$keyDate]);$until=$q->fetch(PDO::FETCH_ASSOC)?:[];
    return $finish(['ok'=>false,'limited'=>true,'remaining'=>0,'quota'=>$quota,'subscription'=>false,
      'retry_at'=>(string)($until['retry_at']??''),'retry_seconds'=>max(0,(int)($until['retry_seconds']??86400)),'time_storage'=>'datetime',
      'message'=>'Лимит исчерпан. Доступ восстановится через 24 часа (' . (string)($until['retry_at']??'') . ').']);
  }
  $newUsed = $used + 1;
  if ($newUsed >= $quota) {
    $pdo->prepare("UPDATE spa_daily_limits SET `{$col}`=?, `{$untilCol}`=DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE user_id=? AND day_date=?")
      ->execute([$newUsed,$userId,$keyDate]);
    $q=$pdo->prepare("SELECT `{$untilCol}` AS retry_at, GREATEST(0,TIMESTAMPDIFF(SECOND,NOW(),`{$untilCol}`)) AS retry_seconds FROM spa_daily_limits WHERE user_id=? AND day_date=?");
    $q->execute([$userId,$keyDate]);$until=$q->fetch(PDO::FETCH_ASSOC)?:[];
    return $finish(['ok'=>true,'limited'=>false,'remaining'=>0,'quota'=>$quota,'subscription'=>false,
      'retry_at'=>(string)($until['retry_at']??''),'retry_seconds'=>max(0,(int)($until['retry_seconds']??86400)),'time_storage'=>'datetime']);
  }
  $pdo->prepare("UPDATE spa_daily_limits SET `{$col}`=? WHERE user_id=? AND day_date=?")->execute([$newUsed,$userId,$keyDate]);
  return $finish(['ok'=>true,'limited'=>false,'remaining'=>$quota-$newUsed,'quota'=>$quota,'subscription'=>false]);
}

function spa_is_mutual(int $a, int $b): bool {
  if ($a === $b) return true;
  if (function_exists('contacts_are_mutual')) {
    try { return (bool)contacts_are_mutual($a, $b); } catch (Throwable $e) {}
  }
  // fallback: any mutual presence of favorites user↔user
  try {
    $st = db()->prepare("SELECT COUNT(*) FROM spa_favorites f1
      JOIN spa_favorites f2 ON f2.user_id = f1.target_id AND f2.target_type='user' AND f2.target_id = f1.user_id
      WHERE f1.user_id=? AND f1.target_type='user' AND f1.target_id=?");
    $st->execute([$a, $b]);
    if ((int)$st->fetchColumn() > 0) return true;
  } catch (Throwable $e) {}
  return false;
}

/* ───────── API ───────── */
$action = $_GET['api'] ?? $_POST['api'] ?? null;
if ($action) {
  $user = function_exists('current_user') ? current_user() : null;

  if ($action === 'version') {
    spa_json(['ok' => true, 'build' => SPA_BUILD, 'min_build' => '20261121013000', 'time' => date('c'), 'runtime' => ['php_version'=>PHP_VERSION,'php_int_size'=>PHP_INT_SIZE,'timestamp_64bit'=>(PHP_INT_SIZE>=8)]]);
  }

  if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $login = trim((string)($_POST['username'] ?? $_POST['phone'] ?? ''));
    if ($login === '' || $password === '') spa_json(['ok' => false, 'error' => 'Заполните логин и пароль'], 400);
    try {
      $stmt = db()->prepare('SELECT * FROM users WHERE phone = ? OR username = ? LIMIT 1');
      $stmt->execute([$login, $login]);
      $u = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$u || !password_verify($password, (string)($u['password_hash'] ?? ''))) {
        spa_json(['ok' => false, 'error' => 'Неверный логин или пароль'], 401);
      }
      if (!empty($u['is_banned'])) spa_json(['ok' => false, 'error' => 'Аккаунт заблокирован'], 403);
      login_user((int)$u['id']);
      spa_json(['ok' => true, 'user' => spa_user_public($u), 'streak' => spa_touch_streak((int)$u['id'])]);
    } catch (Throwable $e) {
      spa_json(['ok' => false, 'error' => 'Ошибка БД'], 500);
    }
  }

  if ($action === 'logout') {
    if ($user) { try { db()->prepare('DELETE FROM spa_presence WHERE user_id=?')->execute([(int)$user['id']]); } catch (Throwable $e) {} }
    if (function_exists('logout_user')) logout_user();
    elseif (session_status() === PHP_SESSION_ACTIVE) { $_SESSION = []; session_destroy(); }
    spa_json(['ok' => true]);
  }

  if ($action === 'me') {
    if (!$user) spa_json(['ok' => false, 'auth' => false], 401);
    spa_json(['ok' => true, 'user' => spa_user_public($user), 'streak' => spa_touch_streak((int)$user['id']), 'has_sub' => spa_has_sub((int)$user['id'])]);
  }

  if ($action === 'presence' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok' => false], 401);
    db()->prepare('INSERT INTO spa_presence (user_id, tab_name, activity, game, detail) VALUES (?,?,?,?,?)
      ON DUPLICATE KEY UPDATE tab_name=VALUES(tab_name), activity=VALUES(activity), game=VALUES(game), detail=VALUES(detail), updated_at=NOW()')
      ->execute([
        (int)$user['id'],
        mb_substr(trim((string)($_POST['tab'] ?? '')), 0, 64),
        mb_substr(trim((string)($_POST['activity'] ?? '')), 0, 128),
        mb_substr(trim((string)($_POST['game'] ?? '')), 0, 128),
        mb_substr(trim((string)($_POST['detail'] ?? '')), 0, 255),
      ]);
    spa_json(['ok' => true]);
  }

  if ($action === 'catalog') {
    if (!$user) spa_json(['ok' => false, 'auth' => false, 'error' => 'Нужен вход'], 401);
    $q = trim((string)($_GET['q'] ?? ''));
    $type = (string)($_GET['type'] ?? 'all');
    $items = [];
    $errors = [];

    if ($type === 'all' || $type === 'users') {
      try {
        $sql = "SELECT id, username, avatar, role, created_at FROM users";
        $params = [];
        $where = [];
        // is_banned может отсутствовать на зеркале
        try {
          db()->query("SELECT is_banned FROM users LIMIT 0");
          $where[] = "(is_banned = 0 OR is_banned IS NULL)";
        } catch (Throwable $e) {}
        if ($q !== '') {
          $where[] = "username LIKE ?";
          $params[] = '%'.$q.'%';
        }
        if ($where) $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " ORDER BY id DESC LIMIT 50";
        $st = db()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
          $items[] = [
            'kind' => 'user', 'id' => (int)$u['id'],
            'title' => (string)($u['username'] ?? ''),
            'subtitle' => '@'.($u['username'] ?? ''),
            'avatar' => spa_avatar($u, 96),
            'username' => (string)($u['username'] ?? ''),
            'time' => (string)($u['created_at'] ?? ''),
          ];
        }
      } catch (Throwable $e) {
        $errors[] = 'users: '.$e->getMessage();
        try {
          $st = db()->query("SELECT id, username, avatar FROM users ORDER BY id DESC LIMIT 50");
          foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $items[] = [
              'kind' => 'user', 'id' => (int)$u['id'],
              'title' => (string)($u['username'] ?? ''),
              'subtitle' => '@'.($u['username'] ?? ''),
              'avatar' => spa_avatar($u, 96),
              'username' => (string)($u['username'] ?? ''),
              'time' => '',
            ];
          }
        } catch (Throwable $e2) {
          $errors[] = 'users2: '.$e2->getMessage();
        }
      }
    }

    if ($type === 'all' || $type === 'channels') {
      try {
        $sql = "SELECT id, title, slug, logo_url, created_at FROM channels";
        $params = [];
        if ($q !== '') {
          $sql .= " WHERE title LIKE ? OR slug LIKE ?";
          $params[] = '%'.$q.'%';
          $params[] = '%'.$q.'%';
        }
        $sql .= " ORDER BY id DESC LIMIT 50";
        $st = db()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
          $logo = (string)($c['logo_url'] ?? '');
          if ($logo !== '' && $logo[0] !== '/' && !preg_match('#^https?://#i', $logo)) $logo = '/'.ltrim($logo, '/');
          $items[] = [
            'kind' => 'channel', 'id' => (int)$c['id'],
            'title' => (string)($c['title'] ?? ''),
            'subtitle' => 'Канал · '.($c['slug'] ?? ''),
            'avatar' => $logo !== '' ? $logo : 'https://www.gravatar.com/avatar/?d=identicon&s=96',
            'slug' => (string)($c['slug'] ?? ''),
            'time' => (string)($c['created_at'] ?? ''),
          ];
        }
      } catch (Throwable $e) {
        $errors[] = 'channels: '.$e->getMessage();
        try {
          $st = db()->query("SELECT id, title, slug FROM channels ORDER BY id DESC LIMIT 50");
          foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $items[] = [
              'kind' => 'channel', 'id' => (int)$c['id'],
              'title' => (string)($c['title'] ?? ''),
              'subtitle' => 'Канал',
              'avatar' => 'https://www.gravatar.com/avatar/?d=identicon&s=96',
              'slug' => (string)($c['slug'] ?? ''),
              'time' => '',
            ];
          }
        } catch (Throwable $e2) {
          $errors[] = 'channels2: '.$e2->getMessage();
        }
      }
    }

    if ($type === 'all' || $type === 'videos' || $type === 'cinema' || $type === 'music') {
      try {
        // минимальный SELECT — без status / thumbnail_url если нет
        $sql = "SELECT v.id, v.title FROM videos v ORDER BY v.id DESC LIMIT 50";
        $rows = [];
        try {
          $sqlFull = "SELECT v.id, v.slug, v.title, v.created_at, v.thumbnail_url, v.embed_url, v.source_url, v.platform,
            c.slug as channel_slug, c.title as channel_title
            FROM videos v LEFT JOIN channels c ON c.id = v.channel_id";
          $params = [];
          if ($q !== '') {
            $sqlFull .= " WHERE v.title LIKE ?";
            $params[] = '%'.$q.'%';
          }
          $sqlFull .= " ORDER BY v.id DESC LIMIT 50";
          $st = db()->prepare($sqlFull);
          $st->execute($params);
          $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
          $errors[] = 'videos_full: '.$e->getMessage();
          try {
            $st = db()->query("SELECT id, title, slug FROM videos ORDER BY id DESC LIMIT 50");
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
          } catch (Throwable $e2) {
            $errors[] = 'videos_min: '.$e2->getMessage();
            $rows = [];
          }
        }
        foreach ($rows as $v) {
          $th = (string)($v['thumbnail_url'] ?? '');
          if ($th !== '' && isset($th[0]) && $th[0] !== '/' && !preg_match('#^https?://#i', $th)) $th = '/'.ltrim($th, '/');
          $items[] = [
            'kind' => 'video', 'id' => (int)$v['id'],
            'slug' => (string)($v['slug'] ?? ''),
            'title' => (string)($v['title'] ?? 'Видео'),
            'subtitle' => 'Видео · '.($v['channel_title'] ?? ''),
            'avatar' => $th !== '' ? $th : 'https://www.gravatar.com/avatar/?d=mp&s=96',
            'embed_url' => (string)($v['embed_url'] ?? ''),
            'source_url' => (string)($v['source_url'] ?? ''),
            'platform' => (string)($v['platform'] ?? ''),
            'time' => (string)($v['created_at'] ?? ''),
          ];
        }
      } catch (Throwable $e) {
        $errors[] = 'videos: '.$e->getMessage();
      }
    }

    if ($type === 'cinema' || $type === 'music') {
      $reCinema = '/фильм|сериал|кино|movie|series|сериал|episode|сезон|season|cinema|дорам|anime|аниме/ui';
      $reMusic = '/музык|music|clip|клип|трек|track|song|песн|альбом|album|dj|remix|ost|soundtrack|радио|radio/ui';
      $items = array_values(array_filter($items, function ($it) use ($type, $reCinema, $reMusic) {
        if (($it['kind'] ?? '') !== 'video' && ($it['kind'] ?? '') !== 'channel') return false;
        $blob = ($it['title'] ?? '') . ' ' . ($it['subtitle'] ?? '') . ' ' . ($it['platform'] ?? '');
        if ($type === 'cinema') return (bool)preg_match($reCinema, $blob);
        return (bool)preg_match($reMusic, $blob);
      }));
    }
    spa_json(['ok' => true, 'items' => $items, 'debug' => $errors]);
  }

  if ($action === 'profile') {
    if (!$user) spa_json(['ok' => false, 'auth' => false], 401);
    $uname = trim((string)($_GET['username'] ?? $user['username'] ?? ''));
    $st = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $st->execute([$uname]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) spa_json(['ok' => false, 'error' => 'Не найден'], 404);

    $isSelf = (int)$p['id'] === (int)$user['id'];
    if (!$isSelf) {
      $lim = spa_check_limit((int)$user['id'], 'profile');
      if (!$lim['ok']) spa_json(['ok' => false, 'limited' => true, 'error' => $lim['message'], 'retry_at' => $lim['retry_at'] ?? null, 'retry_seconds' => $lim['retry_seconds'] ?? null, 'upgrade' => true], 402);
    }

    $pres = null;
    try {
      $st2 = db()->prepare('SELECT tab_name, activity, game, detail, updated_at FROM spa_presence WHERE user_id=?');
      $st2->execute([(int)$p['id']]);
      $pres = $st2->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}

    $rank = null;
    if (function_exists('get_rank_for_xp')) {
      try { $rank = get_rank_for_xp((int)($p['xp'] ?? 0)); } catch (Throwable $e) {}
    }
    $owned = [];
    try {
      $st3 = db()->prepare('SELECT id, title, slug, logo_url FROM channels WHERE owner_id = ? ORDER BY id DESC LIMIT 20');
      $st3->execute([(int)$p['id']]);
      foreach ($st3->fetchAll(PDO::FETCH_ASSOC) as $ch) {
        $logo = (string)($ch['logo_url'] ?? '');
        if ($logo && $logo[0] !== '/' && !preg_match('#^https?://#i', $logo)) $logo = '/'.ltrim($logo, '/');
        $owned[] = ['id'=>(int)$ch['id'],'title'=>(string)$ch['title'],'slug'=>(string)$ch['slug'],'logo'=>$logo];
      }
    } catch (Throwable $e) {}

    $userServer = null;
    $ps = spa_user_server((int)$p['id']);
    if ($ps) {
      $userServer = ['id' => (int)$ps['id'], 'name' => (string)$ps['name'], 'members' => spa_server_member_count((int)$ps['id'])];
    }
    spa_json([
      'ok' => true,
      'profile' => spa_user_public($p) + [
        'xp' => (int)($p['xp'] ?? 0),
        'profile_status_text' => (string)($p['profile_status_text'] ?? ''),
        'role' => (string)($p['role'] ?? 'user'),
      ],
      'rank' => $rank,
      'presence' => $pres,
      'streak' => $isSelf ? spa_get_streak((int)$p['id']) : null,
      'channels' => $owned,
      'server' => $userServer,
      'is_self' => $isSelf,
      'has_sub' => spa_has_sub((int)$user['id']),
      'quota' => spa_daily_quota((int)$user['id']),
    ]);
  }


  if ($action === 'channel_live') {
    if (!$user) spa_json(['ok' => false], 401);
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) spa_json(['ok' => false, 'error' => 'id'], 400);
    try {
      $st = db()->prepare('SELECT * FROM channels WHERE id = ? LIMIT 1');
      $st->execute([$id]);
      $c = $st->fetch(PDO::FETCH_ASSOC);
      if (!$c) spa_json(['ok' => false], 404);
      $live = null;
      if (function_exists('resolve_active_source')) {
        try {
          $src = resolve_active_source($c);
          if ($src) {
            $srcUrl = (string)($src['url'] ?? $src['source_url'] ?? $src['stream_url'] ?? $src['embed_url'] ?? '');
            $srcType = strtolower(trim((string)($src['type'] ?? $src['source_type'] ?? '')));
            // Match channel.php: iframe sources are embedded as-is; all other source types
            // are passed to the configured player (PlayerJS in the SPA).
            $live = [
              'id' => (int)($src['id'] ?? $src['source_id'] ?? 0),
              'url' => $srcUrl,
              'name' => (string)($src['name'] ?? $src['source_name'] ?? 'Live'),
              'type' => $srcType,
              'platform' => (string)($src['platform'] ?? ''),
              'updated_at' => (string)($src['updated_at'] ?? $src['created_at'] ?? ''),
            ];
          }
        } catch (Throwable $e) {}
      }
      // fallback fields on channel row (older schemas / mirrors)
      if (!$live && empty($c['is_broadcast_paused'])) {
        foreach (['live_url','stream_url','playback_url','hls_url','current_url'] as $fld) {
          if (!empty($c[$fld])) {
            $live = ['id'=>0, 'url'=>(string)$c[$fld], 'name'=>'Live', 'type'=>(string)($c['source_type'] ?? ''), 'platform'=>''];
            break;
          }
        }
      }
      $schedule = [];
      try {
        $stSch = db()->prepare('SELECT sch.*, s.name AS source_name FROM schedule sch JOIN sources s ON s.id = sch.source_id WHERE sch.channel_id = ? ORDER BY sch.day_of_week, sch.start_time');
        $stSch->execute([$id]);
        $schedule = $stSch->fetchAll(PDO::FETCH_ASSOC) ?: [];
      } catch (Throwable $e) {}
      $logo = (string)($c['logo_url'] ?? '');
      if ($logo !== '' && $logo[0] !== '/' && !preg_match('#^https?://#i', $logo)) $logo = '/'.ltrim($logo, '/');
      $channelMeta = [
        'id'=>$id, 'title'=>(string)($c['title'] ?? ''), 'slug'=>(string)($c['slug'] ?? ''),
        'description'=>(string)($c['description'] ?? ''), 'logo'=>$logo,
        'status'=>(string)($c['status'] ?? ''), 'is_broadcast_paused'=>!empty($c['is_broadcast_paused']),
        'updated_at'=>(string)($c['updated_at'] ?? $c['modified_at'] ?? ''),
      ];
      spa_json(['ok' => true, 'channel'=>$channelMeta, 'live' => $live, 'schedule'=>$schedule, 'ts' => time()]);
    } catch (Throwable $e) {
      spa_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
  }


  if ($action === 'cinema_list') {
    if (!$user) spa_json(['ok' => false], 401);
    $cinemaStatus = spa_limit_status((int)$user['id'], 'cinema');
    if (empty($cinemaStatus['ok']) || !empty($cinemaStatus['limited']) || (int)($cinemaStatus['remaining'] ?? 0) <= 0) {
      spa_json(['ok'=>false,'limited'=>true,'error'=>'Лимит фильмов и сериалов исчерпан. Доступ восстановится через 24 часа после достижения лимита.','retry_at'=>$cinemaStatus['retry_at']??null,'retry_seconds'=>$cinemaStatus['retry_seconds']??null,'upgrade'=>true],402);
    }
    $q = trim((string)($_GET['q'] ?? ''));
    $limit = min(80, max(1, (int)($_GET['limit'] ?? 40)));
    $items = [];
    try {
      $sql = "SELECT v.id, v.slug, v.title, v.platform, v.thumbnail_url, v.embed_url, v.source_url,
                     v.created_at, v.views_count, c.title AS channel_title
              FROM videos v LEFT JOIN channels c ON c.id = v.channel_id
              WHERE (v.status IS NULL OR v.status = '' OR LOWER(v.status) IN ('published','approved','public','ok','active'))";
      $params = [];
      if ($q !== '') { $sql .= " AND v.title LIKE ?"; $params[] = '%'.$q.'%'; }
      $sql .= " ORDER BY v.id DESC LIMIT ".(int)$limit;
      $st = db()->prepare($sql);
      $st->execute($params);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $th = (string)($v['thumbnail_url'] ?? '');
        if ($th && $th[0] !== '/' && !preg_match('#^https?://#i', $th)) $th = '/'.ltrim($th,'/');
        $items[] = [
          'kind' => 'video',
          'id' => (int)$v['id'],
          'slug' => (string)($v['slug'] ?? ''),
          'title' => (string)$v['title'],
          'subtitle' => 'Кино · '.((string)($v['channel_title'] ?? '')),
          'avatar' => $th ?: 'https://www.gravatar.com/avatar/?d=mp&s=96',
          'embed_url' => (string)($v['embed_url'] ?? ''),
          'source_url' => (string)($v['source_url'] ?? ''),
          'platform' => (string)($v['platform'] ?? ''),
          'time' => (string)($v['created_at'] ?? ''),
          'views' => (int)($v['views_count'] ?? 0),
        ];
      }
    } catch (Throwable $e) {
      try {
        $st = db()->query("SELECT id, slug, title, thumbnail_url, embed_url, platform FROM videos ORDER BY id DESC LIMIT 40");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) {
          $items[] = [
            'kind' => 'video', 'id' => (int)$v['id'], 'slug' => (string)($v['slug']??''),
            'title' => (string)$v['title'], 'subtitle' => 'Кино',
            'avatar' => (string)($v['thumbnail_url']??''), 'embed_url' => (string)($v['embed_url']??''),
            'source_url' => '', 'platform' => (string)($v['platform']??''), 'time' => '', 'views' => 0,
          ];
        }
      } catch (Throwable $e2) {}
    }
    spa_json(['ok' => true, 'items' => $items]);
  }


  if ($action === 'cinema_limit') {
    if (!$user) spa_json(['ok' => false], 401);
    $uid = (int)$user['id'];
    $kpId = preg_replace('/[^0-9]/', '', (string)($_GET['id'] ?? $_POST['id'] ?? ''));
    // Check the global cinema cooldown BEFORE reusing a previously opened title.
    // Otherwise an old per-film grant could bypass an exhausted account quota.
    $status = spa_limit_status($uid, 'cinema');
    if (empty($status['ok']) || !empty($status['limited']) || (int)($status['remaining'] ?? 0) <= 0) {
      spa_json(['ok'=>false,'limited'=>true,'error'=>'Лимит фильмов и сериалов исчерпан. Доступ восстановится через 24 часа после достижения лимита.','quota'=>$status['quota']??1,'retry_at'=>$status['retry_at']??null,'retry_seconds'=>$status['retry_seconds']??null,'upgrade'=>true],402);
    }
    if ($kpId !== '') {
      $st = db()->prepare('SELECT expires_at FROM spa_cinema_access WHERE user_id=? AND kp_id=? LIMIT 1');
      $st->execute([$uid, $kpId]);
      $until = $st->fetchColumn();
      if ($until && strtotime((string)$until) > time()) {
        spa_json(['ok' => true, 'reused' => true, 'remaining' => $status['remaining'] ?? 0, 'quota' => $status['quota'] ?? 1]);
      }
    }
    $lim = spa_check_limit($uid, 'cinema');
    if (!$lim['ok']) spa_json(['ok' => false, 'limited' => true, 'error' => $lim['message'] ?? 'Лимит кино', 'quota' => $lim['quota'] ?? 1, 'retry_at' => $lim['retry_at'] ?? null, 'retry_seconds' => $lim['retry_seconds'] ?? null, 'upgrade' => true], 402);
    if ($kpId !== '') {
      db()->prepare('INSERT INTO spa_cinema_access (user_id,kp_id,granted_at,expires_at) VALUES (?,?,NOW(),DATE_ADD(NOW(), INTERVAL 24 HOUR)) ON DUPLICATE KEY UPDATE granted_at=NOW(), expires_at=DATE_ADD(NOW(), INTERVAL 24 HOUR)')->execute([$uid, $kpId]);
    }
    spa_json(['ok' => true, 'remaining' => $lim['remaining'] ?? 1, 'quota' => $lim['quota'] ?? 1]);
  }

  if ($action === 'limits_status') {
    if (!$user) spa_json(['ok' => false, 'auth' => false], 401);
    $out = [];
    foreach (['profile','channel','video','cinema','music'] as $kind) {
      $st = spa_limit_status((int)$user['id'], $kind);
      $out[$kind] = [
        'limited' => !empty($st['limited']),
        'remaining' => (int)($st['remaining'] ?? 0),
        'quota' => (int)($st['quota'] ?? 0),
        'retry_at' => $st['retry_at'] ?? null,
        'retry_seconds' => (int)($st['retry_seconds'] ?? 0),
        'subscription' => !empty($st['subscription']),
        'server_boost' => spa_server_boost_units((int)$user['id']),
      ];
    }
    // YouTube search has its own daily quota; expose it in the same settings panel.
    $uid = (int)$user['id'];
    $sub = spa_has_sub($uid);
    $srv = spa_user_server($uid);
    $boost = $srv ? spa_server_boost_units($uid) : 0;
    $ytQuota = $sub ? 999 : min(100, 10 + ($boost * 2));
    $today = date('Y-m-d');
    $ytQ = db()->prepare('SELECT searches FROM spa_youtube_daily_usage WHERE user_id=? AND day_date=?');
    $ytQ->execute([$uid, $today]);
    $ytUsed = (int)($ytQ->fetchColumn() ?: 0);
    $ytReset = strtotime('tomorrow');
    $ytLeft = max(0, $ytReset - time());
    $out['youtube'] = [
      'limited' => !$sub && $ytUsed >= $ytQuota,
      'remaining' => $sub ? 999 : max(0, $ytQuota - $ytUsed),
      'quota' => $ytQuota,
      'retry_at' => date('Y-m-d H:i:s', $ytReset),
      'retry_seconds' => $ytLeft,
      'subscription' => $sub,
      'used' => $ytUsed,
      'server_boost' => $boost,
      'reset_type' => 'midnight',
    ];
    // Будущие сервисы: единая серверная квота; отображение — только в настройках SPA.
    foreach (spa_service_quota_config() as $service => $cfg) {
      $st = spa_service_quota((int)$user['id'], $service);
      $out[$service] = [
        'limited' => !empty($st['limited']), 'remaining' => (int)($st['remaining'] ?? 0),
        'quota' => (int)($st['quota'] ?? 0), 'used' => (int)($st['used'] ?? 0),
        'subscription' => !empty($st['subscription']), 'server_boost' => (int)($st['server_boost'] ?? 0),
        'unit' => $cfg['unit'], 'title' => $cfg['title'], 'reset_type' => 'midnight'
      ];
    }
    spa_json(['ok' => true, 'limits' => $out]);
  }

  if ($action === 'service_quota_status') {
    if (!$user) spa_json(['ok'=>false,'auth'=>false],401);
    $service = preg_replace('/[^a-z]/','',strtolower((string)($_GET['service'] ?? '')));
    $st = spa_service_quota((int)$user['id'],$service);
    spa_json($st, !empty($st['ok']) ? 200 : 400);
  }
  if ($action === 'service_quota_charge' && $_SERVER['REQUEST_METHOD']==='POST') {
    spa_require_same_origin_post();
    if (!$user) spa_json(['ok'=>false,'auth'=>false],401);
    $service = preg_replace('/[^a-z]/','',strtolower((string)($_POST['service'] ?? '')));
    $units = (int)($_POST['units'] ?? 1);
    if (!isset(spa_service_quota_config()[$service]) || $units < 1 || $units > 500) spa_json(['ok'=>false,'error'=>'Некорректный сервис или количество'],400);
    $st = spa_service_quota_charge((int)$user['id'],$service,$units);
    spa_json($st, !empty($st['ok']) ? 200 : (!empty($st['limited']) ? 402 : 500));
  }

  if ($action === 'music_limit') {
    if (!$user) spa_json(['ok' => false, 'auth' => false], 401);
    // Read-only status: opening the music screen or refreshing its UI never spends a use.
    $lim = spa_limit_status((int)$user['id'], 'music');
    if (!$lim['ok']) spa_json(['ok' => false, 'limited' => true, 'error' => 'Лимит музыки исчерпан. Доступ восстановится через 24 часа после достижения лимита.', 'quota' => $lim['quota'] ?? 1, 'retry_at' => $lim['retry_at'] ?? null, 'retry_seconds' => $lim['retry_seconds'] ?? null, 'upgrade' => true], 402);
    spa_json(['ok' => true, 'remaining' => $lim['remaining'] ?? 1, 'quota' => $lim['quota'] ?? 1, 'subscription' => !empty($lim['subscription'])]);
  }

  if ($action === 'music_start') {
    if (!$user) spa_json(['ok' => false, 'auth' => false], 401);
    // Only an explicit user-started track consumes a use. Resume and automatic next-track never call this endpoint.
    $lim = spa_check_limit((int)$user['id'], 'music');
    if (!$lim['ok']) spa_json(['ok' => false, 'limited' => true, 'error' => $lim['message'] ?? 'Лимит музыки', 'quota' => $lim['quota'] ?? 1, 'retry_at' => $lim['retry_at'] ?? null, 'retry_seconds' => $lim['retry_seconds'] ?? null, 'upgrade' => true], 402);
    spa_json(['ok' => true, 'remaining' => $lim['remaining'] ?? 1, 'quota' => $lim['quota'] ?? 1, 'subscription' => !empty($lim['subscription'])]);
  }

  if ($action === 'storage_info') {
    if (!$user) spa_json(['ok' => false, 'auth' => false], 401);
    $srv = spa_user_server((int)$user['id']);
    spa_json([
      'ok' => true,
      'quota_bytes' => spa_storage_quota_bytes((int)$user['id']),
      'base_gb' => spa_has_sub((int)$user['id']) ? 100 : 10,
      'bonus_gb' => spa_server_boost_units((int)$user['id']),
      'has_sub' => spa_has_sub((int)$user['id']),
      'in_server' => (bool)$srv,
      'server_name' => $srv ? (string)$srv['name'] : null,
      'server_members' => $srv ? spa_server_member_count((int)$srv['id']) : 0,
      'actions' => spa_vault_quota_status((int)$user['id'])
    ]);
  }

  if ($action === 'vault_action_charge' && $_SERVER['REQUEST_METHOD']==='POST') {
    spa_require_same_origin_post(); if(!$user) spa_json(['ok'=>false,'auth'=>false],401);
    $kind=(string)($_POST['action']??''); $result=spa_vault_action_charge((int)$user['id'],$kind);
    spa_json($result,!empty($result['ok'])?200:(!empty($result['limited'])?429:400));
  }

  if ($action === 'server_kp_get') {
    if (!$user) spa_json(['ok'=>false],401);
    $srv = spa_user_server((int)$user['id']);
    if (!$srv) spa_json(['ok'=>true,'server'=>null,'has_key'=>false,'is_owner'=>false]);
    $row=[]; try {$st=db()->prepare('SELECT kp_api_key,owner_id FROM spa_servers WHERE id=? LIMIT 1');$st->execute([(int)$srv['id']]);$row=$st->fetch(PDO::FETCH_ASSOC)?:[];} catch(Throwable $e) {}
    $owner=((int)($row['owner_id']??0)===(int)$user['id'])||((string)($srv['role']??'')==='owner');
    spa_json(['ok'=>true,'server'=>['id'=>(int)$srv['id'],'name'=>(string)$srv['name']],'has_key'=>trim((string)($row['kp_api_key']??''))!=='','is_owner'=>$owner]);
  }
  if ($action === 'server_kp_set' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    spa_require_same_origin_post();
    if (!$user) spa_json(['ok'=>false],401);
    $srv=spa_user_server((int)$user['id']);
    if (!$srv) spa_json(['ok'=>false,'error'=>'Не в сервере'],403);
    if ((string)($srv['role']??'')!=='owner' && (int)($srv['owner_id']??0)!==(int)$user['id']) spa_json(['ok'=>false,'error'=>'Только владелец сервера'],403);
    $key=trim((string)($_POST['kp_api_key']??''));
    try { spa_save_server_api_key((int)$srv['id'],'kp_api_key',$key); }
    catch(Throwable $e) { spa_json(['ok'=>false,'error'=>$e->getMessage()],503); }
    spa_json(['ok'=>true,'has_key'=>$key!=='']);
  }

  /** Fetch YouTube Data API JSON without exposing a shared API key to the browser. */
  function spa_youtube_http_json(string $url, string $userAgent = 'StreamLive-SPA/External-API'): array {
    $body = false;
    $status = 0;
    $networkError = '';
    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      if ($ch !== false) {
        curl_setopt_array($ch, [
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_FOLLOWLOCATION => false,
          CURLOPT_CONNECTTIMEOUT => 8,
          CURLOPT_TIMEOUT => 18,
          CURLOPT_SSL_VERIFYPEER => true,
          CURLOPT_SSL_VERIFYHOST => 2,
          CURLOPT_HTTPHEADER => ['Accept: application/json'],
          CURLOPT_USERAGENT => $userAgent,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) $networkError = (string)curl_error($ch);
        curl_close($ch);
      }
    } else {
      $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => 18, 'ignore_errors' => true,
        'header' => "Accept: application/json\r\nUser-Agent: ".$userAgent."\r\n",
      ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
      $body = @file_get_contents($url, false, $ctx);
      if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
          if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) $status = (int)$m[1];
        }
      }
      if ($body === false) $networkError = 'PHP outbound HTTPS request failed';
    }
    if ($body === false || $body === '') {
      return ['ok' => false, 'http' => $status, 'error' => 'Не удалось связаться с внешним API'.($networkError !== '' ? ': '.$networkError : '')];
    }
    $data = json_decode((string)$body, true);
    if (!is_array($data)) return ['ok' => false, 'http' => $status, 'error' => 'Внешний API вернул некорректный JSON'];
    if ($status < 200 || $status >= 300 || isset($data['error'])) {
      $message = trim((string)($data['error']['message'] ?? ''));
      if ($message === '') $message = 'Внешний API HTTP '.$status;
      return ['ok' => false, 'http' => $status, 'error' => $message];
    }
    return ['ok' => true, 'http' => $status, 'data' => $data];
  }

  function spa_youtube_refund_charge(string $charge, int $uid): void {
    try {
      $pdo = db();
      $pdo->beginTransaction();
      $q = $pdo->prepare("UPDATE spa_youtube_search_charges SET status='refunded' WHERE charge_id=? AND user_id=? AND status IN ('charged','processing')");
      $q->execute([$charge, $uid]);
      if ($q->rowCount() === 1) {
        $pdo->prepare('UPDATE spa_youtube_daily_usage SET searches=GREATEST(0,searches-1) WHERE user_id=? AND day_date=(SELECT day_date FROM spa_youtube_search_charges WHERE charge_id=?)')->execute([$uid, $charge]);
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    }
  }

  if ($action === 'server_youtube_get') {
    if (!$user) spa_json(['ok'=>false],401);
    $srv=spa_user_server((int)$user['id']);
    if (!$srv) spa_json(['ok'=>true,'server'=>null,'has_key'=>false,'is_owner'=>false]);
    $row=[]; try {$q=db()->prepare('SELECT youtube_api_key,owner_id FROM spa_servers WHERE id=? LIMIT 1');$q->execute([(int)$srv['id']]);$row=$q->fetch(PDO::FETCH_ASSOC)?:[];} catch(Throwable $e) {}
    $owner=((int)($row['owner_id']??0)===(int)$user['id'])||((string)($srv['role']??'')==='owner');
    spa_json(['ok'=>true,'server'=>['id'=>(int)$srv['id'],'name'=>(string)$srv['name']],'has_key'=>trim((string)($row['youtube_api_key']??''))!=='','is_owner'=>$owner]);
  }
  if ($action === 'server_youtube_set' && $_SERVER['REQUEST_METHOD']==='POST') {
    spa_require_same_origin_post();
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_user_server((int)$user['id']);
    if(!$srv)spa_json(['ok'=>false,'error'=>'Ты не состоишь в сервере'],403);
    if((string)($srv['role']??'')!=='owner'&&(int)($srv['owner_id']??0)!==(int)$user['id'])spa_json(['ok'=>false,'error'=>'Только владелец сервера может задать общий YouTube API key'],403);
    $key=trim((string)($_POST['youtube_api_key']??''));
    try{spa_save_server_api_key((int)$srv['id'],'youtube_api_key',$key);}catch(Throwable $e){spa_json(['ok'=>false,'error'=>$e->getMessage()],503);}
    spa_json(['ok'=>true,'has_key'=>$key!=='']);
  }
  if ($action === 'server_imdb_get') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_user_server((int)$user['id']);if(!$srv)spa_json(['ok'=>true,'has_key'=>false,'is_owner'=>false]);
    $row=[];try{$q=db()->prepare('SELECT imdb_api_key,imdb_enabled,owner_id FROM spa_servers WHERE id=? LIMIT 1');$q->execute([(int)$srv['id']]);$row=$q->fetch(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){}
    $owner=((int)($row['owner_id']??0)===(int)$user['id'])||((string)($srv['role']??'')==='owner');spa_json(['ok'=>true,'has_key'=>trim((string)($row['imdb_api_key']??''))!=='','enabled'=>!empty($row['imdb_enabled']) && trim((string)($row['imdb_api_key']??''))!=='','is_owner'=>$owner]);
  }
  if ($action === 'server_imdb_set' && $_SERVER['REQUEST_METHOD']==='POST') {
    spa_require_same_origin_post();if(!$user)spa_json(['ok'=>false],401);$srv=spa_user_server((int)$user['id']);if(!$srv)spa_json(['ok'=>false,'error'=>'Ты не состоишь в сервере'],403);
    if((string)($srv['role']??'')!=='owner'&&(int)($srv['owner_id']??0)!==(int)$user['id'])spa_json(['ok'=>false,'error'=>'Только владелец сервера может задать общий IMDb key'],403);
    $key=trim((string)($_POST['imdb_api_key']??''));try{if(array_key_exists('imdb_api_key',$_POST))spa_save_server_api_key((int)$srv['id'],'imdb_api_key',$key);$current=spa_server_api_key((int)$srv['id'],'imdb_api_key');$enabled=array_key_exists('imdb_enabled',$_POST)?((string)$_POST['imdb_enabled']==='1'||strtolower((string)$_POST['imdb_enabled'])==='true'):($key!=='');if($current==='')$enabled=false;db()->prepare('UPDATE spa_servers SET imdb_enabled=? WHERE id=?')->execute([$enabled?1:0,(int)$srv['id']]);}catch(Throwable $e){spa_json(['ok'=>false,'error'=>$e->getMessage()],400);}spa_json(['ok'=>true,'has_key'=>$current!=='','enabled'=>$enabled]);
  }
  if (($action === 'imdb_search' || $action === 'imdb_details') && $_SERVER['REQUEST_METHOD']==='POST') {
    spa_require_same_origin_post();if(!$user)spa_json(['ok'=>false,'auth'=>false],401);$srv=spa_user_server((int)$user['id']);if(!$srv)spa_json(['ok'=>false,'error'=>'Для IMDb нужен сервер StreamLive'],403);
    try{$key=spa_server_api_key((int)$srv['id'],'imdb_api_key');$enabledQ=db()->prepare('SELECT imdb_enabled FROM spa_servers WHERE id=? LIMIT 1');$enabledQ->execute([(int)$srv['id']]);$imdbEnabled=(bool)$enabledQ->fetchColumn();}catch(Throwable $e){spa_json(['ok'=>false,'error'=>'Не удалось прочитать IMDb key'],503);}if($key===''||!$imdbEnabled)spa_json(['ok'=>false,'configured'=>$key!=='','disabled'=>!$imdbEnabled,'error'=>$key===''?'Владелец сервера ещё не добавил OMDb/IMDb key':'IMDb выключен владельцем сервера'],403);
    $quota=spa_service_quota((int)$user['id'],'imdb');if(!empty($quota['limited']))spa_json(['ok'=>false,'limited'=>true,'error'=>'Дневной лимит IMDb исчерпан. Остаток смотри в Настройках.','quota'=>$quota],402);
    $params=['apikey'=>$key,'r'=>'json'];if($action==='imdb_search'){$q=trim((string)($_POST['query']??''));if($q===''||mb_strlen($q)>120)spa_json(['ok'=>false,'error'=>'Укажи запрос до 120 символов'],400);$params['s']=$q;$params['page']=1;}else{$id=trim((string)($_POST['imdb_id']??''));if(!preg_match('/^tt[0-9]{5,12}$/D',$id))spa_json(['ok'=>false,'error'=>'Некорректный IMDb ID'],400);$params['i']=$id;$params['plot']='full';}
    $url='https://www.omdbapi.com/?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);$remote=spa_youtube_http_json($url);if(empty($remote['ok']))spa_json(['ok'=>false,'error'=>'IMDb/OMDb: '.(string)($remote['error']??'ошибка запроса')],502);$payload=$remote['data']??[];if(($payload['Response']??'False')!=='True')spa_json(['ok'=>false,'error'=>(string)($payload['Error']??'Ничего не найдено')],404);
    $charged=spa_service_quota_charge((int)$user['id'],'imdb',1);if(empty($charged['ok']))spa_json(['ok'=>false,'limited'=>!empty($charged['limited']),'error'=>$charged['error']??'Лимит IMDb исчерпан'],!empty($charged['limited'])?402:500);spa_json(['ok'=>true,'data'=>$payload,'quota'=>$charged]);
  }

  if ($action === 'server_tmdb_get') {
    if (!$user) spa_json(['ok'=>false],401);
    $srv=spa_user_server((int)$user['id']);
    if(!$srv)spa_json(['ok'=>true,'server'=>null,'has_key'=>false,'is_owner'=>false]);
    $row=[];try{$q=db()->prepare('SELECT tmdb_api_key,owner_id FROM spa_servers WHERE id=? LIMIT 1');$q->execute([(int)$srv['id']]);$row=$q->fetch(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){}
    $owner=((int)($row['owner_id']??0)===(int)$user['id'])||((string)($srv['role']??'')==='owner');
    spa_json(['ok'=>true,'server'=>['id'=>(int)$srv['id'],'name'=>(string)$srv['name']],'has_key'=>trim((string)($row['tmdb_api_key']??''))!=='','is_owner'=>$owner]);
  }
  if ($action === 'server_tmdb_set' && $_SERVER['REQUEST_METHOD']==='POST') {
    spa_require_same_origin_post();if(!$user)spa_json(['ok'=>false],401);$srv=spa_user_server((int)$user['id']);
    if(!$srv)spa_json(['ok'=>false,'error'=>'Ты не состоишь в сервере'],403);
    if((string)($srv['role']??'')!=='owner'&&(int)($srv['owner_id']??0)!==(int)$user['id'])spa_json(['ok'=>false,'error'=>'Только владелец сервера может задать общий TMDB API key'],403);
    $key=trim((string)($_POST['tmdb_api_key']??''));try{spa_save_server_api_key((int)$srv['id'],'tmdb_api_key',$key);}catch(Throwable $e){spa_json(['ok'=>false,'error'=>$e->getMessage()],503);}
    spa_json(['ok'=>true,'has_key'=>$key!=='']);
  }

  if (($action === 'tmdb_search' || $action === 'tmdb_details') && $_SERVER['REQUEST_METHOD']==='POST') {
    spa_require_same_origin_post();if(!$user)spa_json(['ok'=>false,'auth'=>false],401);
    $srv=spa_user_server((int)$user['id']);if(!$srv)spa_json(['ok'=>false,'error'=>'Для TMDB нужен сервер StreamLive с общим API key'],403);
    try{$key=spa_server_api_key((int)$srv['id'],'tmdb_api_key');}catch(Throwable $e){spa_json(['ok'=>false,'error'=>'Не удалось прочитать TMDB key'],503);}
    if($key==='')spa_json(['ok'=>false,'configured'=>false,'error'=>'Владелец сервера ещё не добавил TMDB API key'],503);
    $serviceQuota=spa_service_quota((int)$user['id'],'tmdb');if(!empty($serviceQuota['limited']))spa_json(['ok'=>false,'limited'=>true,'error'=>'Дневной лимит TMDB исчерпан. Остаток смотри в Настройках.','quota'=>$serviceQuota],402);
    $path='/3/search/multi';$params=['api_key'=>$key,'language'=>'ru-RU','include_adult'=>'false','page'=>1];
    if($action==='tmdb_search'){$q=trim((string)($_POST['query']??''));if($q===''||mb_strlen($q)>120)spa_json(['ok'=>false,'error'=>'Укажи поисковый запрос до 120 символов'],400);$params['query']=$q;}
    else{$id=(int)($_POST['tmdb_id']??0);$kind=(string)($_POST['media_type']??'movie');if($id<1||$id>200000000||!in_array($kind,['movie','tv'],true))spa_json(['ok'=>false,'error'=>'Некорректный TMDB ID'],400);$path='/3/'.$kind.'/'.$id;$params=['api_key'=>$key,'language'=>'ru-RU','append_to_response'=>'credits,videos,external_ids'];}
    $url='https://api.themoviedb.org'.$path.'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
    $remote=spa_youtube_http_json($url);if(empty($remote['ok']))spa_json(['ok'=>false,'error'=>'TMDB: '.(string)($remote['error']??'ошибка запроса')],502);
    $charged=spa_service_quota_charge((int)$user['id'],'tmdb',1);if(empty($charged['ok']))spa_json(['ok'=>false,'limited'=>!empty($charged['limited']),'error'=>$charged['error']??'Лимит TMDB исчерпан'],!empty($charged['limited'])?402:500);
    spa_json(['ok'=>true,'data'=>$remote['data'],'quota'=>$charged]);
  }

  if ($action === 'kp_proxy' && $_SERVER['REQUEST_METHOD']==='POST') {
    spa_require_same_origin_post();
    if (!$user) spa_json(['ok'=>false,'error'=>'Требуется вход'],401);
    $path=trim((string)($_POST['path']??''));
    $allowedPath = preg_match('#^/api/v2\.[12]/films(?:/[0-9]{1,10}(?:/(?:videos|seasons))?)?$#D', $path) || preg_match('#^/api/v2\.1/films/search-by-keyword$#D', $path) || preg_match('#^/api/v1/staff(?:/[0-9]{1,10})?$#D', $path);
    if (!$allowedPath) spa_json(['ok'=>false,'error'=>'Недопустимый адрес Кинопоиска'],400);
    $rawParams=(string)($_POST['params']??'{}');$params=json_decode($rawParams,true);
    if (!is_array($params)) spa_json(['ok'=>false,'error'=>'Некорректные параметры запроса'],400);
    $allowedParams=['keyword','page','order','type','staffId','filmId'];$safeParams=[];
    foreach($params as $k=>$v){if(!in_array((string)$k,$allowedParams,true)||is_array($v)||is_object($v))continue;$v=trim((string)$v);if($v==='')continue;if(strlen($v)>200)spa_json(['ok'=>false,'error'=>'Слишком длинный параметр'],400);$safeParams[$k]=$v;}
    $personal=trim((string)($_POST['api_key']??''));if($personal!==''&&!preg_match('/^[A-Za-z0-9_-]{16,60}$/D',$personal))spa_json(['ok'=>false,'error'=>'Формат личного API-ключа недопустим.'],400);
    $apiKey=$personal;
    if($apiKey===''){$srv=spa_user_server((int)$user['id']);if($srv){try{$apiKey=spa_server_api_key((int)$srv['id'],'kp_api_key');}catch(Throwable $e){spa_json(['ok'=>false,'error'=>$e->getMessage()],503);}}}
    if($apiKey==='')spa_json(['ok'=>false,'error'=>'Ключ Кинопоиска не настроен. Добавь личный ключ на время сессии или попроси владельца сервера задать общий ключ.'],400);
    $result=spa_kp_http_json($path,$safeParams,$apiKey);
    if(!$result['ok'])spa_json(['ok'=>false,'error'=>$result['error'],'http'=>(int)$result['http']],502);
    spa_json(['ok'=>true,'data'=>$result['data']]);
  }

  if ($action === 'youtube_bookmark_get' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    spa_require_same_origin_post();if(!$user)spa_json(['ok'=>false,'error'=>'Требуется вход'],401);
    $raw=json_decode((string)($_POST['video_ids']??'[]'),true);$ids=is_array($raw)?array_values(array_unique(array_filter(array_map('strval',$raw),static fn($v)=>preg_match('/^[A-Za-z0-9_-]{11}$/D',$v)))):[];$uid=(int)$user['id'];
    if($ids){$ids=array_slice($ids,0,50);$ph=implode(',',array_fill(0,count($ids),'?'));$st=db()->prepare("SELECT video_id,title,channel,thumbnail,description FROM spa_youtube_bookmarks WHERE user_id=? AND video_id IN ($ph)");$st->execute(array_merge([$uid],$ids));}
    else {$st=db()->prepare('SELECT video_id,title,channel,thumbnail,description FROM spa_youtube_bookmarks WHERE user_id=? ORDER BY saved_at DESC LIMIT 100');$st->execute([$uid]);}
    $items=[];foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r)$items[]=['id'=>(string)$r['video_id'],'title'=>(string)$r['title'],'channel'=>(string)$r['channel'],'thumbnail'=>(string)$r['thumbnail'],'description'=>(string)($r['description']??'')];
    spa_json(['ok'=>true,'items'=>$items]);
  }
  if ($action === 'youtube_bookmark_set' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    spa_require_same_origin_post();if(!$user)spa_json(['ok'=>false,'error'=>'Требуется вход'],401);
    $id=trim((string)($_POST['video_id']??''));$saved=!empty($_POST['saved']);if(!preg_match('/^[A-Za-z0-9_-]{11}$/D',$id))spa_json(['ok'=>false,'error'=>'Некорректное видео'],400);$uid=(int)$user['id'];
    if(!$saved)db()->prepare('DELETE FROM spa_youtube_bookmarks WHERE user_id=? AND video_id=?')->execute([$uid,$id]);
    else {$title=mb_substr(trim((string)($_POST['title']??'Видео')),0,500);$channel=mb_substr(trim((string)($_POST['channel']??'YouTube')),0,255);$thumb=trim((string)($_POST['thumbnail']??''));if($thumb!==''&&!preg_match('#^https://i\.ytimg\.com/#i',$thumb))$thumb='';$desc=mb_substr(trim((string)($_POST['description']??'')),0,4000);db()->prepare('INSERT INTO spa_youtube_bookmarks(user_id,video_id,title,channel,thumbnail,description) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),channel=VALUES(channel),thumbnail=VALUES(thumbnail),description=VALUES(description),saved_at=CURRENT_TIMESTAMP')->execute([$uid,$id,$title,$channel,$thumb,$desc]);}
    spa_json(['ok'=>true,'saved'=>$saved]);
  }

  if ($action === 'youtube_reaction_get' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    spa_require_same_origin_post();
    if (!$user) spa_json(['ok'=>false,'error'=>'Требуется вход'],401);
    $ids=json_decode((string)($_POST['video_ids']??'[]'),true);
    if(!is_array($ids))$ids=[];
    $ids=array_values(array_unique(array_filter(array_map('strval',$ids),static fn($v)=>preg_match('/^[A-Za-z0-9_-]{11}$/D',$v))));
    $ids=array_slice($ids,0,50);
    if(!$ids)spa_json(['ok'=>true,'reactions'=>[]]);
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $sql="SELECT video_id,SUM(vote=1) AS likes,SUM(vote=-1) AS dislikes,MAX(CASE WHEN user_id=? THEN vote ELSE 0 END) AS my_vote FROM spa_youtube_reactions WHERE video_id IN ($ph) GROUP BY video_id";
    $st=db()->prepare($sql);$st->execute(array_merge([(int)$user['id']],$ids));$out=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r)$out[(string)$r['video_id']]=['likes'=>(int)$r['likes'],'dislikes'=>(int)$r['dislikes'],'my_vote'=>(int)$r['my_vote']];
    spa_json(['ok'=>true,'reactions'=>$out]);
  }
  if ($action === 'youtube_reaction_set' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    spa_require_same_origin_post();
    if (!$user) spa_json(['ok'=>false,'error'=>'Требуется вход'],401);
    $id=trim((string)($_POST['video_id']??''));$vote=(int)($_POST['vote']??0);
    if(!preg_match('/^[A-Za-z0-9_-]{11}$/D',$id)||!in_array($vote,[-1,0,1],true))spa_json(['ok'=>false,'error'=>'Некорректная реакция'],400);
    $pdo=db();
    if($vote===0)$pdo->prepare('DELETE FROM spa_youtube_reactions WHERE user_id=? AND video_id=?')->execute([(int)$user['id'],$id]);
    else $pdo->prepare('INSERT INTO spa_youtube_reactions(user_id,video_id,vote) VALUES(?,?,?) ON DUPLICATE KEY UPDATE vote=VALUES(vote),updated_at=CURRENT_TIMESTAMP')->execute([(int)$user['id'],$id,$vote]);
    $st=$pdo->prepare('SELECT SUM(vote=1) AS likes,SUM(vote=-1) AS dislikes,MAX(CASE WHEN user_id=? THEN vote ELSE 0 END) AS my_vote FROM spa_youtube_reactions WHERE video_id=?');
    $st->execute([(int)$user['id'],$id]);$r=$st->fetch(PDO::FETCH_ASSOC)?:[];
    spa_json(['ok'=>true,'reaction'=>['likes'=>(int)($r['likes']??0),'dislikes'=>(int)($r['dislikes']??0),'my_vote'=>(int)($r['my_vote']??0)]]);
  }

  if ($action === 'content_reaction_get' && $_SERVER['REQUEST_METHOD']==='POST') {
    spa_require_same_origin_post(); if(!$user) spa_json(['ok'=>false],401);
    $type=(string)($_POST['type']??''); $id=(int)($_POST['id']??0);
    if(!in_array($type,['channel','video'],true)||$id<1) spa_json(['ok'=>false],400);
    $q=db()->prepare('SELECT SUM(vote=1) likes,SUM(vote=-1) dislikes,MAX(CASE WHEN user_id=? THEN vote ELSE 0 END) my_vote FROM spa_content_reactions WHERE target_type=? AND target_id=?');
    $q->execute([(int)$user['id'],$type,$id]); $r=$q->fetch(PDO::FETCH_ASSOC)?:[];
    $f=db()->prepare('SELECT 1 FROM spa_favorites WHERE user_id=? AND target_type=? AND target_id=? LIMIT 1');$f->execute([(int)$user['id'],$type,$id]);
    spa_json(['ok'=>true,'reaction'=>['likes'=>(int)($r['likes']??0),'dislikes'=>(int)($r['dislikes']??0),'my_vote'=>(int)($r['my_vote']??0)],'favorite'=>(bool)$f->fetchColumn()]);
  }
  if ($action === 'content_reaction_set' && $_SERVER['REQUEST_METHOD']==='POST') {
    spa_require_same_origin_post(); if(!$user) spa_json(['ok'=>false],401);
    $type=(string)($_POST['type']??'');$id=(int)($_POST['id']??0);$vote=(int)($_POST['vote']??0);
    if(!in_array($type,['channel','video'],true)||$id<1||!in_array($vote,[-1,0,1],true))spa_json(['ok'=>false],400);
    $pdo=db();if($vote===0)$pdo->prepare('DELETE FROM spa_content_reactions WHERE user_id=? AND target_type=? AND target_id=?')->execute([(int)$user['id'],$type,$id]);
    else $pdo->prepare('INSERT INTO spa_content_reactions(user_id,target_type,target_id,vote) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE vote=VALUES(vote),updated_at=CURRENT_TIMESTAMP')->execute([(int)$user['id'],$type,$id,$vote]);
    $q=$pdo->prepare('SELECT SUM(vote=1) likes,SUM(vote=-1) dislikes,MAX(CASE WHEN user_id=? THEN vote ELSE 0 END) my_vote FROM spa_content_reactions WHERE target_type=? AND target_id=?');$q->execute([(int)$user['id'],$type,$id]);$r=$q->fetch(PDO::FETCH_ASSOC)?:[];
    spa_json(['ok'=>true,'reaction'=>['likes'=>(int)($r['likes']??0),'dislikes'=>(int)($r['dislikes']??0),'my_vote'=>(int)($r['my_vote']??0)]]);
  }

  if ($action === 'youtube_home' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    spa_require_same_origin_post();
    if (!$user) spa_json(['ok'=>false,'error'=>'Требуется вход'],401);
    $uid=(int)$user['id'];
    $personalKey=trim((string)($_POST['api_key']??''));
    if($personalKey!==''&&!preg_match('/^[A-Za-z0-9_-]{16,60}$/D',$personalKey))spa_json(['ok'=>false,'error'=>'Формат личного YouTube API-ключа недопустим.'],400);
    $apiKey=$personalKey;
    if($apiKey===''){
      $srv=spa_user_server($uid);
      if($srv){try{$apiKey=spa_server_api_key((int)$srv['id'],'youtube_api_key');}catch(Throwable $e){spa_json(['ok'=>false,'error'=>$e->getMessage()],503);}}
    }
    if($apiKey==='')spa_json(['ok'=>false,'error'=>'YouTube API key не настроен. Добавь личный ключ или попроси владельца сервера настроить общий ключ.'],400);
    $region=strtoupper(trim((string)($_POST['region']??'US')));
    if(!preg_match('/^[A-Z]{2}$/',$region))$region='US';
    $category=trim((string)($_POST['category']??''));
    $allowed=['1','10','20','28'];
    $params=['part'=>'snippet,statistics','chart'=>'mostPopular','maxResults'=>24,'regionCode'=>$region,'key'=>$apiKey];
    if(in_array($category,$allowed,true))$params['videoCategoryId']=$category;
    $result=spa_youtube_http_json('https://www.googleapis.com/youtube/v3/videos?'.http_build_query($params,'','&',PHP_QUERY_RFC3986));
    if(!$result['ok'])spa_json(['ok'=>false,'error'=>(string)($result['error']??'Ошибка YouTube API'),'http'=>(int)($result['http']??0)],502);
    $items=[];
    foreach(($result['data']['items']??[]) as $item){
      $id=(string)($item['id']??'');if(!preg_match('/^[A-Za-z0-9_-]{11}$/',$id))continue;
      $sn=is_array($item['snippet']??null)?$item['snippet']:[];$thumbs=is_array($sn['thumbnails']??null)?$sn['thumbnails']:[];
      $thumb=(string)($thumbs['maxres']['url']??$thumbs['high']['url']??$thumbs['medium']['url']??$thumbs['default']['url']??'');
      if($thumb!==''&&!preg_match('#^https://i\.ytimg\.com/#i',$thumb))$thumb='';
      $items[]=['id'=>$id,'title'=>(string)($sn['title']??'Видео'),'channel'=>(string)($sn['channelTitle']??'YouTube'),'description'=>(string)($sn['description']??''),'thumbnail'=>$thumb,'published_at'=>(string)($sn['publishedAt']??''),'views'=>(int)($item['statistics']['viewCount']??0)];
    }
    spa_json(['ok'=>true,'items'=>$items,'count'=>count($items),'category'=>$category,'region'=>$region]);
  }

  if ($action === 'youtube_quota_status') {
    if(!$user)spa_json(['ok'=>false,'error'=>'Требуется вход'],401);
    $uid=(int)$user['id'];$sub=spa_has_sub($uid);$srv=spa_user_server($uid);$boost=$srv?spa_server_boost_units($uid):0;$quota=$sub?999:min(100,10+($boost*2));
    $q=db()->prepare('SELECT searches FROM spa_youtube_daily_usage WHERE user_id=? AND day_date=?');$q->execute([$uid,date('Y-m-d')]);$used=(int)($q->fetchColumn()?:0);
    spa_json(['ok'=>true,'quota'=>['used'=>$used,'limit'=>$quota,'remaining'=>$sub?999:max(0,$quota-$used),'subscription'=>$sub,'server_boost'=>$boost]]);
  }
  if ($action === 'youtube_search_charge' && $_SERVER['REQUEST_METHOD']==='POST') {
    spa_require_same_origin_post();
    if(!$user)spa_json(['ok'=>false,'error'=>'Требуется вход'],401);
    $uid=(int)$user['id'];$query=trim((string)($_POST['q']??''));if($query==='')spa_json(['ok'=>false,'error'=>'Пустой поисковый запрос'],400);
    $sub=spa_has_sub($uid);$srv=spa_user_server($uid);$boost=$srv?spa_server_boost_units($uid):0;$quota=$sub?999:min(100,10+($boost*2));$pdo=db();$day=date('Y-m-d');$charge=bin2hex(random_bytes(16));
    $pdo->beginTransaction();try{$pdo->prepare('INSERT IGNORE INTO spa_youtube_daily_usage(user_id,day_date,searches) VALUES(?,?,0)')->execute([$uid,$day]);$q=$pdo->prepare('SELECT searches FROM spa_youtube_daily_usage WHERE user_id=? AND day_date=? FOR UPDATE');$q->execute([$uid,$day]);$used=(int)$q->fetchColumn();if(!$sub&&$used >= $quota){$pdo->commit();spa_json(['ok'=>false,'limited'=>true,'error'=>'Дневной лимит поиска YouTube исчерпан. Серверные бафы увеличивают квоту, подписка SPA снимает ограничение.','quota'=>['used'=>$used,'limit'=>$quota,'remaining'=>0,'server_boost'=>$boost]],402);}$pdo->prepare('UPDATE spa_youtube_daily_usage SET searches=searches+1 WHERE user_id=? AND day_date=?')->execute([$uid,$day]);$pdo->prepare('INSERT INTO spa_youtube_search_charges(charge_id,user_id,day_date,status) VALUES(?,?,?,\'charged\')')->execute([$charge,$uid,$day]);$pdo->commit();$used++;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();spa_json(['ok'=>false,'error'=>'Не удалось проверить лимит YouTube'],500);}
    spa_json(['ok'=>true,'charge_id'=>$charge,'quota'=>['used'=>$used,'limit'=>$quota,'remaining'=>$sub?999:max(0,$quota-$used),'subscription'=>$sub,'server_boost'=>$boost]]);
  }
  if ($action === 'youtube_search_commit' && $_SERVER['REQUEST_METHOD']==='POST') {
    spa_require_same_origin_post();if(!$user)spa_json(['ok'=>false],401);
    $charge=trim((string)($_POST['charge_id']??''));if(!preg_match('/^[a-f0-9]{32}$/',$charge))spa_json(['ok'=>false],400);
    $st=db()->prepare("UPDATE spa_youtube_search_charges SET status='used' WHERE charge_id=? AND user_id=? AND status='charged' AND day_date=?");
    $st->execute([$charge,(int)$user['id'],date('Y-m-d')]);spa_json(['ok'=>true,'committed'=>$st->rowCount()===1]);
  }
  if ($action === 'youtube_search' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    spa_require_same_origin_post();
    if (!$user) spa_json(['ok' => false, 'error' => 'Требуется вход'], 401);
    $uid = (int)$user['id'];
    $query = trim((string)($_POST['q'] ?? ''));
    $query = preg_replace('/[\\x00-\\x1F\\x7F]/u', ' ', $query) ?? $query;
    if ($query === '' || (function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query)) > 200) spa_json(['ok' => false, 'error' => 'Запрос должен содержать от 1 до 200 символов'], 400);
    $charge = strtolower(trim((string)($_POST['charge_id'] ?? '')));
    if (!preg_match('/^[a-f0-9]{32}$/', $charge)) spa_json(['ok' => false, 'error' => 'Не найдено подтверждение поискового лимита'], 400);
    $claimed = false;
    try {
      $pdo = db();
      $pdo->beginTransaction();
      $st = $pdo->prepare("UPDATE spa_youtube_search_charges SET status='processing' WHERE charge_id=? AND user_id=? AND day_date=? AND status='charged'");
      $st->execute([$charge, $uid, date('Y-m-d')]);
      $claimed = $st->rowCount() === 1;
      $pdo->commit();
    } catch (Throwable $e) {
      if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    }
    if (!$claimed) spa_json(['ok' => false, 'error' => 'Поисковый запрос уже использован, истёк или относится к другому дню'], 409);

    // A personal key is sent in the POST body and is never stored by this endpoint.
    // If absent, use the server owner's shared key from MySQL.
    $personalKey = trim((string)($_POST['api_key'] ?? ''));
    if ($personalKey !== '' && !preg_match('/^[A-Za-z0-9_-]{16,60}$/D', $personalKey)) spa_json(['ok'=>false,'error'=>'Формат личного YouTube API-ключа недопустим.'],400);
    $apiKey = $personalKey;
    if ($apiKey === '') {
      $srv = spa_user_server($uid);
      if ($srv) {
        try {
          $apiKey = spa_server_api_key((int)$srv['id'], 'youtube_api_key');
        } catch (Throwable $e) { $apiKey = ''; }
      }
    }
    if ($apiKey === '') {
      spa_youtube_refund_charge($charge, $uid);
      spa_json(['ok' => false, 'error' => 'YouTube API key не настроен. Добавь личный ключ в настройках или попроси владельца сервера задать общий ключ.'], 400);
    }

    $url = 'https://www.googleapis.com/youtube/v3/search?'.http_build_query([
      'part' => 'snippet', 'type' => 'video', 'maxResults' => 20,
      'q' => $query, 'safeSearch' => 'moderate', 'key' => $apiKey,
    ], '', '&', PHP_QUERY_RFC3986);
    $result = spa_youtube_http_json($url);
    if (!$result['ok']) {
      spa_youtube_refund_charge($charge, $uid);
      $msg = (string)($result['error'] ?? 'Ошибка YouTube API');
      spa_json(['ok' => false, 'error' => $msg, 'http' => (int)($result['http'] ?? 0)], 502);
    }

    $items = [];
    foreach (($result['data']['items'] ?? []) as $item) {
      $id = (string)($item['id']['videoId'] ?? '');
      if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) continue;
      $sn = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
      $thumbs = is_array($sn['thumbnails'] ?? null) ? $sn['thumbnails'] : [];
      $thumb = (string)($thumbs['maxres']['url'] ?? $thumbs['high']['url'] ?? $thumbs['medium']['url'] ?? $thumbs['default']['url'] ?? '');
      if ($thumb !== '' && !preg_match('#^https://i\.ytimg\.com/#i', $thumb)) $thumb = '';
      $items[] = [
        'id' => $id,
        'title' => (string)($sn['title'] ?? 'Видео'),
        'channel' => (string)($sn['channelTitle'] ?? 'YouTube'),
        'description' => (string)($sn['description'] ?? ''),
        'thumbnail' => $thumb,
      ];
    }
    try { db()->prepare("UPDATE spa_youtube_search_charges SET status='used' WHERE charge_id=? AND user_id=? AND status='processing'")->execute([$charge, $uid]); } catch (Throwable $e) {}
    spa_json(['ok' => true, 'items' => $items, 'count' => count($items)]);
  }

  if ($action === 'youtube_search_refund' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user)spa_json(['ok'=>false],401);$charge=trim((string)($_POST['charge_id']??''));if(!preg_match('/^[a-f0-9]{32}$/',$charge))spa_json(['ok'=>false],400);$pdo=db();$pdo->beginTransaction();try{$q=$pdo->prepare("UPDATE spa_youtube_search_charges SET status='refunded' WHERE charge_id=? AND user_id=? AND status IN ('charged','processing')");$q->execute([$charge,(int)$user['id']]);if($q->rowCount()===1)$pdo->prepare('UPDATE spa_youtube_daily_usage SET searches=GREATEST(0,searches-1) WHERE user_id=? AND day_date=(SELECT day_date FROM spa_youtube_search_charges WHERE charge_id=?)')->execute([(int)$user['id'],$charge]);$pdo->commit();spa_json(['ok'=>true,'refunded'=>$q->rowCount()===1]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();spa_json(['ok'=>false,'error'=>'Не удалось вернуть запрос в лимит'],500);}
  }

  if ($action === 'channel') {
    if (!$user) spa_json(['ok' => false], 401);
    $lim = spa_check_limit((int)$user['id'], 'channel');
    if (!$lim['ok']) spa_json(['ok' => false, 'limited' => true, 'error' => $lim['message'], 'retry_at' => $lim['retry_at'] ?? null, 'retry_seconds' => $lim['retry_seconds'] ?? null, 'upgrade' => true], 402);

    $id = (int)($_GET['id'] ?? 0);
    $slug = trim((string)($_GET['slug'] ?? ''));
    try {
      if ($id) {
        $st = db()->prepare('SELECT * FROM channels WHERE id = ? LIMIT 1');
        $st->execute([$id]);
      } else {
        $st = db()->prepare('SELECT * FROM channels WHERE slug = ? LIMIT 1');
        $st->execute([$slug]);
      }
      $c = $st->fetch(PDO::FETCH_ASSOC);
      if (!$c) spa_json(['ok' => false, 'error' => 'Канал не найден'], 404);
      if (spa_channel_blocked((int)$user['id'], (int)$c['id'])) {
        spa_json(['ok' => false, 'error' => 'Вас заблокировали на этом канале', 'blocked' => true], 403);
      }

      $logo = (string)($c['logo_url'] ?? '');
      if ($logo && $logo[0] !== '/' && !preg_match('#^https?://#i', $logo)) $logo = '/'.ltrim($logo, '/');

      $live = null;
      if (function_exists('resolve_active_source')) {
        try {
          $src = resolve_active_source($c);
          if ($src) {
            $live = [
              'id' => (int)($src['id'] ?? $src['source_id'] ?? 0),
              'url' => (string)($src['url'] ?? $src['source_url'] ?? $src['stream_url'] ?? $src['embed_url'] ?? ''),
              'name' => (string)($src['name'] ?? $src['source_name'] ?? 'Live'),
              'type' => strtolower(trim((string)($src['type'] ?? $src['source_type'] ?? ''))),
              'platform' => (string)($src['platform'] ?? ''),
              'updated_at' => (string)($src['updated_at'] ?? $src['created_at'] ?? ''),
            ];
          }
        } catch (Throwable $e) {}
      }
      if (!$live) {
        foreach (['live_url','stream_url','playback_url','hls_url','current_url'] as $fld) {
          if (!empty($c[$fld])) { $live = ['url'=>(string)$c[$fld],'name'=>'Live','type'=>'']; break; }
        }
      }

      $videos = [];
      try {
        $st2 = db()->prepare("SELECT id, slug, title, thumbnail_url, embed_url, source_url, platform, status, created_at, views_count
          FROM videos WHERE channel_id = ?
          AND (status IS NULL OR status = '' OR LOWER(status) IN ('published','approved','public','ok','active'))
          ORDER BY id DESC LIMIT 50");
        $st2->execute([(int)$c['id']]);
        $videos = $st2->fetchAll(PDO::FETCH_ASSOC);
      } catch (Throwable $e) {
        try {
          $st2 = db()->prepare("SELECT id, slug, title, thumbnail_url, embed_url, source_url, platform, created_at FROM videos WHERE channel_id = ? ORDER BY id DESC LIMIT 50");
          $st2->execute([(int)$c['id']]);
          $videos = $st2->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e2) {}
      }

      $schedule = [];
      try {
        $st3 = db()->prepare('SELECT sch.*, s.name as source_name FROM schedule sch JOIN sources s ON s.id = sch.source_id WHERE channel_id = ? ORDER BY day_of_week, start_time');
        $st3->execute([(int)$c['id']]);
        $schedule = $st3->fetchAll(PDO::FETCH_ASSOC);
      } catch (Throwable $e) {}

      $likes = 0;
      try {
        $lc = db()->prepare('SELECT COUNT(*) FROM channel_likes WHERE channel_id = ?');
        $lc->execute([(int)$c['id']]);
        $likes = (int)$lc->fetchColumn();
      } catch (Throwable $e) {}

      spa_json(['ok' => true, 'channel' => [
        'id' => (int)$c['id'], 'title' => (string)$c['title'], 'slug' => (string)($c['slug'] ?? ''),
        'logo' => $logo, 'description' => (string)($c['description'] ?? ''),
        'views' => (int)($c['views'] ?? $c['views_count'] ?? 0), 'likes' => $likes,
        'status' => (string)($c['status'] ?? ''), 'is_broadcast_paused' => !empty($c['is_broadcast_paused']),
        'updated_at' => (string)($c['updated_at'] ?? $c['modified_at'] ?? ''),
      ], 'live' => $live, 'videos' => $videos, 'schedule' => $schedule]);
    } catch (Throwable $e) {
      spa_json(['ok' => false, 'error' => 'Ошибка: '.$e->getMessage()], 500);
    }
  }

  if ($action === 'video') {
    if (!$user) spa_json(['ok' => false], 401);
    $lim = spa_check_limit((int)$user['id'], 'video');
    if (!$lim['ok']) spa_json(['ok' => false, 'limited' => true, 'error' => $lim['message'], 'retry_at' => $lim['retry_at'] ?? null, 'retry_seconds' => $lim['retry_seconds'] ?? null, 'upgrade' => true], 402);

    $id = (int)($_GET['id'] ?? 0);
    try {
      $st = db()->prepare("SELECT v.*, c.title as channel_title, c.slug as channel_slug
        FROM videos v LEFT JOIN channels c ON c.id = v.channel_id WHERE v.id = ? LIMIT 1");
      $st->execute([$id]);
      $v = $st->fetch(PDO::FETCH_ASSOC);
      if (!$v) spa_json(['ok' => false, 'error' => 'Видео не найдено'], 404);
      $emb = (string)($v['embed_url'] ?? '');
      $src = (string)($v['source_url'] ?? '');
      $plat = (string)($v['platform'] ?? '');
      // Не преобразуем ссылки через video_embed.php: используем только сохранённые в БД поля.
      $th = (string)($v['thumbnail_url'] ?? '');
      if ($th && $th[0] !== '/' && !preg_match('#^https?://#i', $th)) $th = '/'.ltrim($th, '/');
      spa_json(['ok' => true, 'video' => [
        'id' => (int)$v['id'], 'slug' => (string)($v['slug'] ?? ''), 'title' => (string)$v['title'],
        'description' => (string)($v['description'] ?? ''), 'thumbnail_url' => $th,
        'embed_url' => $emb, 'source_url' => $src, 'platform' => $plat,
        'channel_title' => (string)($v['channel_title'] ?? ''), 'channel_slug' => (string)($v['channel_slug'] ?? ''),
      ]]);
    } catch (Throwable $e) {
      spa_json(['ok' => false, 'error' => 'Ошибка'], 500);
    }
  }

  if ($action === 'online') {
    if (!$user) spa_json(['ok' => false], 401);
    $st = db()->query("SELECT p.user_id, p.tab_name, p.activity, p.game, p.detail, p.updated_at, u.username, u.avatar
      FROM spa_presence p JOIN users u ON u.id = p.user_id
      WHERE p.updated_at >= (NOW() - INTERVAL 90 SECOND)
      ORDER BY p.updated_at DESC LIMIT 50");
    $list = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $list[] = [
        'user' => spa_user_public($r),
        'tab' => $r['tab_name'], 'activity' => $r['activity'],
        'game' => $r['game'], 'detail' => $r['detail'], 'updated_at' => $r['updated_at'],
      ];
    }
    spa_json(['ok' => true, 'online' => $list]);
  }

  /* stories: реальная таблица движка native_stories (media_path), mutual, 24h */


  if ($action === 'react_toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok' => false], 401);
    $scope = preg_replace('/[^a-z_]/', '', (string)($_POST['scope'] ?? 'server'));
    $msgId = (int)($_POST['msg_id'] ?? 0);
    $emoji = mb_substr(trim((string)($_POST['emoji'] ?? '')), 0, 8);
    if ($msgId <= 0 || $emoji === '') spa_json(['ok' => false], 400);
    $uid = (int)$user['id'];
    try {
      $st = db()->prepare('SELECT id FROM spa_msg_reactions WHERE scope=? AND msg_id=? AND user_id=? AND emoji=? LIMIT 1');
      $st->execute([$scope, $msgId, $uid, $emoji]);
      $id = (int)$st->fetchColumn();
      if ($id) {
        db()->prepare('DELETE FROM spa_msg_reactions WHERE id=?')->execute([$id]);
        spa_json(['ok' => true, 'on' => false]);
      }
      db()->prepare('INSERT INTO spa_msg_reactions (scope, msg_id, user_id, emoji) VALUES (?,?,?,?)')
        ->execute([$scope, $msgId, $uid, $emoji]);
      spa_json(['ok' => true, 'on' => true]);
    } catch (Throwable $e) {
      spa_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
  }

  if ($action === 'react_list') {
    if (!$user) spa_json(['ok' => false], 401);
    $scope = preg_replace('/[^a-z_]/', '', (string)($_GET['scope'] ?? 'server'));
    $msgId = (int)($_GET['msg_id'] ?? 0);
    $ids = (string)($_GET['ids'] ?? '');
    $list = array_filter(array_map('intval', explode(',', $ids)));
    if ($msgId > 0) $list[] = $msgId;
    $list = array_values(array_unique($list));
    if (!$list) spa_json(['ok' => true, 'map' => new stdClass()]);
    $in = implode(',', array_fill(0, count($list), '?'));
    $map = [];
    try {
      $st = db()->prepare("SELECT msg_id, emoji, COUNT(*) c FROM spa_msg_reactions WHERE scope=? AND msg_id IN ($in) GROUP BY msg_id, emoji");
      $st->execute(array_merge([$scope], $list));
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $mid = (int)$r['msg_id'];
        if (!isset($map[$mid])) $map[$mid] = [];
        $map[$mid][] = ['emoji' => $r['emoji'], 'count' => (int)$r['c']];
      }
    } catch (Throwable $e) {}
    spa_json(['ok' => true, 'map' => $map ?: new stdClass()]);
  }

  if ($action === 'server_playlist_list') {
    if (!$user) spa_json(['ok' => false], 401);
    $srv = spa_user_server((int)$user['id']);
    if (!$srv) spa_json(['ok' => false, 'error' => 'Не в сервере'], 403);
    $items = [];
    try {
      $st = db()->prepare('SELECT p.*, u.username FROM spa_server_playlists p LEFT JOIN users u ON u.id=p.user_id WHERE p.server_id=? ORDER BY p.id DESC LIMIT 30');
      $st->execute([(int)$srv['id']]);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tracks = json_decode((string)$r['tracks_json'], true) ?: [];
        $items[] = [
          'id' => (int)$r['id'],
          'title' => (string)$r['title'],
          'username' => (string)($r['username'] ?? ''),
          'tracks' => $tracks,
          'created_at' => (string)$r['created_at'],
        ];
      }
    } catch (Throwable $e) {
      spa_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    spa_json(['ok' => true, 'playlists' => $items]);
  }

  if ($action === 'server_playlist_add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok' => false], 401);
    $srv = spa_user_server((int)$user['id']);
    if (!$srv) spa_json(['ok' => false, 'error' => 'Не в сервере'], 403);
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 120);
    $tracks = $_POST['tracks'] ?? '[]';
    if (is_string($tracks)) $tracks = json_decode($tracks, true) ?: [];
    if (!is_array($tracks)) $tracks = [];
    $tracks = array_values(array_slice($tracks, 0, 50));
    if ($title === '') spa_json(['ok' => false, 'error' => 'Название'], 400);
    try {
      db()->prepare('INSERT INTO spa_server_playlists (server_id, user_id, title, tracks_json) VALUES (?,?,?,?)')
        ->execute([(int)$srv['id'], (int)$user['id'], $title, json_encode($tracks, JSON_UNESCAPED_UNICODE)]);
      spa_json(['ok' => true, 'id' => (int)db()->lastInsertId()]);
    } catch (Throwable $e) {
      spa_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
  }


  if ($action === 'dm_list') {
    if (!$user) spa_json(['ok' => false], 401);
    $uid = (int)$user['id'];
    $list = [];
    try {
      $st = db()->prepare(
        "SELECT c.id AS conv_id,
                CASE WHEN c.user_a_id = ? THEN c.user_b_id ELSE c.user_a_id END AS peer_id,
                (SELECT body FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_body,
                (SELECT created_at FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_at
         FROM conversations c
         WHERE c.user_a_id = ? OR c.user_b_id = ?
         ORDER BY c.updated_at DESC LIMIT 80"
      );
      $st->execute([$uid, $uid, $uid]);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $peer = (int)$r['peer_id'];
        $u = null;
        try {
          $su = db()->prepare('SELECT id, username, avatar FROM users WHERE id = ? LIMIT 1');
          $su->execute([$peer]);
          $u = $su->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        if (!$u) continue;
        $list[] = [
          'conv_id' => (int)$r['conv_id'],
          'peer' => spa_user_public($u),
          'last_body' => (string)($r['last_body'] ?? ''),
          'last_at' => (string)($r['last_at'] ?? ''),
        ];
      }
    } catch (Throwable $e) {
      spa_json(['ok' => false, 'error' => 'Мессенджер БД: '.$e->getMessage()], 500);
    }
    spa_json(['ok' => true, 'conversations' => $list]);
  }

  if ($action === 'dm_thread') {
    if (!$user) spa_json(['ok' => false], 401);
    $uid = (int)$user['id'];
    $convId = (int)($_GET['conv_id'] ?? 0);
    if ($convId <= 0) spa_json(['ok' => false, 'error' => 'conv'], 400);
    $st = db()->prepare('SELECT * FROM conversations WHERE id = ? AND (user_a_id = ? OR user_b_id = ?) LIMIT 1');
    $st->execute([$convId, $uid, $uid]);
    if (!$st->fetch()) spa_json(['ok' => false, 'error' => 'Нет доступа'], 403);
    $msgs = [];
    try {
      $sm = db()->prepare(
        'SELECT m.id, m.body, m.sender_id, m.created_at, u.username
         FROM messages m LEFT JOIN users u ON u.id = m.sender_id
         WHERE m.conversation_id = ?
         ORDER BY m.id DESC LIMIT 80'
      );
      $sm->execute([$convId]);
      $rows = array_reverse($sm->fetchAll(PDO::FETCH_ASSOC));
      foreach ($rows as $m) {
        $msgs[] = [
          'id' => (int)$m['id'],
          'body' => (string)$m['body'],
          'sender_id' => (int)$m['sender_id'],
          'username' => (string)($m['username'] ?? ''),
          'mine' => (int)$m['sender_id'] === $uid,
          'created_at' => (string)$m['created_at'],
        ];
      }
    } catch (Throwable $e) {
      spa_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    spa_json(['ok' => true, 'messages' => $msgs]);
  }

  if ($action === 'dm_send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok' => false], 401);
    $uid = (int)$user['id'];
    $convId = (int)($_POST['conv_id'] ?? 0);
    $body = trim(mb_substr((string)($_POST['body'] ?? ''), 0, 4000));
    if ($convId <= 0 || $body === '') spa_json(['ok' => false, 'error' => 'Пусто'], 400);
    $st = db()->prepare('SELECT id FROM conversations WHERE id = ? AND (user_a_id = ? OR user_b_id = ?) LIMIT 1');
    $st->execute([$convId, $uid, $uid]);
    if (!$st->fetch()) spa_json(['ok' => false, 'error' => 'Нет доступа'], 403);
    try {
      db()->prepare('INSERT INTO messages (conversation_id, sender_id, body) VALUES (?,?,?)')
        ->execute([$convId, $uid, $body]);
      try { db()->prepare('UPDATE conversations SET updated_at = NOW() WHERE id = ?')->execute([$convId]); } catch (Throwable $e) {}
      spa_json(['ok' => true, 'id' => (int)db()->lastInsertId()]);
    } catch (Throwable $e) {
      spa_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
  }

  if ($action === 'dm_start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok' => false], 401);
    $uid = (int)$user['id'];
    $peer = (int)($_POST['user_id'] ?? 0);
    if ($peer <= 0 || $peer === $uid) spa_json(['ok' => false, 'error' => 'user'], 400);
    $a = min($uid, $peer); $b = max($uid, $peer);
    try {
      $st = db()->prepare('SELECT id FROM conversations WHERE user_a_id = ? AND user_b_id = ? LIMIT 1');
      $st->execute([$a, $b]);
      $cid = (int)$st->fetchColumn();
      if ($cid <= 0) {
        db()->prepare('INSERT INTO conversations (user_a_id, user_b_id) VALUES (?,?)')->execute([$a, $b]);
        $cid = (int)db()->lastInsertId();
      }
      spa_json(['ok' => true, 'conv_id' => $cid]);
    } catch (Throwable $e) {
      spa_json(['ok' => false, 'error' => 'Диалоги: '.$e->getMessage()], 500);
    }
  }


  if ($action === 'stories') {
    if (!$user) spa_json(['ok' => false], 401);
    $uid = (int)$user['id'];
    try { db()->exec("DELETE FROM native_stories WHERE expires_at < NOW()"); } catch (Throwable $e) {}
    $byUser = [];
    try {
      $st = db()->query("SELECT s.*, u.username, u.avatar FROM native_stories s JOIN users u ON u.id = s.user_id WHERE s.expires_at > NOW() ORDER BY s.created_at DESC LIMIT 100");
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $owner = (int)$row['user_id'];
        if ($owner !== $uid && !spa_is_mutual($uid, $owner)) continue;
        if (!isset($byUser[$owner])) {
          $byUser[$owner] = [ 'user' => spa_user_public($row), 'items' => [] ];
        }
        // движок: media_path + media_type; старый SPA: media_url
        $path = (string)($row['media_path'] ?? $row['media_url'] ?? '');
        $type = (string)($row['media_type'] ?? '');
        $mediaUrl = $path;
        if ($path !== '' && $path[0] === '/' && !preg_match('#^https?://#i', $path)) {
          // файл с диска движка — через native API media
          $mediaUrl = '/api/native_stories.php?action=media&id='.(int)$row['id'];
          // если media_path уже полный путь /uploads/...
          if (strpos($path, '/uploads/') === 0 || strpos($path, 'uploads/') !== false) {
            $mediaUrl = $path[0] === '/' ? $path : '/'.$path;
          }
        }
        if ($type === '' && preg_match('/\.(mp4|webm|mov|m4v)(\?|$)/i', $path)) $type = 'video';
        if ($type === '') $type = 'image';
        $byUser[$owner]['items'][] = [
          'id' => (int)$row['id'],
          'media_url' => $mediaUrl,
          'media_path' => $path,
          'media_type' => $type,
          'caption' => (string)($row['caption'] ?? ''),
          'created_at' => (string)($row['created_at'] ?? ''),
          'expires_at' => (string)($row['expires_at'] ?? ''),
        ];
      }
    } catch (Throwable $e) {
      spa_json(['ok' => false, 'error' => 'Таблица native_stories движка: '.$e->getMessage()], 500);
    }
    spa_json(['ok' => true, 'stories' => array_values($byUser)]);
  }

  if ($action === 'story_add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok' => false], 401);
    $url = trim((string)($_POST['media_url'] ?? $_POST['media_path'] ?? ''));
    $cap = mb_substr(trim((string)($_POST['caption'] ?? '')), 0, 255);
    if ($url === '') spa_json(['ok' => false, 'error' => 'Нужна ссылка на медиа'], 400);
    if ($url[0] !== '/' && !preg_match('#^https?://#i', $url)) {
      spa_json(['ok' => false, 'error' => 'Ссылка должна быть http(s) или путь /...'], 400);
    }
    $type = 'image';
    if (preg_match('/\.(mp4|webm|mov|m4v)(\?|$)/i', $url)) $type = 'video';
    $uid = (int)$user['id'];
    // 1 история / сутки как в движке
    try {
      $ex = db()->prepare('SELECT id FROM native_stories WHERE user_id=? AND created_at>=CURDATE() AND created_at<DATE_ADD(CURDATE(), INTERVAL 1 DAY) LIMIT 1');
      $ex->execute([$uid]);
      if ((int)$ex->fetchColumn() > 0) {
        spa_json(['ok' => false, 'error' => 'Сегодня уже есть история. Новая — после смены суток.', 'limit_reached' => true], 429);
      }
    } catch (Throwable $e) {}
    // INSERT в схему движка (media_path, media_type)
    try {
      db()->prepare('INSERT INTO native_stories (user_id, media_path, media_type, caption, expires_at) VALUES (?,?,?,?,DATE_ADD(NOW(), INTERVAL 24 HOUR))')
        ->execute([$uid, $url, $type, $cap !== '' ? $cap : null]);
      spa_json(['ok' => true, 'id' => (int)db()->lastInsertId()]);
    } catch (Throwable $e) {
      // fallback: если колонки media_url (старая SPA-схема)
      try {
        db()->prepare('DELETE FROM native_stories WHERE user_id = ?')->execute([$uid]);
        db()->prepare('INSERT INTO native_stories (user_id, media_url, caption, expires_at) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL 24 HOUR))')
          ->execute([$uid, $url, $cap]);
        spa_json(['ok' => true, 'fallback' => 'media_url']);
      } catch (Throwable $e2) {
        spa_json(['ok' => false, 'error' => 'Не удалось сохранить: '.$e->getMessage()], 500);
      }
    }
  }

  /* danmaku comments: merge every compatible legacy table plus SPA stream messages. */
  if ($action === 'danmaku_list' || $action === 'comments_list') {
    if (!$user) spa_json(['ok' => false, 'error' => 'Требуется вход'], 401);
    $tt = (string)($_GET['type'] ?? 'video');
    $tid = (int)($_GET['id'] ?? 0);
    if (!in_array($tt, ['video','channel'], true) || $tid <= 0) spa_json(['ok'=>true,'items'=>[]]);
    $items = [];
    $seen = [];
    $push = static function(array $r, string $source) use (&$items, &$seen): void {
      $text = trim((string)($r['text'] ?? ''));
      if ($text === '') return;
      $username = (string)($r['username'] ?? '');
      $created = (string)($r['created_at'] ?? '');
      // The same new message may be stored in both the engine table and spa_danmaku.
      $fingerprint = hash('sha256', mb_strtolower($username).'|'.$text.'|'.$created);
      if (isset($seen[$fingerprint])) return;
      $seen[$fingerprint] = true;
      $items[] = [
        'id' => (int)($r['id'] ?? 0), 'text' => mb_substr($text,0,120),
        'color' => (string)($r['color'] ?? '#ffffff'), 'created_at' => $created,
        'username' => $username, 'source' => $source,
      ];
    };
    $realQueries = $tt === 'video' ? [
      "SELECT c.id, c.message AS text, c.created_at, u.username FROM video_comments c JOIN users u ON u.id=c.user_id WHERE c.video_id=? ORDER BY c.id DESC LIMIT 200",
      "SELECT c.id, c.body AS text, c.created_at, u.username FROM comments c JOIN users u ON u.id=c.user_id WHERE c.video_id=? ORDER BY c.id DESC LIMIT 200",
      "SELECT c.id, c.text AS text, c.created_at, u.username FROM video_comments c JOIN users u ON u.id=c.user_id WHERE c.video_id=? ORDER BY c.id DESC LIMIT 200",
      "SELECT c.id, c.comment AS text, c.created_at, u.username FROM comments c JOIN users u ON u.id=c.user_id WHERE c.video_id=? ORDER BY c.id DESC LIMIT 200",
    ] : [
      "SELECT c.id, c.message AS text, c.created_at, u.username FROM comments c JOIN users u ON u.id=c.user_id WHERE c.channel_id=? AND (c.is_deleted IS NULL OR c.is_deleted=0) ORDER BY c.id DESC LIMIT 200",
      "SELECT c.id, c.body AS text, c.created_at, u.username FROM comments c JOIN users u ON u.id=c.user_id WHERE c.channel_id=? ORDER BY c.id DESC LIMIT 200",
      "SELECT c.id, c.text AS text, c.created_at, u.username FROM channel_comments c JOIN users u ON u.id=c.user_id WHERE c.channel_id=? ORDER BY c.id DESC LIMIT 200",
      "SELECT c.id, c.comment AS text, c.created_at, u.username FROM channel_comments c JOIN users u ON u.id=c.user_id WHERE c.channel_id=? ORDER BY c.id DESC LIMIT 200",
    ];
    foreach ($realQueries as $queryIndex => $sql) {
      try {
        $st = db()->prepare($sql); $st->execute([$tid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $push($r, 'db'.$queryIndex);
      } catch (Throwable $e) { /* schema differs between engine versions; try the next known layout */ }
    }
    try {
      $st = db()->prepare('SELECT d.id, d.text, d.color, d.created_at, u.username FROM spa_danmaku d JOIN users u ON u.id=d.user_id WHERE d.target_type=? AND d.target_id=? ORDER BY d.id DESC LIMIT 200');
      $st->execute([$tt, $tid]);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $push($r, 'spa');
    } catch (Throwable $e) {}
    usort($items, static function($a,$b){ return strcmp((string)$a['created_at'],(string)$b['created_at']); });
    if (count($items) > 200) $items = array_slice($items, -200);
    spa_json(['ok' => true, 'items' => $items]);
  }

  if (($action === 'danmaku_add' || $action === 'comments_add') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok' => false], 401);
    $tt = (string)($_POST['type'] ?? 'video');
    $tid = (int)($_POST['id'] ?? 0);
    $text = mb_substr(trim((string)($_POST['text'] ?? '')), 0, 500);
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['color'] ?? '')) ? (string)$_POST['color'] : '#ffffff';
    if (!in_array($tt, ['video','channel'], true) || $text === '' || $tid <= 0) spa_json(['ok' => false, 'error' => 'Некорректный тип, объект или пустой комментарий'], 400);
    $mirrored = false;
    try {
      db()->prepare('INSERT INTO spa_danmaku (target_type, target_id, user_id, text, color) VALUES (?,?,?,?,?)')
        ->execute([$tt, $tid, (int)$user['id'], mb_substr($text, 0, 120), $color]);
    } catch (Throwable $e) {
      spa_json(['ok'=>false,'error'=>'Не удалось сохранить комментарий в базе данных. Попробуй ещё раз.'],500);
    }
    $inserts = [];
    if ($tt === 'video') {
      $inserts = [
        "INSERT INTO video_comments (video_id, user_id, message, created_at) VALUES (?,?,?,NOW())",
        "INSERT INTO comments (video_id, user_id, message, created_at) VALUES (?,?,?,NOW())",
        "INSERT INTO comments (video_id, user_id, body, created_at) VALUES (?,?,?,NOW())",
        "INSERT INTO video_comments (video_id, user_id, text, created_at) VALUES (?,?,?,NOW())",
        "INSERT INTO comments (video_id, user_id, comment, created_at) VALUES (?,?,?,NOW())",
      ];
    } elseif ($tt === 'channel') {
      $inserts = [
        "INSERT INTO comments (channel_id, user_id, message, created_at) VALUES (?,?,?,NOW())",
        "INSERT INTO comments (channel_id, user_id, body, created_at) VALUES (?,?,?,NOW())",
        "INSERT INTO channel_comments (channel_id, user_id, text, created_at) VALUES (?,?,?,NOW())",
      ];
    }
    foreach ($inserts as $sql) {
      try {
        db()->prepare($sql)->execute([$tid, (int)$user['id'], $text]);
        $mirrored = true;
        break;
      } catch (Throwable $e) {}
    }
    // spa_danmaku is the canonical persistent store; legacy engine tables are mirrored best-effort.
    spa_json(['ok' => true, 'saved_db' => true, 'mirrored_to_engine' => $mirrored]);
  }

  if ($action === 'fav_toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok' => false], 401);
    $tt = (string)($_POST['type'] ?? '');
    $tid = (int)($_POST['id'] ?? 0);
    if ($tt === '' || $tid <= 0) spa_json(['ok' => false], 400);
    $st = db()->prepare('SELECT 1 FROM spa_favorites WHERE user_id=? AND target_type=? AND target_id=?');
    $st->execute([(int)$user['id'], $tt, $tid]);
    if ($st->fetchColumn()) {
      db()->prepare('DELETE FROM spa_favorites WHERE user_id=? AND target_type=? AND target_id=?')->execute([(int)$user['id'], $tt, $tid]);
      spa_json(['ok' => true, 'on' => false]);
    }
    db()->prepare('INSERT INTO spa_favorites (user_id, target_type, target_id) VALUES (?,?,?)')->execute([(int)$user['id'], $tt, $tid]);
    spa_json(['ok' => true, 'on' => true]);
  }

  if ($action === 'favorites') {
    if (!$user) spa_json(['ok' => false], 401);
    $st = db()->prepare('SELECT target_type, target_id, created_at FROM spa_favorites WHERE user_id=? ORDER BY created_at DESC LIMIT 100');
    $st->execute([(int)$user['id']]);
    spa_json(['ok' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  if ($action === 'fav_status') {
    if (!$user) spa_json(['ok' => false], 401);
    $tt = (string)($_GET['type'] ?? ''); $tid = (int)($_GET['id'] ?? 0);
    if (!in_array($tt, ['channel','video','cinema'], true) || $tid <= 0) spa_json(['ok'=>false], 400);
    $st = db()->prepare('SELECT 1 FROM spa_favorites WHERE user_id=? AND target_type=? AND target_id=? LIMIT 1');
    $st->execute([(int)$user['id'], $tt, $tid]);
    spa_json(['ok'=>true, 'on'=>(bool)$st->fetchColumn()]);
  }

  if ($action === 'planner_list') {
    if (!$user) spa_json(['ok'=>false], 401);
    $st = db()->prepare("SELECT id,title,note,due_at,is_done,created_at FROM spa_planner_items WHERE user_id=? ORDER BY is_done ASC, COALESCE(due_at, '9999-12-31 23:59:59') ASC, id DESC LIMIT 200");
    $st->execute([(int)$user['id']]);
    spa_json(['ok'=>true,'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }
  if ($action === 'planner_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok'=>false], 401);
    $title = trim((string)($_POST['title'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    $due = trim((string)($_POST['due_at'] ?? ''));
    if ($title === '') spa_json(['ok'=>false,'error'=>'Укажи название задачи'], 400);
    if (mb_strlen($title) > 180 || mb_strlen($note) > 500) spa_json(['ok'=>false,'error'=>'Слишком длинный текст'], 400);
    $dueSql = null;
    if ($due !== '') { $ts = strtotime($due); if (!$ts) spa_json(['ok'=>false,'error'=>'Некорректная дата'], 400); $dueSql = date('Y-m-d H:i:s',$ts); }
    db()->prepare('INSERT INTO spa_planner_items (user_id,title,note,due_at) VALUES (?,?,?,?)')->execute([(int)$user['id'],$title,$note,$dueSql]);
    spa_json(['ok'=>true,'id'=>(int)db()->lastInsertId()]);
  }
  if ($action === 'planner_toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok'=>false], 401);
    $id=(int)($_POST['id']??0); $done=(int)($_POST['done']??0) ? 1 : 0;
    db()->prepare('UPDATE spa_planner_items SET is_done=? WHERE id=? AND user_id=?')->execute([$done,$id,(int)$user['id']]);
    spa_json(['ok'=>true]);
  }
  if ($action === 'planner_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok'=>false], 401);
    $id=(int)($_POST['id']??0);
    db()->prepare('DELETE FROM spa_planner_items WHERE id=? AND user_id=?')->execute([$id,(int)$user['id']]);
    spa_json(['ok'=>true]);
  }

  if ($action === 'media_continue_list') {
    if (!$user) spa_json(['ok'=>false], 401);
    $cinemaStatus = spa_limit_status((int)$user['id'], 'cinema');
    $cinemaLocked = empty($cinemaStatus['ok']) || !empty($cinemaStatus['limited']) || (int)($cinemaStatus['remaining'] ?? 0) <= 0;
    $st=db()->prepare('SELECT item_json,updated_at FROM spa_media_continue WHERE user_id=? ORDER BY updated_at DESC LIMIT 40');
    $st->execute([(int)$user['id']]);$items=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){$x=json_decode((string)$r['item_json'],true);if(is_array($x)){
      if ($cinemaLocked && (($x['kind'] ?? '') === 'cinema')) continue;
      $x['at']=strtotime((string)$r['updated_at'])*1000;$items[]=$x;
    }}
    spa_json(['ok'=>true,'items'=>$items,'cinema_locked'=>$cinemaLocked,'cinema_retry_at'=>$cinemaStatus['retry_at']??null]);
  }
  if ($action === 'media_continue_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok'=>false], 401);
    $kind=(string)($_POST['kind']??'');$id=trim((string)($_POST['id']??''));
    if(!in_array($kind,['video','channel','cinema'],true)||$id===''||strlen($id)>64)spa_json(['ok'=>false],400);
    $position=max(0,min(86400,(float)($_POST['position']??0)));$duration=max(0,min(86400,(float)($_POST['duration']??0)));
    $item=['kind'=>$kind,'id'=>$id,'title'=>mb_substr(trim((string)($_POST['title']??'')),0,180),'avatar'=>mb_substr(trim((string)($_POST['avatar']??'')),0,1000),'embed_url'=>mb_substr(trim((string)($_POST['embed_url']??'')),0,1000),'source_url'=>mb_substr(trim((string)($_POST['source_url']??'')),0,1000),'platform'=>mb_substr(trim((string)($_POST['platform']??'')),0,60),'slug'=>mb_substr(trim((string)($_POST['slug']??'')),0,160),'subtitle'=>mb_substr(trim((string)($_POST['subtitle']??'')),0,80),'position'=>$position,'duration'=>$duration,'progress'=>($duration>0?min(100,round($position/$duration*100,1)):0)];
    $json=json_encode($item,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    db()->prepare('INSERT INTO spa_media_continue (user_id,item_type,item_id,item_json) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE item_json=VALUES(item_json),updated_at=NOW()')->execute([(int)$user['id'],$kind,$id,$json]);
    spa_json(['ok'=>true]);
  }
  if ($action === 'media_continue_clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok'=>false],401);
    db()->prepare('DELETE FROM spa_media_continue WHERE user_id=?')->execute([(int)$user['id']]);spa_json(['ok'=>true]);
  }
  if ($action === 'media_history_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok'=>false],401);
    $kind=(string)($_POST['kind']??'video');$id=trim((string)($_POST['id']??''));$title=mb_substr(trim((string)($_POST['title']??'')),0,180);
    if(!in_array($kind,['video','channel','cinema'],true)||$id===''||strlen($id)>64)spa_json(['ok'=>false],400);
    db()->prepare('INSERT INTO spa_media_history (user_id,item_type,item_id,title) VALUES (?,?,?,?)')->execute([(int)$user['id'],$kind,$id,$title]);
    db()->prepare('DELETE FROM spa_media_history WHERE user_id=? AND viewed_at < DATE_SUB(NOW(), INTERVAL 30 DAY)')->execute([(int)$user['id']]);
    spa_json(['ok'=>true]);
  }
  if ($action === 'media_history_list') {
    if (!$user) spa_json(['ok'=>false],401);
    $st=db()->prepare('SELECT item_type AS kind,item_id AS id,title,UNIX_TIMESTAMP(viewed_at)*1000 AS ts FROM spa_media_history WHERE user_id=? ORDER BY viewed_at DESC LIMIT 80');
    $st->execute([(int)$user['id']]);spa_json(['ok'=>true,'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }
  if ($action === 'media_history_clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok'=>false],401);
    db()->prepare('DELETE FROM spa_media_history WHERE user_id=?')->execute([(int)$user['id']]);spa_json(['ok'=>true]);
  }
  if ($action === 'services_list') {
    if (!$user) spa_json(['ok'=>false],401);
    $st=db()->prepare('SELECT id,name,url,created_at FROM spa_user_services WHERE user_id=? ORDER BY id DESC LIMIT 100');$st->execute([(int)$user['id']]);spa_json(['ok'=>true,'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }
  if ($action === 'services_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok'=>false],401);
    $name=trim((string)($_POST['name']??''));$url=trim((string)($_POST['url']??''));
    if($name===''||mb_strlen($name)>100||!filter_var($url,FILTER_VALIDATE_URL)||!preg_match('#^https?://#i',$url))spa_json(['ok'=>false,'error'=>'Укажи название и корректную ссылку http(s)'],400);
    db()->prepare('INSERT INTO spa_user_services (user_id,name,url) VALUES (?,?,?)')->execute([(int)$user['id'],$name,$url]);spa_json(['ok'=>true,'id'=>(int)db()->lastInsertId()]);
  }
  if ($action === 'services_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok'=>false],401);
    db()->prepare('DELETE FROM spa_user_services WHERE id=? AND user_id=?')->execute([(int)($_POST['id']??0),(int)$user['id']]);spa_json(['ok'=>true]);
  }
  if ($action === 'prefs_get') {
    if (!$user) spa_json(['ok'=>false],401);
    $st=db()->prepare('SELECT prefs_json FROM spa_user_preferences WHERE user_id=? LIMIT 1');$st->execute([(int)$user['id']]);$raw=$st->fetchColumn();$prefs=json_decode((string)$raw,true);spa_json(['ok'=>true,'prefs'=>is_array($prefs)?$prefs:[]]);
  }
  if ($action === 'prefs_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok'=>false],401);
    $color=(string)($_POST['accentColor']??'#8b5cf6');if(!preg_match('/^#[0-9a-fA-F]{6}$/',$color))$color='#8b5cf6';
    $font=max(0.9,min(1.2,(float)($_POST['fontScale']??1)));
    $prefs=['accentColor'=>$color,'fontScale'=>$font,'compact'=>!empty($_POST['compact']),'reduceMotion'=>!empty($_POST['reduceMotion']),'homeContinue'=>!isset($_POST['homeContinue'])||!in_array((string)$_POST['homeContinue'],['0','false',''],true)];
    $json=json_encode($prefs,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    db()->prepare('INSERT INTO spa_user_preferences (user_id,prefs_json) VALUES (?,?) ON DUPLICATE KEY UPDATE prefs_json=VALUES(prefs_json),updated_at=NOW()')->execute([(int)$user['id'],$json]);spa_json(['ok'=>true,'prefs'=>$prefs]);
  }


  if ($action === 'server_roles_list') {
    if (!$user) spa_json(['ok'=>false],401);
    $srv=spa_user_server((int)$user['id']); if(!$srv) spa_json(['ok'=>false,'error'=>'Не в сервере'],403);
    $st=db()->prepare('SELECT r.id,r.name,r.permissions_json,r.position,(SELECT COUNT(*) FROM spa_server_member_roles mr WHERE mr.role_id=r.id) AS members FROM spa_server_roles r WHERE r.server_id=? ORDER BY r.position,r.id');
    $st->execute([(int)$srv['id']]); $items=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){$r['permissions']=json_decode((string)$r['permissions_json'],true) ?: []; unset($r['permissions_json']);$items[]=$r;}
    $as=db()->prepare('SELECT user_id,role_id FROM spa_server_member_roles WHERE server_id=?');$as->execute([(int)$srv['id']]);$assign=[];foreach($as->fetchAll(PDO::FETCH_ASSOC) as $a)$assign[(string)$a['user_id']]=(int)$a['role_id'];
    spa_json(['ok'=>true,'roles'=>$items,'assignments'=>$assign,'is_owner'=>((int)$srv['owner_id']===(int)$user['id'])]);
  }
  if ($action === 'server_role_save' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user) spa_json(['ok'=>false],401); $srv=spa_user_server((int)$user['id']);
    if(!$srv || !spa_server_can((int)$srv['id'],(int)$user['id'],'manage_roles')) spa_json(['ok'=>false,'error'=>'Нет права управлять ролями'],403);
    $name=mb_substr(trim((string)($_POST['name']??'')),0,48); if($name==='') spa_json(['ok'=>false,'error'=>'Укажи название роли'],400);
    $raw=json_decode((string)($_POST['permissions']??'{}'),true); if(!is_array($raw)) $raw=[];
    $allowed=['manage_chat','kick_members','manage_apps','manage_webhooks','manage_roles','send_announcements','manage_welcome','manage_badges','manage_tickets','manage_polls','manage_events','view_audit']; $perms=[]; foreach($allowed as $k)$perms[$k]=!empty($raw[$k]);
    $id=(int)($_POST['role_id']??0); $json=json_encode($perms,JSON_UNESCAPED_UNICODE);
    if($id){$st=db()->prepare('UPDATE spa_server_roles SET name=?,permissions_json=? WHERE id=? AND server_id=?');$st->execute([$name,$json,$id,(int)$srv['id']]);}
    else {db()->prepare('INSERT INTO spa_server_roles (server_id,name,permissions_json,position) VALUES (?,?,?,0)')->execute([(int)$srv['id'],$name,$json]);$id=(int)db()->lastInsertId();}
    spa_server_audit((int)$srv['id'],(int)$user['id'],'role.saved',$name);
    spa_json(['ok'=>true,'id'=>$id,'permissions'=>$perms]);
  }
  if ($action === 'server_role_assign' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user) spa_json(['ok'=>false],401); $srv=spa_user_server((int)$user['id']);
    if(!$srv || !spa_server_can((int)$srv['id'],(int)$user['id'],'manage_roles')) spa_json(['ok'=>false,'error'=>'Нет права управлять ролями'],403);
    $target=(int)($_POST['user_id']??0); $rid=(int)($_POST['role_id']??0);
    $st=db()->prepare('SELECT 1 FROM spa_server_members WHERE server_id=? AND user_id=?');$st->execute([(int)$srv['id'],$target]);if(!$st->fetchColumn())spa_json(['ok'=>false,'error'=>'Участник не найден'],404);
    $roleName='';
    if($rid===0){db()->prepare('DELETE FROM spa_server_member_roles WHERE server_id=? AND user_id=?')->execute([(int)$srv['id'],$target]);}
    else {$st=db()->prepare('SELECT name FROM spa_server_roles WHERE id=? AND server_id=? LIMIT 1');$st->execute([$rid,(int)$srv['id']]);$roleName=(string)($st->fetchColumn()?:'');if($roleName==='')spa_json(['ok'=>false,'error'=>'Роль не найдена'],404);db()->prepare('INSERT INTO spa_server_member_roles (server_id,user_id,role_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE role_id=VALUES(role_id)')->execute([(int)$srv['id'],$target,$rid]);}
    spa_server_audit((int)$srv['id'],(int)$user['id'],'role.assigned',$roleName!==''?'Назначена роль «'.$roleName.'» участнику #'.$target:'Снята пользовательская роль с участника #'.$target);
    spa_json(['ok'=>true,'role_id'=>$rid,'role_name'=>$roleName]);
  }
  if ($action === 'my_created_services') {
    if (!$user) spa_json(['ok'=>false,'error'=>'Требуется вход'],401);
    try {
      $st=db()->prepare('SELECT a.id,a.server_id,a.name,a.base_url,a.description,a.icon,a.display_mode,a.service_type,a.manifest_json,a.created_at,a.is_published,a.published_at,a.install_count,a.platform,s.name AS server_name FROM spa_server_apps a LEFT JOIN spa_servers s ON s.id=a.server_id WHERE a.created_by=? ORDER BY a.id DESC LIMIT 100');
      $st->execute([(int)$user['id']]); $apps=$st->fetchAll(PDO::FETCH_ASSOC);
      foreach($apps as &$app){$app['manifest']=json_decode((string)($app['manifest_json']??''),true)?:[];unset($app['manifest_json']);}
      unset($app);
      spa_json(['ok'=>true,'services'=>$apps,'count'=>count($apps)]);
    } catch(Throwable $e) { spa_json(['ok'=>false,'error'=>'Не удалось загрузить созданные сервисы'],500); }
  }

  if ($action === 'server_apps_list') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_user_server((int)$user['id']);if(!$srv)spa_json(['ok'=>false,'error'=>'Не в сервере'],403);
    $canManage=spa_server_can((int)$srv['id'],(int)$user['id'],'manage_apps');
    $sql='SELECT id,name,base_url,description,icon,display_mode,service_type,manifest_json,created_at,is_published,published_at,install_count,platform FROM spa_server_apps WHERE server_id=?';
    if(!$canManage)$sql.=' AND is_published=1';
    $sql.=' ORDER BY id DESC';$st=db()->prepare($sql);$st->execute([(int)$srv['id']]);$apps=$st->fetchAll(PDO::FETCH_ASSOC);
    foreach($apps as &$app){$app['manifest']=json_decode((string)($app['manifest_json']??''),true)?:[];unset($app['manifest_json']);}unset($app);
    $quota=['active'=>spa_has_sub((int)$user['id']),'limit'=>10,'used'=>0,'remaining'=>10];
    try{$q=db()->prepare('SELECT COUNT(*) FROM spa_server_app_creation_log WHERE server_id=? AND user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)');$q->execute([(int)$srv['id'],(int)$user['id']]);$quota['used']=(int)$q->fetchColumn();$quota['remaining']=max(0,10-$quota['used']);}catch(Throwable $e){}
    spa_json(['ok'=>true,'apps'=>$apps,'can_manage'=>$canManage,'creation_quota'=>$quota]);
  }
  if ($action === 'server_apps_catalog') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_user_server((int)$user['id']);if(!$srv)spa_json(['ok'=>false,'error'=>'Не в сервере'],403);
    $st=db()->prepare('SELECT a.id,a.name,a.description,a.icon,a.base_url,a.display_mode,a.service_type,a.manifest_json,a.install_count,a.created_at,a.platform,IF(i.user_id IS NULL,0,1) AS installed,COALESCE(rv.avg_rating,0) AS avg_rating,COALESCE(rv.review_count,0) AS review_count,COALESCE(mr.rating,0) AS my_rating FROM spa_server_apps a LEFT JOIN spa_server_app_installs i ON i.server_id=a.server_id AND i.app_id=a.id AND i.user_id=? LEFT JOIN (SELECT server_id,app_id,AVG(rating) avg_rating,COUNT(*) review_count FROM spa_server_app_reviews GROUP BY server_id,app_id) rv ON rv.server_id=a.server_id AND rv.app_id=a.id LEFT JOIN spa_server_app_reviews mr ON mr.server_id=a.server_id AND mr.app_id=a.id AND mr.user_id=? WHERE a.server_id=? AND a.is_published=1 ORDER BY a.install_count DESC,a.id DESC');
    $st->execute([(int)$user['id'],(int)$user['id'],(int)$srv['id']]);$apps=$st->fetchAll(PDO::FETCH_ASSOC);foreach($apps as &$app){$app['manifest']=json_decode((string)($app['manifest_json']??''),true)?:[];unset($app['manifest_json']);}unset($app);
    $reviewsByApp=[];
    try{$rv=db()->prepare("SELECT r.app_id,r.rating,r.review,r.updated_at,u.username FROM spa_server_app_reviews r JOIN users u ON u.id=r.user_id WHERE r.server_id=? AND r.review<>'' ORDER BY r.updated_at DESC LIMIT 120");$rv->execute([(int)$srv['id']]);foreach($rv->fetchAll(PDO::FETCH_ASSOC) as $rr){$aid=(int)$rr['app_id'];if(!isset($reviewsByApp[$aid]))$reviewsByApp[$aid]=[];if(count($reviewsByApp[$aid])<3)$reviewsByApp[$aid][]=$rr;}}catch(Throwable $e){}
    foreach($apps as &$app){$app['reviews']=$reviewsByApp[(int)$app['id']]??[];}unset($app);
    spa_json(['ok'=>true,'apps'=>$apps]);
  }
  if ($action === 'server_app_install' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_user_server((int)$user['id']);if(!$srv)spa_json(['ok'=>false,'error'=>'Не в сервере'],403);
    $id=(int)($_POST['id']??0);$st=db()->prepare('SELECT id FROM spa_server_apps WHERE id=? AND server_id=? AND is_published=1');$st->execute([$id,(int)$srv['id']]);if(!$st->fetchColumn())spa_json(['ok'=>false,'error'=>'Приложение не найдено в каталоге'],404);
    $installed=!empty($_POST['installed']);
    if($installed){db()->prepare('INSERT IGNORE INTO spa_server_app_installs(server_id,app_id,user_id) VALUES(?,?,?)')->execute([(int)$srv['id'],$id,(int)$user['id']]);db()->prepare('UPDATE spa_server_apps SET install_count=(SELECT COUNT(*) FROM spa_server_app_installs WHERE server_id=? AND app_id=?) WHERE id=? AND server_id=?')->execute([(int)$srv['id'],$id,$id,(int)$srv['id']]);}
    else{db()->prepare('DELETE FROM spa_server_app_installs WHERE server_id=? AND app_id=? AND user_id=?')->execute([(int)$srv['id'],$id,(int)$user['id']]);db()->prepare('UPDATE spa_server_apps SET install_count=(SELECT COUNT(*) FROM spa_server_app_installs WHERE server_id=? AND app_id=?) WHERE id=? AND server_id=?')->execute([(int)$srv['id'],$id,$id,(int)$srv['id']]);}
    spa_json(['ok'=>true,'installed'=>$installed]);
  }
  if ($action === 'server_app_review_save' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user)spa_json(['ok'=>false,'error'=>'Нужен вход'],401);
    $srv=spa_user_server((int)$user['id']);if(!$srv)spa_json(['ok'=>false,'error'=>'Не в сервере'],403);
    $id=(int)($_POST['id']??0);$rating=(int)($_POST['rating']??0);$review=mb_substr(trim((string)($_POST['review']??'')),0,500);
    if($rating<1||$rating>5)spa_json(['ok'=>false,'error'=>'Оценка должна быть от 1 до 5'],400);
    $check=db()->prepare('SELECT id FROM spa_server_apps WHERE id=? AND server_id=? AND is_published=1');$check->execute([$id,(int)$srv['id']]);if(!$check->fetchColumn())spa_json(['ok'=>false,'error'=>'Приложение не найдено'],404);
    db()->prepare('INSERT INTO spa_server_app_reviews(server_id,app_id,user_id,rating,review) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE rating=VALUES(rating),review=VALUES(review),updated_at=NOW()')->execute([(int)$srv['id'],$id,(int)$user['id'],$rating,$review]);
    $agg=db()->prepare('SELECT AVG(rating) avg_rating,COUNT(*) review_count FROM spa_server_app_reviews WHERE server_id=? AND app_id=?');$agg->execute([(int)$srv['id'],$id]);$stats=$agg->fetch(PDO::FETCH_ASSOC)?:[];
    spa_server_audit((int)$srv['id'],(int)$user['id'],'app.reviewed','app_id='.$id.';rating='.$rating);
    spa_json(['ok'=>true,'rating'=>$rating,'avg_rating'=>round((float)($stats['avg_rating']??0),2),'review_count'=>(int)($stats['review_count']??0)]);
  }
  if ($action === 'server_app_publish' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_user_server((int)$user['id']);if(!$srv||!spa_server_can((int)$srv['id'],(int)$user['id'],'manage_apps'))spa_json(['ok'=>false,'error'=>'Нет права публиковать приложения'],403);
    $id=(int)($_POST['id']??0);$published=!empty($_POST['published']);$st=db()->prepare('UPDATE spa_server_apps SET is_published=?,published_at=? WHERE id=? AND server_id=?');$st->execute([$published?1:0,$published?date('Y-m-d H:i:s'):null,$id,(int)$srv['id']]);if(!$st->rowCount()){$q=db()->prepare('SELECT id FROM spa_server_apps WHERE id=? AND server_id=?');$q->execute([$id,(int)$srv['id']]);if(!$q->fetchColumn())spa_json(['ok'=>false,'error'=>'Сервис не найден'],404);}
    spa_server_audit((int)$srv['id'],(int)$user['id'],$published?'app.published':'app.unpublished','app_id='.$id);spa_json(['ok'=>true,'published'=>$published]);
  }
  if ($action === 'server_app_save' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_user_server((int)$user['id']);if(!$srv||!spa_server_can((int)$srv['id'],(int)$user['id'],'manage_apps'))spa_json(['ok'=>false,'error'=>'Нет права управлять сервисами'],403);
    if(!spa_has_sub((int)$user['id']))spa_json(['ok'=>false,'error'=>'Создание сервисов доступно только с активной подпиской SPA. Оформи подписку и попробуй снова.','code'=>'subscription_required'],402);
    $name=mb_substr(trim((string)($_POST['name']??'')),0,80);$url=trim((string)($_POST['url']??''));$desc=mb_substr(trim((string)($_POST['description']??'')),0,255);$icon=trim((string)($_POST['icon']??'🔌'));$mode=(string)($_POST['display_mode']??'open');$platform=(string)($_POST['platform']??'universal');
    if($name===''||!filter_var($url,FILTER_VALIDATE_URL)||!preg_match('#^https://#i',$url))spa_json(['ok'=>false,'error'=>'Укажи название и HTTPS URL базового API или сервиса'],400);
    $parts=parse_url($url);if(!$parts||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment']))spa_json(['ok'=>false,'error'=>'URL API должен быть базовым HTTPS-адресом без логина, пароля, query и fragment'],400);$apiHost=strtolower(trim((string)$parts['host'],'[].'));if($apiHost==='localhost'||preg_match('/(?:\.localhost|\.local|\.internal)$/',$apiHost))spa_json(['ok'=>false,'error'=>'Локальные и внутренние адреса API запрещены'],400);if(filter_var($apiHost,FILTER_VALIDATE_IP)&&filter_var($apiHost,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)spa_json(['ok'=>false,'error'=>'Частные и служебные IP-адреса API запрещены'],400);
    if(!in_array($mode,['open','embed'],true))$mode='open';if(!in_array($platform,['universal','android','ios','desktop','web','api'],true))$platform='universal'; if(strlen($icon)>24)$icon='🔌';
    $rawApiSpec=trim((string)($_POST['api_spec']??''));$rawManifest=trim((string)($_POST['manifest']??''));$manifest=[];$serviceType='link';
    if($rawApiSpec!==''){
      if(strlen($rawApiSpec)>1000000)spa_json(['ok'=>false,'error'=>'OpenAPI JSON слишком большой (максимум 1 МБ)'],413);$spec=json_decode($rawApiSpec,true);if(!is_array($spec)||json_last_error()!==JSON_ERROR_NONE||(!isset($spec['openapi'])&&!isset($spec['swagger'])))spa_json(['ok'=>false,'error'=>'Вставь корректную OpenAPI 3.x или Swagger 2.0 JSON-спецификацию'],400);
      $resolveSchema=function($node)use($spec){if(!is_array($node)||empty($node['$ref'])||!is_string($node['$ref'])||strpos($node['$ref'],'#/')!==0)return $node;$cur=$spec;foreach(explode('/',substr($node['$ref'],2)) as $part){$part=str_replace(['~1','~0'],['/','~'],$part);if(!is_array($cur)||!array_key_exists($part,$cur))return []; $cur=$cur[$part];}return is_array($cur)?$cur:[];};$paths=$spec['paths']??[];if(!is_array($paths))spa_json(['ok'=>false,'error'=>'В OpenAPI не найден раздел paths'],400);
      $ops=[];$allowedMethods=['get','post','put','patch','delete'];
      foreach($paths as $path=>$pathItem){if(!is_array($pathItem)||!is_string($path)||$path===''||$path[0]!=='/'||strpos($path,'..')!==false)continue;
        foreach($allowedMethods as $method){if(empty($pathItem[$method])||!is_array($pathItem[$method]))continue;$op=$pathItem[$method];$params=array_merge(is_array($pathItem['parameters']??null)?$pathItem['parameters']:[],is_array($op['parameters']??null)?$op['parameters']:[]);$fields=[];
          foreach($params as $pa){if(!is_array($pa)||!in_array(($pa['in']??''),['path','query'],true)||empty($pa['name'])||preg_match('/[\r\n]/',(string)$pa['name']))continue;$schema=$resolveSchema(is_array($pa['schema']??null)?$pa['schema']:[]);$ftype=in_array(($schema['type']??'string'),['integer','number'],true)?'number':(!empty($schema['enum'])&&is_array($schema['enum'])?'select':'text');$field=['name'=>mb_substr(trim((string)$pa['name']),0,100),'label'=>mb_substr((string)($pa['description']??$pa['name']),0,80),'in'=>$pa['in'],'type'=>$ftype,'required'=>!empty($pa['required'])];if($ftype==='select')$field['options']=array_slice(array_values(array_map('strval',$schema['enum'])),0,20);$fields[]=$field;}
          $bodyFields=[];$rb=$op['requestBody']['content']['application/json']['schema']??[];if(isset($spec['swagger'])&&!$rb){foreach($params as $pa){if(is_array($pa)&&($pa['in']??'')==='body'&&is_array($pa['schema']??null)){$rb=$pa['schema'];break;}}}$rb=$resolveSchema($rb);$props=is_array($rb['properties']??null)?$rb['properties']:[];$required=is_array($rb['required']??null)?$rb['required']:[];foreach(array_slice($props,0,12,true) as $key=>$sch){$sch=$resolveSchema($sch);if(!is_array($sch))continue;$typ=($sch['type']??'string');if(!in_array($typ,['string','integer','number','boolean'],true))continue;$ftype=$typ==='integer'||$typ==='number'?'number':($typ==='boolean'?'checkbox':(!empty($sch['enum'])&&is_array($sch['enum'])?'select':'text'));$bf=['name'=>(string)$key,'label'=>mb_substr((string)($sch['description']??$key),0,80),'type'=>$ftype,'required'=>in_array($key,$required,true)];if($ftype==='select')$bf['options']=array_slice(array_values(array_map('strval',$sch['enum'])),0,20);$bodyFields[]=$bf;}
          $opId=mb_substr((string)($op['operationId']??(strtoupper($method).' '.trim($path,'/'))),0,90);$ops[]=['id'=>$opId,'method'=>strtoupper($method),'path'=>$path,'summary'=>mb_substr((string)($op['summary']??$op['description']??$opId),0,120),'fields'=>$fields,'body_fields'=>$bodyFields];if(count($ops)>=24)break 2;
        }
      }
      if(!$ops)spa_json(['ok'=>false,'error'=>'В OpenAPI нет поддерживаемых операций GET/POST/PUT/PATCH/DELETE'],400);
      $securitySchemes=$spec['components']['securitySchemes']??$spec['securityDefinitions']??[];$authCfg=['type'=>'bearer','name'=>'Authorization'];foreach($securitySchemes as $scheme){if(!is_array($scheme))continue;if(($scheme['type']??'')==='http'&&strtolower((string)($scheme['scheme']??''))==='bearer'){$authCfg=['type'=>'bearer','name'=>'Authorization'];break;}if(($scheme['type']??'')==='apiKey'&&in_array(($scheme['in']??''),['header','query'],true)){$authCfg=['type'=>$scheme['in'],'name'=>preg_replace('/[^a-zA-Z0-9_.-]/','',(string)($scheme['name']??'X-API-Key'))];break;}}$manifest=['kind'=>'api','title'=>$name,'subtitle'=>$desc,'api'=>['auth'=>$authCfg,'operations'=>$ops]];$serviceType='api';
    } elseif($rawManifest!==''){
      $decoded=json_decode($rawManifest,true);
      if(!is_array($decoded)||json_last_error()!==JSON_ERROR_NONE)spa_json(['ok'=>false,'error'=>'Макет должен быть корректным JSON'],400);
      $title=trim((string)($decoded['title']??$name));$subtitle=trim((string)($decoded['subtitle']??$desc));$sections=$decoded['sections']??[];
      if(!is_array($sections)||count($sections)>8)spa_json(['ok'=>false,'error'=>'В макете допускается не более 8 разделов'],400);
      $cleanSections=[];foreach($sections as $sec){if(!is_array($sec))continue;$stitle=mb_substr(trim((string)($sec['title']??'')),0,80);$fields=$sec['fields']??[];if($stitle===''||!is_array($fields)||count($fields)>12)continue;$cleanFields=[];foreach($fields as $f){if(!is_array($f))continue;$key=preg_replace('/[^a-zA-Z0-9_]/','',(string)($f['key']??''));$label=mb_substr(trim((string)($f['label']??'')),0,80);$type=(string)($f['type']??'text');if($key===''||$label===''||!in_array($type,['text','textarea','number','url','select'],true))continue;$field=['key'=>$key,'label'=>$label,'type'=>$type];if($type==='select'){$opts=array_slice(array_values(array_filter(array_map(fn($v)=>mb_substr(trim((string)$v),0,60),is_array($f['options']??null)?$f['options']:[]))),0,12);if(!$opts)continue;$field['options']=$opts;}$cleanFields[]=$field;}$cleanSections[]=['title'=>$stitle,'fields'=>$cleanFields];}
      $manifest=['title'=>mb_substr($title,0,80),'subtitle'=>mb_substr($subtitle,0,180),'sections'=>$cleanSections];
    }
    $manifestJson=$manifest?json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;if($manifest && $serviceType==='link')$serviceType='manifest';
    $pdo=db();$pdo->beginTransaction();try{
      $pdo->prepare('INSERT IGNORE INTO spa_server_app_quota_locks(server_id,user_id) VALUES(?,?)')->execute([(int)$srv['id'],(int)$user['id']]);
      $quotaLock=$pdo->prepare('SELECT user_id FROM spa_server_app_quota_locks WHERE server_id=? AND user_id=? FOR UPDATE');$quotaLock->execute([(int)$srv['id'],(int)$user['id']]);$quotaLock->fetchColumn();
      $lock=$pdo->prepare('SELECT COUNT(*) FROM spa_server_app_creation_log WHERE server_id=? AND user_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)');$lock->execute([(int)$srv['id'],(int)$user['id']]);$used=(int)$lock->fetchColumn();
      if($used>=10){$pdo->rollBack();spa_json(['ok'=>false,'error'=>'Лимит создания сервисов исчерпан: 10 за 30 дней. Удаление сервиса не восстанавливает попытку.','code'=>'creation_limit','used'=>$used,'limit'=>10],429);}
      $pdo->prepare('INSERT INTO spa_server_apps (server_id,name,base_url,description,icon,display_mode,created_by,service_type,manifest_json,is_published,platform) VALUES (?,?,?,?,?,?,?,?,?,0,?)')->execute([(int)$srv['id'],$name,$url,$desc,$icon,$mode,(int)$user['id'],$serviceType,$manifestJson,$platform]);$appId=(int)$pdo->lastInsertId();
      $pdo->prepare('INSERT INTO spa_server_app_creation_log(server_id,user_id,app_id) VALUES(?,?,?)')->execute([(int)$srv['id'],(int)$user['id'],$appId]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();spa_json(['ok'=>false,'error'=>'Не удалось создать сервис'],500);}
    spa_server_audit((int)$srv['id'],(int)$user['id'],'app.created',$name);spa_json(['ok'=>true,'id'=>$appId,'service_type'=>$serviceType,'manifest'=>$manifest,'published'=>false,'platform'=>$platform,'remaining'=>max(0,9-$used)]);
  }
  if ($action === 'server_app_delete' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_user_server((int)$user['id']);if(!$srv||!spa_server_can((int)$srv['id'],(int)$user['id'],'manage_apps'))spa_json(['ok'=>false,'error'=>'Нет права'],403);
    $id=(int)($_POST['id']??0);db()->prepare('DELETE FROM spa_server_app_installs WHERE app_id=? AND server_id=?')->execute([$id,(int)$srv['id']]);db()->prepare('DELETE FROM spa_server_apps WHERE id=? AND server_id=?')->execute([$id,(int)$srv['id']]);spa_server_audit((int)$srv['id'],(int)$user['id'],'app.deleted','app_id='.$id);spa_json(['ok'=>true]);
  }
  if ($action === 'server_webhooks_list') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_user_server((int)$user['id']);if(!$srv)spa_json(['ok'=>false,'error'=>'Не в сервере'],403);
    $st=db()->prepare('SELECT id,name,endpoint_url,events_json,active,created_at FROM spa_server_webhooks WHERE server_id=? ORDER BY id DESC');$st->execute([(int)$srv['id']]);$items=[];foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){$r['events']=json_decode((string)$r['events_json'],true)?:[];unset($r['events_json']);$items[]=$r;}spa_json(['ok'=>true,'webhooks'=>$items]);
  }
  if ($action === 'server_webhook_save' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_user_server((int)$user['id']);if(!$srv||!spa_server_can((int)$srv['id'],(int)$user['id'],'manage_webhooks'))spa_json(['ok'=>false,'error'=>'Нет права управлять Webhooks'],403);
    $name=mb_substr(trim((string)($_POST['name']??'')),0,80);$url=trim((string)($_POST['url']??''));$events=json_decode((string)($_POST['events']??'[]'),true);if(!is_array($events))$events=[];$allowed=['message.created','member.joined','member.left','app.created'];$events=array_values(array_intersect($allowed,$events));
    if($name===''||!filter_var($url,FILTER_VALIDATE_URL)||!preg_match('#^https://#i',$url)||!$events)spa_json(['ok'=>false,'error'=>'Укажи название, HTTPS endpoint и хотя бы одно событие'],400);
    db()->prepare('INSERT INTO spa_server_webhooks (server_id,name,endpoint_url,events_json,active,created_by) VALUES (?,?,?,?,0,?)')->execute([(int)$srv['id'],$name,$url,json_encode($events), (int)$user['id']]);spa_json(['ok'=>true,'id'=>(int)db()->lastInsertId(),'active'=>false,'note'=>'Webhook сохранён как черновик. Доставка событий включается после проверки endpoint и подписи в следующем этапе.']);
  }

  // Server Hub: welcome, badges, collections, statistics, tickets, polls, events and audit.
  if ($action === 'server_welcome_get') {
    if(!$user) spa_json(['ok'=>false],401); $srv=spa_server_require();
    $st=db()->prepare('SELECT enabled,message,rules_url FROM spa_server_welcome WHERE server_id=?');$st->execute([(int)$srv['id']]);$cfg=$st->fetch(PDO::FETCH_ASSOC) ?: ['enabled'=>1,'message'=>'Добро пожаловать, {user}!','rules_url'=>''];
    spa_json(['ok'=>true,'config'=>$cfg,'can_manage'=>spa_server_can((int)$srv['id'],(int)$user['id'],'manage_welcome')]);
  }
  if ($action === 'server_welcome_save' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user) spa_json(['ok'=>false],401);$srv=spa_server_require('manage_welcome');$msg=mb_substr(trim((string)($_POST['message']??'')),0,500);$url=trim((string)($_POST['rules_url']??''));if($url!==''&&(!filter_var($url,FILTER_VALIDATE_URL)||!preg_match('#^https://#i',$url)))spa_json(['ok'=>false,'error'=>'Правила должны иметь HTTPS-ссылку'],400);if($msg==='')$msg='Добро пожаловать, {user}!';$en=!empty($_POST['enabled'])?1:0;
    db()->prepare('INSERT INTO spa_server_welcome(server_id,enabled,message,rules_url) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),message=VALUES(message),rules_url=VALUES(rules_url)')->execute([(int)$srv['id'],$en,$msg,$url]);spa_server_audit((int)$srv['id'],(int)$user['id'],'welcome.updated','Обновлены настройки приветствия');spa_json(['ok'=>true]);
  }
  if ($action === 'server_badges_list') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_server_require();$sid=(int)$srv['id'];
    $st=db()->prepare('SELECT b.id,b.code,b.title,b.description,b.icon,ub.user_id,ub.awarded_at,u.username FROM spa_server_badges b LEFT JOIN spa_server_user_badges ub ON ub.badge_id=b.id AND ub.server_id=b.server_id LEFT JOIN users u ON u.id=ub.user_id WHERE b.server_id=? ORDER BY b.id DESC LIMIT 100');$st->execute([$sid]);spa_json(['ok'=>true,'items'=>$st->fetchAll(PDO::FETCH_ASSOC),'can_manage'=>spa_server_can($sid,(int)$user['id'],'manage_badges')]);
  }
  if ($action === 'server_badge_create' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_server_require('manage_badges');$sid=(int)$srv['id'];$title=mb_substr(trim((string)($_POST['title']??'')),0,80);$desc=mb_substr(trim((string)($_POST['description']??'')),0,255);$icon=mb_substr(trim((string)($_POST['icon']??'🏅')),0,16);$code=preg_replace('/[^a-z0-9_-]/i','',strtolower((string)($_POST['code']??'')));if($title===''||$code==='')spa_json(['ok'=>false,'error'=>'Нужны название и код значка'],400);try{db()->prepare('INSERT INTO spa_server_badges(server_id,code,title,description,icon) VALUES(?,?,?,?,?)')->execute([$sid,$code,$title,$desc,$icon]);}catch(Throwable $e){spa_json(['ok'=>false,'error'=>'Такой код значка уже существует'],400);}spa_server_audit($sid,(int)$user['id'],'badge.created',$title);spa_json(['ok'=>true]);
  }
  if ($action === 'server_badge_default_get') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_server_require();$q=db()->prepare('SELECT default_badge_id FROM spa_servers WHERE id=?');$q->execute([(int)$srv['id']]);spa_json(['ok'=>true,'badge_id'=>(int)($q->fetchColumn()?:0)]);
  }
  if ($action === 'server_badge_default_set' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_server_require('manage_badges');$sid=(int)$srv['id'];$bid=(int)($_POST['badge_id']??0);
    if($bid>0){$q=db()->prepare('SELECT 1 FROM spa_server_badges WHERE id=? AND server_id=?');$q->execute([$bid,$sid]);if(!$q->fetchColumn())spa_json(['ok'=>false,'error'=>'Значок не найден'],404);}
    db()->prepare('UPDATE spa_servers SET default_badge_id=? WHERE id=?')->execute([$bid,$sid]);spa_server_audit($sid,(int)$user['id'],'badge.default_changed',$bid?'Установлен значок по умолчанию #'.$bid:'Значок по умолчанию отключён');spa_json(['ok'=>true,'badge_id'=>$bid]);
  }
  if ($action === 'server_badge_award' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_server_require('manage_badges');$sid=(int)$srv['id'];$uid=(int)($_POST['user_id']??0);$bid=(int)($_POST['badge_id']??0);$m=db()->prepare('SELECT 1 FROM spa_server_members WHERE server_id=? AND user_id=?');$m->execute([$sid,$uid]);if(!$m->fetchColumn())spa_json(['ok'=>false,'error'=>'Участник не найден'],404);$b=db()->prepare('SELECT title FROM spa_server_badges WHERE id=? AND server_id=?');$b->execute([$bid,$sid]);$title=$b->fetchColumn();if(!$title)spa_json(['ok'=>false,'error'=>'Значок не найден'],404);db()->prepare('INSERT IGNORE INTO spa_server_user_badges(server_id,user_id,badge_id,awarded_by) VALUES(?,?,?,?)')->execute([$sid,$uid,$bid,(int)$user['id']]);spa_server_audit($sid,(int)$user['id'],'badge.awarded','Выдан значок «'.$title.'» участнику #'.$uid);spa_json(['ok'=>true]);
  }
  if ($action === 'server_collections_list') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_server_require();$st=db()->prepare('SELECT c.id,c.user_id,c.title,c.description,c.items_json,c.is_shared,c.created_at,u.username FROM spa_server_collections c JOIN users u ON u.id=c.user_id WHERE c.server_id=? AND (c.is_shared=1 OR c.user_id=?) ORDER BY c.id DESC LIMIT 50');$st->execute([(int)$srv['id'],(int)$user['id']]);$items=$st->fetchAll(PDO::FETCH_ASSOC);foreach($items as &$it){$it['items']=json_decode((string)$it['items_json'],true)?:[];unset($it['items_json']);}unset($it);spa_json(['ok'=>true,'items'=>$items]);
  }
  if ($action === 'server_collection_save' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_server_require();$title=mb_substr(trim((string)($_POST['title']??'')),0,100);$desc=mb_substr(trim((string)($_POST['description']??'')),0,255);$raw=json_decode((string)($_POST['items']??'[]'),true);if($title===''||!is_array($raw)||count($raw)>40)spa_json(['ok'=>false,'error'=>'Проверь название и список элементов (до 40)'],400);$items=[];foreach($raw as $it){if(!is_array($it))continue;$url=trim((string)($it['url']??''));$name=mb_substr(trim((string)($it['title']??'')),0,120);if($name!==''&&filter_var($url,FILTER_VALIDATE_URL)&&preg_match('#^https?://#i',$url))$items[]=['title'=>$name,'url'=>$url];}$json=json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);db()->prepare('INSERT INTO spa_server_collections(server_id,user_id,title,description,items_json,is_shared) VALUES(?,?,?,?,?,?)')->execute([(int)$srv['id'],(int)$user['id'],$title,$desc,$json,!empty($_POST['is_shared'])?1:0]);spa_server_audit((int)$srv['id'],(int)$user['id'],'collection.created',$title);spa_json(['ok'=>true,'id'=>(int)db()->lastInsertId()]);
  }
  if ($action === 'server_stats') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_server_require();$sid=(int)$srv['id'];$q=db()->prepare('SELECT COUNT(*) members, SUM(joined_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)) joined_7d FROM spa_server_members WHERE server_id=?');$q->execute([$sid]);$m=$q->fetch(PDO::FETCH_ASSOC)?:[];$q=db()->prepare('SELECT COUNT(*) messages_7d FROM spa_server_messages WHERE server_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)');$q->execute([$sid]);$messages=(int)$q->fetchColumn();$q=db()->prepare('SELECT xp,ach_count,updated_at FROM spa_server_score WHERE server_id=?');$q->execute([$sid]);$score=$q->fetch(PDO::FETCH_ASSOC)?:['xp'=>0,'ach_count'=>0];$q=db()->prepare('SELECT COUNT(*) FROM spa_server_events WHERE server_id=? AND starts_at>=NOW()');$q->execute([$sid]);$events=(int)$q->fetchColumn();spa_json(['ok'=>true,'stats'=>['members'=>(int)($m['members']??0),'joined_7d'=>(int)($m['joined_7d']??0),'messages_7d'=>$messages,'xp'=>(int)$score['xp'],'achievements'=>(int)$score['ach_count'],'upcoming_events'=>$events]]);
  }
  if ($action === 'server_tickets_list') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_server_require();$staff=spa_server_can((int)$srv['id'],(int)$user['id'],'manage_tickets');$sql='SELECT t.id,t.user_id,t.subject,t.body,t.status,t.staff_reply,t.created_at,t.updated_at,u.username FROM spa_server_tickets t JOIN users u ON u.id=t.user_id WHERE t.server_id=?'.($staff?'':' AND t.user_id=?').' ORDER BY t.id DESC LIMIT 50';$st=db()->prepare($sql);$staff?$st->execute([(int)$srv['id']]):$st->execute([(int)$srv['id'],(int)$user['id']]);spa_json(['ok'=>true,'items'=>$st->fetchAll(PDO::FETCH_ASSOC),'can_manage'=>$staff]);
  }
  if ($action === 'server_ticket_create' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_server_require();$subject=mb_substr(trim((string)($_POST['subject']??'')),0,120);$body=trim((string)($_POST['body']??''));if($subject===''||$body===''||mb_strlen($body)>4000)spa_json(['ok'=>false,'error'=>'Заполни тему и текст (до 4000 символов)'],400);db()->prepare('INSERT INTO spa_server_tickets(server_id,user_id,subject,body) VALUES(?,?,?,?)')->execute([(int)$srv['id'],(int)$user['id'],$subject,$body]);spa_server_audit((int)$srv['id'],(int)$user['id'],'ticket.created',$subject);spa_json(['ok'=>true,'id'=>(int)db()->lastInsertId()]);
  }
  if ($action === 'server_ticket_update' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_server_require('manage_tickets');$id=(int)($_POST['id']??0);$status=in_array((string)($_POST['status']??''),['open','in_progress','closed'],true)?(string)$_POST['status']:'open';$reply=mb_substr(trim((string)($_POST['reply']??'')),0,4000);$st=db()->prepare('UPDATE spa_server_tickets SET status=?,staff_reply=? WHERE id=? AND server_id=?');$st->execute([$status,$reply,$id,(int)$srv['id']]);spa_server_audit((int)$srv['id'],(int)$user['id'],'ticket.updated','Заявка #'.$id.' · '.$status);spa_json(['ok'=>true]);
  }
  if ($action === 'server_polls_list') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_server_require();$sid=(int)$srv['id'];$st=db()->prepare('SELECT p.id,p.user_id,p.question,p.options_json,p.ends_at,p.created_at,u.username,(SELECT option_index FROM spa_server_poll_votes v WHERE v.poll_id=p.id AND v.user_id=?) my_vote FROM spa_server_polls p JOIN users u ON u.id=p.user_id WHERE p.server_id=? ORDER BY p.id DESC LIMIT 30');$st->execute([(int)$user['id'],$sid]);$items=$st->fetchAll(PDO::FETCH_ASSOC);foreach($items as &$it){$it['options']=json_decode((string)$it['options_json'],true)?:[];unset($it['options_json']);$q=db()->prepare('SELECT option_index,COUNT(*) votes FROM spa_server_poll_votes WHERE poll_id=? GROUP BY option_index');$q->execute([(int)$it['id']]);$it['counts']=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $c)$it['counts'][(int)$c['option_index']]=(int)$c['votes'];}unset($it);spa_json(['ok'=>true,'items'=>$items,'can_manage'=>spa_server_can($sid,(int)$user['id'],'manage_polls')]);
  }
  if ($action === 'server_poll_create' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_server_require('manage_polls');$question=mb_substr(trim((string)($_POST['question']??'')),0,255);$opts=array_values(array_filter(array_map(fn($x)=>mb_substr(trim((string)$x),0,100),json_decode((string)($_POST['options']??'[]'),true)?:[])));if($question===''||count($opts)<2||count($opts)>8)spa_json(['ok'=>false,'error'=>'Нужно 2–8 вариантов ответа'],400);$ends=trim((string)($_POST['ends_at']??''));$ends=$ends!==''?date('Y-m-d H:i:s',strtotime($ends)):null;db()->prepare('INSERT INTO spa_server_polls(server_id,user_id,question,options_json,ends_at) VALUES(?,?,?,?,?)')->execute([(int)$srv['id'],(int)$user['id'],$question,json_encode($opts,JSON_UNESCAPED_UNICODE),$ends]);spa_server_audit((int)$srv['id'],(int)$user['id'],'poll.created',$question);spa_json(['ok'=>true]);
  }
  if ($action === 'server_poll_vote' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_server_require();$id=(int)($_POST['id']??0);$idx=(int)($_POST['option_index']??-1);$q=db()->prepare('SELECT options_json,ends_at FROM spa_server_polls WHERE id=? AND server_id=?');$q->execute([$id,(int)$srv['id']]);$poll=$q->fetch(PDO::FETCH_ASSOC);$opts=$poll?json_decode((string)$poll['options_json'],true):[];if(!$poll||$idx<0||$idx>=count($opts)||($poll['ends_at']&&strtotime($poll['ends_at'])<time()))spa_json(['ok'=>false,'error'=>'Голосование завершено или вариант неверен'],400);db()->prepare('INSERT INTO spa_server_poll_votes(poll_id,server_id,user_id,option_index) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE option_index=VALUES(option_index),created_at=NOW()')->execute([$id,(int)$srv['id'],(int)$user['id'],$idx]);spa_server_audit((int)$srv['id'],(int)$user['id'],'poll.voted','Голосование #'.$id);spa_json(['ok'=>true]);
  }
  if ($action === 'server_events_list') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_server_require();$st=db()->prepare('SELECT e.id,e.user_id,e.title,e.description,e.starts_at,e.ends_at,e.location,u.username,(SELECT COUNT(*) FROM spa_server_event_rsvps r WHERE r.event_id=e.id AND r.status="going") attendees,(SELECT status FROM spa_server_event_rsvps r WHERE r.event_id=e.id AND r.user_id=?) my_status FROM spa_server_events e JOIN users u ON u.id=e.user_id WHERE e.server_id=? ORDER BY e.starts_at DESC LIMIT 50');$st->execute([(int)$user['id'],(int)$srv['id']]);spa_json(['ok'=>true,'items'=>$st->fetchAll(PDO::FETCH_ASSOC),'can_manage'=>spa_server_can((int)$srv['id'],(int)$user['id'],'manage_events')]);
  }
  if ($action === 'server_event_create' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_server_require('manage_events');$title=mb_substr(trim((string)($_POST['title']??'')),0,120);$desc=mb_substr(trim((string)($_POST['description']??'')),0,3000);$starts=strtotime((string)($_POST['starts_at']??''));$ends=strtotime((string)($_POST['ends_at']??''));$loc=mb_substr(trim((string)($_POST['location']??'')),0,180);if($title===''||!$starts||$starts<time()-60)spa_json(['ok'=>false,'error'=>'Укажи название и будущую дату события'],400);db()->prepare('INSERT INTO spa_server_events(server_id,user_id,title,description,starts_at,ends_at,location) VALUES(?,?,?,?,?,?,?)')->execute([(int)$srv['id'],(int)$user['id'],$title,$desc,date('Y-m-d H:i:s',$starts),$ends?date('Y-m-d H:i:s',$ends):null,$loc]);spa_server_audit((int)$srv['id'],(int)$user['id'],'event.created',$title);spa_json(['ok'=>true]);
  }
  if ($action === 'server_event_rsvp' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_server_require();$id=(int)($_POST['id']??0);$status=in_array((string)($_POST['status']??''),['going','maybe','declined'],true)?(string)$_POST['status']:'going';$q=db()->prepare('SELECT 1 FROM spa_server_events WHERE id=? AND server_id=?');$q->execute([$id,(int)$srv['id']]);if(!$q->fetchColumn())spa_json(['ok'=>false,'error'=>'Событие не найдено'],404);db()->prepare('INSERT INTO spa_server_event_rsvps(event_id,server_id,user_id,status) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)')->execute([$id,(int)$srv['id'],(int)$user['id'],$status]);spa_server_audit((int)$srv['id'],(int)$user['id'],'event.rsvp','Событие #'.$id.' · '.$status);spa_json(['ok'=>true]);
  }
  if ($action === 'server_audit_list') {
    if(!$user)spa_json(['ok'=>false],401);$srv=spa_server_require('view_audit');$st=db()->prepare('SELECT a.id,a.actor_id,a.action,a.details,a.created_at,u.username FROM spa_server_audit a LEFT JOIN users u ON u.id=a.actor_id WHERE a.server_id=? ORDER BY a.id DESC LIMIT 100');$st->execute([(int)$srv['id']]);spa_json(['ok'=>true,'items'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
  }

  if ($action === 'server_mine') {
    if (!$user) spa_json(['ok' => false], 401);
    spa_server_prune_inactive();
    spa_server_touch((int)$user['id']);
    $srv = spa_user_server((int)$user['id']);
    if (!$srv) spa_json(['ok' => true, 'server' => null, 'quota' => spa_daily_quota((int)$user['id'])]);
    $cnt = spa_server_member_count((int)$srv['id']);
    spa_json(['ok' => true, 'server' => [
      'id' => (int)$srv['id'], 'name' => (string)$srv['name'], 'invite_code' => (string)$srv['invite_code'],
      'description' => (string)($srv['description'] ?? ''), 'role' => (string)$srv['role'],
      'owner_id' => (int)$srv['owner_id'], 'members' => $cnt,
      'boost_units' => spa_server_boost_units((int)$user['id']),
      'storage_gb' => (int)(spa_storage_quota_bytes((int)$user['id']) / 1073741824),
      'has_sub' => spa_has_sub((int)$user['id']), 'promo_x' => spa_promo_multiplier(),
      'inactive_kick_days' => (int)($srv['inactive_kick_days'] ?? 0),
      'permissions' => ['manage_chat'=>spa_server_can((int)$srv['id'],(int)$user['id'],'manage_chat'),'kick_members'=>spa_server_can((int)$srv['id'],(int)$user['id'],'kick_members'),'manage_apps'=>spa_server_can((int)$srv['id'],(int)$user['id'],'manage_apps'),'manage_webhooks'=>spa_server_can((int)$srv['id'],(int)$user['id'],'manage_webhooks'),'manage_roles'=>spa_server_can((int)$srv['id'],(int)$user['id'],'manage_roles'),'send_announcements'=>spa_server_can((int)$srv['id'],(int)$user['id'],'send_announcements'),'manage_welcome'=>spa_server_can((int)$srv['id'],(int)$user['id'],'manage_welcome'),'manage_badges'=>spa_server_can((int)$srv['id'],(int)$user['id'],'manage_badges'),'manage_tickets'=>spa_server_can((int)$srv['id'],(int)$user['id'],'manage_tickets'),'manage_polls'=>spa_server_can((int)$srv['id'],(int)$user['id'],'manage_polls'),'manage_events'=>spa_server_can((int)$srv['id'],(int)$user['id'],'manage_events'),'view_audit'=>spa_server_can((int)$srv['id'],(int)$user['id'],'view_audit')],
    ], 'quota' => spa_daily_quota((int)$user['id'])]);
  }

  if ($action === 'server_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok' => false], 401);
    if (spa_user_server((int)$user['id'])) spa_json(['ok' => false, 'error' => 'Ты уже в сервере. Можно только в одном.'], 400);
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 64);
    $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255);
    if ($name === '') spa_json(['ok' => false, 'error' => 'Укажи название'], 400);
    $code = spa_server_code();
    for ($i = 0; $i < 5; $i++) {
      try {
        db()->prepare('INSERT INTO spa_servers (owner_id, name, invite_code, description) VALUES (?,?,?,?)')
          ->execute([(int)$user['id'], $name, $code, $desc]);
        break;
      } catch (Throwable $e) { $code = spa_server_code(); }
    }
    $sid = (int)db()->lastInsertId();
    db()->prepare('INSERT INTO spa_server_members (server_id, user_id, role, last_active) VALUES (?,?,?,NOW())')
      ->execute([$sid, (int)$user['id'], 'owner']);
    try { db()->prepare("INSERT IGNORE INTO spa_server_welcome(server_id,enabled,message,rules_url) VALUES(?,1,?,'')")->execute([$sid,'Добро пожаловать, {user}!']); } catch(Throwable $e) {}
    spa_server_sys_msg($sid, (int)$user['id'], (string)($user['username'] ?? '') . ' создал(а) сервер');
    spa_server_audit($sid,(int)$user['id'],'server.created',$name);
    spa_json(['ok' => true, 'server' => ['id' => $sid, 'name' => $name, 'invite_code' => $code, 'members' => 1]]);
  }


  if ($action === 'server_kick' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok' => false], 401);
    $srv = spa_user_server((int)$user['id']);
    if (!$srv || (string)($srv['role'] ?? '') !== 'owner') {
      spa_json(['ok' => false, 'error' => 'Только владелец может кикать'], 403);
    }
    $target = (int)($_POST['user_id'] ?? 0);
    if ($target <= 0 || $target === (int)$user['id']) spa_json(['ok' => false, 'error' => 'Нельзя'], 400);
    $sid = (int)$srv['id'];
    // удалить сообщения кикнутого (как TG)
    try {
      db()->prepare('DELETE FROM spa_server_messages WHERE server_id = ? AND user_id = ?')->execute([$sid, $target]);
    } catch (Throwable $e) {}
    try {
      db()->prepare('DELETE FROM spa_server_members WHERE server_id = ? AND user_id = ?')->execute([$sid, $target]);
    } catch (Throwable $e) {
      spa_json(['ok' => false, 'error' => 'Не удалось кикнуть'], 500);
    }
    $uname = '';
    try {
      $st = db()->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
      $st->execute([$target]);
      $uname = (string)$st->fetchColumn();
    } catch (Throwable $e) {}
    spa_server_sys_msg($sid, (int)$user['id'], 'Кикнут(а): @' . ($uname !== '' ? $uname : ('id'.$target)));
    spa_server_audit($sid,(int)$user['id'],'member.kicked',$uname);
    spa_json(['ok' => true]);
  }

  if ($action === 'server_join' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok' => false], 401);
    if (spa_user_server((int)$user['id'])) spa_json(['ok' => false, 'error' => 'Уже в сервере. Сначала выйди.'], 400);
    $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    $code = preg_replace('/[^A-Z0-9]/', '', $code);
    if (strlen($code) < 6) spa_json(['ok' => false, 'error' => 'Неверный код'], 400);
    $st = db()->prepare('SELECT * FROM spa_servers WHERE invite_code = ? LIMIT 1');
    $st->execute([$code]);
    $srv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$srv) spa_json(['ok' => false, 'error' => 'Сервер не найден'], 404);
    db()->prepare('INSERT INTO spa_server_members (server_id, user_id, role, last_active) VALUES (?,?,?,NOW())')
      ->execute([(int)$srv['id'], (int)$user['id'], 'member']);
    $uname = (string)($user['username'] ?? '');
    spa_server_sys_msg((int)$srv['id'], (int)$user['id'], $uname . ' вступил(а) в сервер');
    spa_server_audit((int)$srv['id'],(int)$user['id'],'member.joined',$uname);
    try { $w=db()->prepare('SELECT enabled,message,rules_url FROM spa_server_welcome WHERE server_id=?');$w->execute([(int)$srv['id']]);$wc=$w->fetch(PDO::FETCH_ASSOC) ?: ['enabled'=>1,'message'=>'Добро пожаловать, {user}!','rules_url'=>''];if(!empty($wc['enabled'])){$welcome=str_replace('{user}','@'.$uname,(string)$wc['message']);if(!empty($wc['rules_url']))$welcome.=' · Правила: '.$wc['rules_url'];spa_server_sys_msg((int)$srv['id'],(int)$user['id'],$welcome);}} catch(Throwable $e) {}
    spa_server_prune_inactive((int)$srv['id']);
    spa_json(['ok' => true, 'server' => [
      'id' => (int)$srv['id'], 'name' => (string)$srv['name'], 'invite_code' => (string)$srv['invite_code'],
      'members' => spa_server_member_count((int)$srv['id']),
      'quota' => spa_daily_quota((int)$user['id']),
    ]]);
  }

  if ($action === 'server_leave' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok' => false], 401);
    $srv = spa_user_server((int)$user['id']);
    if (!$srv) spa_json(['ok' => false, 'error' => 'Ты не в сервере'], 400);
    $sid = (int)$srv['id'];
    $uid = (int)$user['id'];
    if ((int)$srv['owner_id'] === $uid) {
      // owner leave = delete server if alone, else transfer not implemented — block if others
      $cnt = spa_server_member_count($sid);
      if ($cnt > 1) spa_json(['ok' => false, 'error' => 'Владелец: сначала пусть выйдут все участники, или передай сервер позже'], 400);
      db()->prepare('DELETE FROM spa_server_messages WHERE server_id=?')->execute([$sid]);
      db()->prepare('DELETE FROM spa_server_members WHERE server_id=?')->execute([$sid]);
      db()->prepare('DELETE FROM spa_servers WHERE id=?')->execute([$sid]);
    } else {
      $uname = (string)($user['username'] ?? '');
      spa_server_audit($sid,$uid,'member.left',$uname);
      db()->prepare('DELETE FROM spa_server_members WHERE server_id=? AND user_id=?')->execute([$sid, $uid]);
      spa_server_sys_msg($sid, $uid, $uname . ' покинул(а) сервер');
    }
    spa_json(['ok' => true]);
  }

  if ($action === 'server_get') {
    if (!$user) spa_json(['ok' => false], 401);
    $id = (int)($_GET['id'] ?? 0);
    $st = db()->prepare('SELECT * FROM spa_servers WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $srv = $st->fetch(PDO::FETCH_ASSOC);
    if (!$srv) spa_json(['ok' => false, 'error' => 'Нет'], 404);
    $mine = spa_user_server((int)$user['id']);
    $isMember = $mine && (int)$mine['id'] === (int)$srv['id'];
    $members = [];
    if ($isMember) {
      // Include the assigned custom role name so it can be shown to every member.
      // The built-in member role (owner/member) remains unchanged.
      $st2 = db()->prepare('SELECT m.role, m.joined_at, u.id, u.username, u.avatar, cr.name AS custom_role_name
        FROM spa_server_members m
        JOIN users u ON u.id=m.user_id
        LEFT JOIN spa_server_member_roles mr ON mr.server_id=m.server_id AND mr.user_id=m.user_id
        LEFT JOIN spa_server_roles cr ON cr.id=mr.role_id AND cr.server_id=m.server_id
        WHERE m.server_id=? ORDER BY m.joined_at');
      $st2->execute([(int)$srv['id']]);
      $memberRows = $st2->fetchAll(PDO::FETCH_ASSOC);
      $badgeMap = [];
      try {
        $bst = db()->prepare('SELECT ub.user_id,b.icon,b.title,b.id FROM spa_server_user_badges ub JOIN spa_server_badges b ON b.id=ub.badge_id AND b.server_id=ub.server_id WHERE ub.server_id=? ORDER BY ub.awarded_at ASC');
        $bst->execute([(int)$srv['id']]);
        foreach ($bst->fetchAll(PDO::FETCH_ASSOC) as $br) $badgeMap[(int)$br['user_id']][] = ['id'=>(int)$br['id'],'icon'=>(string)$br['icon'],'title'=>(string)$br['title']];
      } catch (Throwable $e) { $badgeMap = []; }
      $defaultBadge = null;
      $defaultBadgeId = (int)($srv['default_badge_id'] ?? 0);
      if ($defaultBadgeId > 0) {
        try { $dbb=db()->prepare('SELECT id,icon,title FROM spa_server_badges WHERE id=? AND server_id=? LIMIT 1');$dbb->execute([$defaultBadgeId,(int)$srv['id']]);$dbrow=$dbb->fetch(PDO::FETCH_ASSOC);if($dbrow)$defaultBadge=['id'=>(int)$dbrow['id'],'icon'=>(string)$dbrow['icon'],'title'=>(string)$dbrow['title'],'is_default'=>true]; } catch(Throwable $e) {}
      }
      foreach ($memberRows as $r) {
        $memberId = (int)$r['id'];
        $ownBadges = $badgeMap[$memberId] ?? [];
        if (!$ownBadges && $defaultBadge) $ownBadges = [$defaultBadge];
        $members[] = ['user' => spa_user_public($r), 'role' => $r['role'], 'custom_role' => (string)($r['custom_role_name'] ?? ''), 'badges' => $ownBadges, 'joined_at' => $r['joined_at']];
      }
    }
    spa_json(['ok' => true, 'server' => [
      'id' => (int)$srv['id'], 'name' => (string)$srv['name'],
      'invite_code' => $isMember ? (string)$srv['invite_code'] : null,
      'description' => (string)($srv['description'] ?? ''),
      'owner_id' => (int)$srv['owner_id'],
      'members_count' => spa_server_member_count((int)$srv['id']),
      'is_member' => $isMember,
    ], 'members' => $members]);
  }

  if ($action === 'server_chat') {
    if (!$user) spa_json(['ok' => false], 401);
    spa_server_prune_inactive();
    spa_server_touch((int)$user['id']);
    $mine = spa_user_server((int)$user['id']);
    if (!$mine) spa_json(['ok' => false, 'error' => 'Не в сервере'], 403);
    $sid = (int)$mine['id'];
    try {
      $st = db()->prepare('SELECT m.id, m.user_id, m.body, m.created_at, m.is_system, u.username, u.avatar FROM spa_server_messages m LEFT JOIN users u ON u.id=m.user_id WHERE m.server_id=? ORDER BY m.id DESC LIMIT 100');
      $st->execute([$sid]);
    } catch (Throwable $e) {
      $st = db()->prepare('SELECT m.id, m.user_id, m.body, m.created_at, 0 AS is_system, u.username, u.avatar FROM spa_server_messages m LEFT JOIN users u ON u.id=m.user_id WHERE m.server_id=? ORDER BY m.id DESC LIMIT 100');
      $st->execute([$sid]);
    }
    $msgs = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    $chatBadgeMap = [];
    try {
      $chatBadgeStmt = db()->prepare('SELECT ub.user_id,b.icon,b.title FROM spa_server_user_badges ub JOIN spa_server_badges b ON b.id=ub.badge_id AND b.server_id=ub.server_id WHERE ub.server_id=? ORDER BY ub.awarded_at ASC');
      $chatBadgeStmt->execute([$sid]);
      foreach ($chatBadgeStmt->fetchAll(PDO::FETCH_ASSOC) as $br) $chatBadgeMap[(int)$br['user_id']][] = ['icon'=>(string)$br['icon'],'title'=>(string)$br['title']];
    } catch (Throwable $e) { $chatBadgeMap = []; }
    $chatDefaultBadge = null;
    try { $qdb=db()->prepare('SELECT b.icon,b.title FROM spa_servers s JOIN spa_server_badges b ON b.id=s.default_badge_id AND b.server_id=s.id WHERE s.id=? LIMIT 1');$qdb->execute([$sid]);$dr=$qdb->fetch(PDO::FETCH_ASSOC);if($dr)$chatDefaultBadge=['icon'=>(string)$dr['icon'],'title'=>(string)$dr['title'],'is_default'=>true]; } catch(Throwable $e) {}
    foreach ($msgs as &$msg) {
      $msg['is_system'] = !empty($msg['is_system']);
      $msg['badges'] = $chatBadgeMap[(int)($msg['user_id'] ?? 0)] ?? [];
      if (!$msg['badges'] && $chatDefaultBadge) $msg['badges'] = [$chatDefaultBadge];
      unset($msg['user_id']);
    }
    unset($msg);
    spa_json(['ok' => true, 'messages' => $msgs, 'server_id' => $sid]);
  }

  if ($action === 'server_chat_send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok' => false], 401);
    $mine = spa_user_server((int)$user['id']);
    if (!$mine) spa_json(['ok' => false, 'error' => 'Не в сервере'], 403);
    $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 500);
    if ($body === '') spa_json(['ok' => false, 'error' => 'Пусто'], 400);
    db()->prepare('INSERT INTO spa_server_messages (server_id, user_id, body) VALUES (?,?,?)')
      ->execute([(int)$mine['id'], (int)$user['id'], $body]);
    spa_server_audit((int)$mine['id'],(int)$user['id'],'message.created',mb_substr($body,0,160));
    spa_json(['ok' => true]);
  }


  if ($action === 'stickers_list') {
    if (!$user) spa_json(['ok' => false], 401);
    $uid = (int)$user['id'];
    $packs = [];
    try {
      // свои + подписки
      $st = db()->prepare(
        "SELECT DISTINCT sp.id, sp.title FROM sticker_packs sp
         LEFT JOIN sticker_pack_subscriptions sub ON sub.pack_id = sp.id AND sub.user_id = ?
         WHERE sp.owner_id = ? OR sub.user_id IS NOT NULL
         ORDER BY sp.id DESC LIMIT 30"
      );
      $st->execute([$uid, $uid]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      // fallback: любые паки
      if (!$rows) {
        $st = db()->query('SELECT id, title FROM sticker_packs ORDER BY id DESC LIMIT 20');
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      }
      foreach ($rows as $row) {
        $pid = (int)$row['id'];
        $items = [];
        try {
          $it = db()->prepare('SELECT code, image_url, image_url AS url FROM sticker_pack_items WHERE pack_id = ? ORDER BY id LIMIT 80');
          $it->execute([$pid]);
          foreach ($it->fetchAll(PDO::FETCH_ASSOC) as $im) {
            $code = (string)$im['code'];
            $url = (string)$im['image_url'];
            if ($code === '' || $url === '') continue;
            $items[] = ['code' => $code, 'url' => $url];
          }
        } catch (Throwable $e) {}
        if ($items) {
          $packs[] = ['id' => $pid, 'title' => (string)$row['title'], 'items' => $items];
        }
      }
    } catch (Throwable $e) {
      spa_json(['ok' => true, 'packs' => [], 'error' => $e->getMessage(), 'fallback' => true]);
    }
    spa_json(['ok' => true, 'packs' => $packs]);
  }

  if ($action === 'profile_level_status') {
    if (!$user) spa_json(['ok'=>false],401);
    $targetId=(int)$user['id'];
    $uname=trim((string)($_GET['username']??$_POST['username']??''));
    if($uname!==''){
      $st=db()->prepare('SELECT id FROM users WHERE username=? LIMIT 1');$st->execute([$uname]);$found=(int)$st->fetchColumn();
      if(!$found)spa_json(['ok'=>false,'error'=>'Пользователь не найден'],404);$targetId=$found;
    }
    $st=db()->prepare('SELECT COALESCE(SUM(points),0) xp,COUNT(*) awards FROM spa_profile_xp_awards WHERE user_id=?');$st->execute([$targetId]);$r=$st->fetch(PDO::FETCH_ASSOC)?:[];$xp=(int)($r['xp']??0);
    $levels=[0,100,250,500,850,1300,1900,2700,3700,5000,6600,8500,10800,13500,16600,20200];$level=1;for($i=1;$i<count($levels);$i++){if($xp >= $levels[$i])$level=$i+1;else break;}
    $base=$levels[min($level-1,count($levels)-1)];$next=$levels[min($level,count($levels)-1)]??$base;$progress=$next>$base?max(0,min(100,(int)round(($xp-$base)/($next-$base)*100))):100;
    $serverInfo=null;$memberInfo=null;
    $st=db()->prepare('SELECT m.server_id,s.name,(SELECT COUNT(*) FROM spa_server_members mm WHERE mm.server_id=m.server_id) members FROM spa_server_members m JOIN spa_servers s ON s.id=m.server_id WHERE m.user_id=? LIMIT 1');$st->execute([$targetId]);$sm=$st->fetch(PDO::FETCH_ASSOC);
    if($sm){$sid=(int)$sm['server_id'];$members=(int)$sm['members'];$thresholds=[1,5,10,20,35,50,75,100,150,200,300,500];$sl=1;for($i=1;$i<count($thresholds);$i++){if($members >= $thresholds[$i])$sl=$i+1;else break;}$sb=$thresholds[$sl-1]??1;$sn=$thresholds[$sl]??$sb;$sp=$sn>$sb?max(0,min(100,(int)round(($members-$sb)/($sn-$sb)*100))):100;
      $st=db()->prepare('SELECT COALESCE(SUM(points),0) xp FROM spa_server_xp_awards WHERE server_id=? AND user_id=?');$st->execute([$sid,$targetId]);$mxp=(int)$st->fetchColumn();$mlv=1;for($i=1;$i<count($levels);$i++){if($mxp >= $levels[$i])$mlv=$i+1;else break;}$mb=$levels[min($mlv-1,count($levels)-1)];$mn=$levels[min($mlv,count($levels)-1)]??$mb;$mp=$mn>$mb?max(0,min(100,(int)round(($mxp-$mb)/($mn-$mb)*100))):100;
      $serverInfo=['id'=>$sid,'name'=>(string)$sm['name'],'members'=>$members,'level'=>$sl,'progress'=>$sp,'next_members'=>$sn];$memberInfo=['level'=>$mlv,'xp'=>$mxp,'progress'=>$mp,'next_xp'=>$mn];}
    spa_json(['ok'=>true,'user_id'=>$targetId,'is_self'=>$targetId===(int)$user['id'],'xp'=>$xp,'level'=>$level,'next_xp'=>$next,'progress'=>$progress,'awards'=>(int)($r['awards']??0),'server'=>$serverInfo,'member_level'=>$memberInfo]);
  }

  if ($action === 'profile_xp_achievement' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user)spa_json(['ok'=>false],401);$id=strtolower(trim((string)($_POST['achievement']??'')));
    $uid=(int)$user['id'];$valid=false;
    try{
      if($id==='clan_join'){$st=db()->prepare('SELECT 1 FROM spa_server_members WHERE user_id=? LIMIT 1');$st->execute([$uid]);$valid=(bool)$st->fetchColumn();}
      elseif($id==='clan_owner'){$st=db()->prepare('SELECT 1 FROM spa_servers WHERE owner_id=? LIMIT 1');$st->execute([$uid]);$valid=(bool)$st->fetchColumn();}
      elseif($id==='clan_5'){$st=db()->prepare('SELECT COUNT(*) FROM spa_server_members m WHERE m.server_id=(SELECT server_id FROM spa_server_members WHERE user_id=? LIMIT 1)');$st->execute([$uid]);$valid=(int)$st->fetchColumn()>=5;}
      elseif($id==='fav_5'){$st=db()->prepare('SELECT COUNT(*) FROM spa_favorites WHERE user_id=?');$st->execute([$uid]);$valid=(int)$st->fetchColumn()>=5;}
      elseif($id==='server_poll'){$st=db()->prepare('SELECT 1 FROM spa_server_poll_votes WHERE user_id=? LIMIT 1');$st->execute([$uid]);$valid=(bool)$st->fetchColumn();}
      elseif($id==='server_event'){$st=db()->prepare('SELECT 1 FROM spa_server_events WHERE user_id=? LIMIT 1');$st->execute([$uid]);$valid=(bool)$st->fetchColumn();}
      elseif($id==='server_badge'){$st=db()->prepare('SELECT 1 FROM spa_server_user_badges WHERE user_id=? LIMIT 1');$st->execute([$uid]);$valid=(bool)$st->fetchColumn();}
      else spa_json(['ok'=>true,'points'=>0,'unverified'=>true]);
      if(!$valid)spa_json(['ok'=>false,'error'=>'Достижение ещё не подтверждено сервером'],409);
      $st=db()->prepare("INSERT INTO spa_profile_xp_awards(user_id,award_key,points,award_type) VALUES(?,?,8,'achievement')");$st->execute([$uid,'ach:'.$id]);spa_json(['ok'=>true,'points'=>8]);
    }catch(Throwable $e){if(stripos($e->getMessage(),'duplicate')!==false||stripos($e->getMessage(),'1062')!==false)spa_json(['ok'=>true,'points'=>0,'duplicate'=>true]);spa_json(['ok'=>false,'error'=>'Не удалось начислить XP'],500);}
  }

  if ($action === 'daily_tasks_status') {
    if(!$user)spa_json(['ok'=>false],401);
    $tz=new DateTimeZone('Europe/Moscow');$now=new DateTimeImmutable('now',$tz);$day=$now->modify('-3 hours')->format('Y-m-d');
    $srv=spa_user_server((int)$user['id']);$sid=$srv?(int)$srv['id']:0;$uid=(int)$user['id'];
    $defs=[
      ['id'=>'chat_one','title'=>'Поздоровайся в чате сервера','desc'=>'Отправь хотя бы одно сообщение','xp'=>15,'kind'=>'messages','need'=>1],
      ['id'=>'chat_three','title'=>'Будь активным в общении','desc'=>'Отправь три сообщения в чате сервера','xp'=>25,'kind'=>'messages','need'=>3],
      ['id'=>'chat_five','title'=>'Душа компании','desc'=>'Отправь пять сообщений в чате сервера','xp'=>35,'kind'=>'messages','need'=>5],
      ['id'=>'chat_ten','title'=>'Голос сервера','desc'=>'Отправь десять сообщений в чате','xp'=>45,'kind'=>'messages','need'=>10],
      ['id'=>'fav_one','title'=>'Сохрани интересное','desc'=>'Добавь один материал в избранное','xp'=>12,'kind'=>'favorites','need'=>1],
      ['id'=>'fav_three','title'=>'Собери подборку','desc'=>'Добавь три материала в избранное','xp'=>20,'kind'=>'favorites','need'=>3],
      ['id'=>'fav_five','title'=>'Настоящий коллекционер','desc'=>'Добавь пять материалов в избранное','xp'=>30,'kind'=>'favorites','need'=>5],
      ['id'=>'vote_poll','title'=>'Голос сообщества','desc'=>'Проголосуй в опросе сервера','xp'=>20,'kind'=>'poll','need'=>1],
      ['id'=>'poll_two','title'=>'Вырази мнение дважды','desc'=>'Проголосуй в двух опросах сервера','xp'=>30,'kind'=>'poll','need'=>2],
      ['id'=>'event_join','title'=>'Поддержи событие','desc'=>'Запишись на событие сервера','xp'=>20,'kind'=>'event','need'=>1],
      ['id'=>'event_two','title'=>'Участник событий','desc'=>'Запишись на два события сервера','xp'=>30,'kind'=>'event','need'=>2],
      ['id'=>'react_one','title'=>'Оцени контент','desc'=>'Поставь реакцию (лайк) на видео или канал','xp'=>15,'kind'=>'reactions','need'=>1],
      ['id'=>'react_three','title'=>'Активный зритель','desc'=>'Поставь три реакции на контент','xp'=>28,'kind'=>'reactions','need'=>3],
      ['id'=>'app_open','title'=>'Открой центр приложений','desc'=>'Зайди в центр приложений StreamLive','xp'=>10,'kind'=>'apps','need'=>1],
    ];
    $claimed=[];$st=db()->prepare('SELECT task_id FROM spa_profile_daily_claims WHERE user_id=? AND day_key=?');$st->execute([$uid,$day]);foreach($st->fetchAll(PDO::FETCH_COLUMN) as $x)$claimed[(string)$x]=true;
    $progress=['messages'=>0,'favorites'=>0,'poll'=>0,'event'=>0];
    if($sid){$st=db()->prepare('SELECT COUNT(*) FROM spa_server_messages WHERE server_id=? AND user_id=? AND is_system=0 AND created_at>=?');$st->execute([$sid,$uid,$day.' 03:00:00']);$progress['messages']=(int)$st->fetchColumn();
      $st=db()->prepare('SELECT COUNT(*) FROM spa_server_poll_votes WHERE server_id=? AND user_id=? AND created_at>=?');$st->execute([$sid,$uid,$day.' 03:00:00']);$progress['poll']=(int)$st->fetchColumn();
      $st=db()->prepare('SELECT COUNT(*) FROM spa_server_event_rsvps WHERE server_id=? AND user_id=? AND created_at>=?');$st->execute([$sid,$uid,$day.' 03:00:00']);$progress['event']=(int)$st->fetchColumn();}
    $st=db()->prepare('SELECT COUNT(*) FROM spa_favorites WHERE user_id=? AND created_at>=?');$st->execute([$uid,$day.' 03:00:00']);$progress['favorites']=(int)$st->fetchColumn();
    try{$st=db()->prepare('SELECT COUNT(*) FROM spa_content_reactions WHERE user_id=? AND updated_at>=?');$st->execute([$uid,$day.' 03:00:00']);$progress['reactions']=(int)$st->fetchColumn();}catch(Throwable $e){$progress['reactions']=0;}
    try{$st=db()->prepare('SELECT COUNT(*) FROM spa_youtube_reactions WHERE user_id=? AND updated_at>=?');$st->execute([$uid,$day.' 03:00:00']);$progress['reactions']+=(int)$st->fetchColumn();}catch(Throwable $e){}
    /* apps open tracked client-side via claim only when done flag set in local + optional table */
    $progress['apps']=0;
    try{
      $st=db()->prepare("SELECT COUNT(*) FROM spa_profile_xp_awards WHERE user_id=? AND award_key LIKE 'quest:%:open_apps' AND created_at>=?");
      $st->execute([$uid,$day.' 03:00:00']);
      $progress['apps']=(int)$st->fetchColumn()>0?1:0;
    }catch(Throwable $e){}

    $items=[];foreach($defs as $d){$v=(int)$progress[$d['kind']];$items[]=$d+['progress'=>min($v,$d['need']),'done'=>$v >= $d['need'],'claimed'=>!empty($claimed[$d['id']]),'locked'=>in_array($d['kind'],['messages','poll','event'],true)&&!$sid];}
    $st=db()->prepare('SELECT COALESCE(SUM(points),0) FROM spa_profile_daily_claims WHERE user_id=? AND day_key=?');$st->execute([$uid,$day]);$earned=(int)$st->fetchColumn();
    spa_json(['ok'=>true,'day'=>$day,'reset_at'=>($now->setTime(3,0) <= $now ? $now->setTime(3,0)->modify('+1 day') : $now->setTime(3,0))->format(DateTimeInterface::ATOM),'items'=>$items,'earned'=>$earned,'server_required'=>!$sid]);
  }

  if ($action === 'daily_task_claim' && $_SERVER['REQUEST_METHOD']==='POST') {
    if(!$user)spa_json(['ok'=>false],401);
    $id=trim((string)($_POST['task_id']??''));$tz=new DateTimeZone('Europe/Moscow');$now=new DateTimeImmutable('now',$tz);$day=$now->modify('-3 hours')->format('Y-m-d');
    $defs=['chat_one'=>['messages',1,15],'chat_three'=>['messages',3,25],'fav_one'=>['favorites',1,12],'fav_three'=>['favorites',3,20],'fav_five'=>['favorites',5,30],'chat_five'=>['messages',5,35],'vote_poll'=>['poll',1,20],'event_join'=>['event',1,20],'poll_two'=>['poll',2,30],'event_two'=>['event',2,30],'chat_ten'=>['messages',10,45],'chat_twenty'=>['messages',20,60],'fav_ten'=>['favorites',10,45],'poll_three'=>['poll',3,40],'event_three'=>['event',3,45],'react_one'=>['reactions',1,15],'react_three'=>['reactions',3,28],'app_open'=>['apps',1,10]];
    if(!isset($defs[$id]))spa_json(['ok'=>false,'error'=>'Неизвестное задание'],400);
    [$kind,$need,$pts]=$defs[$id];$uid=(int)$user['id'];$srv=spa_user_server($uid);$sid=$srv?(int)$srv['id']:0;$since=$day.' 03:00:00';$count=0;
    if($kind==='favorites'){$st=db()->prepare('SELECT COUNT(*) FROM spa_favorites WHERE user_id=? AND created_at>=?');$st->execute([$uid,$since]);$count=(int)$st->fetchColumn();}
    elseif($sid && $kind==='messages'){$st=db()->prepare('SELECT COUNT(*) FROM spa_server_messages WHERE server_id=? AND user_id=? AND is_system=0 AND created_at>=?');$st->execute([$sid,$uid,$since]);$count=(int)$st->fetchColumn();}
    elseif($sid && $kind==='poll'){$st=db()->prepare('SELECT COUNT(*) FROM spa_server_poll_votes WHERE server_id=? AND user_id=? AND created_at>=?');$st->execute([$sid,$uid,$since]);$count=(int)$st->fetchColumn();}
    elseif($sid && $kind==='event'){$st=db()->prepare('SELECT COUNT(*) FROM spa_server_event_rsvps WHERE server_id=? AND user_id=? AND created_at>=?');$st->execute([$sid,$uid,$since]);$count=(int)$st->fetchColumn();}
    
    elseif($kind==='reactions'){
      $count=0;
      try{$st=db()->prepare('SELECT COUNT(*) FROM spa_content_reactions WHERE user_id=? AND updated_at>=?');$st->execute([$uid,$since]);$count+=(int)$st->fetchColumn();}catch(Throwable $e){}
      try{$st=db()->prepare('SELECT COUNT(*) FROM spa_youtube_reactions WHERE user_id=? AND updated_at>=?');$st->execute([$uid,$since]);$count+=(int)$st->fetchColumn();}catch(Throwable $e){}
    }
    elseif($kind==='apps'){
      $count=0;
      try{$st=db()->prepare("SELECT COUNT(*) FROM spa_profile_xp_awards WHERE user_id=? AND award_key LIKE 'quest:%:open_apps' AND created_at>=?");$st->execute([$uid,$since]);$count=(int)$st->fetchColumn()>0?1:0;}catch(Throwable $e){}
    }
    if($count<$need)spa_json(['ok'=>false,'error'=>'Сначала выполни условие задания'],409);
    try{db()->beginTransaction();db()->prepare('INSERT INTO spa_profile_daily_claims(user_id,day_key,task_id,points) VALUES(?,?,?,?)')->execute([$uid,$day,$id,$pts]);db()->prepare('INSERT INTO spa_profile_xp_awards(user_id,award_key,points,award_type) VALUES(?,?,?,\'daily\')')->execute([$uid,'daily:'.$day.':'.$id,$pts]);
      if($sid && in_array($kind,['messages','poll','event'],true)){$award='daily:'.$day.':'.$id;db()->prepare('INSERT IGNORE INTO spa_server_xp_awards(server_id,user_id,award_key,points,award_type) VALUES(?,?,?,?,\'quest\')')->execute([$sid,$uid,$award,$pts]);if(db()->lastInsertId())db()->prepare('INSERT INTO spa_server_score(server_id,xp,ach_count) VALUES(?,?,0) ON DUPLICATE KEY UPDATE xp=xp+VALUES(xp),updated_at=NOW()')->execute([$sid,$pts]);}
      db()->commit();spa_json(['ok'=>true,'points'=>$pts]);
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();if(stripos($e->getMessage(),'duplicate')!==false||stripos($e->getMessage(),'1062')!==false)spa_json(['ok'=>false,'error'=>'Награда уже получена'],409);spa_json(['ok'=>false,'error'=>'Не удалось начислить награду'],500);}
  }

  if ($action === 'server_xp_status') {
    if (!$user) spa_json(['ok'=>false],401);
    $srv=spa_user_server((int)$user['id']);
    if (!$srv) spa_json(['ok'=>true,'in_server'=>false,'xp'=>0,'level'=>1,'next_xp'=>100,'progress'=>0,'benefits'=>[]]);
    $q=db()->prepare('SELECT COALESCE(SUM(points),0) xp, COUNT(*) awards FROM spa_server_xp_awards WHERE server_id=? AND user_id=?');
    $q->execute([(int)$srv['id'],(int)$user['id']]);$r=$q->fetch(PDO::FETCH_ASSOC)?:[];$xp=(int)($r['xp']??0);
    $levels=[0,100,250,500,850,1300,1900,2700,3700,5000,6600];$level=1;for($i=1;$i<count($levels);$i++){if($xp >= $levels[$i])$level=$i+1;else break;}
    $base=$levels[min($level-1,count($levels)-1)];$next=$levels[min($level,count($levels)-1)]??$base;$progress=$next>$base?max(0,min(100,(int)round(($xp-$base)/($next-$base)*100))):100;
    $benefits=[];if($level>=2)$benefits[]='Значок участника';if($level>=3)$benefits[]='Дополнительные серверные лимиты';if($level>=5)$benefits[]='Эксклюзивная рамка профиля';if($level>=7)$benefits[]='Приоритетные серверные задания';if($level>=10)$benefits[]='Легендарный статус';
    spa_json(['ok'=>true,'in_server'=>true,'server_name'=>(string)($srv['name']??'Сервер'),'xp'=>$xp,'level'=>$level,'next_xp'=>$next,'progress'=>$progress,'awards'=>(int)($r['awards']??0),'benefits'=>$benefits]);
  }

  if ($action === 'server_xp_add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$user) spa_json(['ok' => false], 401);
    $srv = spa_user_server((int)$user['id']);
    if (!$srv) spa_json(['ok' => false, 'error' => 'Не в сервере'], 403);
    $reason = trim((string)($_POST['reason'] ?? ''));
    $type = ''; $key = ''; $pts = 0;
    if (preg_match('/^ach:([a-z0-9_\-]{1,64})$/i', $reason, $m)) {
      $allowedAch=['quests_full','quest_xp50','music_3','music_10','clan_join','clan_owner','clan_5','sub','cinema_first','cinema_5','night_owl','early_bird','story_post','dm_first','react_first','playlist_share','fav_5','theme_oled','lang_switch','block_parent','qr_first','files_first','history_10','server_role','server_badge','server_poll','server_event','server_service','collection_shared'];
      if (!in_array(strtolower($m[1]),$allowedAch,true)) spa_json(['ok'=>false,'error'=>'Неизвестная ачивка'],400);
      $type='achievement'; $key='ach:'.strtolower($m[1]); $pts=8;
    } elseif (preg_match('/^quest:([a-z0-9_\-]{1,48})$/i', $reason, $m)) {
      if (!in_array(strtolower($m[1]),['open_cinema','open_music','server_chat','open_profile','open_apps'],true)) spa_json(['ok'=>false,'error'=>'Неизвестное задание'],400);
      $type='quest'; $key='quest:'.date('Y-m-d').':'.strtolower($m[1]); $pts=3;
    } else {
      spa_json(['ok'=>false,'error'=>'Неизвестный тип награды'],400);
    }
    $sid=(int)$srv['id']; $uid=(int)$user['id'];
    try {
      db()->beginTransaction();
      db()->prepare('INSERT INTO spa_server_xp_awards (server_id,user_id,award_key,points,award_type) VALUES (?,?,?,?,?)')
        ->execute([$sid,$uid,$key,$pts,$type]);
      db()->prepare('INSERT INTO spa_server_score (server_id,xp,ach_count) VALUES (?,?,?) ON DUPLICATE KEY UPDATE xp=xp+VALUES(xp), ach_count=ach_count+VALUES(ach_count), updated_at=NOW()')
        ->execute([$sid,$pts,$type==='achievement'?1:0]);
      db()->commit();
      spa_json(['ok'=>true,'pts'=>$pts,'type'=>$type,'duplicate'=>false]);
    } catch (Throwable $e) {
      if (db()->inTransaction()) db()->rollBack();
      if (stripos($e->getMessage(),'duplicate')!==false || stripos($e->getMessage(),'1062')!==false) spa_json(['ok'=>true,'pts'=>0,'duplicate'=>true]);
      spa_json(['ok'=>false,'error'=>'Не удалось начислить XP'],500);
    }
  }

  if ($action === 'server_inactivity_get') {
    if (!$user) spa_json(['ok'=>false],401);
    $srv=spa_user_server((int)$user['id']); if(!$srv) spa_json(['ok'=>false,'error'=>'Не в сервере'],403);
    spa_json(['ok'=>true,'days'=>(int)($srv['inactive_kick_days']??0),'is_owner'=>(int)$srv['owner_id']===(int)$user['id']]);
  }
  if ($action === 'server_inactivity_set' && $_SERVER['REQUEST_METHOD']==='POST') {
    if (!$user) spa_json(['ok'=>false],401);
    $srv=spa_user_server((int)$user['id']); if(!$srv || (int)$srv['owner_id']!==(int)$user['id']) spa_json(['ok'=>false,'error'=>'Только владелец сервера'],403);
    $days=(int)($_POST['days']??0); if(!in_array($days,[0,30,60,90],true)) spa_json(['ok'=>false,'error'=>'Выбери 0, 30, 60 или 90 дней'],400);
    db()->prepare('UPDATE spa_servers SET inactive_kick_days=? WHERE id=?')->execute([$days,(int)$srv['id']]);
    spa_json(['ok'=>true,'days'=>$days]);
  }

  if ($action === 'server_rank') {
    if (!$user) spa_json(['ok' => false], 401);
    // score = members*10 + xp (ачивки/активность участников)
    try {
      $st = db()->query(
        'SELECT s.id, s.name, s.owner_id, COUNT(m.user_id) AS members,
                COALESCE(sc.xp, 0) AS xp, COALESCE(sc.ach_count, 0) AS ach_count,
                (COUNT(m.user_id)*10 + COALESCE(sc.xp, 0)) AS score
         FROM spa_servers s
         LEFT JOIN spa_server_members m ON m.server_id = s.id
         LEFT JOIN spa_server_score sc ON sc.server_id = s.id
         GROUP BY s.id
         ORDER BY score DESC, members DESC, s.id DESC
         LIMIT 40'
      );
    } catch (Throwable $e) {
      $st = db()->query('SELECT s.id, s.name, s.owner_id, COUNT(m.user_id) as members
        FROM spa_servers s LEFT JOIN spa_server_members m ON m.server_id = s.id
        GROUP BY s.id ORDER BY members DESC, s.id DESC LIMIT 30');
    }
    $list = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $list[] = [
        'id' => (int)$r['id'],
        'name' => (string)$r['name'],
        'members' => (int)$r['members'],
        'xp' => (int)($r['xp'] ?? 0),
        'ach_count' => (int)($r['ach_count'] ?? 0),
        'score' => (int)($r['score'] ?? ((int)$r['members'] * 10)),
      ];
    }
    spa_json(['ok' => true, 'rank' => $list]);
  }



  if ($action === 'music') {
    if (!$user) spa_json(['ok' => false], 401);
    $tracks = [];
    $paths = [
      __DIR__ . '/api/native_music.php',
      __DIR__ . '/webapp/api/native_music.php',
    ];
    // fallback: videos matching music from DB
    try {
      $st = db()->query("SELECT id, title, slug, thumbnail_url, embed_url, source_url, platform, created_at FROM videos
        WHERE title REGEXP 'музык|music|клип|clip|track|песн|OST|remix|radio'
        ORDER BY id DESC LIMIT 40");
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $th = (string)($v['thumbnail_url'] ?? '');
        $tracks[] = [
          'id' => (int)$v['id'],
          'title' => (string)$v['title'],
          'url' => (string)($v['source_url'] ?: $v['embed_url'] ?: ''),
          'cover' => $th,
          'kind' => 'video',
        ];
      }
    } catch (Throwable $e) {
      try {
        $st = db()->query("SELECT id, title, slug, thumbnail_url, embed_url, source_url FROM videos ORDER BY id DESC LIMIT 80");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) {
          $blob = (string)$v['title'];
          if (!preg_match('/музык|music|клип|clip|track|песн|OST|remix|radio/ui', $blob)) continue;
          $tracks[] = [
            'id' => (int)$v['id'],
            'title' => (string)$v['title'],
            'url' => (string)($v['source_url'] ?: $v['embed_url'] ?: ''),
            'cover' => (string)($v['thumbnail_url'] ?? ''),
            'kind' => 'video',
          ];
        }
      } catch (Throwable $e2) {}
    }
    spa_json(['ok' => true, 'tracks' => $tracks]);
  }

  spa_json(['ok' => false, 'error' => 'unknown api'], 404);
}

$__user = function_exists('current_user') ? current_user() : null;
$bootUser = $__user ? spa_user_public($__user) : null;
$bootStreak = $__user ? spa_touch_streak((int)$__user['id']) : null;
$bootSub = $__user ? spa_has_sub((int)$__user['id']) : false;
if (!headers_sent()) { header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache'); header('Expires: 0'); }
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#0c0614">
<title>StreamLive SPA</title>
<script src="<?= htmlspecialchars(rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/streamspa.php'))), '/') . '/playerjs.js?v=' . rawurlencode(SPA_BUILD), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.15/hls.min.js" defer></script>
<style>
html,body{min-height:100%;height:100%;overscroll-behavior-y:none}
body{min-height:100dvh}
.shell{min-height:100dvh;height:100dvh;overflow:hidden}
#app,.col-list,.detail,.detail-body,#pane-body{min-height:0}
:root{
  --bg:#0c0614;--bg2:#140a22;--card:#1c1230;--card2:#24183a;
  --line:rgba(255,255,255,.07);--text:#f3eaff;--muted:#9b8bb8;
  --accent:#7c5cff;--accent2:#a78bfa;--green:#34d399;--danger:#f87171;
  --rail:72px;--list:340px;--safe-b:env(safe-area-inset-bottom,0px);
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;height:100%;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
body{overflow:hidden}
button{font:inherit;color:inherit;background:none;border:0;cursor:pointer}
input{font:inherit;color:inherit}

/* splash */
#splash{position:fixed;inset:0;z-index:10000;background:radial-gradient(ellipse at 50% 30%,#2a1450,#0c0614 70%);
  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;transition:opacity .4s}
#splash.hide{opacity:0;pointer-events:none}
#splash .logo{font-size:28px;font-weight:800;letter-spacing:.04em}
#splash .spin{width:36px;height:36px;border:3px solid rgba(255,255,255,.15);border-top-color:var(--accent);border-radius:50%;animation:sp 0.8s linear infinite}
@keyframes sp{to{transform:rotate(360deg)}}

.update-bar{position:fixed;top:0;left:0;right:0;z-index:9999;display:none;background:linear-gradient(135deg,#6d5efc,#8b5cf6);color:#fff;
  padding:12px 16px;text-align:center;font-size:14px;font-weight:600;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap}
.update-bar.on{display:flex}
html{height:100%;height:100dvh}
body.spa-scroll-down .nav-pill{transform:translateY(110%);opacity:0;pointer-events:none;transition:transform .22s ease,opacity .22s ease}
body.spa-scroll-up .nav-pill,body:not(.spa-scroll-down) .nav-pill{transform:translateY(0);opacity:1;transition:transform .22s ease,opacity .22s ease}
.nav-pill{padding-bottom:calc(8px + env(safe-area-inset-bottom,0px));}
@media(max-width:900px){.shell{min-height:100dvh;min-height:calc(var(--spa-vvh,100dvh));}}

.update-bar button{background:#fff;color:#4c1d95;border-radius:999px;padding:8px 16px;font-weight:700}
.update-bar button.ghost{background:transparent;color:#fff;border:1px solid rgba(255,255,255,.4)}

.shell{display:flex;height:100%;width:100%}

/* left rail (PC) */
.rail{display:none;width:var(--rail);flex-shrink:0;background:#080410;border-right:1px solid var(--line);
  flex-direction:column;align-items:center;padding:12px 0;gap:10px}
.rail .rbtn{width:48px;height:48px;border-radius:16px;background:var(--card);display:grid;place-items:center;font-size:20px;position:relative}
.rail .rbtn.on{background:var(--accent);border-radius:14px}
.rail .rbtn img{width:100%;height:100%;object-fit:cover;border-radius:inherit}
.rail .rplus{background:transparent;border:2px dashed rgba(255,255,255,.15);color:var(--muted)}

/* main column list */
.col-list{width:100%;max-width:480px;margin:0 auto;display:flex;flex-direction:column;height:100%;background:linear-gradient(180deg,#160b28,#0c0614 50%);position:relative;min-width:0}
.col-detail{display:none;flex:1;background:#0a0612;border-left:1px solid var(--line);flex-direction:column;min-width:0}

@media(min-width:900px){
  .rail{display:flex}
  .col-list{max-width:var(--list);margin:0;border-right:1px solid var(--line)}
  .col-detail{display:flex}
  .nav{display:none !important}
  .menu-only{display:none !important}
}

.top{padding:12px 12px 8px;display:flex;align-items:center;gap:8px;flex-shrink:0}
.icon-btn{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.06);display:grid;place-items:center;flex-shrink:0}
.seg{display:flex;width:100%;flex-shrink:0;background:rgba(0,0,0,.2);border-radius:999px;padding:3px;position:relative;z-index:5}
.seg button{flex:1;padding:8px 0;border-radius:999px;font-size:13px;font-weight:600;color:var(--muted)}
.seg button.on{background:linear-gradient(135deg,#6d5efc,#8b5cf6);color:#fff}
.search-mini{flex:1;background:var(--card);border:1px solid var(--line);border-radius:999px;padding:10px 14px;font-size:14px}

.stories{display:flex;gap:12px;padding:6px 12px 12px;overflow-x:auto;flex-shrink:0;scrollbar-width:none}
.stories::-webkit-scrollbar{display:none}
.story{width:64px;text-align:center;flex-shrink:0;cursor:pointer}
.story .av{width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid var(--accent);padding:2px;background:var(--bg);display:block;margin:0 auto 4px}
.story .av.empty{border-style:dashed;border-color:rgba(255,255,255,.25);display:grid;place-items:center;font-size:22px;color:var(--muted)}
.story span{font-size:11px;color:var(--muted);display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.list{flex:1 1 0;min-height:0;overflow-y:auto;padding:0 8px 100px;-webkit-overflow-scrolling:touch;overscroll-behavior-y:contain;touch-action:pan-y}
.row{display:flex;gap:12px;align-items:center;padding:12px 10px;border-radius:14px;cursor:pointer}
.row:hover,.row:active{background:rgba(255,255,255,.05)}
.row .av{width:48px;height:48px;border-radius:50%;object-fit:cover;flex-shrink:0;background:var(--card2)}
.row .av.letter{display:grid;place-items:center;font-weight:700;background:linear-gradient(135deg,#6366f1,#a855f7)}
.row .body{flex:1;min-width:0}
.row .title{font-weight:600;font-size:14px;display:flex;gap:6px;align-items:center}
.row .sub{font-size:12px;color:var(--muted);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.row .meta{font-size:11px;color:var(--muted)}
.badge{font-size:10px;padding:2px 6px;border-radius:6px;background:rgba(124,92,255,.25);color:var(--accent2)}

.nav{position:absolute;left:10px;right:10px;bottom:calc(10px + var(--safe-b));display:flex;gap:10px;z-index:20;align-items:center}
.nav-pill{flex:1;display:flex;background:rgba(28,18,48,.96);backdrop-filter:blur(18px);border-radius:999px;padding:6px;border:1px solid rgba(255,255,255,.12);box-shadow:0 8px 28px rgba(0,0,0,.45)}
.nav-pill button{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:7px 2px;border-radius:999px;font-size:10px;color:rgba(255,255,255,.72);min-width:0}
.nav-pill button span{color:inherit;font-size:10px;line-height:1.1}
.nav-pill button.on{background:var(--accent);color:#fff}
.fab{width:52px;height:52px;border-radius:50%;background:rgba(255,255,255,.12);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);border:1px solid rgba(255,255,255,.28);display:grid;place-items:center;font-size:18px;color:#fff;box-shadow:0 8px 24px rgba(0,0,0,.25),inset 0 1px 0 rgba(255,255,255,.25);flex-shrink:0}

.screen{display:none;flex-direction:column;height:100%;min-height:0}
.screen.on{display:flex}
#sc-chats{overflow:hidden}

#sc-auth{position:relative;isolation:isolate;overflow:hidden;background:radial-gradient(ellipse at 15% 15%,rgba(112,75,210,.22),transparent 45%),radial-gradient(ellipse at 85% 80%,rgba(30,170,190,.13),transparent 42%),#090713}
#sc-auth:before,#sc-auth:after{content:'';position:absolute;z-index:-1;pointer-events:none;width:58vw;height:58vw;max-width:560px;max-height:560px;border-radius:50%;filter:blur(58px);opacity:.48;will-change:transform}
#sc-auth:before{left:-18%;top:-16%;background:conic-gradient(from 20deg,rgba(125,83,255,.0),rgba(125,83,255,.78),rgba(46,190,210,.45),rgba(125,83,255,.0));animation:slAuthFloatA 17s ease-in-out infinite alternate}
#sc-auth:after{right:-20%;bottom:-22%;background:conic-gradient(from 180deg,rgba(37,186,204,.0),rgba(75,95,220,.65),rgba(181,82,255,.62),rgba(37,186,204,.0));animation:slAuthFloatB 21s ease-in-out infinite alternate}
@keyframes slAuthFloatA{0%{transform:translate3d(-3%,0,0) rotate(0) scale(.88)}100%{transform:translate3d(24%,22%,0) rotate(145deg) scale(1.16)}}
@keyframes slAuthFloatB{0%{transform:translate3d(3%,0,0) rotate(0) scale(1)}100%{transform:translate3d(-25%,-18%,0) rotate(-130deg) scale(.82)}}
.auth-wrap{position:relative;z-index:1;flex:0 1 auto;display:flex;flex-direction:column;justify-content:center;padding:32px 26px;max-width:440px;margin:auto;width:calc(100% - 36px);align-self:center;border:1px solid rgba(255,255,255,.16);border-radius:28px;background:linear-gradient(145deg,rgba(32,24,57,.82),rgba(13,13,30,.72));backdrop-filter:blur(24px) saturate(145%);-webkit-backdrop-filter:blur(24px) saturate(145%);box-shadow:0 28px 90px rgba(0,0,0,.42),inset 0 1px 0 rgba(255,255,255,.12)}
#sc-auth .auth-wrap h1{font-size:clamp(34px,7vw,46px);letter-spacing:-1.8px;background:linear-gradient(100deg,#fff 5%,#c7b6ff 48%,#8ee8ed 100%);-webkit-background-clip:text;background-clip:text;color:transparent}
#sc-auth .auth-wrap .field{background:rgba(255,255,255,.065);border-color:rgba(255,255,255,.14);box-shadow:inset 0 1px 0 rgba(255,255,255,.04)}
#sc-auth .auth-wrap .btn-primary{box-shadow:0 10px 30px rgba(116,84,255,.3);transition:transform .2s,filter .2s}
#sc-auth .auth-wrap .btn-primary:hover{transform:translateY(-2px);filter:brightness(1.12)}
@media(prefers-reduced-motion:reduce){#sc-auth:before,#sc-auth:after{animation:none}}
.field{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px 16px;margin-bottom:12px;width:100%}
.btn-primary{width:100%;padding:14px;border-radius:14px;font-weight:700;background:linear-gradient(135deg,#6d5efc,#8b5cf6);color:#fff;margin-top:8px}
.err{color:var(--danger);font-size:13px;min-height:18px}

.sl-level-showcase{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px;border:1px solid rgba(186,145,255,.27);border-radius:20px;background:radial-gradient(circle at 80% 50%,rgba(139,92,246,.24),transparent 44%),linear-gradient(145deg,rgba(34,22,61,.96),rgba(13,12,27,.96));box-shadow:inset 0 1px 0 rgba(255,255,255,.09),0 12px 30px rgba(0,0,0,.18);overflow:hidden}.sl-level-showcase-copy{min-width:0;flex:1}.sl-level-eyebrow{font-size:10px;letter-spacing:.16em;font-weight:850;color:#c9a9ff}.sl-level-name{font-size:clamp(20px,4vw,27px);font-weight:900;line-height:1.15;margin-top:4px;color:#fff;text-shadow:0 3px 18px rgba(168,85,247,.35)}.sl-level-xp{font-size:12px;color:#d7c7f5;margin-top:5px}.sl-level-progress{height:7px;margin-top:12px;border-radius:99px;background:rgba(255,255,255,.10);overflow:hidden}.sl-level-progress i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#7c3aed,#d8a7ff);box-shadow:0 0 14px rgba(192,132,252,.55)}.sl-level-next{font-size:11px;color:#b9abc9;margin-top:7px;line-height:1.4}.sl-level-medal{width:118px;flex:0 0 118px;filter:drop-shadow(0 0 15px rgba(168,85,247,.38));animation:slMedalFloat 4s ease-in-out infinite}.sl-level-medal svg{display:block;width:100%;height:auto}@keyframes slMedalFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-4px)}}#sl-youtube-app .yt-frame:fullscreen{width:100vw;height:100vh;max-height:none;min-height:100vh;aspect-ratio:auto;border-radius:0;background:#000}#sl-youtube-app .yt-frame:fullscreen iframe{width:100%;height:100%}@media(max-width:480px){.sl-level-showcase{padding:11px;gap:8px}.sl-level-medal{width:92px;flex-basis:92px}.sl-level-name{font-size:20px}}
.prof-head{text-align:center;padding:24px 16px 16px;background:linear-gradient(180deg,#3b1d6e 0%,transparent 100%)}
.prof-head .av{width:96px;height:96px;border-radius:50%;object-fit:cover;border:3px solid rgba(255,255,255,.15);margin-bottom:12px}
.card{margin:10px 14px;padding:14px;border-radius:16px;background:var(--card);border:1px solid var(--line)}
.card .label{font-size:12px;color:var(--muted);margin-bottom:4px}
.card .val{font-size:15px;font-weight:600}
.progress{height:8px;background:rgba(255,255,255,.08);border-radius:999px;overflow:hidden;margin-top:8px}
.progress>i{display:block;height:100%;background:linear-gradient(90deg,#6d5efc,#c084fc)}
.empty{text-align:center;color:var(--muted);padding:40px 20px;font-size:14px}
.dot{width:8px;height:8px;border-radius:50%;background:var(--green);display:inline-block}

/* Corvox-like menu panel */
.drawer-ov{position:absolute;inset:0;background:rgba(0,0,0,.55);z-index:50;display:none}
.drawer-ov.on{display:block}
.drawer{
  position:absolute;left:0;top:0;bottom:0;width:min(320px,88vw);background:#12081f;
  border-right:1px solid var(--line);padding:0;display:flex;flex-direction:column;
  animation:slideIn .2s ease;overflow-y:auto
}
@keyframes slideIn{from{transform:translateX(-16px);opacity:.5}to{transform:none;opacity:1}}
.drawer-head{padding:28px 20px 16px;background:linear-gradient(180deg,#2a1450,transparent)}
.drawer-head img{width:64px;height:64px;border-radius:50%;object-fit:cover;margin-bottom:10px}
.drawer-head .name{font-size:18px;font-weight:700}
.drawer-head .handle{color:var(--muted);font-size:13px}
.drawer-menu{padding:8px 12px;display:flex;flex-direction:column;gap:4px}
.drawer-menu .item{
  display:flex;align-items:center;gap:12px;padding:14px 14px;border-radius:14px;font-weight:600;font-size:15px;text-align:left;width:100%
}
.drawer-menu .item:hover{background:rgba(255,255,255,.05)}
.drawer-menu .ico{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;font-size:16px;flex-shrink:0}
.drawer-menu .ico.b{background:#3b82f6}
.drawer-menu .ico.p{background:#8b5cf6}
.drawer-menu .ico.o{background:#f59e0b}
.drawer-menu .ico.g{background:#64748b}
.drawer-menu .ico.n{background:#6366f1}
.drawer-menu .ico.a{background:#a855f7}
.drawer-menu .chev{margin-left:auto;opacity:.4}
.drawer-toggle{display:flex;align-items:center;justify-content:space-between;padding:14px 14px;border-radius:14px;font-weight:600}
.switch{width:44px;height:26px;border-radius:999px;background:#334;position:relative}
.switch.on{background:var(--accent)}
.switch i{position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;transition:left .15s}
.switch.on i{left:21px}
.drawer-foot{margin-top:auto;padding:16px 20px;font-size:12px;color:var(--muted)}

.search-ov,.detail-ov{position:absolute;inset:0;background:var(--bg);z-index:40;display:none;flex-direction:column}
.search-ov.on,.detail-ov.on{display:flex}
.search-start{padding:12px 14px 28px;overflow:auto}.search-start h3{font-size:15px;margin:12px 0 10px}.search-hint{color:var(--muted);font-size:13px;line-height:1.45;margin:4px 2px 14px}.search-shortcuts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.search-shortcut{display:flex;align-items:center;gap:10px;padding:13px;border-radius:15px;background:var(--card);border:1px solid var(--line);text-align:left;font-weight:650}.search-shortcut .sico{width:36px;height:36px;border-radius:12px;display:grid;place-items:center;background:rgba(139,92,246,.15);font-size:18px}.search-recent{display:flex;flex-wrap:wrap;gap:8px}.search-recent button{border:1px solid var(--line);background:var(--card);border-radius:999px;padding:8px 12px;font-size:13px}.search-section-head{display:flex;align-items:center;justify-content:space-between}.search-section-head button{color:var(--accent);font-size:12px}
.detail-top{display:flex;align-items:center;gap:10px;padding:12px 14px}
.detail-body{flex:1;overflow-y:auto;padding:0 14px 40px}
.player-box{position:relative;width:100%;padding-top:56.25%;background:radial-gradient(ellipse at 50% 0%,rgba(124,92,255,.16),transparent 55%),#050509;border:1px solid rgba(255,255,255,.13);border-radius:18px;overflow:hidden;margin-bottom:12px;box-shadow:0 16px 42px rgba(0,0,0,.28),inset 0 1px 0 rgba(255,255,255,.08)}
.player-box iframe,.player-box video,#spa-player,.playerjs-host{position:absolute;inset:0;width:100%;height:100%;border:0}
.playerjs-host{z-index:1;background:#050509;overflow:hidden}
.playerjs-host>div,.playerjs-host iframe,.playerjs-host video{width:100%!important;height:100%!important;border:0!important}
.player-chrome-top{position:absolute;z-index:8;top:10px;left:10px;right:10px;display:flex;align-items:center;justify-content:space-between;gap:8px;pointer-events:none}
.player-brand{display:flex;align-items:center;gap:7px;padding:6px 10px;border:1px solid rgba(255,255,255,.15);border-radius:999px;background:rgba(10,10,18,.55);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);color:#fff;font-size:10px;font-weight:850;letter-spacing:.12em;text-transform:uppercase;text-shadow:0 1px 4px #000}
.player-brand i{display:inline-block;width:7px;height:7px;border-radius:50%;background:#9b87ff;box-shadow:0 0 12px #9b87ff}
.player-media-title{max-width:55%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#fff;font-size:11px;font-weight:650;text-shadow:0 1px 5px #000;text-align:right}
.player-quick-actions{position:absolute;z-index:9;top:52px;right:10px;display:flex;gap:6px;opacity:.82;transition:opacity .18s}
.player-box:hover .player-quick-actions,.player-box:focus-within .player-quick-actions{opacity:1}
.player-quick-actions button{width:34px;height:34px;border:1px solid rgba(255,255,255,.18);border-radius:11px;background:rgba(13,13,23,.66);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);color:#fff;display:grid;place-items:center;font-size:14px;cursor:pointer}
.player-quick-actions button.active{background:rgba(139,112,255,.38);border-color:rgba(180,160,255,.65)}
.player-error{position:absolute;inset:0;z-index:4;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:9px;padding:20px;text-align:center;color:#fff;background:radial-gradient(ellipse at 50% 20%,rgba(124,92,255,.20),transparent 60%),#07070d}
.player-error small{color:rgba(255,255,255,.62);font-size:12px}
.player-toast{position:absolute;z-index:12;left:50%;bottom:58px;transform:translateX(-50%);max-width:90%;padding:8px 12px;border:1px solid rgba(255,255,255,.16);border-radius:12px;background:rgba(12,12,22,.82);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);color:#fff;font-size:12px;text-align:center;box-shadow:0 8px 26px rgba(0,0,0,.28)}

/* danmaku layer */
.danmaku-layer{position:absolute;inset:0;pointer-events:none;overflow:hidden;z-index:5}
.player-box .danmaku-layer{border-radius:14px}

/* Persistent mini-player dock: video and music keep playing while navigating */
#sl-mini-dock{position:fixed;z-index:11000;right:16px;bottom:calc(16px + env(safe-area-inset-bottom,0px));width:min(360px,calc(100vw - 24px));display:flex;flex-direction:column;gap:8px;pointer-events:none}
#sl-mini-dock:empty{display:none}
.sl-mini-card{pointer-events:auto;overflow:hidden;border:1px solid rgba(255,255,255,.18);border-radius:17px;background:rgba(17,16,28,.92);backdrop-filter:blur(22px);-webkit-backdrop-filter:blur(22px);box-shadow:0 14px 42px rgba(0,0,0,.42);color:#fff}
.sl-mini-head{display:flex;align-items:center;gap:9px;padding:9px 10px;min-width:0}
.sl-mini-meta{min-width:0;flex:1}.sl-mini-title{font-size:12px;font-weight:750;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.sl-mini-sub{font-size:10px;color:rgba(255,255,255,.62);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.sl-mini-actions{display:flex;gap:5px;flex-shrink:0}.sl-mini-actions button{width:30px;height:30px;border:1px solid rgba(255,255,255,.14);border-radius:10px;background:rgba(255,255,255,.08);color:#fff;cursor:pointer}
#sl-mini-video-slot .player-box.sl-mini-video{width:100%;padding-top:56.25%;margin:0;border:0;border-radius:0;box-shadow:none}
#sl-mini-video-slot .player-box.sl-mini-video .player-chrome-top{display:none}
#sl-mini-video-slot .player-box.sl-mini-video .player-quick-actions{top:8px;right:8px;opacity:1}
#sl-mini-video-slot .player-box.sl-mini-video .danmaku-layer{display:none}
@media(max-width:600px){#sl-mini-dock{right:8px;bottom:calc(82px + env(safe-area-inset-bottom,0px));width:min(330px,calc(100vw - 16px))}.sl-mini-card{border-radius:15px}}

.player-box .danmaku-item{max-width:90%;overflow:hidden;text-overflow:clip}
.danmaku-input{position:relative;z-index:6}
.danmaku-item{position:absolute;white-space:nowrap;font-size:16px;font-weight:700;text-shadow:0 1px 3px #000,0 0 6px #000;animation:danmu linear forwards}
@keyframes danmu{from{transform:translateX(0)}to{transform:translateX(-120vw)}}
.danmaku-input{display:flex;gap:8px;margin:8px 0 16px}
.danmaku-input input{flex:1;background:var(--card);border:1px solid var(--line);border-radius:999px;padding:10px 14px}
.danmaku-input button{padding:10px 16px;border-radius:999px;background:var(--accent);font-weight:700}

.limit-banner{margin:12px;padding:16px;border-radius:16px;background:linear-gradient(135deg,#3b1d6e,#1e1035);border:1px solid rgba(167,139,250,.3)}
.limit-banner h3{margin:0 0 8px;font-size:16px}
.limit-banner p{margin:0 0 12px;color:var(--muted);font-size:13px;line-height:1.4}

.detail-pane-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--muted)}
.detail-pane-empty h2{margin:0 0 8px;color:var(--text);font-size:18px}


/* tutorial */
.tut-ov{position:fixed;inset:0;z-index:12000;background:rgba(0,0,0,.72);display:none;align-items:center;justify-content:center;padding:20px}
.tut-ov.on{display:flex}
.tut-box{width:min(420px,100%);background:var(--card,#1c1230);border:1px solid var(--line);border-radius:20px;padding:22px 20px 16px;max-height:min(90vh,640px);overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.5)}
.tut-box h2{margin:0 0 8px;font-size:20px}
.tut-box .step-n{font-size:12px;color:var(--muted);margin-bottom:12px}
.tut-box p{margin:0 0 12px;font-size:14px;line-height:1.5;color:var(--text)}
.tut-box ul{margin:0 0 14px;padding-left:18px;font-size:14px;line-height:1.55;color:var(--text)}
.tut-box li{margin-bottom:6px}
.tut-actions{display:flex;gap:8px;margin-top:8px}
.tut-actions button{flex:1;padding:12px;border-radius:12px;font-weight:700}
.tut-actions .next{background:linear-gradient(135deg,#6d5efc,#8b5cf6);color:#fff}
.tut-actions .skip{background:rgba(255,255,255,.06);color:var(--muted)}
.tut-dots{display:flex;gap:6px;justify-content:center;margin:10px 0 4px}
.tut-dots i{width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,.2)}
.tut-dots i.on{background:#8b5cf6}


/* mobile nav + search */
.nav{position:absolute;left:10px;right:10px;bottom:calc(10px + var(--safe-b));display:flex;gap:10px;z-index:20;align-items:center}
.nav-pill{flex:1;display:flex;background:rgba(28,18,48,.96);backdrop-filter:blur(18px);border-radius:999px;padding:6px;border:1px solid rgba(255,255,255,.12);box-shadow:0 8px 28px rgba(0,0,0,.45)}
.nav-pill button{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:7px 2px;border-radius:999px;font-size:10px;color:rgba(255,255,255,.72);min-width:0}
.nav-pill button span{color:inherit;font-size:10px;line-height:1.1}
.nav-pill button.on{background:var(--accent);color:#fff}
.fab{width:52px;height:52px;border-radius:50%;background:rgba(255,255,255,.12);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);border:1px solid rgba(255,255,255,.28);display:grid;place-items:center;font-size:18px;color:#fff;box-shadow:0 8px 24px rgba(0,0,0,.25),inset 0 1px 0 rgba(255,255,255,.25);flex-shrink:0}
.fab:active{transform:scale(.96)}

.theme-light{
  --bg:#f3f0fa;--card:#ffffff;--card2:#efeaf8;--text:#1a1228;--muted:#6b6280;
  --line:rgba(40,20,80,.12);--accent:#6d5efc;--accent2:#7c6cf8;--danger:#dc2626;
}
.theme-light body,
.theme-light .col-list,
.theme-light .col-detail,
.theme-light .rail,
.theme-light .drawer,
.theme-light .detail-ov,
.theme-light .search-ov,
.theme-light .shell{background:#f3f0fa !important;color:#1a1228 !important}
.theme-light .col-list{background:linear-gradient(180deg,#faf8ff,#f3f0fa 50%) !important}
.theme-light .rail{background:#ebe6f6 !important}
.theme-light .drawer{background:#faf8ff !important}
.theme-light .card{background:#fff !important;border-color:rgba(0,0,0,.08);color:#1a1228}
.theme-light .row:hover{background:rgba(0,0,0,.04)}
.theme-light .nav-pill{background:rgba(255,255,255,.97);border-color:rgba(0,0,0,.08);box-shadow:0 8px 24px rgba(0,0,0,.12)}
.theme-light .nav-pill button{color:#5c5470}
.theme-light .nav-pill button.on{background:var(--accent);color:#fff}
.theme-light .fab{background:rgba(255,255,255,.72);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);color:#3b2a60;border:1px solid rgba(0,0,0,.1);box-shadow:0 8px 20px rgba(0,0,0,.12),inset 0 1px 0 rgba(255,255,255,.8)}
.theme-light .search-mini,.theme-light .field{background:#fff;color:#1a1028;border-color:rgba(0,0,0,.12)}
.theme-light .drawer-head{background:linear-gradient(180deg,#d4c4f0,transparent)}
.sl-tool-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.sl-tool-card{border:1px solid var(--line);background:linear-gradient(145deg,rgba(255,255,255,.09),rgba(255,255,255,.025));backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);border-radius:16px;padding:12px}.sl-vault-row{display:flex;align-items:center;gap:10px;padding:10px;border:1px solid var(--line);border-radius:12px;margin:7px 0;background:rgba(255,255,255,.035)}.sl-tool-card,.sl-vault-row,.sl-pdf-book{backdrop-filter:blur(22px);-webkit-backdrop-filter:blur(22px)}#vault-filters button{border:1px solid var(--line);border-radius:999px;padding:7px 11px;background:rgba(255,255,255,.045);color:var(--text);font-size:12px}#vault-filters button.on{background:linear-gradient(135deg,rgba(109,94,252,.5),rgba(69,214,200,.16));border-color:rgba(155,140,255,.55)}#vault-filters{padding:3px 0}#vault-list .sl-vault-row{min-height:66px}#vault-list .sl-vault-row .icon-btn{flex:0 0 34px;width:34px;height:34px;border-radius:11px;border:1px solid var(--line);background:rgba(255,255,255,.06)}.sl-book-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px}.sl-book-card{min-width:0;padding:12px;border:1px solid var(--line);border-radius:18px;background:linear-gradient(145deg,rgba(255,255,255,.075),rgba(255,255,255,.025));backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px)}.sl-book-cover{height:155px;border-radius:13px;padding:13px;display:flex;flex-direction:column;justify-content:space-between;color:#fff;box-shadow:inset 0 1px rgba(255,255,255,.25),0 12px 25px rgba(0,0,0,.2);overflow:hidden}.sl-book-cover small{font-size:9px;letter-spacing:1px;opacity:.8}.sl-book-cover b{font-size:18px;line-height:1.15;overflow-wrap:anywhere}.sl-book-cover-0{background:linear-gradient(145deg,#8270ef,#342457 70%,#126d70)}.sl-book-cover-1{background:linear-gradient(145deg,#d99a5c,#79405f 70%,#241b3e)}.sl-book-cover-2{background:linear-gradient(145deg,#4f8799,#1d3c55 70%,#171c3c)}.sl-book-title{font-weight:700;font-size:12px;margin:10px 0 3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.sl-book-meta{font-size:10px;color:var(--muted)}.sl-book-actions{display:flex;gap:6px;margin-top:10px}.sl-book-actions .btn-primary{margin:0;flex:1;padding:8px 6px;font-size:11px}.sl-book-actions .icon-btn{flex:0 0 32px;width:32px;height:32px;border-radius:10px;border:1px solid var(--line);background:rgba(255,255,255,.06)}.sl-pdf-book-cover{background:linear-gradient(135deg,rgba(111,91,255,.78),rgba(34,24,58,.96) 58%,rgba(30,170,160,.38));border:1px solid rgba(255,255,255,.18);box-shadow:inset 0 1px 0 rgba(255,255,255,.22),0 18px 40px rgba(0,0,0,.26)}.sl-vault-row .meta{min-width:0;flex:1}.sl-vault-row .meta b{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px}.sl-vault-row .meta small{color:var(--muted);font-size:11px}.sl-vault-preview{width:100%;max-height:55vh;background:#000;border-radius:14px;margin:10px 0}.sl-storage-bar{height:8px;border-radius:9px;background:var(--card2);overflow:hidden}.sl-pdf-book{padding:12px}.sl-pdf-book-cover{min-height:145px;display:flex;flex-direction:column;justify-content:space-between;padding:18px;border-radius:12px;background:linear-gradient(145deg,rgba(109,94,252,.65),rgba(34,24,58,.95));box-shadow:inset 0 1px 0 rgba(255,255,255,.22),0 12px 28px rgba(0,0,0,.2);color:#fff}.sl-pdf-cover-title{font-size:20px;font-weight:850;line-height:1.2;overflow-wrap:anywhere}.sl-pdf-controls{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:10px 0}.sl-pdf-controls .btn-primary{width:auto;flex:1;margin:0;padding:9px}.sl-pdf-frame{width:100%;height:min(76vh,850px);border:1px solid var(--line);border-radius:12px;background:#fff}.sl-pdf-thumb{width:42px;height:54px;flex:0 0 42px;display:grid;place-items:center;border-radius:6px 10px 10px 6px;border-left:4px solid rgba(255,255,255,.35);background:linear-gradient(145deg,#8b5cf6,#312052);box-shadow:3px 4px 9px rgba(0,0,0,.22);font-size:23px}.sl-pdf-page-turn{animation:slPageTurn .28s ease}@keyframes slPageTurn{from{opacity:.45;transform:perspective(900px) rotateY(-5deg)}to{opacity:1;transform:perspective(900px) rotateY(0)}}.sl-storage-bar i{display:block;height:100%;background:linear-gradient(90deg,#8b7cf8,#45d6c8);width:0}.sl-service-row{display:flex;gap:8px;align-items:center;padding:10px;border:1px solid var(--line);border-radius:12px;margin:7px 0}.sl-service-row a{min-width:0;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text)}
/* Liquid-glass mini-app center and embedded services */
.sl-app-center-hero{display:flex;align-items:center;gap:14px;padding:18px;margin:4px 0 16px;border:1px solid rgba(190,160,255,.28);border-radius:22px;background:radial-gradient(circle at 12% 10%,rgba(167,139,250,.28),transparent 42%),linear-gradient(135deg,rgba(113,78,190,.22),rgba(35,25,65,.18));backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);box-shadow:inset 0 1px 0 rgba(255,255,255,.16),0 14px 38px rgba(40,18,90,.12)}
.sl-app-center-hero .val{font-size:19px;font-weight:850;letter-spacing:-.3px}.sl-app-center-hero p{font-size:12px;line-height:1.5;color:var(--muted);margin:5px 0 0}.sl-app-center-orb{flex:0 0 52px;width:52px;height:52px;display:grid;place-items:center;border-radius:17px;font-size:25px;color:#fff;background:linear-gradient(145deg,rgba(183,148,255,.72),rgba(106,75,190,.42));border:1px solid rgba(255,255,255,.28);box-shadow:inset 0 1px 0 rgba(255,255,255,.45),0 8px 24px rgba(119,75,210,.24)}
.sl-app-center-section{margin:16px 0}.sl-app-center-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 2px 9px}.sl-app-center-head b{font-size:14px}.sl-app-center-head span,.sl-app-center-head button{font-size:11px;color:var(--muted)}.sl-app-center-head button{color:var(--accent);font-weight:750}
.sl-service-app-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:10px}.sl-service-app-wrap{position:relative;min-width:0}.sl-service-app{width:100%;min-height:126px;padding:13px 8px 10px;border-radius:19px;border:1px solid rgba(190,170,230,.2);background:linear-gradient(145deg,rgba(255,255,255,.105),rgba(255,255,255,.025));backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;color:var(--text);box-shadow:inset 0 1px 0 rgba(255,255,255,.12);transition:transform .18s,border-color .18s}.sl-service-app:active{transform:scale(.97)}.sl-service-app:hover{border-color:rgba(174,139,255,.55)}.sl-service-app-icon{width:48px;height:48px;border-radius:15px;display:grid;place-items:center;background:linear-gradient(145deg,rgba(151,119,230,.22),rgba(255,255,255,.07));border:1px solid rgba(255,255,255,.13);overflow:hidden}.sl-service-app-icon img{width:30px;height:30px;object-fit:contain}.sl-service-app-icon i{font-style:normal;font-size:25px}.sl-service-app b{font-size:11px;line-height:1.25;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.sl-service-app small{font-size:9px;color:var(--muted);max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.sl-service-delete{position:absolute;right:5px;top:5px;width:24px;height:24px;border-radius:50%;border:1px solid var(--line);background:rgba(25,18,42,.7);color:#fff;z-index:2}.sl-service-add-card .field{margin:7px 0}.sl-service-hint{font-size:10px;color:var(--muted);margin:8px 0 0}
.sl-service-modal{position:fixed;z-index:100000;inset:0;padding:clamp(8px,2vw,24px);display:grid;place-items:center;background:rgba(9,6,19,.72);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px)}.sl-service-window{width:min(1240px,100%);height:min(850px,100%);min-height:min(480px,100%);display:flex;flex-direction:column;overflow:hidden;border-radius:23px;border:1px solid rgba(205,185,255,.3);background:linear-gradient(145deg,rgba(37,29,58,.97),rgba(17,14,29,.97));box-shadow:0 24px 90px rgba(0,0,0,.48),inset 0 1px 0 rgba(255,255,255,.16)}.sl-service-window-head{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 14px;border-bottom:1px solid rgba(255,255,255,.1);background:linear-gradient(100deg,rgba(151,112,230,.2),rgba(255,255,255,.035))}.sl-service-window-brand{display:flex;align-items:center;gap:10px;min-width:0}.sl-service-window-brand img,.sl-service-window-brand span{width:30px;height:30px;object-fit:contain;border-radius:9px}.sl-service-window-brand span{place-items:center;background:rgba(255,255,255,.1);font-size:19px}.sl-service-window-brand div{min-width:0}.sl-service-window-brand b,.sl-service-window-brand small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.sl-service-window-brand b{font-size:13px;color:#fff}.sl-service-window-brand small{font-size:10px;color:rgba(255,255,255,.6);margin-top:2px}.sl-service-close{width:36px;height:36px;flex:0 0 36px;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.09);color:#fff;font-size:17px}.sl-service-frame-wrap{position:relative;flex:1;min-height:0;background:#fff}.sl-service-frame-wrap iframe{position:absolute;inset:0;width:100%;height:100%;border:0;background:#fff}.sl-service-frame-loading{position:absolute;z-index:1;inset:0;display:grid;place-items:center;background:#171225;color:#eee;font-size:13px;pointer-events:none}.sl-service-window footer{flex:0 0 auto;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 13px;font-size:10px;color:rgba(255,255,255,.58);border-top:1px solid rgba(255,255,255,.1)}.sl-service-window footer a{color:#c6adff;font-weight:700;white-space:nowrap}
@media(max-width:600px){.sl-service-modal{padding:0}.sl-service-window{width:100%;height:100%;min-height:100%;border-radius:0;border:0}.sl-service-window-head{padding:10px 12px}.sl-service-window footer{font-size:9px}.sl-service-app-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.sl-service-app{min-height:112px}.sl-app-center-hero{padding:14px}}


/* StreamLive Smart Home + mini-app hub */
.home-feed{flex:1 1 0;min-height:0;min-width:0;padding:10px 12px 16px;display:flex;flex-direction:column;gap:14px;overflow-x:hidden;overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior-y:contain;touch-action:pan-y;scrollbar-width:thin}
.home-feed>*{flex-shrink:0}
.home-strip{touch-action:pan-x;overscroll-behavior-x:contain}
.home-welcome{padding:16px;border-radius:18px;background:linear-gradient(130deg,rgba(109,94,252,.28),rgba(139,92,246,.10));border:1px solid var(--line)}
.home-welcome .hw-title{font-size:19px;font-weight:800;margin-bottom:4px}.home-welcome .hw-sub{font-size:12px;color:var(--muted)}
.home-section{min-width:0}.home-section-head{display:flex;align-items:center;justify-content:space-between;margin:0 2px 8px}.home-section-head b{font-size:14px}.home-section-head button{font-size:12px;color:var(--accent);font-weight:700}
.home-strip{display:flex;gap:9px;overflow-x:auto;padding:1px 1px 5px;scrollbar-width:none}.home-strip::-webkit-scrollbar{display:none}
.home-tile{flex:0 0 142px;min-width:0;border:1px solid var(--line);border-radius:14px;background:var(--card);padding:10px;text-align:left;overflow:hidden}
.home-tile img{display:block;width:100%;height:76px;object-fit:cover;border-radius:9px;margin-bottom:8px;background:var(--card2)}
.home-tile .ht-title{font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.home-tile .ht-sub{font-size:10px;color:var(--muted);margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.home-empty{font-size:12px;color:var(--muted);padding:12px;border:1px dashed var(--line);border-radius:12px}
.apps-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;padding:12px 0}
.app-tile{min-height:94px;border:1px solid var(--line);background:var(--card);border-radius:16px;padding:12px 8px;text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px}
.app-tile .app-ico{display:grid;place-items:center;min-height:34px;min-width:34px;font-size:25px;line-height:1.2;font-family:"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif}.drawer-menu .ico{font-family:"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif}.app-tile b{font-size:11px;line-height:1.2}.app-tile:active,.home-tile:active{transform:scale(.98)}
.theme-light .seg{background:rgba(0,0,0,.06)}
.theme-light .seg button{color:#4a4260}
.theme-light .seg button.on{color:#fff;background:var(--accent)}
.theme-light .icon-btn{background:rgba(0,0,0,.06);color:#1a1228}
.theme-light .btn-primary{color:#fff}
.theme-light .prof-head{background:linear-gradient(180deg,#d4c4f0,transparent);color:#1a1228}
.theme-light .top{color:#1a1228}
.theme-light .drawer .item{color:#1a1228}
.theme-light .drawer .item .chev{color:#8a8298}
.theme-light .empty{color:#6b6280}
.theme-light .label{color:#6b6280}
.theme-light .val{color:#1a1228}
.theme-light .list{color:#1a1228}
.theme-light .row .title{color:#1a1228}
.theme-light .row .sub,.theme-light .row .meta{color:#6b6280}
.theme-light .switch{background:rgba(0,0,0,.15)}
.theme-light .switch.on{background:var(--accent)}

.theme-oled{
  --bg:#000;--card:#0a0a0a;--card2:#111;--text:#f2f2f2;--muted:#8a8a8a;
  --line:rgba(255,255,255,.08);--accent:#8b7cf8;--accent2:#a78bfa;
}
.theme-oled .col-list,
.theme-oled .col-detail,
.theme-oled .rail,
.theme-oled .drawer,
.theme-oled .detail-ov,
.theme-oled .search-ov,
.theme-oled .shell{background:#000 !important}
.theme-oled .col-list{background:#000 !important}
.theme-oled .nav-pill{background:rgba(20,20,20,.96);border-color:rgba(255,255,255,.1)}
.theme-oled .card{background:#0d0d0d;border-color:rgba(255,255,255,.08)}
.theme-oled .fab{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.2)}



.sl-limit-list{display:grid;gap:7px;margin-top:8px}.sl-limit-row{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:8px 10px;border-radius:10px;background:rgba(255,255,255,.045);font-size:12px}.sl-limit-row b{font-weight:700;text-align:right;color:var(--text)}
.msg-reacts{display:flex;gap:4px;flex-wrap:wrap;margin-top:4px}
.msg-react{font-size:12px;padding:2px 6px;border-radius:999px;background:rgba(255,255,255,.08);cursor:pointer}
.msg-react:hover{background:rgba(109,94,252,.35)}

/* messenger production */
.dm-bubble{max-width:85%;padding:10px 12px;border-radius:16px;font-size:14px;line-height:1.35;word-break:break-word}
.dm-bubble.mine{background:linear-gradient(135deg,rgba(109,94,252,.55),rgba(139,92,246,.4));border-bottom-right-radius:6px;margin-left:auto}
.dm-bubble.theirs{background:rgba(255,255,255,.08);border-bottom-left-radius:6px}
.dm-meta{font-size:10px;color:var(--muted);margin-top:4px}
.dm-reply-preview{font-size:11px;color:var(--muted);border-left:2px solid var(--accent);padding-left:8px;margin-bottom:6px}
.dm-stickers{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0}
.dm-stickers button{width:42px;height:42px;border-radius:12px;background:rgba(255,255,255,.08);font-size:22px;border:1px solid var(--line)}
.dm-stickers button:active{transform:scale(.94)}
/* YouTube-style age labels */
.age-badge{
  display:inline-flex;align-items:center;gap:4px;
  font-size:10px;font-weight:700;letter-spacing:.02em;
  padding:2px 6px;border-radius:2px;line-height:1.2;
  background:#606060;color:#fff;vertical-align:middle;flex-shrink:0
}
.age-badge.g6{background:#0f9d58}
.age-badge.g12{background:#f9ab00;color:#111}
.age-badge.g16{background:#e37400}
.age-badge.g18{background:#cc0000}
.age-badge.restricted{background:#606060;text-transform:none;font-weight:600;font-size:10px}
.yt-age-gate{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  text-align:center;padding:32px 20px;min-height:280px;
  background:#0f0f0f;border-radius:14px;border:1px solid rgba(255,255,255,.08)
}
.yt-age-gate .yt-ico{font-size:48px;margin-bottom:12px;opacity:.9}
.yt-age-gate h3{margin:0 0 8px;font-size:18px;font-weight:700}
.yt-age-gate p{margin:0 0 18px;color:#aaa;font-size:14px;line-height:1.45;max-width:320px}
.theme-light .yt-age-gate{background:#fff;border-color:rgba(0,0,0,.08)}
.theme-light .yt-age-gate p{color:#606060}
.theme-light .age-badge.restricted{background:#606060;color:#fff}

.dark-sticker-pack,.light-sticker-pack{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0;padding:9px;border-radius:14px;border:1px solid var(--line)}
.dark-sticker-pack{background:linear-gradient(135deg,rgba(10,8,16,.88),rgba(38,22,48,.72));border-color:rgba(190,150,220,.22)}
.light-sticker-pack{background:linear-gradient(135deg,rgba(255,255,255,.12),rgba(124,92,255,.10));border-color:rgba(180,160,255,.24)}
.dark-sticker-pack button,.light-sticker-pack button{width:44px;height:44px;border-radius:12px;border:1px solid var(--line);font-size:24px;cursor:pointer}
.dark-sticker-pack button{background:rgba(12,10,18,.9)}
.light-sticker-pack button{background:rgba(255,255,255,.10)}
.dark-sticker-pack button:active,.light-sticker-pack button:active{transform:scale(.92)}
.server-sticker-caption{font-size:11px;color:var(--muted);margin:2px 0 6px}
.qr-box{text-align:center;padding:12px;background:#0a0a0a;border-radius:14px;border:1px solid rgba(255,255,255,.1)}
.qr-box img{width:180px;height:180px;border-radius:8px;background:#fff}

/* StreamLive YouTube source selector + icon-first liquid glass navigation */
#sl-youtube-app .yt-source-wrap{display:flex;align-items:center;gap:8px;flex:0 0 auto}
#sl-youtube-app .yt-source-label{font-size:11px;color:var(--muted);white-space:nowrap}
#sl-youtube-app .yt-source-select{height:42px;max-width:190px;padding:0 34px 0 13px;border:1px solid rgba(190,165,255,.24);border-radius:14px;color:var(--text);background:linear-gradient(145deg,rgba(255,255,255,.10),rgba(124,92,255,.10));box-shadow:inset 0 1px 0 rgba(255,255,255,.10),0 8px 22px rgba(0,0,0,.12);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);font-size:12px;font-weight:650;outline:none}
#sl-youtube-app .yt-source-select:focus{border-color:rgba(190,165,255,.55);box-shadow:0 0 0 3px rgba(139,92,246,.16)}
#sl-youtube-app .yt-source-note{display:none;margin:10px 0 14px;padding:12px 14px;border:1px solid rgba(190,165,255,.20);border-radius:15px;background:linear-gradient(135deg,rgba(124,92,255,.13),rgba(255,255,255,.035));color:var(--muted);font-size:12px;line-height:1.5}
#sl-youtube-app .yt-source-note.on{display:block}
@media(min-width:641px){
 #sl-youtube-app .yt-side-group{display:grid;gap:5px;margin:10px 0;padding:11px 9px;border:1px solid rgba(194,169,255,.14);border-radius:20px;background:linear-gradient(145deg,rgba(255,255,255,.075),rgba(255,255,255,.018));box-shadow:inset 0 1px 0 rgba(255,255,255,.075),0 10px 28px rgba(0,0,0,.12);backdrop-filter:blur(22px);-webkit-backdrop-filter:blur(22px)}
 #sl-youtube-app .yt-side-btn{position:relative;min-height:46px;margin:0;padding:0 12px 0 10px;border:1px solid transparent;border-radius:15px;background:rgba(255,255,255,.025);gap:12px}
 #sl-youtube-app .yt-side-btn .ico{display:grid;place-items:center;flex:0 0 30px;width:30px;height:30px;border:1px solid rgba(255,255,255,.10);border-radius:11px;background:linear-gradient(145deg,rgba(255,255,255,.13),rgba(124,92,255,.10));box-shadow:inset 0 1px 0 rgba(255,255,255,.12),0 5px 14px rgba(0,0,0,.10);font-size:16px}
 #sl-youtube-app .yt-side-btn:hover .ico,#sl-youtube-app .yt-side-btn.active .ico{border-color:rgba(196,169,255,.30);background:linear-gradient(145deg,rgba(139,92,246,.35),rgba(255,255,255,.12));box-shadow:0 5px 18px rgba(93,63,165,.20),inset 0 1px 0 rgba(255,255,255,.16)}
 #sl-youtube-app .yt-side-btn.active{font-weight:750}
 #sl-youtube-app .yt-side-brand{padding:7px 10px 15px}
 #sl-youtube-app .yt-brand-ico{width:38px;height:34px;border:1px solid rgba(255,255,255,.18);border-radius:13px;box-shadow:inset 0 1px 0 rgba(255,255,255,.22),0 8px 24px rgba(139,92,246,.22)}
}
@media(min-width:641px) and (max-width:1024px){
 #sl-youtube-app{grid-template-columns:190px minmax(0,1fr)}
 #sl-youtube-app .yt-sidebar{padding:15px 10px}
 #sl-youtube-app .yt-side-btn{gap:9px;padding-left:8px;font-size:12px}
 #sl-youtube-app .yt-side-btn .ico{flex-basis:28px;width:28px;height:28px}
 #sl-youtube-app .yt-source-label{display:none}
 #sl-youtube-app .yt-source-select{max-width:155px}
}
@media(max-width:640px){
 #sl-youtube-app .yt-source-wrap{gap:5px}
 #sl-youtube-app .yt-source-label{display:none}
 #sl-youtube-app .yt-source-select{height:36px;max-width:118px;padding:0 25px 0 9px;border-radius:12px;font-size:10px}
 #sl-youtube-app .yt-source-note{font-size:11px;margin:8px 0 12px}
}

/* Desktop/tablet YouTube navigation: restore the floating liquid-glass tab treatment. */
@media(min-width:641px){
 #sl-youtube-app .yt-sidebar{padding:20px 13px;background:linear-gradient(160deg,rgba(25,17,43,.72),rgba(11,9,20,.52));border-right:1px solid rgba(196,169,255,.16);box-shadow:inset -1px 0 0 rgba(255,255,255,.025)}
 #sl-youtube-app .yt-side-group{margin:8px 0;padding:10px 8px;border:1px solid rgba(194,169,255,.13);border-radius:19px;background:linear-gradient(145deg,rgba(255,255,255,.075),rgba(255,255,255,.018));box-shadow:inset 0 1px 0 rgba(255,255,255,.075),0 10px 28px rgba(0,0,0,.12);backdrop-filter:blur(22px);-webkit-backdrop-filter:blur(22px)}
 #sl-youtube-app .yt-side-btn{min-height:44px;margin:3px 0;border:1px solid transparent;border-radius:14px;background:rgba(255,255,255,.025);transition:background .18s,border-color .18s,transform .18s,box-shadow .18s}
 #sl-youtube-app .yt-side-btn:hover{transform:translateX(2px);border-color:rgba(190,165,255,.2);background:rgba(255,255,255,.075);box-shadow:inset 0 1px 0 rgba(255,255,255,.07)}
 #sl-youtube-app .yt-side-btn.active{border-color:rgba(190,165,255,.28);background:linear-gradient(115deg,rgba(124,92,255,.30),rgba(167,139,250,.13));box-shadow:inset 0 1px 0 rgba(255,255,255,.09),0 6px 18px rgba(70,45,130,.14)}
 #sl-youtube-app .yt-cats{gap:10px;padding-top:14px}
 #sl-youtube-app .yt-cat{border-radius:999px;padding:10px 17px;background:linear-gradient(145deg,rgba(255,255,255,.075),rgba(255,255,255,.025));box-shadow:inset 0 1px 0 rgba(255,255,255,.08);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px)}
}
.srv-member-badges{display:flex;flex-wrap:wrap;align-items:center;gap:5px;margin-top:5px;min-width:0}.srv-badge-chip{display:inline-flex;align-items:center;gap:5px;max-width:100%;min-width:0;padding:4px 8px;border:1px solid rgba(190,165,255,.24);border-radius:999px;background:linear-gradient(135deg,rgba(139,92,246,.20),rgba(255,255,255,.055));color:var(--accent2);font-size:11px;font-weight:700;line-height:1.3;vertical-align:middle;white-space:normal;overflow-wrap:anywhere}.srv-member-row{align-items:flex-start!important;gap:10px!important;flex-wrap:wrap!important}.srv-custom-role-line{display:flex;align-items:center;gap:5px;flex-wrap:wrap;line-height:1.45;overflow-wrap:anywhere}.srv-custom-role{display:inline-block;min-width:0;max-width:100%;overflow-wrap:anywhere}.srv-member-main .title{display:flex;align-items:center;gap:6px;flex-wrap:wrap;line-height:1.4}.srv-member-main{flex:1 1 220px;min-width:0}.srv-member-actions{display:flex;flex:0 1 100%;justify-content:flex-end;align-items:center;gap:6px;flex-wrap:wrap;min-width:0}.srv-member-actions .field{flex:1 1 135px;max-width:180px!important;min-width:0}.srv-member-actions .fav-btn{flex:0 0 38px}@media(min-width:760px){.srv-member-actions{flex:0 1 auto;max-width:390px}.srv-member-actions .field{flex:0 1 150px}}

/* StreamLive YouTube — full YouTube-style Liquid Glass layout */
#sl-youtube-app{--yt-glass:rgba(27,19,43,.72);--yt-line:rgba(202,180,255,.13);display:grid;grid-template-columns:216px minmax(0,1fr);gap:0;width:100%;max-width:none;min-height:100%;margin:0;padding:0;color:var(--text);background:radial-gradient(ellipse at 55% -15%,rgba(124,92,255,.14),transparent 48%)}
#sl-youtube-app *{box-sizing:border-box}
#sl-youtube-app .yt-sidebar{position:sticky;top:0;align-self:start;height:calc(100dvh - 110px);min-height:420px;overflow:auto;padding:18px 12px;border-right:1px solid var(--yt-line);background:linear-gradient(180deg,rgba(30,20,49,.68),rgba(13,10,23,.34));backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px)}
#sl-youtube-app .yt-side-brand{display:flex;align-items:center;gap:10px;padding:5px 9px 18px;font-size:19px;font-weight:850;letter-spacing:-.5px}
#sl-youtube-app .yt-brand-ico{width:36px;height:28px;display:grid;place-items:center;border-radius:10px;background:linear-gradient(135deg,#ff315d,#a72cff);font-size:17px;box-shadow:0 7px 22px rgba(255,49,93,.22)}
#sl-youtube-app .yt-side-group{padding:10px 0;border-top:1px solid var(--yt-line)}
#sl-youtube-app .yt-side-btn{display:flex;align-items:center;gap:15px;width:100%;min-height:43px;padding:0 13px;border:0;border-radius:13px;background:transparent;color:var(--text);text-align:left;font-size:13px;font-weight:600;cursor:pointer}
#sl-youtube-app .yt-side-btn .ico{width:22px;text-align:center;font-size:17px}
#sl-youtube-app .yt-side-btn:hover,#sl-youtube-app .yt-side-btn.active{background:rgba(151,117,255,.18);box-shadow:inset 0 0 0 1px rgba(183,155,255,.12)}
#sl-youtube-app .yt-content{min-width:0;padding:0 clamp(12px,2vw,28px) 30px}
#sl-youtube-app .yt-top{position:sticky;top:0;z-index:20;display:flex;align-items:center;gap:15px;min-height:76px;padding:12px 0;border:0;border-radius:0;background:linear-gradient(180deg,rgba(13,9,22,.94),rgba(13,9,22,.72));backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px)}
#sl-youtube-app .yt-brand{display:none}
#sl-youtube-app .yt-search{display:flex;gap:0;flex:1;max-width:760px;min-width:0;margin:0 auto}
#sl-youtube-app .yt-search .field{height:46px;min-width:0;flex:1;margin:0;padding:0 20px;border:1px solid rgba(190,165,255,.24);border-right:0;border-radius:999px 0 0 999px;background:rgba(255,255,255,.065);box-shadow:inset 0 1px 0 rgba(255,255,255,.06);font-size:14px}
#sl-youtube-app .yt-search .btn-primary{height:46px;min-width:68px;margin:0;padding:0 22px;border:1px solid rgba(190,165,255,.24);border-radius:0 999px 999px 0;background:linear-gradient(135deg,rgba(124,92,255,.38),rgba(167,139,250,.2));color:#fff}
#sl-youtube-app .yt-quota{width:auto;max-width:220px;padding:8px 12px;border:1px solid var(--yt-line);border-radius:999px;background:rgba(255,255,255,.045);font-size:10px;color:var(--muted);text-align:center}
#sl-youtube-app .yt-main{min-width:0}
#sl-youtube-app .yt-cats{position:sticky;top:76px;z-index:15;display:flex;gap:9px;overflow-x:auto;padding:12px 0 17px;background:linear-gradient(180deg,rgba(13,9,22,.92),rgba(13,9,22,.72));backdrop-filter:blur(18px);scrollbar-width:none;overscroll-behavior-x:contain}
#sl-youtube-app .yt-cats::-webkit-scrollbar{display:none}
#sl-youtube-app .yt-cat{white-space:nowrap;border:1px solid var(--yt-line);background:rgba(255,255,255,.055);border-radius:11px;padding:9px 15px;font-size:12px;color:var(--text);font-weight:650;transition:.18s}
#sl-youtube-app .yt-cat.active,#sl-youtube-app .yt-cat:hover{background:linear-gradient(135deg,rgba(124,92,255,.42),rgba(167,139,250,.22));border-color:rgba(190,165,255,.42);color:#fff}
#sl-youtube-app .yt-section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:10px 2px 16px}
#sl-youtube-app .yt-section-head h3{font-size:19px;margin:0;font-weight:850;letter-spacing:-.3px}
#sl-youtube-app .yt-results{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:25px 17px}
#sl-youtube-app .yt-card{display:block;min-width:0;text-align:left;padding:0;border:0;border-radius:17px;overflow:hidden;background:transparent;color:inherit;cursor:pointer;transition:transform .18s}
#sl-youtube-app .yt-card:hover{transform:translateY(-3px)}
#sl-youtube-app .yt-thumb{display:block;width:100%;aspect-ratio:16/9;object-fit:cover;background:#09090d;border:1px solid var(--yt-line);border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,.18)}
#sl-youtube-app .yt-card-body{display:flex;gap:11px;padding:12px 2px 0;min-height:86px}
#sl-youtube-app .yt-channel-avatar{width:36px;height:36px;flex:0 0 36px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(135deg,#7147e8,#178cb8);font-size:14px;font-weight:800}
#sl-youtube-app .yt-card-copy{min-width:0;flex:1}
#sl-youtube-app .yt-card-title{font-size:13px;line-height:1.42;font-weight:750;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;overflow-wrap:anywhere}
#sl-youtube-app .yt-card-meta{font-size:11px;line-height:1.5;color:var(--muted);margin-top:6px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
#sl-youtube-app .yt-reaction-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:12px}
#sl-youtube-app .yt-reaction-bar button{border:1px solid var(--yt-line);border-radius:999px;padding:8px 13px;background:rgba(255,255,255,.045);color:var(--text);font-size:12px;cursor:pointer}
#sl-youtube-app .yt-reaction-bar button.active,#sl-youtube-app .yt-card-actions button.active{color:#fff;border-color:rgba(167,139,250,.65);background:rgba(124,92,255,.24)}
#sl-youtube-app .yt-player-tools{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
#sl-youtube-app .yt-source-loading{display:grid;place-items:center;width:100%;height:100%;min-height:190px;padding:20px;text-align:center;color:var(--muted);background:#08070c}
#sl-youtube-app .yt-about-toggle{margin-top:9px;border:1px solid var(--yt-line);border-radius:12px;padding:8px 12px;background:rgba(255,255,255,.045);color:var(--text);cursor:pointer;font-size:12px}
#sl-youtube-app .yt-about-panel{margin-top:8px;padding:12px 14px;border:1px solid var(--yt-line);border-radius:12px;background:rgba(255,255,255,.035);color:var(--muted);font-size:13px;line-height:1.55;white-space:pre-wrap}
#sl-youtube-app .yt-card-actions{display:flex;gap:6px;margin:7px 0 0 47px}
#sl-youtube-app .yt-card-actions button{border:1px solid var(--yt-line);border-radius:999px;padding:4px 9px;background:rgba(255,255,255,.045);color:var(--muted);font-size:10px}
#sl-youtube-app .yt-player-card{border:1px solid rgba(139,92,246,.3);border-radius:22px;padding:clamp(12px,2vw,20px);margin:8px 0 24px;background:linear-gradient(145deg,rgba(30,20,49,.92),rgba(12,10,22,.92));box-shadow:0 18px 50px rgba(0,0,0,.22);backdrop-filter:blur(22px)}
#sl-youtube-app .yt-player-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}
#sl-youtube-app .yt-player-title{font-size:clamp(16px,2vw,21px);font-weight:800;line-height:1.35;overflow-wrap:anywhere}
#sl-youtube-app .yt-frame{position:relative;width:100%;aspect-ratio:16/9;min-height:200px;max-height:min(72vh,820px);background:#000;border-radius:16px;overflow:hidden}
#sl-youtube-app .yt-frame iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
#sl-youtube-app .yt-open-original{font-size:12px;color:var(--accent2);text-decoration:none}
#sl-youtube-app .yt-empty{grid-column:1/-1;padding:38px 18px;border:1px solid var(--yt-line);border-radius:18px;text-align:center;color:var(--muted);background:rgba(255,255,255,.035)}
@media(max-width:1150px){#sl-youtube-app{grid-template-columns:180px minmax(0,1fr)}#sl-youtube-app .yt-results{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:640px){#sl-youtube-app{grid-template-columns:58px minmax(0,1fr)}#sl-youtube-app .yt-sidebar{height:calc(100dvh - 92px);padding:12px 5px}#sl-youtube-app .yt-side-brand{justify-content:center;padding:5px 0 14px;font-size:0}#sl-youtube-app .yt-side-btn{justify-content:center;padding:0;min-height:46px;font-size:0}#sl-youtube-app .yt-side-btn .ico{font-size:19px}#sl-youtube-app .yt-side-group{padding:8px 0}#sl-youtube-app .yt-content{padding:0 10px 22px}#sl-youtube-app .yt-top{min-height:64px;gap:6px}#sl-youtube-app .yt-quota{display:none}#sl-youtube-app .yt-cats{top:64px;padding:9px 0 13px}#sl-youtube-app .yt-results{grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 10px}#sl-youtube-app .yt-card-body{padding-top:8px;gap:7px}#sl-youtube-app .yt-channel-avatar{width:29px;height:29px;flex-basis:29px;font-size:11px}#sl-youtube-app .yt-card-title{font-size:12px}#sl-youtube-app .yt-card-meta{font-size:10px}#sl-youtube-app .yt-card-actions{margin-left:36px}}
@media(max-width:480px){#sl-youtube-app{grid-template-columns:48px minmax(0,1fr)}#sl-youtube-app .yt-sidebar{padding-left:3px;padding-right:3px}#sl-youtube-app .yt-content{padding-left:8px;padding-right:8px}#sl-youtube-app .yt-search .field{height:42px;padding:0 13px;font-size:12px}#sl-youtube-app .yt-search .btn-primary{height:42px;min-width:48px;padding:0 12px;font-size:12px}#sl-youtube-app .yt-results{grid-template-columns:minmax(0,1fr);gap:14px}#sl-youtube-app .yt-card{display:grid;grid-template-columns:43% minmax(0,1fr);align-items:start;column-gap:9px}#sl-youtube-app .yt-thumb{height:auto;min-height:92px;aspect-ratio:16/9}#sl-youtube-app .yt-card-body{padding:2px 0 0;min-height:0;gap:0}#sl-youtube-app .yt-channel-avatar{display:none}#sl-youtube-app .yt-card-title{font-size:12px;-webkit-line-clamp:3}#sl-youtube-app .yt-card-meta{font-size:10px;-webkit-line-clamp:2;margin-top:4px}#sl-youtube-app .yt-card-actions{grid-column:2;margin:3px 0 0}#sl-youtube-app .yt-player-card{padding:9px;border-radius:15px}#sl-youtube-app .yt-frame{border-radius:10px;max-height:none}}




/* StreamLive API protection + restored Services center glass tiles */
.apps-grid .app-tile{border:1px solid rgba(190,165,255,.18);background:linear-gradient(145deg,rgba(123,92,190,.14),rgba(255,255,255,.035));box-shadow:inset 0 1px 0 rgba(255,255,255,.07),0 8px 24px rgba(0,0,0,.08);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px);transition:transform .18s,border-color .18s,background .18s}
.apps-grid .app-tile:hover{transform:translateY(-2px);border-color:rgba(190,165,255,.34);background:linear-gradient(145deg,rgba(123,92,190,.23),rgba(255,255,255,.055))}

.sl-service-glass-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:12px}
.sl-service-glass-card{min-width:0;padding:16px;border:1px solid rgba(190,165,255,.2);border-radius:20px;background:linear-gradient(145deg,rgba(123,92,190,.19),rgba(255,255,255,.045) 55%,rgba(34,211,238,.045));box-shadow:inset 0 1px 0 rgba(255,255,255,.09),0 12px 34px rgba(0,0,0,.12);backdrop-filter:blur(22px);-webkit-backdrop-filter:blur(22px)}
.sl-service-glass-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:12px}
.sl-service-glass-icon{display:grid;place-items:center;width:42px;height:42px;border-radius:14px;background:linear-gradient(145deg,rgba(139,92,246,.3),rgba(255,255,255,.08));border:1px solid rgba(255,255,255,.13);font-size:23px}
.sl-service-glass-state{font-size:10px;padding:5px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:var(--muted)}
.sl-service-glass-state.published{color:#9be7c2;border-color:rgba(52,211,153,.22);background:rgba(52,211,153,.08)}
.sl-service-glass-title{display:block;font-size:15px;line-height:1.3;overflow-wrap:anywhere}
.sl-service-glass-meta{font-size:11px;color:var(--muted);margin-top:5px}
.sl-service-glass-desc{font-size:12px;line-height:1.45;color:var(--muted);min-height:34px;margin:10px 0 12px;overflow-wrap:anywhere}
.sl-service-glass-card .btn-primary{margin:0;width:100%;border-radius:13px}
/* Mobile YouTube is a full-width feed, not a squeezed desktop sidebar. */
@media(max-width:640px){
 #sl-youtube-app{display:block;min-height:0;width:100%;overflow:hidden}
 #sl-youtube-app .yt-sidebar{position:relative;top:auto;display:flex;align-items:center;gap:7px;width:100%;height:auto;min-height:0;max-height:none;overflow-x:auto;overflow-y:hidden;padding:8px 10px;border-right:0;border-bottom:1px solid var(--yt-line);scrollbar-width:none;overscroll-behavior-x:contain}
 #sl-youtube-app .yt-sidebar::-webkit-scrollbar{display:none}
 #sl-youtube-app .yt-side-brand{display:none}
 #sl-youtube-app .yt-side-group{display:flex;align-items:center;gap:6px;flex:0 0 auto;padding:0;border:0}
 #sl-youtube-app .yt-side-btn{display:flex;align-items:center;justify-content:center;gap:7px;width:auto;min-width:max-content;min-height:36px;padding:0 11px;border:1px solid var(--yt-line);border-radius:999px;background:rgba(255,255,255,.045);font-size:11px;white-space:nowrap}
 #sl-youtube-app .yt-side-btn .ico{width:auto;font-size:14px}
 #sl-youtube-app .yt-content{padding:0 12px 24px}
 #sl-youtube-app .yt-top{position:sticky;top:0;min-height:58px;padding:8px 0;gap:8px}
 #sl-youtube-app .yt-quota{display:none}
 #sl-youtube-app .yt-cats{top:58px;padding:8px 0 12px;gap:7px}
 #sl-youtube-app .yt-cat{padding:8px 12px;font-size:11px;border-radius:999px}
 #sl-youtube-app .yt-results{grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 12px}
 #sl-youtube-app .yt-card{display:block;overflow:visible;border-radius:14px}
 #sl-youtube-app .yt-thumb{width:100%;height:auto;min-height:0;aspect-ratio:16/9;border-radius:13px}
 #sl-youtube-app .yt-card-body{display:flex;gap:8px;padding:8px 1px 0;min-height:0}
 #sl-youtube-app .yt-channel-avatar{display:grid;width:28px;height:28px;flex:0 0 28px;font-size:11px}
 #sl-youtube-app .yt-card-title{font-size:12px;line-height:1.35;-webkit-line-clamp:3}
 #sl-youtube-app .yt-card-meta{font-size:10px;line-height:1.35;margin-top:4px;-webkit-line-clamp:2}
 #sl-youtube-app .yt-card-actions{margin:7px 0 0 36px}
 #sl-youtube-app .yt-player-card{margin:8px 0 18px;padding:12px;border-radius:17px}
 #sl-youtube-app .yt-frame{min-height:0;max-height:none;aspect-ratio:16/9;border-radius:12px}
 #sl-youtube-app .yt-player-head{flex-direction:column;gap:7px}
}
@media(max-width:480px){
 #sl-youtube-app .yt-content{padding:0 10px 22px}
 #sl-youtube-app .yt-top{min-height:54px}
 #sl-youtube-app .yt-search .field{height:42px;padding:0 14px;font-size:13px;border-radius:999px 0 0 999px}
 #sl-youtube-app .yt-search .btn-primary{height:42px;min-width:48px;padding:0 13px;border-radius:0 999px 999px 0}
 #sl-youtube-app .yt-cats{top:54px}
 #sl-youtube-app .yt-results{grid-template-columns:minmax(0,1fr);gap:20px}
 #sl-youtube-app .yt-card{display:block}
 #sl-youtube-app .yt-thumb{width:100%;aspect-ratio:16/9;border-radius:14px}
 #sl-youtube-app .yt-card-body{padding:9px 2px 0;gap:10px}
 #sl-youtube-app .yt-channel-avatar{display:grid;width:34px;height:34px;flex-basis:34px;font-size:13px}
 #sl-youtube-app .yt-card-title{font-size:14px;line-height:1.4;-webkit-line-clamp:2}
 #sl-youtube-app .yt-card-meta{font-size:11px;line-height:1.4;margin-top:5px;-webkit-line-clamp:2}
 #sl-youtube-app .yt-card-actions{margin:8px 0 0 44px}
 #sl-youtube-app .yt-player-card{padding:9px;border-radius:14px}
 #sl-youtube-app .yt-player-title{font-size:15px}
 .sl-service-glass-grid{grid-template-columns:minmax(0,1fr);gap:10px}
 .sl-service-glass-card{padding:14px;border-radius:17px}
 .apps-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}
 .app-tile{min-height:88px;border-radius:17px;background:linear-gradient(145deg,rgba(139,92,246,.13),rgba(255,255,255,.045));backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px)}
}


/* StreamLive app-center restoration: polished glass tiles and responsive cards */
.apps-grid .app-tile{border:1px solid rgba(190,165,255,.2);background:linear-gradient(145deg,rgba(123,92,190,.18),rgba(255,255,255,.045));box-shadow:inset 0 1px 0 rgba(255,255,255,.09),0 10px 28px rgba(0,0,0,.1);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);transition:transform .18s,border-color .18s,background .18s}
.apps-grid .app-tile:hover{transform:translateY(-2px);border-color:rgba(190,165,255,.4)}
@media(max-width:640px){.sl-service-app-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.sl-service-app{min-height:108px}.sl-app-center-hero{padding:14px}}


/* StreamLive mobile YouTube: compact top navigation, native feed, no desktop sidebar squeeze. */
@media(max-width:640px){
 #sl-youtube-app{display:block!important;grid-template-columns:minmax(0,1fr)!important;width:100%;min-height:0;overflow:visible}
 #sl-youtube-app .yt-sidebar{position:sticky!important;z-index:31;left:auto;right:auto;bottom:auto;top:0!important;width:100%!important;height:auto!important;min-height:0;max-height:none;display:flex!important;align-items:center;justify-content:flex-start;gap:7px;overflow-x:auto;overflow-y:hidden;padding:8px 10px;border:0;border-bottom:1px solid rgba(190,165,255,.18);border-radius:0;background:rgba(17,13,27,.94);box-shadow:0 8px 22px rgba(0,0,0,.14);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);scrollbar-width:none;overscroll-behavior-x:contain}
 #sl-youtube-app .yt-sidebar::-webkit-scrollbar{display:none}
 #sl-youtube-app .yt-side-brand{display:flex!important;flex:0 0 auto;gap:7px;padding:0 7px 0 0;font-size:12px;white-space:nowrap}
 #sl-youtube-app .yt-brand-ico{width:27px;height:23px;border-radius:8px;font-size:13px}
 #sl-youtube-app .yt-side-group{display:flex!important;flex:0 0 auto;align-items:center;gap:6px;padding:0;border:0}
 #sl-youtube-app .yt-side-btn{display:flex!important;flex:0 0 auto;width:auto;min-width:max-content;min-height:34px;height:34px;flex-direction:row;align-items:center;justify-content:center;gap:6px;padding:0 11px!important;border:1px solid rgba(190,165,255,.18)!important;border-radius:999px!important;background:rgba(255,255,255,.045)!important;font-size:11px!important;line-height:1.15;white-space:nowrap;color:var(--muted)}
 #sl-youtube-app .yt-side-btn .ico{display:block;width:auto;font-size:13px;line-height:1.1}
 #sl-youtube-app .yt-side-btn.active{color:#fff;background:linear-gradient(135deg,rgba(139,92,246,.34),rgba(255,255,255,.08))!important;border-color:rgba(190,165,255,.32)!important}
 #sl-youtube-app .yt-content{width:100%;min-width:0;padding:0 0 22px}
 #sl-youtube-app .yt-top{position:sticky;top:50px;z-index:30;display:flex;align-items:center;gap:8px;min-height:56px;padding:8px 11px;background:rgba(15,11,23,.94);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px)}
 #sl-youtube-app .yt-top:before{content:none!important;display:none!important}
 #sl-youtube-app .yt-search{display:flex;flex:1;min-width:0;max-width:none}
 #sl-youtube-app .yt-search .field{min-width:0;width:100%;height:42px;padding:0 14px;font-size:13px;border-radius:999px 0 0 999px}
 #sl-youtube-app .yt-search .btn-primary{height:42px;min-width:46px;padding:0 13px;border-radius:0 999px 999px 0}
 #sl-youtube-app .yt-quota{display:block!important;flex:0 0 auto;max-width:104px;padding:6px 8px;font-size:9px;white-space:normal}
 #sl-youtube-app .yt-main{width:100%;min-width:0}
 #sl-youtube-app .yt-cats{position:sticky;top:106px;z-index:25;margin:0;padding:8px 11px 11px;gap:7px;background:rgba(15,11,23,.92);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px)}
 #sl-youtube-app .yt-cat{flex:0 0 auto;padding:8px 12px;border-radius:999px;font-size:11px}
 #sl-youtube-app .yt-section-head{margin:14px 12px 12px}
 #sl-youtube-app .yt-section-head h3{font-size:17px}
 #sl-youtube-app .yt-results{grid-template-columns:minmax(0,1fr)!important;gap:18px;padding:0 0 12px}
 #sl-youtube-app .yt-card{display:block;width:100%;border-radius:0;overflow:visible}
 #sl-youtube-app .yt-card-open{display:block;width:100%;}
 #sl-youtube-app .yt-thumb{display:block;width:100%;height:auto;min-height:0;aspect-ratio:16/9;border:0;border-radius:0;box-shadow:none;object-fit:cover}
 #sl-youtube-app .yt-card-body{display:flex;gap:10px;padding:9px 12px 0;min-height:0}
 #sl-youtube-app .yt-channel-avatar{display:grid;width:34px;height:34px;flex:0 0 34px;font-size:13px}
 #sl-youtube-app .yt-card-title{font-size:13px;line-height:1.4;-webkit-line-clamp:2}
 #sl-youtube-app .yt-card-meta{font-size:10px;line-height:1.4;margin-top:4px;-webkit-line-clamp:2}
 #sl-youtube-app .yt-card-actions{margin:6px 12px 0 56px}
 #sl-youtube-app .yt-player-card{margin:8px 10px 18px;padding:10px;border-radius:14px}
 #sl-youtube-app .yt-frame{min-height:0;max-height:none;aspect-ratio:16/9;border-radius:10px}
 #sl-youtube-app .yt-player-title{font-size:15px}
}
@media(max-width:420px){
 #sl-youtube-app .yt-side-brand{font-size:0;padding-right:2px}
 #sl-youtube-app .yt-side-brand .yt-brand-ico{width:30px;height:28px}
 #sl-youtube-app .yt-quota{max-width:82px;font-size:8px}
 #sl-youtube-app .yt-top{top:46px}
 #sl-youtube-app .yt-cats{top:102px}
 #sl-youtube-app .yt-results{gap:16px}
 #sl-youtube-app .yt-card-title{font-size:12px}
}


.srv-chat-mention{display:inline-flex;align-items:center;padding:1px 5px;border-radius:6px;background:rgba(139,92,246,.2);color:var(--accent2);font-weight:750}
@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important;animation-duration:.01ms!important;transition-duration:.01ms!important}}
</style>
</head>
<body>
<div id="sl-offline-gate" role="alertdialog" aria-modal="true" aria-live="assertive" style="display:none;position:fixed;inset:0;z-index:2147483000;align-items:center;justify-content:center;padding:24px;background:rgba(5,7,16,.97);backdrop-filter:blur(24px);color:#fff;text-align:center">
  <div style="width:min(420px,100%);padding:30px 24px;border:1px solid rgba(167,139,250,.32);border-radius:24px;background:linear-gradient(145deg,rgba(34,27,62,.98),rgba(12,17,34,.99));box-shadow:0 24px 90px rgba(0,0,0,.55)">
    <div style="font-size:48px;margin-bottom:14px">📡</div><h2 style="margin:0 0 10px;font-size:22px">Нет подключения к интернету</h2>
    <p style="margin:0 0 20px;color:#c7c4d8;line-height:1.55">StreamLive временно недоступен. Включи Wi-Fi или мобильный интернет. После восстановления связи страница обновится автоматически.</p>
    <div id="sl-offline-state" style="font-size:12px;color:#a78bfa;margin-bottom:14px">Ожидаем подключение…</div>
    <button type="button" id="sl-offline-retry" style="border:0;border-radius:12px;padding:11px 20px;background:linear-gradient(100deg,#7657e8,#a855f7);color:white;font-weight:700">Проверить и обновить</button>
  </div>
</div>
<script>(function(){var gate,btn,reloadTimer=null;function show(){if(!gate)gate=document.getElementById('sl-offline-gate');if(gate)gate.style.display=navigator.onLine?'none':'flex';document.documentElement.dataset.connection=navigator.onLine?'online':'offline';document.body&&document.body.setAttribute('aria-busy',navigator.onLine?'false':'true');}function retry(){if(!navigator.onLine){show();return;}var st=document.getElementById('sl-offline-state');if(st)st.textContent='Соединение восстановлено. Обновляем StreamLive…';if(reloadTimer)clearTimeout(reloadTimer);reloadTimer=setTimeout(function(){location.reload();},650);}function init(){gate=document.getElementById('sl-offline-gate');btn=document.getElementById('sl-offline-retry');if(btn)btn.addEventListener('click',retry);show();}window.addEventListener('offline',show);window.addEventListener('online',retry);document.addEventListener('DOMContentLoaded',init);if(document.readyState!=='loading')init();})();</script>

<div class="tut-ov" id="tut-ov" role="dialog" aria-modal="true">
  <div class="tut-box">
    <div class="step-n" id="tut-step-n">Шаг 1</div>
    <h2 id="tut-title">Добро пожаловать</h2>
    <div id="tut-body"></div>
    <div class="tut-dots" id="tut-dots"></div>
    <div class="tut-actions">
      <button type="button" class="skip" id="tut-skip">Пропустить</button>
      <button type="button" class="next" id="tut-next">Далее</button>
    </div>
  </div>
</div>

<div id="splash"><div class="logo">StreamLive</div><div class="spin"></div><div style="color:var(--muted);font-size:13px">Загрузка SPA…</div></div>
<div class="update-bar" id="update-bar"><span>Доступна новая версия StreamLive SPA</span>
  <button type="button" id="update-reload">Обновить</button>
  <button type="button" class="ghost" id="update-later">Позже</button>
</div>

<div class="shell">
  <aside class="rail" id="rail">
    <button class="rbtn on" data-sc="chats" title="Каталог">💬</button>
    <button class="rbtn" data-sc="online" title="Онлайн">🟢</button>
    <button class="rbtn" data-sc="clan" title="Сервер">🏰</button>
    <button class="rbtn" data-sc="profile" title="Профиль">👤</button>
    <button class="rbtn" id="rail-server" title="Мой сервер" style="display:none">🏰</button>
    <button class="rbtn rplus" id="rail-add" title="Сервер">＋</button>
    <div style="flex:1"></div>
    <button class="rbtn" id="rail-menu" title="Меню">☰</button>
  </aside>

  <div class="col-list" id="app">
    <div class="screen" id="sc-auth">
      <div class="auth-wrap">
        <div aria-hidden="true" style="font-size:13px;letter-spacing:3px;color:#c9baff;margin-bottom:12px">✦ STREAMLIVE · YOUR SPACE</div>
        <h1 style="margin:0 0 6px">StreamLive</h1>
        <p style="color:var(--muted);margin:0 0 20px;font-size:14px">Каталог, профили, сторис и комментарии</p>
        <input class="field" id="login-user" placeholder="Телефон или username" autocomplete="username">
        <input class="field" id="login-pass" type="password" placeholder="Пароль" autocomplete="current-password">
        <div class="err" id="login-err"></div>
        <button class="btn-primary" id="login-btn">Войти</button>
      </div>
    </div>

    <div class="screen" id="sc-chats">
      <div class="top">
        <button class="icon-btn menu-only" id="btn-menu" type="button">☰</button>
        <input class="search-mini" id="q-mini" placeholder="Поиск" readonly>
        <button class="icon-btn menu-only" id="btn-clan-mobile" type="button" title="Сервер">🏰</button>
        <button class="icon-btn" id="btn-refresh" type="button">↻</button>
      </div>
      <div class="home-feed" id="home-feed">
        <div class="home-welcome"><div class="hw-title">Твоя StreamLive</div><div class="hw-sub">Всё, что тебе интересно — в одном месте</div></div>
        <section class="home-section"><div class="home-section-head"><b>▶ Продолжить просмотр</b><button type="button" data-home-go="continue">Всё</button></div><div class="home-strip" id="home-continue"><div class="home-empty">Здесь появятся твои просмотры</div></div></section>
        <section class="home-section"><div class="home-section-head"><b>🕑 Недавно открывали</b><button type="button" data-home-go="history">История</button></div><div class="home-strip" id="home-recent"><div class="home-empty">Пока нет истории</div></div></section>
        <section class="home-section"><div class="home-section-head"><b>✨ Новое на StreamLive</b><button type="button" data-home-go="catalog">Каталог</button></div><div class="home-strip" id="home-new"><div class="home-empty">Загружаем свежие публикации…</div></div></section>
        <section class="home-section"><div class="home-section-head"><b>🧩 Мини-приложения</b><button type="button" data-home-go="apps">Все приложения</button></div><div class="apps-grid" id="home-apps"></div></section>
      </div>
      <div class="seg" id="filter-seg" style="margin:0 12px 8px">
        <button type="button" data-f="all" class="on">Все</button>
        <button type="button" data-f="users">Люди</button>
        <button type="button" data-f="channels">Каналы</button>
        <button type="button" data-f="videos">Видео</button>
      </div>
      <div class="stories" id="stories"></div>
      <div class="list" id="list-catalog"></div>
    </div>

    <div class="screen" id="sc-online">
      <div class="top">
        <button class="icon-btn menu-only" id="btn-menu-2" type="button">☰</button>
        <div style="font-weight:700;font-size:17px;flex:1">Сейчас в сети</div>
        <button class="icon-btn" id="btn-refresh-online" type="button">↻</button>
      </div>
      <div class="list" id="list-online"></div>
    </div>

    <div class="screen" id="sc-profile">
      <div class="top">
        <button class="icon-btn menu-only" id="btn-menu-3" type="button">☰</button>
        <div style="font-weight:700;font-size:17px;flex:1">Профиль</div>
      </div>
      <div class="list" id="profile-body"></div>
    </div>

    <!-- Corvox-style menu -->
    <div class="drawer-ov" id="drawer-ov">
      <div class="drawer" id="drawer" onclick="event.stopPropagation()">
        <div class="drawer-head">
          <img id="dr-av" src="" alt="">
          <div class="name" id="dr-name">—</div>
          <div class="handle" id="dr-handle">@—</div>
        </div>
        <div class="drawer-menu">
          <button type="button" class="item" data-go="profile"><span class="ico b">👤</span> Мой профиль <span class="chev">›</span></button>
          <button type="button" class="item" data-go="online"><span class="ico p">🟢</span> Кто в сети <span class="chev">›</span></button>
          <button type="button" class="item" data-go="clan"><span class="ico a">🏰</span> Серверы <span class="chev">›</span></button>
          <button type="button" class="item" data-go="favs"><span class="ico o">🔖</span> Избранное <span class="chev">›</span></button>
          <button type="button" class="item" data-go="settings"><span class="ico g">⚙</span> Настройки <span class="chev">›</span></button>
          <div class="drawer-toggle"><span style="display:flex;align-items:center;gap:12px"><span class="ico n">🌙</span> Ночной режим</span>
            <button type="button" class="switch on" id="theme-sw"><i></i></button>
          </div>
          <button type="button" class="item" data-go="sub"><span class="ico a">🎁</span> Подписка SPA <span class="chev">›</span></button>
          <button type="button" class="item" data-go="passport"><span class="ico o">🛂</span> Паспорт Мира <span class="chev">›</span></button>
          <button type="button" class="item" data-go="geo"><span class="ico g">🌍</span> Гео / страна <span class="chev">›</span></button>
          <button type="button" class="item" data-go="family"><span class="ico b">👨‍👩‍👧‍👦</span> Семейные профили <span class="chev">›</span></button>
          <button type="button" class="item" data-go="apps"><span class="ico p">🧩</span> Мини-приложения <span class="chev">›</span></button>
          <button type="button" class="item" data-go="continue"><span class="ico p">▶</span> Продолжить просмотр <span class="chev">›</span></button>
          <button type="button" class="item" data-go="cinema"><span class="ico o">🎬</span> Фильмы и сериалы <span class="chev">›</span></button>
          <button type="button" class="item" data-go="music"><span class="ico a">🎵</span> Музыка <span class="chev">›</span></button>
          <button type="button" class="item" data-go="achievements"><span class="ico o">🏆</span> Ачивки <span class="chev">›</span></button>
          <button type="button" class="item" data-go="mood"><span class="ico a">😎</span> Настроение <span class="chev">›</span></button>
          <button type="button" class="item" data-go="messenger"><span class="ico b">💬</span> Сообщения <span class="chev">›</span></button>
          <button type="button" class="item" data-go="quests"><span class="ico a">⚡</span> Квесты дня <span class="chev">›</span></button>
          <button type="button" class="item" data-go="tutorial"><span class="ico b">📖</span> Туториал <span class="chev">›</span></button>
          <button type="button" class="item" data-go="notes"><span class="ico o">📝</span> Заметки <span class="chev">›</span></button>
          <button type="button" class="item" data-go="tip"><span class="ico a">💡</span> Совет дня <span class="chev">›</span></button>
          <button type="button" class="item" data-go="history"><span class="ico g">🕑</span> История 24ч <span class="chev">›</span></button>
        </div>
        <div class="drawer-foot"><span id="dr-build-line">StreamLive SPA · сборка <span id="dr-build"></span></span><br>
          <button type="button" id="drawer-logout" style="color:var(--danger);margin-top:10px;font-weight:600">Выйти</button>
        </div>
      </div>
    </div>

    <div class="search-ov" id="search-ov">
      <div class="top">
        <input class="search-mini" id="search-q" placeholder="Фильмы, сериалы, люди, каналы…" aria-label="Единый поиск StreamLive" style="flex:1">
        <button class="icon-btn" id="search-close" type="button">✕</button>
      </div>
      <div class="seg" id="search-tabs" style="margin:0 12px 8px">
        <button type="button" data-t="all" class="on">Все</button>
        <button type="button" data-t="users">Люди</button>
        <button type="button" data-t="channels">Каналы</button>
        <button type="button" data-t="videos">Видео</button>
        <button type="button" data-t="films">Кино</button>
      </div>
      <div class="search-start" id="search-start"></div>
      <div class="list" id="list-search" style="display:none"></div>
    </div>

    <!-- mobile detail overlay -->
    <div class="detail-ov" id="detail-ov">
      <div class="detail-top">
        <button class="icon-btn" id="detail-back" type="button">←</button>
        <div id="detail-title" style="font-weight:700;flex:1">Просмотр</div>
      </div>
      <div class="detail-body" id="detail-body"></div>
    </div>

    <div class="nav" id="main-nav" style="display:none">
      <div class="nav-pill">
        <button type="button" data-sc="chats" class="on">💬<span>Каталог</span></button>
        <button type="button" data-sc="online">🟢<span>Онлайн</span></button>
        <button type="button" data-sc="clan">🏰<span>Сервер</span></button>
        <button type="button" data-sc="profile">👤<span>Профиль</span></button>
      </div>
      <button class="fab" id="btn-search" type="button">🔍</button>
    </div>
  </div>

  <div class="col-detail" id="col-detail">
    <div class="detail-pane-empty" id="pane-empty">
      <h2>Выберите элемент</h2>
      <p>Каталог слева — профили, каналы и видео</p>
    </div>
    <div class="detail-body" id="pane-body" style="display:none;padding:16px;overflow-y:auto;flex:1"></div>
  </div>
</div>

<script>
const BOOT = {
  user: <?= json_encode($bootUser, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  streak: <?= json_encode($bootStreak, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
  has_sub: <?= $bootSub ? 'true' : 'false' ?>,
  build: <?= json_encode(SPA_BUILD) ?>,
  self: <?= json_encode((function() {
  $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/streamspa.php'));
  $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
  if ($dir === '/' || $dir === '.' || $dir === '') $dir = '';
  // всегда имя streamspa.php в той же папке (не aes.php)
  return ($dir === '' ? '' : $dir) . '/streamspa.php';
})(), JSON_UNESCAPED_SLASHES) ?>
};
window.onerror = function(msg, src, line){ try { console.error('SPA', msg, line); } catch(e) {} };
const state = { user: BOOT.user, streak: BOOT.streak, has_sub: BOOT.has_sub, filter: 'all', screen: 'chats', searchType: 'all', dark: true };

const I18N = {
  ru: {
    catalog:'Каталог', online:'Кто в сети', clan:'Сервер', profile:'Мой профиль',
    settings:'Настройки', cinema:'Фильмы и сериалы', music:'Музыка',
    continue:'Продолжить', passport:'Паспорт Мира', family:'Семья', geo:'Гео',
    tutorial:'Туториал', notes:'Заметки', tip:'Совет дня', favs:'Избранное', sub:'Подписка SPA',
    theme:'Ночной режим', sound:'Звук интерфейса', save:'Сохранить',
    lang:'Язык интерфейса', kp_key:'Кинопоиск API', need_login:'Нужен вход',
    empty:'Пусто', loading:'Загрузка…',
    messenger:'Сообщения', quests:'Квесты дня', achievements:'Ачивки', mood:'Настроение',
    servers:'Серверы', stickers:'Стикеры', playlist:'Плейлисты', now_on_page:'Сейчас на странице', now:'Сейчас', no_data:'нет данных', channels:'Каналы', videos:'Видео'
  },
  en: {
    catalog:'Catalog', online:'Online', clan:'Server', profile:'My profile',
    settings:'Settings', cinema:'Movies & series', music:'Music',
    continue:'Continue', passport:'World passport', family:'Family', geo:'Geo',
    tutorial:'Tutorial', notes:'Notes', tip:'Daily tip', favs:'Favorites', sub:'SPA subscription',
    theme:'Dark mode', sound:'UI sounds', save:'Save',
    lang:'Interface language', kp_key:'Kinopoisk API', need_login:'Login required',
    empty:'Empty', loading:'Loading…',
    messenger:'Messages', quests:'Daily quests', achievements:'Achievements', mood:'Mood',
    servers:'Servers', stickers:'Stickers', playlist:'Playlists', now_on_page:'Currently viewing', now:'Now', no_data:'No data', channels:'Channels', videos:'Videos'
  },
  uk: {
    catalog:'Каталог', online:'Хто в мережі', clan:'Сервер', profile:'Мій профіль',
    settings:'Налаштування', cinema:'Фільми та серіали', music:'Музика',
    continue:'Продовжити', passport:'Паспорт Світу', family:"Сім'я", geo:'Гео',
    tutorial:'Туторіал', notes:'Нотатки', tip:'Порада дня', favs:'Обране', sub:'Підписка SPA',
    theme:'Нічний режим', sound:'Звуки інтерфейсу', save:'Зберегти',
    lang:'Мова інтерфейсу', kp_key:'Кінопошук API', need_login:'Потрібен вхід',
    empty:'Порожньо', loading:'Завантаження…',
    messenger:'Повідомлення', quests:'Квести дня', achievements:'Ачівки', mood:'Настрій',
    servers:'Сервери', stickers:'Стікери', playlist:'Плейлисти', now_on_page:'Зараз на сторінці', now:'Зараз', no_data:'Немає даних', channels:'Канали', videos:'Відео'
  }
};
const SPA_LOCALES = {
  ru:'Русский', en:'English', uk:'Українська', 'zh-CN':'简体中文', 'zh-TW':'繁體中文', 'ko-KR':'한국어',
  ja:'日本語', es:'Español', de:'Deutsch', fr:'Français', pt:'Português', ar:'العربية'
};
const SPA_CORE_I18N = {
  'zh-CN':{catalog:'目录',online:'在线',clan:'服务器',profile:'我的资料',settings:'设置',cinema:'电影和剧集',music:'音乐',continue:'继续观看',passport:'世界护照',family:'家庭',geo:'位置',tutorial:'使用教程',notes:'笔记',tip:'每日提示',favs:'收藏',sub:'SPA 订阅',theme:'深色模式',sound:'界面音效',save:'保存',lang:'界面语言',need_login:'请先登录',empty:'暂无内容',loading:'加载中…',messenger:'消息',quests:'每日任务',achievements:'成就',servers:'服务器',stickers:'贴纸',playlist:'播放列表',channels:'频道',videos:'视频'},
  'zh-TW':{catalog:'目錄',online:'線上',clan:'伺服器',profile:'我的個人資料',settings:'設定',cinema:'電影與影集',music:'音樂',continue:'繼續觀看',passport:'世界護照',family:'家庭',geo:'位置',tutorial:'使用教學',notes:'筆記',tip:'每日提示',favs:'收藏',sub:'SPA 訂閱',theme:'深色模式',sound:'介面音效',save:'儲存',lang:'介面語言',need_login:'請先登入',empty:'目前沒有內容',loading:'載入中…',messenger:'訊息',quests:'每日任務',achievements:'成就',servers:'伺服器',stickers:'貼圖',playlist:'播放清單',channels:'頻道',videos:'影片'},
  'ko-KR':{catalog:'카탈로그',online:'온라인',clan:'서버',profile:'내 프로필',settings:'설정',cinema:'영화 및 시리즈',music:'음악',continue:'이어보기',passport:'월드 패스포트',family:'가족',geo:'위치',tutorial:'사용 안내',notes:'메모',tip:'오늘의 팁',favs:'즐겨찾기',sub:'SPA 구독',theme:'다크 모드',sound:'인터페이스 사운드',save:'저장',lang:'인터페이스 언어',need_login:'로그인이 필요합니다',empty:'비어 있음',loading:'불러오는 중…',messenger:'메시지',quests:'일일 퀘스트',achievements:'업적',servers:'서버',stickers:'스티커',playlist:'재생목록',channels:'채널',videos:'동영상'},
  ja:{catalog:'カタログ',online:'オンライン',clan:'サーバー',profile:'マイプロフィール',settings:'設定',cinema:'映画・シリーズ',music:'音楽',continue:'続きを見る',passport:'ワールドパスポート',family:'ファミリー',geo:'位置情報',tutorial:'チュートリアル',notes:'メモ',tip:'今日のヒント',favs:'お気に入り',sub:'SPAサブスクリプション',theme:'ダークモード',sound:'UIサウンド',save:'保存',lang:'表示言語',need_login:'ログインが必要です',empty:'空です',loading:'読み込み中…',messenger:'メッセージ',quests:'デイリークエスト',achievements:'実績',servers:'サーバー',stickers:'スタンプ',playlist:'プレイリスト',channels:'チャンネル',videos:'動画'},
  es:{catalog:'Catálogo',online:'En línea',clan:'Servidor',profile:'Mi perfil',settings:'Ajustes',cinema:'Películas y series',music:'Música',continue:'Continuar viendo',passport:'Pasaporte mundial',family:'Familia',geo:'Ubicación',tutorial:'Tutorial',notes:'Notas',tip:'Consejo del día',favs:'Favoritos',sub:'Suscripción SPA',theme:'Modo oscuro',sound:'Sonidos de interfaz',save:'Guardar',lang:'Idioma de interfaz',need_login:'Inicia sesión',empty:'Vacío',loading:'Cargando…',messenger:'Mensajes',quests:'Misiones diarias',achievements:'Logros',servers:'Servidores',stickers:'Pegatinas',playlist:'Listas de reproducción',channels:'Canales',videos:'Vídeos'},
  de:{catalog:'Katalog',online:'Online',clan:'Server',profile:'Mein Profil',settings:'Einstellungen',cinema:'Filme und Serien',music:'Musik',continue:'Weiterschauen',passport:'Weltpass',family:'Familie',geo:'Standort',tutorial:'Einführung',notes:'Notizen',tip:'Tipp des Tages',favs:'Favoriten',sub:'SPA-Abonnement',theme:'Dunkler Modus',sound:'Oberflächentöne',save:'Speichern',lang:'Oberflächensprache',need_login:'Anmeldung erforderlich',empty:'Leer',loading:'Wird geladen…',messenger:'Nachrichten',quests:'Tägliche Aufgaben',achievements:'Erfolge',servers:'Server',stickers:'Sticker',playlist:'Wiedergabelisten',channels:'Kanäle',videos:'Videos'},
  fr:{catalog:'Catalogue',online:'En ligne',clan:'Serveur',profile:'Mon profil',settings:'Paramètres',cinema:'Films et séries',music:'Musique',continue:'Reprendre',passport:'Passeport du monde',family:'Famille',geo:'Position',tutorial:'Guide',notes:'Notes',tip:'Conseil du jour',favs:'Favoris',sub:'Abonnement SPA',theme:'Mode sombre',sound:'Sons de l’interface',save:'Enregistrer',lang:'Langue de l’interface',need_login:'Connexion requise',empty:'Vide',loading:'Chargement…',messenger:'Messages',quests:'Quêtes quotidiennes',achievements:'Succès',servers:'Serveurs',stickers:'Autocollants',playlist:'Listes de lecture',channels:'Chaînes',videos:'Vidéos'},
  pt:{catalog:'Catálogo',online:'Online',clan:'Servidor',profile:'Meu perfil',settings:'Configurações',cinema:'Filmes e séries',music:'Música',continue:'Continuar assistindo',passport:'Passaporte mundial',family:'Família',geo:'Localização',tutorial:'Tutorial',notes:'Notas',tip:'Dica do dia',favs:'Favoritos',sub:'Assinatura SPA',theme:'Modo escuro',sound:'Sons da interface',save:'Salvar',lang:'Idioma da interface',need_login:'Faça login',empty:'Vazio',loading:'Carregando…',messenger:'Mensagens',quests:'Missões diárias',achievements:'Conquistas',servers:'Servidores',stickers:'Figurinhas',playlist:'Listas de reprodução',channels:'Canais',videos:'Vídeos'},
  ar:{catalog:'الفهرس',online:'متصل',clan:'الخادم',profile:'ملفي الشخصي',settings:'الإعدادات',cinema:'الأفلام والمسلسلات',music:'الموسيقى',continue:'متابعة المشاهدة',passport:'جواز العالم',family:'العائلة',geo:'الموقع',tutorial:'دليل الاستخدام',notes:'ملاحظات',tip:'نصيحة اليوم',favs:'المفضلة',sub:'اشتراك SPA',theme:'الوضع الداكن',sound:'أصوات الواجهة',save:'حفظ',lang:'لغة الواجهة',need_login:'يلزم تسجيل الدخول',empty:'فارغ',loading:'جارٍ التحميل…',messenger:'الرسائل',quests:'المهام اليومية',achievements:'الإنجازات',servers:'الخوادم',stickers:'الملصقات',playlist:'قوائم التشغيل',channels:'القنوات',videos:'الفيديوهات'}
};
function spaLang() {
  try {
    const c = (state && state._spaConf) || JSON.parse(localStorage.getItem('streamspa_settings')||'{}');
    const l = c.lang || 'ru';
    return (l === 'en' || l === 'uk' || Object.prototype.hasOwnProperty.call(SPA_LOCALES,l)) ? l : 'ru';
  } catch(e) { return 'ru'; }
}
function t(key) {
  const lang=spaLang();
  const L = I18N[lang] || I18N.ru;
  return (SPA_CORE_I18N[lang] && SPA_CORE_I18N[lang][key]) || L[key] || I18N.en[key] || I18N.ru[key] || key;
}

// Translate visible static UI labels across every SPA screen, not only the drawer.
// Exact text-node matching avoids changing usernames, messages, titles and user content.
const SPA_TEXT_I18N = {
  'Подключение к API':['API connection','Підключення до API'],
  'Сервисы сервера':['Server services','Сервіси сервера'],
  'Запросы выполняются из браузера. API должен разрешать CORS для домена StreamLive.':['Requests run in the browser. The API must allow CORS for the StreamLive domain.','Запити виконуються у браузері. API має дозволяти CORS для домену StreamLive.'],
  'Ключ/токен не сохраняется на сервере и очищается при закрытии приложения.':['The key/token is not stored on the server and is cleared when the app closes.','Ключ/токен не зберігається на сервері та очищується після закриття застосунку.'],
  'Токен этого участника':['This member’s token','Токен цього учасника'],
  'Ключ/токен этого участника':['This member’s key/token','Ключ/токен цього учасника'],
  'API key / заголовок':['API key / header','API key / заголовок'],
  'API key / параметр URL':['API key / query parameter','API key / параметр URL'],
  'API-приложение':['API app','API-застосунок'],
  'Быстрый тестовый сервис · JSON-макет':['Quick test service · JSON layout','Швидкий тестовий сервіс · JSON-макет'],
  '⚙️ Продвинутое приложение · импорт OpenAPI':['⚙️ Advanced app · import OpenAPI','⚙️ Розширений застосунок · імпорт OpenAPI'],
  'Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.':['For an advanced app, enter the API base URL, not the website URL.','Для розширеного застосунку вкажи базову адресу API, а не адресу сайту.'],
  'Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE.':['Paste OpenAPI 3.x / Swagger 2.0 JSON. StreamLive will build working forms for GET, POST, PUT, PATCH and DELETE.','Встав OpenAPI 3.x / Swagger 2.0 JSON. StreamLive створить робочі форми для GET, POST, PUT, PATCH і DELETE.'],
  'Выполнить запрос':['Run request','Виконати запит'],
  'Bearer-токен (если требуется)':['Bearer token (if required)','Bearer-токен (якщо потрібно)'],
  'Токен не сохраняется на сервере и очищается при закрытии приложения.':['The token is not stored on the server and is cleared when the app closes.','Токен не зберігається на сервері та очищується після закриття застосунку.'],
  'Туториал':['Tutorial','Посібник'], 'Заметки':['Notes','Нотатки'], 'Совет дня':['Daily tip','Порада дня'],
  'Настройки StreamLive SPA':['StreamLive SPA settings','Налаштування StreamLive SPA'],
  'Язык интерфейса (локально)':['Interface language (local)','Мова інтерфейсу (локально)'],
  'Подписи SPA. Без сервера.':['SPA labels. No server required.','Підписи SPA. Без сервера.'],
  'Кинопоиск API':['Kinopoisk API','Kinopoisk API'], 'Ключ для фильмов/сериалов/трейлеров':['Key for movies/series/trailers','Ключ для фільмів/серіалів/трейлерів'],
  'Внешний вид':['Appearance','Зовнішній вигляд'], 'Тема':['Theme','Тема'], 'Тёмная':['Dark','Темна'], 'Светлая':['Light','Світла'],
  'Авто-тема по времени':['Automatic theme by time','Автотема за часом'], 'Компактный список':['Compact list','Компактний список'],
  'Меньше анимаций':['Reduce animations','Менше анімацій'], 'Размер шрифта':['Font size','Розмір шрифту'],
  'Каталог':['Catalog','Каталог'], 'Лимит сегодня':['Limit today','Ліміт сьогодні'], 'Лимиты и сервер':['Limits and server','Ліміти та сервер'],
  'Экспорт настроек':['Export settings','Експорт налаштувань'], 'Сбросить всё локальное':['Reset all local data','Скинути всі локальні дані'],
  'Настройки только на этом устройстве':['Settings are stored on this device only','Налаштування зберігаються лише на цьому пристрої'],
  'Только клиент SPA. Не трогают админку StreamLive.':['SPA client only. StreamLive admin panel is not affected.','Лише клієнт SPA. Адмінка StreamLive не змінюється.'],
  'Добавить':['Add','Додати'], 'Удалить':['Delete','Видалити'], 'Пока пусто':['Nothing here yet','Поки порожньо'],
  'Новая заметка…':['New note…','Нова нотатка…'], 'Только на этом устройстве':['Only on this device','Лише на цьому пристрої'],
  'Ещё совет':['Another tip','Ще порада'], 'Мини-приложения':['Mini apps','Міні-застосунки'],
  'Центр приложений':['App center','Центр застосунків'],
  'Быстрый доступ к разделам StreamLive. Каждая плитка открывает существующую функцию.':['Quick access to StreamLive sections. Each tile opens an existing feature.','Швидкий доступ до розділів StreamLive. Кожна плитка відкриває наявну функцію.'],
  'Все приложения':['All apps','Усі застосунки'], 'Профиль':['Profile','Профіль'], 'Кто в сети':['Who is online','Хто в мережі'],
  'Сообщения':['Messages','Повідомлення'], 'Серверы':['Servers','Сервери'], 'Фильмы и сериалы':['Movies & series','Фільми та серіали'],
  'Музыка':['Music','Музика'], 'Продолжить просмотр':['Continue watching','Продовжити перегляд'], 'Избранное':['Favorites','Обране'],
  'Семья':['Family','Сім’я'], 'Паспорт мира':['World passport','Паспорт світу'], 'Гео':['Geo','Гео'],
  'Квесты дня':['Daily quests','Щоденні квести'], 'Достижения':['Achievements','Досягнення'], 'Настроение':['Mood','Настрій'],
  'Подписка SPA':['SPA subscription','Підписка SPA'], 'Сохранить':['Save','Зберегти'], 'Открыть':['Open','Відкрити'],
  'Поиск':['Search','Пошук'], 'Загрузка…':['Loading…','Завантаження…'], 'Пусто':['Empty','Порожньо'],
  'Баффы сервера':['Server boosts','Бонуси сервера'], 'Код приглашения':['Invite code','Код запрошення'],
  'Скопировать код':['Copy code','Скопіювати код'], 'QR для друзей':['QR for friends','QR для друзів'],
  'Неактивные участники':['Inactive members','Неактивні учасники'], 'Не исключать автоматически':['Never auto-remove','Не виключати автоматично'],
  '30 дней без активности':['30 days inactive','30 днів без активності'], '60 дней без активности':['60 days inactive','60 днів без активності'],
  '90 дней без активности':['90 days inactive','90 днів без активності'], 'Сохранить правило':['Save rule','Зберегти правило'],
  'Кинопоиск API сервера':['Server Kinopoisk API','Kinopoisk API сервера'], 'Сохранить ключ сервера':['Save server key','Зберегти ключ сервера'],
  'API Hub · сервисы сервера':['API Hub · server services','API Hub · сервіси сервера'], 'Название сервиса':['Service name','Назва сервісу'],
  'Описание сервиса':['Service description','Опис сервісу'], 'Добавить сервис':['Add service','Додати сервіс'],
  'Открывать отдельно':['Open separately','Відкривати окремо'], 'Открывать внутри StreamLive (если сайт разрешает iframe)':['Open inside StreamLive (if iframe is allowed)','Відкривати в StreamLive (якщо сайт дозволяє iframe)'],
  'Webhooks · автоматизация':['Webhooks · automation','Webhooks · автоматизація'], 'События':['Events','Події'],
  'Новое сообщение':['New message','Нове повідомлення'], 'Новый участник':['New member','Новий учасник'],
  'Участник вышел':['Member left','Учасник вийшов'], 'Добавлен сервис':['Service added','Сервіс додано'],
  'Сохранить Webhook':['Save Webhook','Зберегти Webhook'], 'Роли и права':['Roles and permissions','Ролі та дозволи'],
  'Управлять чатом':['Manage chat','Керувати чатом'], 'Кикать участников':['Kick members','Виключати учасників'],
  'Управлять сервисами':['Manage services','Керувати сервісами'], 'Управлять Webhooks':['Manage Webhooks','Керувати Webhooks'],
  'Управлять ролями':['Manage roles','Керувати ролями'], 'Объявления':['Announcements','Оголошення'],
  'Создать роль':['Create role','Створити роль'], 'Участники':['Members','Учасники'], 'Владелец':['Owner','Власник'],
  'Чат сервера · тёмные стикеры':['Server chat · dark stickers','Чат сервера · темні стікери'],
  'Выйти из сервера':['Leave server','Вийти із сервера'], 'Сервер пока не попал в топ':['Server is not ranked yet','Сервер ще не потрапив до рейтингу'],
  'Рейтинг сервера:':['Server rank:','Рейтинг сервера:'], 'Сервисы пока не добавлены':['No services added yet','Сервіси ще не додані'],
  'Пока нет пользовательских ролей':['No custom roles yet','Поки немає власних ролей'], 'Без дополнительных прав':['No extra permissions','Без додаткових дозволів'],
  'Сообщение всем в сервере':['Message everyone on the server','Повідомлення всім на сервері'],
  'Перейти к подключённому сервису':['Go to connected service','Перейти до підключеного сервісу'],
  'Открыть':['Open','Відкрити'], 'Сохранено':['Saved','Збережено'], 'Автоисключение выключено':['Auto-removal is off','Автовиключення вимкнено'],
  'Владелец задаёт общий ключ — у всех в сервере работает кино':['The owner sets a shared key for server cinema','Власник задає спільний ключ для кіно сервера'],
  'Приветствие новых участников':['New member welcome','Привітання нових учасників'], 'Включить приветствие':['Enable welcome message','Увімкнути привітання'],
  'Сохранить приветствие':['Save welcome message','Зберегти привітання'], 'Переменная {user} подставляет имя нового участника.':['The {user} variable inserts the new member’s name.','Змінна {user} підставляє ім’я нового учасника.'],
  'Достижения и коллекционные значки':['Achievements and collectible badges','Досягнення та колекційні значки'], 'Название значка':['Badge name','Назва значка'],
  'Описание':['Description','Опис'], 'Создать значок':['Create badge','Створити значок'], 'Значков пока нет':['No badges yet','Значків поки немає'],
  'Умные подборки':['Smart collections','Розумні добірки'], 'Название подборки':['Collection name','Назва добірки'], 'Поделиться с сервером':['Share with server','Поділитися із сервером'],
  'Сохранить подборку':['Save collection','Зберегти добірку'], 'Подборок пока нет':['No collections yet','Добірок поки немає'],
  'Статистика развития сервера':['Server growth statistics','Статистика розвитку сервера'], 'Участников':['Members','Учасників'], 'Новых за 7 дней':['New in 7 days','Нових за 7 днів'],
  'Сообщений за 7 дней':['Messages in 7 days','Повідомлень за 7 днів'], 'Достижений':['Achievements','Досягнень'], 'Предстоящих событий':['Upcoming events','Майбутніх подій'],
  'Обращения к администрации':['Support tickets','Звернення до адміністрації'], 'Тема обращения':['Ticket subject','Тема звернення'], 'Отправить обращение':['Submit ticket','Надіслати звернення'],
  'Голосования и опросы':['Polls and voting','Голосування та опитування'], 'Вопрос голосования':['Poll question','Питання голосування'], 'Каждый вариант с новой строки':['One option per line','Кожен варіант з нового рядка'], 'Создать голосование':['Create poll','Створити голосування'],
  'События и календарь':['Events and calendar','Події та календар'], 'Название события':['Event name','Назва події'], 'Создать событие':['Create event','Створити подію'],
  'Журнал действий сервера':['Server audit log','Журнал дій сервера'], 'Выдать значок…':['Award badge…','Видати значок…'], 'Пойду':['I’m going','Піду'], 'Возможно':['Maybe','Можливо'],
  'Обновить заявку':['Update ticket','Оновити звернення'], 'Открыта':['Open','Відкрита'], 'В работе':['In progress','У роботі'], 'Закрыта':['Closed','Закрита'],
  'Каталог приложений сервера':['Server app catalog','Каталог застосунків сервера'], 'Отзыв (необязательно)':['Review (optional)','Відгук (необов’язково)'], 'Оценить':['Rate','Оцінити'], 'Опубликованные приложения доступны всем участникам этого сервера. Добавляй нужные в свою коллекцию.':['Published apps are available to all members of this server. Add the ones you need to your collection.','Опубліковані застосунки доступні всім учасникам цього сервера. Додавай потрібні до своєї колекції.'],
  'Поиск приложений…':['Search apps…','Пошук застосунків…'], 'Опубликовать':['Publish','Опублікувати'], 'Снять':['Unpublish','Зняти публікацію'], 'Добавить в мои приложения':['Add to my apps','Додати до моїх застосунків'], 'Убрать из моих приложений':['Remove from my apps','Прибрати з моїх застосунків'],
  'Создание сервисов доступно только с активной подпиской SPA. Лимит подписки: 10 сервисов за 30 дней.':['Creating services requires an active SPA subscription. Subscription limit: 10 services per 30 days.','Створення сервісів доступне лише з активною підпискою SPA. Ліміт підписки: 10 сервісів за 30 днів.'],
  'Все платформы · универсальная ссылка':['All platforms · universal link','Усі платформи · універсальне посилання'], 'Android · App Links':['Android · App Links','Android · App Links'], 'iOS · Universal Links':['iOS · Universal Links','iOS · Universal Links'], 'Windows / macOS / Linux':['Windows / macOS / Linux','Windows / macOS / Linux'], 'Веб-приложение':['Web app','Вебзастосунок'], 'API / интеграция':['API / integration','API / інтеграція'],
  'Музыка':['Music','Музика'], 'Продолжает играть':['Keeps playing','Продовжує грати'], 'Предыдущий':['Previous','Попередній'], 'Следующий':['Next','Наступний'], 'Остановить':['Stop','Зупинити'], 'Скрыть':['Hide','Приховати'], 'Пауза/играть':['Pause/Play','Пауза/відтворити'], 'Повторить запуск':['Retry playback','Повторити запуск'], 'Не удалось запустить PlayerJS':['Could not start PlayerJS','Не вдалося запустити PlayerJS'], 'PlayerJS не загрузился':['PlayerJS did not load','PlayerJS не завантажився'], 'Ничего не играет':['Nothing is playing','Нічого не відтворюється'], 'Воспроизведение остановлено':['Playback stopped','Відтворення зупинено']
};
const SPA_SETTINGS_I18N = {"en":{"Кинопоиск API":"Kinopoisk API","Ключ для фильмов/сериалов/трейлеров":"Key for movies, series and trailers","Тема":"Theme","Тёмная":"Dark","Светлая":"Light","Авто-тема по времени":"Automatic theme by time","Компактный список":"Compact list","Меньше анимаций":"Reduce animations","Размер шрифта":"Font size","Каталог":"Catalog","Фильтр при входе":"Default filter","Все":"All","Люди":"People","Каналы":"Channels","Видео":"Videos","Спрашивать перед открытием":"Ask before opening","Плеер и комментарии":"Player and comments","Автовоспроизведение":"Autoplay","Летящие комментарии":"Floating comments","Приватность":"Privacy","Показывать «сейчас онлайн»":"Show online status","Скрыть вкладку Онлайн":"Hide Online tab","Звук":"Sound","Звук интерфейса (premium)":"Interface sounds (premium)","Громкость UI":"UI volume","Проверить звук":"Test sound","Прочее":"Other","Не выключать экран (wake)":"Keep screen awake","Вибро (если есть)":"Vibration (if supported)","Мягкие цвета":"Soft colors","Показывать build":"Show build number","Снова туториал":"Show tutorial again","Лимит сегодня":"Today’s limits","Сервер":"Server","Открыть туториал":"Open tutorial","Экспорт настроек":"Export settings","Сбросить всё локальное":"Reset local data","настройки только на этом устройстве":"settings are stored on this device only"},"uk":{"Кинопоиск API":"Кінопошук API","Ключ для фильмов/сериалов/трейлеров":"Ключ для фільмів/серіалів/трейлерів","Тема":"Тема","Тёмная":"Темна","Светлая":"Світла","Авто-тема по времени":"Автотема за часом","Компактный список":"Компактний список","Меньше анимаций":"Менше анімацій","Размер шрифта":"Розмір шрифту","Каталог":"Каталог","Фильтр при входе":"Фільтр під час входу","Все":"Усі","Люди":"Люди","Каналы":"Канали","Видео":"Відео","Спрашивать перед открытием":"Питати перед відкриттям","Плеер и комментарии":"Плеєр і коментарі","Автовоспроизведение":"Автовідтворення","Летящие комментарии":"Плаваючі коментарі","Приватность":"Приватність","Показывать «сейчас онлайн»":"Показувати статус «у мережі»","Скрыть вкладку Онлайн":"Приховати вкладку «Онлайн»","Звук":"Звук","Звук интерфейса (premium)":"Звуки інтерфейсу (premium)","Громкость UI":"Гучність інтерфейсу","Проверить звук":"Перевірити звук","Прочее":"Інше","Не выключать экран (wake)":"Не вимикати екран","Вибро (если есть)":"Вібрація (якщо підтримується)","Мягкие цвета":"М’які кольори","Показывать build":"Показувати номер збірки","Снова туториал":"Показати навчання знову","Лимит сегодня":"Ліміти на сьогодні","Сервер":"Сервер","Открыть туториал":"Відкрити навчання","Экспорт настроек":"Експорт налаштувань","Сбросить всё локальное":"Скинути всі локальні дані","настройки только на этом устройстве":"налаштування зберігаються лише на цьому пристрої"},"de":{"Кинопоиск API":"Kinopoisk-API","Ключ для фильмов/сериалов/трейлеров":"Schlüssel für Filme, Serien und Trailer","Тема":"Design","Тёмная":"Dunkel","Светлая":"Hell","Авто-тема по времени":"Automatisches Design nach Uhrzeit","Компактный список":"Kompakte Liste","Меньше анимаций":"Weniger Animationen","Размер шрифта":"Schriftgröße","Каталог":"Katalog","Фильтр при входе":"Standardfilter","Все":"Alle","Люди":"Personen","Каналы":"Kanäle","Видео":"Videos","Спрашивать перед открытием":"Vor dem Öffnen fragen","Плеер и комментарии":"Player und Kommentare","Автовоспроизведение":"Automatische Wiedergabe","Летящие комментарии":"Schwebende Kommentare","Приватность":"Datenschutz","Показывать «сейчас онлайн»":"Online-Status anzeigen","Скрыть вкладку Онлайн":"Online-Tab ausblenden","Звук":"Ton","Звук интерфейса (premium)":"Oberflächentöne (Premium)","Громкость UI":"Lautstärke der Oberfläche","Проверить звук":"Ton testen","Прочее":"Sonstiges","Не выключать экран (wake)":"Bildschirm eingeschaltet lassen","Вибро (если есть)":"Vibration (falls unterstützt)","Мягкие цвета":"Sanfte Farben","Показывать build":"Build-Nummer anzeigen","Снова туториал":"Tutorial erneut anzeigen","Лимит сегодня":"Heutige Limits","Сервер":"Server","Открыть туториал":"Tutorial öffnen","Экспорт настроек":"Einstellungen exportieren","Сбросить всё локальное":"Alle lokalen Daten zurücksetzen","настройки только на этом устройстве":"Einstellungen werden nur auf diesem Gerät gespeichert"},"es":{"Кинопоиск API":"API de Kinopoisk","Ключ для фильмов/сериалов/трейлеров":"Clave para películas, series y tráileres","Тема":"Tema","Тёмная":"Oscuro","Светлая":"Claro","Авто-тема по времени":"Tema automática por horario","Компактный список":"Lista compacta","Меньше анимаций":"Reducir animaciones","Размер шрифта":"Tamaño de fuente","Каталог":"Catálogo","Фильтр при входе":"Filtro predeterminado","Все":"Todo","Люди":"Personas","Каналы":"Canales","Видео":"Vídeos","Спрашивать перед открытием":"Preguntar antes de abrir","Плеер и комментарии":"Reproductor y comentarios","Автовоспроизведение":"Reproducción automática","Летящие комментарии":"Comentarios flotantes","Приватность":"Privacidad","Показывать «сейчас онлайн»":"Mostrar estado en línea","Скрыть вкладку Онлайн":"Ocultar pestaña En línea","Звук":"Sonido","Звук интерфейса (premium)":"Sonidos de interfaz (premium)","Громкость UI":"Volumen de interfaz","Проверить звук":"Probar sonido","Прочее":"Otros","Не выключать экран (wake)":"Mantener la pantalla encendida","Вибро (если есть)":"Vibración (si está disponible)","Мягкие цвета":"Colores suaves","Показывать build":"Mostrar número de compilación","Снова туториал":"Repetir tutorial","Лимит сегодня":"Límites de hoy","Сервер":"Servidor","Открыть туториал":"Abrir tutorial","Экспорт настроек":"Exportar ajustes","Сбросить всё локальное":"Restablecer todos los datos locales","настройки только на этом устройстве":"los ajustes solo se guardan en este dispositivo"},"fr":{"Кинопоиск API":"API Kinopoisk","Ключ для фильмов/сериалов/трейлеров":"Clé pour les films, séries et bandes-annonces","Тема":"Thème","Тёмная":"Sombre","Светлая":"Clair","Авто-тема по времени":"Thème automatique selon l’heure","Компактный список":"Liste compacte","Меньше анимаций":"Réduire les animations","Размер шрифта":"Taille du texte","Каталог":"Catalogue","Фильтр при входе":"Filtre par défaut","Все":"Tout","Люди":"Personnes","Каналы":"Chaînes","Видео":"Vidéos","Спрашивать перед открытием":"Demander avant ouverture","Плеер и комментарии":"Lecteur et commentaires","Автовоспроизведение":"Lecture automatique","Летящие комментарии":"Commentaires flottants","Приватность":"Confidentialité","Показывать «сейчас онлайн»":"Afficher le statut en ligne","Скрыть вкладку Онлайн":"Masquer l’onglet En ligne","Звук":"Son","Звук интерфейса (premium)":"Sons de l’interface (premium)","Громкость UI":"Volume de l’interface","Проверить звук":"Tester le son","Прочее":"Autres","Не выключать экран (wake)":"Garder l’écran allumé","Вибро (если есть)":"Vibration (si disponible)","Мягкие цвета":"Couleurs douces","Показывать build":"Afficher le numéro de build","Снова туториал":"Revoir le tutoriel","Лимит сегодня":"Limites du jour","Сервер":"Serveur","Открыть туториал":"Ouvrir le tutoriel","Экспорт настроек":"Exporter les paramètres","Сбросить всё локальное":"Réinitialiser les données locales","настройки только на этом устройстве":"les paramètres sont enregistrés sur cet appareil uniquement"},"pt":{"Кинопоиск API":"API Kinopoisk","Ключ для фильмов/сериалов/трейлеров":"Chave para filmes, séries e trailers","Тема":"Tema","Тёмная":"Escuro","Светлая":"Claro","Авто-тема по времени":"Tema automática por horário","Компактный список":"Lista compacta","Меньше анимаций":"Reduzir animações","Размер шрифта":"Tamanho da fonte","Каталог":"Catálogo","Фильтр при входе":"Filtro padrão","Все":"Todos","Люди":"Pessoas","Каналы":"Canais","Видео":"Vídeos","Спрашивать перед открытием":"Perguntar antes de abrir","Плеер и комментарии":"Player e comentários","Автовоспроизведение":"Reprodução automática","Летящие комментарии":"Comentários flutuantes","Приватность":"Privacidade","Показывать «сейчас онлайн»":"Mostrar status online","Скрыть вкладку Онлайн":"Ocultar aba Online","Звук":"Som","Звук интерфейса (premium)":"Sons da interface (premium)","Громкость UI":"Volume da interface","Проверить звук":"Testar som","Прочее":"Outros","Не выключать экран (wake)":"Manter a tela ligada","Вибро (если есть)":"Vibração (se disponível)","Мягкие цвета":"Cores suaves","Показывать build":"Mostrar número da versão","Снова туториал":"Rever tutorial","Лимит сегодня":"Limites de hoje","Сервер":"Servidor","Открыть туториал":"Abrir tutorial","Экспорт настроек":"Exportar configurações","Сбросить всё локальное":"Redefinir todos os dados locais","настройки только на этом устройстве":"as configurações ficam salvas apenas neste dispositivo"},"ja":{"Кинопоиск API":"Kinopoisk API","Ключ для фильмов/сериалов/трейлеров":"映画・シリーズ・予告編のAPIキー","Тема":"テーマ","Тёмная":"ダーク","Светлая":"ライト","Авто-тема по времени":"時間に応じた自動テーマ","Компактный список":"コンパクトリスト","Меньше анимаций":"アニメーションを減らす","Размер шрифта":"文字サイズ","Каталог":"カタログ","Фильтр при входе":"初期フィルター","Все":"すべて","Люди":"ユーザー","Каналы":"チャンネル","Видео":"動画","Спрашивать перед открытием":"開く前に確認","Плеер и комментарии":"プレーヤーとコメント","Автовоспроизведение":"自動再生","Летящие комментарии":"流れるコメント","Приватность":"プライバシー","Показывать «сейчас онлайн»":"オンライン状態を表示","Скрыть вкладку Онлайн":"オンラインタブを非表示","Звук":"サウンド","Звук интерфейса (premium)":"UIサウンド（プレミアム）","Громкость UI":"UI音量","Проверить звук":"サウンドをテスト","Прочее":"その他","Не выключать экран (wake)":"画面をスリープさせない","Вибро (если есть)":"バイブレーション（対応時）","Мягкие цвета":"ソフトカラー","Показывать build":"ビルド番号を表示","Снова туториал":"チュートリアルを再表示","Лимит сегодня":"本日の上限","Сервер":"サーバー","Открыть туториал":"チュートリアルを開く","Экспорт настроек":"設定をエクスポート","Сбросить всё локальное":"ローカルデータをすべてリセット","настройки только на этом устройстве":"設定はこの端末にのみ保存されます"},"ko-KR":{"Кинопоиск API":"Kinopoisk API","Ключ для фильмов/сериалов/трейлеров":"영화·시리즈·예고편 API 키","Тема":"테마","Тёмная":"다크","Светлая":"라이트","Авто-тема по времени":"시간별 자동 테마","Компактный список":"간결한 목록","Меньше анимаций":"애니메이션 줄이기","Размер шрифта":"글꼴 크기","Каталог":"카탈로그","Фильтр при входе":"기본 필터","Все":"전체","Люди":"사용자","Каналы":"채널","Видео":"동영상","Спрашивать перед открытием":"열기 전에 확인","Плеер и комментарии":"플레이어 및 댓글","Автовоспроизведение":"자동 재생","Летящие комментарии":"플로팅 댓글","Приватность":"개인정보 보호","Показывать «сейчас онлайн»":"온라인 상태 표시","Скрыть вкладку Онлайн":"온라인 탭 숨기기","Звук":"소리","Звук интерфейса (premium)":"인터페이스 사운드(프리미엄)","Громкость UI":"인터페이스 음량","Проверить звук":"소리 테스트","Прочее":"기타","Не выключать экран (wake)":"화면 켜짐 유지","Вибро (если есть)":"진동(지원 시)","Мягкие цвета":"부드러운 색상","Показывать build":"빌드 번호 표시","Снова туториал":"튜토리얼 다시 보기","Лимит сегодня":"오늘의 한도","Сервер":"서버","Открыть туториал":"튜토리얼 열기","Экспорт настроек":"설정 내보내기","Сбросить всё локальное":"모든 로컬 데이터 초기화","настройки только на этом устройстве":"설정은 이 기기에만 저장됩니다"},"zh-CN":{"Кинопоиск API":"Kinopoisk API","Ключ для фильмов/сериалов/трейлеров":"电影、剧集和预告片 API 密钥","Тема":"主题","Тёмная":"深色","Светлая":"浅色","Авто-тема по времени":"按时间自动切换主题","Компактный список":"紧凑列表","Меньше анимаций":"减少动画","Размер шрифта":"字体大小","Каталог":"目录","Фильтр при входе":"默认筛选","Все":"全部","Люди":"用户","Каналы":"频道","Видео":"视频","Спрашивать перед открытием":"打开前询问","Плеер и комментарии":"播放器和评论","Автовоспроизведение":"自动播放","Летящие комментарии":"飘屏评论","Приватность":"隐私","Показывать «сейчас онлайн»":"显示在线状态","Скрыть вкладку Онлайн":"隐藏在线标签","Звук":"声音","Звук интерфейса (premium)":"界面音效（高级版）","Громкость UI":"界面音量","Проверить звук":"测试声音","Прочее":"其他","Не выключать экран (wake)":"保持屏幕常亮","Вибро (если есть)":"振动（如支持）","Мягкие цвета":"柔和配色","Показывать build":"显示版本号","Снова туториал":"重新显示教程","Лимит сегодня":"今日限额","Сервер":"服务器","Открыть туториал":"打开教程","Экспорт настроек":"导出设置","Сбросить всё локальное":"重置所有本地数据","настройки только на этом устройстве":"设置仅保存在此设备"},"zh-TW":{"Кинопоиск API":"Kinopoisk API","Ключ для фильмов/сериалов/трейлеров":"電影、影集與預告片 API 金鑰","Тема":"主題","Тёмная":"深色","Светлая":"淺色","Авто-тема по времени":"依時間自動切換主題","Компактный список":"精簡清單","Меньше анимаций":"減少動畫","Размер шрифта":"字體大小","Каталог":"目錄","Фильтр при входе":"預設篩選","Все":"全部","Люди":"使用者","Каналы":"頻道","Видео":"影片","Спрашивать перед открытием":"開啟前先詢問","Плеер и комментарии":"播放器與留言","Автовоспроизведение":"自動播放","Летящие комментарии":"飄動留言","Приватность":"隱私","Показывать «сейчас онлайн»":"顯示線上狀態","Скрыть вкладку Онлайн":"隱藏線上分頁","Звук":"音效","Звук интерфейса (premium)":"介面音效（進階版）","Громкость UI":"介面音量","Проверить звук":"測試音效","Прочее":"其他","Не выключать экран (wake)":"保持螢幕開啟","Вибро (если есть)":"震動（若支援）","Мягкие цвета":"柔和色彩","Показывать build":"顯示版本編號","Снова туториал":"再次顯示教學","Лимит сегодня":"今日額度","Сервер":"伺服器","Открыть туториал":"開啟教學","Экспорт настроек":"匯出設定","Сбросить всё локальное":"重設所有本機資料","настройки только на этом устройстве":"設定只儲存在此裝置"},"ar":{"Кинопоиск API":"واجهة Kinopoisk البرمجية","Ключ для фильмов/сериалов/трейлеров":"مفتاح الأفلام والمسلسلات والإعلانات","Тема":"المظهر","Тёмная":"داكن","Светлая":"فاتح","Авто-тема по времени":"تغيير المظهر حسب الوقت","Компактный список":"قائمة مضغوطة","Меньше анимаций":"تقليل الحركة","Размер шрифта":"حجم الخط","Каталог":"الفهرس","Фильтр при входе":"التصفية الافتراضية","Все":"الكل","Люди":"الأشخاص","Каналы":"القنوات","Видео":"الفيديو","Спрашивать перед открытием":"السؤال قبل الفتح","Плеер и комментарии":"المشغل والتعليقات","Автовоспроизведение":"التشغيل التلقائي","Летящие комментарии":"تعليقات عائمة","Приватность":"الخصوصية","Показывать «сейчас онлайн»":"إظهار حالة الاتصال","Скрыть вкладку Онлайн":"إخفاء تبويب المتصلين","Звук":"الصوت","Звук интерфейса (premium)":"أصوات الواجهة (مميز)","Громкость UI":"مستوى صوت الواجهة","Проверить звук":"اختبار الصوت","Прочее":"أخرى","Не выключать экран (wake)":"إبقاء الشاشة قيد التشغيل","Вибро (если есть)":"الاهتزاز (إن كان مدعوماً)","Мягкие цвета":"ألوان هادئة","Показывать build":"إظهار رقم الإصدار","Снова туториал":"إعادة عرض الدليل","Лимит сегодня":"حدود اليوم","Сервер":"الخادم","Открыть туториал":"فتح الدليل","Экспорт настроек":"تصدير الإعدادات","Сбросить всё локальное":"إعادة ضبط البيانات المحلية","настройки только на этом устройстве":"تُحفظ الإعدادات على هذا الجهاز فقط"}};
Object.keys(SPA_SETTINGS_I18N).forEach(lang=>{
  const m=SPA_SETTINGS_I18N[lang];
  const add={
    'Не выключать экран':'Keep screen awake','Вибрация':'Vibration','Работает в поддерживаемых браузерах по HTTPS, пока приложение открыто.':'Works in supported browsers over HTTPS while the app remains open.','Зависит от браузера и устройства; на iPhone браузер может не поддерживать вибрацию.':'Depends on the browser and device; browser vibration may not be supported on iPhone.','Вибрация не поддерживается этим браузером или устройством.':'Vibration is not supported by this browser or device.','Эта функция требует браузер с поддержкой Screen Wake Lock и HTTPS.':'This feature requires a browser with Screen Wake Lock support and HTTPS.','Сборка':'Build','StreamLive SPA · сборка ':'StreamLive SPA · build '
  };
  if(lang==='en'){Object.assign(m,add);}
});
const SPA_EXTRA_LOCALE_TEXT = {
 'zh-CN':{'Туториал':'使用教程','Заметки':'笔记','Совет дня':'每日提示','Настройки StreamLive SPA':'StreamLive SPA 设置','Язык интерфейса (локально)':'界面语言（本地）','Внешний вид':'外观','Тема':'主题','Тёмная':'深色','Светлая':'浅色','Сохранить':'保存','Добавить':'添加','Удалить':'删除','Пока пусто':'暂无内容','Каталог':'目录','Кто в сети':'在线用户','Сообщения':'消息','Серверы':'服务器','Фильмы и сериалы':'电影和剧集','Музыка':'音乐','Продолжить просмотр':'继续观看','Избранное':'收藏','Семья':'家庭','Квесты дня':'每日任务','Достижения':'成就','Подписка SPA':'SPA 订阅','Открыть':'打开','Поиск':'搜索','Загрузка…':'加载中…','Пусто':'暂无内容','Каталог приложений сервера':'服务器应用目录','Отзыв (необязательно)':'评价（可选）','Оценить':'评分','Поиск приложений…':'搜索应用…','Опубликовать':'发布','Снять':'取消发布','Добавить в мои приложения':'添加到我的应用','Убрать из моих приложений':'从我的应用中移除','Создать голосование':'创建投票','Создать событие':'创建活动','Отправить обращение':'提交请求','Обновить заявку':'更新请求','Назад':'返回','Далее':'下一步','Завершить':'完成','Начать сначала':'重新开始'},
 'zh-TW':{'Туториал':'使用教學','Заметки':'筆記','Совет дня':'每日提示','Настройки StreamLive SPA':'StreamLive SPA 設定','Язык интерфейса (локально)':'介面語言（本機）','Внешний вид':'外觀','Тема':'主題','Тёмная':'深色','Светлая':'淺色','Сохранить':'儲存','Добавить':'新增','Удалить':'刪除','Пока пусто':'目前沒有內容','Каталог':'目錄','Кто в сети':'線上使用者','Сообщения':'訊息','Серверы':'伺服器','Фильмы и сериалы':'電影與影集','Музыка':'音樂','Продолжить просмотр':'繼續觀看','Избранное':'收藏','Семья':'家庭','Квесты дня':'每日任務','Достижения':'成就','Подписка SPA':'SPA 訂閱','Открыть':'開啟','Поиск':'搜尋','Загрузка…':'載入中…','Пусто':'目前沒有內容','Каталог приложений сервера':'伺服器應用程式目錄','Отзыв (необязательно)':'評論（選填）','Оценить':'評分','Поиск приложений…':'搜尋應用程式…','Опубликовать':'發布','Снять':'取消發布','Добавить в мои приложения':'加入我的應用程式','Убрать из моих приложений':'從我的應用程式移除','Создать голосование':'建立投票','Создать событие':'建立活動','Отправить обращение':'提交請求','Обновить заявку':'更新請求','Назад':'返回','Далее':'下一步','Завершить':'完成','Начать сначала':'重新開始'},
 'ko-KR':{'Туториал':'사용 안내','Заметки':'메모','Совет дня':'오늘의 팁','Настройки StreamLive SPA':'StreamLive SPA 설정','Язык интерфейса (локально)':'인터페이스 언어(로컬)','Внешний вид':'화면 모양','Тема':'테마','Тёмная':'다크','Светлая':'라이트','Сохранить':'저장','Добавить':'추가','Удалить':'삭제','Пока пусто':'아직 비어 있습니다','Каталог':'카탈로그','Кто в сети':'온라인 사용자','Сообщения':'메시지','Серверы':'서버','Фильмы и сериалы':'영화 및 시리즈','Музыка':'음악','Продолжить просмотр':'이어보기','Избранное':'즐겨찾기','Семья':'가족','Квесты дня':'일일 퀘스트','Достижения':'업적','Подписка SPA':'SPA 구독','Открыть':'열기','Поиск':'검색','Загрузка…':'불러오는 중…','Пусто':'비어 있음','Каталог приложений сервера':'서버 앱 카탈로그','Отзыв (необязательно)':'후기(선택 사항)','Оценить':'평가하기','Поиск приложений…':'앱 검색…','Опубликовать':'게시','Снять':'게시 취소','Добавить в мои приложения':'내 앱에 추가','Убрать из моих приложений':'내 앱에서 제거','Создать голосование':'투표 만들기','Создать событие':'이벤트 만들기','Отправить обращение':'문의 보내기','Обновить заявку':'문의 업데이트','Назад':'뒤로','Далее':'다음','Завершить':'완료','Начать сначала':'처음부터 시작'},
 'ja':{'Туториал':'チュートリアル','Заметки':'メモ','Совет дня':'今日のヒント','Настройки StreamLive SPA':'StreamLive SPA 設定','Язык интерфейса (локально)':'表示言語（ローカル）','Внешний вид':'外観','Тема':'テーマ','Тёмная':'ダーク','Светлая':'ライト','Сохранить':'保存','Добавить':'追加','Удалить':'削除','Пока пусто':'まだ何もありません','Каталог':'カタログ','Кто в сети':'オンラインユーザー','Сообщения':'メッセージ','Серверы':'サーバー','Фильмы и сериалы':'映画・シリーズ','Музыка':'音楽','Продолжить просмотр':'続きを見る','Избранное':'お気に入り','Семья':'ファミリー','Квесты дня':'デイリークエスト','Достижения':'実績','Подписка SPA':'SPAサブスクリプション','Открыть':'開く','Поиск':'検索','Загрузка…':'読み込み中…','Пусто':'空です','Каталог приложений сервера':'サーバーアプリカタログ','Отзыв (необязательно)':'レビュー（任意）','Оценить':'評価する','Поиск приложений…':'アプリを検索…','Опубликовать':'公開','Снять':'公開を解除','Добавить в мои приложения':'マイアプリに追加','Убрать из моих приложений':'マイアプリから削除','Создать голосование':'投票を作成','Создать событие':'イベントを作成','Отправить обращение':'問い合わせを送信','Обновить заявку':'問い合わせを更新','Назад':'戻る','Далее':'次へ','Завершить':'完了','Начать сначала':'最初から'},
 'es':{'Туториал':'Tutorial','Заметки':'Notas','Совет дня':'Consejo del día','Настройки StreamLive SPA':'Ajustes de StreamLive SPA','Язык интерфейса (локально)':'Idioma de interfaz (local)','Внешний вид':'Apariencia','Тема':'Tema','Тёмная':'Oscuro','Светлая':'Claro','Сохранить':'Guardar','Добавить':'Añadir','Удалить':'Eliminar','Пока пусто':'Todavía está vacío','Каталог':'Catálogo','Кто в сети':'Quién está en línea','Сообщения':'Mensajes','Серверы':'Servidores','Фильмы и сериалы':'Películas y series','Музыка':'Música','Продолжить просмотр':'Continuar viendo','Избранное':'Favoritos','Семья':'Familia','Квесты дня':'Misiones diarias','Достижения':'Logros','Подписка SPA':'Suscripción SPA','Открыть':'Abrir','Поиск':'Buscar','Загрузка…':'Cargando…','Пусто':'Vacío','Каталог приложений сервера':'Catálogo de apps del servidor','Отзыв (необязательно)':'Reseña (opcional)','Оценить':'Valorar','Поиск приложений…':'Buscar aplicaciones…','Опубликовать':'Publicar','Снять':'Retirar publicación','Добавить в мои приложения':'Añadir a mis apps','Убрать из моих приложений':'Quitar de mis apps','Создать голосование':'Crear votación','Создать событие':'Crear evento','Отправить обращение':'Enviar solicitud','Обновить заявку':'Actualizar solicitud','Назад':'Atrás','Далее':'Siguiente','Завершить':'Finalizar','Начать сначала':'Empezar de nuevo'},
 'de':{'Туториал':'Einführung','Заметки':'Notizen','Совет дня':'Tipp des Tages','Настройки StreamLive SPA':'StreamLive SPA Einstellungen','Язык интерфейса (локально)':'Oberflächensprache (lokal)','Внешний вид':'Darstellung','Тема':'Design','Тёмная':'Dunkel','Светлая':'Hell','Сохранить':'Speichern','Добавить':'Hinzufügen','Удалить':'Löschen','Пока пусто':'Noch nichts vorhanden','Каталог':'Katalog','Кто в сети':'Wer ist online','Сообщения':'Nachrichten','Серверы':'Server','Фильмы и сериалы':'Filme und Serien','Музыка':'Musik','Продолжить просмотр':'Weiterschauen','Избранное':'Favoriten','Семья':'Familie','Квесты дня':'Tägliche Aufgaben','Достижения':'Erfolge','Подписка SPA':'SPA-Abonnement','Открыть':'Öffnen','Поиск':'Suche','Загрузка…':'Wird geladen…','Пусто':'Leer','Каталог приложений сервера':'Server-App-Katalog','Отзыв (необязательно)':'Bewertung (optional)','Оценить':'Bewerten','Поиск приложений…':'Apps suchen…','Опубликовать':'Veröffentlichen','Снять':'Veröffentlichung aufheben','Добавить в мои приложения':'Zu meinen Apps hinzufügen','Убрать из моих приложений':'Aus meinen Apps entfernen','Создать голосование':'Umfrage erstellen','Создать событие':'Ereignis erstellen','Отправить обращение':'Anfrage senden','Обновить заявку':'Anfrage aktualisieren','Назад':'Zurück','Далее':'Weiter','Завершить':'Abschließen','Начать сначала':'Neu beginnen'},
 'fr':{'Туториал':'Guide','Заметки':'Notes','Совет дня':'Conseil du jour','Настройки StreamLive SPA':'Paramètres de StreamLive SPA','Язык интерфейса (локально)':'Langue de l’interface (locale)','Внешний вид':'Apparence','Тема':'Thème','Тёмная':'Sombre','Светлая':'Clair','Сохранить':'Enregistrer','Добавить':'Ajouter','Удалить':'Supprimer','Пока пусто':'Il n’y a encore rien','Каталог':'Catalogue','Кто в сети':'Qui est en ligne','Сообщения':'Messages','Серверы':'Serveurs','Фильмы и сериалы':'Films et séries','Музыка':'Musique','Продолжить просмотр':'Reprendre','Избранное':'Favoris','Семья':'Famille','Квесты дня':'Quêtes quotidiennes','Достижения':'Succès','Подписка SPA':'Abonnement SPA','Открыть':'Ouvrir','Поиск':'Rechercher','Загрузка…':'Chargement…','Пусто':'Vide','Каталог приложений сервера':'Catalogue des applications du serveur','Отзыв (необязательно)':'Avis (facultatif)','Оценить':'Noter','Поиск приложений…':'Rechercher des applications…','Опубликовать':'Publier','Снять':'Dépublier','Добавить в мои приложения':'Ajouter à mes applications','Убрать из моих приложений':'Retirer de mes applications','Создать голосование':'Créer un sondage','Создать событие':'Créer un événement','Отправить обращение':'Envoyer une demande','Обновить заявку':'Mettre à jour la demande','Назад':'Retour','Далее':'Suivant','Завершить':'Terminer','Начать сначала':'Recommencer'},
 'pt':{'Туториал':'Tutorial','Заметки':'Notas','Совет дня':'Dica do dia','Настройки StreamLive SPA':'Configurações do StreamLive SPA','Язык интерфейса (локально)':'Idioma da interface (local)','Внешний вид':'Aparência','Тема':'Tema','Тёмная':'Escuro','Светлая':'Claro','Сохранить':'Salvar','Добавить':'Adicionar','Удалить':'Excluir','Пока пусто':'Ainda não há nada','Каталог':'Catálogo','Кто в сети':'Quem está online','Сообщения':'Mensagens','Серверы':'Servidores','Фильмы и сериалы':'Filmes e séries','Музыка':'Música','Продолжить просмотр':'Continuar assistindo','Избранное':'Favoritos','Семья':'Família','Квесты дня':'Missões diárias','Достижения':'Conquistas','Подписка SPA':'Assinatura SPA','Открыть':'Abrir','Поиск':'Pesquisar','Загрузка…':'Carregando…','Пусто':'Vazio','Каталог приложений сервера':'Catálogo de apps do servidor','Отзыв (необязательно)':'Avaliação (opcional)','Оценить':'Avaliar','Поиск приложений…':'Pesquisar aplicativos…','Опубликовать':'Publicar','Снять':'Remover publicação','Добавить в мои приложения':'Adicionar aos meus apps','Убрать из моих приложений':'Remover dos meus apps','Создать голосование':'Criar votação','Создать событие':'Criar evento','Отправить обращение':'Enviar solicitação','Обновить заявку':'Atualizar solicitação','Назад':'Voltar','Далее':'Avançar','Завершить':'Concluir','Начать сначала':'Recomeçar'},
 'ar':{'Туториал':'دليل الاستخدام','Заметки':'ملاحظات','Совет дня':'نصيحة اليوم','Настройки StreamLive SPA':'إعدادات StreamLive SPA','Язык интерфейса (локально)':'لغة الواجهة (محلياً)','Внешний вид':'المظهر','Тема':'السمة','Тёмная':'داكن','Светлая':'فاتح','Сохранить':'حفظ','Добавить':'إضافة','Удалить':'حذف','Пока пусто':'لا يوجد شيء بعد','Каталог':'الفهرس','Кто в сети':'المتصلون الآن','Сообщения':'الرسائل','Серверы':'الخوادم','Фильмы и сериалы':'الأفلام والمسلسلات','Музыка':'الموسيقى','Продолжить просмотр':'متابعة المشاهدة','Избранное':'المفضلة','Семья':'العائلة','Квесты дня':'المهام اليومية','Достижения':'الإنجازات','Подписка SPA':'اشتراك SPA','Открыть':'فتح','Поиск':'بحث','Загрузка…':'جارٍ التحميل…','Пусто':'فارغ','Каталог приложений сервера':'دليل تطبيقات الخادم','Отзыв (необязательно)':'مراجعة (اختياري)','Оценить':'تقييم','Поиск приложений…':'البحث عن التطبيقات…','Опубликовать':'نشر','Снять':'إلغاء النشر','Добавить в мои приложения':'إضافة إلى تطبيقاتي','Убрать из моих приложений':'إزالة من تطبيقاتي','Создать голосование':'إنشاء تصويت','Создать событие':'إنشاء حدث','Отправить обращение':'إرسال طلب','Обновить заявку':'تحديث الطلب','Назад':'رجوع','Далее':'التالي','Завершить':'إنهاء','Начать сначала':'البدء من جديد'}
};
Object.keys({'zh-CN': {'⚙️ Продвинутое приложение · импорт OpenAPI': '⚙️ 高级应用 · 导入 OpenAPI', 'Базовый HTTPS URL API': 'API 基础 HTTPS 地址', 'Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.': '高级应用请填写 API 基础地址，而不是网站地址。', 'Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE.': '粘贴 OpenAPI 3.x / Swagger 2.0 JSON。StreamLive 将为 GET、POST、PUT、PATCH 和 DELETE 生成可用表单。', 'Выполнить запрос': '执行请求', 'Bearer-токен (если требуется)': 'Bearer 令牌（如需要）', 'Токен не сохраняется на сервере и очищается при закрытии приложения.': '令牌不会保存在服务器上，关闭应用时会清除。', 'Быстрый тестовый сервис · JSON-макет': '快速测试服务 · JSON 布局'}, 'zh-TW': {'⚙️ Продвинутое приложение · импорт OpenAPI': '⚙️ 進階應用程式 · 匯入 OpenAPI', 'Базовый HTTPS URL API': 'API 基本 HTTPS 位址', 'Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.': '進階應用程式請填寫 API 基本位址，而不是網站位址。', 'Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE.': '貼上 OpenAPI 3.x / Swagger 2.0 JSON。StreamLive 將為 GET、POST、PUT、PATCH 和 DELETE 建立可用表單。', 'Выполнить запрос': '執行請求', 'Bearer-токен (если требуется)': 'Bearer 權杖（如需要）', 'Токен не сохраняется на сервере и очищается при закрытии приложения.': '權杖不會儲存在伺服器，關閉應用程式時會清除。', 'Быстрый тестовый сервис · JSON-макет': '快速測試服務 · JSON 版面'}, 'ko-KR': {'⚙️ Продвинутое приложение · импорт OpenAPI': '⚙️ 고급 앱 · OpenAPI 가져오기', 'Базовый HTTPS URL API': 'API 기본 HTTPS 주소', 'Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.': '고급 앱에는 웹사이트 주소가 아닌 API 기본 주소를 입력하세요.', 'Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE.': 'OpenAPI 3.x / Swagger 2.0 JSON을 붙여넣으세요. StreamLive가 GET, POST, PUT, PATCH, DELETE용 양식을 생성합니다.', 'Выполнить запрос': '요청 실행', 'Bearer-токен (если требуется)': 'Bearer 토큰(필요한 경우)', 'Токен не сохраняется на сервере и очищается при закрытии приложения.': '토큰은 서버에 저장되지 않으며 앱을 닫으면 지워집니다.', 'Быстрый тестовый сервис · JSON-макет': '간단 테스트 서비스 · JSON 레이아웃'}, 'ja': {'⚙️ Продвинутое приложение · импорт OpenAPI': '⚙️ 高度なアプリ · OpenAPI のインポート', 'Базовый HTTPS URL API': 'API の基本 HTTPS URL', 'Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.': '高度なアプリにはウェブサイトではなく API の基本 URL を入力してください。', 'Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE.': 'OpenAPI 3.x / Swagger 2.0 JSON を貼り付けると、GET、POST、PUT、PATCH、DELETE 用のフォームを生成します。', 'Выполнить запрос': 'リクエストを実行', 'Bearer-токен (если требуется)': 'Bearer トークン（必要な場合）', 'Токен не сохраняется на сервере и очищается при закрытии приложения.': 'トークンはサーバーに保存されず、アプリを閉じると消去されます。', 'Быстрый тестовый сервис · JSON-макет': '簡易テストサービス · JSON レイアウト'}, 'es': {'⚙️ Продвинутое приложение · импорт OpenAPI': '⚙️ Aplicación avanzada · importar OpenAPI', 'Базовый HTTPS URL API': 'URL HTTPS base de la API', 'Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.': 'Para una aplicación avanzada, introduce la URL base de la API, no la del sitio web.', 'Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE.': 'Pega JSON de OpenAPI 3.x / Swagger 2.0. StreamLive generará formularios funcionales para GET, POST, PUT, PATCH y DELETE.', 'Выполнить запрос': 'Ejecutar solicitud', 'Bearer-токен (если требуется)': 'Token Bearer (si es necesario)', 'Токен не сохраняется на сервере и очищается при закрытии приложения.': 'El token no se guarda en el servidor y se borra al cerrar la aplicación.', 'Быстрый тестовый сервис · JSON-макет': 'Servicio de prueba rápido · diseño JSON'}, 'de': {'⚙️ Продвинутое приложение · импорт OpenAPI': '⚙️ Erweiterte App · OpenAPI importieren', 'Базовый HTTPS URL API': 'HTTPS-Basis-URL der API', 'Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.': 'Gib für eine erweiterte App die API-Basisadresse und nicht die Website-Adresse ein.', 'Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE.': 'Füge OpenAPI-3.x-/Swagger-2.0-JSON ein. StreamLive erstellt nutzbare Formulare für GET, POST, PUT, PATCH und DELETE.', 'Выполнить запрос': 'Anfrage ausführen', 'Bearer-токен (если требуется)': 'Bearer-Token (falls erforderlich)', 'Токен не сохраняется на сервере и очищается при закрытии приложения.': 'Das Token wird nicht auf dem Server gespeichert und beim Schließen der App gelöscht.', 'Быстрый тестовый сервис · JSON-макет': 'Einfacher Testdienst · JSON-Layout'}, 'fr': {'⚙️ Продвинутое приложение · импорт OpenAPI': '⚙️ Application avancée · importer OpenAPI', 'Базовый HTTPS URL API': 'URL HTTPS de base de l’API', 'Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.': 'Pour une application avancée, indique l’URL de base de l’API, pas celle du site web.', 'Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE.': 'Collez le JSON OpenAPI 3.x / Swagger 2.0. StreamLive générera des formulaires fonctionnels pour GET, POST, PUT, PATCH et DELETE.', 'Выполнить запрос': 'Exécuter la requête', 'Bearer-токен (если требуется)': 'Jeton Bearer (si nécessaire)', 'Токен не сохраняется на сервере и очищается при закрытии приложения.': 'Le jeton n’est pas stocké sur le serveur et est effacé à la fermeture de l’application.', 'Быстрый тестовый сервис · JSON-макет': 'Service de test rapide · modèle JSON'}, 'pt': {'⚙️ Продвинутое приложение · импорт OpenAPI': '⚙️ Aplicativo avançado · importar OpenAPI', 'Базовый HTTPS URL API': 'URL HTTPS base da API', 'Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.': 'Para um aplicativo avançado, informe a URL base da API, não a do site.', 'Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE.': 'Cole o JSON OpenAPI 3.x / Swagger 2.0. O StreamLive criará formulários funcionais para GET, POST, PUT, PATCH e DELETE.', 'Выполнить запрос': 'Executar solicitação', 'Bearer-токен (если требуется)': 'Token Bearer (se necessário)', 'Токен не сохраняется на сервере и очищается при закрытии приложения.': 'O token não é salvo no servidor e é apagado ao fechar o aplicativo.', 'Быстрый тестовый сервис · JSON-макет': 'Serviço de teste rápido · layout JSON'}, 'ar': {'⚙️ Продвинутое приложение · импорт OpenAPI': '⚙️ تطبيق متقدم · استيراد OpenAPI', 'Базовый HTTPS URL API': 'عنوان HTTPS الأساسي لواجهة API', 'Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.': 'للتطبيق المتقدم، أدخل عنوان API الأساسي وليس عنوان الموقع.', 'Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE.': 'الصق JSON الخاص بـ OpenAPI 3.x أو Swagger 2.0. سينشئ StreamLive نماذج عملية لطلبات GET وPOST وPUT وPATCH وDELETE.', 'Выполнить запрос': 'تنفيذ الطلب', 'Bearer-токен (если требуется)': 'رمز Bearer (عند الحاجة)', 'Токен не сохраняется на сервере и очищается при закрытии приложения.': 'لا يُحفظ الرمز على الخادم ويُمسح عند إغلاق التطبيق.', 'Быстрый тестовый сервис · JSON-макет': 'خدمة اختبار سريعة · تخطيط JSON'}}).forEach(function(lang){Object.assign(SPA_EXTRA_LOCALE_TEXT[lang]||(SPA_EXTRA_LOCALE_TEXT[lang]={}), {'zh-CN': {'⚙️ Продвинутое приложение · импорт OpenAPI': '⚙️ 高级应用 · 导入 OpenAPI', 'Базовый HTTPS URL API': 'API 基础 HTTPS 地址', 'Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.': '高级应用请填写 API 基础地址，而不是网站地址。', 'Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE.': '粘贴 OpenAPI 3.x / Swagger 2.0 JSON。StreamLive 将为 GET、POST、PUT、PATCH 和 DELETE 生成可用表单。', 'Выполнить запрос': '执行请求', 'Bearer-токен (если требуется)': 'Bearer 令牌（如需要）', 'Токен не сохраняется на сервере и очищается при закрытии приложения.': '令牌不会保存在服务器上，关闭应用时会清除。', 'Быстрый тестовый сервис · JSON-макет': '快速测试服务 · JSON 布局'}, 'zh-TW': {'⚙️ Продвинутое приложение · импорт OpenAPI': '⚙️ 進階應用程式 · 匯入 OpenAPI', 'Базовый HTTPS URL API': 'API 基本 HTTPS 位址', 'Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.': '進階應用程式請填寫 API 基本位址，而不是網站位址。', 'Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE.': '貼上 OpenAPI 3.x / Swagger 2.0 JSON。StreamLive 將為 GET、POST、PUT、PATCH 和 DELETE 建立可用表單。', 'Выполнить запрос': '執行請求', 'Bearer-токен (если требуется)': 'Bearer 權杖（如需要）', 'Токен не сохраняется на сервере и очищается при закрытии приложения.': '權杖不會儲存在伺服器，關閉應用程式時會清除。', 'Быстрый тестовый сервис · JSON-макет': '快速測試服務 · JSON 版面'}, 'ko-KR': {'⚙️ Продвинутое приложение · импорт OpenAPI': '⚙️ 고급 앱 · OpenAPI 가져오기', 'Базовый HTTPS URL API': 'API 기본 HTTPS 주소', 'Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.': '고급 앱에는 웹사이트 주소가 아닌 API 기본 주소를 입력하세요.', 'Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE.': 'OpenAPI 3.x / Swagger 2.0 JSON을 붙여넣으세요. StreamLive가 GET, POST, PUT, PATCH, DELETE용 양식을 생성합니다.', 'Выполнить запрос': '요청 실행', 'Bearer-токен (если требуется)': 'Bearer 토큰(필요한 경우)', 'Токен не сохраняется на сервере и очищается при закрытии приложения.': '토큰은 서버에 저장되지 않으며 앱을 닫으면 지워집니다.', 'Быстрый тестовый сервис · JSON-макет': '간단 테스트 서비스 · JSON 레이아웃'}, 'ja': {'⚙️ Продвинутое приложение · импорт OpenAPI': '⚙️ 高度なアプリ · OpenAPI のインポート', 'Базовый HTTPS URL API': 'API の基本 HTTPS URL', 'Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.': '高度なアプリにはウェブサイトではなく API の基本 URL を入力してください。', 'Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE.': 'OpenAPI 3.x / Swagger 2.0 JSON を貼り付けると、GET、POST、PUT、PATCH、DELETE 用のフォームを生成します。', 'Выполнить запрос': 'リクエストを実行', 'Bearer-токен (если требуется)': 'Bearer トークン（必要な場合）', 'Токен не сохраняется на сервере и очищается при закрытии приложения.': 'トークンはサーバーに保存されず、アプリを閉じると消去されます。', 'Быстрый тестовый сервис · JSON-макет': '簡易テストサービス · JSON レイアウト'}, 'es': {'⚙️ Продвинутое приложение · импорт OpenAPI': '⚙️ Aplicación avanzada · importar OpenAPI', 'Базовый HTTPS URL API': 'URL HTTPS base de la API', 'Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.': 'Para una aplicación avanzada, introduce la URL base de la API, no la del sitio web.', 'Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE.': 'Pega JSON de OpenAPI 3.x / Swagger 2.0. StreamLive generará formularios funcionales para GET, POST, PUT, PATCH y DELETE.', 'Выполнить запрос': 'Ejecutar solicitud', 'Bearer-токен (если требуется)': 'Token Bearer (si es necesario)', 'Токен не сохраняется на сервере и очищается при закрытии приложения.': 'El token no se guarda en el servidor y se borra al cerrar la aplicación.', 'Быстрый тестовый сервис · JSON-макет': 'Servicio de prueba rápido · diseño JSON'}, 'de': {'⚙️ Продвинутое приложение · импорт OpenAPI': '⚙️ Erweiterte App · OpenAPI importieren', 'Базовый HTTPS URL API': 'HTTPS-Basis-URL der API', 'Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.': 'Gib für eine erweiterte App die API-Basisadresse und nicht die Website-Adresse ein.', 'Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE.': 'Füge OpenAPI-3.x-/Swagger-2.0-JSON ein. StreamLive erstellt nutzbare Formulare für GET, POST, PUT, PATCH und DELETE.', 'Выполнить запрос': 'Anfrage ausführen', 'Bearer-токен (если требуется)': 'Bearer-Token (falls erforderlich)', 'Токен не сохраняется на сервере и очищается при закрытии приложения.': 'Das Token wird nicht auf dem Server gespeichert und beim Schließen der App gelöscht.', 'Быстрый тестовый сервис · JSON-макет': 'Einfacher Testdienst · JSON-Layout'}, 'fr': {'⚙️ Продвинутое приложение · импорт OpenAPI': '⚙️ Application avancée · importer OpenAPI', 'Базовый HTTPS URL API': 'URL HTTPS de base de l’API', 'Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.': 'Pour une application avancée, indique l’URL de base de l’API, pas celle du site web.', 'Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE.': 'Collez le JSON OpenAPI 3.x / Swagger 2.0. StreamLive générera des formulaires fonctionnels pour GET, POST, PUT, PATCH et DELETE.', 'Выполнить запрос': 'Exécuter la requête', 'Bearer-токен (если требуется)': 'Jeton Bearer (si nécessaire)', 'Токен не сохраняется на сервере и очищается при закрытии приложения.': 'Le jeton n’est pas stocké sur le serveur et est effacé à la fermeture de l’application.', 'Быстрый тестовый сервис · JSON-макет': 'Service de test rapide · modèle JSON'}, 'pt': {'⚙️ Продвинутое приложение · импорт OpenAPI': '⚙️ Aplicativo avançado · importar OpenAPI', 'Базовый HTTPS URL API': 'URL HTTPS base da API', 'Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.': 'Para um aplicativo avançado, informe a URL base da API, não a do site.', 'Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE.': 'Cole o JSON OpenAPI 3.x / Swagger 2.0. O StreamLive criará formulários funcionais para GET, POST, PUT, PATCH e DELETE.', 'Выполнить запрос': 'Executar solicitação', 'Bearer-токен (если требуется)': 'Token Bearer (se necessário)', 'Токен не сохраняется на сервере и очищается при закрытии приложения.': 'O token não é salvo no servidor e é apagado ao fechar o aplicativo.', 'Быстрый тестовый сервис · JSON-макет': 'Serviço de teste rápido · layout JSON'}, 'ar': {'⚙️ Продвинутое приложение · импорт OpenAPI': '⚙️ تطبيق متقدم · استيراد OpenAPI', 'Базовый HTTPS URL API': 'عنوان HTTPS الأساسي لواجهة API', 'Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.': 'للتطبيق المتقدم، أدخل عنوان API الأساسي وليس عنوان الموقع.', 'Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE.': 'الصق JSON الخاص بـ OpenAPI 3.x أو Swagger 2.0. سينشئ StreamLive نماذج عملية لطلبات GET وPOST وPUT وPATCH وDELETE.', 'Выполнить запрос': 'تنفيذ الطلب', 'Bearer-токен (если требуется)': 'رمز Bearer (عند الحاجة)', 'Токен не сохраняется на сервере и очищается при закрытии приложения.': 'لا يُحفظ الرمز على الخادم ويُمسح عند إغلاق التطبيق.', 'Быстрый тестовый сервис · JSON-макет': 'خدمة اختبار سريعة · تخطيط JSON'}}[lang]);});

const __slApiDiagTranslations = {"en": {"Диагностика запросов": "Request diagnostics", "Очистить": "Clear", "Запросов в этой сессии пока нет. История не содержит токенов и тел запросов.": "No requests in this session yet. History contains no tokens or request bodies.", "История пока пуста": "History is empty", "Этот запрос может изменить или удалить данные во внешнем сервисе. Продолжить?": "This request may change or delete data in the external service. Continue?", "Выполняю запрос…": "Sending request…", "Превышено время ожидания (20 секунд)": "Request timed out (20 seconds)", "Ошибка": "Error"}, "uk": {"Диагностика запросов": "Діагностика запитів", "Очистить": "Очистити", "Запросов в этой сессии пока нет. История не содержит токенов и тел запросов.": "У цій сесії ще немає запитів. Історія не містить токенів і тіл запитів.", "История пока пуста": "Історія порожня", "Этот запрос может изменить или удалить данные во внешнем сервисе. Продолжить?": "Цей запит може змінити або видалити дані у зовнішньому сервісі. Продовжити?", "Выполняю запрос…": "Виконую запит…", "Превышено время ожидания (20 секунд)": "Час очікування минув (20 секунд)", "Ошибка": "Помилка"}, "zh-CN": {"Диагностика запросов": "请求诊断", "Очистить": "清除", "Запросов в этой сессии пока нет. История не содержит токенов и тел запросов.": "本次会话尚无请求。历史记录不包含令牌或请求正文。", "История пока пуста": "历史记录为空", "Этот запрос может изменить или удалить данные во внешнем сервисе. Продолжить?": "此请求可能会更改或删除外部服务中的数据。继续吗？", "Выполняю запрос…": "正在发送请求…", "Превышено время ожидания (20 секунд)": "请求超时（20 秒）", "Ошибка": "错误"}, "zh-TW": {"Диагностика запросов": "請求診斷", "Очистить": "清除", "Запросов в этой сессии пока нет. История не содержит токенов и тел запросов.": "此工作階段尚無請求。歷史紀錄不包含權杖或請求本文。", "История пока пуста": "歷史紀錄是空的", "Этот запрос может изменить или удалить данные во внешнем сервисе. Продолжить?": "此請求可能會變更或刪除外部服務中的資料。要繼續嗎？", "Выполняю запрос…": "正在執行請求…", "Превышено время ожидания (20 секунд)": "請求逾時（20 秒）", "Ошибка": "錯誤"}, "ko-KR": {"Диагностика запросов": "요청 진단", "Очистить": "지우기", "Запросов в этой сессии пока нет. История не содержит токенов и тел запросов.": "이 세션에는 아직 요청이 없습니다. 기록에는 토큰과 요청 본문이 저장되지 않습니다.", "История пока пуста": "기록이 비어 있습니다", "Этот запрос может изменить или удалить данные во внешнем сервисе. Продолжить?": "이 요청은 외부 서비스의 데이터를 변경하거나 삭제할 수 있습니다. 계속할까요?", "Выполняю запрос…": "요청 실행 중…", "Превышено время ожидания (20 секунд)": "요청 시간 초과(20초)", "Ошибка": "오류"}, "ja": {"Диагностика запросов": "リクエスト診断", "Очистить": "クリア", "Запросов в этой сессии пока нет. История не содержит токенов и тел запросов.": "このセッションにはまだリクエストがありません。履歴にトークンやリクエスト本文は保存されません。", "История пока пуста": "履歴は空です", "Этот запрос может изменить или удалить данные во внешнем сервисе. Продолжить?": "このリクエストは外部サービスのデータを変更または削除する可能性があります。続行しますか？", "Выполняю запрос…": "リクエストを実行中…", "Превышено время ожидания (20 секунд)": "タイムアウト（20 秒）", "Ошибка": "エラー"}, "es": {"Диагностика запросов": "Diagnóstico de solicitudes", "Очистить": "Limpiar", "Запросов в этой сессии пока нет. История не содержит токенов и тел запросов.": "Aún no hay solicitudes en esta sesión. El historial no contiene tokens ni cuerpos de solicitud.", "История пока пуста": "El historial está vacío", "Этот запрос может изменить или удалить данные во внешнем сервисе. Продолжить?": "Esta solicitud puede modificar o eliminar datos del servicio externo. ¿Continuar?", "Выполняю запрос…": "Enviando solicitud…", "Превышено время ожидания (20 секунд)": "Tiempo de espera agotado (20 segundos)", "Ошибка": "Error"}, "de": {"Диагностика запросов": "Anfragediagnose", "Очистить": "Leeren", "Запросов в этой сессии пока нет. История не содержит токенов и тел запросов.": "In dieser Sitzung gibt es noch keine Anfragen. Der Verlauf enthält keine Token oder Anfrageinhalte.", "История пока пуста": "Der Verlauf ist leer", "Этот запрос может изменить или удалить данные во внешнем сервисе. Продолжить?": "Diese Anfrage kann Daten im externen Dienst ändern oder löschen. Fortfahren?", "Выполняю запрос…": "Anfrage wird gesendet…", "Превышено время ожидания (20 секунд)": "Zeitüberschreitung (20 Sekunden)", "Ошибка": "Fehler"}, "fr": {"Диагностика запросов": "Diagnostic des requêtes", "Очистить": "Effacer", "Запросов в этой сессии пока нет. История не содержит токенов и тел запросов.": "Aucune requête dans cette session. L’historique ne contient ni jetons ni corps de requête.", "История пока пуста": "L’historique est vide", "Этот запрос может изменить или удалить данные во внешнем сервисе. Продолжить?": "Cette requête peut modifier ou supprimer des données du service externe. Continuer ?", "Выполняю запрос…": "Envoi de la requête…", "Превышено время ожидания (20 секунд)": "Délai dépassé (20 secondes)", "Ошибка": "Erreur"}, "pt": {"Диагностика запросов": "Diagnóstico de solicitações", "Очистить": "Limpar", "Запросов в этой сессии пока нет. История не содержит токенов и тел запросов.": "Ainda não há solicitações nesta sessão. O histórico não contém tokens nem corpos de solicitação.", "История пока пуста": "O histórico está vazio", "Этот запрос может изменить или удалить данные во внешнем сервисе. Продолжить?": "Esta solicitação pode alterar ou excluir dados no serviço externo. Continuar?", "Выполняю запрос…": "Enviando solicitação…", "Превышено время ожидания (20 секунд)": "Tempo limite excedido (20 segundos)", "Ошибка": "Erro"}, "ar": {"Диагностика запросов": "تشخيص الطلبات", "Очистить": "مسح", "Запросов в этой сессии пока нет. История не содержит токенов и тел запросов.": "لا توجد طلبات في هذه الجلسة بعد. لا يحتوي السجل على الرموز أو محتويات الطلبات.", "История пока пуста": "السجل فارغ", "Этот запрос может изменить или удалить данные во внешнем сервисе. Продолжить?": "قد يؤدي هذا الطلب إلى تغيير البيانات أو حذفها من الخدمة الخارجية. هل تريد المتابعة؟", "Выполняю запрос…": "جارٍ تنفيذ الطلب…", "Превышено время ожидания (20 секунд)": "انتهت مهلة الطلب (20 ثانية)", "Ошибка": "خطأ"}};
Object.keys(__slApiDiagTranslations).forEach(lang => Object.assign(SPA_EXTRA_LOCALE_TEXT[lang] || (SPA_EXTRA_LOCALE_TEXT[lang] = {}), __slApiDiagTranslations[lang]));

const __slApiExportTranslations = {"en":{"Скачать конфигурацию":"Download configuration"},"uk":{"Скачать конфигурацию":"Завантажити конфігурацію"},"zh-CN":{"Скачать конфигурацию":"下载配置"},"zh-TW":{"Скачать конфигурацию":"下載設定"},"ko-KR":{"Скачать конфигурацию":"구성 다운로드"},"ja":{"Скачать конфигурацию":"設定をダウンロード"},"es":{"Скачать конфигурацию":"Descargar configuración"},"de":{"Скачать конфигурацию":"Konfiguration herunterladen"},"fr":{"Скачать конфигурацию":"Télécharger la configuration"},"pt":{"Скачать конфигурацию":"Baixar configuração"},"ar":{"Скачать конфигурацию":"تنزيل الإعدادات"}};
Object.keys(__slApiExportTranslations).forEach(lang => Object.assign(SPA_EXTRA_LOCALE_TEXT[lang] || (SPA_EXTRA_LOCALE_TEXT[lang] = {}), __slApiExportTranslations[lang]));

const SPA_RU_EN_TEXT = {
  'Settings':'Настройки','Search':'Поиск','Loading…':'Загрузка…','Loading...':'Загрузка…','Open':'Открыть','Close':'Закрыть','Save':'Сохранить','Cancel':'Отмена','Delete':'Удалить','Edit':'Изменить','Add':'Добавить','Back':'Назад','Next':'Далее','Done':'Готово','Continue':'Продолжить','Retry':'Повторить','Refresh':'Обновить','Share':'Поделиться','Copy':'Копировать','Download':'Скачать','Upload':'Загрузить','Send':'Отправить','Create':'Создать','Apply':'Применить','Reset':'Сбросить','Clear':'Очистить','Remove':'Убрать','All':'Все','People':'Люди','Channels':'Каналы','Videos':'Видео','Online':'В сети','Offline':'Не в сети','Profile':'Профиль','Catalog':'Каталог','Favorites':'Избранное','Messages':'Сообщения','Music':'Музыка','Movies & series':'Фильмы и сериалы','Continue watching':'Продолжить просмотр','Interface language':'Язык интерфейса','Appearance':'Внешний вид','Dark':'Тёмная','Light':'Светлая','Theme':'Тема','Compact list':'Компактный список','Font size':'Размер шрифта','Privacy':'Приватность','Sound':'Звук','Other':'Прочее','Server':'Сервер','Today':'Сегодня','Today’s limit':'Лимит сегодня','No data':'Нет данных','Empty':'Пусто','Nothing here yet':'Здесь пока пусто','No results':'Ничего не найдено','Show build':'Показывать номер сборки','Build':'Сборка','Premium':'Премиум','API key':'Ключ API','API connection':'Подключение к API','Request failed':'Не удалось выполнить запрос','Network error':'Ошибка сети','Unknown error':'Неизвестная ошибка','Sign in':'Войти','Log in':'Войти','Log out':'Выйти','Sign out':'Выйти','Register':'Регистрация','Password':'Пароль','Username':'Имя пользователя','Email':'Электронная почта','Required':'Обязательно','Optional':'Необязательно','Enabled':'Включено','Disabled':'Выключено','On':'Вкл.','Off':'Выкл.','Yes':'Да','No':'Нет','Keep screen awake':'Не выключать экран','Vibration':'Вибрация','Soft colors':'Мягкие цвета','Reduce motion':'Меньше анимаций','Floating comments':'Летящие комментарии','Autoplay':'Автовоспроизведение','Default filter':'Фильтр при входе','Ask before opening':'Спрашивать перед открытием','Player and comments':'Плеер и комментарии','Interface sounds':'Звук интерфейса','UI volume':'Громкость интерфейса','Test sound':'Проверить звук','Automatic theme by time':'Авто-тема по времени','Show online status':'Показывать «сейчас онлайн»','Hide Online tab':'Скрыть вкладку Онлайн','Show tutorial again':'Снова туториал','Export settings':'Экспорт настроек','Reset local data':'Сбросить локальные данные','Today’s limits':'Лимиты сегодня','Today’s limit':'Лимит сегодня','Screen capture':'Захват экрана','My files':'Мои файлы','Server app catalog':'Каталог приложений сервера','Server services':'Сервисы сервера','Server dashboard':'Панель сервера','Create service':'Создать сервис','Add service':'Добавить сервис','Published':'Опубликовано','Draft':'Черновик','Install':'Установить','Uninstall':'Удалить приложение','Review (optional)':'Отзыв (необязательно)','Rate':'Оценить','Search apps…':'Поиск приложений…','No apps yet':'Приложений пока нет','Open app':'Открыть приложение'
};
const __spaTextOriginals = new WeakMap();
const __spaAttrOriginals = new WeakMap();
function spaLocaleText(raw, fallback) {
  const l=spaLang();
  if (l === 'ru') return SPA_RU_EN_TEXT[raw] || raw;
  return (SPA_SETTINGS_I18N[l] && SPA_SETTINGS_I18N[l][raw]) || (SPA_EXTRA_LOCALE_TEXT[l] && SPA_EXTRA_LOCALE_TEXT[l][raw]) || fallback || raw;
}
function spaTranslateDom(root) {
  try {
    if (!root) return;
    const lang = spaLang();
    const idx = lang === 'uk' ? 1 : 0;
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
    const nodes = []; while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach(n => {
      if (!__spaTextOriginals.has(n)) __spaTextOriginals.set(n, n.nodeValue || '');
      const source = __spaTextOriginals.get(n) || '';
      const trimmed = source.trim();
      if (!trimmed) return;
      const fallback = SPA_TEXT_I18N[trimmed] ? SPA_TEXT_I18N[trimmed][idx] : '';
      const translated = spaLocaleText(trimmed, fallback);
      const leading = source.match(/^\s*/)?.[0] || '', trailing = source.match(/\s*$/)?.[0] || '';
      n.nodeValue = leading + (translated || trimmed) + trailing;
    });
    root.querySelectorAll?.('[placeholder],[title],[aria-label]').forEach(el => {
      ['placeholder','title','aria-label'].forEach(a => {
        const current=el.getAttribute(a); if(current==null)return;
        let originals=__spaAttrOriginals.get(el); if(!originals){originals={};__spaAttrOriginals.set(el,originals);} if(!(a in originals)) originals[a]=current;
        const v=originals[a], fb=SPA_TEXT_I18N[v]?SPA_TEXT_I18N[v][idx]:'';
        const tr=spaLocaleText(v,fb); if(tr&&tr!==v)el.setAttribute(a,tr); else el.setAttribute(a,v);
      });
    });
  } catch(e) { console.warn('spaTranslateDom', e); }
}

function $(id) { return document.getElementById(id); }

function isDesktop() {
  try { return window.matchMedia && window.matchMedia('(min-width:900px)').matches; } catch(e) { return window.innerWidth >= 900; }
}
function wireTutorial(){
  // Старый модальный контейнер оставлен для совместимости; актуальный туториал
  // рендерится в панели через openTutorial и сам назначает обработчики шагов.
  const legacy=$('tut-ov'); if(legacy) legacy.classList.remove('on');
  return true;
}

function openTutorial(force) {
  if(!force){try{if(localStorage.getItem('spa_tutorial_done')==='1')return;}catch(e){}}
  try { closeDrawer(); } catch(e) {}
  const pages = [
    { t:'Добро пожаловать', b:'StreamLive SPA — каталог, кино, музыка, серверы и личка в одном интерфейсе.' },
    { t:'Каталог', b:'Вкладки Люди / Каналы / Видео / Кино. Используй поиск, чтобы быстро находить контент.' },
    { t:'Меню', b:'Профиль, онлайн, серверы, избранное, настройки, кино, музыка, семья, сообщения, квесты и достижения.' },
    { t:'Сервер и приложения', b:'В сервере есть чат, роли, события, опросы и каталог приложений. Владелец публикует приложения для участников.' },
    { t:'Подписка и сервисы', b:'Создание пользовательских сервисов доступно при активной подписке SPA: до 10 созданий за 30 дней. Удаление не возвращает попытку.' },
    { t:'Семья', b:'Взрослый и детский профиль, PIN, возрастные ограничения и блокировка нежелательных карточек.' },
    { t:'Кино и музыка', b:'Кино открывается через доступные источники и плееры. Музыка и локальные треки доступны в соответствующем разделе.' },
    { t:'Сообщения и достижения', b:'Открывай личные диалоги из профиля, используй стикеры, выполняй квесты и собирай достижения.' },
    { t:'Готово', b:'Туториал можно открыть повторно из меню. Приятного использования StreamLive 💜' }
  ];
  let i=0;
  const paint=()=>{
    const p=pages[i];
    setDetailTitle('Туториал · '+(i+1)+'/'+pages.length);
    const target=showInPane(`<div class="card" style="background:linear-gradient(135deg,rgba(109,94,252,.25),rgba(0,0,0,.1))"><div class="label">Шаг ${i+1} из ${pages.length}</div><div class="val" style="font-size:20px;margin-top:6px">${esc(p.t)}</div><p style="font-size:14px;color:var(--muted);margin:12px 0 0;line-height:1.55">${esc(p.b)}</p></div><div style="display:flex;gap:8px;margin-top:12px"><button type="button" class="btn-primary" data-tutorial-action="prev" style="margin:0;flex:1;background:rgba(255,255,255,.08)" ${i===0?'disabled':''}>← Назад</button><button type="button" class="btn-primary" data-tutorial-action="next" style="margin:0;flex:1">${i===pages.length-1?'Завершить':'Далее →'}</button></div><div style="display:flex;gap:4px;justify-content:center;margin-top:14px">${pages.map((_,j)=>`<span style="width:8px;height:8px;border-radius:50%;background:${j===i?'var(--accent)':'rgba(255,255,255,.2)'}"></span>`).join('')}</div><button type="button" class="btn-primary" data-tutorial-action="restart" style="margin-top:12px;background:rgba(255,255,255,.07)">Начать сначала</button>`);
    if(!target)return;
    target.querySelectorAll('[data-tutorial-action]').forEach(btn=>{btn.onclick=(ev)=>{ev.preventDefault();ev.stopPropagation();const action=btn.dataset.tutorialAction;if(action==='prev'){i=Math.max(0,i-1);paint();return;}if(action==='restart'){i=0;paint();return;}if(i<pages.length-1){i++;paint();return;}try{localStorage.setItem('spa_tutorial_done','1');}catch(e){}if(isDesktop()){const pb=$('pane-body');if(pb)pb.style.display='none';const pe=$('pane-empty');if(pe)pe.style.display='flex';}else{const ov=$('detail-ov');if(ov)ov.classList.remove('on');}setDetailTitle('Просмотр');};});
  };
  paint();
}

/** Быстрые заметки SPA (локально) */
function spaNotesGet() {
  try { return JSON.parse(localStorage.getItem('spa_notes_v1')||'[]'); } catch(e) { return []; }
}
function spaNotesSet(arr) {
  try { localStorage.setItem('spa_notes_v1', JSON.stringify(arr.slice(0,40))); } catch(e) {}
}
function openSpaNotes() {
  try { closeDrawer(); } catch(e) {}
  setDetailTitle('Заметки');
  const list = spaNotesGet();
  showInPane(`
    <div class="card"><div class="val">📝 Заметки</div>
      <p style="font-size:12px;color:var(--muted);margin:6px 0 0">Только на этом устройстве</p></div>
    <textarea class="field" id="note-in" rows="3" style="min-height:72px;resize:vertical" placeholder="Новая заметка…"></textarea>
    <button type="button" class="btn-primary" id="note-add" style="margin:0">Добавить</button>
    <div id="note-list" style="margin-top:12px">
      ${list.map((n,idx)=>`<div class="card" style="margin:0 0 8px"><div style="font-size:14px;white-space:pre-wrap">${esc(n.text)}</div>
        <div style="font-size:11px;color:var(--muted);margin-top:6px">${esc(n.at||'')}</div>
        <button type="button" class="btn-primary note-del" data-i="${idx}" style="margin-top:8px;background:rgba(127,29,29,.5)">Удалить</button></div>`).join('')
        || '<div class="empty">Пока пусто</div>'}
    </div>
  `);
  if ($('note-add')) $('note-add').onclick = () => {
    const text = ($('note-in').value||'').trim();
    if (!text) return;
    const arr = spaNotesGet();
    arr.unshift({ text: text.slice(0,500), at: new Date().toLocaleString('ru-RU') });
    spaNotesSet(arr);
    spaPlay('ok');
    openSpaNotes();
  };
  document.querySelectorAll('.note-del').forEach(b => {
    b.onclick = () => {
      const arr = spaNotesGet();
      arr.splice(+b.dataset.i, 1);
      spaNotesSet(arr);
      openSpaNotes();
    };
  });
}

/** Случайный совет дня */

/** История просмотров за 24ч (локально) */
function spaWatchHistGet() {
  try {
    const arr = JSON.parse(localStorage.getItem('spa_watch_hist_v1')||'[]');
    const cut = Date.now() - 864e5;
    return arr.filter(x => x && x.ts >= cut).slice(0, 80);
  } catch(e) { return []; }
}
function spaWatchHistAdd(entry) {
  try {
    const arr = spaWatchHistGet();
    arr.unshift({
      kind: entry.kind || 'video',
      id: entry.id || '',
      title: String(entry.title||'').slice(0,120),
      ts: Date.now()
    });
    localStorage.setItem('spa_watch_hist_v1', JSON.stringify(arr.slice(0,80)));
    if(state.user && entry && entry.id) api('media_history_save',{method:'POST',body:{kind:entry.kind||'video',id:entry.id,title:String(entry.title||'').slice(0,180)}}).catch(()=>{});
  } catch(e) {}
}
async function openWatchHistory() {
  try { closeDrawer(); } catch(e) {}
  setDetailTitle('История · 30 дней');
  let list = spaWatchHistGet();
  const cinemaAllowed = await spaCinemaGate(false);
  try { const remote=await api('media_history_list'); if(remote&&remote.ok&&Array.isArray(remote.items)) list=remote.items.map(x=>({kind:x.kind,id:x.id,title:x.title,ts:Number(x.ts)})); } catch(e) {}
  if (!cinemaAllowed) list = list.filter(x => x.kind !== 'cinema');
  showInPane(`
    <div class="card"><div class="val">🕑 История просмотров</div>
      <p style="font-size:12px;color:var(--muted);margin:6px 0 0">Синхронизируется с аккаунтом · авто-очистка старше 30 дней</p></div>
    ${list.length ? list.map(h => {
      const when = new Date(h.ts).toLocaleString('ru-RU',{hour:'2-digit',minute:'2-digit',day:'2-digit',month:'2-digit'});
      return `<div class="row" data-kind="${esc(h.kind)}" data-id="${esc(h.id)}" data-title="${esc(h.title)}">
        <div class="av letter">${h.kind==='channel'?'📺':(h.kind==='cinema'?'🎬':'▶')}</div>
        <div class="body"><div class="title">${esc(h.title||h.kind)}</div>
        <div class="sub">${esc(h.kind)} · ${esc(when)}</div></div></div>`;
    }).join('') : '<div class="empty">Пока пусто — открой канал, видео или фильм</div>'}
    <button type="button" class="btn-primary" id="hist-clear" style="margin-top:12px;background:rgba(127,29,29,.5)">Очистить</button>
  `);
  if ($('hist-clear')) $('hist-clear').onclick = async () => {
    try { localStorage.removeItem('spa_watch_hist_v1'); } catch(e) {}
    try { await api('media_history_clear',{method:'POST',body:{}}); } catch(e) {}
    openWatchHistory();
  };
  document.querySelectorAll('#detail-body .row[data-kind], #pane-body .row[data-kind]').forEach(r => {
    r.onclick = () => {
      const k = r.dataset.kind, id = r.dataset.id;
      if (k==='channel') openChannel(id);
      else if (k==='video') openVideo(id);
      else if (k==='cinema') openCinemaFilm(id);
    };
  });
}


/** Стикеры из движка (sticker_packs) */
let __spaStickerPacks = null;
async function loadEngineStickers(force = false) {
  if (!force && __spaStickerPacks !== null) return __spaStickerPacks;
  try {
    const d = await api('stickers_list');
    if (d && d.ok && Array.isArray(d.packs)) {
      // Пустой массив — валидный ответ; сетевые/SQL-ошибки не кэшируем,
      // чтобы пользователь мог повторить загрузку без перезагрузки SPA.
      __spaStickerPacks = d.packs;
      return __spaStickerPacks;
    }
  } catch(e) {}
  return __spaStickerPacks || [];
}
function normalizeStickerCode(code) {
  return String(code || '').trim().replace(/^:+|:+$/g, '').toLowerCase();
}
function stickerToken(code) {
  const raw = String(code || '').trim();
  if (!raw) return '';
  return (raw.startsWith(':') && raw.endsWith(':')) ? raw : ':' + raw.replace(/^:+|:+$/g, '') + ':';
}
function stickerCodeMap() {
  const map = {};
  (__spaStickerPacks || []).forEach(p => (p.items||[]).forEach(it => {
    if (!it || !it.code) return;
    const u = it.url || it.image_url || it.image || '';
    if (!u) return;
    // В StreamLive код хранится как :name:, а текст сообщения содержит :name:.
    // Для поиска используем нормализованный ключ без двоеточий.
    map[normalizeStickerCode(it.code)] = u;
  }));
  return map;
}
function renderStickerText(body) {
  const raw = String(body || '');
  const map = stickerCodeMap();
  // уже HTML не трогаем
  let s = esc(raw);
  // :code: и :Code-Name:
  s = s.replace(/:([^:\s]{1,80}):/g, (m, code) => {
    const url = map[normalizeStickerCode(code)];
    if (url) {
      return '<img class="spa-sticker" src="'+esc(url)+'" alt=":'+esc(code)+':" style="width:72px;height:72px;object-fit:contain;vertical-align:middle;display:inline-block" loading="lazy">';
    }
    return m;
  });
  // если сообщение целиком URL картинки стикера
  if (/^https?:\/\/\S+\.(png|webp|gif|jpg|jpeg)(\?\S*)?$/i.test(raw.trim())) {
    return '<img class="spa-sticker" src="'+esc(raw.trim())+'" alt="sticker" style="width:96px;height:96px;object-fit:contain" loading="lazy">';
  }
  return s;
}
async function renderStickerTextAsync(body) {
  await loadEngineStickers();
  return renderStickerText(body);
}
function engineStickerBarHtml(id) {
  return `<div id="${id}" class="engine-stickers" style="margin:8px 0">
    <div style="font-size:11px;color:var(--muted);margin-bottom:6px">Стикеры StreamLive</div>
    <div class="empty" style="padding:8px">Загрузка…</div>
  </div>`;
}
async function fillEngineStickerBar(id, onPick) {
  const host = $(id);
  if (!host) return;
  const packs = await loadEngineStickers();
  if (!packs.length) {
    host.innerHTML = '<div style="font-size:12px;color:var(--muted)">Нет стикер-паков в БД. Добавь в sticker_packs движка.</div>';
    return;
  }
  let html = '';
  packs.forEach(p => {
    html += `<div style="font-size:11px;color:var(--muted);margin:6px 0 4px">${esc(p.title)}</div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;max-height:140px;overflow-y:auto">`;
    (p.items||[]).forEach(it => {
      html += `<button type="button" data-code="${esc(it.code)}" title="${esc(stickerToken(it.code))}" style="width:52px;height:52px;padding:4px;border-radius:10px;background:#141414;border:1px solid rgba(255,255,255,.1)">
        <img src="${esc(it.url||it.image_url||'')}" alt="" style="width:100%;height:100%;object-fit:contain" loading="lazy" onerror="this.parentNode.style.display=\\'none\\'">
      </button>`;
    });
    html += '</div>';
  });
  host.innerHTML = html;
  host.querySelectorAll('button[data-code]').forEach(b => {
    b.onclick = () => { if (typeof onPick === 'function') onPick(stickerToken(b.dataset.code)); };
  });
}
async function serverXpFromAch(pts, reason) {
  try {
    const m=String(reason||'');if(m.startsWith('ach:'))await api('profile_xp_achievement',{method:'POST',body:{achievement:m.slice(4)}});
    if (!state.myServer) return;
    await api('server_xp_add', { method:'POST', body:{ pts: pts||5, reason: reason||'ach' } });
  } catch(e) {}
}

const DARK_STICKERS = ['🖤','💀','🔥','🌑','🦇','🗡️','🩸','😈','🕳️','🕶️','📻','📼','💜','🌀','⚡','🌙','🕯️','🧿','♟️','🖤'];
function darkStickerBar(id, onPick) {
  return `<div class="dark-sticker-pack" id="${id}">
    ${DARK_STICKERS.map(s => `<button type="button" data-s="${s}" title="стикер">${s}</button>`).join('')}
  </div>`;
}
function bindDarkStickers(id, sendFn) {
  const el = $(id);
  if (!el) return;
  el.querySelectorAll('button').forEach(b => {
    b.onclick = () => { if (typeof sendFn === 'function') sendFn(b.dataset.s); };
  });
}

// Every server gets its own deterministic sticker ordering. Dark branding is
// selected only when the server name clearly signals a dark/crime aesthetic;
// all other servers receive the friendly/light collection.
const SERVER_DARK_STICKERS = ['🖤','💀','🔥','🌑','🦇','🗡️','🩸','😈','🕳️','🕶️','📻','📼','💜','🌀','⚡','🌙','🕯️','🧿','♟️','🐍','🐺','🦂','🕸️','🪦','⛓️','🥀','🧛','🧟','👁️','🚬','🥷','🌘','🦉','🧨','⚔️','🕷️'];
const SERVER_LIGHT_STICKERS = ['😀','😁','😎','🥳','🎉','🌟','☀️','🌈','💛','💚','💙','💖','🌸','🌺','🌻','🍀','🦋','🐬','🐼','🦊','🐻','🐣','🐱','🐶','🏆','👑','🤝','❤️','✨','🎈','🎵','🚀','🌼','🧡','💫','🕊️'];
function makeServerStickerPack(server) {
  const name = String((server && server.name) || '').toLowerCase();
  const darkWords = ['чушпан','тёмн','темн','dark','shadow','mafia','crime','gang','криминал','банд','мрак','ноч','black','blood','улиц','тюрьм','зона','вор'];
  const dark = darkWords.some(word => name.includes(word));
  const source = dark ? SERVER_DARK_STICKERS : SERVER_LIGHT_STICKERS;
  const seedText = String((server && server.id) || '') + ':' + name;
  let seed = 0;
  for (let i=0;i<seedText.length;i++) seed = (seed * 31 + seedText.charCodeAt(i)) >>> 0;
  const items = source.slice();
  for (let i=items.length-1;i>0;i--) {
    seed = (seed * 1664525 + 1013904223) >>> 0;
    const j = seed % (i+1);
    [items[i],items[j]] = [items[j],items[i]];
  }
  return { dark, items, title: dark ? 'Тёмная коллекция' : 'Светлая коллекция' };
}
function serverStickerBarHtml(pack) {
  const cls = pack.dark ? 'dark-sticker-pack' : 'light-sticker-pack';
  return `<div class="server-sticker-caption">${pack.title} · личный набор этого сервера</div><div class="${cls}" id="srv-server-st">${pack.items.map(s => `<button type="button" data-s="${s}" title="Стикер сервера">${s}</button>`).join('')}</div>`;
}
function bindServerStickers(id, sendFn) {
  const el = $(id);
  if (!el) return;
  el.querySelectorAll('button[data-s]').forEach(b => {
    b.onclick = () => { if (typeof sendFn === 'function') sendFn(b.dataset.s); };
  });
}

function openSpaTip() {
  try { closeDrawer(); } catch(e) {}
  const tips = [
    'Вступи в сервер — лимиты просмотров растут с числом людей.',
    'В Семье задай PIN и детский профиль до 12+.',
    'В кино нужен ключ kinopoiskapiunofficial.tech — один на устройство или ключ сервера.',
    'Музыку можно слушать с экрана выкл. — треки хранятся локально.',
    'Ачивки не сбрасываются при обновлении. Квесты — с 03:00 МСК.',
    'Жми 🚫 на канале/видео чтобы скрыть в текущем семейном профиле.',
    'Из профиля друга → «Написать» открывает личку.',
    'OLED-тема экономит батарею на AMOLED.'
  ];
  const tip = tips[Math.floor(Math.random()*tips.length)];
  setDetailTitle('Совет');
  showInPane(`
    <div class="card" style="background:linear-gradient(135deg,rgba(251,191,36,.2),rgba(109,94,252,.15))">
      <div class="val">💡 Совет</div>
      <p style="font-size:15px;margin:12px 0 0;line-height:1.5">${esc(tip)}</p>
    </div>
    <button type="button" class="btn-primary" id="tip-next" style="margin-top:12px">Ещё совет</button>
  `);
  if ($('tip-next')) $('tip-next').onclick = () => openSpaTip();
}


function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function api(name, opts) {
  opts = opts || {};
  const base = (BOOT && BOOT.self) ? String(BOOT.self) : 'streamspa.php';
  const method = (opts.method || 'GET').toUpperCase();
  let url = base + (base.indexOf('?') >= 0 ? '&' : '?') + 'api=' + encodeURIComponent(name) + '&client_build=' + encodeURIComponent(String(BOOT.build||''));
  if (opts.params) {
    Object.keys(opts.params).forEach(k => {
      if (opts.params[k] == null) return;
      url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(String(opts.params[k]));
    });
  }
  const init = { method: method, credentials: 'same-origin', cache: 'no-store', headers:{Accept:'application/json'} };
  if (method === 'POST' || method === 'PUT') {
    const body = opts.body || {};
    const fd = new FormData();
    Object.keys(body).forEach(k => {
      let v = body[k];
      if (v != null && typeof v === 'object') v = JSON.stringify(v);
      fd.append(k, v == null ? '' : String(v));
    });
    init.body = fd;
  }
  const requestOnce = () => fetch(url, init).then(async r => {
    const text = await r.text();
    const trimmed = String(text||'').replace(/^\uFEFF/, '').trim();
    const contentType = String(r.headers.get('content-type')||'').toLowerCase();
    let data = null;
    try {
      if (!trimmed) throw new Error('empty response');
      if (!(trimmed.charAt(0)==='{' || trimmed.charAt(0)==='[')) {
        if (/text\/html|<!doctype|<html/i.test(contentType+' '+trimmed.slice(0,160))) {
          const loginPage = /login|вход|авторизац/i.test(trimmed.slice(0,1200));
          return {ok:false, error:loginPage?'Сессия входа не подтверждена на этом зеркале. Войди заново в этой вкладке.':'Зеркало вернуло HTML вместо JSON. Проверь адрес API и PHP-обработчик.', code:loginPage?'session_required':'html_response', http:r.status};
        }
        throw new Error('response is not JSON');
      }
      data = JSON.parse(trimmed);
    } catch (e) {
      return {ok:false,error:'Зеркало вернуло некорректный JSON-ответ.',code:'invalid_json',http:r.status,content_type:contentType,details:String(e&&e.message||e),raw:trimmed.slice(0,240)};
    }
    if (!data || typeof data !== 'object') data = {ok:false,error:'Пустой ответ сервера',http:r.status};
    if (!('ok' in data)) data.ok = r.ok;
    if (!r.ok && data.ok) data.ok = false;
    data.http = r.status;
    return data;
  });
  return (async () => {
    const maxAttempts = method === 'GET' ? 3 : 1;
    let last = null;
    for (let attempt = 0; attempt < maxAttempts; attempt++) {
      try {
        last = await requestOnce();
        const transient = last && (last.code === 'network_error' || [502,503,504].includes(Number(last.http)));
        if (!transient || attempt + 1 >= maxAttempts) return last;
      } catch (e) {
        last = {ok:false,error:String(e && e.message || e),code:'network_error'};
        if (attempt + 1 >= maxAttempts) return last;
      }
      await new Promise(resolve => setTimeout(resolve, 350 * Math.pow(2, attempt)));
    }
    return last || {ok:false,error:'Сервер временно недоступен',code:'network_error'};
  })();
}
function applySpaI18n() {
  try {
    const labels = { chats: t('catalog'), online: t('online'), clan: t('clan'), profile: t('profile') };
    document.querySelectorAll('#main-nav .nav-pill button[data-sc]').forEach(b => {
      const sc = b.dataset.sc;
      if (!labels[sc]) return;
      const emoji = { chats:'💬', online:'🟢', clan:'🏰', profile:'👤' }[sc] || '';
      b.innerHTML = emoji + '<span>' + labels[sc] + '</span>';
    });
    document.querySelectorAll('.rail .rbtn[data-sc]').forEach(b => {
      const sc = b.dataset.sc;
      if (labels[sc]) b.title = labels[sc];
    });
    // фиксированные RU/EN/UK подписи — не оставлять ключи
    const goLabels = {
      profile: t('profile'), online: t('online'), clan: t('servers') !== 'servers' ? t('servers') : t('clan'),
      favs: t('favs'), settings: t('settings'), sub: t('sub'), passport: t('passport'),
      cinema: t('cinema'), music: t('music'), continue: t('continue'), family: t('family'),
      geo: t('geo'), tutorial: t('tutorial'), notes: t('notes'), tip: t('tip'), messenger: t('messenger'), quests: t('quests'),
      achievements: t('achievements'), mood: t('mood'), apps: (spaLang()==='en'?'Mini apps':(spaLang()==='uk'?'Міні-застосунки':(spaLang()==='ru'?'Мини-приложения':(SPA_CORE_I18N[spaLang()]?.apps||'Mini apps'))))
    };
    document.querySelectorAll('.drawer .item[data-go]').forEach(b => {
      const go = b.dataset.go;
      const label = goLabels[go];
      if (!label || label === go) return; // не трогать если нет перевода
      const ico = b.querySelector('.ico');
      const chev = b.querySelector('.chev');
      b.innerHTML = (ico ? ico.outerHTML + ' ' : '') + label + ' ' + (chev ? chev.outerHTML : '');
    });
    document.documentElement.lang = spaLang();
    document.documentElement.dir = spaLang()==='ar' ? 'rtl' : 'ltr';
    spaTranslateDom(document.body);
  } catch (e) { console.warn('i18n', e); }
}
function spaPlay(kind) {
  try {
    if (!state._spaConf || state._spaConf.sound !== true) return;
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return;
    if (!window.__spaAudioCtx) window.__spaAudioCtx = new Ctx();
    const ctx = window.__spaAudioCtx;
    const o = ctx.createOscillator();
    const g = ctx.createGain();
    const vol = (state._spaConf && state._spaConf.soundVol) != null ? +state._spaConf.soundVol : 0.4;
    o.type = kind === 'err' ? 'sawtooth' : (kind === 'ok' ? 'triangle' : 'sine');
    o.frequency.value = kind === 'err' ? 180 : (kind === 'ok' ? 520 : 360);
    g.gain.value = Math.max(0.05, Math.min(1, vol)) * 0.08;
    o.connect(g); g.connect(ctx.destination);
    o.start();
    setTimeout(() => { try { o.stop(); } catch(e) {} }, 80);
  } catch (e) {}
}
function presence(tab, detail) {
  try {
    if (state._spaConf && state._spaConf.presence === false) return;
  } catch(e) {}
  state._presenceLabel = detail || tab;
  try {
    api('presence', { method:'POST', body:{ tab:String(tab||'').slice(0,64), activity:String(detail||tab||'').slice(0,128), game:'', detail:String(detail||tab||'').slice(0,255) } });
  } catch(e) {}
}
function setScreen(name) {
  state.screen = name;
  ['auth','chats','online','profile'].forEach(s => {
    const el = $('sc-'+s);
    if (el) el.classList.toggle('on', s===name);
  });
  document.querySelectorAll('.nav-pill button, .rail .rbtn[data-sc]').forEach(b => b.classList.toggle('on', b.dataset.sc===name));
  if ($('main-nav')) $('main-nav').style.display = (name==='auth'||!state.user) ? 'none' : '';
  const labels = { chats:'Каталог', online:'Кто в сети', clan:'Сервер', profile:'Мой профиль' };
  presence(name, labels[name]||name);
  if (name !== 'auth') spaPlay('click');
  window.__musicHubOpen = false;
  try { try{slVaultCleanupOnClose();}catch(e){}$('detail-ov').classList.remove('on'); setDetailTitle('Просмотр'); } catch(e) {}
  try { closeDrawer(); } catch(e) {}
  try { $('search-ov').classList.remove('on'); } catch(e) {}
}
function openDrawer() {
  spaPlay('click');
  if (state.user) {
    if ($('dr-av')) $('dr-av').src = state.user.avatar || '';
    if ($('dr-name')) $('dr-name').textContent = state.user.username || '';
    if ($('dr-handle')) $('dr-handle').textContent = '@'+(state.user.username||'');
  }
  if ($('dr-build')) $('dr-build').textContent = BOOT.build || '';
  if ($('drawer-ov')) $('drawer-ov').classList.add('on');
}
function closeDrawer() {
  if ($('drawer-ov')) $('drawer-ov').classList.remove('on');
}
function destroySpaPlayer() {
  try {
    if (window.__chLiveTimer) { clearInterval(window.__chLiveTimer); window.__chLiveTimer = null; }
  } catch(e) {}
  try {
    const p = document.getElementById('spa-player');
    if (p) { p.innerHTML = ''; }
  } catch(e) {}
}
function isHlsUrl(url, platform) {
  const u = String(url||'').toLowerCase();
  const p = String(platform||'').toLowerCase();
  return /\.m3u8(\?|$)/.test(u) || p === 'hls' || p.indexOf('hls') >= 0;
}
function playerSafeUrl(url){
  const u=String(url||'').trim();
  return (/^https?:\/\//i.test(u) || u.startsWith('/')) ? u : '';
}
function playerChromeHtml(title, live){
  const pip=slT('Картинка в картинке','Picture in picture','Картинка в картинці'), dm=slT('Плавающие сообщения','Floating messages','Плаваючі повідомлення'), fs=slT('На весь экран','Fullscreen','На весь екран'), mini=slT('Свернуть в мини-плеер','Minimize to mini-player','Згорнути в міні-плеєр');
  return `<div class="player-chrome-top"><span class="player-brand"><i></i> STREAMLIVE</span><span class="player-media-title">${esc(title||'Видео')}</span></div><div class="player-quick-actions"><button type="button" data-player-action="mini" title="${esc(mini)}" aria-label="${esc(mini)}">⌄</button><button type="button" data-player-action="pip" title="${esc(pip)}" aria-label="${esc(pip)}">▣</button><button type="button" data-player-action="danmaku" class="active" title="${esc(dm)}" aria-label="${esc(dm)}">💬</button><button type="button" data-player-action="fullscreen" title="${esc(fs)}" aria-label="${esc(fs)}">⛶</button></div>`;
}
function buildPlayerHtml(url, platform, title, sourceUrl, opts){
  opts=opts||{}; url=playerSafeUrl(url);
  if(!url) return '<div class="player-box"><div class="player-error"><b>Источник недоступен</b><small>Для этого видео не передана корректная ссылка.</small></div></div>';
  const p=String(platform||'').toLowerCase();
  // Iframe используется только для явно заданных embed-платформ/обёрток.
  // Обычные видео и прямые URL запускаются через PlayerJS.
  const embedSite=url.includes('embedwebsite') || ['dropbox','instagram','iframe','tiktok','twitter','reddit','youtube','vimeo','twitch','rutube','vk','vkvideo'].includes(p) || /(?:youtube\.com\/embed\/|player\.vimeo\.com\/video\/|rutube\.ru\/play\/embed\/|vk\.com\/video_ext\.php)/i.test(url);
  let media='';
  if(embedSite){
    let emb=url;
    if(!emb.includes('embedwebsite') && !['iframe','tiktok','twitter','reddit'].includes(p)) emb='/embedwebsite/index.php?url='+encodeURIComponent(playerSafeUrl(sourceUrl)||url)+'&embed=1';
    else if(emb.includes('embedwebsite') && !emb.includes('embed=')) emb+=(emb.includes('?')?'&':'?')+'embed=1';
    media=`<iframe src="${esc(emb)}" allowfullscreen loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" title="${esc(title||'Видео')}"></iframe>`;
  } else {
    media='<div id="playerjs-container" class="playerjs-host"></div>';
  }
  return `<div class="player-box" id="player-wrap">${media}${playerChromeHtml(title,false)}<div class="danmaku-layer" id="danmaku-layer" aria-live="off"></div></div>`;
}

function buildLivePlayerHtml(live,title,logo){
  if(!live || !live.url) return '<div class="player-box"><div class="player-error"><b>Сейчас нет активного эфира</b><small>Когда трансляция начнётся, плеер появится здесь.</small></div></div>';
  const url=playerSafeUrl(live.url); if(!url) return '<div class="player-box"><div class="player-error"><b>Некорректный адрес трансляции</b><small>Проверь источник эфира в настройках канала.</small></div></div>';
  const type=String(live.type||'').toLowerCase();
  const media=type==='iframe' ? `<iframe src="${esc(url)}" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen style="position:absolute;inset:0;width:100%;height:100%;border:0"></iframe>` : '<div id="playerjs-container" class="playerjs-host"></div>';
  return `<div class="player-box" id="player-wrap">${media}${playerChromeHtml(title,true)}${logo?`<img src="${esc(logo)}" alt="" style="position:absolute;right:12px;top:94px;width:38px;height:38px;object-fit:contain;z-index:7;pointer-events:none;filter:drop-shadow(0 2px 5px #000)">`:''}<div class="danmaku-layer" id="danmaku-layer"></div></div>`;
}
function playerNotice(message){const wrap=document.getElementById('player-wrap');if(!wrap)return;const old=wrap.querySelector('.player-toast');if(old)old.remove();const el=document.createElement('div');el.className='player-toast';el.textContent=String(message||'');wrap.appendChild(el);setTimeout(()=>{if(el.isConnected)el.remove();},2600);}
function ensureMiniDock(){
  let dock=document.getElementById('sl-mini-dock');
  if(!dock){dock=document.createElement('div');dock.id='sl-mini-dock';dock.setAttribute('aria-live','polite');document.body.appendChild(dock);}
  return dock;
}
function miniDockCleanup(){const d=document.getElementById('sl-mini-dock');if(d&&!d.children.length)d.remove();}
function miniVideoStart(wrap){
  if(!wrap)return;
  const dock=ensureMiniDock();let slot=dock.querySelector('#sl-mini-video-slot');
  if(!slot){slot=document.createElement('div');slot.id='sl-mini-video-slot';dock.prepend(slot);}
  if(!wrap.__slMiniPlaceholder){wrap.__slMiniPlaceholder=document.createComment('StreamLive mini-player placeholder');wrap.parentNode&&wrap.parentNode.insertBefore(wrap.__slMiniPlaceholder,wrap);}
  wrap.classList.add('sl-mini-video');slot.innerHTML='';slot.appendChild(wrap);
  let head=slot.querySelector('.sl-mini-video-head');
  if(!head){head=document.createElement('div');head.className='sl-mini-card sl-mini-head sl-mini-video-head';head.innerHTML='<div class="sl-mini-meta"><div class="sl-mini-title">Видео продолжает播放</div><div class="sl-mini-sub">Мини-плеер</div></div><div class="sl-mini-actions"><button type="button" data-mini-restore title="Развернуть">↗</button><button type="button" data-mini-close title="Закрыть">×</button></div>';slot.prepend(head);}
  head.querySelector('[data-mini-restore]').onclick=()=>miniVideoRestore();
  head.querySelector('[data-mini-close]').onclick=()=>closeMiniVideoPlayer();
}
function miniVideoRestore(){
  const wrap=document.querySelector('#sl-mini-video-slot #player-wrap');if(!wrap)return;
  const ph=wrap.__slMiniPlaceholder;
  if(ph&&ph.parentNode){ph.parentNode.insertBefore(wrap,ph);ph.remove();}
  else {const target=isDesktop()?$('pane-body'):$('detail-body');if(target){target.appendChild(wrap);if(!isDesktop())$('detail-ov').classList.add('on');}}
  wrap.__slMiniPlaceholder=null;wrap.classList.remove('sl-mini-video');
  const slot=document.getElementById('sl-mini-video-slot');if(slot)slot.remove();miniDockCleanup();bindPlayerChrome();
}
function closeMiniVideoPlayer(){
  const wrap=document.querySelector('#sl-mini-video-slot #player-wrap');
  if(wrap){try{if(window.__spaPlayerInstance&&typeof window.__spaPlayerInstance.destroy==='function')window.__spaPlayerInstance.destroy();}catch(e){}window.__spaPlayerInstance=null;try{if(window.__spaHls){window.__spaHls.destroy();window.__spaHls=null;}}catch(e){}if(wrap.__slMiniPlaceholder&&wrap.__slMiniPlaceholder.parentNode)wrap.__slMiniPlaceholder.remove();wrap.remove();}
  const slot=document.getElementById('sl-mini-video-slot');if(slot)slot.remove();miniDockCleanup();
}
function renderMiniMusic(){
  const audio=__slMusicAudio;let dock=document.getElementById('sl-mini-dock');let card=dock&&dock.querySelector('#sl-mini-music-card');
  if(window.__slMusicMiniHidden){if(card)card.remove();miniDockCleanup();return;}
  dock=ensureMiniDock();card=dock.querySelector('#sl-mini-music-card');
  if(!audio||!audio.src){if(card)card.remove();miniDockCleanup();return;}
  if(!card){card=document.createElement('div');card.id='sl-mini-music-card';card.className='sl-mini-card';card.innerHTML='<div class="sl-mini-head"><img id="sl-mini-music-cover" alt="" style="width:38px;height:38px;flex:0 0 38px;border-radius:10px;object-fit:cover;background:rgba(255,255,255,.09)" src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs="><div class="sl-mini-meta"><div class="sl-mini-title" id="sl-mini-music-title">Музыка</div><div class="sl-mini-sub" id="sl-mini-music-sub">Продолжает играть</div></div><div class="sl-mini-actions"><button type="button" data-music-prev title="Предыдущий">⏮</button><button type="button" data-music-toggle title="Пауза/играть">▶</button><button type="button" data-music-next title="Следующий">⏭</button><button type="button" data-music-stop title="Остановить">■</button><button type="button" data-music-hide title="Скрыть">×</button></div></div>';
    dock.appendChild(card);
    card.querySelector('[data-music-prev]').onclick=()=>musicPlayPrev();card.querySelector('[data-music-toggle]').onclick=()=>musicTogglePlay();card.querySelector('[data-music-next]').onclick=()=>musicPlayNext(false);card.querySelector('[data-music-stop]').onclick=()=>musicStopPlayback();card.querySelector('[data-music-hide]').onclick=()=>{window.__slMusicMiniHidden=true;musicStopPlayback();card.remove();miniDockCleanup();};
  }
  const list=(__slMusicQueue&&__slMusicQueue.length)?__slMusicQueue:musicLibGet();const tr=list[__slMusicIdx]||{};
  const t=card.querySelector('#sl-mini-music-title'),sub=card.querySelector('#sl-mini-music-sub'),toggle=card.querySelector('[data-music-toggle]');
  if(t)t.textContent=tr.title||musicCleanTitle(tr.name||tr.url||'Музыка');if(sub)sub.textContent=(tr.artist||'StreamLive · Музыка');if(toggle)toggle.textContent=audio.paused?'▶':'Ⅱ';
  const cover=card.querySelector('#sl-mini-music-cover');if(cover){const coverTrackId=String(tr.id||tr.blobId||'');cover.src='data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=';musicCoverUrl(tr).then(u=>{if(u&&cover.isConnected&&coverTrackId===String(__slMusicCurrentTrackId||''))cover.src=u;});}
  try{spaTranslateDom(card);}catch(e){}
}
function bindPlayerChrome(){
  const wrap=document.getElementById('player-wrap');if(!wrap||wrap.dataset.chromeBound==='1')return;wrap.dataset.chromeBound='1';
  wrap.querySelectorAll('[data-player-action]').forEach(btn=>btn.addEventListener('click',async()=>{
    const action=btn.dataset.playerAction;
    if(action==='mini'){miniVideoStart(wrap);return;}
    if(action==='fullscreen'){try{if(document.fullscreenElement)await document.exitFullscreen();else await wrap.requestFullscreen();}catch(e){playerNotice(slT('Полноэкранный режим недоступен','Fullscreen is unavailable','Повноекранний режим недоступний'));}}
    if(action==='pip'){const v=wrap.querySelector('video')||document.querySelector('#playerjs-container video');try{if(v&&document.pictureInPictureEnabled){if(document.pictureInPictureElement)await document.exitPictureInPicture();else await v.requestPictureInPicture();}else playerNotice(slT('Картинка в картинке пока недоступна для этого источника','Picture in picture is unavailable for this source','Картинка в картинці недоступна для цього джерела'));}catch(e){playerNotice(slT('Не удалось включить картинку в картинке','Could not enable picture in picture','Не вдалося ввімкнути картинку в картинці'));}}
    if(action==='danmaku'){const layer=wrap.querySelector('#danmaku-layer');if(!layer)return;const off=layer.style.display==='none';layer.style.display=off?'':'none';btn.classList.toggle('active',off);if(state._spaConf)state._spaConf.danmaku=off;}
  }));
}
function mountSpaPlayer(url,type,title,opts){
  opts=opts||{};const container=document.getElementById('playerjs-container');
  bindPlayerChrome();
  if(!container)return;
  if(String(type||'').toLowerCase()==='iframe')return;
  const src=playerSafeUrl(url);if(!src){container.innerHTML='<div class="player-error"><b>Источник не задан</b><small>Проверь URL потока: требуется http(s)-ссылка или локальный путь.</small></div>';return;}
  try{if(window.__spaPlayerInstance&&typeof window.__spaPlayerInstance.destroy==='function')window.__spaPlayerInstance.destroy();}catch(e){}
  try{if(window.__spaHls){window.__spaHls.destroy();window.__spaHls=null;}}catch(e){}
  window.__spaPlayerInstance=null;container.innerHTML='';
  const isHls=/\.m3u8(?:$|[?#])/i.test(src)||String(type||'').toLowerCase()==='hls'||String(type||'').toLowerCase()==='m3u8';
  const isFile=/\.(?:mp4|webm|ogg|m4v|mov)(?:$|[?#])/i.test(src)||['mp4','webm','ogg'].includes(String(type||'').toLowerCase());
  const retryHtml='<button type="button" class="btn-primary" id="sl-player-retry" style="margin-top:10px">Повторить запуск</button>';
  function bindRetry(){const b=container.querySelector('#sl-player-retry');if(b)b.onclick=()=>mountSpaPlayer(src,type,title,opts);}
  function nativeFallback(reason){
    if(!isHls&&!isFile)return false;
    container.innerHTML='';
    const video=document.createElement('video');video.id='sl-native-video';video.controls=true;video.playsInline=true;video.preload='metadata';video.autoplay=!!opts.autoplay;video.muted=!!opts.muted;video.style.cssText='width:100%;height:100%;object-fit:contain;background:#000';
    const poster=playerSafeUrl(opts.poster||'');if(poster)video.poster=poster;
    container.appendChild(video);
    if(isHls&&window.Hls&&window.Hls.isSupported()){
      try{const h=new window.Hls({enableWorker:true,lowLatencyMode:true});window.__spaHls=h;h.loadSource(src);h.attachMedia(video);h.on(window.Hls.Events.ERROR,(_event,data)=>{if(data&&data.fatal){const msg='HLS: '+String(data.details||data.type||'ошибка потока');showNativeError(msg);}});}
      catch(e){video.src=src;}
    }else video.src=src;
    function showNativeError(msg){let note=container.querySelector('.player-native-error');if(!note){note=document.createElement('div');note.className='player-native-error';note.style.cssText='position:absolute;left:10px;right:10px;bottom:10px;padding:9px 12px;border-radius:10px;background:#24131beF;color:#ffd7df;font-size:12px;z-index:4';container.appendChild(note);}note.textContent='Проблема воспроизведения: '+msg;}
    video.addEventListener('error',()=>{const e=video.error;showNativeError(e?('код '+e.code+' · '+(e.message||'не удалось прочитать медиаданные')):'не удалось прочитать медиаданные');});
    if(opts.autoplay){const play=video.play();if(play&&typeof play.catch==='function')play.catch(()=>{});}
    return true;
  }
  const PlayerCtor=window.Playerjs||window.PlayerJS||window.playerjs||(typeof Playerjs==='function'?Playerjs:null);
  if(typeof PlayerCtor==='function'){
    try{
      // Оставляем только документированные базовые параметры, как в оригинальном channel-pc.php.
      window.__spaPlayerInstance=new PlayerCtor({id:'playerjs-container',file:src,autoplay:opts.autoplay?1:0,muted:opts.muted?1:0,poster:playerSafeUrl(opts.poster||'')});
      if(window.__spaPlayerInstance)return;
      throw new Error('PlayerJS не создал экземпляр плеера');
    }catch(e){
      const msg=String(e&&e.message||e||'неизвестная ошибка');
      if(nativeFallback(msg)){console.warn('PlayerJS failed; native playback fallback enabled:',msg);return;}
      console.error('StreamLive PlayerJS init failed:',e);
      container.innerHTML='<div class="player-error"><b>Не удалось запустить PlayerJS</b><small>'+esc(msg)+'<br>Источник: '+esc(src)+'</small>'+retryHtml+'</div>';bindRetry();try{spaTranslateDom(container);}catch(_e){}return;
    }
  }
  // If the library is blocked/missing, direct MP4/HLS still plays through the browser.
  if(nativeFallback('PlayerJS library is unavailable')){console.warn('PlayerJS unavailable; using native media playback');return;}
  const script=document.querySelector('script[src*="playerjs.js"]');
  const srcInfo=script?script.src:'playerjs.js не найден в HTML';
  container.innerHTML='<div class="player-error"><b>PlayerJS не загрузился</b><small>'+esc(srcInfo)+'<br>Библиотека недоступна, а формат источника не поддерживается встроенным резервным плеером.<br>Проверь наличие playerjs.js рядом со streamspa.php и HTTP-статус 200.</small>'+retryHtml+'</div>';bindRetry();try{spaTranslateDom(container);}catch(_e){}
}

function destroySpaPlayer(){
  try{if(window.__chLiveTimer){clearInterval(window.__chLiveTimer);window.__chLiveTimer=null;}}catch(e){}
  try{if(window.__slYoutubePlayer&&typeof window.__slYoutubePlayer.destroy==='function')window.__slYoutubePlayer.destroy();window.__slYoutubePlayer=null;}catch(e){}
  try{if(typeof __dmTimer!=='undefined'&&__dmTimer){clearInterval(__dmTimer);__dmTimer=null;}if(typeof __dmTarget!=='undefined')__dmTarget=null;}catch(e){}
  /* A minimized video is detached from the content pane and must survive navigation. */
  if(document.querySelector('#sl-mini-video-slot #player-wrap'))return;
  try{if(window.__spaPlayerInstance&&typeof window.__spaPlayerInstance.destroy==='function'){window.__spaPlayerInstance.destroy();window.__spaPlayerInstance=null;}}catch(e){}
  try{if(window.__spaHls){window.__spaHls.destroy();window.__spaHls=null;}}catch(e){}
  const p=document.getElementById('spa-player');if(p)p.innerHTML='';
}
function fmtTime(t) {
  if (!t) return '';
  const d = new Date(String(t).replace(' ','T'));
  return isNaN(d) ? '' : d.toLocaleTimeString('ru-RU',{hour:'2-digit',minute:'2-digit'});
}
function rowHtml(item) {
  if (!item) return '';
  const av = item.avatar
    ? `<img class="av" src="${esc(item.avatar)}" alt="" onerror="this.className='av letter';this.textContent='•'">`
    : `<div class="av letter">${esc((item.title||item.username||'?').charAt(0).toUpperCase())}</div>`;
  let ageB = '';
  try { ageB = ytAgeBadgeHtml(analyzeContentRatingSafe(item)); } catch (e) { ageB = ''; }
  let block = '';
  try { block = parentalBlockBtn(item.title || item.username || ''); } catch(e) {}
  return `<div class="row" data-kind="${esc(item.kind||'')}" data-id="${esc(item.id||'')}" data-username="${esc(item.username||'')}" data-slug="${esc(item.slug||'')}" data-embed="${esc(item.embed_url||'')}" data-source="${esc(item.source_url||'')}" data-platform="${esc(item.platform||'')}" data-title="${esc(item.title||'')}">
    ${av}
    <div class="body"><div class="title">${esc(item.title||item.username||'')} ${ageB}</div>
    <div class="sub">${esc(item.subtitle||item.platform||'')}${item.time?' · '+esc(fmtTime(item.time)):''}</div></div>
    ${block}
    ${(item.kind==='channel' || item.kind==='video') ? `<div class="sl-row-reactions" data-content-reactions style="display:flex;gap:5px;align-items:center;flex:0 0 auto"><button type="button" data-content-vote="1" title="Нравится" style="border:1px solid var(--line);background:rgba(255,255,255,.04);color:inherit;border-radius:9px;padding:5px 7px;font-size:11px">👍 <b>0</b></button><button type="button" data-content-vote="-1" title="Не нравится" style="border:1px solid var(--line);background:rgba(255,255,255,.04);color:inherit;border-radius:9px;padding:5px 7px;font-size:11px">👎 <b>0</b></button></div><button type="button" class="fav-btn" data-fav-kind="${esc(item.kind)}" data-fav-id="${esc(item.id)}" title="В избранное">☆</button>` : ''}
  </div>`;
}
function bindRows(root) {
  if (!root) return;
  root.querySelectorAll('.row[data-kind]').forEach(r => {
    r.onclick = () => openItem(r.dataset);
  });
  root.querySelectorAll('.row[data-kind="channel"],.row[data-kind="video"]').forEach(r => {
    try { spaBindContentReactions(r, r.dataset.kind, r.dataset.id); } catch(e) {}
  });
  root.querySelectorAll('.fav-btn').forEach(btn => {
    btn.onclick = (e) => {
      e.stopPropagation();
      if (btn.classList.contains('parental-block')) {
        const text = btn.dataset.block || '';
        if (parentalBlockText(text)) {
          const row = btn.closest('.row');
          if (row) row.remove();
        }
        return;
      }
      if (typeof toggleFav === 'function') toggleFav(btn.dataset.favKind, btn.dataset.favId, btn);
    };
  });
  if (root.querySelector('.fav-btn')) api('favorites').then(d=>{if(!d||!d.ok||!Array.isArray(d.items))return;const saved=new Set(d.items.map(x=>x.target_type+':'+x.target_id));root.querySelectorAll('.fav-btn[data-fav-kind]').forEach(b=>{b.textContent=saved.has(b.dataset.favKind+':'+b.dataset.favId)?'⭐':'☆';});}).catch(()=>{});
}



/* —— язык при старте + детский/взрослый —— */
const LANG_ASK_KEY = 'spa_lang_asked_v1';
const PARENT_KEY = 'spa_parental_v1';

function parentalLoad() {
  try {
    const d = JSON.parse(localStorage.getItem(PARENT_KEY) || 'null');
    if (d && d.profiles) {
      d.profiles = d.profiles.map(p => {
        if (p.kids && (p.maxAge == null)) p.maxAge = 12;
        if (!p.blocks) p.blocks = { keywords: [], categories: [], kpIds: [] };
        if (!Array.isArray(p.blocks.keywords)) p.blocks.keywords = [];
        if (!Array.isArray(p.blocks.kpIds)) p.blocks.kpIds = [];
        return p;
      });
      return d;
    }
  } catch (e) {}
  return {
    pin: '',
    activeId: 'adult',
    profiles: [
      { id: 'adult', name: 'Взрослый', kids: false, maxAge: 99, blocks: { keywords: [], categories: [], kpIds: [] } },
      { id: 'kids', name: 'Детский', kids: true, maxAge: 12, blocks: { keywords: ['18+','эротика','horror','ужас','xxx','adult'], categories: ['adult'], kpIds: [] } }
    ]
  };
}

/** ——— Авто-рейтинг контента (как YouTube maturity) ——— */
const YT_RATE_CACHE_KEY = 'spa_yt_rate_v2';
function ytRateCacheGet() {
  try { return JSON.parse(localStorage.getItem(YT_RATE_CACHE_KEY) || '{}'); } catch(e) { return {}; }
}
function ytRateCacheSet(map) {
  try { localStorage.setItem(YT_RATE_CACHE_KEY, JSON.stringify(map)); } catch(e) {}
}

/** Словари сигналов → возраст (чем выше score, тем жёстче) */
const YT_AGE_RULES = [
  { age: 18, w: 12, re: /18\+|adult|nsfw|xxx|porn|эрот|секс|onlyfans|strip|nude|18 plus|возрастные ограничения|age.?restricted|для взрослых|hentai|pornhub/i },
  { age: 18, w: 8, re: /нарко|drug use|cocaine|наркотик/i },
  { age: 16, w: 9, re: /16\+|horror|ужас|слэшер|blood|кровь|убий|gore|расчлен|gta\s*(5|6|v|vi)|call of duty|warzone|kill/i },
  { age: 16, w: 6, re: /насили|violence|shooting|стреля|война|terror/i },
  { age: 12, w: 7, re: /12\+|teen|подрост|подростк|marvel|anime fight|битва|сражен/i },
  { age: 12, w: 5, re: /scary|страшн|мистик|thriller|триллер/i },
  { age: 6, w: 6, re: /6\+|0\+|kids|детск|для детей|мульт|cartoon|nursery|preschool|свинка пеппа|paw patrol|щенячий/i },
  { age: 6, w: 4, re: /семейный|family friendly|детский канал|junior/i }
];

/**
 * Анализирует текст (название, описание, платформа, жанры) и отдаёт
 * { age: 0|6|12|16|18, label, category, confidence, reasons[] }
 */
function analyzeContentRating(input) {
  let text = '';
  let idKey = '';
  if (typeof input === 'string') {
    text = input;
  } else if (input && typeof input === 'object') {
    idKey = String(input.id || input.kinopoiskId || input.filmId || input.slug || '');
    text = [
      input.title, input.name, input.subtitle, input.description, input.platform,
      input.ratingMpaa, input.ratingAgeLimits, input.genres,
      Array.isArray(input.genres) ? input.genres.map(g => g.genre || g).join(' ') : ''
    ].filter(Boolean).join(' ');
  }
  text = String(text || '');

  // cache
  if (idKey) {
    const cache = ytRateCacheGet();
    if (cache[idKey] && cache[idKey].age != null && cache[idKey].t > Date.now() - 7*864e5) {
      return cache[idKey];
    }
  }

  // explicit API-like numbers in text
  let age = 0;
  const reasons = [];
  let score = { 6:0, 12:0, 16:0, 18:0 };

  const mpaa = text.match(/\b(G|PG-13|PG|R|NC-17|TV-Y|TV-Y7|TV-G|TV-PG|TV-14|TV-MA)\b/i);
  if (mpaa) {
    const m = mpaa[1].toUpperCase();
    const map = { 'G':6,'TV-Y':6,'TV-Y7':6,'TV-G':6,'PG':12,'TV-PG':12,'PG-13':12,'TV-14':12,'R':16,'TV-MA':18,'NC-17':18 };
    if (map[m]) { age = Math.max(age, map[m]); reasons.push('рейтинг ' + m); score[map[m]] += 10; }
  }
  const ageNum = text.match(/(?:age|возраст|рейтинг)[^\d]{0,6}(\d{1,2})\s*\+/i) || text.match(/\bage(6|12|16|18)\b/i) || text.match(/\b(0|6|12|16|18)\s*\+/);
  if (ageNum) {
    const n = parseInt(ageNum[1], 10);
    if (n===0||n===6||n===12||n===16||n===18) { age = Math.max(age, n === 0 ? 6 : n); reasons.push(n + '+ в тексте'); score[n===0?6:n] += 10; }
  }

  for (const rule of YT_AGE_RULES) {
    if (rule.re.test(text)) {
      score[rule.age] += rule.w;
      reasons.push(rule.age + '+ сигнал');
    }
  }

  // pick highest age with score
  let best = age;
  let bestS = 0;
  [18,16,12,6].forEach(a => {
    if (score[a] > bestS) { bestS = score[a]; best = a; }
    else if (score[a] === bestS && a > best) best = a;
  });
  if (bestS === 0 && age === 0) best = 0;
  else if (bestS > 0) best = Math.max(best, age);

  // эвристика как Restricted Mode: больше сигналов = больше меток
  if (bestS === 0 && best === 0) {
    const blob = text.toLowerCase();
    if (/мульт|cartoon|детск|для детей|свинка|щенячий|barbie|peppa|0\+|kids|junior|nursery|lol surprise|paw patrol/.test(blob)) {
      best = 6; bestS = 4; reasons.push('детский');
    } else if (/18\+|onlyfans|эрот|xxx|nsfw|porn|adult|секс|strip|hentai|pornhub/.test(blob)) {
      best = 18; bestS = 10; reasons.push('18+');
    } else if (/gta|horror|ужас|call of duty|warzone|\bkill\b|blood|кровь|расчлен|slasher|outlast|resident evil/.test(blob)) {
      best = 16; bestS = 6; reasons.push('16+');
    } else if (/стрим|twitch|live|новост|политик|standup|стендап|podcast|подкаст|обзор|летсплей|gameplay|реакц|рутуб|youtube/.test(blob)) {
      best = 12; bestS = 3; reasons.push('12+');
    } else if (input && typeof input === 'object') {
      const k = String(input.kind || '').toLowerCase();
      // канал/видео без явных слов — 12+ (не «для всех детей»)
      if (k === 'channel' || k === 'video') {
        best = 12; bestS = 2; reasons.push('видео/канал');
      }
    }
  }

  const labels = { 0: 'Все', 6: '6+', 12: '12+', 16: '16+', 18: '18+' };
  const cats = { 0: 'Для всех', 6: 'Дети', 12: 'Семья / подростки', 16: 'Подростки+', 18: 'Только взрослые' };
  const conf = bestS >= 12 ? 'high' : (bestS >= 6 ? 'mid' : (best > 0 ? 'low' : 'none'));

  const result = {
    age: best,
    label: labels[best] || 'Все',
    category: cats[best] || 'Для всех',
    confidence: conf,
    reasons: reasons.slice(0, 4),
    t: Date.now()
  };
  if (idKey) {
    const cache = ytRateCacheGet();
    cache[idKey] = result;
    // limit cache size
    const keys = Object.keys(cache);
    if (keys.length > 400) keys.slice(0, keys.length - 400).forEach(k => delete cache[k]);
    ytRateCacheSet(cache);
  }
  return result;
}


function analyzeContentRatingSafe(input) {
  try { return analyzeContentRating(input); }
  catch (e) { return { age: 0, label: 'Все', category: 'Для всех', confidence: 'none', reasons: [] }; }
}

function contentAgeTag(text) {
  return analyzeContentRatingSafe(text).age;
}

function ytAgeBadgeHtml(ageOrObj) {
  const age = typeof ageOrObj === 'object' ? (+ageOrObj.age || 0) : (+ageOrObj || 0);
  if (age >= 18) return '<span class="age-badge g18" title="18+">18+</span>';
  if (age >= 16) return '<span class="age-badge g16" title="16+">16+</span>';
  if (age >= 12) return '<span class="age-badge g12" title="12+">12+</span>';
  if (age >= 6) return '<span class="age-badge g6" title="6+">6+</span>';
  return ''; // без сигнала — без бейджа (не «Все» на всём)
}
function ytAgeMetaHtml(rating) {
  if (!rating || !rating.age) return '';
  const conf = rating.confidence === 'high' ? '' : (rating.confidence === 'mid' ? ' · авто' : ' · примерно');
  return `<span class="age-badge ${rating.age>=18?'g18':rating.age>=16?'g16':rating.age>=12?'g12':'g6'}" title="${esc(rating.category)}">${esc(rating.label)}</span>` +
    `<span style="font-size:10px;color:var(--muted);margin-left:4px">${esc(rating.category)}${conf}</span>`;
}
function ytAgeGateHtml(title, age) {
  const label = age >= 18 ? 'Возрастные ограничения' : ('Ограничение ' + age + '+');
  return `<div class="yt-age-gate">
    <div class="yt-ico">⚠️</div>
    <h3>${label}</h3>
    <p>Контент «${esc(title||'')}» может быть неприемлем для части аудитории (как Age-restricted на YouTube). Нужен допуск ${age}+.</p>
    <button type="button" class="btn-primary" id="yt-age-adult" style="max-width:280px;margin:0">Мне уже есть ${age} лет</button>
    <button type="button" class="btn-primary" id="yt-age-back" style="margin-top:10px;max-width:280px;background:rgba(255,255,255,.08)">Назад</button>
  </div>`;
}
function ytNeedAgeGate(textOrObj) {
  try {
    const r = analyzeContentRatingSafe(textOrObj);
    const age = r.age || 0;
    if (!age) return 0;
    const p = parentalActive();
    const maxA = p && p.maxAge != null ? +p.maxAge : (p && p.kids ? 12 : 99);
    // детский: жёсткий блок через parentalAllows, gate только для info
    if (p && (p.kids || maxA < 99) && age > maxA) return age;
    if (age >= 18) {
      try { if (sessionStorage.getItem('spa_age_ok_18') === '1') return 0; } catch(e) {}
      return 18;
    }
    return 0;
  } catch (e) { return 0; }
}
function showYtAgeGate(title, age, onOk) {
  setDetailTitle(age >= 18 ? 'Возрастные ограничения' : (age + '+'));
  showInPane(ytAgeGateHtml(title, age));
  if ($('yt-age-back')) $('yt-age-back').onclick = () => {
    try { $('detail-ov').classList.remove('on'); setDetailTitle('Просмотр'); } catch(e) {}
  };
  if ($('yt-age-adult')) $('yt-age-adult').onclick = () => {
    if (parentalIsKids()) {
      alert('Детский профиль: ' + age + '+ закрыт. Семья → взрослый профиль.');
      return;
    }
    if (age >= 18) { try { sessionStorage.setItem('spa_age_ok_18', '1'); } catch(e) {} }
    if (typeof onOk === 'function') onOk();
  };
}

function parentalAllowsText(text, kpId) {
  try {
    const p = parentalActive();
    if (!p) return true;
    if (kpId && p.blocks && Array.isArray(p.blocks.kpIds) && p.blocks.kpIds.indexOf(String(kpId)) >= 0) return false;
    if (parentalBlockedText(text)) return false;
    // детский / ограниченный профиль
    if (p.kids || (p.maxAge != null && p.maxAge < 99)) {
      const maxA = p.maxAge != null ? +p.maxAge : (p.kids ? 12 : 99);
      const rate = analyzeContentRatingSafe(text);
      let age = rate.age || 0;
      // без метки = неизвестный контент → для детей НЕ показываем (как Restricted Mode)
      const blob2 = (typeof text === 'string' ? text : [text&&text.title,text&&text.description,text&&text.age_rating].filter(Boolean).join(' ')).toLowerCase();
      // явный 0+/3+/6+/12+ — не скрывать
      if (/(?:^|[^\d])(0|3|6|12)\s*\+/.test(blob2) || /мульт|cartoon|детск|для детей|kids|junior|пеппа|щенячий/.test(blob2)) {
        if (!/18\+|21\+|xxx|porn|nsfw|для взрослых/.test(blob2)) return true;
      }
      if (age === 0) {
        if (/мульт|cartoon|детск|для детей|0\+|6\+|kids|junior|пеппа|щенячий/.test(blob2)) age = 6;
        else return false;
      }
      if (/(?:^|[^\d])(0|3|6)\s*\+/.test(blob2) && age > 12 && age < 18) age = 6;
      if (age > maxA) return false;
    }
    return true;
  } catch (e) { return true; }
}
function parentalBlockKp(kpId, title) {
  const d = parentalLoad();
  if (d.pin && parentalIsKids() && !askPin('PIN')) return false;
  const p = d.profiles.find(x => x.id === d.activeId) || d.profiles[0];
  if (!p.blocks) p.blocks = { keywords: [], kpIds: [] };
  if (!Array.isArray(p.blocks.kpIds)) p.blocks.kpIds = [];
  const id = String(kpId || '');
  if (id && p.blocks.kpIds.indexOf(id) < 0) p.blocks.kpIds.push(id);
  if (title) {
    if (!Array.isArray(p.blocks.keywords)) p.blocks.keywords = [];
    const k = String(title).slice(0, 80);
    if (p.blocks.keywords.indexOf(k) < 0) p.blocks.keywords.push(k);
  }
  parentalSave(d);
  try { spaAchUnlock('block_parent', 'Родительский контроль'); } catch(e) {}
  spaPlay('ok');
  return true;
}
function parentalSave(d) {
  try { localStorage.setItem(PARENT_KEY, JSON.stringify(d)); } catch (e) {}
}
function parentalActive() {
  const d = parentalLoad();
  return d.profiles.find(p => p.id === d.activeId) || d.profiles[0];
}
function parentalIsKids() {
  const p = parentalActive();
  return !!(p && p.kids);
}
function parentalBlockedText(text) {
  const p = parentalActive();
  if (!p || !p.blocks) return false;
  const keys = p.blocks.keywords || [];
  if (!keys.length) return false;
  const blob = String(text || '').toLowerCase().replace(/\s+/g, ' ');
  return keys.some(k => {
    const kk = String(k || '').toLowerCase().trim();
    return kk && blob.indexOf(kk) >= 0;
  });
}
function askPin(okMsg) {
  const d = parentalLoad();
  if (!d.pin) return true;
  const v = prompt(okMsg || 'PIN родителя');
  return v !== null && String(v) === String(d.pin);
}
function showLangBanner() {
  try { if (localStorage.getItem(LANG_ASK_KEY) === '1') return; } catch (e) {}
  if (document.getElementById('lang-banner')) return;
  const ov = document.createElement('div');
  ov.id = 'lang-banner';
  ov.style.cssText = 'position:fixed;inset:0;z-index:10050;background:rgba(0,0,0,.72);display:flex;align-items:center;justify-content:center;padding:16px';
  ov.innerHTML = `<div class="card" style="max-width:360px;width:100%;text-align:center">
    <div class="val" style="margin-bottom:8px">Язык интерфейса / Language</div>
    <p style="font-size:13px;color:var(--muted);margin:0 0 14px">Какой язык вам удобнее?</p>
    <div style="display:flex;gap:8px;flex-direction:column">
      <button type="button" class="btn-primary" data-l="ru" style="margin:0">Русский</button>
      <button type="button" class="btn-primary" data-l="uk" style="margin:0;background:rgba(255,255,255,.1)">Українська</button>
      <button type="button" class="btn-primary" data-l="en" style="margin:0;background:rgba(255,255,255,.1)">English</button>
      <button type="button" class="btn-primary" data-l="zh-CN" style="margin:0;background:rgba(255,255,255,.1)">简体中文</button>
      <button type="button" class="btn-primary" data-l="ko-KR" style="margin:0;background:rgba(255,255,255,.1)">한국어</button>
    </div>
  </div>`;
  document.body.appendChild(ov);
  ov.querySelectorAll('button[data-l]').forEach(b => {
    b.onclick = () => {
      let conf = {};
      try { conf = JSON.parse(localStorage.getItem('streamspa_settings')||'{}'); } catch(e) {}
      conf.lang = b.dataset.l;
      state._spaConf = Object.assign({}, state._spaConf||{}, conf);
      try { localStorage.setItem('streamspa_settings', JSON.stringify(conf)); localStorage.setItem(LANG_ASK_KEY, '1'); } catch(e) {}
      applySpaI18n();
      ov.remove();
    };
  });
}


function parentalBlockText(text) {
  text = String(text || '').trim();
  if (!text) return false;
  const d = parentalLoad();
  // блок без PIN если PIN не задан или активен взрослый; из детского — нужен PIN
  if (d.pin && parentalIsKids()) {
    if (!askPin('PIN чтобы заблокировать')) return false;
  }
  const p = d.profiles.find(x => x.id === d.activeId) || d.profiles[0];
  if (!p) return false;
  if (!p.blocks) p.blocks = { keywords: [], categories: [] };
  if (!Array.isArray(p.blocks.keywords)) p.blocks.keywords = [];
  const key = text.slice(0, 80);
  if (p.blocks.keywords.indexOf(key) < 0) p.blocks.keywords.push(key);
  parentalSave(d);
  spaPlay('ok');
  try { spaAchUnlock('block_parent', 'Родительский контроль'); } catch(e) {}
  return true;
}
function parentalBlockBtn(text) {
  return `<button type="button" class="fav-btn parental-block" data-block="${esc(text)}" title="Скрыть в этом профиле" style="color:#f87171">🚫</button>`;
}

function openParental() {
  destroySpaPlayer();
  try { closeDrawer(); } catch(e) {}
  try {
    if ($('detail-ov')) $('detail-ov').classList.add('on');
    if ($('col-detail')) { /* pc */ }
  } catch(e) {}
  setDetailTitle('Профили · PIN');
  const d = parentalLoad();
  const act = parentalActive();
  const ages = [6, 12, 16, 18, 21, 99];
  showInPane(`
    <div class="card"><div class="val">👨‍👩‍👧‍👦 Семья</div>
      <p style="font-size:13px;color:var(--muted);margin:8px 0 0">Возрастной фильтр · блоки по словам и ID · PIN</p></div>
    <div class="card">
      <div class="label">Активный профиль</div>
      <div class="val">${esc(act.name)} ${act.kids ? '· до ' + (act.maxAge||12) + ' лет' : '· без лимита'}</div>
      <div style="display:flex;gap:8px;margin-top:10px">
        <button type="button" class="btn-primary" id="par-adult" style="margin:0;flex:1">Взрослый</button>
        <button type="button" class="btn-primary" id="par-kids" style="margin:0;flex:1;background:rgba(255,255,255,.1)">Детский</button>
      </div>
    </div>
    <div class="card">
      <div class="label">Макс. возраст контента (детский)</div>
      <div class="seg" id="par-age" style="margin:8px 0;flex-wrap:wrap">
        ${ages.map(a => `<button type="button" data-a="${a}" class="${+(act.maxAge||12)===a?'on':''}">${a>=99?'18+ все':a+'+'}</button>`).join('')}
      </div>
      <p style="font-size:12px;color:var(--muted);margin:0">Детский: без метки = скрыто. Только контент ≤ выбранного возраста (6/12/16…)</p>
    </div>
    <div class="card">
      <div class="label">PIN родителя</div>
      <input class="field" id="par-pin" type="password" inputmode="numeric" placeholder="${d.pin ? '•••• (сменить)' : 'Задать PIN (от 4 цифр)'}">
      <button type="button" class="btn-primary" id="par-pin-save" style="margin:0">Сохранить PIN</button>
    </div>
    <div class="card">
      <div class="label">Блок-слова (через запятую)</div>
      <textarea class="field" id="par-blocks" rows="3" style="min-height:72px;resize:vertical" placeholder="канал, 18+, слово">${esc((act.blocks.keywords||[]).join(', '))}</textarea>
      <button type="button" class="btn-primary" id="par-blocks-save" style="margin:0">Сохранить блоки</button>
      <p style="font-size:12px;color:var(--muted);margin:8px 0 0">Или 🚫 на карточке / фильме. Сейчас: <b>${(act.blocks.keywords||[]).length}</b> слов, <b>${(act.blocks.kpIds||[]).length}</b> кино ID</p>
    </div>
    <div class="card" id="par-limits"><div class="label">Лимиты и сервер</div><div class="empty" style="padding:8px">…</div></div>
  `);
  api('server_mine').then(d2 => {
    const el = $('par-limits');
    if (!el) return;
    const q = d2 && d2.quota != null ? d2.quota : 1;
    const srv = d2 && d2.server ? d2.server.name : 'нет';
    el.innerHTML = `<div class="label">Лимиты и сервер</div><div class="val">Лимит/сутки: ${q}</div>
      <p style="font-size:12px;color:var(--muted);margin:8px 0 0">Сервер: ${esc(String(srv))}</p>`;
  });
  $('par-adult').onclick = () => {
    if (parentalIsKids() && d.pin && !askPin('PIN чтобы выйти из детского')) return;
    d.activeId = 'adult';
    parentalSave(d);
    spaPlay('ok');
    openParental();
  };
  $('par-kids').onclick = () => {
    d.activeId = 'kids';
    parentalSave(d);
    spaPlay('ok');
    openParental();
  };
  document.querySelectorAll('#par-age button').forEach(b => {
    b.onclick = () => {
      if (d.pin && !askPin('PIN')) return;
      const p = d.profiles.find(x => x.id === 'kids');
      if (p) { p.maxAge = +b.dataset.a; p.kids = true; }
      parentalSave(d);
      openParental();
    };
  });
  $('par-pin-save').onclick = () => {
    if (d.pin && !askPin('Текущий PIN')) return;
    const np = ($('par-pin').value || '').trim();
    if (np.length < 4) { alert('Минимум 4 символа'); return; }
    d.pin = np;
    parentalSave(d);
    alert('PIN сохранён');
  };
  $('par-blocks-save').onclick = () => {
    if (d.pin && !askPin('PIN родителя')) return;
    const raw = ($('par-blocks').value || '').split(/[,;\n]+/).map(s => s.trim()).filter(Boolean);
    const p = d.profiles.find(x => x.id === d.activeId);
    if (p) {
      p.blocks = p.blocks || {};
      p.blocks.keywords = raw;
      if (!Array.isArray(p.blocks.kpIds)) p.blocks.kpIds = [];
    }
    parentalSave(d);
    spaPlay('ok');
    alert('Сохранено: ' + raw.length + ' блок(ов)\\nПример проверки: «' + (raw[0]||'') + '»');
    openParental();
  };
}

function spaMskDayKey() {
  // сутки квестов с 03:00 МСК
  const now = new Date();
  const msk = new Date(now.getTime() + (3 - (-now.getTimezoneOffset()/60)) * 3600000);
  // simpler: use UTC+3 offset
  const t = new Date(now.getTime() + 3 * 3600000);
  if (t.getUTCHours() < 3) t.setUTCDate(t.getUTCDate() - 1);
  return t.toISOString().slice(0,10);
}
function spaQuestsToday() {
  const day = spaMskDayKey();
  try {
    const j = JSON.parse(localStorage.getItem('spa_quests_v1')||'null');
    if (j && j.day === day) return j;
  } catch(e) {}
  const q = { day, done: { open_cinema:false, open_music:false, server_chat:false, open_profile:false }, xp: 0 };
  try { localStorage.setItem('spa_quests_v1', JSON.stringify(q)); } catch(e) {}
  return q;
}
function spaQuestDone(key) {
  try { serverXpFromAch(3, 'quest:'+key); } catch(e) {}
  const q = spaQuestsToday();
  if (q.done[key]) return;
  q.done[key] = true;
  q.xp = (q.xp||0) + 10;
  try { localStorage.setItem('spa_quests_v1', JSON.stringify(q)); } catch(e) {}
  const all = q.done.open_cinema && q.done.open_music && q.done.server_chat && q.done.open_profile;
  if (all) { spaConfetti(50); spaAchUnlock('quests_full', 'Все квесты дня'); }
}
async function openSpaQuests() {
  try { closeDrawer(); if ($('detail-ov')) $('detail-ov').classList.add('on'); } catch(e) {}
  setDetailTitle('Ежедневные задания');
  showInPane('<div class="empty">Загружаю ежедневные задания…</div>');
  const data=await api('daily_tasks_status');
  if(!data||!data.ok){showInPane('<div class="empty">Не удалось загрузить задания. Проверь подключение.</div>');return;}
  let profileLevel=null;try{profileLevel=await api('profile_level_status');}catch(e){}
  const pane=document.getElementById('detail-body')||document.getElementById('pane-body');if(!pane)return;
  const fmtReset=()=>{try{return new Date(data.reset_at).toLocaleString('ru-RU',{hour:'2-digit',minute:'2-digit'});}catch(e){return '03:00';}};
  const render=()=>{pane.innerHTML=`<div class="card" style="background:linear-gradient(135deg,rgba(109,94,252,.24),rgba(30,190,170,.12));border:1px solid rgba(167,139,250,.25)"><div class="val">⚡ Ежедневные задания</div><div class="sub">Получено сегодня: <b>${data.earned||0} XP</b> · обновление ежедневно в 03:00 МСК</div>${profileLevel&&profileLevel.ok?`<div class="sub" style="margin-top:6px">⭐ Личный профиль: <b>${esc(spaLevelTitle(profileLevel.level))} · уровень ${profileLevel.level}</b> · ${Number(profileLevel.xp||0).toLocaleString('ru-RU')} XP</div>`:''}<div class="sub" style="margin-top:5px">Набор дня · ${esc(data.day||'')}</div></div>${data.items.map(x=>`<div class="card" style="display:flex;gap:12px;align-items:center;justify-content:space-between"><div style="min-width:0;flex:1"><div class="val" style="font-size:14px">${x.claimed?'✅':x.done?'🟣':'◯'} ${esc(x.title)}</div><div class="sub">${esc(x.desc)}</div><div class="sub" style="margin-top:5px">Прогресс: ${x.progress}/${x.need}</div></div><div style="text-align:right;flex:0 0 auto"><b style="color:#c4a7ff">+${x.xp} XP</b><div style="margin-top:6px">${x.claimed?'<span class="badge">Получено</span>':x.locked?'<span class="badge">Нужен сервер</span>':x.done?`<button class="btn-primary" data-claim-task="${esc(x.id)}" style="margin:0;padding:7px 10px">Забрать</button>`:'<span class="sub">В процессе</span>'}</div></div></div>`).join('')}<div class="card"><div class="sub">XP заданий повышает твой общий уровень профиля. Задания сервера дополнительно развивают твой уровень участника в этом сервере. Повторная награда за один день невозможна.</div></div><button type="button" class="btn-primary" id="daily-ach-open" style="background:rgba(255,255,255,.08)">🏆 Посмотреть достижения</button>`;pane.querySelectorAll('[data-claim-task]').forEach(b=>b.onclick=async()=>{b.disabled=true;const r=await api('daily_task_claim',{method:'POST',body:{task_id:b.dataset.claimTask}});if(!r||!r.ok){alert(r&&r.error||'Не удалось забрать награду');b.disabled=false;return;}spaPlay('ok');openSpaQuests();});const ach=pane.querySelector('#daily-ach-open');if(ach)ach.onclick=()=>openSpaAchievements();};render();
}


/* —— крутые фичи SPA —— */
function spaConfetti(n) {
  n = n || 40;
  const box = document.createElement('div');
  box.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:12000;overflow:hidden';
  document.body.appendChild(box);
  const colors = ['#a78bfa','#34d399','#f472b6','#fbbf24','#60a5fa','#fb7185'];
  for (let i = 0; i < n; i++) {
    const p = document.createElement('i');
    const x = Math.random() * 100;
    const d = 1.2 + Math.random() * 1.8;
    const s = 6 + Math.random() * 8;
    p.style.cssText = `position:absolute;left:${x}%;top:-12px;width:${s}px;height:${s}px;border-radius:${Math.random()>.5?'50%':'2px'};background:${colors[i%colors.length]};opacity:.95;animation:spaFall ${d}s linear forwards;transform:rotate(${Math.random()*360}deg)`;
    box.appendChild(p);
  }
  if (!document.getElementById('spa-fall-style')) {
    const st = document.createElement('style');
    st.id = 'spa-fall-style';
    st.textContent = '@keyframes spaFall{to{transform:translateY(105vh) rotate(720deg);opacity:0}}';
    document.head.appendChild(st);
  }
  setTimeout(() => box.remove(), 3200);
  try { spaPlay('ok'); } catch(e) {}
}

function spaMoodGet() {
  try { return JSON.parse(localStorage.getItem('spa_mood_v1')||'null') || { emoji:'😎', text:'' }; } catch(e) { return { emoji:'😎', text:'' }; }
}
function spaMoodSet(m) {
  try { localStorage.setItem('spa_mood_v1', JSON.stringify(m)); } catch(e) {}
  try { presence('mood', (m.emoji||'') + ' ' + (m.text||'')); } catch(e) {}
}

function spaAchLoad() {
  try { return JSON.parse(localStorage.getItem('spa_ach_v1')||'{}'); } catch(e) { return {}; }
}
function spaAchUnlock(id, title) {
  const a = spaAchLoad();
  if (a[id]) return false;
  try { serverXpFromAch(8, 'ach:'+id); } catch(e) {}
  a[id] = { at: Date.now(), title: title || id };
  try { localStorage.setItem('spa_ach_v1', JSON.stringify(a)); } catch(e) {}
  // toast
  const t = document.createElement('div');
  t.style.cssText = 'position:fixed;left:50%;bottom:90px;transform:translateX(-50%);z-index:11000;background:rgba(20,10,40,.95);border:1px solid rgba(167,139,250,.5);padding:12px 16px;border-radius:14px;font-size:14px;box-shadow:0 12px 40px rgba(0,0,0,.45)';
  t.innerHTML = '🏆 <b>Ачивка:</b> ' + (title || id);
  document.body.appendChild(t);
  spaConfetti(24);
  setTimeout(() => t.remove(), 3200);
  return true;
}
function spaAchCheck() {
  const q = spaQuestsToday();
  if (q.xp >= 40) spaAchUnlock('quests_full', 'Все квесты дня');
  if (q.xp >= 50) spaAchUnlock('quest_xp50', 'Гриндер');
  try {
    const n = (musicLibGet()||[]).length;
    if (n >= 3) spaAchUnlock('music_3', 'Коллекционер треков');
    if (n >= 10) spaAchUnlock('music_10', 'DJ');
  } catch(e) {}
  if (state.myServer) {
    spaAchUnlock('clan_join', 'В клане');
    if (state.myServer.role === 'owner' || state.myServer.is_owner) spaAchUnlock('clan_owner', 'Вождь');
    if ((state.myServer.members||0) >= 5) spaAchUnlock('clan_5', 'Отряд');
  }
  if (state.has_sub) spaAchUnlock('sub', 'Подписка SPA');
  try {
    if ((state.themeMode||'') === 'oled') spaAchUnlock('theme_oled', 'OLED-маньяк');
  } catch(e) {}
  try {
    const c = parseInt(localStorage.getItem('spa_cinema_opens')||'0',10);
    if (c >= 5) spaAchUnlock('cinema_5', 'Завсегдатай кино');
    if (localStorage.getItem('spa_qr_created_v1')) spaAchUnlock('qr_first','Создатель QR');
    if (localStorage.getItem('spa_files_opened_v1')) spaAchUnlock('files_first','Хранитель файлов');
    const hist=spaWatchHistGet(); if(Array.isArray(hist)&&hist.length>=10) spaAchUnlock('history_10','Летописец просмотров');
  } catch(e) {}
}

function openSpaAchievements() {
  try { closeDrawer(); if ($('detail-ov')) $('detail-ov').classList.add('on'); } catch(e) {}
  setDetailTitle('Ачивки');
  spaAchCheck();
  const a = spaAchLoad();
  const catalog = [
    ['quests_full', 'Все квесты дня', 'Закрой 4 квеста за сутки'],
    ['music_3', 'Коллекционер треков', '3+ трека в библиотеке'],
    ['music_10', 'DJ', '10+ треков в библиотеке'],
    ['clan_join', 'В клане', 'Вступи или создай сервер'],
    ['clan_owner', 'Вождь', 'Создай сервер'],
    ['clan_5', 'Отряд', '5+ человек на сервере'],
    ['sub', 'Подписка SPA', '14 дней подряд'],
    ['cinema_first', 'Киноман', 'Открой фильм в SPA'],
    ['cinema_5', 'Завсегдатай кино', '5 фильмов открыто'],
    ['night_owl', 'Ночная сова', 'Зайди после 23:00'],
    ['early_bird', 'Ранняя пташка', 'Зайди до 7:00 МСК'],
    ['story_post', 'Сторимейкер', 'Опубликуй историю'],
    ['dm_first', 'Переписка', 'Открой диалог'],
    ['react_first', 'Эмоциональный', 'Поставь реакцию'],
    ['playlist_share', 'Куратор', 'Общий плейлист сервера'],
    ['fav_5', 'Коллекционер', '5 избранных'],
    ['theme_oled', 'OLED-маньяк', 'Включи OLED тему'],
    ['lang_switch', 'Полиглот', 'Смени язык интерфейса'],
    ['quest_xp50', 'Гриндер', '50 XP за день'],
    ['block_parent', 'Родительский контроль', 'Заблокируй контент'],
    ['qr_first', 'Создатель QR', 'Создай свой первый QR-код'],
    ['files_first', 'Хранитель файлов', 'Открой своё локальное хранилище'],
    ['history_10', 'Летописец просмотров', 'Собери 10 записей в истории'],
    ['server_role', 'Своё звание', 'Получи пользовательскую роль на сервере'],
    ['server_badge', 'Коллекционер значков', 'Получи значок от администрации сервера'],
    ['server_poll', 'Голос сообщества', 'Проголосуй в серверном опросе'],
    ['server_event', 'Организатор', 'Создай событие на сервере'],
    ['server_service', 'Создатель сервисов', 'Добавь сервис на сервер'],
    ['collection_shared', 'Куратор коллекций', 'Поделись подборкой с сервером']
  ];
  const h = new Date().getHours();
  if (h >= 23 || h < 5) spaAchUnlock('night_owl', 'Ночная сова');
  showInPane(`
    <div class="card" style="background:linear-gradient(135deg,rgba(251,191,36,.2),rgba(109,94,252,.15))">
      <div class="val">🏆 Ачивки</div>
      <p style="font-size:13px;color:var(--muted);margin:8px 0 0">Открыто: ${Object.keys(a).length} / ${catalog.length}</p>
    </div>
    ${catalog.map(([id,title,sub]) => {
      const on = !!a[id];
      return `<div class="card" style="opacity:${on?1:.55}"><div class="val">${on?'✅':'🔒'} ${title}</div>
        <p style="font-size:12px;color:var(--muted);margin:6px 0 0">${sub}</p></div>`;
    }).join('')}
  `);
}

function openSpaMood() {
  try { closeDrawer(); if ($('detail-ov')) $('detail-ov').classList.add('on'); } catch(e) {}
  setDetailTitle('Настроение');
  const m = spaMoodGet();
  const emojis = ['😎','🔥','😴','🎮','🎵','🎬','💜','🚀','🥶','🫠','👻','✨'];
  showInPane(`
    <div class="card"><div class="val">Настроение в SPA</div>
      <p style="font-size:13px;color:var(--muted);margin:8px 0 0">Видно в presence · только у тебя на устройстве текст</p></div>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0" id="mood-em">
      ${emojis.map(e => `<button type="button" class="btn-primary mood-e" data-e="${e}" style="margin:0;width:48px;font-size:22px;background:${m.emoji===e?'linear-gradient(135deg,#6d5efc,#8b5cf6)':'rgba(255,255,255,.08)'}">${e}</button>`).join('')}
    </div>
    <input class="field" id="mood-text" placeholder="Короткий статус" value="${esc(m.text||'')}" maxlength="40">
    <button type="button" class="btn-primary" id="mood-save">Сохранить</button>
  `);
  let cur = m.emoji || '😎';
  document.querySelectorAll('.mood-e').forEach(b => {
    b.onclick = () => {
      cur = b.dataset.e;
      document.querySelectorAll('.mood-e').forEach(x => x.style.background = 'rgba(255,255,255,.08)');
      b.style.background = 'linear-gradient(135deg,#6d5efc,#8b5cf6)';
    };
  });
  $('mood-save').onclick = () => {
    spaMoodSet({ emoji: cur, text: ($('mood-text').value||'').trim().slice(0,40) });
    spaPlay('ok');
    alert('Настроение: ' + cur);
  };
}

function applyAutoTheme() {
  try {
    const c = JSON.parse(localStorage.getItem('streamspa_settings')||'{}');
    if (!c.autoTheme) return;
    const h = new Date().getHours();
    const dark = !(h >= 8 && h < 20);
    applyTheme(dark);
  } catch(e) {}
}



async function openMessenger() {
  try { closeDrawer(); if ($('detail-ov')) $('detail-ov').classList.add('on'); } catch(e) {}
  destroySpaPlayer();
  setDetailTitle('Сообщения');
  try { spaAchUnlock('dm_first','Переписка'); } catch(e) {}
  showInPane('<div class="empty">Загрузка…</div>');
  const data = await api('dm_list');
  if (!data || !data.ok) {
    showInPane(`<div class="card"><div class="val">💬 Сообщения</div>
      <p style="font-size:13px;color:var(--muted);margin:8px 0 0">${esc((data&&data.error)||'Нужны таблицы conversations / messages движка')}</p></div>`);
    return;
  }
  const list = data.conversations || [];
  const box = showInPane(`
    <div class="card" style="background:linear-gradient(135deg,rgba(109,94,252,.2),rgba(0,0,0,.1))">
      <div class="val">💬 Сообщения</div>
      <p style="font-size:12px;color:var(--muted);margin:6px 0 0">Диалоги StreamLive · ${list.length}</p>
    </div>
    ${list.length ? list.map(c => `
      <div class="row" data-conv="${c.conv_id}">
        <img class="av" src="${esc(c.peer.avatar||'')}" alt="" onerror="this.outerHTML='<div class=\\'av letter\\'>@</div>'">
        <div class="body"><div class="title">${esc(c.peer.username||'')}</div>
        <div class="sub">${esc((c.last_body||'Нет сообщений').slice(0,72))}</div></div>
        <div class="meta">${esc((c.last_at||'').slice(11,16))}</div>
      </div>`).join('') : '<div class="empty">Пусто · открой профиль друга → «Написать»</div>'}
  `);
  box.querySelectorAll('[data-conv]').forEach(r => {
    r.onclick = () => openDmThread(+r.dataset.conv, (r.querySelector('.title') && r.querySelector('.title').textContent) || '');
  });
}

async function openDmThread(convId, title) {
  setDetailTitle(title || 'Диалог');
  showInPane('<div class="empty">…</div>');
  await loadEngineStickers();
  const data = await api('dm_thread', { params:{ conv_id: convId } });
  if (!data || !data.ok) {
    showInPane('<div class="empty">' + esc((data&&data.error)||'Ошибка') + '</div>');
    return;
  }
  const msgs = data.messages || [];
  let replyTo = null;
  showInPane(`
    <div id="dm-log" style="max-height:48vh;overflow-y:auto;padding:8px 4px">
      ${msgs.map(m => `
        <div style="display:flex;flex-direction:column;margin:10px 0;align-items:${m.mine?'flex-end':'flex-start'}">
          <div class="dm-bubble ${m.mine?'mine':'theirs'}">
            ${m.mine?'':`<div style="font-size:11px;color:var(--accent2);margin-bottom:2px">@${esc(m.username)}</div>`}
            <div>${renderStickerText(m.body)}</div>
            <div class="dm-meta">${esc((m.created_at||'').slice(11,16))}</div>
          </div>
          <button type="button" class="dm-reply-btn" data-body="${esc(m.body).slice(0,80)}" data-user="${esc(m.username||'')}" style="font-size:11px;color:var(--muted);margin-top:2px">↩ ответ</button>
        </div>`).join('') || '<div class="empty">Напиши первым</div>'}
    </div>
    <div id="dm-reply-bar" style="display:none;font-size:12px;color:var(--muted);padding:6px 8px;border-left:3px solid var(--accent);margin:6px 0"></div>
    ${engineStickerBarHtml('dm-engine-st')}
    <div class="dm-stickers" id="dm-st">
      ${['😀','😂','❤️','🔥','👍','🎉','😎','🙏','💯','🎵','🎬','🏰'].map(s => `<button type="button" data-s="${s}">${s}</button>`).join('')}
    </div>
    <div class="danmaku-input">
      <input id="dm-inp" maxlength="2000" placeholder="Сообщение…">
      <button type="button" id="dm-send">→</button>
    </div>
    <button type="button" class="btn-primary" id="dm-back" style="margin-top:8px;background:rgba(255,255,255,.08)">← Диалоги</button>
  `);
  const log = $('dm-log');
  if (log) log.scrollTop = log.scrollHeight;
  document.querySelectorAll('.dm-reply-btn').forEach(b => {
    b.onclick = () => {
      replyTo = { user: b.dataset.user, body: b.dataset.body };
      const bar = $('dm-reply-bar');
      if (bar) { bar.style.display = 'block'; bar.textContent = '↩ ' + (replyTo.user||'') + ': ' + (replyTo.body||''); }
    };
  });
  fillEngineStickerBar('dm-engine-st', async (code) => {
    // code already ":name:" — also try resolve to URL for fallback display
    let body = code;
    const map = stickerCodeMap();
    const bare = normalizeStickerCode(code);
    if (map[bare]) {
      body = stickerToken(code); // StreamLive хранит и передаёт ровно один :code:
    }
    await api('dm_send', { method:'POST', body:{ conv_id: convId, body: body } });
    openDmThread(convId, title);
  });
  bindDarkStickers('dm-dark-st', async (emoji) => {
    await api('dm_send', { method:'POST', body:{ conv_id: convId, body: emoji } });
    openDmThread(convId, title);
  });
  document.querySelectorAll('#dm-st button').forEach(b => {
    b.onclick = async () => {
      const body = b.dataset.s;
      await api('dm_send', { method:'POST', body:{ conv_id: convId, body } });
      openDmThread(convId, title);
    };
  });
  $('dm-back').onclick = () => openMessenger();
  const doSend = async () => {
    let body = ($('dm-inp').value||'').trim();
    if (!body) return;
    if (replyTo) {
      body = '↩ ' + (replyTo.user||'') + ': ' + (replyTo.body||'') + '\\n' + body;
      replyTo = null;
    }
    const r = await api('dm_send', { method:'POST', body:{ conv_id: convId, body } });
    if (r && r.ok) openDmThread(convId, title);
    else alert((r&&r.error)||'Ошибка');
  };
  $('dm-send').onclick = doSend;
  $('dm-inp').onkeydown = (e) => { if (e.key === 'Enter') doSend(); };
}

async function startDmWithUser(userId, username) {
  const r = await api('dm_start', { method:'POST', body:{ user_id: userId } });
  if (r && r.ok && r.conv_id) openDmThread(r.conv_id, username || 'Диалог');
  else alert((r && r.error) || 'Не удалось открыть диалог');
}


function setDetailTitle(title) {
  const el = $('detail-title');
  if (el) el.textContent = title || 'Просмотр';
}
function showInPane(html){
  if (isDesktop()) {
    $('pane-empty').style.display = 'none';
    const pb = $('pane-body');
    pb.style.display = 'block';
    pb.innerHTML = html;
    spaTranslateDom(pb);
    return pb;
  }
  $('detail-ov').classList.add('on');
  $('detail-body').innerHTML = html;
  spaTranslateDom($('detail-body'));
  return $('detail-body');
}
function showLimit(msg, retryAt, retrySeconds){
  spaPlay('err');
  const retryMs = Number.isFinite(Number(retrySeconds)) && Number(retrySeconds)>0 ? Date.now()+Number(retrySeconds)*1000 : (typeof retryAt==='number'||/^\d+$/.test(String(retryAt||'')) ? Number(retryAt)*1000 : Date.parse(String(retryAt||'').replace(' ','T')));
  const retry = Number.isFinite(retryMs) && retryMs>Date.now() ? retryMs : 0;
  const countdownId = 'spa-limit-countdown';
  const countdown = retry > 0 ? `<p id="${countdownId}" style="font-size:13px;font-weight:700">Повторный доступ: ${new Date(retry).toLocaleString()}</p>` : '';
  showInPane(`<div class="limit-banner"><h3>${spaLang()==='en'?'Usage limit reached':spaLang()==='uk'?'Ліміт без підписки':'Лимит без подписки'}</h3><p>${esc(msg)}</p>${countdown}
    <p style="font-size:13px">${spaLang()==='en'?'Each category has its own 24-hour cooldown, starting when its limit is reached.':'Для каждой категории действует отдельная пауза ровно 24 часа с момента достижения лимита.'}</p>
    <button class="btn-primary" type="button" onclick="setScreen('profile');loadProfile()">${spaLang()==='en'?'Subscription settings':'К подписке в профиле'}</button></div>`);
  if (retry > 0) {
    const el = $(countdownId);
    const tick = () => {
      if (!el || !el.isConnected) return;
      const left = Math.max(0, Math.ceil((retry-Date.now())/1000));
      const h = Math.floor(left/3600), m = Math.floor((left%3600)/60), sec = left%60;
      el.textContent = (spaLang()==='en'?'Available in: ':'Доступ через: ') + [h,m,sec].map(v=>String(v).padStart(2,'0')).join(':');
      if (left > 0) setTimeout(tick,1000);
      else el.textContent = spaLang()==='en'?'Available now — reload the content.':'Лимит восстановлен — открой контент снова.';
    };
    tick();
  }
}

async function openItem(ds){
  if (state._spaConf && state._spaConf.confirmOpen) {
    if (!confirm('Открыть: ' + (ds.kind || '') + '?')) return;
  }
  const blob = (ds.title || '') + ' ' + (ds.subtitle || '') + ' ' + (ds.platform || '');
  if (!parentalAllowsText(blob)) {
    spaPlay('err');
    showInPane('<div class="empty">Скрыто семейным фильтром</div>');
    return;
  }
  const need = ytNeedAgeGate(blob);
  if (need) {
    showYtAgeGate(ds.title || ds.kind || 'Контент', need, () => {
      spaPlay('open');
      if (ds.kind==='user') return openProfile(ds.username);
      if (ds.kind==='channel') return openChannel(ds.id, ds.slug);
      if (ds.kind==='video') return openVideo(ds.id, ds.embed, ds.source);
    });
    return;
  }
  spaPlay('open');
  if (ds.kind==='user') return openProfile(ds.username);
  if (ds.kind==='channel') return openChannel(ds.id, ds.slug);
  if (ds.kind==='video') return openVideo(ds.id, ds.embed, ds.source);
}

/* Floating messages over the player (engine-style danmaku) */
let __dmSeen=new Set(), __dmTimer=null, __dmTarget=null;
function spawnDanmaku(text,color){
  if(state._spaConf && state._spaConf.danmaku===false) return;
  const layer=document.getElementById('danmaku-layer'); if(!layer||!text)return;
  const el=document.createElement('div'); el.className='danmaku-item'; el.textContent=String(text).slice(0,120);
  el.style.color=/^#[0-9a-f]{6}$/i.test(color||'')?color:'#fff';
  el.style.top=(8+Math.random()*70)+'%'; el.style.left='100%'; el.style.animationDuration=(8+Math.random()*6)+'s';
  layer.appendChild(el); el.addEventListener('animationend',()=>el.remove());
}
async function loadDanmaku(type,id,initial){
  const data=await api('danmaku_list',{params:{type,id}}); if(!data||!data.ok||!Array.isArray(data.items))return;
  const list=document.getElementById('dm-comments-list');
  if(list){const rows=data.items.slice(-60).reverse();list.innerHTML=rows.length?rows.map(it=>`<article style="padding:9px 11px;border-radius:12px;background:rgba(255,255,255,.045);border:1px solid rgba(255,255,255,.07)"><div style="display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:4px"><b style="font-size:12px">${esc(it.username||'Пользователь')}</b><time style="font-size:11px;color:var(--muted)">${esc(fmtTime(it.created_at)||'')}</time></div><div style="font-size:13px;white-space:pre-wrap;overflow-wrap:anywhere">${esc(it.text||'')}</div></article>`).join(''):'<div class="empty">Пока нет комментариев. Будь первым!</div>';}
  const visible=initial?data.items.slice(-40):data.items;visible.forEach((it,index)=>{const key=String(it.source||'')+':'+String(it.id||'');if(__dmSeen.has(key))return;__dmSeen.add(key);if(initial)setTimeout(()=>spawnDanmaku(it.text,it.color),Math.min(index,39)*360);else spawnDanmaku(it.text,it.color);});
  if(__dmSeen.size>500)__dmSeen=new Set(Array.from(__dmSeen).slice(-250));
}
function danmakuForm(type,id){
  return `<section class="sl-comments-panel" style="margin:14px 0;padding:14px;border:1px solid var(--line,rgba(255,255,255,.12));border-radius:16px;background:var(--glass,rgba(255,255,255,.04))"><div style="font-weight:700;margin-bottom:10px">Комментарии</div><div class="danmaku-input"><input id="dm-text" maxlength="120" placeholder="Написать комментарий"><button type="button" id="dm-send">Отправить</button></div><div id="dm-comments-list" aria-live="polite" style="display:grid;gap:9px;margin-top:12px"><div class="empty">Загружаю комментарии…</div></div></section>`;
}
function bindDanmaku(type,id){
  if(__dmTimer){clearInterval(__dmTimer);__dmTimer=null;} __dmSeen=new Set();__dmTarget={type,id};
  const btn=document.getElementById('dm-send'),inp=document.getElementById('dm-text');
  if(btn&&inp){const send=async()=>{const text=inp.value.trim();if(!text)return;btn.disabled=true;try{const r=await api('danmaku_add',{method:'POST',body:{type,id,text,color:'#ffffff'}});if(r&&r.ok){inp.value='';await loadDanmaku(type,id,false);}}finally{btn.disabled=false;}};btn.onclick=send;inp.onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();send();}};}
  loadDanmaku(type,id,true); __dmTimer=setInterval(()=>{if(__dmTarget&&(document.getElementById('danmaku-layer')||document.getElementById('dm-comments-list')))loadDanmaku(type,id,false);else{clearInterval(__dmTimer);__dmTimer=null;}},5000);
}

function spaLevelTitle(level){const names=['Новичок','Легенда','Активный','Опытный','Ветеран','Мастер','Эксперт','Элита','Чемпион','Грандмастер','Мифический','Титан','Абсолют','Бессмертный','Властелин','Легенда StreamLive'];return names[Math.max(0,Math.min(names.length-1,(Number(level)||1)-1))];}
function spaLevelMedal(level,title,xp,progress,subline){const lv=Math.max(1,Number(level)||1),pct=Math.max(0,Math.min(100,Number(progress)||0));return `<section class="sl-level-showcase"><div class="sl-level-showcase-copy"><div class="sl-level-eyebrow">ВАШ УРОВЕНЬ</div><div class="sl-level-name">${esc(title||spaLevelTitle(lv))}</div><div class="sl-level-xp">${Number(xp||0).toLocaleString('ru-RU')} XP</div><div class="sl-level-progress"><i style="width:${pct}%"></i></div><div class="sl-level-next">${esc(subline||'Продолжай выполнять задания и открывать достижения')}</div></div><div class="sl-level-medal" aria-label="Уровень ${lv}"><svg viewBox="0 0 180 180" role="img" aria-hidden="true"><defs><linearGradient id="slMedalG" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#e8c7ff"/><stop offset=".38" stop-color="#a855f7"/><stop offset=".72" stop-color="#6135cf"/><stop offset="1" stop-color="#25115c"/></linearGradient><radialGradient id="slMedalCore"><stop offset="0" stop-color="#4c2c9b"/><stop offset="1" stop-color="#120b2d"/></radialGradient></defs><path d="M90 5 111 20 138 18 148 43 171 59 163 86 171 111 148 129 139 155 111 153 90 175 69 153 41 155 32 129 9 111 17 86 9 59 32 43 41 18 69 20Z" fill="url(#slMedalG)" stroke="#e9c8ff" stroke-width="3"/><path d="M90 22 108 34 130 32 139 53 157 66 151 88 157 109 139 122 130 144 108 142 90 158 72 142 50 144 41 122 23 109 29 88 23 66 41 53 50 32 72 34Z" fill="url(#slMedalCore)" stroke="#cba6ff" stroke-width="2"/><circle cx="90" cy="88" r="48" fill="none" stroke="#b78aff" stroke-width="2" opacity=".8"/><text x="90" y="104" text-anchor="middle" font-size="${lv>99?37:52}" font-weight="900" fill="#fff" font-family="Arial,sans-serif">${lv}</text><path d="M90 27 95 39 108 40 98 49 101 62 90 55 79 62 82 49 72 40 85 39Z" fill="#f2d7ff" opacity=".85"/></svg></div></section>`;}

async function openProfile(username){
  destroySpaPlayer();
  setDetailTitle('Профиль');
  try { spaQuestDone('open_profile'); } catch(e) {}
  const data = await api('profile', { params:{ username } });
  if (data.limited) { showLimit(data.error, data.retry_at, data.retry_seconds); return; }
  if (!data.ok) { showInPane('<div class="empty">Не найден</div>'); return; }
  if (!data.server) {
    try {
      const sm = await api('server_mine');
      if (sm && sm.ok && sm.server && data.profile && sm.server) {
        /* only attach for matching user if is_self */
        if (data.is_self) data.server = { id: sm.server.id, name: sm.server.name, members: sm.server.members };
      }
    } catch(e) {}
  }
  const p = data.profile, pr = data.presence, s = data.streak||{}, rank = data.rank;
  let presenceHtml = pr
    ? `<div class="card"><div class="label">${t('now_on_page')}</div><div class="val"><span class="dot"></span> ${esc(pr.detail||pr.tab_name||'—')}<div style="font-size:11px;color:var(--muted);margin-top:4px">${esc(pr.activity||'StreamLive')} · ${esc(pr.tab_name||'')}</div></div></div>`
    : `<div class="card"><div class="label">${t('now')}</div><div class="val" style="color:var(--muted)">${t('no_data')}</div></div>`;
  let chHtml = (data.channels||[]).map(ch => rowHtml({ kind:'channel', id:ch.id, slug:ch.slug, title:ch.title, description:ch.description||'', subtitle:'Канал', avatar:ch.logo||'' })).join('');
  
  let levelHtml = '';
  let questsHtml = '';
  try { const lv = await spaFetchProfileLevelData(username||(data.profile&&data.profile.username)||''); levelHtml = spaBuildLevelHtml(lv); } catch(e) {}
  if (data.is_self) { try { questsHtml = await spaFetchDailyQuestsHtml(); } catch(e) {} }
const box = showInPane(`
    <div class="prof-head"><img class="av" src="${esc(p.avatar)}"><h2 style="margin:0">${esc(p.username)}</h2>
    <div style="color:var(--muted)">@${esc(p.username)} ${rank&&rank.title?'· '+esc(rank.title):''} · XP ${p.xp||0}</div>
    ${p.profile_status_text?`<div style="margin-top:8px;color:var(--muted)">${esc(p.profile_status_text)}</div>`:''}</div>
    <div id="profile-level-cards">${levelHtml}</div>
    ${data.is_self?`<div id="profile-daily-quests-box" style="margin:8px 0 12px">${questsHtml}</div><button type="button" class="btn-primary" id="profile-daily-quests" style="margin:8px 0 12px;background:linear-gradient(100deg,rgba(124,92,230,.9),rgba(168,85,247,.9))">⚡ Ежедневные задания · получить XP</button>`:''}
    <div class="card"><div class="label">Имя</div><div class="val">@${esc(p.username)}</div></div>
    ${data.server?`<div class="card"><div class="label">Сервер</div><div class="val">🏰 ${esc(data.server.name)} · ${data.server.members} чел.</div></div>`:''}
    ${presenceHtml}
    ${data.is_self && s ? `<div class="card"><div class="label">Подписка SPA</div><div class="val">${s.streak_days||0}/${s.streak_need||14} дней</div>
      ${s.subscription_active?`<span class="badge">до ${esc(s.subscription_until)}</span>`:'<p style="font-size:12px;color:var(--muted);margin:8px 0 0">14 дней подряд = 30 дней подписки. Без неё лимит каждой категории действует 24 часа с момента исчерпания: профили, каналы, видео и фильмы.</p>'}</div>`:''}
    ${chHtml?`<div style="font-weight:700;padding:8px 6px">${t('channels')}</div>${chHtml}`:''}`);
  bindRows(box);
  const dqBox=box.querySelector('#profile-daily-quests-box'); if(dqBox) spaBindDailyQuestClaims(dqBox);
  const pfA=box.querySelector('#pf-activity'); if(pfA) pfA.onclick=()=>spaOpenMyActivity();
  const pfQ=box.querySelector('#pf-queue'); if(pfQ) pfQ.onclick=()=>spaOpenWatchQueue();
  const pfS=box.querySelector('#pf-stats'); if(pfS) pfS.onclick=()=>spaOpenMyStats();
  const pfN=box.querySelector('#pf-notify'); if(pfN) pfN.onclick=()=>spaOpenNotifyCenter();

  const dailyQuestBtn=box.querySelector('#profile-daily-quests'); if(dailyQuestBtn) dailyQuestBtn.onclick=()=>openSpaQuests();

  presence('profile', 'Профиль: @'+username);
  try {
    const uid = (typeof p !== 'undefined' && p && p.id) ? p.id : null;
    if (uid && state.user && +uid !== +state.user.id) {
      const pane = document.getElementById('detail-body') || document.getElementById('pane-body');
      if (pane && !pane.querySelector('#dm-start-btn')) {
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'btn-primary'; b.id = 'dm-start-btn';
        b.textContent = '💬 Написать';
        b.style.marginTop = '12px';
        b.onclick = () => startDmWithUser(uid, username);
        pane.appendChild(b);
      }
    }
  } catch(e) {}
  try { continuePush({ kind:'user', id:p.id, title:p.username, username:p.username, avatar:p.avatar||'' }); } catch(e) {}
}

async function spaBindContentReactions(root,type,id){
  if(!root||!id)return;const box=root.querySelector('[data-content-reactions]');if(!box)return;
  const like=box.querySelector('[data-content-vote="1"]'),dislike=box.querySelector('[data-content-vote="-1"]'),fav=box.querySelector('[data-content-fav]');
  const paint=r=>{if(like){like.classList.toggle('active',Number(r.my_vote)===1);like.querySelector('b').textContent=String(r.likes||0);}if(dislike){dislike.classList.toggle('active',Number(r.my_vote)===-1);dislike.querySelector('b').textContent=String(r.dislikes||0);}};
  try{const d=await api('content_reaction_get',{method:'POST',body:{type,id}});if(d&&d.ok){paint(d.reaction||{});if(fav)fav.textContent=d.favorite?'★ В избранном':'☆ В избранное';}}catch(e){}
  [like,dislike].filter(Boolean).forEach(b=>b.onclick=async(e)=>{if(e)e.stopPropagation();const v=Number(b.dataset.contentVote),cur=box.querySelector('[data-content-vote].active'),next=cur&&Number(cur.dataset.contentVote)===v?0:v;box.querySelectorAll('[data-content-vote]').forEach(x=>x.disabled=true);try{const d=await api('content_reaction_set',{method:'POST',body:{type,id,vote:next}});if(d&&d.ok)paint(d.reaction||{});}finally{box.querySelectorAll('[data-content-vote]').forEach(x=>x.disabled=false);}});
  if(fav)fav.onclick=async(e)=>{if(e)e.stopPropagation();fav.disabled=true;try{const d=await api('fav_toggle',{method:'POST',body:{type,id}});if(d&&d.ok)fav.textContent=d.on?'★ В избранном':'☆ В избранное';}finally{fav.disabled=false;}};
}
function spaContentReactionHtml(type,id){return `<div class="yt-reaction-bar sl-content-reactions" data-content-reactions><button type="button" data-content-vote="1">👍 <b>0</b></button><button type="button" data-content-vote="-1">👎 <b>0</b></button><button type="button" data-content-fav>☆ В избранное</button></div>`;}

async function openChannel(id, slug){
  destroySpaPlayer();
  setDetailTitle('Канал');
  // детский фильтр до загрузки
  try {
    if (!parentalAllowsText({ kind:'channel', id:id, title:String(slug||id) })) {
      showInPane('<div class="empty">Канал скрыт семейным фильтром (профиль '+(parentalActive().maxAge||12)+'+)</div>');
      return;
    }
  } catch(e) {}
  const data = await api('channel', { params:{ id, slug } });
  if (data.limited) { showLimit(data.error, data.retry_at, data.retry_seconds); return; }
  if (data.blocked) { showInPane('<div class="empty">🚫 '+(data.error||'Канал заблокировал вас')+'</div>'); return; }
  if (!data.ok) { showInPane('<div class="empty">'+(data.error||'Ошибка')+'</div>'); return; }
  const c = data.channel, live = data.live;
  let liveHtml = '', liveUrl = '';
  if (live && live.url) { liveUrl = live.url; liveHtml = buildLivePlayerHtml(live, c.title, c.logo); }
  let sched = '';
  if (data.schedule && data.schedule.length) {
    sched = '<div class="card"><div class="label">Расписание</div>'+data.schedule.slice(0,14).map(s=>
      `<div style="font-size:13px;color:var(--muted);padding:3px 0">${esc(String(s.day_of_week||''))} ${esc(String(s.start_time||''))} · ${esc(s.source_name||'')}</div>`).join('')+'</div>';
  }
  const vids = (data.videos||[]).filter(v => {
    try { return parentalAllowsText({ kind:'video', id:v.id, title:v.title, platform:v.platform||'' }); } catch(e) { return true; }
  }).map(v => rowHtml({
    kind:'video', id:v.id, slug:v.slug||'', title:v.title, description:v.description||'', subtitle:'Видео',
    avatar:v.thumbnail_url||'', embed_url:v.embed_url||'', source_url:v.source_url||'', platform:v.platform||'', time:v.created_at
  })).join('');
  const box = showInPane(`
    <div class="prof-head">${c.logo?`<img class="av" src="${esc(c.logo)}">`:''}<h2 style="margin:0">${esc(c.title)} ${ytAgeBadgeHtml(analyzeContentRatingSafe({kind:'channel',title:c.title,description:c.description||'',slug:c.slug||''}))}</h2>
    <div style="color:var(--muted)">/${esc(c.slug)} · 👁 ${c.views||0}</div>
    <div style="color:var(--muted);font-size:13px;margin-top:8px;white-space:pre-wrap">${esc(c.description||'')}</div>${spaContentReactionHtml('channel',c.id)}</div>
    ${liveHtml}${danmakuForm('channel', c.id)}${sched}
    <div style="font-weight:700;padding:8px 6px">${t('videos')} (${(data.videos||[]).length})</div>${vids||'<div class="empty">Нет видео</div>'}`);
  if (liveUrl) {
    // buildLivePlayerHtml creates #playerjs-container; mount unconditionally or it stays black.
    mountSpaPlayer(liveUrl, (live && live.type) || '', c.title, {autoplay:true,muted:true});
  }
  // как channel.php: если активный источник сменился — обновляем плеер
  if (window.__chLiveTimer) clearInterval(window.__chLiveTimer);
  const channelSyncShape = (ch) => ch ? {id:ch.id||0,title:ch.title||'',slug:ch.slug||'',description:ch.description||'',logo:ch.logo||'',status:ch.status||'',is_broadcast_paused:!!ch.is_broadcast_paused,updated_at:ch.updated_at||''} : null;
  let __lastChannelSnapshot = JSON.stringify({channel:channelSyncShape(c),live:live||null,schedule:data.schedule||[]});
  window.__chLiveTimer = setInterval(async () => {
    try {
      const lv = await api('channel_live', { params:{ id: c.id } });
      if (!lv || !lv.ok) {
        if (lv && (lv.http === 404 || lv.code === 'not_found')) {
          destroySpaPlayer();
          showInPane('<div class="empty">Этот канал удалён или больше недоступен.</div>');
        }
        return;
      }
      const snapshot = JSON.stringify({channel:channelSyncShape(lv.channel),live:lv.live||null,schedule:lv.schedule||[]});
      if (snapshot !== __lastChannelSnapshot) {
        __lastChannelSnapshot = snapshot;
        // The PHP channel page resolves the current source and its metadata from the
        // database on every refresh. Re-open only this channel pane (not the whole SPA)
        // so title/logo/description/schedule/source/player type all stay in sync.
        await openChannel(c.id, (lv.channel && lv.channel.slug) || c.slug || '');
      }
    } catch(e) { console.warn('StreamLive channel sync:', e); }
  }, 8000);
  bindDanmaku('channel', c.id);
  spaBindContentReactions(box,'channel',c.id);
  bindRows(box);
  try {
    if (!parentalAllowsText({ kind:'channel', id:c.id, title:c.title, subtitle:c.description||'', platform:c.slug||'' })) {
      showInPane('<div class="empty">Канал «'+esc(c.title)+'» скрыт для профиля до '+(parentalActive().maxAge||12)+'+</div>');
      return;
    }
  } catch(e) {}
  presence('channel', 'Канал: '+c.title);
  try { spaWatchHistAdd({ kind:'channel', id:c.id, title:c.title }); } catch(e) {}
  try { continuePush({ kind:'channel', id:c.id, title:c.title, avatar:c.logo||'', slug:c.slug||'' }); } catch(e) {}
}

async function openVideo(id, embed, source){
  closeMiniVideoPlayer();
  destroySpaPlayer();
  setDetailTitle('Видео');
  const data = await api('video', { params:{ id } });
  if (data.limited) { showLimit(data.error, data.retry_at, data.retry_seconds); return; }
  if (!data.ok) { showInPane('<div class="empty">'+(data.error||'Не найдено')+'</div>'); return; }
  const v = data.video;
  const platform=String(v.platform||'').toLowerCase();
  const isDirect = (url) => /\.(mp4|webm|ogg|m4v|mov|m3u8)(\?|$)/i.test(String(url||'')) || ['mp4','m3u8','hls','webm','ogg'].includes(platform);
  // Для обычных файлов приоритет у source_url. Embed берём только если он реально
  // сохранён в записи видео; никакой автогенерации через старый PHP-модуль.
  const directSource = isDirect(v.source_url) ? v.source_url : '';
  const emb = String(directSource || v.embed_url || embed || v.source_url || source || '').trim();
  if (!emb) {
    showInPane(`<div class="empty">Для этого видео не указан источник воспроизведения.</div>
      <div class="card"><div class="val">${esc(v.title)} ${ytAgeBadgeHtml(analyzeContentRatingSafe({kind:'video',title:v.title,description:v.description||'',platform:v.platform||''}))}</div>
      <div style="color:var(--muted);font-size:13px;margin-top:6px">${esc(v.channel_title||'')}</div></div>`);
    return;
  }
  showInPane(`${buildPlayerHtml(emb, v.platform, v.title, v.source_url, {poster:v.thumbnail_url})}${danmakuForm('video', v.id)}
    <div class="card"><div class="val">${esc(v.title)} ${ytAgeBadgeHtml(analyzeContentRatingSafe({kind:'video',title:v.title,description:v.description||'',platform:v.platform||''}))}</div>
    <div style="color:var(--muted);font-size:13px;margin-top:6px">${esc(v.channel_title||'')} · ${esc(v.platform||'')}</div>
    <div style="font-size:13px;margin-top:8px;white-space:pre-wrap">${esc(v.description||'')}</div>${spaContentReactionHtml('video',v.id)}</div>`);
  const videoPane=document.getElementById('detail-body')||document.getElementById('pane-body');spaBindContentReactions(videoPane,'video',v.id);
  const resumeItem={kind:'video',id:v.id,title:v.title,avatar:v.thumbnail_url||'',embed_url:v.embed_url||'',platform:v.platform||'',slug:v.slug||''};
  if(state.user){try{const remote=await api('media_continue_list');if(remote&&remote.ok&&Array.isArray(remote.items)){const found=remote.items.find(x=>x.kind==='video'&&String(x.id)===String(v.id));if(found){const local=continueFind('video',v.id)||{};const merged={...found,...local,position:Number(local.position||found.position||0),duration:Number(local.duration||found.duration||0),progress:Number(local.progress||found.progress||0)};const all=continueLoad().filter(x=>!(x.kind==='video'&&String(x.id)===String(v.id)));all.unshift({...merged,...resumeItem,position:merged.position,duration:merged.duration,progress:merged.progress});continueSave(all);}}}catch(e){}}
  try{continuePush(resumeItem);}catch(e){}
  if (document.getElementById('playerjs-container')) {
    mountSpaPlayer(emb, v.platform, v.title, {poster:v.thumbnail_url,autoplay:false,muted:false});
    continueObservePlayer(resumeItem);
  }
  bindDanmaku('video', v.id);
  presence('video', 'Смотрит: '+v.title);
  try { spaWatchHistAdd({ kind:'video', id:v.id, title:v.title }); } catch(e) {}
}


async function openFavorites() {
  destroySpaPlayer();
  setDetailTitle('Избранное');
  const cinemaAllowed = await spaCinemaGate(false);
  const data = await api('favorites');
  const items = (data && data.ok && Array.isArray(data.items) ? data.items : []).filter(it => ['channel','video','cinema'].includes(it.target_type) && (cinemaAllowed || it.target_type !== 'cinema'));
  if (!items.length) { showInPane('<div class="empty">Избранное пусто. Добавляй каналы, видео и фильмы звёздочкой.</div>'); return; }
  const cat = await api('catalog', { params:{ type:'all', q:'' } });
  const map = {};
  if (cat && cat.ok && Array.isArray(cat.items)) cat.items.forEach(i => { map[i.kind+':'+i.id] = i; });
  const out = [];
  for (const it of items) {
    const kind=it.target_type, id=it.target_id, k=kind+':'+id;
    if (kind==='cinema') {
      let f=null;
      try { if (kpApiKey()) f=await kpFetch('/api/v2.2/films/'+encodeURIComponent(id)); } catch(e) {}
      const title=f?kpTitle(f):('Фильм / сериал #'+id), poster=f?kpPoster(f):'';
      out.push(`<button type="button" class="row" data-fav-cinema="${esc(id)}" style="width:100%;text-align:left;border:0;color:inherit;background:transparent;cursor:pointer">${poster?`<img class="av" src="${esc(poster)}" alt="" style="border-radius:8px">`:'<div class="av letter">🎬</div>'}<div class="body"><div class="title">${esc(title)}</div><div class="sub">${f?esc(String(f.year||''))+' · ★ '+esc(String(f.ratingKinopoisk||'—')):'Избранное кино'} · Мой список</div></div><span>⭐</span></button>`);
    } else {
      const item=map[k] || {kind,id,title:(kind==='channel'?'Канал #':'Видео #')+id,subtitle:'Избранное',avatar:''};
      out.push(rowHtml(item));
    }
  }
  const box=showInPane(out.join('') || '<div class="empty">Пусто</div>');
  bindRows(box);
  box.querySelectorAll('[data-fav-cinema]').forEach(b=>b.onclick=()=>openCinemaFilm(b.dataset.favCinema));
}

async function openCinemaMyList(){
  destroySpaPlayer();
  if (!(await spaCinemaGate(true))) return;
  setDetailTitle('Мой список');
  const d=await api('favorites');
  const ids=(d&&d.ok&&Array.isArray(d.items)?d.items:[]).filter(x=>x.target_type==='cinema');
  if(!ids.length){showInPane('<div class="empty">Здесь будут фильмы и сериалы, которые ты добавишь в Мой список ⭐</div><button class="btn-primary" id="ml-cinema" type="button">Открыть кинотеатр</button>');const b=$('ml-cinema');if(b)b.onclick=()=>openCinema();return;}
  const box=showInPane('<div class="empty">Загружаю твой список…</div>');
  const rows=[];
  for(const it of ids){let f=null;try{f=await kpFetch('/api/v2.2/films/'+encodeURIComponent(it.target_id));}catch(e){}const title=f?kpTitle(f):'Фильм / сериал #'+it.target_id;const poster=f?kpPoster(f):'';rows.push(`<button type="button" class="row" data-kpid="${esc(it.target_id)}" style="width:100%;text-align:left;border:0;background:transparent;color:inherit;cursor:pointer">${poster?`<img class="av" src="${esc(poster)}" style="border-radius:8px" alt="">`:'<div class="av letter">🎬</div>'}<div class="body"><div class="title">${esc(title)}</div><div class="sub">${f?esc(String(f.year||''))+' · ★ '+esc(String(f.ratingKinopoisk||'—')):'В твоём списке'}</div></div><span>⭐</span></button>`);}
  box.innerHTML=rows.join('');box.querySelectorAll('[data-kpid]').forEach(b=>b.onclick=()=>openCinemaFilm(b.dataset.kpid));
}

async function toggleFav(kind, id, btn) {
  const r = await api('fav_toggle', { method:'POST', body:{ type:kind, id } });
  if (r.ok && btn) {
    btn.textContent = r.on ? '⭐' : '☆';
    spaPlay(r.on ? 'ok' : 'click');
  } else spaPlay('err');
}


const SPA_SEARCH_HISTORY_KEY = 'streamspa_search_history_v1';
function spaSearchHistoryGet(){
  try { const a=JSON.parse(localStorage.getItem(SPA_SEARCH_HISTORY_KEY)||'[]'); return Array.isArray(a)?a.filter(x=>typeof x==='string').slice(0,8):[]; } catch(e){ return []; }
}
function spaSearchHistorySave(q){
  q=String(q||'').trim().slice(0,80); if(!q) return;
  try { const a=spaSearchHistoryGet().filter(x=>x.toLowerCase()!==q.toLowerCase()); a.unshift(q); localStorage.setItem(SPA_SEARCH_HISTORY_KEY,JSON.stringify(a.slice(0,8))); } catch(e) {}
}
function spaSearchStart(){
  const start=$('search-start'), results=$('list-search'); if(!start||!results) return;
  results.style.display='none'; start.style.display='block';
  const history=spaSearchHistoryGet();
  start.innerHTML=`<div class="search-hint">Единый поиск по StreamLive: находи людей, каналы и видео в одном месте. Выбери категорию или начни вводить запрос.</div>
    <h3>Быстрый поиск</h3>
    <div class="search-shortcuts">
      <button class="search-shortcut" data-search-type="all"><span class="sico">✨</span><span>Всё сразу</span></button>
      <button class="search-shortcut" data-search-type="users"><span class="sico">👥</span><span>Люди</span></button>
      <button class="search-shortcut" data-search-type="channels"><span class="sico">📺</span><span>Каналы</span></button>
      <button class="search-shortcut" data-search-type="videos"><span class="sico">▶️</span><span>Видео</span></button>
      <button class="search-shortcut" data-search-type="films"><span class="sico">🎬</span><span>Фильмы и сериалы</span></button>
    </div>
    ${history.length?`<div class="search-section-head"><h3>Недавние запросы</h3><button type="button" id="search-history-clear">Очистить</button></div><div class="search-recent">${history.map(x=>`<button type="button" data-search-recent="${esc(x)}">🕘 ${esc(x)}</button>`).join('')}</div>`:''}
    <h3 style="margin-top:20px">Разделы StreamLive</h3>
    <div class="search-shortcuts">
      <button class="search-shortcut" data-search-go="cinema"><span class="sico">🎬</span><span>Фильмы и сериалы</span></button>
      <button class="search-shortcut" data-search-go="music"><span class="sico">🎵</span><span>Музыка</span></button>
    </div>`;
  start.querySelectorAll('[data-search-type]').forEach(b=>b.onclick=()=>{
    state.searchType=b.dataset.searchType;
    document.querySelectorAll('#search-tabs button').forEach(x=>x.classList.toggle('on',x.dataset.t===state.searchType));
    $('search-q').focus();
  });
  start.querySelectorAll('[data-search-recent]').forEach(b=>b.onclick=()=>{
    $('search-q').value=b.dataset.searchRecent; spaSearchHistorySave(b.dataset.searchRecent); loadCatalog(state.searchType,b.dataset.searchRecent);
  });
  const clear=$('search-history-clear'); if(clear) clear.onclick=()=>{try{localStorage.removeItem(SPA_SEARCH_HISTORY_KEY)}catch(e){} spaSearchStart();};
  start.querySelectorAll('[data-search-go]').forEach(b=>b.onclick=()=>{
    $('search-ov').classList.remove('on');
    if(b.dataset.searchGo==='cinema') openCinema();
    else if(b.dataset.searchGo==='music') openMusicHub();
  });
}
function spaSearchShowResults(){ const a=$('search-start'),b=$('list-search'); if(a)a.style.display='none'; if(b)b.style.display='block'; }

async function spaGlobalFilmResults(q){
  if(!q) return [];
  try {
    await refreshServerKpKey();
    if(!kpApiKey()) return [];
    const data=await kpFetch('/api/v2.1/films/search-by-keyword',{keyword:q,page:'1'});
    return (data.films||data.items||[]).filter(f=>{
      const id=f.filmId||f.kinopoiskId;
      return parentalAllowsText(kpTitle(f)+' '+String(f.year||''),id);
    }).slice(0,12);
  } catch(e) { return []; }
}
function spaGlobalFilmRows(items){
  return items.map(f=>{
    const id=f.filmId||f.kinopoiskId, poster=kpPoster(f);
    return `<div class="row" data-global-kpid="${esc(id)}">${poster?`<img class="av" src="${esc(poster)}" alt="" style="border-radius:8px" onerror="this.style.display='none'">`:`<div class="av letter">🎬</div>`}<div class="body"><div class="title">${esc(kpTitle(f))} ${ytAgeBadgeHtml(analyzeContentRatingSafe(f))}</div><div class="sub">${esc(String(f.year||''))} · ★ ${esc(String(f.rating||f.ratingKinopoisk||'—'))} · Кинопоиск</div></div></div>`;
  }).join('');
}

async function loadCatalog(type, q){
  const inSearch = $('search-ov').classList.contains('on');
  const box = inSearch ? $('list-search') : $('list-catalog');
  if (!box) return;
  q=String(q||'').trim();
  if (inSearch && !q) { spaSearchStart(); return; }
  if (inSearch) spaSearchShowResults();
  box.innerHTML = '<div class="empty">Загрузка…</div>';
  try {
    let data = {ok:true,items:[]};
    if ((type||state.filter) !== 'films') data = await api('catalog', { params:{ type: type||state.filter, q: q||'' } });
    if (!data || !data.ok) {
      let msg = (data && data.error) ? data.error : 'Нужна авторизация';
      if (data && data.raw) msg += ' · ' + data.raw;
      box.innerHTML = '<div class="empty">'+esc(msg)+'</div>';
      return;
    }
    if (data.items) {
      try {
        data.items = data.items.filter(it => {
          try { return parentalAllowsText(it); } catch (e) { return true; }
        });
      } catch (e) {}
    }
    if ((!data.items || !data.items.length) && (type||state.filter) === 'music') {
      const m = await api('music');
      if (m && m.ok && m.tracks && m.tracks.length) {
        box.innerHTML = m.tracks.map(tr => rowHtml({
          kind: 'video', id: tr.id, title: tr.title, subtitle: 'Музыка',
          avatar: tr.cover || '', embed_url: tr.url || '', source_url: tr.url || '', platform: 'music'
        })).join('');
        bindRows(box);
        return;
      }
    }
    let filmItems=[];
    if ((type||state.filter)==='all' || (type||state.filter)==='films') {
      filmItems=await spaGlobalFilmResults(q);
    }
    const hasCatalogItems=!!(data.items&&data.items.length);
    if (!hasCatalogItems && !filmItems.length) {
      let extra = (data.debug && data.debug.length) ? (' · '+data.debug.join('; ')) : '';
      if ((type||state.filter)==='films' && !kpApiKey()) extra=' · Добавь ключ Кинопоиска в разделе «Фильмы и сериалы»';
      box.innerHTML = '<div class="empty">Ничего не найдено'+esc(extra)+'</div>';
      return;
    }
    box.innerHTML = (hasCatalogItems?data.items.map(rowHtml).join(''):'') + (filmItems.length?`<div style="padding:12px 14px 4px;font-size:12px;font-weight:800;color:var(--muted)">🎬 ФИЛЬМЫ И СЕРИАЛЫ</div>${spaGlobalFilmRows(filmItems)}`:'');
    bindRows(box);
    box.querySelectorAll('[data-global-kpid]').forEach(r=>r.onclick=()=>openCinemaFilm(r.dataset.globalKpid));
  } catch (e) {
    box.innerHTML = '<div class="empty">Ошибка загрузки каталога: '+esc(String(e && e.message || e))+'</div>';
  }
}

let __storiesCache = [];
async function loadStories(){
  const el = $('stories');
  if (!state.user) { el.innerHTML = ''; return; }
  const data = await api('stories');
  __storiesCache = (data && data.ok && data.stories) ? data.stories : [];
  let html = `<div class="story" id="story-add"><div class="av empty">＋</div><span>Моя</span></div>`;
  __storiesCache.forEach((s, idx) => {
    const u = s.user;
    const first = (s.items&&s.items[0]&&s.items[0].media_url) || u.avatar;
    html += `<div class="story" data-sidx="${idx}" data-u="${esc(u.username)}"><img class="av" src="${esc(first)}" alt=""><span>${esc(u.username)}</span></div>`;
  });
  el.innerHTML = html;
  const add = $('story-add');
  if (add) add.onclick = async () => {
    const url = prompt('Ссылка на фото или видео (https://… или /path). Одна история на 24ч. Без загрузки файла — только ссылка.');
    if (!url) return;
    const caption = prompt('Подпись (можно пусто)') || '';
    const r = await api('story_add', { method:'POST', body:{ media_url:url.trim(), caption } }); try { if (r&&r.ok) spaAchUnlock('story_post','Сторимейкер'); } catch(e) {}
    if (r.ok) loadStories(); else alert(r.error||'Ошибка');
  };
  el.querySelectorAll('.story[data-sidx]').forEach(s => {
    s.onclick = () => openStoryViewer(parseInt(s.dataset.sidx, 10));
  });
}

function mediaKind(url) {
  const u = String(url || '').toLowerCase();
  if (/\.(mp4|webm|mov|m4v|mkv)(\?|$)/.test(u)) return 'video';
  if (/\.(jpe?g|png|gif|webp|bmp|avif)(\?|$)/.test(u)) return 'image';
  if (/youtube\.com|youtu\.be|vimeo\.|rutube\.|tiktok\.|cdn\.|stream/.test(u)) return 'video';
  return 'image'; // по умолчанию пробуем картинку
}
function openStoryViewer(idx) {
  spaPlay('open');
  const pack = __storiesCache[idx];
  if (!pack || !pack.items || !pack.items.length) {
    if (pack && pack.user) openProfile(pack.user.username);
    return;
  }
  const u = pack.user;
  let i = 0;
  const show = () => {
    const it = pack.items[i];
    if (!it) return;
    let url = String(it.media_url || '').trim();
    if (url && url[0] === '/') url = location.origin + url;
    const kind = mediaKind(url);
    let media;
    if (!url) {
      media = '<div class="empty">Нет ссылки на медиа</div>';
    } else if (kind === 'video') {
      media = `<video src="${esc(url)}" controls autoplay playsinline style="width:100%;max-height:65vh;border-radius:12px;background:#000"></video>
        <p style="font-size:11px;color:var(--muted);word-break:break-all;margin-top:6px">${esc(url)}</p>`;
    } else {
      media = (it.media_type==='video' || /\.(mp4|webm|mov)(\?|$)/i.test(url)) ? `<video src="${esc(url)}" controls autoplay playsinline style="max-width:100%;max-height:70vh;border-radius:12px"></video>` : `<img src="${esc(url)}" alt="story" style="width:100%;max-height:65vh;object-fit:contain;border-radius:12px;background:#000"
        onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
        <video src="${esc(url)}" controls playsinline style="display:none;width:100%;max-height:65vh;border-radius:12px;background:#000"></video>
        <p style="font-size:11px;color:var(--muted);word-break:break-all;margin-top:6px">${esc(url)}</p>`;
    }
    showInPane(`
      <div class="prof-head" style="padding-bottom:8px">
        <img class="av" src="${esc(u.avatar)}" style="width:48px;height:48px" alt="">
        <div class="val">@${esc(u.username)}</div>
        <div style="color:var(--muted);font-size:12px">История · ${i+1}/${pack.items.length} · до ${esc(it.expires_at||'')}</div>
      </div>
      <div style="margin:8px 0">${media}</div>
      ${it.caption?`<div class="card">${esc(it.caption)}</div>`:''}
      <div style="display:flex;gap:8px;margin-top:12px">
        <button type="button" class="btn-primary" id="st-prev" style="margin:0;flex:1">←</button>
        <button type="button" class="btn-primary" id="st-next" style="margin:0;flex:1">→</button>
      </div>
      <button type="button" class="btn-primary" id="st-prof" style="margin-top:10px;background:rgba(255,255,255,.08)">Профиль @${esc(u.username)}</button>
    `);
    $('detail-title').textContent = 'История';
    const prev = $('st-prev');
    const next = $('st-next');
    if (prev) prev.onclick = () => { if (i > 0) { i--; show(); } };
    if (next) next.onclick = () => {
      if (i < pack.items.length - 1) { i++; show(); }
    };
    const pr = $('st-prof');
    if (pr) pr.onclick = () => openProfile(u.username);
  };
  show();
}


/* —— WebApp offline features (localStorage, без БД) —— */
const PP_KEY = 'sl_world_passport_v1';
const FAM_KEY = 'sl_family_v1';
const CONT_KEY = 'sl_continue_watch_v1';

function passportLoad() {
  try { return JSON.parse(localStorage.getItem(PP_KEY) || 'null'); } catch(e) { return null; }
}
function passportSave(doc) {
  try { localStorage.setItem(PP_KEY, JSON.stringify(doc)); } catch(e) { alert('Не сохранилось'); }
}
function openPassport() {
  destroySpaPlayer();
  setDetailTitle('Паспорт Мира');
  const doc = passportLoad();
  if (!doc) {
    showInPane(`
      <div class="card"><div class="val">🛂 Паспорт Мира</div>
        <p style="font-size:13px;color:var(--muted);margin:8px 0 0;line-height:1.45">Виртуальный паспорт StreamLive · только в этом браузере · без сервера и БД</p></div>
      <div class="empty">Паспорт ещё не создан</div>
      <button type="button" class="btn-primary" id="pp-create">Создать паспорт</button>
    `);
    $('pp-create').onclick = () => openPassportEdit();
    return;
  }
  showInPane(`
    <div style="max-width:360px;margin:0 auto 14px;background:linear-gradient(145deg,#3b0a1a,#5c0f2a 40%,#2a0612);border-radius:14px;padding:18px 16px;color:#f3e7c5;box-shadow:0 18px 40px rgba(0,0,0,.45),inset 0 0 0 2px rgba(212,175,55,.35);font-family:Georgia,serif;position:relative">
      <div style="font-size:11px;letter-spacing:.12em;opacity:.8">STREAMLIVE · WORLD PASSPORT</div>
      <div style="font-size:20px;font-weight:700;margin-top:10px">${esc(doc.surname||'')} ${esc(doc.name||'')}</div>
      <div style="font-size:12px;margin-top:10px;opacity:.9">№ ${esc(doc.number||'—')}</div>
      <div style="font-size:12px;margin-top:6px">${esc(doc.country||'')} · ${esc(doc.city||'')}</div>
      <div style="font-size:12px;margin-top:6px">Дата рожд.: ${esc(doc.birth||'—')}</div>
      <div style="font-size:12px;margin-top:6px">Выдан: ${esc(doc.issued||'—')}</div>
      ${doc.note?`<div style="font-size:12px;margin-top:10px;opacity:.85">${esc(doc.note)}</div>`:''}
      <div style="position:absolute;right:12px;top:12px;width:64px;height:64px;border:2px solid rgba(212,175,55,.5);border-radius:50%;display:flex;align-items:center;justify-content:center;transform:rotate(-18deg);font-size:10px;text-align:center;opacity:.9">WORLD<br>STAMP</div>
    </div>
    <button type="button" class="btn-primary" id="pp-edit">Изменить</button>
    <button type="button" class="btn-primary" id="pp-qr" style="margin-top:8px;background:rgba(255,255,255,.08)">QR паспорта</button>
    <button type="button" class="btn-primary" id="pp-del" style="margin-top:8px;background:rgba(127,29,29,.5)">Удалить с устройства</button>
  `);
  $('pp-edit').onclick = () => openPassportEdit();
  $('pp-qr').onclick = () => openPassportQr();
  $('pp-del').onclick = () => {
    if (!confirm('Удалить паспорт с этого браузера?')) return;
    localStorage.removeItem(PP_KEY);
    openPassport();
  };
}
function openPassportEdit() {
  const doc = passportLoad() || { name:'', surname:'', birth:'', country:'', city:'', number:'', issued:'', note:'', id: 'PP'+Date.now().toString(36).toUpperCase() };
  if (!doc.number) doc.number = 'SL-'+Math.random().toString(36).slice(2,8).toUpperCase();
  if (!doc.issued) doc.issued = new Date().toISOString().slice(0,10);
  showInPane(`
    <div class="card"><div class="val">Данные паспорта</div></div>
    <input class="field" id="pp-name" placeholder="Имя" value="${esc(doc.name)}">
    <input class="field" id="pp-sur" placeholder="Фамилия" value="${esc(doc.surname)}">
    <input class="field" id="pp-birth" placeholder="Дата рождения ГГГГ-ММ-ДД" value="${esc(doc.birth)}">
    <input class="field" id="pp-country" placeholder="Страна" value="${esc(doc.country)}">
    <input class="field" id="pp-city" placeholder="Город" value="${esc(doc.city)}">
    <input class="field" id="pp-num" placeholder="Номер" value="${esc(doc.number)}">
    <input class="field" id="pp-note" placeholder="Заметка" value="${esc(doc.note||'')}">
    <button type="button" class="btn-primary" id="pp-save">Сохранить</button>
  `);
  $('pp-save').onclick = () => {
    const next = {
      id: doc.id || ('PP'+Date.now().toString(36).toUpperCase()),
      name: $('pp-name').value.trim(),
      surname: $('pp-sur').value.trim(),
      birth: $('pp-birth').value.trim(),
      country: $('pp-country').value.trim(),
      city: $('pp-city').value.trim(),
      number: $('pp-num').value.trim() || doc.number,
      issued: doc.issued || new Date().toISOString().slice(0,10),
      note: $('pp-note').value.trim()
    };
    passportSave(next);
    spaPlay('ok');
    openPassport();
  };
}
function openPassportQr() {
  const doc = passportLoad();
  if (!doc) { openPassport(); return; }
  const payload = JSON.stringify({ v:1, type:'streamlive-world-passport', id:doc.id, name:doc.name, surname:doc.surname, birth:doc.birth, country:doc.country, city:doc.city, number:doc.number, issued:doc.issued, note:doc.note||'' });
  const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' + encodeURIComponent(payload);
  showInPane(`
    <div class="card"><div class="val">QR паспорта</div>
      <p style="font-size:13px;color:var(--muted);margin:8px 0 0">Только для переноса между браузерами. Не персональные документы.</p></div>
    <div style="display:flex;justify-content:center;padding:16px;background:#fff;border-radius:16px;margin:12px 0">
      <img src="${esc(qrUrl)}" alt="QR" width="220" height="220">
    </div>
    <button type="button" class="btn-primary" id="pp-back">Назад</button>
  `);
  $('pp-back').onclick = () => openPassport();
}

function familyLoad() {
  try {
    const d = JSON.parse(localStorage.getItem(FAM_KEY) || 'null');
    if (d && d.profiles) return d;
  } catch(e) {}
  const base = {
    pin: '',
    activeId: 'main',
    profiles: [{ id:'main', name:'Основной', avatar:'👤', kids:false, color:'#7c4dff' }]
  };
  try { localStorage.setItem(FAM_KEY, JSON.stringify(base)); } catch(e) {}
  return base;
}
function familySave(d) {
  try { localStorage.setItem(FAM_KEY, JSON.stringify(d)); } catch(e) {}
}
function openFamily() {
  destroySpaPlayer();
  $('detail-title').textContent = 'Семья';
  const d = familyLoad();
  const list = (d.profiles||[]).map(pr => `
    <div class="row" data-fid="${esc(pr.id)}">
      <div class="av letter" style="background:${esc(pr.color||'#7c4dff')}">${esc(pr.avatar||'👤')}</div>
      <div class="body"><div class="title">${esc(pr.name)} ${pr.kids?'<span class="badge">kids</span>':''}${pr.id===d.activeId?'<span class="badge">активен</span>':''}</div>
      <div class="sub">${esc(pr.id)}</div></div>
    </div>`).join('');
  showInPane(`
    <div class="card"><div class="val">Семейные профили</div>
      <p style="font-size:13px;color:var(--muted);margin:8px 0 0">Только в браузере (как в WebApp). Без БД.</p></div>
    ${list}
    <div class="card">
      <input class="field" id="fam-name" placeholder="Имя нового профиля">
      <label style="font-size:13px;display:flex;gap:8px;align-items:center;margin:8px 0"><input type="checkbox" id="fam-kids"> Детский</label>
      <button type="button" class="btn-primary" id="fam-add" style="margin:0">Добавить</button>
    </div>
  `);
  document.querySelectorAll('#pane-body .row[data-fid], #detail-body .row[data-fid]').forEach(r => {
    r.onclick = () => {
      d.activeId = r.dataset.fid;
      familySave(d);
      spaPlay('ok');
      openFamily();
    };
  });
  $('fam-add').onclick = () => {
    const name = ($('fam-name').value||'').trim() || 'Профиль';
    const kids = !!($('fam-kids')&&$('fam-kids').checked);
    d.profiles.push({ id:'p'+Date.now(), name, avatar: kids?'🧒':'👤', kids, color: kids?'#f59e0b':'#7c4dff' });
    familySave(d);
    openFamily();
  };
}

function continueLoad() {
  try { return JSON.parse(localStorage.getItem(CONT_KEY)||'[]'); } catch(e) { return []; }
}
function continueSave(list) {
  try { localStorage.setItem(CONT_KEY, JSON.stringify(list.slice(0,40))); } catch(e) {}
}
function continuePush(item) {
  const oldList=continueLoad();
  const old=oldList.find(x=>x.kind===item.kind && String(x.id)===String(item.id) && String(x.username||'')===String(item.username||''))||{};
  const merged={...old,...item,position:Number(old.position||0),duration:Number(old.duration||0),progress:Number(old.progress||0),at:Date.now()};
  let list = oldList.filter(x => !(x.kind===item.kind && String(x.id)===String(item.id) && String(x.username||'')===String(item.username||'')));
  list.unshift(merged); continueSave(list);
  if(state.user && item && item.id && ['video','channel','cinema'].includes(item.kind)) api('media_continue_save',{method:'POST',body:{kind:item.kind,id:item.id,title:item.title||'',avatar:item.avatar||'',embed_url:item.embed_url||'',source_url:item.source_url||'',platform:item.platform||'',slug:item.slug||'',subtitle:item.subtitle||'',position:merged.position||0,duration:merged.duration||0}}).catch(()=>{});
  return merged;
}
function continueFind(kind,id){return continueLoad().find(x=>x.kind===kind&&String(x.id)===String(id))||null;}
let __slResumeSaveAt=0;
function continueUpdateProgress(item,position,duration,force){
  position=Math.max(0,Number(position)||0);duration=Math.max(0,Number(duration)||0);
  if(!item||!item.id||!duration||!isFinite(position))return;
  const now=Date.now();if(!force&&now-__slResumeSaveAt<7000)return;__slResumeSaveAt=now;
  const progress=Math.max(0,Math.min(100,Math.round(position/duration*1000)/10));
  const list=continueLoad();const idx=list.findIndex(x=>x.kind===item.kind&&String(x.id)===String(item.id));
  const saved={...(idx>=0?list[idx]:item),position,duration,progress,at:now};
  if(idx>=0)list.splice(idx,1);list.unshift(saved);continueSave(list);
  if(state.user)api('media_continue_save',{method:'POST',body:{kind:item.kind,id:item.id,title:item.title||'',avatar:item.avatar||'',embed_url:item.embed_url||'',source_url:item.source_url||'',platform:item.platform||'',slug:item.slug||'',subtitle:item.subtitle||'',position,duration}}).catch(()=>{});
}
function continueAttachVideo(video,item){
  if(!video||video.dataset.slResumeBound==='1')return;video.dataset.slResumeBound='1';
  let restored=false;
  const restore=()=>{if(restored)return;const saved=continueFind(item.kind,item.id);const pos=Number(saved&&saved.position||0),dur=Number(video.duration||0);if(pos>5&&dur>0&&pos<dur-12){try{video.currentTime=pos;}catch(e){}}restored=true;};
  video.addEventListener('loadedmetadata',restore,{once:true});
  video.addEventListener('timeupdate',()=>continueUpdateProgress(item,video.currentTime,video.duration,false));
  video.addEventListener('pause',()=>continueUpdateProgress(item,video.currentTime,video.duration,true));
  video.addEventListener('ended',()=>{continueUpdateProgress(item,video.duration,video.duration,true);});
  if(video.readyState>=1)restore();
}
function continueObservePlayer(item){
  try{if(window.__slResumeObserver)window.__slResumeObserver.disconnect();}catch(e){}
  const root=document.getElementById('playerjs-container');if(!root)return;
  const bind=()=>root.querySelectorAll('video').forEach(v=>continueAttachVideo(v,item));bind();
  if(window.MutationObserver){window.__slResumeObserver=new MutationObserver(bind);window.__slResumeObserver.observe(root,{childList:true,subtree:true});}
  const player=window.__spaPlayerInstance;
  if(player&&typeof player.on==='function'){
    let restored=false;
    const saved=continueFind(item.kind,item.id);const pos=Number(saved&&saved.position||0);
    const restorePlayer=()=>{if(restored||pos<=5)return;restored=true;try{if(typeof player.seek==='function')player.seek(pos);}catch(e){}};
    try{player.on('ready',restorePlayer);player.on('play',restorePlayer);player.on('time',value=>{let current=0,duration=0;if(typeof value==='number')current=value;else if(value&&typeof value==='object'){current=Number(value.position||value.currentTime||value.time||0);duration=Number(value.duration||0);}try{if(!duration&&typeof player.getDuration==='function')duration=Number(player.getDuration()||0);}catch(e){}if(!current&&typeof player.getPosition==='function'){try{current=Number(player.getPosition()||0);}catch(e){}}continueUpdateProgress(item,current,duration,false);});}catch(e){}
  }
}
async function openContinueWatch() {
  destroySpaPlayer();
  setDetailTitle('Продолжить');
  let list = continueLoad();
  let cinemaAllowed = await spaCinemaGate(false);
  try { const remote=await api('media_continue_list'); if(remote&&remote.ok&&Array.isArray(remote.items)){ const seen=new Set(); list=[...remote.items,...list].filter(x=>{const k=x.kind+':'+x.id;if(seen.has(k))return false;seen.add(k);return true;}).slice(0,40);continueSave(list); if (remote.cinema_locked) cinemaAllowed = false; } } catch(e) {}
  if (!cinemaAllowed) list = list.filter(x => x.kind !== 'cinema');
  if (!list.length) {
    showInPane('<div class="empty">Пока пусто. Открывай видео, каналы или профили — появятся здесь.</div>');
    return;
  }
  const label = { video:slT('Видео','Video','Відео'), channel:slT('Канал','Channel','Канал'), user:slT('Профиль','Profile','Профіль'), cinema:slT('Фильм / сериал','Movie / series','Фільм / серіал') };
  const html = '<div class="home-strip" style="flex-wrap:wrap">'+list.map(it => spaHomeTile(it,(label[it.kind]||it.kind||'')+' · '+new Date(it.at||0).toLocaleDateString(spaLang()==='en'?'en-US':spaLang()==='uk'?'uk-UA':'ru-RU'))).join('')+'</div>';
  const box = showInPane(`<div class="card"><div class="val">▶ ${slT('Продолжить просмотр','Continue watching','Продовжити перегляд')}</div>
    <p style="font-size:13px;color:var(--muted);margin:8px 0 0">${slT('Видео, каналы, фильмы и сериалы · синхронизация с аккаунтом StreamLive','Videos, channels, movies and series · synced with your StreamLive account','Відео, канали, фільми та серіали · синхронізація з акаунтом StreamLive')}</p></div>` + html +
    '<button type="button" class="btn-primary" id="cont-clear" style="margin-top:12px;background:rgba(255,255,255,.08)">'+slT('Очистить','Clear','Очистити')+'</button>');
  spaHomeBindTiles(box);
  $('cont-clear').onclick = async () => { localStorage.removeItem(CONT_KEY); try{await api('media_continue_clear',{method:'POST',body:{}});}catch(e){} openContinueWatch(); };
}

const SPA_HOME_NEW_CACHE_KEY = 'sl_home_new_cache_v1';
const SPA_HOME_APPS = [
  ['cinema','🎬','films'],['youtube','▶️','youtube'],['services','🔗','services'],['myservices','🧩','myservices'],['library','🗂️','library'],['planner','🗓️','planner'],
  ['personalize','✨','personalize'],['music','🎵','music'],['qr','📱','qr'],['screenshot','📸','screenshots'],['calculator','🧮','calculator'],
  ['notes','📝','notespro'],['diagnostics','🩺','diagnostics'],['serverapps','🏰','serverapps'],['files','📁','files'],
  ['achievements','🏆','achievements'],['quests','⚡','quests'],['tip','💡','tip'],
  ['history','🕑','history'],['favs','🔖','favs'],['messenger','💬','messenger'],
  ['family','👨‍👩‍👧‍👦','family'],['passport','🛂','passport'],['geo','🌍','geo'],
  ['mood','😎','mood'],['settings','⚙️','settings']
];
const SPA_APP_LABELS = {
  ru:{films:'Фильмы и сериалы',youtube:'YouTube',music:'Музыка',qr:'QR-студия',calculator:'Конвертер и калькулятор',notespro:'Заметки Pro',services:'StreamLive Services',myservices:'Мои сервисы',diagnostics:'Диагностика StreamLive',serverapps:'Сервисы сервера',library:'Единая медиатека',planner:'Планировщик',personalize:'Hyper персонализация',files:'Файлы и загрузки',achievements:'Достижения',quests:'Квесты дня',tip:'Совет дня',history:'История 24ч',favs:'Избранное',messenger:'Сообщения',family:'Семья',passport:'Паспорт мира',geo:'Гео / страна',mood:'Настроение',settings:'Настройки',screenshots:'Скриншоты'},
  en:{films:'Movies & series',youtube:'YouTube',music:'Music',qr:'QR Studio',calculator:'Converter & calculator',notespro:'Notes Pro',services:'StreamLive Services',myservices:'My services',diagnostics:'StreamLive diagnostics',serverapps:'Server services',library:'Unified media library',planner:'Planner',personalize:'Hyper personalization',files:'Files & downloads',achievements:'Achievements',quests:'Daily quests',tip:'Daily tip',history:'24h history',favs:'Favorites',messenger:'Messages',family:'Family',passport:'World passport',geo:'Geo / country',mood:'Mood',settings:'Settings',screenshots:'Screenshots'},
  uk:{films:'Фільми та серіали',youtube:'YouTube',music:'Музика',qr:'QR-студія',calculator:'Конвертер і калькулятор',notespro:'Нотатки Pro',services:'StreamLive Services',serverapps:'Сервіси сервера',library:'Єдина медіатека',planner:'Планувальник',personalize:'Hyper персоналізація',files:'Файли та завантаження',achievements:'Досягнення',quests:'Квести дня',tip:'Порада дня',history:'Історія 24 год',favs:'Обране',messenger:'Повідомлення',family:'Сім’я',passport:'Паспорт світу',geo:'Гео / країна',mood:'Настрій',settings:'Налаштування',screenshots:'Знімки екрана'}
};
function spaAppTitle(key){const l=SPA_APP_LABELS[spaLang()]||SPA_APP_LABELS.ru;return l[key]||SPA_APP_LABELS.ru[key]||key;}
function spaRunHomeAction(action){
  closeDrawer();
  if(action==='qr'){ openQrStudio(); return; }
  if(action==='screenshot'){ openScreenshotStudio(); return; }
  if(action==='calculator'){ openConverterCalculator(); return; }
  if(action==='services'){ openMyServices(); return; }
  if(action==='myservices'){ openMyCreatedServices(); return; }
  if(action==='diagnostics'){ openDiagnosticsApp(); return; }
  if(action==='serverapps'){ if(state.myServer){openMyServer();setTimeout(()=>{const el=$('srv-platform-hub');if(el)el.scrollIntoView({behavior:'smooth',block:'start'});},450);}else{openServerHub();setTimeout(()=>{const el=$('srv-name');if(el)el.focus();},120);} return; }
  if(action==='library'){ openMediaLibrary(); return; }
  if(action==='planner'){ openPlanner(); return; }
  if(action==='personalize'){ openHyperPersonalization(); return; }
  if(action==='files'){ openLocalFilesHub(); return; }
  if(action==='catalog'){ const target=$('filter-seg'); if(target) target.scrollIntoView({behavior:'smooth',block:'start'}); return; }
  if(action==='apps'){ openSpaApps(); return; }
  if(action==='cinema'){ openCinema(); return; }
  if(action==='youtube'){ openYouTubeApp(); return; }
  if(action==='music'){ openMusicHub(); return; }
  if(action==='achievements'){ openSpaAchievements(); return; }
  if(action==='quests'){ openSpaQuests(); return; }
  if(action==='notes'){ openSpaNotes(); return; }
  if(action==='tip'){ openSpaTip(); return; }
  if(action==='history'){ openWatchHistory(); return; }
  if(action==='favs'){ openFavorites(); return; }
  if(action==='messenger'){ openMessenger(); return; }
  if(action==='family'){ openParental(); return; }
  if(action==='passport'){ openPassport(); return; }
  if(action==='geo'){ openGeoInfo(); return; }
  if(action==='mood'){ openSpaMood(); return; }
  if(action==='settings'){ openSpaSettings(); return; }
  if(action==='continue'){ openContinueWatch(); return; }
}
function slServiceHost(url){try{return new URL(url).hostname.replace(/^www\./,'')}catch(e){return ''}}
function slServiceIcon(url){const h=slServiceHost(url);return h?'https://www.google.com/s2/favicons?domain='+encodeURIComponent(h)+'&sz=128':''}
function slServiceFallback(name,url){const s=(String(name||'')+' '+slServiceHost(url)).toLowerCase();if(/music|sound|audio|музык/.test(s))return '♫';if(/video|youtube|rutube| кино|movie|tv/.test(s))return '▶';if(/mail|почт/.test(s))return '✉';if(/news|новост/.test(s))return '▤';if(/game|игр/.test(s))return '♟';return '🌐'}
function slOpenService(service){
  const url=String(service&&service.url||'');let parsed;try{parsed=new URL(url)}catch(e){return}
  if(!/^https?:$/.test(parsed.protocol))return;
  document.getElementById('sl-service-modal')?.remove();
  const modal=document.createElement('div');modal.id='sl-service-modal';modal.className='sl-service-modal';
  const title=String(service.name||parsed.hostname);const icon=slServiceIcon(url);
  modal.innerHTML=`<div class="sl-service-window" role="dialog" aria-modal="true" aria-label="${esc(title)}"><header class="sl-service-window-head"><div class="sl-service-window-brand"><img src="${esc(icon)}" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='grid'"><span style="display:none">${slServiceFallback(title,url)}</span><div><b>${esc(title)}</b><small>${esc(parsed.hostname)}</small></div></div><button type="button" class="sl-service-close" aria-label="Закрыть">✕</button></header><div class="sl-service-frame-wrap"><div class="sl-service-frame-loading">Открываем сервис…</div><iframe title="${esc(title)}" src="${esc(url)}" loading="eager" referrerpolicy="strict-origin-when-cross-origin" allow="fullscreen; autoplay; clipboard-read; clipboard-write; picture-in-picture" sandbox="allow-forms allow-scripts allow-same-origin allow-popups allow-downloads allow-presentation"></iframe></div><footer><span>Если сайт запрещает встраивание, он может не открыться внутри окна.</span><a href="${esc(url)}" target="_blank" rel="noopener noreferrer">Открыть отдельно ↗</a></footer></div>`;
  document.body.appendChild(modal);const close=()=>{modal.remove();document.removeEventListener('keydown',onKey)};const onKey=e=>{if(e.key==='Escape')close()};modal.querySelector('.sl-service-close').onclick=close;modal.addEventListener('click',e=>{if(e.target===modal)close()});document.addEventListener('keydown',onKey);const frame=modal.querySelector('iframe');frame.addEventListener('load',()=>{const l=modal.querySelector('.sl-service-frame-loading');if(l)l.remove()},{once:true});
  try{const a=JSON.parse(localStorage.getItem('sl_services_recent_v1')||'[]').filter(x=>x.url!==url);a.unshift({name:title,url,at:Date.now()});localStorage.setItem('sl_services_recent_v1',JSON.stringify(a.slice(0,12)))}catch(e){}
}
async function slLoadServiceCards(target,compact){
  if(!target)return;target.innerHTML='<div class="empty">Загружаю твои сервисы…</div>';
  const d=await api('services_list');if(!d||!d.ok){target.innerHTML='<div class="empty">Войди в аккаунт, чтобы увидеть личные сервисы.</div>';return}
  const items=Array.isArray(d.items)?d.items:[];
  target.innerHTML=items.length?items.map(x=>{const icon=slServiceIcon(x.url),fallback=slServiceFallback(x.name,x.url);return `<button type="button" class="sl-service-app" data-open-service="${Number(x.id)}"><span class="sl-service-app-icon"><img src="${esc(icon)}" alt="" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='grid'"><i style="display:none">${fallback}</i></span><b>${esc(x.name)}</b><small>${esc(slServiceHost(x.url))}</small></button>`}).join(''):'<div class="empty">Пока нет сервисов. Добавь первый в разделе «Мои сервисы».</div>';
  target.querySelectorAll('[data-open-service]').forEach(b=>b.onclick=()=>{const x=items.find(v=>Number(v.id)===Number(b.dataset.openService));if(x)slOpenService(x)});
}
function openSpaApps(){
  destroySpaPlayer();if(window.__slAppCenterBusy)return;window.__slAppCenterBusy=true;setDetailTitle('Центр приложений');try{spaQuestDone('open_apps');api('xp_award',{method:'POST',body:{reason:'quest:open_apps'}}).catch(function(){});}catch(e){}
  const html=`<div class="sl-app-center-hero"><div class="sl-app-center-orb">✦</div><div><div class="val">Центр приложений</div><p>Твои инструменты StreamLive и любимые сайты — в одном месте.</p></div></div><input type="search" id="sl-app-search" class="field" placeholder="Поиск приложений…" style="margin:10px 0;width:100%" autocomplete="off"><section class="sl-app-center-section"><div class="sl-app-center-head"><b>Мои приложения</b><button type="button" id="sl-manage-services">Управлять сервисами →</button></div><div class="sl-service-app-grid" id="sl-center-services"><div class="empty">Загрузка…</div></div></section><section class="sl-app-center-section"><div class="sl-app-center-head"><b>Приложения StreamLive</b><span>встроенные</span></div><div class="apps-grid">${SPA_HOME_APPS.map(a=>`<button type="button" class="app-tile" data-app-action="${a[0]}" data-app-q="${esc((spaAppTitle(a[2])+" "+String(a[0])).toLowerCase())}"><span class="app-ico">${a[1]}</span><b>${spaAppTitle(a[2])}</b></button>`).join('')}</div></section>`;
  const root=showInPane(html);root.querySelectorAll('[data-app-action]').forEach(b=>b.onclick=()=>spaRunHomeAction(b.dataset.appAction));root.querySelector('#sl-manage-services').onclick=()=>openMyServices();
  try{
    var qEl=document.getElementById('sl-app-search');
    function applyAppSearch(){var q=(qEl&&qEl.value||'').trim().toLowerCase();document.querySelectorAll('.app-tile,.sl-service-app,.sl-server-app-card').forEach(function(el){var hay=(el.getAttribute('data-app-q')||el.textContent||'').toLowerCase();el.style.display=(!q||hay.indexOf(q)>=0)?'':'none';});}
    if(qEl&&!qEl.__bound){qEl.addEventListener('input',applyAppSearch);qEl.__bound=1;}window.__slAppCenterBusy=false;
    (async function(){var box=document.getElementById('sl-center-server-apps');if(!box){var host=document.querySelector('.sl-app-center-section:last-of-type')||document.querySelector('#detail-body');if(host&&!document.getElementById('sl-center-server-apps')){var sec=document.createElement('section');sec.className='sl-app-center-section';sec.innerHTML='<div class="sl-app-center-head"><b>Сервисы серверов</b><span>для участников</span></div><div id="sl-center-server-apps"><div class="empty">Загрузка…</div></div>';host.appendChild(sec);box=document.getElementById('sl-center-server-apps');}else return;}
      try{var d=await api('server_apps_catalog');if(!d||!d.ok){box.innerHTML='<div class="empty">Нет доступа или каталог пуст</div>';return;}var apps=Array.isArray(d.apps)?d.apps:(Array.isArray(d.items)?d.items:[]);
      if(!apps.length){box.innerHTML='<div class="empty">Пока нет серверных приложений</div>';return;}
      box.innerHTML=apps.map(function(a){var sponsor=(a.sponsor||a.is_sponsor||a.sponsored)?'<span class="badge" style="position:absolute;top:8px;left:8px;background:#f59e0b;color:#111;font-size:10px;font-weight:800">СПОНСОР</span>':'';var title=a.name||a.title||'Приложение';var desc=a.description||'';return '<div class="card sl-server-app-card" data-app-q="'+esc((title+' '+desc).toLowerCase())+'" style="position:relative;margin:8px 0">'+sponsor+'<div class="val">'+esc(title)+'</div><div class="sub">'+esc(desc)+'</div><button type="button" class="btn-primary srv-center-open" data-id="'+esc(String(a.id||''))+'" style="margin-top:8px;width:auto">Открыть</button></div>';}).join('');
      box.querySelectorAll('.srv-center-open').forEach(function(b){b.onclick=function(){var a=apps.find(function(x){return String(x.id)===String(b.dataset.id);});if(a&&typeof openServerApp==='function')openServerApp(a);};});
      }catch(e){box.innerHTML='<div class="empty">Ошибка загрузки</div>';}
    })();
  }catch(e){}
slLoadServiceCards(root.querySelector('#sl-center-services'),true);
}
// ===== Local mini-apps: Weather, QR, Calculator, My Services, Files =====
function slT(ru,en,uk){var l=spaLang();if(l==='ru')return ru;if(l==='uk')return uk||en||ru;if(l==='en')return en||ru;return spaLocaleText(ru,en||ru)||en||ru;}
function slFmtBytes(n){n=Math.max(0,Number(n)||0);var u=['Б','КБ','МБ','ГБ','ТБ'],i=0;while(n>=1024&&i<u.length-1){n/=1024;i++;}return (i?n.toFixed(1):Math.round(n))+' '+u[i];}

function slVaultOpen(){
  return new Promise(function(resolve, reject){
    var done=false;
    var t=setTimeout(function(){ if(done) return; done=true; reject(new Error('IndexedDB timeout')); }, 8000);
    try {
      var r = indexedDB.open('sl_local_vault_v1', 1);
      r.onupgradeneeded = function(){ if(!r.result.objectStoreNames.contains('files')) r.result.createObjectStore('files'); };
      r.onsuccess = function(){ if(done) return; done=true; clearTimeout(t); resolve(r.result); };
      r.onerror = function(){ if(done) return; done=true; clearTimeout(t); reject(r.error || new Error('IndexedDB unavailable')); };
    } catch(e){ if(!done){ done=true; clearTimeout(t); reject(e); } }
  });
}
async function slVaultPut(id, blob){ var db=await slVaultOpen(); return new Promise(function(resolve,reject){ var tx=db.transaction('files','readwrite'); tx.objectStore('files').put(blob, id); tx.oncomplete=function(){resolve(true);}; tx.onerror=function(){reject(tx.error||new Error('put failed'));}; }); }
async function slVaultGet(id){ var db=await slVaultOpen(); return new Promise(function(resolve,reject){ var tx=db.transaction('files','readonly'); var req=tx.objectStore('files').get(id); req.onsuccess=function(){resolve(req.result||null);}; req.onerror=function(){reject(req.error||new Error('get failed'));}; }); }
async function slVaultDelete(id){ var db=await slVaultOpen(); return new Promise(function(resolve,reject){ var tx=db.transaction('files','readwrite'); tx.objectStore('files').delete(id); tx.oncomplete=function(){resolve(true);}; tx.onerror=function(){reject(tx.error||new Error('delete failed'));}; }); }
function slVaultMetaRaw(){ try{ return JSON.parse(localStorage.getItem('sl_vault_meta_v1')||'[]'); }catch(e){ return []; } }
function slVaultMetaSave(list){ try{ localStorage.setItem('sl_vault_meta_v1', JSON.stringify(list||[])); }catch(e){} }
function slVaultFolders(){ try{ return JSON.parse(localStorage.getItem('sl_vault_folders_v1')||'[]'); }catch(e){ return []; } }
function slVaultFolderSave(list){ try{ localStorage.setItem('sl_vault_folders_v1', JSON.stringify(list||[])); }catch(e){} }
window.__slVaultCurrentFolder = window.__slVaultCurrentFolder || '';
function slVaultMeta(){
  var all = slVaultMetaRaw().filter(function(f){ return !f.deletedAt; });
  var folder = window.__slVaultCurrentFolder || '';
  return all.filter(function(f){ return String(f.folderPath||'') === folder; });
}
async function slVaultPurgeExpired(){
  try{
    var all=slVaultMetaRaw(), cut=Date.now()-30*86400000, keep=[], n=0;
    for(var i=0;i<all.length;i++){
      var f=all[i];
      if(f.deletedAt && Number(f.deletedAt)<cut && n<2){ try{ await slVaultDelete(f.id); n++; }catch(e){} }
      else keep.push(f);
    }
    if(n) slVaultMetaSave(keep);
  }catch(e){}
}
function slFmtBytes(n){ n=Number(n)||0; if(n<1024) return n+' B'; if(n<1048576) return (n/1024).toFixed(1)+' KB'; if(n<1073741824) return (n/1048576).toFixed(1)+' MB'; return (n/1073741824).toFixed(2)+' GB'; }

async function openLocalFilesHub(){
  /* soft lock 6s max */
  if(window.__slVaultBusy && (Date.now()-(window.__slVaultBusyAt||0))<6000) return;
  window.__slVaultBusy = true;
  window.__slVaultBusyAt = Date.now();
  try {
    try { localStorage.setItem('spa_files_opened_v1','1'); } catch(e){}
    try { if(typeof spaAchUnlock==='function') spaAchUnlock('files_first','Хранитель файлов'); } catch(e){}
    try { if(typeof destroySpaPlayer==='function') destroySpaPlayer(); } catch(e){}
    try { setDetailTitle(typeof spaAppTitle==='function' ? spaAppTitle('files') : 'Файлы и загрузки'); } catch(e){}

    showInPane('<div class="empty">Загрузка хранилища…</div>');

    /* quota — optional, never block UI forever */
    var info = { ok:true, quota_bytes: 10*1024*1024*1024, actions:null };
    try {
      info = await Promise.race([
        api('storage_info').catch(function(){ return null; }),
        new Promise(function(r){ setTimeout(function(){ r(null); }, 3500); })
      ]) || info;
      if(!info || !info.ok) info = { ok:true, quota_bytes:10*1024*1024*1024, actions:null, offline:1 };
    } catch(e){ info = { ok:true, quota_bytes:10*1024*1024*1024, actions:null, offline:1 }; }

    var cap = Number(info.quota_bytes) || 10*1024*1024*1024;
    var metaAll = slVaultMetaRaw().filter(function(f){ return !f.deletedAt; });
    var used = metaAll.reduce(function(n,x){ return n+(Number(x.size)||0); }, 0);
    var pct = Math.min(100, Math.round(used/cap*100));
    var srvText = info.in_server ? (' · сервер '+(info.server_name||'')+' · бафы +'+(info.bonus_gb||0)+' ГБ') : '';
    if(info.offline) srvText += ' · offline-режим';

    var html = ''
      + '<div class="sl-tool-card">'
      +   '<div style="display:flex;align-items:center;gap:12px">'
      +     '<div style="width:48px;height:48px;border-radius:15px;display:grid;place-items:center;font-size:25px;background:linear-gradient(145deg,rgba(130,105,255,.38),rgba(50,210,190,.15));border:1px solid rgba(255,255,255,.16)">☁️</div>'
      +     '<div><div class="val">Файлы и загрузки</div><div class="sub">Твоя библиотека · документы · книги · медиа</div></div>'
      +   '</div>'
      +   '<div style="font-size:12px;color:var(--muted);margin:10px 0">Файлы хранятся на этом устройстве в браузере. На сервер не отправляются.</div>'
      +   '<div id="vault-action-quota" class="sub">Лимит действий: '+(info.actions && !info.actions.subscription ? ((info.actions.remaining||0)+' / '+(info.actions.quota||0)) : 'без ограничений')+srvText+'</div>'
      +   '<div style="display:flex;justify-content:space-between;font-size:12px;margin:10px 0 6px"><b>'+slFmtBytes(used)+' использовано</b><span>'+slFmtBytes(cap)+'</span></div>'
      +   '<div class="sl-storage-bar"><i style="width:'+pct+'%"></i></div>'
      + '</div>'
      + '<div class="sl-tool-card" id="vault-pro-toolbar" style="margin:10px 0">'
      +   '<div style="display:flex;gap:7px;flex-wrap:wrap;align-items:center">'
      +     '<select id="vault-folder-select" class="field" style="flex:1;min-width:140px"></select>'
      +     '<button type="button" class="btn-primary" id="vault-folder-new" style="width:auto;margin:0;padding:8px 12px">＋ Папка</button>'
      +     '<button type="button" class="btn-primary" id="vault-trash-open" style="width:auto;margin:0;padding:8px 12px">🗑 Корзина</button>'
      +   '</div>'
      +   '<div class="sub" id="vault-folder-path" style="margin-top:7px"></div>'
      + '</div>'
      + '<div class="sl-tool-card">'
      +   '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">'
      +     '<input type="search" id="vault-search" class="field" placeholder="Поиск файлов…" style="flex:1;min-width:140px">'
      +     '<select id="vault-filter" class="field" style="width:auto"><option value="all">Все</option><option value="image">Фото</option><option value="video">Видео</option><option value="audio">Аудио</option><option value="books">Книги</option><option value="docs">Документы</option><option value="archive">Архивы</option></select>'
      +     '<select id="vault-sort" class="field" style="width:auto"><option value="new">Сначала новые</option><option value="old">Сначала старые</option><option value="name">По имени</option><option value="size">По размеру</option></select>'
      +   '</div>'
      +   '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">'
      +     '<label class="btn-primary" style="width:auto;margin:0;padding:8px 14px;cursor:pointer">＋ Загрузить<input type="file" id="vault-file" multiple hidden></label>'
      +     '<button type="button" class="btn-primary" id="vault-bulk-move" style="width:auto;margin:0;padding:8px 12px">Переместить</button>'
      +     '<button type="button" class="btn-primary" id="vault-bulk-delete" style="width:auto;margin:0;padding:8px 12px">В корзину</button>'
      +   '</div>'
      +   '<div id="vault-list"><div class="empty">Загрузка списка…</div></div>'
      + '</div>'
      + '<div id="vault-preview"></div>';

    var root = showInPane(html);
    var listEl = root.querySelector('#vault-list');
    var previewEl = root.querySelector('#vault-preview');
    var quotaEl = root.querySelector('#vault-action-quota');
    var vaultSearch = root.querySelector('#vault-search');
    var vaultFilter = root.querySelector('#vault-filter');
    var vaultSort = root.querySelector('#vault-sort');
    var folderSel = root.querySelector('#vault-folder-select');
    var folderPath = root.querySelector('#vault-folder-path');
    var __slVaultUrl = null;

    function fillFolders(){
      var folders = slVaultFolders();
      folderSel.innerHTML = '<option value="">Все файлы · корень</option>' + folders.map(function(f){
        return '<option value="'+esc(f.path)+'">'+esc(String(f.path||'').replace(/\//g,' / '))+'</option>';
      }).join('');
      folderSel.value = window.__slVaultCurrentFolder || '';
      folderPath.textContent = window.__slVaultCurrentFolder ? ('Папка: '+window.__slVaultCurrentFolder) : 'Корневая папка';
    }
    fillFolders();

    function showVaultQuota(q){
      if(!quotaEl || !q) return;
      quotaEl.textContent = q.subscription
        ? ('Действия хранилища: без ограничений'+srvText)
        : ('Действия за 24 часа: '+Number(q.remaining||0)+' / '+Number(q.quota||0)+(q.retry_seconds>0?' · восстановление через '+Math.ceil(q.retry_seconds/3600)+' ч.':'')+srvText);
    }
    if(info.actions) showVaultQuota(info.actions);

    async function chargeVault(kind){
      try{
        var q = await Promise.race([
          api('vault_action_charge',{method:'POST',body:{action:kind}}).catch(function(){return null;}),
          new Promise(function(r){ setTimeout(function(){ r({ok:true,timeout:1}); }, 4000); })
        ]);
        if(q && q.ok){ showVaultQuota(q); return q; }
        if(q && !q.ok){ showVaultQuota(q); throw new Error(q.error||'Лимит хранилища'); }
        return {ok:true,timeout:1};
      }catch(e){
        if(e && e.message && e.message.indexOf('Лимит')>=0) throw e;
        return {ok:true,timeout:1};
      }
    }

    function draw(){
      try {
        var meta = slVaultMeta();
        if(!meta.length){
          listEl.innerHTML = '<div class="empty">Пока нет файлов. Нажми «Загрузить».</div>';
          return;
        }
        var needle = (vaultSearch && vaultSearch.value || '').trim().toLowerCase();
        var filter = vaultFilter && vaultFilter.value || 'all';
        var sort = vaultSort && vaultSort.value || 'new';
        var rows = meta.filter(function(f){
          var t = String(f.type||'').toLowerCase();
          var n = String(f.name||'').toLowerCase();
          var isBook = /\.(pdf|epub|fb2|mobi|azw|azw3|djvu)$/i.test(n);
          var isArchive = /\.(zip|rar|7z|tar|gz)$/i.test(n);
          var ok = filter==='all'
            || (filter==='books' && isBook)
            || (filter==='archive' && isArchive)
            || (filter==='docs' && !isBook && !isArchive && /\.(docx?|xlsx?|pptx?|txt|rtf|odt|ods)$/i.test(n))
            || (filter==='image' && t.indexOf('image/')===0)
            || (filter==='video' && t.indexOf('video/')===0)
            || (filter==='audio' && t.indexOf('audio/')===0);
          return ok && (!needle || n.indexOf(needle)>=0);
        });
        rows.sort(function(a,b){
          if(sort==='old') return (a.added||0)-(b.added||0);
          if(sort==='name') return String(a.name||'').localeCompare(String(b.name||''));
          if(sort==='size') return (Number(b.size)||0)-(Number(a.size)||0);
          return (b.added||0)-(a.added||0);
        });
        if(!rows.length){
          listEl.innerHTML = '<div class="empty">По этому фильтру файлов нет</div>';
          return;
        }
        /* soft page 30 */
        var PAGE=30;
        window.__slVaultPage = Math.max(0, Number(window.__slVaultPage)||0);
        var maxPage = Math.max(0, Math.ceil(rows.length/PAGE)-1);
        if(window.__slVaultPage>maxPage) window.__slVaultPage=maxPage;
        var pageRows = rows.slice(window.__slVaultPage*PAGE, (window.__slVaultPage+1)*PAGE);
        var pager = '<div style="display:flex;gap:8px;align-items:center;justify-content:space-between;margin:8px 0;flex-wrap:wrap">'
          +'<span class="sub">'+rows.length+' файлов · стр. '+(window.__slVaultPage+1)+'/'+(maxPage+1)+'</span>'
          +'<span style="display:flex;gap:6px">'
          +'<button type="button" class="btn-primary" id="vault-page-prev" style="width:auto;margin:0;padding:6px 10px"'+(window.__slVaultPage<=0?' disabled':'')+'>←</button>'
          +'<button type="button" class="btn-primary" id="vault-page-next" style="width:auto;margin:0;padding:6px 10px"'+(window.__slVaultPage>=maxPage?' disabled':'')+'>→</button>'
          +'</span></div>';

        var body = pageRows.map(function(f){
          var book = /\.(pdf|epub|fb2|mobi|azw|azw3|djvu)$/i.test(f.name||'');
          var icon = book?'BOOK':(/^video\//.test(f.type||'')?'VID':(/^audio\//.test(f.type||'')?'AUD':(/^image\//.test(f.type||'')?'IMG':(/\.(zip|rar|7z|tar|gz)$/i.test(f.name||'')?'ZIP':'FILE'))));
          return '<div class="sl-vault-row" style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06)">'
            +'<input type="checkbox" class="vault-bulk-check" data-id="'+esc(f.id)+'" style="width:18px;height:18px;accent-color:#8b7cf8">'
            +'<div class="sl-pdf-thumb" style="width:36px;height:36px;border-radius:10px;display:grid;place-items:center;font-size:10px;background:rgba(130,105,255,.2)">'+icon+'</div>'
            +'<div class="meta" style="min-width:0;flex:1"><b style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+esc(f.name)+'</b>'
            +'<small>'+(book?'Книга · ':'')+slFmtBytes(f.size)+' · '+esc(f.type||'file')+'</small></div>'
            +'<button type="button" class="icon-btn" data-vault-open="'+esc(f.id)+'" title="Открыть">▶</button>'
            +'<button type="button" class="icon-btn" data-vault-download="'+esc(f.id)+'" title="Скачать">↓</button>'
            +'<button type="button" class="icon-btn" data-vault-del="'+esc(f.id)+'" title="В корзину">✕</button>'
            +'</div>';
        }).join('');
        listEl.innerHTML = pager + body;

        var vp=listEl.querySelector('#vault-page-prev');
        var vn=listEl.querySelector('#vault-page-next');
        if(vp) vp.onclick=function(){ window.__slVaultPage=Math.max(0,(window.__slVaultPage||0)-1); draw(); };
        if(vn) vn.onclick=function(){ window.__slVaultPage=(window.__slVaultPage||0)+1; draw(); };

        listEl.querySelectorAll('[data-vault-open]').forEach(function(b){
          b.onclick = async function(){
            try{
              var all = slVaultMetaRaw();
              var f = all.find(function(x){ return String(x.id)===String(b.dataset.vaultOpen); });
              if(!f) return alert('Файл не найден');
              var blob = await slVaultGet(f.id);
              if(!blob) return alert('Файл не найден в IndexedDB (возможно, очищен браузер)');
              try{ await chargeVault('open'); }catch(e){ alert(e.message||e); return; }
              if(__slVaultUrl) try{ URL.revokeObjectURL(__slVaultUrl); }catch(e){}
              __slVaultUrl = URL.createObjectURL(blob);
              var isImg = /^image\//.test(f.type||'');
              var isVid = /^video\//.test(f.type||'');
              var isAud = /^audio\//.test(f.type||'');
              var isPdf = /\.pdf$/i.test(f.name||'') || f.type==='application/pdf';
              var media = isImg ? ('<img src="'+__slVaultUrl+'" style="max-width:100%;border-radius:12px">')
                : isVid ? ('<video src="'+__slVaultUrl+'" controls playsinline style="width:100%;max-height:60vh;border-radius:12px"></video>')
                : isAud ? ('<audio src="'+__slVaultUrl+'" controls style="width:100%"></audio>')
                : isPdf ? ('<iframe src="'+__slVaultUrl+'" style="width:100%;height:70vh;border:0;border-radius:12px;background:#111"></iframe>')
                : ('<div class="empty">Превью недоступно. <a href="'+__slVaultUrl+'" download="'+esc(f.name)+'">Скачать файл</a></div>');
              previewEl.innerHTML = '<div class="sl-tool-card"><div class="val">'+esc(f.name)+'</div>'+media
                +'<div style="margin-top:10px"><a class="btn-primary" style="display:inline-block;width:auto;padding:8px 14px;text-decoration:none" href="'+__slVaultUrl+'" download="'+esc(f.name)+'">Скачать</a></div></div>';
              previewEl.scrollIntoView({behavior:'smooth',block:'nearest'});
            }catch(e){ alert('Ошибка открытия: '+(e&&e.message?e.message:e)); }
          };
        });
        listEl.querySelectorAll('[data-vault-download]').forEach(function(b){
          b.onclick = async function(){
            try{
              var all=slVaultMetaRaw();
              var f=all.find(function(x){ return String(x.id)===String(b.dataset.vaultDownload); });
              if(!f) return;
              var blob=await slVaultGet(f.id); if(!blob) return alert('Файл не найден');
              try{ await chargeVault('download'); }catch(e){ alert(e.message||e); return; }
              var url=URL.createObjectURL(blob);
              var a=document.createElement('a'); a.href=url; a.download=f.name||'file'; a.click();
              setTimeout(function(){ URL.revokeObjectURL(url); }, 2000);
            }catch(e){ alert(e.message||e); }
          };
        });
        listEl.querySelectorAll('[data-vault-del]').forEach(function(b){
          b.onclick = function(){
            if(!confirm('Переместить в корзину? Удалится через 30 дней.')) return;
            var all=slVaultMetaRaw();
            var f=all.find(function(x){ return String(x.id)===String(b.dataset.vaultDel); });
            if(!f) return;
            f.deletedAt=Date.now();
            slVaultMetaSave(all);
            draw();
          };
        });
      } catch(e){
        listEl.innerHTML = '<div class="empty">Ошибка списка: '+esc(String(e&&e.message||e))+'</div>';
        console.error('vault draw', e);
      }
    }

    vaultSearch.oninput = function(){ window.__slVaultPage=0; draw(); };
    vaultFilter.onchange = function(){ window.__slVaultPage=0; draw(); };
    vaultSort.onchange = function(){ window.__slVaultPage=0; draw(); };
    folderSel.onchange = function(){ window.__slVaultCurrentFolder = folderSel.value; window.__slVaultPage=0; fillFolders(); draw(); };

    root.querySelector('#vault-folder-new').onclick = function(){
      var name = prompt('Название папки'); if(!name) return;
      name = name.trim().replace(/[\\\\:*?"<>|]/g,'_').slice(0,60); if(!name) return;
      var parent = window.__slVaultCurrentFolder || '';
      var path = (parent ? parent+'/' : '') + name;
      var folders = slVaultFolders();
      if(folders.some(function(x){ return x.path===path; })){ alert('Такая папка уже есть'); return; }
      folders.push({path:path, name:name, createdAt:Date.now()});
      slVaultFolderSave(folders);
      window.__slVaultCurrentFolder = path;
      fillFolders(); draw();
    };

    root.querySelector('#vault-bulk-move').onclick = function(){
      var ids = Array.prototype.map.call(listEl.querySelectorAll('.vault-bulk-check:checked'), function(x){ return x.dataset.id; });
      if(!ids.length){ alert('Выбери файлы'); return; }
      var folders = slVaultFolders();
      var choice = prompt('Куда переместить? Пусто = корень.\n' + ['(Корень)'].concat(folders.map(function(x){return x.path;})).join('\n'), window.__slVaultCurrentFolder||'');
      if(choice===null) return;
      choice = choice.trim();
      if(choice && !folders.some(function(x){ return x.path===choice; })){ alert('Папка не найдена'); return; }
      var all = slVaultMetaRaw();
      all.forEach(function(f){ if(ids.indexOf(String(f.id))>=0) f.folderPath = choice; });
      slVaultMetaSave(all);
      draw();
    };
    root.querySelector('#vault-bulk-delete').onclick = function(){
      var ids = Array.prototype.map.call(listEl.querySelectorAll('.vault-bulk-check:checked'), function(x){ return x.dataset.id; });
      if(!ids.length){ alert('Выбери файлы'); return; }
      if(!confirm('В корзину ('+ids.length+')?')) return;
      var all = slVaultMetaRaw();
      all.forEach(function(f){ if(ids.indexOf(String(f.id))>=0) f.deletedAt = Date.now(); });
      slVaultMetaSave(all);
      draw();
    };
    root.querySelector('#vault-trash-open').onclick = function(){
      var all = slVaultMetaRaw().filter(function(f){ return f.deletedAt; });
      if(!all.length){ previewEl.innerHTML = '<div class="sl-tool-card"><div class="val">🗑 Корзина</div><div class="empty">Пусто</div></div>'; return; }
      previewEl.innerHTML = '<div class="sl-tool-card"><div class="val">🗑 Корзина · автоудаление через 30 дней</div>' + all.map(function(f){
        return '<div class="sl-vault-row" style="display:flex;gap:8px;align-items:center;padding:8px 0"><div class="meta" style="flex:1"><b>'+esc(f.name)+'</b><small>'+slFmtBytes(f.size)+'</small></div>'
          +'<button type="button" class="btn-primary" data-trash-restore="'+esc(f.id)+'" style="width:auto;margin:0;padding:6px 10px">Восстановить</button>'
          +'<button type="button" class="icon-btn" data-trash-drop="'+esc(f.id)+'">✕</button></div>';
      }).join('') + '</div>';
      previewEl.querySelectorAll('[data-trash-restore]').forEach(function(b){
        b.onclick = function(){
          var all2 = slVaultMetaRaw();
          var f = all2.find(function(x){ return String(x.id)===String(b.dataset.trashRestore); });
          if(f){ delete f.deletedAt; slVaultMetaSave(all2); draw(); root.querySelector('#vault-trash-open').onclick(); }
        };
      });
      previewEl.querySelectorAll('[data-trash-drop]').forEach(function(b){
        b.onclick = async function(){
          if(!confirm('Удалить навсегда?')) return;
          try{ await slVaultDelete(b.dataset.trashDrop); }catch(e){}
          slVaultMetaSave(slVaultMetaRaw().filter(function(x){ return String(x.id)!==String(b.dataset.trashDrop); }));
          draw(); root.querySelector('#vault-trash-open').onclick();
        };
      });
    };

    root.querySelector('#vault-file').onchange = async function(){
      var files = Array.prototype.slice.call(this.files||[]);
      this.value = '';
      if(!files.length) return;
      for(var i=0;i<files.length;i++){
        var file = files[i];
        try{
          try{ await chargeVault('upload'); }catch(e){ alert(e.message||e); break; }
          if(used + file.size > cap){ alert('Не хватает места в квоте'); break; }
          var id = 'f'+Date.now()+'_'+Math.random().toString(36).slice(2,8);
          await slVaultPut(id, file);
          var all = slVaultMetaRaw();
          all.unshift({ id:id, name:file.name, type:file.type||'application/octet-stream', size:file.size, added:Date.now(), folderPath: window.__slVaultCurrentFolder||'' });
          slVaultMetaSave(all);
          used += file.size;
        }catch(e){
          alert('Не удалось загрузить «'+file.name+'»: '+(e&&e.message?e.message:e));
          break;
        }
      }
      draw();
    };

    draw();
    /* background purge — never blocks */
    setTimeout(function(){ try{ Promise.resolve(slVaultPurgeExpired()).catch(function(){}); }catch(e){} }, 1500);
  } catch(e){
    console.error('vault open', e);
    try { showInPane('<div class="empty">Ошибка хранилища: '+esc(String(e&&e.message||e))+'<br><button type="button" class="btn-primary" onclick="openLocalFilesHub()">Повторить</button></div>'); } catch(_e){}
  } finally {
    window.__slVaultBusy = false;
  }
}

async function openMyCreatedServices(){
  destroySpaPlayer();setDetailTitle(spaAppTitle('myservices'));
  const root=showInPane(`<div class="sl-tool-card"><div class="val">🧩 ${slT('Мои сервисы','My services','Мої сервіси')}</div><p class="sub">${slT('Здесь собраны сервисы, которые ты создал через StreamLive Services / API Hub, даже если они находятся в разных серверах.','All services you created through StreamLive Services / API Hub, including services in different servers.','Тут зібрані сервіси, які ти створив через StreamLive Services / API Hub, навіть якщо вони в різних серверах.')}</p><button type="button" class="btn-primary" id="my-services-refresh">${slT('Обновить список','Refresh list','Оновити список')}</button></div><div id="my-created-service-list"><div class="empty">${slT('Загружаю сервисы…','Loading services…','Завантажую сервіси…')}</div></div>`);
  const list=root.querySelector('#my-created-service-list');
  async function draw(){
    list.innerHTML='<div class="empty">'+slT('Загружаю сервисы…','Loading services…','Завантажую сервіси…')+'</div>';
    const d=await api('my_created_services');
    if(!d||!d.ok){list.innerHTML='<div class="empty">'+esc(d&&d.error||slT('Не удалось загрузить сервисы','Could not load services','Не вдалося завантажити сервіси'))+'</div>';return;}
    const items=Array.isArray(d.services)?d.services:[];
    if(!items.length){list.innerHTML='<div class="empty">'+slT('Ты пока не создал сервисов через StreamLive Services. Открой свой сервер → API Hub.','You have not created any StreamLive Services yet. Open your server → API Hub.','Ти ще не створив сервісів через StreamLive Services. Відкрий свій сервер → API Hub.')+'</div>';return;}
    list.innerHTML='<div class="sl-service-glass-grid">'+items.map(a=>`<article class="sl-service-glass-card"><div class="sl-service-glass-head"><span class="sl-service-glass-icon">${esc(a.icon||'🔌')}</span><span class="sl-service-glass-state ${a.is_published?'published':'draft'}">${a.is_published?'Опубликован':'Черновик'}</span></div><b class="sl-service-glass-title">${esc(a.name)}</b><div class="sl-service-glass-meta">${esc(a.server_name||'StreamLive')} · ${esc(a.service_type||'link')}</div><p class="sl-service-glass-desc">${esc(a.description||a.base_url||'')}</p><button type="button" class="btn-primary my-service-open" data-id="${Number(a.id)}">${slT('Открыть сервис','Open service','Відкрити сервіс')} <span aria-hidden="true">↗</span></button></article>`).join('')+'</div>';
    list.querySelectorAll('.my-service-open').forEach(b=>b.onclick=()=>{const a=items.find(x=>Number(x.id)===Number(b.dataset.id));if(!a)return;if(typeof window.__openStreamLiveService==='function')window.__openStreamLiveService(a);else if(state.myServer){openMyServer();setTimeout(()=>{if(typeof window.__openStreamLiveService==='function')window.__openStreamLiveService(a);else if(a.base_url)window.open(a.base_url,'_blank','noopener');},900);}else if(a.base_url)window.open(a.base_url,'_blank','noopener');});
    spaTranslateDom(list);
  }
  root.querySelector('#my-services-refresh').onclick=draw;await draw();
}

async function openDiagnosticsApp(){
  destroySpaPlayer();setDetailTitle(spaAppTitle('diagnostics'));
  const root=showInPane(`<div class="sl-tool-card"><div class="val">🩺 ${slT('Центр диагностики StreamLive','StreamLive diagnostics center','Центр діагностики StreamLive')}</div><p class="sub">${slT('Проверка соединения, API, версии сервера и возможностей устройства. Секреты и пароли не собираются.','Checks connection, API, server version and device capabilities. No secrets or passwords are collected.','Перевірка з’єднання, API, версії сервера та можливостей пристрою. Секрети й паролі не збираються.')}</p><button type="button" class="btn-primary" id="diag-run">${slT('Запустить проверку','Run checks','Запустити перевірку')}</button><div id="diag-results" style="margin-top:10px"><div class="empty">${slT('Нажми «Запустить проверку»','Tap “Run checks”','Натисни «Запустити перевірку»')}</div></div></div>`);
  const out=root.querySelector('#diag-results');
  async function run(){
    const btn=root.querySelector('#diag-run');btn.disabled=true;out.innerHTML='<div class="empty">'+slT('Проверяю…','Checking…','Перевіряю…')+'</div>';
    let version=null,limits=null;try{version=await api('version');}catch(e){}try{if(state.user)limits=await api('limits_status');}catch(e){}
    const checks=[
      [slT('Интернет','Internet','Інтернет'),navigator.onLine?slT('Подключён','Online','Підключено'):slT('Нет соединения','Offline','Немає з’єднання'),!!navigator.onLine],
      ['API StreamLive',version&&version.ok?slT('Отвечает','Responding','Відповідає'):slT('Нет ответа','No response','Немає відповіді'),!!(version&&version.ok)],
      [slT('Авторизованный API','Authenticated API','Авторизований API'),!state.user?slT('Не проверен: вход не выполнен','Not checked: signed out','Не перевірено: не виконано вхід'):(limits&&limits.ok?slT('Доступен','Available','Доступний'):slT('Проверь сессию на зеркале','Check mirror session','Перевір сесію на дзеркалі')),!!(limits&&limits.ok)],
      ['PlayerJS',((window.Playerjs||window.PlayerJS||window.playerjs)||typeof Playerjs==='function')?slT('Загружен','Loaded','Завантажений'):slT('Не найден — включится резервный плеер для MP4/HLS','Not found — MP4/HLS fallback will be used','Не знайдений — для MP4/HLS буде резервний плеєр'),!!(window.Playerjs||window.PlayerJS||window.playerjs||typeof Playerjs==='function')],
      [slT('Screen Wake Lock','Screen Wake Lock','Screen Wake Lock'),navigator.wakeLock?slT('Поддерживается','Supported','Підтримується'):slT('Не поддерживается','Not supported','Не підтримується'),!!navigator.wakeLock],
      [slT('Вибрация','Vibration','Вібрація'),typeof navigator.vibrate==='function'?slT('Поддерживается','Supported','Підтримується'):slT('Не поддерживается браузером','Not supported by browser','Не підтримується браузером'),typeof navigator.vibrate==='function'],
      [slT('Хранение лимитов до 9999 года','Limit dates through year 9999','Зберігання лімітів до 9999 року'),version&&version.runtime&&version.runtime.limit_time_storage==='mysql_datetime_9999'?slT('MySQL DATETIME · дата и время хранятся отдельно от Unix timestamp','MySQL DATETIME · date/time are stored independently of Unix timestamps','MySQL DATETIME · дата й час зберігаються окремо від Unix timestamp'):slT('Проверь версию скрипта и схему DATETIME','Check script version and DATETIME schema','Перевір версію скрипта та схему DATETIME'),!!(version&&version.runtime&&version.runtime.limit_time_storage==='mysql_datetime_9999')]
    ];
    out.innerHTML=checks.map(x=>`<div class="sl-vault-row"><div style="flex:1;min-width:0"><b>${esc(x[0])}</b><small style="display:block;color:var(--muted)">${esc(x[1])}</small></div><span>${x[2]?'✅':'⚠️'}</span></div>`).join('')+`<div class="sub" style="margin-top:8px">${slT('Сборка клиента','Client build','Збірка клієнта')}: ${esc(BOOT.build)} · ${slT('Сборка сервера','Server build','Збірка сервера')}: ${esc(version&&version.build||'—')} · PHP ${esc(version&&version.runtime&&version.runtime.php_version||'—')}</div>`;
    btn.disabled=false;spaTranslateDom(out);
  }
  root.querySelector('#diag-run').onclick=run;await run();
}

async function openMyServices(){
  destroySpaPlayer();setDetailTitle(spaAppTitle('services'));
  const root=showInPane(`<div class="sl-app-center-hero"><div class="sl-app-center-orb">🔗</div><div><div class="val">StreamLive Services</div><p>${slT('Добавляй сайты и запускай их внутри StreamLive, не покидая приложение. Некоторые сайты запрещают встраивание — для них будет доступно отдельное открытие.','Add websites and launch them inside StreamLive without leaving the app. Some websites block embedding and can be opened separately.','Додавай сайти та запускай їх усередині StreamLive, не залишаючи застосунок.')}</p></div></div><div class="sl-tool-card sl-service-add-card"><div class="label">${slT('Новый сервис','New service','Новий сервіс')}</div><input class="field" id="svc-name" maxlength="100" placeholder="${slT('Название сервиса','Service name','Назва сервісу')}"><input class="field" id="svc-url" inputmode="url" placeholder="https://example.com"><button class="btn-primary" id="svc-add">＋ ${slT('Добавить сервис','Add service','Додати сервіс')}</button><p class="sl-service-hint">Иконка сайта загрузится автоматически. Поддерживаются ссылки HTTPS и HTTP.</p></div><section class="sl-app-center-section"><div class="sl-app-center-head"><b>${slT('Мои сервисы','My services','Мої сервіси')}</b><span id="svc-count"></span></div><div class="sl-service-app-grid" id="svc-list"><div class="empty">${slT('Загрузка…','Loading…','Завантаження…')}</div></div></section>`);
  const box=root.querySelector('#svc-list');
  async function draw(){const d=await api('services_list');if(!d||!d.ok){box.innerHTML='<div class="empty">Не удалось загрузить сервисы. Проверь авторизацию.</div>';return;}const items=Array.isArray(d.items)?d.items:[];root.querySelector('#svc-count').textContent=items.length+' / 100';box.innerHTML=items.length?items.map(x=>{const icon=slServiceIcon(x.url),fallback=slServiceFallback(x.name,x.url);return `<div class="sl-service-app-wrap"><button type="button" class="sl-service-app" data-open="${Number(x.id)}"><span class="sl-service-app-icon"><img src="${esc(icon)}" alt="" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='grid'"><i style="display:none">${fallback}</i></span><b>${esc(x.name)}</b><small>${esc(slServiceHost(x.url))}</small></button><button type="button" class="sl-service-delete" data-del="${Number(x.id)}" aria-label="Удалить ${esc(x.name)}">✕</button></div>`}).join(''):'<div class="empty">Добавь первый сервис — он появится здесь как мини-приложение.</div>';
    box.querySelectorAll('[data-open]').forEach(b=>b.onclick=()=>{const x=items.find(v=>Number(v.id)===Number(b.dataset.open));if(x)slOpenService(x)});box.querySelectorAll('[data-del]').forEach(b=>b.onclick=async()=>{if(!confirm('Удалить этот сервис из StreamLive?'))return;const r=await api('services_delete',{method:'POST',body:{id:b.dataset.del}});if(r&&r.ok)draw();else alert(r&&r.error||'Не удалось удалить сервис')});}
  root.querySelector('#svc-add').onclick=async()=>{const name=root.querySelector('#svc-name').value.trim(),url=root.querySelector('#svc-url').value.trim();const r=await api('services_save',{method:'POST',body:{name,url}});if(!r||!r.ok){alert(r&&r.error||'Не удалось сохранить сервис');return;}root.querySelector('#svc-name').value='';root.querySelector('#svc-url').value='';await draw();};root.querySelector('#svc-url').addEventListener('keydown',e=>{if(e.key==='Enter')root.querySelector('#svc-add').click()});draw();
}

async function openMediaLibrary(){
  destroySpaPlayer();setDetailTitle('Единая медиатека');
  let cont=continueLoad(),hist=spaWatchHistGet();
  try{const [c,h]=await Promise.all([api('media_continue_list'),api('media_history_list')]);if(c&&c.ok&&Array.isArray(c.items))cont=c.items;if(h&&h.ok&&Array.isArray(h.items))hist=h.items;}catch(e){}
  const root=showInPane(`<div class="sl-tool-card"><div class="val">🗂️ Единая медиатека</div><p style="font-size:12px;color:var(--muted)">Избранное, продолжение и история привязаны к аккаунту StreamLive и доступны на зеркалах с общей базой данных.</p><div class="apps-grid"><button class="app-tile" id="ml-favs"><span class="app-ico">⭐</span><b>Избранное</b></button><button class="app-tile" id="ml-continue"><span class="app-ico">▶️</span><b>Продолжить</b></button><button class="app-tile" id="ml-history"><span class="app-ico">🕘</span><b>История</b></button><button class="app-tile" id="ml-cinema"><span class="app-ico">🎬</span><b>Мой список кино</b></button><button class="app-tile" id="ml-music"><span class="app-ico">🎵</span><b>Музыка</b></button></div><div class="sl-tool-card" style="margin-top:12px"><div class="val">Твоя медиатека</div><div class="sl-vault-row"><b>${cont.length}</b><small>элементов в продолжении просмотра</small></div><div class="sl-vault-row"><b>${hist.length}</b><small>недавних просмотров</small></div></div></div>`);
  root.querySelector('#ml-favs').onclick=()=>openFavorites();root.querySelector('#ml-continue').onclick=()=>openContinueWatch();root.querySelector('#ml-history').onclick=()=>openWatchHistory();root.querySelector('#ml-cinema').onclick=()=>openCinemaMyList();root.querySelector('#ml-music').onclick=()=>openMusicHub();
}

async function openPlanner(){
  destroySpaPlayer();setDetailTitle('Планировщик');
  const root=showInPane(`<div class="sl-tool-card"><div class="val">🗓️ Планировщик</div><p style="font-size:12px;color:var(--muted)">Задачи и планы привязаны к твоему аккаунту StreamLive.</p><input class="field" id="pl-title" maxlength="180" placeholder="Название задачи"><textarea class="field" id="pl-note" maxlength="500" rows="2" placeholder="Заметка (необязательно)"></textarea><input class="field" id="pl-due" type="datetime-local"><button class="btn-primary" id="pl-add">Добавить задачу</button></div><div id="pl-list"><div class="empty">Загружаю задачи…</div></div>`);
  const list=root.querySelector('#pl-list');
  async function draw(){const d=await api('planner_list');if(!d||!d.ok){list.innerHTML='<div class="empty">Не удалось загрузить задачи. Проверь авторизацию.</div>';return;}list.innerHTML=d.items.length?d.items.map(x=>`<div class="sl-vault-row" style="display:flex;gap:10px;align-items:flex-start"><input type="checkbox" data-pl-done="${x.id}" ${x.is_done?'checked':''} style="margin-top:4px"><div class="meta" style="flex:1;min-width:0"><b style="${x.is_done?'text-decoration:line-through;opacity:.55':''}">${esc(x.title)}</b>${x.note?`<small>${esc(x.note)}</small>`:''}${x.due_at?`<small>📅 ${esc(x.due_at)}</small>`:''}</div><button class="icon-btn" data-pl-del="${x.id}">✕</button></div>`).join(''):'<div class="empty">Пока нет задач. Добавь первую выше.</div>';list.querySelectorAll('[data-pl-done]').forEach(b=>b.onchange=async()=>{await api('planner_toggle',{method:'POST',body:{id:b.dataset.plDone,done:b.checked?1:0}});draw();});list.querySelectorAll('[data-pl-del]').forEach(b=>b.onclick=async()=>{await api('planner_delete',{method:'POST',body:{id:b.dataset.plDel}});draw();});}
  root.querySelector('#pl-add').onclick=async()=>{const title=root.querySelector('#pl-title').value.trim();if(!title){alert('Напиши название задачи');return;}const due=root.querySelector('#pl-due').value;const r=await api('planner_save',{method:'POST',body:{title,note:root.querySelector('#pl-note').value.trim(),due_at:due}});if(!r||!r.ok){alert(r&&r.error||'Не удалось сохранить задачу');return;}root.querySelector('#pl-title').value='';root.querySelector('#pl-note').value='';root.querySelector('#pl-due').value='';draw();};draw();
}

async function spaLoadCloudPrefs(){
  if(!state.user)return;try{const d=await api('prefs_get');if(d&&d.ok&&d.prefs){const c=JSON.parse(localStorage.getItem('streamspa_settings')||'{}');const merged=Object.assign({},c,d.prefs);localStorage.setItem('streamspa_settings',JSON.stringify(merged));applySpaSettings(merged);}}catch(e){}
}
function openHyperPersonalization(){
  destroySpaPlayer();setDetailTitle('Hyper персонализация');
  let conf={};try{conf=JSON.parse(localStorage.getItem('streamspa_settings')||'{}')}catch(e){}
  const colors=[['Фиолетовый','#8b5cf6'],['Синий','#3b82f6'],['Бирюзовый','#14b8a6'],['Розовый','#ec4899'],['Оранжевый','#f97316'],['Зелёный','#22c55e']];const current=conf.accentColor||'#8b5cf6';
  const root=showInPane(`<div class="sl-tool-card"><div class="val">✨ Hyper персонализация</div><p style="font-size:12px;color:var(--muted)">Настрой интерфейс StreamLive под себя. Оформление синхронизируется с аккаунтом.</p><div class="label">Акцентный цвет</div><div class="apps-grid" id="hp-colors">${colors.map(c=>`<button type="button" class="app-tile" data-color="${c[1]}" style="border:2px solid ${current===c[1]?'var(--accent)':'transparent'}"><span class="app-ico" style="color:${c[1]}">●</span><b>${c[0]}</b></button>`).join('')}</div><div class="label" style="margin-top:14px">Размер текста</div><input class="field" id="hp-font" type="range" min="0.9" max="1.2" step="0.05" value="${Number(conf.fontScale||1)}"><div class="label">Плотность списков</div><label class="sl-vault-row"><input id="hp-compact" type="checkbox" ${conf.compact?'checked':''}> Компактный режим</label><div class="label">Движение интерфейса</div><label class="sl-vault-row"><input id="hp-motion" type="checkbox" ${conf.reduceMotion?'checked':''}> Уменьшить анимации</label><div class="label">Главный экран</div><label class="sl-vault-row"><input id="hp-continue" type="checkbox" ${conf.homeContinue!==false?'checked':''}> Показывать «Продолжить просмотр»</label><button type="button" class="btn-primary" id="hp-save">Сохранить оформление</button></div>`);
  let color=current;root.querySelectorAll('[data-color]').forEach(b=>b.onclick=()=>{color=b.dataset.color;root.querySelectorAll('[data-color]').forEach(x=>x.style.borderColor=x===b?'var(--accent)':'transparent');document.documentElement.style.setProperty('--accent',color);});
  root.querySelector('#hp-save').onclick=async()=>{conf.accentColor=color;conf.fontScale=Number(root.querySelector('#hp-font').value)||1;conf.compact=root.querySelector('#hp-compact').checked;conf.reduceMotion=root.querySelector('#hp-motion').checked;conf.homeContinue=root.querySelector('#hp-continue').checked;localStorage.setItem('streamspa_settings',JSON.stringify(conf));applySpaSettings(conf);document.documentElement.style.setProperty('--accent',color);let synced=false;try{const r=await api('prefs_save',{method:'POST',body:{accentColor:conf.accentColor,fontScale:conf.fontScale,compact:conf.compact?1:0,reduceMotion:conf.reduceMotion?1:0,homeContinue:conf.homeContinue?1:0}});synced=!!(r&&r.ok);}catch(e){}spaPlay('ok');alert(synced?'Персонализация сохранена в аккаунте StreamLive.':'Персонализация сохранена на этом устройстве; синхронизация пока недоступна.');};
}

const SPA_YT_SESSION_KEY='sl_youtube_personal_key_session_v2';
const SPA_KP_SESSION_KEY='sl_kp_personal_key_session_v2';
function spaReadSessionKey(name){try{return String(sessionStorage.getItem(name)||'').trim();}catch(e){return '';}}
function spaWriteSessionKey(name,value){try{if(value)sessionStorage.setItem(name,String(value));else sessionStorage.removeItem(name);}catch(e){}}
let spaYouTubePersonalApiKey = spaReadSessionKey(SPA_YT_SESSION_KEY);
let spaKinopoiskPersonalApiKey = spaReadSessionKey(SPA_KP_SESSION_KEY);
let spaServerKpHasKey = false;
function spaMigrateLegacyApiKeys(){
  // One-time safe move: recover any legacy local key into this tab's session, then erase persistent copies.
  try{const c=JSON.parse(localStorage.getItem('streamspa_settings')||'{}');if(!spaYouTubePersonalApiKey&&c.youtube_api_key){spaYouTubePersonalApiKey=String(c.youtube_api_key).trim();spaWriteSessionKey(SPA_YT_SESSION_KEY,spaYouTubePersonalApiKey);}if(!spaKinopoiskPersonalApiKey&&c.kp_api_key){spaKinopoiskPersonalApiKey=String(c.kp_api_key).trim();spaWriteSessionKey(SPA_KP_SESSION_KEY,spaKinopoiskPersonalApiKey);}delete c.youtube_api_key;delete c.kp_api_key;localStorage.setItem('streamspa_settings',JSON.stringify(c));}catch(e){}
  try{if(!spaYouTubePersonalApiKey){spaYouTubePersonalApiKey=String(localStorage.getItem('sl_youtube_api_key')||'').trim();if(spaYouTubePersonalApiKey)spaWriteSessionKey(SPA_YT_SESSION_KEY,spaYouTubePersonalApiKey);}if(!spaKinopoiskPersonalApiKey){spaKinopoiskPersonalApiKey=String(localStorage.getItem('sl_kp_api_key')||'').trim();if(spaKinopoiskPersonalApiKey)spaWriteSessionKey(SPA_KP_SESSION_KEY,spaKinopoiskPersonalApiKey);}}catch(e){}
  try{localStorage.removeItem('sl_youtube_api_key');localStorage.removeItem('sl_kp_api_key');localStorage.removeItem('spa_server_kp_key');localStorage.removeItem('spa_server_youtube_key');}catch(e){}
}
function youtubePersonalKey(){spaMigrateLegacyApiKeys();return spaYouTubePersonalApiKey||spaReadSessionKey(SPA_YT_SESSION_KEY);}
// Personal keys stay in this tab's sessionStorage: reload-safe, cleared when the tab session ends.
spaMigrateLegacyApiKeys();
async function refreshServerYoutubeKey(){try{localStorage.removeItem('spa_server_youtube_key');return await api('server_youtube_get');}catch(e){return null;}}
function ensureYouTubeIframeApi(){
  if(window.YT&&window.YT.Player)return Promise.resolve(window.YT);
  if(window.__slYtIframeApiPromise)return window.__slYtIframeApiPromise;
  window.__slYtIframeApiPromise=new Promise((resolve,reject)=>{
    let settled=false;
    const finish=(fn,value)=>{if(settled)return;settled=true;fn(value);};
    const previous=window.onYouTubeIframeAPIReady;
    window.onYouTubeIframeAPIReady=function(){try{if(typeof previous==='function')previous();}catch(e){}finish(resolve,window.YT);};
    let script=document.querySelector('script[data-sl-youtube-iframe-api]');
    if(!script){script=document.createElement('script');script.src='https://www.youtube.com/iframe_api';script.async=true;script.dataset.slYoutubeIframeApi='1';script.onerror=()=>finish(reject,new Error('Не удалось загрузить официальный YouTube Player API'));document.head.appendChild(script);}
    setTimeout(()=>finish(reject,new Error('YouTube Player API не ответил за 15 секунд')),15000);
  });
  return window.__slYtIframeApiPromise;
}
function openYouTubeApp(){
  destroySpaPlayer();setDetailTitle('YouTube · StreamLive');
  const root=showInPane(`<section id="sl-youtube-app">
    <aside class="yt-sidebar" aria-label="Навигация YouTube">
      <div class="yt-side-brand"><span class="yt-brand-ico">▶</span><span>YouTube</span></div>
      <div class="yt-side-group">
        <button class="yt-side-btn active" type="button" data-yt-view="home"><span class="ico">⌂</span>Главная</button>
        <button class="yt-side-btn" type="button" data-yt-view="popular"><span class="ico">🔥</span>Популярное</button>
      </div>
      <div class="yt-side-group">
        <button class="yt-side-btn" type="button" data-yt-view="music"><span class="ico">♫</span>Музыка</button>
        <button class="yt-side-btn" type="button" data-yt-view="gaming"><span class="ico">🎮</span>Игры</button>
        <button class="yt-side-btn" type="button" data-yt-view="tech"><span class="ico">⌘</span>Технологии</button>
        <button class="yt-side-btn" type="button" data-yt-view="trailers"><span class="ico">🎬</span>Трейлеры</button>
      </div>
      <div class="yt-side-group">
        <button class="yt-side-btn" type="button" data-yt-view="history"><span class="ico">◷</span>История</button>
        <button class="yt-side-btn" type="button" data-yt-view="saved"><span class="ico">▱</span>Сохранённые</button>
      </div>
    </aside>
    <div class="yt-content">
      <header class="yt-top">
        <form class="yt-search" id="yt-search-form"><input class="field" id="yt-search-q" autocomplete="off" placeholder="Поиск на YouTube…"><button class="btn-primary" id="yt-search-go" type="submit" aria-label="Найти">⌕</button></form>
        <div class="yt-quota" id="yt-quota">Подключаю YouTube…</div>
      </header>
      <main class="yt-main">
        <nav class="yt-cats" aria-label="Категории YouTube">
          <button class="yt-cat active" type="button" data-yt-home="">Все</button>
          <button class="yt-cat" type="button" data-yt-home="10">Музыка</button>
          <button class="yt-cat" type="button" data-yt-home="20">Игры</button>
          <button class="yt-cat" type="button" data-yt-home="28">Технологии</button>
          <button class="yt-cat" type="button" data-yt-home="1">Трейлеры и кино</button>
        </nav>
        <div id="yt-player-wrap"></div>
        <div class="yt-section-head"><h3 id="yt-results-title">Рекомендации</h3><span class="sub" id="yt-results-count"></span></div>
        <div id="yt-results" class="yt-results"><div class="yt-empty">Загружаю видео…</div></div>
      </main>
    </div>
  </section>`);
  const q=root.querySelector('#yt-search-q'),form=root.querySelector('#yt-search-form'),go=root.querySelector('#yt-search-go'),results=root.querySelector('#yt-results'),quota=root.querySelector('#yt-quota'),player=root.querySelector('#yt-player-wrap'),count=root.querySelector('#yt-results-count'),heading=root.querySelector('#yt-results-title');
  let serverKeyReady=false,serverHasKey=false,ytPlayer=null,activeCharge=null,searchBusy=false,currentVideo=null,currentSource='youtube',sourceMode='auto',ytFallbackTimer=null;
  const favKey='sl_yt_saved_v2',histKey='sl_yt_history_v2',localReactKey='sl_yt_reactions_v1';
  const getLocal=k=>{try{const a=JSON.parse(localStorage.getItem(k)||'[]');return Array.isArray(a)?a:[];}catch(e){return [];}};
  const putLocal=(k,a)=>{try{localStorage.setItem(k,JSON.stringify(a.slice(0,100)));}catch(e){}};
  const getLocalReacts=()=>{try{return JSON.parse(localStorage.getItem(localReactKey)||'{}')||{};}catch(e){return {};}};
  const setLocalReact=(id,v)=>{const a=getLocalReacts();if(v)a[id]=v;else delete a[id];try{localStorage.setItem(localReactKey,JSON.stringify(a));}catch(e){}};
  function applyReactionUI(scope,id,r){if(!scope)return;const like=scope.querySelector('[data-yt-vote="1"]'),dislike=scope.querySelector('[data-yt-vote="-1"]');if(like){like.classList.toggle('active',r.my_vote===1);const n=like.querySelector('.yt-vote-count');if(n)n.textContent=String(r.likes||0);}if(dislike){dislike.classList.toggle('active',r.my_vote===-1);const n=dislike.querySelector('.yt-vote-count');if(n)n.textContent=String(r.dislikes||0);}}
  async function getReactions(ids){const clean=[...new Set(ids.filter(x=>/^[A-Za-z0-9_-]{11}$/.test(String(x||''))))].slice(0,50);if(!clean.length)return {};try{const d=await api('youtube_reaction_get',{method:'POST',body:{video_ids:JSON.stringify(clean)}});if(d&&d.ok)return d.reactions||{};}catch(e){}const local=getLocalReacts(),out={};clean.forEach(id=>out[id]={likes:0,dislikes:0,my_vote:Number(local[id]||0)});return out;}
  async function setReaction(id,v,scope){const buttons=scope?scope.querySelectorAll('[data-yt-vote]'):[];buttons.forEach(b=>b.disabled=true);const active=scope&&scope.querySelector('[data-yt-vote].active');const current=active?Number(active.dataset.ytVote):Number(getLocalReacts()[id]||0);const next=current===v?0:v;setLocalReact(id,next);let r={likes:0,dislikes:0,my_vote:next};try{const d=await api('youtube_reaction_set',{method:'POST',body:{video_id:id,vote:next}});if(d&&d.ok)r=d.reaction||r;}catch(e){}applyReactionUI(scope,id,r);buttons.forEach(b=>b.disabled=false);}
  async function hydrateReactionButtons(scope,id){const d=await getReactions([id]);const local=getLocalReacts();applyReactionUI(scope,id,d[id]||{likes:0,dislikes:0,my_vote:Number(local[id]||0)});}
  async function getBookmarks(ids){let remote=null;try{const body=ids?{video_ids:JSON.stringify(ids)}:{};const d=await api('youtube_bookmark_get',{method:'POST',body});if(d&&d.ok)remote=d.items||[];}catch(e){}const local=getLocal(favKey);if(!remote)return local;const merged=[...remote];local.forEach(x=>{if(x&&x.id&&!merged.some(y=>y.id===x.id))merged.push(x);});return ids?merged.filter(x=>ids.includes(x.id)):merged.slice(0,100);}
  async function toggleBookmark(item,button){let a=getLocal(favKey),exists=button&&button.dataset.saved!==undefined?button.dataset.saved==='1':a.some(x=>x.id===item.id),on=!exists;if(on){a=[item,...a.filter(x=>x.id!==item.id)].slice(0,100);}else a=a.filter(x=>x.id!==item.id);putLocal(favKey,a);if(button){button.dataset.saved=on?'1':'0';button.textContent=on?'✓ В закладках':'＋ В закладки';}try{const d=await api('youtube_bookmark_set',{method:'POST',body:{video_id:item.id,saved:on?1:0,title:item.title,channel:item.channel,thumbnail:item.thumbnail,description:item.description||''}});if(d&&d.ok)return;}catch(e){} }
  const region=(String(navigator.language||'en-US').match(/[-_]([A-Za-z]{2})$/)||[])[1]||'US';
  if(quota) quota.remove();
  function showQuota(d){ /* квоты показываются только в Настройках SPA */ }
  function play(v){
    const id=String(v.id||'').replace(/[^A-Za-z0-9_-]/g,'');if(!/^[A-Za-z0-9_-]{11}$/.test(id))return;
    try{if(ytPlayer&&typeof ytPlayer.destroy==='function')ytPlayer.destroy();}catch(e){}ytPlayer=null;window.__slYoutubePlayer=null;
    const item={id,title:String(v.title||'YouTube'),channel:String(v.channel||'YouTube'),thumbnail:String(v.thumbnail||''),description:String(v.description||'')};currentVideo=item;
    const hist=getLocal(histKey).filter(x=>x.id!==id);hist.unshift(item);putLocal(histKey,hist);
    sourceMode=(()=>{try{return localStorage.getItem('sl_yt_source_mode')||'auto';}catch(e){return 'auto';}})();
    const title=esc(item.title),origin=encodeURIComponent(location.origin);
    player.innerHTML=`<div class="yt-player-card"><div class="yt-player-head"><div><div class="yt-player-title">${title}</div><div class="sub" style="margin-top:5px">${esc(item.channel)}</div></div><div class="yt-player-tools"><label class="yt-source-label">Источник <select id="yt-source-mode" class="yt-source-select"><option value="auto" ${sourceMode==='auto'?'selected':''}>Авто</option><option value="youtube" ${sourceMode==='youtube'?'selected':''}>YouTube</option><option value="piped" ${sourceMode==='piped'?'selected':''}>Piped · прокси</option><option value="invidious" ${sourceMode==='invidious'?'selected':''}>Invidious · API</option><option value="cobalt" ${sourceMode==='cobalt'?'selected':''}>Cobalt · альтернативный</option></select></label><button type="button" class="yt-open-original" id="yt-sources-refresh" title="Обновить публичные источники">↻ Источники</button><button type="button" class="yt-open-original" id="yt-fullscreen" title="Развернуть плеер">⛶ На весь экран</button><button type="button" class="yt-open-original" id="yt-cobalt-config" title="Настроить собственный Cobalt API">⚙ Cobalt</button><a class="yt-open-original" href="https://www.youtube.com/watch?v=${id}" target="_blank" rel="noopener noreferrer">Открыть на YouTube ↗</a></div></div><div class="yt-frame" id="yt-frame-wrap"><iframe id="yt-frame" src="https://www.youtube.com/embed/${id}?enablejsapi=1&origin=${origin}&autoplay=1&playsinline=1&rel=0" title="${title}" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div><div class="yt-player-status sub" id="yt-player-status" role="status" aria-live="polite">Подключаю официальный YouTube-плеер…</div><button type="button" class="yt-about-toggle" id="yt-about-toggle">О видео · About</button><div class="yt-about-panel" id="yt-about-panel" hidden>${esc(item.description||'Описание для этого видео не предоставлено источником.')}</div><div class="yt-reaction-bar" id="yt-player-reactions"><button type="button" data-yt-vote="1">👍 <span class="yt-vote-count">0</span></button><button type="button" data-yt-vote="-1">👎 <span class="yt-vote-count">0</span></button><button type="button" data-yt-player-save="1">＋ В закладки</button></div></div>`;
    const mode=player.querySelector('#yt-source-mode');if(mode)mode.onchange=()=>{sourceMode=mode.value;try{localStorage.setItem('sl_yt_source_mode',sourceMode);}catch(e){}if(sourceMode==='piped')playPiped(item,0);else if(sourceMode==='invidious')playInvidious(item,0);else if(sourceMode==='cobalt')playCobalt(item);else playYouTube(item);};
    const refreshSources=player.querySelector('#yt-sources-refresh');if(refreshSources)refreshSources.onclick=async()=>{try{localStorage.removeItem('sl_piped_instances_v2');localStorage.removeItem('sl_invidious_instances_v1');await Promise.all([loadPipedInstances(),loadInvidiousInstances(true)]);alert('Списки источников обновлены.');}catch(e){alert('Не удалось обновить списки источников.');}};const fsButton=player.querySelector('#yt-fullscreen');if(fsButton)fsButton.onclick=async()=>{const frameWrap=player.querySelector('#yt-frame-wrap');try{if(frameWrap&&frameWrap.requestFullscreen)await frameWrap.requestFullscreen();else if(frameWrap&&frameWrap.webkitRequestFullscreen)frameWrap.webkitRequestFullscreen();}catch(e){}};const cobaltConfigButton=player.querySelector('#yt-cobalt-config');if(cobaltConfigButton)cobaltConfigButton.onclick=configureCobalt;
    const aboutToggle=player.querySelector('#yt-about-toggle'),aboutPanel=player.querySelector('#yt-about-panel');if(aboutToggle&&aboutPanel)aboutToggle.onclick=()=>{aboutPanel.hidden=!aboutPanel.hidden;aboutToggle.textContent=aboutPanel.hidden?'О видео · About':'Скрыть описание';};
    const reactions=player.querySelector('#yt-player-reactions');if(reactions){reactions.querySelectorAll('[data-yt-vote]').forEach(b=>b.onclick=()=>setReaction(id,Number(b.dataset.ytVote),reactions));const save=reactions.querySelector('[data-yt-player-save]');if(save){getBookmarks([id]).then(a=>{const on=a.some(x=>x.id===id);save.dataset.saved=on?'1':'0';save.textContent=on?'✓ В закладках':'＋ В закладки';});save.onclick=()=>toggleBookmark(item,save);}hydrateReactionButtons(reactions,id);}
    player.scrollIntoView({behavior:'smooth',block:'start'});
    if(sourceMode==='piped')playPiped(item,0);else if(sourceMode==='invidious')playInvidious(item,0);else if(sourceMode==='cobalt')playCobalt(item);else playYouTube(item);
  }
  const pipedFallbackInstances=[
    'https://pipedapi.kavin.rocks','https://pipedapi.leptons.xyz','https://pipedapi.nosebs.ru',
    'https://pipedapi-libre.kavin.rocks','https://piped-api.privacy.com.de','https://pipedapi.adminforge.de',
    'https://api.piped.yt','https://pipedapi.drgns.space','https://pipedapi.owo.si','https://pipedapi.ducks.party',
    'https://piped-api.codespace.cz','https://pipedapi.reallyaweso.me','https://api.piped.private.coffee',
    'https://pipedapi.darkness.services','https://pipedapi.orangenet.cc'
  ];
  let pipedInstancesPromise=null;
  async function loadPipedInstances(){
    if(pipedInstancesPromise)return pipedInstancesPromise;
    pipedInstancesPromise=(async()=>{
      let discovered=[];
      try{
        const cached=JSON.parse(localStorage.getItem('sl_piped_instances_v2')||'{}');
        if(Array.isArray(cached.items)&&Date.now()-Number(cached.at||0)<30*60*1000)discovered=cached.items;
      }catch(e){}
      if(!discovered.length){
        try{
          const ctrl=new AbortController(),timer=setTimeout(()=>ctrl.abort(),5000);
          const r=await fetch('https://raw.githubusercontent.com/TeamPiped/documentation/main/content/docs/public-instances/index.md',{cache:'no-store',signal:ctrl.signal,headers:{Accept:'text/plain'}});clearTimeout(timer);
          if(r.ok){const md=await r.text();const urls=[...md.matchAll(/https:\/\/[^\s|)<>`]+/g)].map(m=>m[0].replace(/[),.;]+$/,''));discovered=urls.filter(u=>{try{const h=new URL(u).hostname;return new URL(u).protocol==='https:'&&(/piped/i.test(h)||/^api\.piped\./i.test(h));}catch(e){return false;}});}
        }catch(e){}
      }
      const all=[...new Set([...discovered,...pipedFallbackInstances])].filter(u=>{try{return new URL(u).protocol==='https:';}catch(e){return false;}});
      try{localStorage.setItem('sl_piped_instances_v2',JSON.stringify({at:Date.now(),items:all}));}catch(e){}
      let preferred='';try{preferred=localStorage.getItem('sl_piped_working_v2')||'';}catch(e){}
      if(preferred&&all.includes(preferred))return [preferred,...all.filter(x=>x!==preferred)];
      return all;
    })().finally(()=>{pipedInstancesPromise=null;});
    return pipedInstancesPromise;
  }
  let invidiousInstancesPromise=null;
  async function loadInvidiousInstances(force=false){
    if(invidiousInstancesPromise)return invidiousInstancesPromise;
    invidiousInstancesPromise=(async()=>{let list=[];try{const cache=JSON.parse(localStorage.getItem('sl_invidious_instances_v1')||'{}');if(!force&&Array.isArray(cache.items)&&Date.now()-Number(cache.at||0)<30*60*1000)list=cache.items;}catch(e){}
      if(!list.length){try{const ctrl=new AbortController(),timer=setTimeout(()=>ctrl.abort(),7000);const r=await fetch('https://api.invidious.io/instances.json?sort_by=health',{cache:'no-store',signal:ctrl.signal,headers:{Accept:'application/json'}});clearTimeout(timer);if(r.ok){const data=await r.json();list=(Array.isArray(data)?data:[]).filter(x=>x&&x[1]&&x[1].api&&x[1].cors).map(x=>{const host=String(x[0]||'').replace(/^https?:\/\//,'').replace(/\/$/,'');return host?'https://'+host:'';}).filter(Boolean).slice(0,40);}}catch(e){}}
      list=[...new Set(list)].filter(x=>/^https:\/\//i.test(x));try{localStorage.setItem('sl_invidious_instances_v1',JSON.stringify({at:Date.now(),items:list}));}catch(e){}let preferred='';try{preferred=localStorage.getItem('sl_invidious_working_v1')||'';}catch(e){}return preferred&&list.includes(preferred)?[preferred,...list.filter(x=>x!==preferred)]:list;
    })().finally(()=>{invidiousInstancesPromise=null;});return invidiousInstancesPromise;
  }
  async function playInvidious(item,startIndex=0){
    if(ytFallbackTimer){clearTimeout(ytFallbackTimer);ytFallbackTimer=null;}currentSource='invidious';try{if(ytPlayer&&typeof ytPlayer.destroy==='function')ytPlayer.destroy();}catch(e){}ytPlayer=null;window.__slYoutubePlayer=null;
    const status=player.querySelector('#yt-player-status'),wrap=player.querySelector('#yt-frame-wrap');if(status)status.textContent='Обновляю список Invidious и проверяю API…';if(wrap)wrap.innerHTML='<div class="yt-source-loading">Ищу рабочий Invidious-инстанс…</div>';
    try{let bases=await loadInvidiousInstances();if(!bases.length)throw new Error('Каталог Invidious не вернул доступные API');const offset=((Number(startIndex)||0)%bases.length+bases.length)%bases.length;bases=[...bases.slice(offset),...bases.slice(0,offset)];let found=null,last='';
      for(const base of bases.slice(0,12)){try{const ctrl=new AbortController(),timer=setTimeout(()=>ctrl.abort(),6500);const r=await fetch(base+'/api/v1/videos/'+encodeURIComponent(item.id)+'?fields=videoId,title,formatStreams',{mode:'cors',cache:'no-store',signal:ctrl.signal,headers:{Accept:'application/json'}});clearTimeout(timer);if(!r.ok)throw new Error('HTTP '+r.status);const d=await r.json();const streams=Array.isArray(d.formatStreams)?d.formatStreams:[];const chosen=streams.filter(x=>x&&x.url&&/^https:\/\//i.test(x.url)&&String(x.type||'').includes('video/mp4')).sort((a,b)=>parseInt(b.quality||'0',10)-parseInt(a.quality||'0',10))[0];if(!chosen)throw new Error('Нет совместимого MP4 потока');found={base,data:d,chosen};break;}catch(e){last=e&&e.message||String(e);}}
      if(!found)throw new Error(last||'Нет доступных потоков');try{localStorage.setItem('sl_invidious_working_v1',found.base);}catch(e){}if(wrap)wrap.innerHTML='<div id="playerjs-container" style="width:100%;height:100%;min-height:240px;background:#000"></div>';if(status)status.textContent='Источник: Invidious · '+new URL(found.base).host+' · PlayerJS';if(typeof mountSpaPlayer==='function')mountSpaPlayer(found.chosen.url,'mp4',item.title,{autoplay:true,muted:false,poster:item.thumbnail||''});watchExternalVideoError(wrap,()=>playInvidious(item,bases.indexOf(found.base)+1));
    }catch(e){if(status)status.textContent='Invidious недоступен: '+(e&&e.message||e)+'. Переключаюсь на Piped…';playPiped(item,0);}
  }
  function cobaltGetConfig(){let base='';try{base=String(localStorage.getItem('sl_cobalt_instance_v1')||'').trim();}catch(e){}let token='';try{token=String(sessionStorage.getItem('sl_cobalt_token_v1')||'').trim();}catch(e){}return {base:base.replace(/\/+$/,''),token};}
  async function configureCobalt(){
    const current=cobaltGetConfig();
    const entered=prompt('Адрес собственного Cobalt API (например https://cobalt.example.com). Публичный api.cobalt.tools не используется без разрешения владельца.',current.base||'');
    if(entered===null)return false;
    const base=String(entered).trim().replace(/\/+$/,'');
    if(!base){try{localStorage.removeItem('sl_cobalt_instance_v1');sessionStorage.removeItem('sl_cobalt_token_v1');}catch(e){}alert('Адрес Cobalt очищен.');return false;}
    let u;try{u=new URL(base);if(u.protocol!=='https:')throw new Error('Нужен HTTPS');}catch(e){alert('Укажи корректный HTTPS-адрес Cobalt API.');return false;}
    const token=prompt('API-Key (если владелец Cobalt выдал ключ). Оставь пустым, если авторизация не требуется.',current.token||'');if(token===null)return false;
    try{
      const ctrl=new AbortController(),timer=setTimeout(()=>ctrl.abort(),7000);
      const r=await fetch(base+'/',{method:'GET',mode:'cors',cache:'no-store',signal:ctrl.signal,headers:{Accept:'application/json'}});clearTimeout(timer);
      if(!r.ok)throw new Error('HTTP '+r.status);
      const info=await r.json();if(!info||!info.cobalt)throw new Error('Ответ не похож на Cobalt API');
      localStorage.setItem('sl_cobalt_instance_v1',base);
      if(token)sessionStorage.setItem('sl_cobalt_token_v1',token);else sessionStorage.removeItem('sl_cobalt_token_v1');
      alert('Cobalt подключён: '+(info.cobalt.version||'API доступен')+'.');if(sourceMode==='cobalt'&&currentVideo)playCobalt(currentVideo);return true;
    }catch(e){alert('Не удалось проверить Cobalt API: '+(e&&e.message||'ошибка сети')+'. Проверь адрес, доступность API и разрешение CORS для домена StreamLive.');return false;}
  }
  function watchExternalVideoError(wrap,onError){
    try{if(window.__slExternalVideoObserver)window.__slExternalVideoObserver.disconnect();}catch(e){}
    if(!wrap)return;
    const attach=()=>{const v=wrap.querySelector('video');if(v&&!v.dataset.slSourceErrorBound){v.dataset.slSourceErrorBound='1';v.addEventListener('error',()=>onError(v.error&&v.error.message||'ошибка медиапотока'),{once:true});}};
    const box=wrap.querySelector('#playerjs-container')||wrap;
    attach();if(window.MutationObserver){window.__slExternalVideoObserver=new MutationObserver(attach);window.__slExternalVideoObserver.observe(box,{childList:true,subtree:true});}
    setTimeout(attach,500);setTimeout(attach,1500);
  }
  async function requestPipedStreams(base,id){
    const ctrl=new AbortController(),timer=setTimeout(()=>ctrl.abort(),6500);
    try{
      const response=await fetch(base+'/streams/'+encodeURIComponent(id),{mode:'cors',cache:'no-store',signal:ctrl.signal,headers:{Accept:'application/json'}});
      if(!response.ok)throw new Error('HTTP '+response.status);
      const data=await response.json(),streams=Array.isArray(data.videoStreams)?data.videoStreams:[];
      const choices=streams.filter(x=>x&&x.url&&x.videoOnly!==true&&String(x.mimeType||'').toLowerCase().includes('video/mp4')).sort((a,b)=>Number(b.height||0)-Number(a.height||0));
      if(!choices.length)throw new Error('Нет совместимого MP4-потока');
      return {base,data,chosen:choices.find(x=>Number(x.height||0)<=1080)||choices[0]};
    }finally{clearTimeout(timer);}
  }
  async function firstWorkingPiped(item,bases){
    for(let i=0;i<bases.length;i+=4){
      const batch=bases.slice(i,i+4);if(!batch.length)continue;
      const controllers=[];let settled=false,remaining=batch.length;
      const winner=await new Promise(resolve=>{
        batch.forEach(base=>{
          const ctrl=new AbortController();controllers.push(ctrl);const timer=setTimeout(()=>ctrl.abort(),6500);
          fetch(base+'/streams/'+encodeURIComponent(item.id),{mode:'cors',cache:'no-store',signal:ctrl.signal,headers:{Accept:'application/json'}})
            .then(r=>{if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
            .then(data=>{const streams=Array.isArray(data.videoStreams)?data.videoStreams:[];const choices=streams.filter(x=>x&&x.url&&x.videoOnly!==true&&String(x.mimeType||'').toLowerCase().includes('video/mp4')).sort((a,b)=>Number(b.height||0)-Number(a.height||0));if(!choices.length)throw new Error('Нет MP4');if(!settled){settled=true;clearTimeout(timer);controllers.forEach(c=>{if(c!==ctrl)c.abort();});resolve({base,data,chosen:choices.find(x=>Number(x.height||0)<=1080)||choices[0]});}})
            .catch(()=>{clearTimeout(timer);remaining--;if(!remaining&&!settled)resolve(null);});
        });
      });
      if(winner)return winner;
    }
    return null;
  }
  async function playPiped(item,startIndex=0){
    if(ytFallbackTimer){clearTimeout(ytFallbackTimer);ytFallbackTimer=null;}currentSource='piped';try{if(ytPlayer&&typeof ytPlayer.destroy==='function')ytPlayer.destroy();}catch(e){}ytPlayer=null;window.__slYoutubePlayer=null;
    const status=player.querySelector('#yt-player-status'),wrap=player.querySelector('#yt-frame-wrap');if(status)status.textContent='Обновляю список Piped и проверяю несколько серверов одновременно…';if(wrap)wrap.innerHTML='<div class="yt-source-loading">Ищу доступный Piped-инстанс…</div>';
    let lastError='Нет доступных серверов';
    try{
      let bases=await loadPipedInstances();if(!bases.length)bases=pipedFallbackInstances.slice();
      const offset=((Number(startIndex)||0)%bases.length+bases.length)%bases.length;bases=[...bases.slice(offset),...bases.slice(0,offset)];
      const found=await firstWorkingPiped(item,bases);
      if(!found)throw new Error('Все найденные Piped-инстансы недоступны или не отдают совместимый MP4');
      const {base,data,chosen}=found;try{localStorage.setItem('sl_piped_working_v2',base);}catch(e){}
      if(wrap)wrap.innerHTML='<div id="playerjs-container" style="width:100%;height:100%;min-height:240px;background:#000"></div>';
      if(status)status.textContent='Источник: Piped · '+(data.uploader||item.channel)+' · '+(chosen.quality||((chosen.height||'')+'p'))+' · '+new URL(base).host+' · PlayerJS';
      if(wrap&&typeof mountSpaPlayer==='function')mountSpaPlayer(chosen.url,'mp4',item.title,{autoplay:true,muted:false,poster:item.thumbnail||''});
      watchExternalVideoError(wrap,()=>{if(status)status.textContent='Поток Piped оборвался. Ищу следующий рабочий сервер…';const next=bases.indexOf(base)+1;playPiped(item,next>=bases.length?0:next);});
      return;
    }catch(e){lastError=e&&e.message?e.message:String(e);}
    if(sourceMode==='auto'){
      const cobalt=cobaltGetConfig();if(cobalt.base){if(status)status.textContent='Piped недоступен. Пробую настроенный Cobalt…';playCobalt(item);}
      else{if(status)status.textContent='Piped недоступен ('+lastError+'). Переключаюсь на YouTube…';playYouTube(item);}
    }else{if(status)status.textContent='Piped не подключился: '+lastError+'. Обнови список или выбери другой источник.';if(wrap)wrap.innerHTML='<div class="yt-source-loading">Не найден рабочий Piped-инстанс. Попробуй ещё раз или переключи источник.</div>';}
  }
  function cobaltDirectMediaType(url,mime){
    const m=String(mime||'').toLowerCase(),u=String(url||'').toLowerCase();
    if(m.includes('mpegurl')||/\.m3u8(?:$|[?#])/.test(u))return 'hls';
    if(m.includes('webm')||/\.webm(?:$|[?#])/.test(u))return 'webm';
    if(m.includes('ogg')||/\.og[gv](?:$|[?#])/.test(u))return 'ogg';
    if(m.includes('mp4')||/\.(?:mp4|m4v|mov)(?:$|[?#])/.test(u))return 'mp4';
    // Cobalt tunnel URLs often have no extension; video requests are MP4 by default.
    return 'mp4';
  }
  function cobaltMountDirect(url,title,thumb,mime){
    const safe=playerSafeUrl(url);if(!safe||!/^https:\/\//i.test(safe))throw new Error('Cobalt вернул небезопасную или неподдерживаемую ссылку');
    const wrap=player.querySelector('#yt-frame-wrap');if(wrap)wrap.innerHTML='<div id="playerjs-container" style="width:100%;height:100%;min-height:240px;background:#000"></div>';
    const type=cobaltDirectMediaType(safe,mime);
    if(typeof mountSpaPlayer!=='function')throw new Error('PlayerJS не найден в приложении');
    mountSpaPlayer(safe,type,title||'Видео',{autoplay:true,muted:false,poster:thumb||''});
    watchExternalVideoError(wrap,()=>{const st=player.querySelector('#yt-player-status');if(st)st.textContent='Поток Cobalt недоступен. Попробуй обновить источник или выбрать Piped.';});
  }
  function cobaltShowPicker(items,item){
    const wrap=player.querySelector('#yt-frame-wrap'),status=player.querySelector('#yt-player-status');
    const videos=(Array.isArray(items)?items:[]).filter(x=>x&&x.type==='video'&&typeof x.url==='string'&&/^https:\/\//i.test(x.url));
    if(!videos.length)throw new Error('Cobalt вернул picker без совместимых видео');
    if(status)status.textContent='Cobalt · выбери прямой видеопоток ('+videos.length+')';
    if(wrap){wrap.innerHTML='<div class="cobalt-picker-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;padding:12px;max-height:100%;overflow:auto">'+videos.map((v,i)=>'<button type="button" class="cobalt-picker-item" data-cobalt-pick="'+i+'" style="display:flex;flex-direction:column;gap:7px;min-width:0;padding:8px;border:1px solid rgba(180,150,255,.28);border-radius:14px;background:rgba(40,30,65,.72);color:#fff;text-align:left;cursor:pointer">'+(v.thumb&&/^https:\/\//i.test(v.thumb)?'<img src="'+esc(v.thumb)+'" alt="" style="width:100%;aspect-ratio:16/9;object-fit:cover;border-radius:9px">':'<span style="display:grid;place-items:center;width:100%;aspect-ratio:16/9;background:#171322;border-radius:9px;font-size:28px">▶</span>')+'<span>Видео '+(i+1)+'</span></button>').join('')+'</div>';
      wrap.querySelectorAll('[data-cobalt-pick]').forEach(btn=>btn.addEventListener('click',()=>{const v=videos[Number(btn.getAttribute('data-cobalt-pick'))];try{cobaltMountDirect(v.url,item.title,v.thumb||item.thumbnail,v.mimeType||v.type);if(status)status.textContent='Источник: Cobalt · выбранный поток · PlayerJS';}catch(e){if(status)status.textContent='Ошибка запуска Cobalt: '+(e.message||e);}}));
    }
  }
  async function playCobalt(item){
    if(ytFallbackTimer){clearTimeout(ytFallbackTimer);ytFallbackTimer=null;}currentSource='cobalt';try{if(ytPlayer&&typeof ytPlayer.destroy==='function')ytPlayer.destroy();}catch(e){}ytPlayer=null;window.__slYoutubePlayer=null;
    const status=player.querySelector('#yt-player-status'),wrap=player.querySelector('#yt-frame-wrap');if(status)status.textContent='Подключаю Cobalt и запрашиваю прямой поток…';if(wrap)wrap.innerHTML='<div class="yt-source-loading">Получаю прямую ссылку Cobalt для PlayerJS…</div>';
    const cfg=cobaltGetConfig();if(!cfg.base){if(status)status.textContent='Сначала настрой собственный Cobalt API кнопкой «⚙ Cobalt».';if(wrap)wrap.innerHTML='<div class="yt-source-loading">Нужен адрес собственного Cobalt API. Введи адрес сервера Cobalt в настройках источника.</div>';return;}
    let timer=null;
    try{
      const headers={'Accept':'application/json','Content-Type':'application/json'};if(cfg.token)headers.Authorization='Api-Key '+cfg.token;
      const ctrl=new AbortController();timer=setTimeout(()=>ctrl.abort(),25000);
      const response=await fetch(cfg.base+'/',{method:'POST',mode:'cors',cache:'no-store',signal:ctrl.signal,headers,body:JSON.stringify({url:'https://www.youtube.com/watch?v='+encodeURIComponent(item.id),videoQuality:'1080',youtubeVideoCodec:'h264',youtubeVideoContainer:'mp4',downloadMode:'auto',filenameStyle:'basic',alwaysProxy:true,localProcessing:'disabled'})});
      const raw=await response.text();let data;try{data=JSON.parse(raw);}catch(_e){throw new Error('Cobalt API вернул не JSON (HTTP '+response.status+')');}
      if(!response.ok||!data||data.status==='error')throw new Error((data&&data.error&&data.error.code)||('HTTP '+response.status));
      if(data.status==='picker'){
        cobaltShowPicker(data.picker,item);return;
      }
      let mediaUrl='',mime=String(data.mimeType||data.type||'');
      if(data.status==='redirect'||data.status==='tunnel')mediaUrl=String(data.url||'');
      else if(data.status==='local-processing'){
        const tunnels=Array.isArray(data.tunnel)?data.tunnel:[];
        if(tunnels.length===1)mediaUrl=String(tunnels[0]||'');
        else if(tunnels.length>1)throw new Error('Cobalt вернул раздельные аудио и видео. Повтори запрос с отключённой локальной обработкой или используй другой Cobalt API.');
      }
      if(!mediaUrl)throw new Error('Cobalt вернул неподдерживаемый статус: '+String(data.status||'неизвестно'));
      cobaltMountDirect(mediaUrl,item.title,item.thumbnail,mime);
      if(status)status.textContent='Источник: Cobalt · '+(data.filename||item.title)+' · прямой поток через PlayerJS';
    }catch(e){const msg=e&&e.message?e.message:String(e);if(status)status.textContent='Ошибка Cobalt: '+msg+'. Проверь API, ключ и CORS или выбери Piped.';if(wrap)wrap.innerHTML='<div class="yt-source-loading">Не удалось получить прямой поток Cobalt: '+esc(msg)+'. Попробуй другой источник.</div>';}
    finally{if(timer)clearTimeout(timer);}
  }
  function playYouTube(item){
    if(ytFallbackTimer){clearTimeout(ytFallbackTimer);ytFallbackTimer=null;}currentSource='youtube';try{if(ytPlayer&&typeof ytPlayer.destroy==='function')ytPlayer.destroy();}catch(e){}try{if(window.__spaPlayerInstance&&typeof window.__spaPlayerInstance.destroy==='function')window.__spaPlayerInstance.destroy();window.__spaPlayerInstance=null;}catch(e){}try{if(window.__spaHls){window.__spaHls.destroy();window.__spaHls=null;}}catch(e){}ytPlayer=null;window.__slYoutubePlayer=null;
    const status=player.querySelector('#yt-player-status'),wrap=player.querySelector('#yt-frame-wrap');if(wrap){const origin=encodeURIComponent(location.origin);wrap.innerHTML=`<iframe id="yt-frame" src="https://www.youtube.com/embed/${item.id}?enablejsapi=1&origin=${origin}&autoplay=1&playsinline=1&rel=0" title="${esc(item.title)}" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>`;}
    if(status)status.textContent='Подключаю официальный YouTube-плеер…';
    ensureYouTubeIframeApi().then(()=>{const frame=player.querySelector('#yt-frame');if(!frame||!root.contains(frame)||!window.YT||!window.YT.Player)return;ytPlayer=new YT.Player('yt-frame',{events:{onReady:e=>{window.__slYoutubePlayer=ytPlayer;const f=e.target&&e.target.getIframe?e.target.getIframe():null;if(f)f.referrerPolicy='strict-origin-when-cross-origin';const st=player.querySelector('#yt-player-status');if(st)st.textContent='Источник: официальный YouTube. Если автозапуск заблокирован, нажми Play.';},onAutoplayBlocked:()=>{const st=player.querySelector('#yt-player-status');if(st)st.textContent='Автозапуск заблокирован браузером. Нажми Play.';},onStateChange:e=>{const st=player.querySelector('#yt-player-status');if(!st)return;if(e.data===YT.PlayerState.BUFFERING){st.textContent='YouTube: буферизация видео…';if(sourceMode==='auto'){if(ytFallbackTimer)clearTimeout(ytFallbackTimer);ytFallbackTimer=setTimeout(()=>{if(currentSource==='youtube'&&sourceMode==='auto'){st.textContent='YouTube долго не отвечает. Пробую Piped…';playPiped(item,0);}},16000);}}else if(e.data===YT.PlayerState.PLAYING){if(ytFallbackTimer){clearTimeout(ytFallbackTimer);ytFallbackTimer=null;}st.textContent='Источник: YouTube · видео воспроизводится';}else if(e.data===YT.PlayerState.PAUSED){if(ytFallbackTimer){clearTimeout(ytFallbackTimer);ytFallbackTimer=null;}st.textContent='YouTube: видео на паузе';}else if(e.data===YT.PlayerState.ENDED){if(ytFallbackTimer){clearTimeout(ytFallbackTimer);ytFallbackTimer=null;}st.textContent='YouTube: видео завершено';}},onError:e=>{const st=player.querySelector('#yt-player-status');const messages={2:'Некорректный идентификатор видео.',5:'Браузер не смог воспроизвести видео в HTML5-плеере.',100:'Видео удалено, скрыто или недоступно.',101:'Владелец видео запретил встроенное воспроизведение.',150:'Владелец видео запретил встроенное воспроизведение.',153:'YouTube не получил HTTP Referer или идентификатор клиента.'};if(sourceMode==='auto'&&[5,101,150,153].includes(e.data)){if(st)st.textContent='YouTube не запустился. Пробую Piped…';playPiped(item,0);return;}if(st)st.textContent=(messages[e.data]||'YouTube не смог запустить это видео.')+' Выбери другой источник или открой ролик непосредственно на YouTube.';}}});window.__slYoutubePlayer=ytPlayer;}).catch(e=>{if(sourceMode==='auto')playPiped(item,0);else{const st=player.querySelector('#yt-player-status');if(st)st.textContent=(e&&e.message?e.message:'Не удалось запустить YouTube Player API');}});
  }
  function renderResults(items,cached){const list=Array.isArray(items)?items:[],saved=getLocal(favKey);count.textContent=list.length?`${list.length} видео`:'';results.innerHTML=list.map(v=>`<article class="yt-card"><button type="button" class="yt-card-open" data-yt-id="${esc(v.id)}" style="display:block;width:100%;padding:0;border:0;background:transparent;color:inherit;text-align:left"><img class="yt-thumb" src="${esc(v.thumbnail||'')}" alt="" loading="lazy"><span class="yt-card-body"><span class="yt-channel-avatar">▶</span><span class="yt-card-copy"><span class="yt-card-title">${esc(v.title)}</span><span class="yt-card-meta">${esc(v.channel||'YouTube')}${v.description?' · '+esc(v.description):''}</span></span></span></button><div class="yt-card-actions"><button type="button" data-yt-vote="1">👍 <span class="yt-vote-count">0</span></button><button type="button" data-yt-vote="-1">👎 <span class="yt-vote-count">0</span></button><button type="button" data-yt-save="${esc(v.id)}">${saved.some(x=>x.id===v.id)?'✓ В закладках':'＋ В закладки'}</button></div></article>`).join('')||'<div class="yt-empty">Видео не найдены. Попробуй другой запрос или категорию.</div>';results.querySelectorAll('.yt-card-open').forEach(b=>b.onclick=()=>{const v=list.find(x=>x.id===b.dataset.ytId);if(v)play(v);});results.querySelectorAll('[data-yt-save]').forEach(b=>b.onclick=()=>{const v=list.find(x=>x.id===b.dataset.ytSave);if(v)toggleBookmark(v,b);});getBookmarks(list.map(x=>x.id)).then(a=>{const ids=new Set(a.map(x=>x.id));results.querySelectorAll('[data-yt-save]').forEach(b=>{const on=ids.has(b.dataset.ytSave);b.dataset.saved=on?'1':'0';b.textContent=on?'✓ В закладках':'＋ В закладки';});});results.querySelectorAll('.yt-card').forEach(card=>{const id=card.querySelector('[data-yt-id]')?.dataset.ytId;card.querySelectorAll('[data-yt-vote]').forEach(b=>b.onclick=()=>setReaction(id,Number(b.dataset.ytVote),card));});getReactions(list.map(x=>x.id)).then(all=>{results.querySelectorAll('.yt-card').forEach(card=>{const id=card.querySelector('[data-yt-id]')?.dataset.ytId;if(id)applyReactionUI(card,id,all[id]||{likes:0,dislikes:0,my_vote:Number(getLocalReacts()[id]||0)});});});if(cached&&quota)quota.textContent+=' · кэш';}
  async function ensureKey(){if(!serverKeyReady){const kd=await refreshServerYoutubeKey();serverHasKey=!!(kd&&kd.ok&&kd.has_key);serverKeyReady=true;}return youtubePersonalKey()||serverHasKey;}
  async function loadHome(category='',title='Рекомендации'){
    heading.textContent=title;results.innerHTML='<div class="yt-empty">Загружаю видео…</div>';count.textContent='';
    const key='sl_yt_home_v2:'+region+':'+category;
    try{const cached=JSON.parse(localStorage.getItem(key)||'null');if(cached&&cached.at&&Date.now()-cached.at<900000&&Array.isArray(cached.items)){renderResults(cached.items,true);return;}}catch(e){}
    if(!(await ensureKey())){results.innerHTML='<div class="yt-empty">Для ленты нужен YouTube API key. Добавь личный ключ в Настройки → YouTube Data API v3 или попроси владельца сервера подключить общий ключ.</div>';return;}
    let homeCharge=null;
    try {
      const charge=await api('youtube_search_charge',{method:'POST',body:{q:'__home__:'+String(category||'all')}});
      if(!charge||!charge.ok){results.innerHTML='<div class="yt-empty">'+esc(charge&&charge.error||'Дневной лимит YouTube исчерпан')+'</div>';return;}
      homeCharge=charge.charge_id;
      const data=await api('youtube_home',{method:'POST',body:{api_key:youtubePersonalKey(),region,category}});
      if(!data||!data.ok){if(homeCharge)await api('youtube_search_refund',{method:'POST',body:{charge_id:homeCharge}});results.innerHTML='<div class="yt-empty">Не удалось загрузить ленту: '+esc(data&&data.error||'ошибка YouTube API')+'</div>';return;}
      if(homeCharge)await api('youtube_search_commit',{method:'POST',body:{charge_id:homeCharge}});
      try{localStorage.setItem(key,JSON.stringify({at:Date.now(),items:data.items||[]}));}catch(e){}renderResults(data.items||[],false);
    } catch(err) {
      if(homeCharge)try{await api('youtube_search_refund',{method:'POST',body:{charge_id:homeCharge}});}catch(refundErr){}
      results.innerHTML='<div class="yt-empty">Не удалось загрузить ленту: '+esc(err&&err.message||'ошибка YouTube API')+'</div>';
    }
  }
  async function search(forced){const term=String(forced??q.value).trim();if(!term){q.focus();return;}if(searchBusy)return;q.value=term;const direct=(term.match(/(?:youtu\.be\/|youtube(?:-nocookie)?\.com\/(?:watch\?(?:[^#]*&)?v=|embed\/|shorts\/|live\/))([A-Za-z0-9_-]{11})/)||term.match(/^([A-Za-z0-9_-]{11})$/));if(direct){play({id:direct[1],title:'YouTube · '+direct[1],channel:'Открыто по ссылке'});return;}
    const cacheKey='sl_yt_search_cache_v2:'+term.toLocaleLowerCase();try{const cached=JSON.parse(localStorage.getItem(cacheKey)||'null');if(cached&&cached.at&&Date.now()-cached.at<900000&&Array.isArray(cached.items)){heading.textContent='Результаты поиска';renderResults(cached.items,true);return;}}catch(e){}
    searchBusy=true;go.disabled=true;go.textContent='…';results.innerHTML='<div class="yt-empty">Ищу видео…</div>';heading.textContent='Результаты поиска';
    try{if(!(await ensureKey())){results.innerHTML='<div class="yt-empty">YouTube API key не настроен. Добавь личный ключ в Настройки → YouTube Data API v3 или попроси владельца сервера подключить общий ключ.</div>';return;}
      const status=await api('youtube_quota_status');if(!status||!status.ok)throw new Error('Не удалось проверить лимит поиска YouTube');showQuota(status);if(!status.quota.subscription&&Number(status.quota.remaining)<=0)throw new Error('Дневной лимит поиска исчерпан. Лента и категории при этом доступны без лимита StreamLive.');
      const charge=await api('youtube_search_charge',{method:'POST',body:{q:term}});if(!charge||!charge.ok){if(charge&&charge.quota)showQuota(charge);throw new Error(charge&&charge.error||'Лимит поиска недоступен');}activeCharge=charge.charge_id;showQuota(charge);
      const data=await api('youtube_search',{method:'POST',body:{q:term,api_key:youtubePersonalKey(),charge_id:activeCharge}});if(!data||!data.ok)throw new Error(data&&data.error||'Сервер не смог выполнить поиск YouTube');activeCharge=null;
      const items=Array.isArray(data.items)?data.items:[];try{localStorage.setItem(cacheKey,JSON.stringify({at:Date.now(),items}));}catch(e){}renderResults(items,false);api('youtube_quota_status').then(qd=>{if(qd&&qd.ok)window.dispatchEvent(new CustomEvent('spa-youtube-quota-updated',{detail:qd.quota}));});
    }catch(e){if(activeCharge){try{await api('youtube_search_refund',{method:'POST',body:{charge_id:activeCharge}});}catch(x){}activeCharge=null;}results.innerHTML='<div class="yt-empty">Ошибка поиска: '+esc(e.message||e)+'</div>';}finally{searchBusy=false;go.disabled=false;go.textContent='⌕';}}
  const views={home:()=>loadHome('','Рекомендации'),popular:()=>loadHome('','Популярное'),music:()=>loadHome('10','Музыка'),gaming:()=>loadHome('20','Игры'),tech:()=>loadHome('28','Технологии'),trailers:()=>loadHome('1','Трейлеры и кино'),history:()=>{heading.textContent='История просмотров';renderResults(getLocal(histKey),false);},saved:async()=>{heading.textContent='Сохранённые видео';results.innerHTML='<div class="yt-empty">Загружаю закладки…</div>';const items=await getBookmarks();renderResults(items,false);}};
  root.querySelectorAll('[data-yt-view]').forEach(b=>b.onclick=()=>{root.querySelectorAll('[data-yt-view]').forEach(x=>x.classList.toggle('active',x===b));root.querySelectorAll('[data-yt-home]').forEach(x=>x.classList.remove('active'));const fn=views[b.dataset.ytView];if(fn)fn();});
  root.querySelectorAll('[data-yt-home]').forEach(b=>b.onclick=()=>{root.querySelectorAll('[data-yt-home]').forEach(x=>x.classList.toggle('active',x===b));root.querySelectorAll('[data-yt-view]').forEach(x=>x.classList.toggle('active',x.dataset.ytView==='home'));const id=b.dataset.ytHome||'';loadHome(id,id==='10'?'Музыка':id==='20'?'Игры':id==='28'?'Технологии':id==='1'?'Трейлеры и кино':'Рекомендации');});
  form.onsubmit=e=>{e.preventDefault();search();};q.onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();search();}};
  Promise.all([refreshServerYoutubeKey(),api('youtube_quota_status')]).then(([kd,qd])=>{serverKeyReady=true;serverHasKey=!!(kd&&kd.ok&&kd.has_key);if(qd&&qd.ok)showQuota(qd);});
  loadHome();
}


function openConverterCalculator(){destroySpaPlayer();setDetailTitle(spaAppTitle('calculator'));var root=showInPane(`<div class="sl-tool-card"><div class="val">🧮 ${slT('Калькулятор','Calculator','Калькулятор')}</div><input class="field" id="calc-a" type="number" placeholder="Первое число"><select class="field" id="calc-op"><option value="+">+</option><option value="-">−</option><option value="*">×</option><option value="/">÷</option></select><input class="field" id="calc-b" type="number" placeholder="Второе число"><button class="btn-primary" id="calc-go">Рассчитать</button><div class="val" id="calc-out">Результат: —</div></div><div class="sl-tool-card"><div class="val">${slT('Конвертер единиц','Unit converter','Конвертер одиниць')}</div><input class="field" id="conv-value" type="number" value="1"><select class="field" id="conv-type"><option value="km-mi">Километры → мили</option><option value="mi-km">Мили → километры</option><option value="kg-lb">Килограммы → фунты</option><option value="lb-kg">Фунты → килограммы</option><option value="c-f">°C → °F</option><option value="f-c">°F → °C</option><option value="gb-mb">ГБ → МБ</option><option value="mb-gb">МБ → ГБ</option></select><div class="val" id="conv-out">Результат: 1</div></div>`);root.querySelector('#calc-go').onclick=()=>{var a=Number(root.querySelector('#calc-a').value),b=Number(root.querySelector('#calc-b').value),o=root.querySelector('#calc-op').value,r=o==='+'?a+b:o==='-'?a-b:o==='*'?a*b:b===0?'Деление на ноль':a/b;root.querySelector('#calc-out').textContent='Результат: '+r;};function conv(){var v=Number(root.querySelector('#conv-value').value),t=root.querySelector('#conv-type').value,f={'km-mi':x=>x*.621371,'mi-km':x=>x/0.621371,'kg-lb':x=>x*2.20462,'lb-kg':x=>x/2.20462,'c-f':x=>x*9/5+32,'f-c':x=>(x-32)*5/9,'gb-mb':x=>x*1024,'mb-gb':x=>x/1024}[t];root.querySelector('#conv-out').textContent='Результат: '+(f?Number(f(v).toFixed(4)):v);}root.querySelector('#conv-value').oninput=conv;root.querySelector('#conv-type').onchange=conv;conv();}

function spaHomeTile(item, subtitle){
  const img=item.avatar||'';
  const pct=Math.max(0,Math.min(100,Number(item.progress||0)));
  return `<button type="button" class="home-tile" data-home-kind="${esc(item.kind||'')}" data-home-id="${esc(item.id||'')}" data-home-title="${esc(item.title||'')}" data-home-user="${esc(item.username||'')}" data-home-slug="${esc(item.slug||'')}" data-home-embed="${esc(item.embed_url||'')}" data-home-source="${esc(item.source_url||'')}" data-home-platform="${esc(item.platform||'')}">${img?`<img src="${esc(img)}" alt="" loading="lazy" onerror="this.style.display='none'">`:`<div style="height:76px;display:grid;place-items:center;font-size:27px;background:var(--card2);border-radius:9px;margin-bottom:8px">${item.kind==='channel'?'📺':item.kind==='user'?'👤':'▶'}</div>`}<div class="ht-title">${esc(item.title||'Без названия')}</div><div class="ht-sub">${esc(subtitle||item.subtitle||item.kind||'')}</div>${item.kind==='video'&&pct>0?`<div style="height:4px;background:rgba(255,255,255,.12);border-radius:9px;overflow:hidden;margin-top:7px"><i style="display:block;height:100%;width:${pct}%;background:var(--accent);border-radius:9px"></i></div><div style="font-size:10px;color:var(--muted);margin-top:3px">${pct.toFixed(0)}% просмотрено</div>`:''}</button>`;
}
function spaHomeBindTiles(root){
  if(!root)return;
  root.querySelectorAll('[data-home-kind]').forEach(b=>b.onclick=()=>{if(b.dataset.homeKind==='cinema'){openCinemaFilm(b.dataset.homeId);return;}openItem({kind:b.dataset.homeKind,id:b.dataset.homeId,title:b.dataset.homeTitle,username:b.dataset.homeUser,slug:b.dataset.homeSlug,embed_url:b.dataset.homeEmbed,source_url:b.dataset.homeSource,platform:b.dataset.homePlatform});});
}
async function loadSmartHome(){
  const feed=$('home-feed'); if(!feed)return;
  let cont=continueLoad(), recent=spaWatchHistGet(), cinemaAllowed=await spaCinemaGate(false);
  try{const [c,h]=await Promise.all([api('media_continue_list'),api('media_history_list')]);if(c&&c.ok&&Array.isArray(c.items)){const seen=new Set();cont=[...c.items,...cont].filter(x=>{const k=x.kind+':'+x.id;if(seen.has(k))return false;seen.add(k);return true;});continueSave(cont);if(c.cinema_locked)cinemaAllowed=false;}if(h&&h.ok&&Array.isArray(h.items))recent=h.items.map(x=>({kind:x.kind,id:x.id,title:x.title,ts:Number(x.ts)}));}catch(e){}
  if (!cinemaAllowed) { cont=cont.filter(x=>x.kind!=='cinema'); recent=recent.filter(x=>x.kind!=='cinema'); }
  cont=cont.slice(0,8);recent=recent.slice(0,8);
  const ce=$('home-continue'), re=$('home-recent');
  ce.innerHTML=cont.length?cont.map(x=>spaHomeTile(x,(x.kind||'')+' · продолжить')).join(''):'<div class="home-empty">Открывай видео, каналы, фильмы и сериалы — они появятся здесь</div>';
  re.innerHTML=recent.length?recent.map(x=>spaHomeTile({kind:x.kind,id:x.id,title:x.title},new Date(x.ts).toLocaleString('ru-RU',{hour:'2-digit',minute:'2-digit'}))).join(''):'<div class="home-empty">История просмотров пока пустая</div>';
  spaHomeBindTiles(ce); spaHomeBindTiles(re);
  const apps=$('home-apps');
  if(apps){ apps.innerHTML=SPA_HOME_APPS.slice(0,6).map(a=>`<button type="button" class="app-tile" data-app-action="${a[0]}"><span class="app-ico">${a[1]}</span><b>${spaAppTitle(a[2])}</b></button>`).join('')+'<button type="button" class="app-tile" data-app-action="apps"><span class="app-ico">＋</span><b>Все приложения</b></button>'; apps.querySelectorAll('[data-app-action]').forEach(b=>b.onclick=()=>spaRunHomeAction(b.dataset.appAction)); }
  const ne=$('home-new');
  try{
    let cached=null;
    try{const c=JSON.parse(localStorage.getItem(SPA_HOME_NEW_CACHE_KEY)||'null');if(c&&Array.isArray(c.items)&&Date.now()-c.at<5*60*1000)cached=c.items;}catch(e){}
    let items=cached;
    if(!items){const d=await api('catalog',{params:{type:'all',q:''}});items=(d&&d.ok&&Array.isArray(d.items)?d.items:[]).filter(x=>x&&x.kind!=='user').slice(0,8);try{localStorage.setItem(SPA_HOME_NEW_CACHE_KEY,JSON.stringify({at:Date.now(),items}));}catch(e){}}
    ne.innerHTML=items.length?items.map(x=>spaHomeTile(x,x.kind==='channel'?'Новый канал':'Новая публикация')).join(''):'<div class="home-empty">Пока нет новых публикаций</div>';
    spaHomeBindTiles(ne);
  }catch(e){ne.innerHTML='<div class="home-empty">Не удалось загрузить новинки. Попробуй обновить каталог.</div>';}
  feed.querySelectorAll('[data-home-go]').forEach(b=>b.onclick=()=>spaRunHomeAction(b.dataset.homeGo));
}

function kpApiKey() {
  spaMigrateLegacyApiKeys();
  // A remembered preference must not strand the user after sessionStorage expires.
  if (localStorage.getItem('spa_kp_use_personal') === '1' && spaKinopoiskPersonalApiKey) return spaKinopoiskPersonalApiKey;
  return spaServerKpHasKey ? 'server-key-configured' : '';
}
async function refreshServerKpKey() {
  try {
    const d = await api('server_kp_get');
    spaServerKpHasKey = !!(d && d.ok && d.has_key);
    return d;
  } catch (e) { spaServerKpHasKey=false; return null; }
}
async function kpFetch(path, params) {
  spaMigrateLegacyApiKeys();
  const usePersonal = localStorage.getItem('spa_kp_use_personal') === '1' && !!spaKinopoiskPersonalApiKey;
  const personal = usePersonal ? spaKinopoiskPersonalApiKey : '';
  if (!usePersonal && !spaServerKpHasKey) throw new Error('Нет доступного ключа Кинопоиска. Добавь личный ключ или попроси владельца сервера настроить общий.');
  const r = await api('kp_proxy', {method:'POST', body:{path:String(path||''),params:params||{},api_key:personal}});
  if (!r || !r.ok) throw new Error((r&&r.error)||'Ошибка прокси Кинопоиска');
  return r.data;
}
function kpPoster(f) {
  return f.posterUrlPreview || f.posterUrl || f.coverUrl || '';
}
function kpTitle(f) {
  return f.nameRu || f.nameOriginal || f.nameEn || ('id ' + (f.kinopoiskId || f.filmId || f.filmId));
}
function kinoboxEmbedCandidates(kpId, isSeries) {
  const id=encodeURIComponent(String(kpId||''));
  if(!/^\d{1,10}$/.test(id)) return [];
  return [
    'https://kinoboxtv.tatnet.app/embed/#'+id,
    'https://kinoplayertop.tatnet.app/embed/#'+id,
    'https://kinobd.net/embed/'+id
  ];
}
function cinemaWatchPlayerHtml(kpId,isSeries){
  const candidates=kinoboxEmbedCandidates(kpId,isSeries);
  if(!candidates.length)return '';
  return `<section class="card" id="cinema-watch-card"><div class="val">▶ Смотреть онлайн</div><p class="sub" style="margin:6px 0 10px">Встроенный плеер · можно переключить источник, если первый не открылся</p><div class="seg" id="cinema-watch-tabs" style="display:flex;gap:6px;flex-wrap:wrap;margin:8px 0"><button type="button" data-watch-source="0" class="on">Kinobox</button><button type="button" data-watch-source="1">KinoplayerTop</button><button type="button" data-watch-source="2">KinoBD</button></div><div class="player-box" style="height:clamp(220px,56vw,520px);max-height:65vh;background:#000;border-radius:12px;overflow:hidden"><iframe id="cinema-watch-frame" title="Видеоплеер" src="" loading="lazy" allowfullscreen allow="autoplay;encrypted-media;fullscreen;picture-in-picture" referrerpolicy="strict-origin-when-cross-origin" style="border:0;width:100%;height:100%;background:#000"></iframe></div><div class="sub" id="cinema-watch-status" style="margin-top:7px">Источник: Kinobox</div></section>`;
}
function cinemaBindWatchPlayer(kpId,isSeries){
  const candidates=kinoboxEmbedCandidates(kpId,isSeries);if(!candidates.length)return;
  const frame=document.getElementById('cinema-watch-frame'),status=document.getElementById('cinema-watch-status');
  const buttons=document.querySelectorAll('[data-watch-source]');
  const names=['Kinobox','KinoplayerTop','KinoBD'];
  const select=(index)=>{const i=Math.max(0,Math.min(candidates.length-1,Number(index)||0));if(frame)frame.src=candidates[i];buttons.forEach(b=>b.classList.toggle('on',Number(b.dataset.watchSource)===i));if(status)status.textContent='Источник: '+names[i];};
  buttons.forEach(b=>b.onclick=()=>select(b.dataset.watchSource));select(0);
}
async function cinemaFindKpIdByTitle(title,year){
  if(!kpApiKey())return null;
  try{
    const d=await kpFetch('/api/v2.1/films/search-by-keyword',{keyword:String(title||'').slice(0,100),page:'1'});
    const items=Array.isArray(d.films)?d.films:(Array.isArray(d.items)?d.items:[]);if(!items.length)return null;
    const norm=v=>String(v||'').toLowerCase().replace(/[ё]/g,'е').replace(/[^a-zа-я0-9]+/gi,' ').trim();
    const target=norm(title),y=String(year||'').slice(0,4);
    const exact=items.filter(x=>[x.nameRu,x.nameEn,x.nameOriginal].some(v=>norm(v)===target));
    const pool=exact.length?exact:items.filter(x=>y&&String(x.year||'')===y&&[x.nameRu,x.nameEn,x.nameOriginal].some(v=>{const n=norm(v);return n===target||(n&&target&&(n.includes(target)||target.includes(n)));}));
    if(!pool.length)return null;const best=pool.find(x=>!y||String(x.year||'')===y)||pool[0];
    const id=best.filmId||best.kinopoiskId;if(!id)return null;
    const t=String(best.type||best.typeName||'').toUpperCase();return {id:String(id),isSeries:/SERIES|SERIAL|TV_SHOW/.test(t)};
  }catch(e){return null;}
}

async function spaCinemaGate(showMessage=true) {
  try {
    const d = await api('limits_status');
    const q = d && d.ok && d.limits ? d.limits.cinema : null;
    if (q && (q.limited || Number(q.remaining) <= 0)) {
      if (showMessage) showLimit('Лимит фильмов и сериалов исчерпан. Каталог, карточки и просмотр снова откроются после окончания 24-часовой паузы.', q.retry_at || null, q.retry_seconds);
      return false;
    }
    return true;
  } catch (e) {
    // Fail closed for cinema entry when the quota service cannot be checked.
    if (showMessage) showLimit('Не удалось проверить лимит фильмов и сериалов. Обнови страницу и попробуй снова.');
    return false;
  }
}

async function openCinema() {
  destroySpaPlayer();
  if (!(await spaCinemaGate(true))) return;
  setDetailTitle('Фильмы и сериалы');
  try { spaQuestDone('open_cinema'); } catch(e) {}
  await refreshServerKpKey();
  spaMigrateLegacyApiKeys();
  const key = kpApiKey();
  const tmdbServerConfig = await api('server_tmdb_get');
  const tmdbReady = !!(tmdbServerConfig && tmdbServerConfig.ok && tmdbServerConfig.has_key);
  const imdbServerConfig = await api('server_imdb_get');
  const imdbReady = !!(imdbServerConfig && imdbServerConfig.ok && imdbServerConfig.has_key && imdbServerConfig.enabled);
  window.spaCinemaImdbEnabled = imdbReady;
  const usePersonal = localStorage.getItem('spa_kp_use_personal') === '1' && !!spaKinopoiskPersonalApiKey;
  showInPane(`
    <div class="card">
      <div class="val">🎬 Фильмы и сериалы</div>
      <p style="font-size:13px;color:var(--muted);margin:8px 0 0">Кинопоиск · IMDb (если включён) · TMDB · актёры · сезоны · трейлеры</p>
      <div style="display:flex;gap:8px;margin-top:8px"><button type="button" class="btn-primary" id="kp-my-list" style="margin:0;flex:1;background:rgba(255,255,255,.08)">⭐ Мой список</button><button type="button" class="btn-primary" id="kp-all-favs" style="margin:0;flex:1;background:rgba(255,255,255,.08)">Избранное</button></div>
      <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
        <button type="button" class="btn-primary" id="kp-use-server" style="margin:0;flex:1;font-size:12px;background:${usePersonal?'rgba(255,255,255,.08)':'linear-gradient(135deg,#6d5efc,#8b5cf6)'}">Ключ сервера</button>
        <button type="button" class="btn-primary" id="kp-use-mine" style="margin:0;flex:1;font-size:12px;background:${usePersonal?'linear-gradient(135deg,#6d5efc,#8b5cf6)':'rgba(255,255,255,.08)'}">Свой ключ</button>
      </div>
      <input class="field" id="kp-q" placeholder="Поиск / ID КП" style="margin-top:10px">
      <div class="seg" id="kp-tabs" style="margin-top:10px">
        <button type="button" data-t="TOP_FILM" class="on">Топ фильмы</button>
        <button type="button" data-t="TOP_TV">Топ сериалы</button>
        <button type="button" data-t="search">Поиск</button>
      </div>
    </div>
    <div id="kp-list" class="empty">Загрузка…</div>
  `);
  if ($('kp-my-list')) $('kp-my-list').onclick=()=>openCinemaMyList();
  if ($('kp-all-favs')) $('kp-all-favs').onclick=()=>openFavorites();
  if ($('kp-use-server')) $('kp-use-server').onclick = async () => {
    localStorage.setItem('spa_kp_use_personal', '0');
    await refreshServerKpKey();
    if (!kpApiKey()) alert('У сервера нет общего ключа. Владелец задаёт в сервере.');
    openCinema();
  };
  if ($('kp-use-mine')) $('kp-use-mine').onclick = () => {
    localStorage.setItem('spa_kp_use_personal', '1');
    if (!spaKinopoiskPersonalApiKey) { showInPane('<div class="card"><b>Личный ключ Кинопоиска</b><input class="field" id="kp-key-in" type="password" autocomplete="off" placeholder="X-API-KEY"><button class="btn-primary" id="kp-key-save">Использовать ключ</button></div>'); $('kp-key-save').onclick=()=>{spaKinopoiskPersonalApiKey=($('kp-key-in').value||'').trim();if(spaKinopoiskPersonalApiKey){spaWriteSessionKey(SPA_KP_SESSION_KEY,spaKinopoiskPersonalApiKey);localStorage.setItem('spa_kp_use_personal','1');openCinema();}}; return; }
    openCinema();
  };

  let mode = 'TOP_FILM';
  const loadTop = async (type) => {
    const box = $('kp-list');
    if(!kpApiKey()){box.innerHTML='<div class="empty">Кинопоиск API key не задан. Используй поиск ниже — результаты будут загружены из TMDB.</div>';return;}
    box.innerHTML = '<div class="empty">Загрузка КП…</div>';
    try {
      // v2.2 films collections / filters
      let data;
      if (type === 'TOP_TV') {
        data = await kpFetch('/api/v2.2/films', { order: 'NUM_VOTE', type: 'TV_SERIES', page: '1' });
      } else {
        data = await kpFetch('/api/v2.2/films', { order: 'NUM_VOTE', type: 'FILM', page: '1' });
      }
      let items = data.items || data.films || [];
      items = items.filter(f => {
          const blob = kpTitle(f) + ' ' + (f.ratingMpaa||'') + ' ' + ((f.genres||[]).map(g=>g.genre||g).join(' ')) + ' ' + (f.ratingAgeLimits||'');
          return parentalAllowsText(blob, f.kinopoiskId || f.filmId);
        });
      if (!items.length) { box.innerHTML = '<div class="empty">Пусто / скрыто фильтром профиля</div>'; return; }
      box.innerHTML = items.map(f => {
        const id = f.kinopoiskId || f.filmId;
        const poster = kpPoster(f);
        const year = f.year || '';
        const rating = (f.ratingKinopoisk != null ? f.ratingKinopoisk : (f.rating || ''));
        return `<div class="row" data-kpid="${id}">
          ${poster ? `<img class="av" src="${esc(poster)}" alt="" style="border-radius:8px" onerror="this.style.display='none'">` : `<div class="av letter">🎬</div>`}
          <div class="body"><div class="title">${esc(kpTitle(f))} ${ytAgeBadgeHtml(analyzeContentRatingSafe(f))}</div>
          <div class="sub">${year ? year + ' · ' : ''}★ ${rating} · КП ${id}</div></div>
        </div>`;
      }).join('');
      box.querySelectorAll('[data-kpid]').forEach(r => {
        r.onclick = () => openCinemaFilm(r.dataset.kpid);
      });
    } catch (e) {
      box.innerHTML = '<div class="empty">' + esc(String(e.message || e)) + '</div>';
    }
  };

  const loadSearch = async (q) => {
    const box = $('kp-list');
    if (!q) { box.innerHTML = '<div class="empty">Введи запрос</div>'; return; }
    box.innerHTML = '<div class="empty">Ищу в Кинопоиске, TMDB и IMDb…</div>';
    try {
      if (/^\d+$/.test(q)) { openCinemaFilm(q); return; }
      const [kpResult,tmdbResult,imdbResult]=await Promise.allSettled([
        kpApiKey()?kpFetch('/api/v2.1/films/search-by-keyword',{keyword:q,page:'1'}):Promise.resolve({films:[]}),
        api('tmdb_search',{method:'POST',body:{query:q}}),
        (window.spaCinemaImdbEnabled?api('imdb_search',{method:'POST',body:{query:q}}):Promise.resolve({ok:false,disabled:true}))
      ]);
      const kpData=kpResult.status==='fulfilled'?kpResult.value:null;
      const kpItems=kpData?(kpData.films||kpData.items||[]):[];
      const tmdbData=tmdbResult.status==='fulfilled'?tmdbResult.value:null;
      const tmdbItems=tmdbData&&tmdbData.ok&&tmdbData.data&&Array.isArray(tmdbData.data.results)?tmdbData.data.results.filter(x=>['movie','tv'].includes(x.media_type)):[];
      const imdbData=imdbResult.status==='fulfilled'?imdbResult.value:null;
      const imdbItems=imdbData&&imdbData.ok&&imdbData.data&&Array.isArray(imdbData.data.Search)?imdbData.data.Search:[];
      const kpHtml=kpItems.slice(0,15).map(f=>{
        const id=f.filmId||f.kinopoiskId,poster=kpPoster(f),title=kpTitle(f);
        return `<div class="row" data-kpid="${esc(id)}">${poster?`<img class="av" src="${esc(poster)}" alt="" style="border-radius:8px">`:`<div class="av letter">🎬</div>`}<div class="body"><div class="title">${esc(title)} ${ytAgeBadgeHtml(analyzeContentRatingSafe(f))}</div><div class="sub">Кинопоиск · ${esc(String(f.year||''))} · ★ ${esc(String(f.rating||'—'))} · КП ${esc(String(id))}</div></div></div>`;
      }).join('');
      const tmdbHtml=tmdbItems.slice(0,15).map(f=>{
        const id=Number(f.id),title=String(f.title||f.name||'Без названия'),year=String((f.release_date||f.first_air_date||'').slice(0,4));
        const poster=f.poster_path?'https://image.tmdb.org/t/p/w185'+f.poster_path:'';
        return `<div class="row" data-tmdb-id="${id}" data-tmdb-type="${esc(f.media_type)}">${poster?`<img class="av" src="${esc(poster)}" alt="" style="border-radius:8px" loading="lazy">`:`<div class="av letter">🎞️</div>`}<div class="body"><div class="title">${esc(title)}</div><div class="sub">TMDB · ${f.media_type==='tv'?'Сериал':'Фильм'} · ${esc(year)} · ★ ${Number(f.vote_average||0).toFixed(1)}</div></div></div>`;
      }).join('');
      const imdbHtml=imdbItems.slice(0,15).map(f=>{const id=String(f.imdbID||''),poster=f.Poster&&f.Poster!=='N/A'?String(f.Poster):'';return `<div class="row" data-imdb-id="${esc(id)}">${poster?`<img class="av" src="${esc(poster)}" alt="" style="border-radius:8px" loading="lazy">`:`<div class="av letter">⭐</div>`}<div class="body"><div class="title">${esc(f.Title||'Без названия')}</div><div class="sub">IMDb · ${esc(f.Type==='series'?'Сериал':f.Type==='movie'?'Фильм':'Видео')} · ${esc(f.Year||'')} · ${esc(id)}</div></div></div>`;}).join('');
      const tmdbNote=tmdbData&&tmdbData.ok?'':'<div class="sub" style="margin:8px 0;color:var(--muted)">TMDB пока не подключён или лимит исчерпан. Владелец сервера может добавить TMDB API key в настройках сервера.</div>';
      const imdbNote=window.spaCinemaImdbEnabled&&!(imdbData&&imdbData.ok)?'<div class="sub" style="margin:8px 0;color:var(--muted)">IMDb временно недоступен. Проверь ключ OMDb и дневную квоту.</div>':'';
      box.innerHTML=(kpHtml?'<div class="label" style="padding:8px 4px">Кинопоиск</div>'+kpHtml:'')+(tmdbHtml?'<div class="label" style="padding:12px 4px 8px">TMDB</div>'+tmdbHtml:'')+tmdbNote+(window.spaCinemaImdbEnabled&&imdbHtml?'<div class="label" style="padding:12px 4px 8px">IMDb</div>'+imdbHtml:'')+imdbNote+(!kpHtml&&!tmdbHtml&&!imdbHtml?'<div class="empty">Ничего не найдено</div>':'');
      box.querySelectorAll('[data-kpid]').forEach(r=>r.onclick=()=>openCinemaFilm(r.dataset.kpid));
      box.querySelectorAll('[data-tmdb-id]').forEach(r=>r.onclick=()=>openTmdbFilm(r.dataset.tmdbId,r.dataset.tmdbType));
      if(window.spaCinemaImdbEnabled)box.querySelectorAll('[data-imdb-id]').forEach(r=>r.onclick=()=>openImdbFilm(r.dataset.imdbId));
      if(kpResult.status==='rejected'&&!tmdbHtml)throw kpResult.reason;
    } catch (e) {
      box.innerHTML = '<div class="empty">' + esc(String(e.message || e)) + '</div>';
    }
  };

  $('kp-tabs').querySelectorAll('button').forEach(b => {
    b.onclick = () => {
      $('kp-tabs').querySelectorAll('button').forEach(x => x.classList.remove('on'));
      b.classList.add('on');
      mode = b.dataset.t;
      if (mode === 'search') loadSearch(($('kp-q').value || '').trim());
      else loadTop(mode);
    };
  });
  $('kp-q').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      mode='search';
      $('kp-tabs').querySelectorAll('button').forEach(x => x.classList.toggle('on', x.dataset.t === mode));
      loadSearch(($('kp-q').value || '').trim());
    }
  });
  await loadTop('TOP_FILM');
}

async function openTmdbFilm(tmdbId,mediaType){
  destroySpaPlayer();setDetailTitle('TMDB · карточка');
  const box=showInPane('<div class="empty">Загружаю карточку TMDB…</div>');
  try{
    const response=await api('tmdb_details',{method:'POST',body:{tmdb_id:tmdbId,media_type:mediaType==='tv'?'tv':'movie'}});
    if(!response||!response.ok)throw new Error(response&&response.error||'Не удалось загрузить карточку TMDB');
    const d=response.data||{},title=String(d.title||d.name||'Без названия'),poster=d.poster_path?'https://image.tmdb.org/t/p/w500'+d.poster_path:'',backdrop=d.backdrop_path?'https://image.tmdb.org/t/p/w1280'+d.backdrop_path:'';
    const date=String(d.release_date||d.first_air_date||'');const cast=Array.isArray(d.credits&&d.credits.cast)?d.credits.cast.slice(0,10):[];
    const trailer=(d.videos&&Array.isArray(d.videos.results)?d.videos.results:[]).find(v=>v.site==='YouTube'&&v.key&&['Trailer','Teaser'].includes(v.type));
    const genres=Array.isArray(d.genres)?d.genres.map(x=>x.name).join(' · '):'';const kpMatch=await cinemaFindKpIdByTitle(title,String(date||'').slice(0,4));const watch=kpMatch?cinemaWatchPlayerHtml(kpMatch.id,kpMatch.isSeries):'<section class="card"><div class="val">▶ Kinobox</div><p class="sub">Не удалось сопоставить эту карточку TMDB с Кинопоиском. Плеер не будет открывать случайное видео.</p></section>';
    box.innerHTML=`<div class="card">${backdrop?`<img src="${esc(backdrop)}" alt="" style="width:100%;max-height:220px;object-fit:cover;border-radius:14px" loading="lazy">`:''}<div style="display:flex;gap:14px;margin-top:12px;align-items:flex-start">${poster?`<img src="${esc(poster)}" alt="" style="width:112px;max-width:34%;border-radius:12px" loading="lazy">`:''}<div style="min-width:0"><h2 style="margin:0 0 6px">${esc(title)}</h2><div class="sub">TMDB · ${mediaType==='tv'?'Сериал':'Фильм'} · ${esc(date.slice(0,4))} · ★ ${Number(d.vote_average||0).toFixed(1)}</div><div class="sub" style="margin-top:6px">${esc(genres)}</div></div></div><p style="white-space:pre-wrap;line-height:1.55">${esc(d.overview||'Описание отсутствует.')}</p>${trailer?`<div style="aspect-ratio:16/9"><iframe src="https://www.youtube.com/embed/${encodeURIComponent(trailer.key)}" title="Трейлер ${esc(title)}" allow="accelerometer; autoplay; encrypted-media; picture-in-picture" allowfullscreen style="width:100%;height:100%;border:0;border-radius:12px"></iframe></div>`:''}<div class="label" style="margin-top:14px">В ролях</div><div class="sub">${cast.length?cast.map(x=>esc(x.name||'')).join(' · '):'Информация об актёрах отсутствует'}</div><div class="sub" style="margin-top:12px">Карточка и метаданные предоставлены TMDB. TMDB не предоставляет сам видеопоток для просмотра.</div></div>${watch}`;if(kpMatch)cinemaBindWatchPlayer(kpMatch.id,kpMatch.isSeries);
  }catch(e){box.innerHTML='<div class="empty">'+esc(String(e&&e.message||e))+'</div>';}
}

function cinemaStaffCard(a){
  const nm=a.nameRu||a.nameEn||'Участник';const pic=a.posterUrl||a.posterUrlPreview||'';
  const image=pic?'<img src="'+esc(pic)+'" alt="" loading="lazy" style="width:88px;height:112px;object-fit:cover;border-radius:12px;background:var(--card2)" onerror="this.style.display=\'none\'">':'<div style="width:88px;height:112px;border-radius:12px;background:var(--card2);display:grid;place-items:center;margin:auto">👤</div>';
  return '<button type="button" data-staff-id="'+esc(a.staffId||'')+'" style="flex:0 0 104px;max-width:104px;border:0;background:transparent;color:inherit;text-align:center;padding:0;cursor:pointer">'+image+'<b style="display:block;font-size:12px;margin-top:6px;line-height:1.25">'+esc(nm)+'</b><small style="display:block;color:var(--muted);font-size:10px;margin-top:3px">'+esc(a.description||a.professionText||'')+'</small></button>';
}
function cinemaEpisodeCard(ep){return '<div class="sl-vault-row"><b>'+esc(String(ep.episodeNumber||''))+'. '+esc(ep.nameRu||ep.nameEn||'Серия')+'</b><small>'+esc(ep.releaseDate||'')+(ep.synopsis?' · '+esc(ep.synopsis):'')+'</small></div>';}
function cinemaSeasonCard(se){const episodes=Array.isArray(se.episodes)?se.episodes:[];return '<details style="padding:8px 0;border-bottom:1px solid var(--line)"><summary style="cursor:pointer;font-weight:700">'+esc(se.name||('Сезон '+(se.number||'')))+' · '+episodes.length+' сер.</summary><div style="padding-top:8px">'+(episodes.length?episodes.map(cinemaEpisodeCard).join(''):'<small>Информация о сериях пока отсутствует</small>')+'</div></details>';}


async function openImdbFilm(imdbId){
  const id=String(imdbId||'');if(!/^tt[0-9]{5,12}$/.test(id)||!window.spaCinemaImdbEnabled)return;setDetailTitle('IMDb');showInPane('<div class="empty">Загружаю карточку IMDb…</div>');
  try{const r=await api('imdb_details',{method:'POST',body:{imdb_id:id}});if(!r||!r.ok)throw new Error(r&&r.error||'Не удалось загрузить IMDb');const f=r.data||{},poster=f.Poster&&f.Poster!=='N/A'?f.Poster:'',ratings=Array.isArray(f.Ratings)?f.Ratings:[],plot=String(f.Plot||'Описание недоступно'),title=String(f.Title||'Без названия');const kpMatch=await cinemaFindKpIdByTitle(title,String(f.Year||'').slice(0,4));const watch=kpMatch?cinemaWatchPlayerHtml(kpMatch.id,kpMatch.isSeries):'<section class="card"><div class="val">▶ Kinobox</div><p class="sub">Не удалось сопоставить IMDb ID с карточкой Кинопоиска. Плеер не будет открывать случайный фильм.</p></section>';const root=showInPane(`<div class="card"><div style="display:flex;gap:13px;align-items:flex-start">${poster?`<img src="${esc(poster)}" alt="" style="width:112px;max-height:168px;object-fit:cover;border-radius:12px">`:'<div class="av letter">⭐</div>'}<div class="body"><div class="val">${esc(title)}</div><div class="sub">${esc(f.Year||'')} · ${esc(f.Type||'')} · ${esc(f.Runtime||'')}</div><div style="font-size:14px;margin-top:7px">⭐ IMDb ${esc(f.imdbRating||'—')} · ${esc(f.imdbVotes||'')} голосов</div><div class="sub">IMDb ID: ${esc(id)}</div></div></div><p style="font-size:13px;line-height:1.55">${esc(plot)}</p><div class="sub">${esc(f.Genre||'')} · ${esc(f.Director||'')} · ${esc(f.Actors||'')}</div>${ratings.map(x=>`<div class="sl-vault-row"><b>${esc(x.Source)}</b><span>${esc(x.Value)}</span></div>`).join('')}<a href="https://www.imdb.com/title/${encodeURIComponent(id)}/" target="_blank" rel="noopener noreferrer">Открыть на IMDb ↗</a></div>${watch}<button type="button" class="btn-primary" id="imdb-back" style="background:rgba(255,255,255,.08)">← Назад к поиску</button>`);if(kpMatch)cinemaBindWatchPlayer(kpMatch.id,kpMatch.isSeries);const back=root.querySelector('#imdb-back');if(back)back.onclick=()=>openCinema();return root;}catch(e){showInPane('<div class="empty">'+esc(String(e&&e.message||e))+'</div>');}
}

async function openCinemaFilm(kpId) {
  destroySpaPlayer();
  if (!(await spaCinemaGate(true))) return;
  setDetailTitle('Фильм');
  try {
    spaAchUnlock('cinema_first', 'Киноман');
    const c = parseInt(localStorage.getItem('spa_cinema_opens')||'0',10)+1;
    localStorage.setItem('spa_cinema_opens', String(c));
    if (c >= 5) spaAchUnlock('cinema_5', 'Завсегдатай кино');
  } catch(e) {}
  showInPane('<div class="empty">Загрузка…</div>');
  try {
    const f = await kpFetch('/api/v2.2/films/' + encodeURIComponent(kpId));
    const kpType = String(f.type || f.typeName || '').toUpperCase();
    const isSeries = /SERIES|SERIAL|TV_SHOW/.test(kpType);
    let trailers = [];
    try {
      const v = await kpFetch('/api/v2.2/films/' + encodeURIComponent(kpId) + '/videos');
      trailers = (v.items || []).filter(x => /YOUTUBE|youtube|TRAILER|teaser/i.test(String(x.site || '') + String(x.name || '')));
      if (!trailers.length) trailers = v.items || [];
    } catch (e) {}
    let staff=[];
    try { const st=await kpFetch('/api/v1/staff',{filmId:String(kpId)}); staff=Array.isArray(st)?st:[]; } catch(e) {}
    let seasons=[];
    if(isSeries){try{const sd=await kpFetch('/api/v2.2/films/'+encodeURIComponent(kpId)+'/seasons');seasons=sd.items||[];}catch(e){}}

    const poster = kpPoster(f);
    const cover = f.coverUrl || f.coverUrlPreview || '';
    const title = kpTitle(f);
    const fObj = Object.assign({}, f, { id: kpId, title: title });
    const filmRate = analyzeContentRatingSafe(fObj);
    const filmBlob = title + ' ' + (f.description||'') + ' ' + ((f.genres||[]).map(g=>g.genre).join(' ')) + ' ' + (f.ratingAgeLimits||'') + ' ' + (f.ratingMpaa||'');
    if (!parentalAllowsText(filmBlob, kpId)) {
      showInPane('<div class="empty">Контент скрыт семейным фильтром</div><button type="button" class="btn-primary" id="kp-back">Назад</button>');
      $('kp-back').onclick = () => openCinema();
      return;
    }
    const needA = ytNeedAgeGate(filmBlob);
    if (needA) {
      showYtAgeGate(title, needA, () => openCinemaFilm(kpId));
      return;
    }
    // Лимит проверяется после семейного подтверждения и привязан к ID фильма:
    // повторное открытие уже разрешённого фильма в течение 24 часов не расходует новую попытку.
    const lim = await api('cinema_limit', { params:{ id:kpId } });
    if (lim && lim.limited) { showLimit(lim.error || 'Лимит кино', lim.retry_at, lim.retry_seconds); return; }
    if (lim && !lim.ok && lim.error) { showLimit(lim.error, lim.retry_at, lim.retry_seconds); return; }
    const desc = f.description || f.shortDescription || '';
    const rating = f.ratingKinopoisk != null ? f.ratingKinopoisk : '—';
    const year = f.year || '';
    const genres = (f.genres || []).map(g => g.genre).join(', ');
    const countries = (f.countries || []).map(c => c.country).join(', ');
    const runtime = f.filmLength ? (String(f.filmLength)+' мин.') : '';
    const ageLabel = f.ratingAgeLimits ? String(f.ratingAgeLimits).replace(/^age/,'') : '';
    const premiere = f.premiereWorld || f.premiereRu || '';
    const directors=staff.filter(x=>/режисс|director/i.test(String(x.professionText||x.professionKey||''))).slice(0,6);
    const actors=staff.filter(x=>/актёр|актер|actor/i.test(String(x.professionText||x.professionKey||''))).slice(0,18);
    const cast=actors.length?actors:staff.filter(x=>/actor|актёр|актер/i.test(String(x.professionKey||''))).slice(0,18);
    const castHtml=cast.length?'<section class="card"><div class="val">Актёры и съёмочная группа</div><div style="display:flex;gap:10px;overflow-x:auto;padding:10px 0 4px">'+cast.map(cinemaStaffCard).join('')+'</div></section>':'<section class="card"><div class="val">Актёры</div><div class="sub">Состав пока не предоставлен API для этого фильма.</div></section>';
    const directorsHtml=directors.length?'<div class="sub" style="margin-top:6px">Режиссёр: '+directors.map(x=>esc(x.nameRu||x.nameEn||'')).join(', ')+'</div>':'';
    const seasonsHtml=seasons.length?'<section class="card"><div class="val">Сезоны и серии</div>'+seasons.map(cinemaSeasonCard).join('')+'</section>':'';

    const trailHtml = trailers.slice(0, 6).map((tr, i) => {
      let url = tr.url || '';
      // youtube embed
      let embed = '';
      const ym = url.match(/(?:youtu\.be\/|v=)([\w-]{6,})/);
      if (ym) embed = 'https://www.youtube.com/embed/' + ym[1];
      else if (/youtube\.com\/embed/i.test(url)) embed = url;
      if (embed) {
        return `<div class="card"><div class="label">Трейлер · ${esc(tr.name || '')}</div>
          <div class="player-box" style="height:200px;margin-top:8px"><iframe src="${esc(embed)}" allowfullscreen allow="autoplay;encrypted-media" style="border:0;width:100%;height:100%"></iframe></div></div>`;
      }
      return `<a class="row" href="${esc(url)}" target="_blank" rel="noopener"><div class="av letter">▶</div><div class="body"><div class="title">${esc(tr.name||'Трейлер')}</div><div class="sub">${esc(tr.site||'')}</div></div></a>`;
    }).join('');

    showInPane(`
      <div class="card" style="padding:0;overflow:hidden;background-image:linear-gradient(90deg,rgba(8,10,18,.96),rgba(8,10,18,.80)),${cover?`url('${esc(cover)}')`:'none'};background-size:cover;background-position:center">
        <div style="display:flex;gap:14px;align-items:flex-start;padding:16px;flex-wrap:wrap">
          ${poster ? `<img src="${esc(poster)}" alt="" loading="lazy" style="width:clamp(112px,30%,170px);max-height:250px;object-fit:cover;border-radius:14px;box-shadow:0 12px 30px rgba(0,0,0,.3)">` : ''}
          <div style="flex:1;min-width:170px"><div class="sub">${isSeries?'СЕРИАЛ':'ФИЛЬМ'} · ${esc(String(year))}</div>
          <h2 style="margin:6px 0 8px;font-size:clamp(20px,4vw,28px);line-height:1.15">${esc(title)} ${ytAgeBadgeHtml(filmRate)}</h2>
          <div style="font-size:14px;font-weight:700;color:var(--text)">⭐ ${esc(String(rating))} <span style="font-size:11px;color:var(--muted);font-weight:400">Кинопоиск · ${esc(String(kpId))}</span></div>
          <div style="color:var(--muted);font-size:12px;margin-top:8px">${esc(genres)}</div><div style="color:var(--muted);font-size:12px;margin-top:4px">${esc(countries)}${runtime?' · '+esc(runtime):''}${ageLabel?' · '+esc(ageLabel):''}</div>${premiere?`<div style="color:var(--muted);font-size:11px;margin-top:4px">Премьера: ${esc(premiere)}</div>`:''}${f.slogan?`<div style="font-size:12px;color:var(--muted);margin-top:6px;font-style:italic">${esc(f.slogan)}</div>`:''}${directorsHtml}
          <button type="button" id="kp-fav" class="btn-primary" style="margin-top:12px;width:auto;padding:9px 14px;background:rgba(255,255,255,.10)">☆ Добавить в мой список</button></div>
        </div>
      </div>
      ${desc ? `<div class="card" style="font-size:14px;line-height:1.55;white-space:pre-wrap">${esc(desc)}</div>` : ''}
      ${castHtml}
      ${seasonsHtml}
      ${cinemaWatchPlayerHtml(kpId,isSeries)}
      ${trailHtml || '<div class="empty">Трейлеры не найдены в API</div>'}
      <button type="button" class="btn-primary" id="kp-block" style="margin-top:8px;background:rgba(127,29,29,.55)">🚫 Скрыть в этом профиле</button>
      <button type="button" class="btn-primary" id="kp-back" style="margin-top:8px;background:rgba(255,255,255,.08)">← К списку</button>
    `);
    const favStatus=await api('fav_status',{params:{type:'cinema',id:kpId}});
    const favBtn=$('kp-fav');if(favBtn){favBtn.textContent=favStatus&&favStatus.on?'⭐ В моём списке':'☆ Добавить в мой список';favBtn.onclick=async()=>{const rr=await api('fav_toggle',{method:'POST',body:{type:'cinema',id:kpId}});if(rr&&rr.ok){favBtn.textContent=rr.on?'⭐ В моём списке':'☆ Добавить в мой список';spaPlay(rr.on?'ok':'click');}else alert(rr&&rr.error||'Не удалось обновить список');};}
    document.querySelectorAll('[data-staff-id]').forEach(b=>b.onclick=()=>{if(b.dataset.staffId)openCinemaPerson(b.dataset.staffId,kpId);});
    cinemaBindWatchPlayer(kpId,isSeries);
    if ($('kp-block')) $('kp-block').onclick = () => {
      if (parentalBlockKp(kpId, title) || parentalBlockText(title)) {
        alert('Скрыто для профиля «' + (parentalActive().name||'') + '»');
        openCinema();
      }
    };
    $('kp-back').onclick = () => openCinema();
    presence('cinema', 'Кино: ' + title);
    try {
      spaWatchHistAdd({ kind:'cinema', id:kpId, title:title });
      continuePush({ kind:'cinema', id:kpId, title:title, avatar:poster||'', subtitle:isSeries?'series':'film' });
    } catch(e) {}
  } catch (e) {
    showInPane('<div class="empty">' + esc(String(e.message || e)) + '</div><button type="button" class="btn-primary" id="kp-back">Назад</button>');
    $('kp-back').onclick = () => openCinema();
  }
}


async function openCinemaPerson(staffId,returnFilmId){
  destroySpaPlayer();
  if (!(await spaCinemaGate(true))) return;
  setDetailTitle('Актёр');showInPane('<div class="empty">Загружаю карточку актёра…</div>');
  try{const person=await kpFetch('/api/v1/staff/'+encodeURIComponent(staffId));let films=[];try{const d=await kpFetch('/api/v2.2/films',{staffId:String(staffId),page:'1'});films=d.items||[];}catch(e){}
    const name=person.nameRu||person.nameEn||'Участник съёмочной группы',photo=person.posterUrl||person.posterUrlPreview||'';
    const html=`<button type="button" class="btn-primary" id="cp-return" style="background:rgba(255,255,255,.08);margin-bottom:10px">← Назад к фильму</button><div class="card" style="display:flex;gap:14px;align-items:flex-start">${photo?`<img src="${esc(photo)}" alt="" style="width:116px;max-height:160px;object-fit:cover;border-radius:12px">`:''}<div><h2 style="margin:0 0 8px">${esc(name)}</h2><div class="sub">${esc(person.profession||person.professionText||'Актёр / съёмочная группа')}</div><div class="sub">${esc(person.birthday||'')}${person.death?` · ${esc(person.death)}`:''}</div><p style="font-size:13px;line-height:1.45">${esc(person.facts||'')}</p></div></div><div class="val">Фильмография</div>${films.slice(0,30).map(f=>`<button class="row" data-kpid="${esc(f.kinopoiskId||f.filmId||'')}" style="width:100%;text-align:left;border:0;background:transparent;color:inherit;cursor:pointer"><div class="av letter">🎬</div><div class="body"><div class="title">${esc(kpTitle(f))}</div><div class="sub">${esc(String(f.year||''))} · ${esc(f.description||'')}</div></div></button>`).join('')||'<div class="empty">Фильмография недоступна</div>'}`;
    const root=showInPane(html);const back=root.querySelector('#cp-return');if(back)back.onclick=()=>returnFilmId?openCinemaFilm(returnFilmId):openCinema();root.querySelectorAll('[data-kpid]').forEach(b=>b.onclick=()=>openCinemaFilm(b.dataset.kpid));
  }catch(e){showInPane('<div class="empty">Не удалось загрузить актёра: '+esc(e.message||e)+'</div><button class="btn-primary" id="cp-back">Назад</button>');const b=$('cp-back');if(b)b.onclick=()=>openCinema();}
}

/* ===== Музыка WebApp → SPA (локально, те же ключи) ===== */
var __slMusicAudio = null;
// Server-authorized playback session exists only in this page memory.
// Never trust a localStorage timestamp for quota bypass after reload.
var __slMusicSessionAuthorized = false;
var __slMusicStartPending = false;
var __slMusicQueue = [];
var __slMusicIdx = -1;
var __slMusicWave = false;
var __slMusicLibMem = null;
var __slMusicIdb = null;
var __slMusicObjUrl = null;
var __slMusicCoverUrls = Object.create(null);
var __slMusicPlayGeneration = 0;
async function musicCoverUrl(tr){if(!tr)return '';if(tr.cover)return tr.cover;if(!tr.coverBlobId)return '';if(__slMusicCoverUrls[tr.coverBlobId])return __slMusicCoverUrls[tr.coverBlobId];try{const blob=await musicIdbGet(tr.coverBlobId);if(blob){const u=URL.createObjectURL(blob);__slMusicCoverUrls[tr.coverBlobId]=u;return u;}}catch(e){}return '';}
var __slMusicCurrentTrackId = (function(){ try{return localStorage.getItem('sl_music_current_track_v1')||'';}catch(e){return '';} })();

function musicCleanTitle(name) {
  return String(name || '').replace(/\.[a-z0-9]{2,5}$/i, '').replace(/[_+]+/g, ' ').trim() || 'Трек';
}
function musicLibGet() {
  if (__slMusicLibMem && Array.isArray(__slMusicLibMem)) return __slMusicLibMem.slice();
  var keys = ['sl_music_lib_v3', 'sl_music_lib_v2', 'sl_music_lib'];
  for (var i = 0; i < keys.length; i++) {
    try {
      var raw = localStorage.getItem(keys[i]);
      if (!raw) continue;
      var list = JSON.parse(raw);
      if (Array.isArray(list)) {
        __slMusicLibMem = list.slice();
        try { localStorage.setItem('sl_music_lib_v3', JSON.stringify(list)); } catch (e) {}
        return list.slice();
      }
    } catch (e) {}
  }
  try {
    var sraw = sessionStorage.getItem('sl_music_lib_v3');
    if (sraw) {
      var sl = JSON.parse(sraw);
      if (Array.isArray(sl)) { __slMusicLibMem = sl.slice(); return sl.slice(); }
    }
  } catch (e) {}
  return [];
}
function musicLibSet(list) {
  list = Array.isArray(list) ? list : [];
  __slMusicLibMem = list.slice();
  try { localStorage.setItem('sl_music_lib_v3', JSON.stringify(list)); } catch (e) {
    try { sessionStorage.setItem('sl_music_lib_v3', JSON.stringify(list)); } catch (e2) {}
  }
}
function musicPlaylistsGet() {
  try {
    var p = JSON.parse(localStorage.getItem('sl_music_playlists_v1') || 'null');
    if (p && Array.isArray(p.lists)) return p;
  } catch (e) {}
  return { active: 'fav', lists: [{ id: 'fav', name: 'Избранное', tracks: [] }] };
}
function musicPlaylistsSet(p) {
  try { localStorage.setItem('sl_music_playlists_v1', JSON.stringify(p)); } catch (e) {}
}
function musicPlaylistAdd(trackId) {
  var p = musicPlaylistsGet();
  var L = p.lists.find(function(x){ return x.id === (p.active || 'fav'); }) || p.lists[0];
  if (!L.tracks) L.tracks = [];
  if (L.tracks.indexOf(String(trackId)) < 0) L.tracks.push(String(trackId));
  musicPlaylistsSet(p);
}
function musicIdbOpen() {
  return new Promise(function(resolve, reject) {
    if (__slMusicIdb) { resolve(__slMusicIdb); return; }
    if (!window.indexedDB) { reject(new Error('no idb')); return; }
    var req = indexedDB.open('sl_music_blobs_v1', 1);
    req.onupgradeneeded = function() {
      var db = req.result;
      if (!db.objectStoreNames.contains('files')) db.createObjectStore('files');
    };
    req.onsuccess = function() { __slMusicIdb = req.result; resolve(__slMusicIdb); };
    req.onerror = function() { reject(req.error || new Error('idb')); };
  });
}
function musicIdbPut(id, blob) {
  return musicIdbOpen().then(function(db) {
    return new Promise(function(resolve, reject) {
      var tx = db.transaction('files', 'readwrite');
      tx.objectStore('files').put(blob, id);
      tx.oncomplete = function() { resolve(true); };
      tx.onerror = function() { reject(tx.error); };
    });
  });
}
function musicIdbGet(id) {
  return musicIdbOpen().then(function(db) {
    return new Promise(function(resolve, reject) {
      var tx = db.transaction('files', 'readonly');
      var r = tx.objectStore('files').get(id);
      r.onsuccess = function() { resolve(r.result || null); };
      r.onerror = function() { reject(r.error); };
    });
  });
}
function musicIdbDel(id) {
  return musicIdbOpen().then(function(db) {
    return new Promise(function(resolve) {
      var tx = db.transaction('files', 'readwrite');
      tx.objectStore('files').delete(id);
      tx.oncomplete = function() { resolve(true); };
      tx.onerror = function() { resolve(false); };
    });
  }).catch(function(){ return false; });
}
function musicStopAll() {
  try {
    if (__slMusicAudio) {
      __slMusicAudio.onended = null;
      __slMusicAudio.pause();
      __slMusicAudio.removeAttribute('src');
      __slMusicAudio.load();
    }
  } catch (e) {}
  if (__slMusicObjUrl) { try { URL.revokeObjectURL(__slMusicObjUrl); } catch (e) {} __slMusicObjUrl = null; }
  __slMusicAudio = null;
}
function musicDecodeId3Text(bytes){
  if(!bytes||!bytes.length)return '';
  const enc=bytes[0],data=bytes.subarray(1);
  try{
    if(enc===0)return new TextDecoder('windows-1252').decode(data).replace(/\0+$/g,'').trim();
    if(enc===3)return new TextDecoder('utf-8').decode(data).replace(/\0+$/g,'').trim();
    if(enc===1||enc===2){let b=data;if(enc===2&&b.length%2)b=b.subarray(0,b.length-1);return new TextDecoder(enc===1?'utf-16':'utf-16be').decode(b).replace(/\0+$/g,'').trim();}
  }catch(e){}
  return '';
}
function musicParseFilename(name){
  let base=musicCleanTitle(name).replace(/^\d{1,3}\s*[-_.]\s*/,'').trim();
  const m=base.match(/^(.{2,80}?)\s+[–—-]\s+(.{2,120})$/);
  return m?{artist:m[1].trim(),title:m[2].trim()}:{artist:'',title:base};
}
async function musicReadAudioTags(file){
  const out={title:'',artist:'',album:'',coverBlob:null};
  try{
    const buf=await file.slice(0,Math.min(file.size,8*1024*1024)).arrayBuffer();
    const b=new Uint8Array(buf);if(b.length<10||String.fromCharCode(b[0],b[1],b[2])!=='ID3')return out;
    const ver=b[3],tagSize=((b[6]&127)<<21)|((b[7]&127)<<14)|((b[8]&127)<<7)|(b[9]&127);let pos=10,end=Math.min(b.length,10+tagSize);
    while(pos+10<=end){
      const id=String.fromCharCode(b[pos],b[pos+1],b[pos+2],b[pos+3]);if(!id.trim()||id.charCodeAt(0)===0)break;
      let size=ver===4?(((b[pos+4]&127)<<21)|((b[pos+5]&127)<<14)|((b[pos+6]&127)<<7)|(b[pos+7]&127)):((b[pos+4]<<24)|(b[pos+5]<<16)|(b[pos+6]<<8)|b[pos+7]);
      if(size<=0||pos+10+size>end)break;
      const frame=b.subarray(pos+10,pos+10+size);
      if(id==='TIT2')out.title=musicDecodeId3Text(frame);
      else if(id==='TPE1')out.artist=musicDecodeId3Text(frame);
      else if(id==='TALB')out.album=musicDecodeId3Text(frame);
      else if(id==='APIC'&&frame.length>8&&!out.coverBlob){
        const enc=frame[0];let i=1;while(i<frame.length&&frame[i]!==0)i++;const mime=new TextDecoder().decode(frame.subarray(1,i))||'image/jpeg';i+=2;
        if(enc===1||enc===2){while(i+1<frame.length&&(frame[i]!==0||frame[i+1]!==0))i+=2;i+=2;}else{while(i<frame.length&&frame[i]!==0)i++;i++;}
        if(i<frame.length)out.coverBlob=new Blob([frame.subarray(i)],{type:mime});
      }
      pos+=10+size;
    }
  }catch(e){}
  return out;
}
async function musicArtworkSearch(title,artist){
  const clean=x=>String(x||'').toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g,'').replace(/[^\p{L}\p{N}]+/gu,' ').trim();
  const targetTitle=clean(title),targetArtist=clean(artist);
  const score=x=>{const t=clean(x.title),a=clean(x.artist);return (targetTitle&&t===targetTitle?8:0)+(targetArtist&&a===targetArtist?6:0)+(targetTitle&&t.includes(targetTitle)?2:0)+(targetArtist&&a.includes(targetArtist)?1:0);};
  const term=encodeURIComponent([artist,title].filter(Boolean).join(' ')||title||'');if(!term)return null;
  const candidates=[];
  try{const r=await fetch('https://itunes.apple.com/search?term='+term+'&media=music&limit=12',{mode:'cors'});if(r.ok){const j=await r.json();for(const x of (j.results||[]))candidates.push({title:x.trackName||'',artist:x.artistName||'',album:x.collectionName||'',cover:String(x.artworkUrl100||'').replace(/\d+x\d+bb/,'600x600bb'),source:'iTunes'});}}catch(e){}
  if(!candidates.some(x=>x.cover&&score(x)>=(targetArtist?12:8))){
    try{const r=await fetch('https://api.deezer.com/search?q='+term+'&limit=12',{mode:'cors'});if(r.ok){const j=await r.json();for(const x of (j.data||[]))candidates.push({title:x.title||'',artist:x.artist&&x.artist.name||'',album:x.album&&x.album.title||'',cover:x.album&&String(x.album.cover_xl||x.album.cover_big||x.album.cover_medium||'')||'',source:'Deezer'});}}catch(e){}
  }
  candidates.sort((a,b)=>score(b)-score(a));const best=candidates.find(x=>x.cover&&score(x)>=(targetArtist?12:4));
  return best?{title:best.title||title,artist:best.artist||artist,album:best.album||'',cover:best.cover,source:best.source}:null;
}
async function musicLookupMetadata(title,artist){return await musicArtworkSearch(title,artist);}
function musicFetchCover(title, artist) {return musicArtworkSearch(title,artist).then(x=>x&&x.cover||'').catch(()=> '');}
async function musicResolveUrl(tr) {
  if (!tr) return '';
  if (tr.blobId || (tr.id && String(tr.id).indexOf('local-') === 0 && !tr.url)) {
    var blob = await musicIdbGet(tr.blobId || tr.id);
    if (!blob) return '';
    if (__slMusicObjUrl) try { URL.revokeObjectURL(__slMusicObjUrl); } catch (e) {}
    __slMusicObjUrl = URL.createObjectURL(blob);
    return __slMusicObjUrl;
  }
  return tr.url || tr.audio || '';
}
async function musicPlayAt(idx, fromWave, isResume, isAuto) {
  var list = (__slMusicQueue && __slMusicQueue.length) ? __slMusicQueue : musicLibGet();
  if (!list.length) return;
  if (idx < 0) idx = list.length - 1;
  if (idx >= list.length) idx = 0;
  var tr = list[idx];
  var playGeneration = ++__slMusicPlayGeneration;
  var url = await musicResolveUrl(tr);
  if(playGeneration!==__slMusicPlayGeneration)return;
  if (!url) { alert('Нет файла/ссылки'); return; }
  // One explicit start consumes one server quota unit. Automatic next-track is free
  // only while this same page still holds a server-authorized session in memory.
  // A saved localStorage timestamp must never unlock playback after reload.
  var continueAuthorizedQueue = !!(__slMusicSessionAuthorized && isAuto);
  if (!continueAuthorizedQueue) {
    if (__slMusicStartPending) return;
    __slMusicStartPending = true;
    var quota;
    try { quota = await api('music_start'); }
    finally { __slMusicStartPending = false; }
    if (!quota || !quota.ok) {
      __slMusicSessionAuthorized = false;
      if (__slMusicObjUrl) { try { URL.revokeObjectURL(__slMusicObjUrl); } catch(e) {} __slMusicObjUrl = null; }
      showLimit((quota && quota.error) || 'Лимит прослушивания музыки исчерпан', quota && quota.retry_at, quota && quota.retry_seconds);
      return;
    }
    __slMusicSessionAuthorized = true;
  }
  if(playGeneration!==__slMusicPlayGeneration)return;
  __slMusicIdx = idx;
  __slMusicCurrentTrackId = String(tr.id || tr.blobId || '');
  try { localStorage.setItem('sl_music_current_track_v1', __slMusicCurrentTrackId); } catch(e) {}
  musicStopAll();
  // re-resolve if stop cleared obj url for blob
  if (tr.blobId || (tr.id && String(tr.id).indexOf('local-') === 0 && !tr.url)) {
    url = await musicResolveUrl(tr);
  }
  __slMusicAudio = new Audio();
  __slMusicAudio.preload = 'auto';
  __slMusicAudio.src = url;
  __slMusicAudio.onended = function() { musicPlayNext(true); };
  __slMusicAudio.onpause = function() { if (__slMusicCurrentTrackId) presence('music', 'Пауза: ' + (tr.title || 'Музыка')); renderMiniMusic(); };
  __slMusicAudio.onplay = function() { presence('music', 'Слушает: ' + (tr.title || 'Музыка')); renderMiniMusic(); };
  try {
    await __slMusicAudio.play();
    spaPlay('open');
    presence('music', 'Слушает: ' + (tr.title || musicCleanTitle(tr.name || tr.url || 'Трек')));
    renderMiniMusic();
  } catch (e) {}
  if (window.__slMusicNowUI) {
    window.__slMusicNowUI(tr.title || musicCleanTitle(tr.name || tr.url || ''), {
      artist: tr.artist || (tr.blobId ? 'Файл' : 'Ссылка'),
      cover: tr.cover || ''
    });
    musicCoverUrl(tr).then(function(u){if(playGeneration===__slMusicPlayGeneration&&u&&window.__slMusicNowUI&&__slMusicIdx===idx&&String(__slMusicCurrentTrackId)===String(tr.id||tr.blobId||''))window.__slMusicNowUI(tr.title||'Трек',{artist:tr.artist||'Музыка',cover:u});});
  }
  if (!tr.cover) {
    musicFetchCover(tr.title || '', tr.artist || '').then(function(art) {
      if (!art || playGeneration!==__slMusicPlayGeneration || __slMusicIdx!==idx || String(__slMusicCurrentTrackId)!==String(tr.id||tr.blobId||'')) return;
      var L = musicLibGet();
      for (var i = 0; i < L.length; i++) {
        if (String(L[i].id) === String(tr.id)) { L[i].cover = art; break; }
      }
      musicLibSet(L);
      if (window.__slMusicNowUI && __slMusicIdx === idx) {
        window.__slMusicNowUI(tr.title || '', { artist: tr.artist || '', cover: art });
      }
    });
  }
}
function musicStopPlayback(){
  __slMusicPlayGeneration++;
  __slMusicSessionAuthorized = false;
  musicStopAll();
  __slMusicIdx=-1;__slMusicQueue=[];__slMusicWave=false;
  try{localStorage.removeItem('sl_music_current_track_v1');}catch(e){}
  renderMiniMusic();
  if(window.__slMusicNowUI)window.__slMusicNowUI('Ничего не играет',{artist:'Воспроизведение остановлено',cover:''});
  try{presence('music','Музыка остановлена');}catch(e){}
}
function musicPlayNext(auto) {
  var list = (__slMusicQueue && __slMusicQueue.length) ? __slMusicQueue : musicLibGet();
  if (!list.length) return;
  if (__slMusicIdx >= list.length-1) {
    if(auto){musicStopPlayback();return;}
    musicPlayAt(0,false,false,false);return;
  }
  musicPlayAt(__slMusicIdx+1,false,false,!!auto);
}
function musicPlayPrev() {
  var list = (__slMusicQueue && __slMusicQueue.length) ? __slMusicQueue : musicLibGet();
  if (!list.length) return;
  musicPlayAt((__slMusicIdx - 1 + list.length) % list.length, false);
}

// StreamLive deliberately stops music when the screen locks, the page is hidden,
// or the browser leaves the page. Main player and floating mini-player share this audio.
function slStopMusicOnScreenOff(){
  try{if(__slMusicAudio||__slMusicSessionAuthorized)musicStopPlayback();}catch(e){try{if(__slMusicAudio)__slMusicAudio.pause();}catch(_){}}
}
document.addEventListener('visibilitychange',function(){if(document.visibilityState==='hidden')slStopMusicOnScreenOff();});
document.addEventListener('pagehide',slStopMusicOnScreenOff);

function musicTogglePlay() {
  if (!__slMusicAudio) {
    var list = (__slMusicQueue && __slMusicQueue.length) ? __slMusicQueue : musicLibGet();
    if (!list.length) return;
    var resumeId = __slMusicCurrentTrackId;
    if (!resumeId) { try { resumeId = localStorage.getItem('sl_music_current_track_v1') || ''; } catch(e) {} }
    var resumeIdx = resumeId ? list.findIndex(function(x){ return String(x.id || x.blobId || '') === String(resumeId); }) : -1;
    if (resumeIdx < 0) { resumeIdx = 0; resumeId = ''; }
    musicPlayAt(resumeIdx, false, !!resumeId);
    return;
  }
  if (__slMusicAudio.paused) __slMusicAudio.play().catch(function(){});
  else __slMusicAudio.pause();
  renderMiniMusic();
  if (window.__slMusicNowUI) {
    var list = (__slMusicQueue && __slMusicQueue.length) ? __slMusicQueue : musicLibGet();
    var tr = list[__slMusicIdx] || {};
    window.__slMusicNowUI(tr.title || 'Трек', { artist: tr.artist || '', cover: tr.cover || '' });
  }
}
function musicStartWave() {
  var list = musicLibGet().slice();
  if (!list.length) { alert('Сначала добавь треки'); return; }
  // shuffle
  for (var i = list.length - 1; i > 0; i--) {
    var j = Math.floor(Math.random() * (i + 1));
    var tmp = list[i]; list[i] = list[j]; list[j] = tmp;
  }
  __slMusicWave = true;
  __slMusicQueue = list;
  musicPlayAt(0, true);
}

async function openMusicHub() {
  destroySpaPlayer();
  setDetailTitle('Музыка');
  try { spaQuestDone('open_music'); } catch(e) {}
  window.__musicHubOpen = true;

  const renderListOnly = () => {
    const box = $('mus-list');
    if (!box) return;
    const lib = musicLibGet();
    const pl = musicPlaylistsGet();
    const fav = (pl.lists.find(x => x.id === 'fav') || { tracks: [] }).tracks || [];
    const head = $('mus-lib-head');
    if (head) head.textContent = 'Библиотека · ' + lib.length;
    if (!lib.length) {
      box.innerHTML = '<div class="empty">Пока нет треков — файл или ссылка</div>';
      return;
    }
    box.innerHTML = lib.map((tr, i) => `
      <div class="row" data-i="${i}">
        ${tr.cover ? `<img class="av" data-cover-track="${esc(String(tr.id))}" src="${esc(tr.cover)}" alt="" onerror="this.outerHTML='<div class=\'av letter\'>♪</div>'">` : `<div class="av letter" data-cover-track="${esc(String(tr.id))}">♪</div>`}
        <div class="body"><div class="title">${esc(tr.title || musicCleanTitle(tr.name || tr.url || ''))}</div>
        <div class="sub">${esc(tr.artist || (tr.blobId ? 'Файл' : 'Ссылка'))}${fav.indexOf(String(tr.id))>=0?' · ★':''}</div></div>
        <button type="button" class="fav-btn mus-star" data-i="${i}" title="В избранное">★</button>
        <button type="button" class="fav-btn mus-del" data-i="${i}" title="Удалить">✕</button>
      </div>`).join('');
    bindListClicks();
    lib.filter(tr=>tr.coverBlobId&&!tr.cover).forEach(async tr=>{const u=await musicCoverUrl(tr);const el=box.querySelector('[data-cover-track="'+CSS.escape(String(tr.id))+'"]');if(el&&u){if(el.tagName==='IMG')el.src=u;else el.outerHTML='<img class="av" data-cover-track="'+esc(String(tr.id))+'" src="'+esc(u)+'" alt="">';}});
  };

  const bindListClicks = () => {
    document.querySelectorAll('#mus-list .row[data-i]').forEach(r => {
      r.onclick = (e) => {
        if (e.target.closest('.mus-del') || e.target.closest('.mus-star')) return;
        const i = +r.dataset.i;
        __slMusicWave = false;
        __slMusicQueue = musicLibGet().slice();
        musicPlayAt(i, false);
      };
    });
    document.querySelectorAll('.mus-star').forEach(b => {
      b.onclick = (e) => {
        e.stopPropagation();
        const tr = musicLibGet()[+b.dataset.i];
        if (!tr) return;
        musicPlaylistAdd(tr.id);
        spaPlay('ok');
        renderListOnly();
      };
    });
    document.querySelectorAll('.mus-del').forEach(b => {
      b.onclick = async (e) => {
        e.stopPropagation();
        const i = +b.dataset.i;
        let L = musicLibGet();
        const tr = L[i];
        if (!tr) return;
        if (!confirm('Удалить «' + (tr.title || '') + '»?')) return;
        if (tr.blobId || String(tr.id).indexOf('local-') === 0) await musicIdbDel(tr.blobId || tr.id);if(tr.coverBlobId)await musicIdbDel(tr.coverBlobId);
        L = L.filter((_, idx) => idx !== i);
        musicLibSet(L);
        renderListOnly();
      };
    });
  };

  const paint = () => {
    if (!window.__musicHubOpen) return;
    const lib = musicLibGet();
    showInPane(`
      <div class="card" id="mus-player-card">
        <div style="display:flex;gap:12px;align-items:center">
          <div id="mus-cover" style="width:64px;height:64px;border-radius:12px;background:rgba(255,255,255,.08);display:grid;place-items:center;font-size:28px;overflow:hidden;flex-shrink:0">🎵</div>
          <div style="min-width:0;flex:1">
            <div class="val" id="mus-title" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">Ничего не играет</div>
            <div style="color:var(--muted);font-size:13px" id="mus-sub">Файлы + ссылки · локально</div>
          </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
          <button type="button" class="btn-primary" id="mus-prev" style="margin:0;flex:1">⏮</button>
          <button type="button" class="btn-primary" id="mus-play" style="margin:0;flex:1">▶</button>
          <button type="button" class="btn-primary" id="mus-next" style="margin:0;flex:1">⏭</button><button type="button" class="btn-primary" id="mus-stop" style="margin:0;flex:1">■</button>
        </div>
        <button type="button" class="btn-primary" id="mus-wave" style="margin-top:8px;background:rgba(255,255,255,.08)">🌊 Моя волна</button>
        <button type="button" class="btn-primary" id="mus-mini" style="margin-top:8px;background:rgba(255,255,255,.08)">▱ Мини-плеер</button>
      </div>

      <div class="card">
        <div class="label">Добавить файл</div>
        <input type="file" id="mus-file" accept="audio/*,.mp3,.m4a,.ogg,.wav,.flac,.aac" multiple style="width:100%;font-size:13px;margin-top:6px">
        <p id="mus-file-status" style="font-size:12px;color:var(--muted);margin:6px 0 0"></p>
      </div>
      <div class="card">
        <div class="label">Добавить ссылку</div>
        <input class="field" id="mus-url" placeholder="https://… mp3/m4a" autocomplete="off">
        <input class="field" id="mus-tname" placeholder="Название" autocomplete="off">
        <input class="field" id="mus-artist" placeholder="Исполнитель" autocomplete="off">
        <label for="mus-rights" style="font-size:12px;display:flex;gap:8px;align-items:center;margin:8px 0;cursor:pointer;user-select:none">
          <input type="checkbox" id="mus-rights" style="width:18px;height:18px;flex-shrink:0">
          <span>Подтверждаю права на трек</span>
        </label>
        <button type="button" class="btn-primary" id="mus-addurl" style="margin:0">Добавить ссылку</button>
        <p id="mus-url-status" style="font-size:12px;color:var(--muted);margin:6px 0 0"></p>
      </div>

      <div class="card"><div class="val" id="mus-lib-head">Библиотека · ${lib.length}</div>
        <p style="font-size:12px;color:var(--muted);margin:6px 0 0">Только на этом устройстве</p></div>
      <div id="mus-list"></div>
    `);

    window.__slMusicNowUI = function(title, meta) {
      const mt = $('mus-title');
      const ms = $('mus-sub');
      const mc = $('mus-cover');
      const bp = $('mus-play');
      if (mt) mt.textContent = title || 'Трек';
      if (ms) ms.textContent = (meta && meta.artist) ? meta.artist : (__slMusicWave ? 'Моя волна' : 'Библиотека');
      if (mc) {
        if (meta && meta.cover) {
          mc.innerHTML = '<img src="'+esc(meta.cover)+'" alt="" style="width:100%;height:100%;object-fit:cover" onerror="this.parentNode.textContent=\'🎧\'">';
        } else mc.textContent = '🎧';
      }
      if (bp) bp.textContent = (__slMusicAudio && !__slMusicAudio.paused) ? '⏸' : '▶';
    };

    try {
      if (__slMusicAudio && __slMusicAudio.src) {
        const tr = ((__slMusicQueue && __slMusicQueue.length ? __slMusicQueue : musicLibGet())[__slMusicIdx] || {});
        window.__slMusicNowUI(tr.title || 'Трек', { artist: tr.artist || '', cover: tr.cover || '' });
      }
    } catch (e) {}

    if ($('mus-prev')) $('mus-prev').onclick = () => musicPlayPrev();
    if ($('mus-play')) $('mus-play').onclick = () => musicTogglePlay();
    if ($('mus-next')) $('mus-next').onclick = () => musicPlayNext(false);
    if ($('mus-stop')) $('mus-stop').onclick = () => musicStopPlayback();
    if ($('mus-wave')) $('mus-wave').onclick = () => musicStartWave();
    if ($('mus-mini')) $('mus-mini').onclick = () => { window.__slMusicMiniHidden=false; renderMiniMusic(); const c=document.getElementById('sl-mini-music-card'); if(c)c.scrollIntoView({block:'nearest',behavior:'smooth'}); };
    // checkbox: не даём label/перерисовке сбрасывать
    const rights = $('mus-rights');
    if (rights) {
      rights.addEventListener('click', (e) => { e.stopPropagation(); });
      rights.addEventListener('change', (e) => { e.stopPropagation(); });
    }

    if ($('mus-file')) $('mus-file').onchange = async () => {
      const files = Array.from($('mus-file').files || []);
      if (!files.length) return;
      const st = $('mus-file-status');
      if (st) st.textContent = slT('Сохранение…','Saving…','Збереження…');
      let L = musicLibGet();
      let added = 0;
      for (const f of files) {
        if (!f.type.startsWith('audio') && !/\.(mp3|m4a|ogg|wav|flac|aac)$/i.test(f.name)) continue;
        const id = 'local-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
        try {
          await musicIdbPut(id, f);
          const tags = await musicReadAudioTags(f);
          const fileNameMeta=musicParseFilename(f.name);
          let title = tags.title || fileNameMeta.title || musicCleanTitle(f.name);
          let artist = tags.artist || fileNameMeta.artist || '';
          let cover = '';
          let coverBlobId='';
          if(tags.coverBlob){coverBlobId=id+'-cover';try{await musicIdbPut(coverBlobId,tags.coverBlob);}catch(e){coverBlobId='';}}
          L.unshift({ id, blobId: id, title, artist, album:tags.album||'', name: f.name, cover, coverBlobId, added: Date.now() });
          added++;
          musicLibSet(L);
          // If the file has no embedded tags/art, try a catalog match in the background.
          (async () => {
            try {
              if(coverBlobId)return;
              const hit=await musicLookupMetadata(title,artist);
              if(!hit)return;
              const LL=musicLibGet();
              for(let i=0;i<LL.length;i++)if(String(LL[i].id)===String(id)){
                if(!tags.title&&hit.title)LL[i].title=hit.title;
                if(!tags.artist&&hit.artist)LL[i].artist=hit.artist;
                if(!coverBlobId&&hit.cover)LL[i].cover=hit.cover;
                if(hit.album)LL[i].album=hit.album;LL[i].artworkLookupTriedAt=Date.now();
                break;
              }
              musicLibSet(LL);if(window.__musicHubOpen)renderListOnly();
            }catch(e){}
          })();
        } catch (e) {
          if (st) st.textContent = 'Ошибка: ' + f.name + ' (' + (e.message||e) + ')';
        }
      }
      if (st) st.textContent = added ? ('Добавлено: ' + added) : 'Не удалось добавить';
      spaPlay(added ? 'ok' : 'err');
      renderListOnly();
      try { $('mus-file').value = ''; } catch (e) {}
    };

    if ($('mus-addurl')) $('mus-addurl').onclick = async () => {
      const st = $('mus-url-status');
      const rightsEl = $('mus-rights');
      if (!rightsEl || !rightsEl.checked) {
        if (st) st.textContent = 'Поставь галочку «Подтверждаю права»';
        alert('Подтверди права на трек');
        return;
      }
      const u = ($('mus-url').value || '').trim();
      if (!/^https?:\/\//i.test(u)) {
        if (st) st.textContent = 'Нужна ссылка http(s)';
        alert('Нужна ссылка http(s)');
        return;
      }
      let title = ($('mus-tname').value || '').trim() || musicCleanTitle(u);
      let artist = ($('mus-artist').value || '').trim();
      const id = 'url-' + Date.now();
      const L = musicLibGet();
      L.unshift({ id, url: u, title, artist, cover: '', added: Date.now() });
      musicLibSet(L);
      if (st) st.textContent = 'Добавлено';
      spaPlay('ok');
      // сброс полей, галочку оставляем
      try { $('mus-url').value = ''; $('mus-tname').value = ''; $('mus-artist').value = ''; } catch (e) {}
      renderListOnly();
      (async () => {
        try {
          const cover = await musicFetchCover(title, artist);
          if (!cover) return;
          const LL = musicLibGet();
          for (let i = 0; i < LL.length; i++) {
            if (String(LL[i].id) === String(id)) { LL[i].cover = cover; break; }
          }
          musicLibSet(LL);
          if (window.__musicHubOpen) renderListOnly();
        } catch (e) {}
      })();
    };

    renderListOnly();

    // auto covers — только список, без полного paint
    (async () => {
      try {
        const need = musicLibGet().filter(tr => tr && !tr.cover && !tr.coverBlobId && (!tr.artworkLookupTriedAt || Date.now()-Number(tr.artworkLookupTriedAt)>86400000)).slice(0, 12);
        for (const tr of need) {
          if (!window.__musicHubOpen) return;
          const hit = await musicArtworkSearch(tr.title || musicCleanTitle(tr.name||''), tr.artist || '');
          const L = musicLibGet();
          for (let i = 0; i < L.length; i++) if (String(L[i].id) === String(tr.id)) {
            L[i].artworkLookupTriedAt = Date.now();
            if(hit&&hit.cover){L[i].cover=hit.cover;if(!L[i].title&&hit.title)L[i].title=hit.title;if(!L[i].artist&&hit.artist)L[i].artist=hit.artist;if(!L[i].album&&hit.album)L[i].album=hit.album;}
            break;
          }
          musicLibSet(L);
        }
        if (window.__musicHubOpen && need.length) renderListOnly();
      } catch (e) {}
    })();
  };

  paint();
}

async function openGeoInfo() {
  destroySpaPlayer();
  setDetailTitle('Гео');
  showInPane('<div class="empty">Определяем страну…</div>');
  let info = { country: '—', city: '—', ip: '—' };
  try {
    const r = await fetch('https://ipapi.co/json/', { cache:'no-store' });
    const j = await r.json();
    info = { country: j.country_name||j.country||'—', city: j.city||'—', ip: j.ip||'—' };
  } catch(e) {
    try {
      const r = await fetch('https://api.country.is/', { cache:'no-store' });
      const j = await r.json();
      info.country = j.country || '—';
    } catch(e2) {}
  }
  try { localStorage.setItem('sl_geo_country', info.country); } catch(e) {}
  showInPane(`
    <div class="card"><div class="val">🌍 Гео (без БД)</div>
      <p style="font-size:13px;color:var(--muted);margin:8px 0 0">Публичный geo по IP, как в WebApp.</p></div>
    <div class="card"><div class="label">Страна</div><div class="val">${esc(info.country)}</div></div>
    <div class="card"><div class="label">Город</div><div class="val">${esc(info.city)}</div></div>
    <div class="card"><div class="label">IP</div><div class="val">${esc(info.ip)}</div></div>
  `);
}


function openSpaSettings() {
  spaMigrateLegacyApiKeys();
  destroySpaPlayer();
  setDetailTitle('Настройки SPA');
  let conf = {};
  try { conf = JSON.parse(localStorage.getItem('streamspa_settings') || '{}'); } catch(e) {}
  delete conf.kp_api_key; delete conf.youtube_api_key;
  conf = Object.assign({
    presence: true, tut: false, compact: false, autoplay: true,
    danmaku: true, confirmOpen: false, hideOnlineTab: false,
    fontScale: 1, reduceMotion: false, defaultFilter: 'all',
    sound: false, keepAwake: false, soundVol: 0.5, haptic: false, lang: 'ru', autoTheme: false,
    blurSpoilers: false, openInPane: true, showBuild: true,
    cinemaOnlySub: false, softColors: false
  }, conf);
  const dark = state.dark !== false;
  const sw = (id, on) => `<button type="button" class="switch ${on?'on':''}" id="${id}"><i></i></button>`;
  showInPane(`
    <div class="card"><div class="val">${slT('Настройки StreamLive SPA','StreamLive SPA settings','Налаштування StreamLive SPA')}</div>
      <p style="font-size:13px;color:var(--muted);margin:8px 0 0">${slT('Только клиент SPA. Не трогают админку StreamLive.','SPA client only. StreamLive admin panel is not affected.','Лише клієнт SPA. Адмінка StreamLive не змінюється.')}</p></div>

    <div class="card"><div class="label">${slT('Язык интерфейса (локально)','Interface language (local)','Мова інтерфейсу (локально)')}</div>
      <select class="field" id="set-lang-select" style="margin:0">${Object.entries(SPA_LOCALES).map(([code,name])=>`<option value="${code}" ${(conf.lang||'ru')===code?'selected':''}>${name}</option>`).join('')}</select>
      <p style="font-size:12px;color:var(--muted);margin:8px 0 0">${slT('Подписи SPA. Без сервера.','SPA labels. No server required.','Підписи SPA. Без сервера.')}</p></div>
    <div class="card"><div class="label">Кинопоиск API</div>
      <input class="field" id="set-kp-key" type="password" autocomplete="off" placeholder="Личный ключ · только на время этой вкладки" value="">
      <p id="set-kp-key-status" style="font-size:12px;color:var(--muted);margin:6px 0 0">Личный ключ хранится только в текущей вкладке и отправляется PHP-прокси при запросе. Можно использовать общий ключ сервера.</p>
    </div>
    <div class="card"><div class="label">YouTube Data API v3</div>
      <input class="field" id="set-youtube-key" type="password" autocomplete="new-password" placeholder="Личный Google API key · только эта вкладка" value="">
      <p class="sub" id="set-youtube-key-status">Личный ключ хранится только в текущей вкладке (переживает перезагрузку страницы) и отправляется серверу в POST. Общий ключ сервера зашифрован в базе и не выдаётся участникам.</p>
    </div>
    <div class="card"><div class="label">${slT('Внешний вид','Appearance','Зовнішній вигляд')}</div>
      <div style="font-size:13px;margin-bottom:6px">Тема</div>
      <div class="seg" id="set-theme-mode" style="margin:0 0 10px">
        <button type="button" data-m="dark" class="${(state.themeMode||'dark')==='dark'?'on':''}">Тёмная</button>
        <button type="button" data-m="oled" class="${(state.themeMode||'')==='oled'?'on':''}">OLED</button>
        <button type="button" data-m="light" class="${(state.themeMode||'')==='light'?'on':''}">Светлая</button>
      </div>
      <div class="drawer-toggle" style="padding:8px 0"><span>Авто-тема по времени</span>${sw('set-autotheme', !!conf.autoTheme)}</div>
      <div class="drawer-toggle" style="padding:8px 0"><span>Компактный список</span>${sw('set-compact', !!conf.compact)}</div>
      <div class="drawer-toggle" style="padding:8px 0"><span>Меньше анимаций</span>${sw('set-motion', !!conf.reduceMotion)}</div>
      <div style="margin-top:8px;font-size:13px">Размер шрифта</div>
      <div style="display:flex;gap:8px;margin-top:8px">
        <button type="button" class="btn-primary" id="set-font-sm" style="margin:0;flex:1;background:rgba(255,255,255,.08)">A−</button>
        <button type="button" class="btn-primary" id="set-font-md" style="margin:0;flex:1;background:rgba(255,255,255,.08)">A</button>
        <button type="button" class="btn-primary" id="set-font-lg" style="margin:0;flex:1;background:rgba(255,255,255,.08)">A+</button>
      </div>
    </div>

    <div class="card"><div class="label">Каталог</div>
      <div style="font-size:13px;margin-bottom:6px">Фильтр при входе</div>
      <div class="seg" id="set-filter" style="margin:0">
        <button type="button" data-f="all" class="${conf.defaultFilter==='all'?'on':''}">Все</button>
        <button type="button" data-f="users" class="${conf.defaultFilter==='users'?'on':''}">Люди</button>
        <button type="button" data-f="channels" class="${conf.defaultFilter==='channels'?'on':''}">Каналы</button>
        <button type="button" data-f="videos" class="${conf.defaultFilter==='videos'?'on':''}">Видео</button>
      </div>
      <div class="drawer-toggle" style="padding:8px 0;margin-top:8px"><span>Спрашивать перед открытием</span>${sw('set-confirm', !!conf.confirmOpen)}</div>
    </div>

    <div class="card"><div class="label">Плеер и комментарии</div>
      <div class="drawer-toggle" style="padding:8px 0"><span>Автовоспроизведение</span>${sw('set-autoplay', conf.autoplay!==false)}</div>
      <div class="drawer-toggle" style="padding:8px 0"><span>Летящие комментарии</span>${sw('set-danmaku', conf.danmaku!==false)}</div>
    </div>

    <div class="card"><div class="label">Приватность</div>
      <div class="drawer-toggle" style="padding:8px 0"><span>Показывать «сейчас онлайн»</span>${sw('set-presence', conf.presence!==false)}</div>
      <div class="drawer-toggle" style="padding:8px 0"><span>Скрыть вкладку Онлайн</span>${sw('set-hideonline', !!conf.hideOnlineTab)}</div>
    </div>

    <div class="card"><div class="label">Звук</div>
      <div class="drawer-toggle" style="padding:8px 0"><span>Звук интерфейса (premium)</span>${sw('set-sound', !!conf.sound)}</div>
      <div style="font-size:13px;margin:8px 0 4px">Громкость UI</div>
      <input type="range" id="set-svol" min="0.1" max="1" step="0.05" value="${conf.soundVol||0.5}" style="width:100%">
      <button type="button" class="btn-primary" id="set-testsound" style="margin-top:10px;background:rgba(255,255,255,.08)">Проверить звук</button>
    </div>
    <div class="card"><div class="label">Прочее</div>
      <div class="drawer-toggle" style="padding:8px 0"><span>Не выключать экран</span>${sw('set-wake', !!conf.keepAwake)}</div>
      <p class="sub" style="font-size:11px;margin:0 0 6px">Работает в поддерживаемых браузерах по HTTPS, пока приложение открыто.</p>
      <div class="drawer-toggle" style="padding:8px 0"><span>Вибрация</span>${sw('set-haptic', !!conf.haptic)}</div>
      <p class="sub" style="font-size:11px;margin:0 0 6px">Зависит от браузера и устройства; на iPhone браузер может не поддерживать вибрацию.</p>
      <div class="drawer-toggle" style="padding:8px 0"><span>Мягкие цвета</span>${sw('set-soft', !!conf.softColors)}</div>
      <div class="drawer-toggle" style="padding:8px 0"><span>Показывать build</span>${sw('set-build', conf.showBuild!==false)}</div>
      <div class="drawer-toggle" style="padding:8px 0"><span>Снова туториал</span>${sw('set-tut', !!conf.tut)}</div>
    </div>

    <div class="card" id="set-quota"><div class="label">Лимит сегодня</div><div class="val">…</div></div>
    <div class="card" id="set-server"><div class="label">Сервер</div><div class="val">…</div></div>

    <div class="card">
      <button type="button" class="btn-primary" id="set-retut" style="margin:0">Открыть туториал</button>
      <button type="button" class="btn-primary" id="set-export" style="margin-top:8px;background:rgba(255,255,255,.08)">Экспорт настроек</button>
      <button type="button" class="btn-primary" id="set-clear" style="margin-top:8px;background:rgba(255,255,255,.08)">Сбросить всё локальное</button>
    </div>
    <div class="card" id="set-build-info" style="font-size:12px;color:var(--muted)">Сборка ${esc(BOOT.build||'')} · настройки только на этом устройстве</div>
    <div class="card"><b>Обновления StreamLive</b><p class="sub">Можно продолжить работу и установить обновление позже.</p><button type="button" class="btn-primary" id="set-check-update">Проверить и установить обновление</button></div>
  `);

  function saveConf() {
    delete conf.kp_api_key; delete conf.youtube_api_key;
    state._spaConf = conf;
    try { localStorage.setItem('streamspa_settings', JSON.stringify(conf)); } catch(e) {}
    applySpaSettings(conf);
  }
  function bindSw(id, key, invert) {
    const el = $(id);
    if (!el) return;
    el.onclick = () => {
      el.classList.toggle('on');
      const on = el.classList.contains('on');
      conf[key] = invert ? !on : on;
      if (key === 'presence') conf.presence = on;
      if (key === 'tut' && on) try { localStorage.removeItem(TUT_KEY); } catch(e) {}
      if (key === 'theme') return;
      saveConf();
    };
  }
  const th = $('set-theme');
  if (th) th.onclick = () => { applyTheme(!state.dark); th.classList.toggle('on', state.dark); };
  bindSw('set-compact', 'compact');
  bindSw('set-motion', 'reduceMotion');
  bindSw('set-confirm', 'confirmOpen');
  bindSw('set-autoplay', 'autoplay');
  bindSw('set-danmaku', 'danmaku');
  bindSw('set-presence', 'presence');
  bindSw('set-hideonline', 'hideOnlineTab');
  bindSw('set-sound', 'sound');
  bindSw('set-autotheme', 'autoTheme');
  const tm = $('set-theme-mode');
  if (tm) tm.querySelectorAll('button').forEach(b => {
    b.onclick = () => { applyTheme(b.dataset.m); openSpaSettings(); };
  });
  const kpk = $('set-kp-key');
  const kpkStatus=$('set-kp-key-status');
  if(kpkStatus)kpkStatus.textContent=spaKinopoiskPersonalApiKey?'Личный ключ задан для этой вкладки. Он не хранится в базе или постоянном хранилище браузера.':'Личный ключ ещё не задан в этой вкладке. Можно использовать общий ключ сервера.';
  if (kpk) kpk.onchange = () => { const value=kpk.value.trim();if(value){spaKinopoiskPersonalApiKey=value;spaWriteSessionKey(SPA_KP_SESSION_KEY,value);if(spaKinopoiskPersonalApiKey)localStorage.setItem('spa_kp_use_personal','1');}kpk.value='';if(kpkStatus)kpkStatus.textContent=spaKinopoiskPersonalApiKey?'Личный ключ задан для этой вкладки и сохранён до закрытия вкладки.':'Личный ключ не изменён.'; };
  const ytk = $('set-youtube-key');
  const ytkStatus=$('set-youtube-key-status');
  if(ytkStatus)ytkStatus.textContent=spaYouTubePersonalApiKey?'Личный YouTube-ключ задан для этой вкладки. Он переживает обновление страницы и не сохраняется в базе.':'Личный YouTube-ключ ещё не задан в этой вкладке. Можно использовать общий ключ сервера.';
  if (ytk) ytk.onchange = () => { const value=ytk.value.trim();if(value){spaYouTubePersonalApiKey=value;spaWriteSessionKey(SPA_YT_SESSION_KEY,value);}ytk.value='';if(ytkStatus)ytkStatus.textContent=spaYouTubePersonalApiKey?'Личный YouTube-ключ сохранён в текущей вкладке до её закрытия.':'Личный ключ не изменён.'; };

  const snd = $('set-sound');
  if (snd) {
    const prev = snd.onclick;
    snd.onclick = () => {
      if (prev) prev();
      spaEnsureAudio();
      if (spaSoundEnabled()) spaPlay('notif');
    };
  }
  bindSw('set-wake', 'keepAwake');
  const wakeSwitch=$('set-wake');
  if(wakeSwitch){const oldWake=wakeSwitch.onclick;wakeSwitch.onclick=()=>{if(oldWake)oldWake();if(conf.keepAwake && !navigator.wakeLock)alert(slT('Эта функция требует браузер с поддержкой Screen Wake Lock и HTTPS.','This feature requires a browser with Screen Wake Lock support and HTTPS.','Ця функція потребує браузера з підтримкою Screen Wake Lock і HTTPS.'));};}
  bindSw('set-tut', 'tut');
  bindSw('set-haptic', 'haptic');
  const hapticSwitch=$('set-haptic');
  if(hapticSwitch){const oldHaptic=hapticSwitch.onclick;hapticSwitch.onclick=()=>{if(oldHaptic)oldHaptic();if(conf.haptic){if(navigator.vibrate)navigator.vibrate([18,35,18]);else alert(slT('Вибрация не поддерживается этим браузером или устройством.','Vibration is not supported by this browser or device.','Вібрація не підтримується цим браузером або пристроєм.'));}};}
  const langSelect=$('set-lang-select');
  if(langSelect) langSelect.onchange=()=>{conf.lang=langSelect.value;state._spaConf=conf;saveConf();applySpaI18n();try{spaAchUnlock('lang_switch','Полиглот');}catch(e){}openSpaSettings();};
  const langSeg = $('set-lang');
  if (langSeg) langSeg.querySelectorAll('button').forEach(b => {
    b.onclick = () => {
      langSeg.querySelectorAll('button').forEach(x=>x.classList.remove('on'));
      b.classList.add('on');
      conf.lang = b.dataset.l;
      state._spaConf = conf;
      saveConf();
      applySpaI18n();
      try { spaAchUnlock('lang_switch','Полиглот'); } catch(e) {}
      openSpaSettings();
    };
  });

  bindSw('set-soft', 'softColors');
  bindSw('set-build', 'showBuild');
  const svol = $('set-svol');
  if (svol) svol.oninput = () => { conf.soundVol = parseFloat(svol.value)||0.5; saveConf(); };
  const testB = $('set-testsound');
  if (testB) testB.onclick = () => { conf.sound = true; state._spaConf = conf; spaEnsureAudio(); spaPlay('ok'); setTimeout(()=>spaPlay('notif'), 150); };


  const fs = { 'set-font-sm': 0.9, 'set-font-md': 1, 'set-font-lg': 1.15 };
  Object.keys(fs).forEach(id => {
    const el = $(id);
    if (el) el.onclick = () => { conf.fontScale = fs[id]; saveConf(); };
  });

  const filt = $('set-filter');
  if (filt) filt.querySelectorAll('button').forEach(b => {
    b.onclick = () => {
      filt.querySelectorAll('button').forEach(x => x.classList.remove('on'));
      b.classList.add('on');
      conf.defaultFilter = b.dataset.f;
      state.filter = conf.defaultFilter;
      saveConf();
    };
  });

  $('set-retut').onclick = () => openTutorial(true);
  $('set-export').onclick = () => {
    const blob = new Blob([JSON.stringify(conf, null, 2)], {type:'application/json'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'streamspa-settings.json';
    a.click();
  };
  $('set-clear').onclick = () => {
    if (!confirm('Сбросить все локальные настройки SPA?')) return;
    try {
      ['streamspa_settings', TUT_KEY, 'streamspa_theme', BUILD_KEY].forEach(k => localStorage.removeItem(k));
    } catch(e) {}
    alert('Готово. Обнови страницу.');
  };

  applySpaSettings(conf);
  applyBuild(BOOT.build);
  const updateBtn=$('set-check-update'); if(updateBtn) updateBtn.onclick=()=>checkBuild(true);
  Promise.all([api('server_mine'), api('limits_status'), api('storage_info')]).then(([d, ld, storageInfo]) => {
    const el = $('set-quota');
    const es = $('set-server');
    if (el) {
      const names = {profile:'Профили',channel:'Каналы',video:'Видео',cinema:'Фильмы и сериалы',music:'Музыка',youtube:'YouTube',tmdb:'TMDB',imdb:'IMDb'};
      const limits = ld && ld.ok && ld.limits ? ld.limits : {};
      const rows = Object.keys(names).map(k => {
        const x = limits[k] || {};
        if (x.subscription) return `<div class="sl-limit-row"><span>${names[k]}</span><b>Без ограничений</b></div>`;
        if (x.limited && Number(x.retry_seconds)>0) return `<div class="sl-limit-row"><span>${names[k]}</span><b><span class="sl-limit-countdown" data-seconds="${Math.max(0,Number(x.retry_seconds)||0)}" data-label="${names[k]}">Загрузка таймера…</span></b></div>`;
        const unit = x.unit ? ' '+esc(x.unit) : '';
        const note = x.server_boost ? ` <small style="color:var(--muted);font-weight:500">· баф +${Number(x.server_boost)}</small>` : '';
        return `<div class="sl-limit-row"><span>${names[k]}${unit}</span><b>${Number(x.remaining||0)} / ${Number(x.quota||0)}${note}</b></div>`;
      }).join('');
      const vaultQ=storageInfo&&storageInfo.ok?storageInfo.actions:null;const vaultValue=vaultQ&&Number(vaultQ.retry_seconds)>0?`<span class="sl-limit-countdown" data-seconds="${Math.max(0,Number(vaultQ.retry_seconds)||0)}" data-label="Хранилище">Загрузка таймера…</span>`:vaultQ&&vaultQ.subscription?'Без ограничений':vaultQ?`${Number(vaultQ.remaining||0)} / ${Number(vaultQ.quota||0)}`:'';const vaultRow=vaultQ?`<div class="sl-limit-row"><span>Хранилище · действия за 24 часа</span><b>${vaultValue}</b></div>`:'';
      el.innerHTML = `<div class="label">Лимиты по категориям</div><div class="sl-limit-list">${rows || '<div class="empty">Не удалось загрузить лимиты</div>'}${vaultRow}</div>
        <p style="font-size:12px;color:var(--muted);margin:8px 0 0">Лимиты и остаток показываются только здесь, в настройках. Основные категории восстанавливаются через 24 часа после достижения лимита; YouTube и сервисные квоты обновляются в полночь по времени сервера. Серверные бафы увеличивают квоты. Для TMDB и IMDb учитываются запросы и операции.</p>`;
      const tickLimitTimers = () => {
        if (!el.isConnected) return;
        let hasActiveTimer = false;
        el.querySelectorAll('.sl-limit-countdown').forEach(node => {
          let left = Math.max(0, Number(node.dataset.seconds)||0);
          const h = Math.floor(left/3600), m = Math.floor((left%3600)/60), sec = left%60;
          node.textContent = left > 0 ? `Доступ через ${[h,m,sec].map(v=>String(v).padStart(2,'0')).join(':')}` : 'Лимит восстановлен — обнови раздел';
          if (left > 0) { node.dataset.seconds = String(left-1); hasActiveTimer = true; }
        });
        if (hasActiveTimer) window.setTimeout(tickLimitTimers,1000);
      };
      tickLimitTimers();
    }
    if (es) {
      if (d && d.server) {
        es.innerHTML = `<div class="label">Сервер</div><div class="val">🏰 ${esc(d.server.name)}</div>
          <p style="font-size:12px;color:var(--muted);margin:8px 0 0">${d.server.members} чел. · бафы +${Number(d.server.boost_units||0)} · код ${esc(d.server.invite_code||'')}</p>`;
      } else {
        es.innerHTML = `<div class="label">Сервер</div><div class="val" style="color:var(--muted)">Не состоишь</div>`;
      }
    }
  });
}

let __spaWakeLockSentinel = null;
let __spaWakeLockPending = false;
async function spaSyncWakeLock(enabled) {
  if (!enabled || !navigator.wakeLock || document.visibilityState !== 'visible') {
    if (__spaWakeLockSentinel) { try { await __spaWakeLockSentinel.release(); } catch(e) {} __spaWakeLockSentinel=null; }
    return;
  }
  if (__spaWakeLockSentinel || __spaWakeLockPending) return;
  __spaWakeLockPending=true;
  try {
    __spaWakeLockSentinel=await navigator.wakeLock.request('screen');
    __spaWakeLockSentinel.addEventListener('release',()=>{__spaWakeLockSentinel=null;});
    if (!(state._spaConf && state._spaConf.keepAwake===true) || document.visibilityState!=='visible') { try { await __spaWakeLockSentinel.release(); } catch(e) {} __spaWakeLockSentinel=null; }
  } catch(e) { __spaWakeLockSentinel=null; }
  finally { __spaWakeLockPending=false; }
}
function spaHaptic(pattern) {
  try {
    const conf=state._spaConf || {};
    if (conf.haptic && navigator.vibrate) navigator.vibrate(pattern || 12);
  } catch(e) {}
}
document.addEventListener('visibilitychange',()=>{
  const conf=state._spaConf || {};
  spaSyncWakeLock(conf.keepAwake===true);
});
document.addEventListener('click',ev=>{
  const target=ev.target && ev.target.closest ? ev.target.closest('button,[role="button"],.switch') : null;
  if(target && !target.disabled) spaHaptic(10);
});

function applySpaSettings(conf) {
  conf = conf || {};
  document.documentElement.style.fontSize = ((conf.fontScale || 1) * 16) + 'px';
  if (conf.accentColor) document.documentElement.style.setProperty('--accent', String(conf.accentColor));
  document.body.classList.toggle('compact-list', !!conf.compact);
  document.body.classList.toggle('reduce-motion', !!conf.reduceMotion);
  document.body.classList.toggle('soft-colors', !!conf.softColors);
  const contSection=document.getElementById('home-continue')?.closest('.home-section'); if(contSection) contSection.style.display=conf.homeContinue===false?'none':'';
  const onlineBtn = document.querySelector('.nav-pill button[data-sc="online"]');
  if (onlineBtn) onlineBtn.style.display = conf.hideOnlineTab ? 'none' : '';
  state._spaConf = conf;
  spaSyncWakeLock(conf.keepAwake===true);
  applyBuild(BOOT.build);
  try { applySpaI18n(); } catch(e) {}
  if (conf.sound) {
    // AudioContext нужно разбудить жестом — первый клик в UI вызовет spaEnsureAudio
    document.body.addEventListener('click', function once() {
      spaEnsureAudio();
      document.body.removeEventListener('click', once);
    }, { once: true });
  }
}

// apply on boot
try {
  applySpaSettings(JSON.parse(localStorage.getItem('streamspa_settings') || '{}'));
} catch(e) {}

async function loadOnline(){
  const box = $('list-online');
  box.innerHTML = '<div class="empty">Загрузка…</div>';
  const data = await api('online');
  if (!data.ok || !data.online.length) { box.innerHTML = '<div class="empty">Никого нет</div>'; return; }
  box.innerHTML = data.online.map(o => rowHtml({
    kind:'user', id:o.user.id, title:o.user.username, username:o.user.username,
    subtitle:[o.tab,o.detail,o.activity].filter(Boolean).join(' · ')||'в сети',
    avatar:o.user.avatar, time:o.updated_at
  })).join('');
  bindRows(box);
}



function spaProfileLevelFromXp(xp){
  xp=Math.max(0,Number(xp)||0);
  var levels=[0,100,250,500,850,1300,1900,2700,3700,5000,6600,8500,10800,13500,16600,20200];
  var level=1;
  for(var i=1;i<levels.length;i++){ if(xp>=levels[i]) level=i+1; else break; }
  var base=levels[Math.min(level-1,levels.length-1)];
  var next=levels[Math.min(level,levels.length-1)]||base;
  var progress=next>base?Math.max(0,Math.min(100,Math.round((xp-base)/(next-base)*100))):100;
  return {xp:xp,level:level,next_xp:next,progress:progress};
}
async function spaFetchProfileLevelData(uname){
  var target=null;
  try{ target=await api('profile_level_status',{params:{username:uname||''}}); }catch(e){ target=null; }
  if(target&&target.ok) return target;
  try{
    var pdata=await api('profile',{params:{username:uname||''}});
    var xp=(pdata&&pdata.profile&&pdata.profile.xp)||0;
    return Object.assign({ok:true,is_self:!!(pdata&&pdata.is_self)}, spaProfileLevelFromXp(xp));
  }catch(e){ return {ok:false}; }
}
function spaBuildLevelHtml(target){
  if(!target||!target.ok) return '<div class="card"><div class="sub">Уровень пока недоступен</div></div>';
  var h='';
  h+='<div class="card" style="padding:0;overflow:hidden;border:1px solid rgba(167,139,250,.35)">'+
    spaLevelMedal(target.level, spaLevelTitle(target.level), target.xp, target.progress,
      target.level>=16?'Максимальный уровень профиля':(Math.max(0,(target.next_xp||0)-(target.xp||0))+' XP до следующего уровня'))+
    '</div>';
  if(target.member_level){
    var m=target.member_level;
    h+='<div class="card" style="padding:0;overflow:hidden;margin-top:10px;border:1px solid rgba(255,255,255,.12)">'+
      '<div class="label" style="padding:10px 16px 0">👥 Уровень участника'+(target.server&&target.server.name?(' · '+esc(target.server.name)):'')+'</div>'+
      spaLevelMedal(m.level, spaLevelTitle(m.level), m.xp, m.progress, m.level>=16?'Максимум':(Math.max(0,(m.next_xp||0)-(m.xp||0))+' XP до следующего'))+
      '</div>';
  }
  if(target.server){
    var v=target.server;
    h+='<div class="card" style="margin-top:10px;background:linear-gradient(135deg,rgba(109,94,252,.24),rgba(38,190,170,.12));border:1px solid rgba(167,139,250,.25)">'+
      '<div class="label">🏰 Уровень сервера · '+esc(v.name)+'</div>'+
      '<div class="val">Уровень '+v.level+' · '+v.members+' участников</div>'+
      '<div class="progress"><i style="width:'+(v.progress||0)+'%"></i></div>'+
      '<div class="sub">'+(v.next_members>v.members?('Ещё '+(v.next_members-v.members)+' участников до следующего уровня'):'Максимальный уровень')+'</div></div>';
  }
  return h;
}
async function spaFetchDailyQuestsHtml(){
  try{
    var data=await api('daily_tasks_status');
    if(!data||!data.ok) return '<div class="card"><div class="sub">Задания временно недоступны</div></div>';
    var items=Array.isArray(data.items)?data.items:[];
    var html='<div class="card" style="background:linear-gradient(135deg,rgba(109,94,252,.24),rgba(30,190,170,.12));border:1px solid rgba(167,139,250,.25)">'+
      '<div class="val">⚡ Ежедневные задания</div>'+
      '<div class="sub">Получено сегодня: <b>'+(data.earned||0)+' XP</b> · обновление в 03:00 МСК</div>'+
      '<div class="sub" style="margin-top:4px">День · '+esc(data.day||'')+'</div></div>';
    if(!items.length) html+='<div class="empty">На сегодня заданий нет</div>';
    items.forEach(function(x){
      html+='<div class="card" style="display:flex;gap:12px;align-items:center;justify-content:space-between">'+
        '<div style="min-width:0;flex:1"><div class="val" style="font-size:14px">'+(x.claimed?'✅':x.done?'🟣':'◯')+' '+esc(x.title)+'</div>'+
        '<div class="sub">'+esc(x.desc||'')+'</div>'+
        '<div class="sub" style="margin-top:4px">Прогресс: '+(x.progress||0)+'/'+(x.need||1)+'</div></div>'+
        '<div style="text-align:right;flex:0 0 auto"><b style="color:#c4a7ff">+'+(x.xp||0)+' XP</b><div style="margin-top:6px">'+
        (x.claimed?'<span class="badge">Получено</span>':x.locked?'<span class="badge">Нужен сервер</span>':x.done?
          '<button type="button" class="btn-primary" data-claim-task="'+esc(x.id)+'" style="margin:0;padding:7px 10px">Забрать</button>':
          '<span class="sub">В процессе</span>')+
        '</div></div></div>';
    });
    html+='<div class="card"><div class="sub">XP заданий повышает уровень обычного профиля. Повторная награда за день невозможна.</div></div>';
    return html;
  }catch(e){ return '<div class="card"><div class="sub">Ошибка загрузки заданий</div></div>'; }
}
function spaBindDailyQuestClaims(root){
  if(!root) return;
  root.querySelectorAll('[data-claim-task]').forEach(function(b){
    b.onclick=async function(){
      b.disabled=true;
      var r=await api('daily_task_claim',{method:'POST',body:{task_id:b.dataset.claimTask}});
      if(!r||!r.ok){ alert((r&&r.error)||'Не удалось забрать награду'); b.disabled=false; return; }
      try{ spaPlay('ok'); }catch(e){}
      var html=await spaFetchDailyQuestsHtml();
      root.innerHTML=html;
      spaBindDailyQuestClaims(root);
    };
  });
}
/* legacy wrappers */
async function spaRenderProfileLevels(host, uname){
  if(!host) return;
  var t=await spaFetchProfileLevelData(uname);
  host.innerHTML=spaBuildLevelHtml(t);
}
async function spaRenderProfileDailyQuests(host){
  if(!host) return;
  host.innerHTML=await spaFetchDailyQuestsHtml();
  spaBindDailyQuestClaims(host);
}


async function loadProfile(username){
  const box = $('profile-body');
  box.innerHTML = '<div class="empty">Загрузка…</div>';
  const uname = username||(state.user&&state.user.username)||'';
  const data = await api('profile', { params: { username: uname } });
  if (!data.ok) { box.innerHTML = '<div class="empty">'+(data.error||'Не найден')+'</div>'; return; }
  // подтянуть сервер, если API не отдал (или для себя)
  if (!data.server && data.is_self) {
    try {
      const sm = await api('server_mine');
      if (sm && sm.ok && sm.server) data.server = { id: sm.server.id, name: sm.server.name, members: sm.server.members };
    } catch(e) {}
  }
  const p = data.profile, s = data.streak||state.streak||{}, pr = data.presence, rank = data.rank;
  const pct = Math.min(100, Math.round(((s.streak_days||0)/(s.streak_need||14))*100));

  /* single paint: load levels + quests before render */
  let levelHtml = '';
  let questsHtml = '';
  try { const lv = await spaFetchProfileLevelData(uname); levelHtml = spaBuildLevelHtml(lv); } catch(e) { levelHtml = ''; }
  if (data.is_self) { try { questsHtml = await spaFetchDailyQuestsHtml(); } catch(e) { questsHtml = ''; } }
  let chHtml = (data.channels||[]).map(ch => rowHtml({ kind:'channel', id:ch.id, slug:ch.slug, title:ch.title, description:ch.description||'', subtitle:'Канал', avatar:ch.logo||'' })).join('');
  box.innerHTML = `
    <div class="prof-head"><img class="av" src="${esc(p.avatar)}"><h2 style="margin:0">${esc(p.username)}</h2>
    <div style="color:var(--muted)">@${esc(p.username)} ${rank&&rank.title?'· '+esc(rank.title):''} · XP ${p.xp||0}</div>
    ${s.subscription_active?'<span class="badge">Подписка до '+esc(s.subscription_until)+'</span>':''}</div>
    <div id="profile-level-cards-embedded">${levelHtml}</div>${data.is_self?`<div id="profile-daily-quests-box" style="margin-top:12px">${questsHtml}</div>`:``}
    ${data.is_self?`<div class="card" style="display:flex;gap:8px;flex-wrap:wrap">
      <button type="button" class="btn-primary" id="pf-activity" style="width:auto;margin:0">✨ Активность</button>
      <button type="button" class="btn-primary" id="pf-queue" style="width:auto;margin:0">🎞 Очередь</button>
      <button type="button" class="btn-primary" id="pf-stats" style="width:auto;margin:0">📊 Статистика</button>
      <button type="button" class="btn-primary" id="pf-notify" style="width:auto;margin:0">🔔 Уведомления</button>
    </div>`:''}
    ${data.server?`<div class="card"><div class="label">Сервер</div><div class="val">🏰 ${esc(data.server.name)} · ${data.server.members} чел.</div></div>`:''}
    <div class="card"><div class="label">Сейчас (видят другие)</div>
      <div class="val">${pr?('<span class="dot"></span> '+esc(pr.detail||pr.tab_name)):'—'}</div></div>
    ${data.is_self?`<div class="card"><div class="label">Серия → подписка</div><div class="val">${s.streak_days||0} / ${s.streak_need||14}</div>
      <div class="progress"><i style="width:${pct}%"></i></div>
      <p style="font-size:12px;color:var(--muted);margin:8px 0 0">14 дней подряд = 30 дней. Без подписки: 1 профиль, 1 канал, 1 видео в сутки.</p></div>
      <div class="card"><button class="btn-primary" id="btn-logout" type="button" style="margin:0">Выйти</button></div>`:''}
    ${chHtml?`<div style="font-weight:700;padding:8px 14px 0">Каналы</div>${chHtml}`:''}`;
  bindRows(box);
  const dqBox=box.querySelector('#profile-daily-quests-box'); if(dqBox) spaBindDailyQuestClaims(dqBox);


  const lo = $('btn-logout');
  if (lo) lo.onclick = async () => { await api('logout',{method:'POST',body:{}}); state.user=null; location.reload(); };
  presence('profile', 'Профиль: @'+p.username);
  try {
    if (p && p.id && state.user && +p.id !== +state.user.id) {
      const pane = document.getElementById('detail-body') || document.getElementById('pane-body');
      if (pane && !pane.querySelector('#dm-start-btn')) {
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'btn-primary'; b.id = 'dm-start-btn';
        b.textContent = '💬 Написать';
        b.style.marginTop = '12px';
        b.onclick = () => startDmWithUser(p.id, p.username);
        pane.appendChild(b);
      }
    }
  } catch(e) {}
}


function applyBuild(b) {
  try {
    const conf=state._spaConf || JSON.parse(localStorage.getItem('streamspa_settings')||'{}');
    const visible=conf.showBuild!==false;
    const line=$('dr-build-line'); if(line) line.style.display=visible?'':'none';
    const info=$('set-build-info'); if(info) info.style.display=visible?'':'none';
    const el=$('dr-build'); if(el) el.textContent=visible?(b || BOOT.build || ''):'';
  } catch(e) {}
}
function checkBuild(forcePrompt) {
  try {
    const base=(BOOT&&BOOT.self)?String(BOOT.self):'streamspa.php';
    const url=base+(base.indexOf('?')>=0?'&':'?')+'api=version&_='+Date.now();
    fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','Pragma':'no-cache'}}).then(async r=>{
      if(!r.ok)return;
      const body=(await r.text()).trim();let d={};try{d=JSON.parse(body.replace(/^\uFEFF/,''));}catch(e){return;}
      const cur=String(BOOT.build||''),remote=String(d.build||'');if(!remote||remote===cur){hideUpdateBar();return;}
      const min=String(d.min_build||'');const mandatory=!!(min&&cur<min);
      let skipped='';try{skipped=localStorage.getItem('spa_update_skip_build')||'';}catch(e){}
      if(!mandatory&&!forcePrompt&&skipped===remote){hideUpdateBar();return;}
      const bar=$('update-bar');if(!bar)return;
      const later=$('update-later');if(later)later.style.display=mandatory?'none':'';
      const span=bar.querySelector('span');if(span)span.textContent=mandatory?'Эта сборка больше не поддерживается. Обнови StreamLive до '+remote+'.':'Доступна новая версия StreamLive ('+remote+'). Можно обновить сейчас или продолжить работу и обновить позже в настройках.';
      bar.classList.add('on');bar.dataset.remoteBuild=remote;
      if(mandatory){bar.dataset.mandatory='1';bar.style.position='fixed';bar.style.inset='0';bar.style.zIndex='2147483000';bar.style.width='100%';bar.style.height='100%';bar.style.maxWidth='none';bar.style.borderRadius='0';bar.style.display='flex';bar.style.flexDirection='column';bar.style.alignItems='center';bar.style.justifyContent='center';bar.style.gap='18px';bar.style.padding='28px';bar.style.background='rgba(8,10,18,.97)';bar.style.backdropFilter='blur(24px)';bar.style.textAlign='center';document.body.style.overflow='hidden';document.documentElement.style.overflow='hidden';}
      else {bar.removeAttribute('style');bar.classList.add('on');}
    }).catch(()=>{});
  } catch(e) {}
}
function hideUpdateBar() {
  try {const el=$('update-bar');if(el){el.classList.remove('on');if(el.dataset.mandatory){el.removeAttribute('style');delete el.dataset.mandatory;document.body.style.overflow='';document.documentElement.style.overflow='';}delete el.dataset.remoteBuild;const later=$('update-later');if(later)later.style.display='';}}catch(e){}
}
function acceptUpdate() {
  try{localStorage.removeItem('spa_update_skip_build');localStorage.setItem('spa_build_seen',BOOT.build||'');}catch(e){}
  const base=(BOOT&&BOOT.self)?String(BOOT.self):'streamspa.php';const sep=base.indexOf('?')>=0?'&':'?';location.replace(base+sep+'_spa_refresh='+Date.now());
}
function deferUpdate(){
  const bar=$('update-bar');const remote=bar&&bar.dataset.remoteBuild;
  if(remote){try{localStorage.setItem('spa_update_skip_build',remote);}catch(e){}}
  hideUpdateBar();
}
function hideSplash(){
  const s = $('splash');
  if (s) s.classList.add('hide');
}
function showDailySubscriptionBanner(){
  if(state.has_sub || !state.user) return;
  const uid=String(state.user.id||'guest'); const today=new Date().toISOString().slice(0,10); const key='spa_sub_banner_'+uid;
  try { if(localStorage.getItem(key)===today) return; localStorage.setItem(key,today); } catch(e) {}
  const old=document.getElementById('spa-daily-sub-banner'); if(old)old.remove();
  const el=document.createElement('div');el.id='spa-daily-sub-banner';
  el.style.cssText='position:fixed;z-index:9998;left:14px;right:14px;bottom:calc(14px + env(safe-area-inset-bottom));max-width:560px;margin:auto;padding:14px 16px;border:1px solid rgba(255,255,255,.16);border-radius:18px;background:rgba(24,25,38,.94);backdrop-filter:blur(22px);box-shadow:0 12px 40px rgba(0,0,0,.35);display:flex;align-items:center;gap:12px;color:#fff';
  el.innerHTML='<div style="font-size:26px">✨</div><div style="flex:1;min-width:0"><b style="display:block;font-size:14px">Открой больше возможностей StreamLive</b><span style="display:block;font-size:12px;opacity:.75;margin-top:3px">Оформи подписку — больше лимитов и дополнительных функций</span></div><button type="button" id="spa-sub-banner-go" style="border:0;border-radius:12px;padding:9px 12px;background:var(--accent,#8b5cf6);color:#fff;font-weight:700">Подписка</button><button type="button" id="spa-sub-banner-close" aria-label="Закрыть" style="border:0;background:transparent;color:#fff;font-size:20px;padding:3px">×</button>';
  document.body.appendChild(el);
  document.getElementById('spa-sub-banner-close').onclick=()=>el.remove();
  document.getElementById('spa-sub-banner-go').onclick=()=>{el.remove();try{openProfile(state.user&&state.user.username);}catch(e){openDrawer();}};
}

function showApp(){
  applySpaI18n();
  setScreen('chats');
  hideSplash();
  try {
    const c = JSON.parse(localStorage.getItem('streamspa_settings')||'{}');
    if (c.defaultFilter) state.filter = c.defaultFilter;
  } catch(e) {}
  loadCatalog(state.filter).catch(()=>{});
  loadStories().catch(()=>{});
  loadSmartHome().catch(()=>{});
  refreshMyServer().catch(()=>{});
  spaLoadCloudPrefs().catch(()=>{});
  wireTutorial();
  try { applyAutoTheme(); spaAchCheck(); } catch(e) {}
  try {
    var lg = localStorage.getItem('spa_lang') || '';
    if (!lg) { localStorage.setItem('spa_lang', 'ru'); }
  } catch(e) {}
  setTimeout(() => showLangBanner(), 300);
  setTimeout(() => openTutorial(false), 800);
  setTimeout(() => showDailySubscriptionBanner(), 1800);
}
function showAuth(){
  setScreen('auth');
  $('main-nav').style.display='none';
  hideSplash();
}

/* events */
$('login-btn').onclick = async () => {
  $('login-err').textContent = '';
  const data = await api('login', { method:'POST', body:{ username:$('login-user').value.trim(), phone:$('login-user').value.trim(), password:$('login-pass').value } });
  if (!data.ok) { $('login-err').textContent = data.error||'Ошибка'; spaPlay('err'); return; }
  spaPlay('ok');
  state.user = data.user; state.streak = data.streak; state.has_sub = !!(data.streak&&data.streak.subscription_active);
  showApp();
};

['btn-menu','btn-menu-2','btn-menu-3','rail-menu'].forEach(id => { const el=$(id); if(el) el.onclick = e => { e.preventDefault(); openDrawer(); }; });
$('drawer-ov').onclick = () => closeDrawer();
document.querySelectorAll('#drawer [data-go]').forEach(b => {
  b.onclick = (e) => {
    try {
      if (e) { e.preventDefault(); e.stopPropagation(); }
      const go = b.dataset.go;
      closeDrawer();
      // экраны
      if (go==='profile') { setScreen('profile'); loadProfile(); return; }
      if (go==='online') { setScreen('online'); loadOnline(); return; }
      if (go==='chats') { setScreen('chats'); loadCatalog(state.filter); loadStories(); return; }
      // оверлеи / функции
      if (go==='clan') { if (state.myServer) openMyServer(); else openServerHub(); return; }
      if (go==='favs') { openFavorites(); return; }
      if (go==='settings') { openSpaSettings(); return; }
      if (go==='sub') { setScreen('profile'); loadProfile(); return; }
      if (go==='messenger') { openMessenger(); return; }
      if (go==='quests') { openSpaQuests(); return; }
      if (go==='achievements') { openSpaAchievements(); return; }
      if (go==='mood') { openSpaMood(); return; }
      if (go==='tutorial') { openTutorial(true); return; }
      if (go==='notes') { openSpaNotes(); return; }
      if (go==='tip') { openSpaTip(); return; }
      if (go==='history') { openWatchHistory(); return; }
      if (go==='passport') { openPassport(); return; }
      if (go==='geo') { openGeoInfo(); return; }
      if (go==='family') { openParental(); return; }
      if (go==='apps') { openSpaApps(); return; }
      if (go==='continue') { openContinueWatch(); return; }
      if (go==='cinema') { openCinema(); return; }
      if (go==='music') { openMusicHub(); return; }
    } catch (err) {
      console.error('menu', err);
      alert('Меню: ' + (err && err.message ? err.message : err));
    }
  };
});
$('drawer-logout').onclick = async () => { await api('logout',{method:'POST',body:{}}); location.reload(); };
function applyTheme(mode) {
  // mode: true/'dark' | false/'light' | 'oled'
  let m = mode;
  if (m === true) m = 'dark';
  if (m === false) m = 'light';
  if (m !== 'light' && m !== 'oled') m = 'dark';
  state.dark = m !== 'light';
  state.themeMode = m;
  document.documentElement.classList.toggle('theme-light', m === 'light');
  document.body.classList.toggle('theme-light', m === 'light');
  document.documentElement.classList.toggle('theme-oled', m === 'oled');
  document.body.classList.toggle('theme-oled', m === 'oled');
  const sw = $('theme-sw');
  if (sw) sw.classList.toggle('on', m !== 'light');
  try { localStorage.setItem('streamspa_theme', m); } catch(e) {} try { if (m==='oled' && typeof spaAchUnlock==='function') spaAchUnlock('theme_oled','OLED-маньяк'); } catch(e) {}
}
$('theme-sw').onclick = (e) => {
  e.preventDefault();
  e.stopPropagation();
  applyTheme(!state.dark);
};
try {
  const th = localStorage.getItem('streamspa_theme');
  if (th === 'light') applyTheme(false);
} catch(e) {}


const filterSeg = $('filter-seg');
if (filterSeg) {
  filterSeg.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-f]');
    if (!b) return;
    e.preventDefault();
    filterSeg.querySelectorAll('button').forEach(x => x.classList.remove('on'));
    b.classList.add('on');
    state.filter = b.getAttribute('data-f') || 'all';
    loadCatalog(state.filter);
  });
}

document.querySelectorAll('.nav-pill button, .rail .rbtn[data-sc]').forEach(b => {
  b.onclick = () => {
    const sc = b.dataset.sc; setScreen(sc);
    if (sc==='chats'){ loadCatalog(state.filter); loadStories(); }
    if (sc==='online') loadOnline();
    if (sc==='clan') { if (state.myServer) openMyServer(); else openServerHub(); }
    if (sc==='profile') loadProfile();
  };
});

function spaOpenGlobalSearch(){
  $('search-ov').classList.add('on');
  $('search-q').value='';
  spaSearchStart();
  setTimeout(()=>{try{$('search-q').focus()}catch(e){}},30);
}
$('q-mini').onclick = spaOpenGlobalSearch;
$('btn-search').onclick = spaOpenGlobalSearch;
$('search-close').onclick = () => $('search-ov').classList.remove('on');
$('search-q').addEventListener('input', () => { clearTimeout(window.__st); window.__st=setTimeout(()=>loadCatalog(state.searchType,$('search-q').value.trim()),220); });
$('search-q').addEventListener('keydown', e => {
  if(e.key==='Enter'){ const q=$('search-q').value.trim(); if(q){spaSearchHistorySave(q);loadCatalog(state.searchType,q);} }
  if(e.key==='Escape') $('search-ov').classList.remove('on');
});
document.addEventListener('keydown', e=>{
  if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();spaOpenGlobalSearch();}
  if(e.key==='Escape'&&$('search-ov').classList.contains('on')) $('search-ov').classList.remove('on');
});
document.querySelectorAll('#search-tabs button').forEach(b => {
  b.onclick = () => {
    document.querySelectorAll('#search-tabs button').forEach(x=>x.classList.remove('on'));
    b.classList.add('on'); state.searchType=b.dataset.t; loadCatalog(state.searchType,$('search-q').value.trim());
  };
});
$('btn-refresh').onclick = () => { loadCatalog(state.filter); loadStories(); loadSmartHome(); };
$('btn-refresh-online').onclick = () => loadOnline();
$('detail-back').onclick = () => {
  window.__musicHubOpen = false;
  destroySpaPlayer();
  // Музыка живёт независимо от текущего экрана; закрытие панели не останавливает трек.
  $('detail-ov').classList.remove('on');
  setDetailTitle('Просмотр');
  if ($('pane-body')) { try { $('pane-body').style.display = 'none'; $('pane-empty').style.display = ''; } catch(e) {} }
};

async function refreshMyServer() {
  const btn = $('rail-server');
  if (!state.user || !btn) return;
  try {
    const data = await api('server_mine');
    if (data && data.ok && data.server) {
      state.myServer = data.server;
      btn.style.display = '';
      btn.title = data.server.name + ' (' + data.server.members + ')';
      btn.textContent = '🏰';
    } else {
      state.myServer = null;
      btn.style.display = 'none';
    }
  } catch(e) { if (btn) btn.style.display = 'none'; }
}

function openServerHub() {
  destroySpaPlayer();
  setDetailTitle('Сервер');
  const html = `
    <div class="card"><div class="val">Сервер (клан)</div>
      <p style="font-size:13px;color:var(--muted);margin:8px 0 0;line-height:1.4">
        Один сервер на аккаунт. Друзья заходят по <b>коду</b>. Чем больше людей — тем выше дневной лимит просмотров без подписки.
      </p></div>
    <div class="card">
      <div class="label">Создать сервер</div>
      <input class="field" id="srv-name" placeholder="Название клана" style="margin-top:8px">
      <input class="field" id="srv-desc" placeholder="Описание (необязательно)">
      <button class="btn-primary" type="button" id="srv-create" style="margin:0">Создать</button>
    </div>
    <div class="card">
      <div class="label">Войти по коду</div>
      <input class="field" id="srv-code" placeholder="Код приглашения" style="margin-top:8px;text-transform:uppercase">
      <button class="btn-primary" type="button" id="srv-join" style="margin:0">Присоединиться</button>
    </div>
    <div class="card"><div class="label">Рейтинг серверов</div><div id="srv-rank"><div class="empty" style="padding:12px">…</div></div></div>
  `;
  const box = showInPane(html);
  $('srv-create').onclick = async () => {
    const r = await api('server_create', { method:'POST', body:{ name:$('srv-name').value.trim(), description:$('srv-desc').value.trim() } });
    if (!r.ok) { alert(r.error||'Ошибка'); return; }
    await refreshMyServer();
    openMyServer();
  };
  $('srv-join').onclick = async () => {
    const r = await api('server_join', { method:'POST', body:{ code:$('srv-code').value.trim() } });
    if (!r.ok) { alert(r.error||'Ошибка'); return; }
    await refreshMyServer();
    openMyServer();
  };
  api('server_rank').then(d => {
    const el = $('srv-rank');
    if (!el) return;
    if (!d.ok || !d.rank.length) { el.innerHTML = '<div class="empty" style="padding:8px">Пока пусто</div>'; return; }
    el.innerHTML = d.rank.map((s,i) => `<div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line);font-size:14px">
      <span>#${i+1} <b>${esc(s.name)}</b>${state.myServer&&+state.myServer.id===+s.id?' <span style=\"color:var(--accent2)\">· ТВОЙ СЕРВЕР</span>':''}</span><span style="color:var(--muted)">${s.members} чел. · ${s.xp||0} XP · ${s.score!=null?s.score:(s.members*10)} очков</span></div>`).join('');
  });
}

async function openMyServer() {
  destroySpaPlayer();
  setDetailTitle('Сервер');
  const data = await api('server_mine');
  if (!data.ok || !data.server) { openServerHub(); return; }
  const s = data.server;
  const full = await api('server_get', { params:{ id: s.id } });
  const members = (full.ok && full.members) ? full.members : [];
  try { const mine=members.find(m=>m.user&&state.user&&+m.user.id===+state.user.id); if(mine&&mine.custom_role)spaAchUnlock('server_role','Своё звание'); } catch(e) {}
  const memCount = s.members || members.length || 1;
  const quota = data.quota || 1;
  const boost = Number((s && s.boost_units) || data.boost_units || 0);
  const amOwner = members.some(m => m.role==='owner' && m.user && state.user && +m.user.id === +state.user.id)
    || (s.role === 'owner');
  const serverPerms = s.permissions || {};
  const canManageRoles = amOwner || !!serverPerms.manage_roles;
  const canManageApps = amOwner || !!serverPerms.manage_apps;
  const canManageHooks = amOwner || !!serverPerms.manage_webhooks;
  const canKickMembers = amOwner || !!serverPerms.kick_members;
  const serverStickerPack = makeServerStickerPack(s);
  let memHtml = members.map(m => `
    <div class="row srv-member-row" data-kind="user" data-username="${esc(m.user.username)}" data-id="${m.user.id}">
      <img class="av" src="${esc(m.user.avatar)}" alt="">
      <div class="body srv-member-main"><div class="title">${esc(m.user.username)} ${m.role==='owner'?'<span class="badge">владелец</span>':''}</div>
      <div class="sub srv-custom-role-line" style="${m.custom_role?'':'display:none'};margin-top:3px;color:var(--accent2);font-weight:700">🎖 Роль: <span class="srv-custom-role">${esc(m.custom_role||'')}</span></div>
      ${(m.badges||[]).length?`<div class="srv-member-badges">${m.badges.map(b=>`<span class="srv-badge-chip" title="${esc(b.title)}">${esc(b.icon)} ${esc(b.title)}</span>`).join('')}</div>`:''}
      <div class="sub">${esc(m.joined_at||'')}</div></div>
      <div class="srv-member-actions">${canManageRoles && m.role!=='owner' ? `<select class="field srv-role-assign" data-uid="${m.user.id}" aria-label="Роль участника"><option value="0">Участник</option></select><button type="button" class="fav-btn srv-kick" data-uid="${m.user.id}" data-un="${esc(m.user.username)}" title="Кик">⛔</button>` : ''}
      ${serverPerms.manage_badges || amOwner ? `<select class="field srv-badge-assign" data-uid="${m.user.id}" aria-label="Выдать значок"><option value="">Выдать значок…</option></select>` : ''}</div>
    </div>`).join('');
  const box = showInPane(`
    <div class="prof-head"><div style="font-size:40px">🏰</div>
      <h2 style="margin:0">${esc(s.name)}</h2>
      <div style="color:var(--muted)">${memCount} уч. · серверные бафы: +${boost}</div><div style="font-size:11px;color:var(--muted);margin-top:4px">Хранилище: ${data.storage_gb||10} ГБ · ${data.promo_x>1?'сейчас действует акция ×'+data.promo_x:''}</div>
      <div id="srv-my-rank" style="margin-top:6px;font-size:13px;color:var(--accent2)">Рейтинг: …</div>
      <div class="sl-tool-card" id="srv-level-card" style="margin-top:12px;text-align:left"><div style="padding-bottom:12px;border-bottom:1px solid var(--line)"><div style="display:flex;justify-content:space-between;align-items:center"><b>🏰 Уровень сервера</b><strong id="srv-community-level">Загрузка…</strong></div><div id="srv-community-medal" style="margin-top:8px"></div><div class="sl-storage-bar" style="margin-top:9px"><i id="srv-community-progress" style="width:0%"></i></div><div class="sub" id="srv-community-next" style="margin-top:7px">Развитие зависит от числа участников</div></div><div style="padding-top:12px"><div style="display:flex;justify-content:space-between;align-items:center"><b>👥 Твой уровень участника</b><strong id="srv-level-value">Загрузка…</strong></div><div id="srv-member-level-medal" style="margin-top:8px"></div><div class="sl-storage-bar" style="margin-top:9px"><i id="srv-level-progress" style="width:0%"></i></div><div class="sub" id="srv-level-xp" style="margin-top:7px">Получай XP за достижения и ежедневные задания</div><div class="sub" id="srv-level-benefits" style="margin-top:5px">Награды открываются с каждым уровнем</div></div><button type="button" class="btn-primary" id="srv-open-daily-quests" style="margin-top:10px">⚡ Ежедневные задания</button></div>
      ${s.description?`<div style="margin-top:8px;color:var(--muted);font-size:13px">${esc(s.description)}</div>`:''}
    </div>
    <div class="card" style="background:linear-gradient(135deg,rgba(109,94,252,.25),rgba(139,92,246,.12))">
      <div class="label">Баффы сервера</div>
      <div class="val" style="font-size:14px">Участники сервера увеличивают дневной лимит и хранилище для каждого участника.</div>
      <p style="font-size:12px;color:var(--muted);margin:8px 0 0">Формула: база 1 + уровни бафа сервера; от 3 участников +2, от 5 +10, от 10 +15, бонус владельца +3. По выходным дневной лимит удваивается. Подписка снимает дневные лимиты. Хранилище: 10 ГБ без подписки и 100 ГБ с подпиской плюс серверные бафы.</p>
    </div>
    <div class="card"><div class="label">Код приглашения</div>
      <div class="val" style="letter-spacing:.08em;user-select:all">${esc(s.invite_code)}</div>
      <button type="button" class="btn-primary" id="srv-copy" style="margin-top:10px">Скопировать код</button>
      <div class="qr-box" style="margin-top:12px">
        <div class="label">QR для друзей</div>
        <img alt="QR" width="180" height="180" style="margin-top:8px;border-radius:8px;background:#fff"
          src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&margin=10&color=000000&bgcolor=ffffff&data=${encodeURIComponent(String(s.invite_code||''))}">
        <p style="font-size:12px;color:var(--muted);margin:8px 0 0">Сканируй → вставь код в «Войти по коду»</p>
      </div>
    </div>
    <div class="card" id="srv-inactivity-box" style="${amOwner?'':'display:none'}">
      <div class="label">🕒 Неактивные участники</div>
      <p style="font-size:12px;color:var(--muted);margin:6px 0">По умолчанию исключение выключено. Если включить, участник будет удалён из сервера после выбранного срока без входа. Его аккаунт, личные данные и достижения не удаляются.</p>
      <select class="field" id="srv-inactive-days" ${amOwner?'':'disabled'}>
        <option value="0">Не исключать автоматически</option><option value="30">30 дней без активности</option><option value="60">60 дней без активности</option><option value="90">90 дней без активности</option>
      </select>
      ${amOwner?'<button type="button" class="btn-primary" id="srv-inactive-save">Сохранить правило</button>':'<div class="sub">Настраивает владелец сервера</div>'}
      <div id="srv-inactive-status" class="sub" style="margin-top:6px"></div>
    </div>
    <div class="card" id="srv-kp-box" style="${amOwner?'':'display:none'}">
      <div class="label">Кинопоиск API сервера</div>
      <p style="font-size:12px;color:var(--muted);margin:6px 0">Владелец задаёт общий ключ — у всех в сервере работает кино</p>
      <input class="field" id="srv-kp-key" type="password" placeholder="Новый X-API-KEY" autocomplete="new-password">
      <button type="button" class="btn-primary" id="srv-kp-save" style="margin:0">Сохранить ключ сервера</button>
      <p id="srv-kp-status" style="font-size:12px;color:var(--muted);margin:8px 0 0"></p>
    </div>
    <div class="card" id="srv-youtube-box" style="${amOwner?'':'display:none'}">
      <div class="label">YouTube Data API сервера</div>
      <p class="sub">Общий ключ хранится только в базе StreamLive и не показывается участникам. Ограничь Google API key сервисом YouTube Data API v3.</p>
      <input class="field" id="srv-youtube-key" type="password" autocomplete="new-password" placeholder="Вставь новый Google API key">
      <button type="button" class="btn-primary" id="srv-youtube-save" style="margin:0">Сохранить общий ключ</button>
      <button type="button" class="btn-primary" id="srv-youtube-clear" style="margin-top:8px;background:rgba(255,255,255,.08)">Удалить общий ключ</button>
      <p id="srv-youtube-status" class="sub" style="margin-top:8px"></p>
    </div>
    <div class="card" id="srv-tmdb-box" style="${amOwner?'':'display:none'}">
      <div class="label">TMDB API сервера</div>
      <p class="sub">Общий TMDB API key хранится зашифрованным на сервере. Участники получают только доступ к поиску, сам ключ им не передаётся.</p>
      <input class="field" id="srv-tmdb-key" type="password" autocomplete="new-password" placeholder="TMDB API Key (v3 auth)">
      <button type="button" class="btn-primary" id="srv-tmdb-save" style="margin:0">Сохранить TMDB key</button>
      <button type="button" class="btn-primary" id="srv-tmdb-clear" style="margin-top:8px;background:rgba(255,255,255,.08)">Удалить TMDB key</button>
      <p id="srv-tmdb-status" class="sub" style="margin-top:8px"></p>
    </div>
    <div class="card" id="srv-imdb-box" style="${amOwner?'':'display:none'}"><div class="label">⭐ IMDb / OMDb API</div><p class="sub">IMDb-поиск через OMDb. Ключ хранится зашифрованным на сервере и не показывается участникам. Это OMDb, а не официальный IMDb Graph API; AWS аккаунт для этого варианта не нужен.</p><details style="margin:8px 0"><summary style="cursor:pointer;color:var(--accent2);font-weight:700">📘 Подробная инструкция: как получить IMDb key</summary><ol class="sub" style="padding-left:20px;line-height:1.65"><li>Открой официальный сайт OMDb: <a href="https://www.omdbapi.com/apikey.aspx" target="_blank" rel="noopener noreferrer">omdbapi.com/apikey.aspx</a>.</li><li>Выбери план Free (если он доступен для твоего использования) или подходящий платный план.</li><li>Введи действующий адрес электронной почты и отправь заявку.</li><li>Открой письмо от OMDb и подтверди адрес, если сервис попросит.</li><li>Скопируй выданный API key из письма или страницы активации.</li><li>Вернись сюда, вставь ключ в поле ниже и нажми «Сохранить IMDb key».</li><li>Включи переключатель IMDb. После этого IMDb появится в поиске у всех участников сервера.</li></ol><p class="sub">Бесплатный план имеет дневной лимит и может изменяться самим OMDb. Если ключ не активирован, превышен лимит или сайт не отвечает, IMDb-поиск временно не будет доступен.</p></details><a class="sub" href="https://www.omdbapi.com/apikey.aspx" target="_blank" rel="noopener noreferrer">Получить OMDb API key ↗</a><input class="field" id="srv-imdb-key" type="password" autocomplete="off" placeholder="OMDb API key"><button type="button" class="btn-primary" id="srv-imdb-save">Сохранить IMDb key</button><button type="button" class="btn-primary" id="srv-imdb-clear" style="background:rgba(255,255,255,.08)">Удалить ключ</button><label class="sub" style="display:flex;gap:9px;align-items:center;margin-top:12px"><input id="srv-imdb-enabled" type="checkbox" style="width:18px;height:18px"> Включить IMDb для всех участников сервера</label><div class="sub" id="srv-imdb-status"></div></div>
    <div class="card" id="srv-platform-hub" style="${canManageApps?'':'display:none'}">
      <div class="label">🧩 API Hub · сервисы сервера</div>
      <p style="font-size:12px;color:var(--muted)">Добавляй собственные HTTPS-сервисы. Они появятся в общем каталоге приложений этого сервера.</p>
      <input class="field" id="srv-app-name" placeholder="Название сервиса">
      <input class="field" id="srv-app-url" placeholder="https://api.example.com/v1" aria-label="Базовый HTTPS URL API"><div class="sub">Для продвинутого приложения укажи именно базовый адрес API, а не адрес сайта.</div>
      <input class="field" id="srv-app-icon" maxlength="8" value="🔌" placeholder="Иконка">
      <input class="field" id="srv-app-desc" placeholder="Описание сервиса">
      <details style="margin:8px 0" open><summary style="cursor:pointer;color:var(--accent2)">⚙️ Продвинутое приложение · импорт OpenAPI</summary>
        <p class="sub">Вставь OpenAPI 3.x / Swagger 2.0 JSON. StreamLive построит рабочие формы для GET, POST, PUT, PATCH и DELETE. Запросы выполняются напрямую из браузера участника; API должен разрешать CORS. Секреты владельца не сохраняются и не раздаются участникам.</p>
        <textarea class="field" id="srv-app-api-spec" rows="8" spellcheck="false" placeholder='{"openapi":"3.0.0","info":{"title":"My API","version":"1.0.0"},"paths":{"/status":{"get":{"summary":"Получить статус","responses":{"200":{"description":"OK"}}}}}}'></textarea>
        <div class="sub">Авторизация: Bearer-токен вводит каждый участник при открытии приложения. Токен остаётся только в памяти его вкладки.</div>
      </details>
      <details style="margin:8px 0"><summary style="cursor:pointer;color:var(--accent2)">Быстрый тестовый сервис · JSON-макет</summary>
        <p class="sub">Укажи структуру экранов и полей. StreamLive создаст карточку сервиса для всех участников сервера. Здесь задаётся интерфейс; выполнение произвольных API-запросов и хранение секретных ключей не включаются автоматически.</p>
        <textarea class="field" id="srv-app-manifest" rows="7" placeholder='{"title":"Мой сервис","subtitle":"Панель сервера","sections":[{"title":"Основное","fields":[{"key":"status","label":"Статус","type":"select","options":["Включён","Выключен"]},{"key":"note","label":"Заметка","type":"textarea"}]}]}'></textarea>
      </details>
      <select class="field" id="srv-app-mode"><option value="open">Открывать отдельно</option><option value="embed">Открывать внутри StreamLive (если сайт разрешает iframe)</option></select>
      <select class="field" id="srv-app-platform"><option value="universal">Все платформы · универсальная ссылка</option><option value="android">Android · App Links</option><option value="ios">iOS · Universal Links</option><option value="desktop">Windows / macOS / Linux</option><option value="web">Веб-приложение</option><option value="api">API / интеграция</option></select>
      <div id="srv-app-quota" class="sub" style="margin:8px 0">Создание: 10 сервисов за 30 дней · требуется активная подписка SPA.</div>
      <button type="button" class="btn-primary" id="srv-app-add">Добавить сервис</button>
      <div id="srv-app-list" style="margin-top:10px"></div>
      <div class="label" style="margin-top:18px">🛍️ Каталог приложений сервера</div>
      <p class="sub">Опубликованные приложения доступны всем участникам этого сервера. Добавляй нужные в свою коллекцию.</p>
      <input class="field" id="srv-app-catalog-search" placeholder="Поиск приложений…">
      <div id="srv-app-catalog" style="margin-top:10px"></div>
    </div>
    <div class="card" id="srv-webhooks-box" style="${canManageHooks?'':'display:none'}">
      <div class="label">🪝 Webhooks · автоматизация</div>
      <p style="font-size:12px;color:var(--muted)">Создай подключение к своему боту или сайту. Сейчас endpoint сохраняется как черновик; отправка событий включится после проверки URL и подписи, чтобы не открыть SSRF-уязвимость.</p>
      <input class="field" id="srv-hook-name" placeholder="Название подключения">
      <input class="field" id="srv-hook-url" placeholder="https://example.com/streamlive-hook">
      <div style="font-size:12px;color:var(--muted);margin:8px 0">События</div>
      <label><input type="checkbox" class="srv-hook-event" value="message.created" checked> Новое сообщение</label><br>
      <label><input type="checkbox" class="srv-hook-event" value="member.joined" checked> Новый участник</label><br>
      <label><input type="checkbox" class="srv-hook-event" value="member.left"> Участник вышел</label><br>
      <label><input type="checkbox" class="srv-hook-event" value="app.created"> Добавлен сервис</label>
      <button type="button" class="btn-primary" id="srv-hook-add">Сохранить Webhook</button>
      <div id="srv-hook-list" style="margin-top:10px"></div>
    </div>
    <div class="card" id="srv-roles-box" style="${canManageRoles?'':'display:none'}">
      <div class="label">🛡️ Роли и права</div>
      <p style="font-size:12px;color:var(--muted)">Владелец создаёт роли и сам выбирает разрешения. Администратор не получает полный доступ автоматически.</p>
      <input class="field" id="srv-role-name" placeholder="Например: Модератор">
      <div id="srv-role-perms" style="display:grid;grid-template-columns:1fr 1fr;gap:7px;font-size:12px;margin:8px 0">
        <label><input type="checkbox" value="manage_chat"> Управлять чатом</label><label><input type="checkbox" value="kick_members"> Кикать участников</label>
        <label><input type="checkbox" value="manage_apps"> Управлять сервисами</label><label><input type="checkbox" value="manage_webhooks"> Управлять Webhooks</label>
        <label><input type="checkbox" value="manage_roles"> Управлять ролями</label><label><input type="checkbox" value="send_announcements"> Объявления</label><label><input type="checkbox" value="manage_welcome"> Приветствие</label><label><input type="checkbox" value="manage_badges"> Значки</label><label><input type="checkbox" value="manage_tickets"> Обращения</label><label><input type="checkbox" value="manage_polls"> Опросы</label><label><input type="checkbox" value="manage_events"> События</label><label><input type="checkbox" value="view_audit"> Журнал действий</label>
      </div>
      <button type="button" class="btn-primary" id="srv-role-add">Создать роль</button>
      <div id="srv-role-list" style="margin-top:10px"></div>
    </div>
    <div class="card" id="srv-welcome-box" style="${(amOwner||serverPerms.manage_welcome)?'':'display:none'}"><div class="label">👋 Приветствие новых участников</div><label class="sub"><input type="checkbox" id="srv-welcome-enabled" checked> Включить приветствие</label><textarea class="field" id="srv-welcome-message" rows="2" placeholder="Добро пожаловать, {user}!">Добро пожаловать, {user}!</textarea><input class="field" id="srv-welcome-rules" placeholder="HTTPS-ссылка на правила (необязательно)"><button class="btn-primary" id="srv-welcome-save">Сохранить приветствие</button><div class="sub">Переменная {user} подставляет имя нового участника.</div></div>
    <div class="card" id="srv-badges-box" style="${(amOwner||serverPerms.manage_badges)?'':'display:none'}"><div class="label">🏅 Достижения и коллекционные значки</div><div class="sub">Значок по умолчанию будет показываться участникам, которым не выдали личный значок.</div><select class="field" id="srv-badge-default"><option value="0">Не показывать общий значок</option></select><button type="button" class="btn-primary" id="srv-badge-default-save">Сохранить общий значок</button><input class="field" id="srv-badge-title" placeholder="Название значка"><input class="field" id="srv-badge-code" placeholder="Уникальный код (например, helper)"><input class="field" id="srv-badge-icon" value="🏅" placeholder="Эмодзи"><input class="field" id="srv-badge-desc" placeholder="Описание"><button class="btn-primary" id="srv-badge-create">Создать значок</button><div id="srv-badges-list"></div></div>
    <div class="card" id="srv-collections-box"><div class="label">🎬 Умные подборки</div><input class="field" id="srv-collection-title" placeholder="Название подборки"><input class="field" id="srv-collection-desc" placeholder="Описание"><textarea class="field" id="srv-collection-items" rows="4" placeholder='Список JSON: [{"title":"Название","url":"https://..."}]'></textarea><label class="sub"><input type="checkbox" id="srv-collection-shared" checked> Поделиться с сервером</label><button class="btn-primary" id="srv-collection-save">Сохранить подборку</button><div id="srv-collections-list"></div></div>
    <div class="card" id="srv-stats-box"><div class="label">📊 Статистика развития сервера</div><div id="srv-stats-content" class="sub">Загрузка статистики…</div></div>
    <div class="card" id="srv-tickets-box"><div class="label">🎫 Обращения к администрации</div><input class="field" id="srv-ticket-subject" placeholder="Тема обращения"><textarea class="field" id="srv-ticket-body" rows="3" placeholder="Опиши вопрос или проблему"></textarea><button class="btn-primary" id="srv-ticket-create">Отправить обращение</button><div id="srv-tickets-list"></div></div>
    <div class="card" id="srv-polls-box"><div class="label">🗳️ Голосования и опросы</div><input class="field" id="srv-poll-question" placeholder="Вопрос голосования"><textarea class="field" id="srv-poll-options" rows="3" placeholder="Каждый вариант с новой строки"></textarea><button class="btn-primary" id="srv-poll-create">Создать голосование</button><div id="srv-polls-list"></div></div>
    <div class="card" id="srv-events-box"><div class="label">📅 События и календарь</div><input class="field" id="srv-event-title" placeholder="Название события"><textarea class="field" id="srv-event-desc" rows="2" placeholder="Описание"></textarea><input class="field" id="srv-event-date" type="datetime-local"><input class="field" id="srv-event-location" placeholder="Место или ссылка"><button class="btn-primary" id="srv-event-create">Создать событие</button><div id="srv-events-list"></div></div>
    <div class="card" id="srv-audit-box" style="${(amOwner||serverPerms.view_audit)?'':'display:none'}"><div class="label">📜 Журнал действий сервера</div><div id="srv-audit-list" class="sub">Загрузка журнала…</div></div>
    <div class="card"><div class="label">Участники</div></div>
    ${memHtml || '<div class="empty">Нет</div>'}
    <div class="card"><div class="label">Чат сервера · стикеры сервера</div>
      <div id="srv-chat" style="max-height:220px;overflow-y:auto;margin:8px 0;font-size:13px"></div>
      ${engineStickerBarHtml('srv-engine-st')}
      ${serverStickerBarHtml(serverStickerPack)}
      <div class="danmaku-input"><input id="srv-msg" maxlength="500" placeholder="Сообщение всем в сервере">
      <button type="button" id="srv-send">→</button></div>
    </div>
    <div class="card"><button type="button" class="btn-primary" id="srv-leave" style="margin:0;background:#7f1d1d">Выйти из сервера</button></div>
  `);
  bindRows(box);
  api('profile_level_status').then(d=>{if(!d||!d.ok)return;const sv=d.server||null,m=d.member_level||null;if(sv){const a=$('srv-community-level'),b=$('srv-community-progress'),c=$('srv-community-next');if(a)a.textContent='Уровень '+sv.level;const sm=$('srv-community-medal');if(sm)sm.innerHTML=spaLevelMedal(sv.level,'Уровень сервера',sv.members,sv.progress,sv.next_members>sv.members?`${sv.members} / ${sv.next_members} участников`:'Максимальный уровень сервера');if(b)b.style.width=(sv.progress||0)+'%';if(c)c.textContent=sv.next_members>sv.members?sv.members+' / '+sv.next_members+' участников · ещё '+(sv.next_members-sv.members)+' до следующего уровня':'Максимальный уровень сервера';}if(m){const lv=$('srv-level-value'),bar=$('srv-level-progress'),xp=$('srv-level-xp'),benefits=$('srv-level-benefits');if(lv)lv.textContent='Уровень '+(m.level||1)+' · '+spaLevelTitle(m.level||1);const medal=$('srv-member-level-medal');if(medal)medal.innerHTML=spaLevelMedal(m.level||1,spaLevelTitle(m.level||1),m.xp||0,m.progress||0,m.level>=16?'Максимальный уровень':Math.max(0,(m.next_xp||0)-(m.xp||0))+' XP до следующего уровня');if(bar)bar.style.width=(m.progress||0)+'%';if(xp)xp.textContent=(m.xp||0)+' XP · '+(m.level>=16?'Максимальный уровень':Math.max(0,(m.next_xp||0)-(m.xp||0))+' XP до следующего уровня');if(benefits)benefits.textContent='XP начисляется за достижения и ежедневные задания';}});const questBtn=$('srv-open-daily-quests');if(questBtn)questBtn.onclick=()=>openSpaQuests();
  api('server_rank').then(d=>{const el=$('srv-my-rank');if(!el||!d||!d.ok)return;const i=(d.rank||[]).findIndex(x=>+x.id===+s.id);const row=i>=0?d.rank[i]:null;el.textContent=row?('Рейтинг сервера: #'+(i+1)+' · '+(row.xp||0)+' XP · '+(row.score||0)+' очков'):'Сервер пока не попал в топ';});
  document.querySelectorAll('.srv-kick').forEach(b => {
    b.onclick = async (e) => {
      e.stopPropagation();
      const un = b.dataset.un || b.dataset.uid;
      if (!confirm('Кикнуть @' + un + '? Сообщения удалятся.')) return;
      const r = await api('server_kick', { method:'POST', body:{ user_id: b.dataset.uid } });
      if (r && r.ok) { spaPlay('ok'); openMyServer(); }
      else alert((r && r.error) || 'Ошибка');
    };
  });
  const copy = $('srv-copy');
  if (copy) copy.onclick = () => { navigator.clipboard.writeText(s.invite_code).then(()=>alert('Скопировано')).catch(()=>prompt('Код', s.invite_code)); };

  // YouTube shared key: only the owner can set/clear it; the API returns metadata only.
  (async () => {
    const d=await api('server_youtube_get');
    const inp=$('srv-youtube-key'),st=$('srv-youtube-status'),btn=$('srv-youtube-save'),clear=$('srv-youtube-clear');
    if(!inp)return;
    if(d&&d.ok){
      if(d.is_owner){if(st)st.textContent=d.has_key?'Общий ключ задан · хранится только на сервере':'Ключ ещё не задан';}
      else {inp.disabled=true;inp.placeholder=d.has_key?'Общий ключ задан владельцем':'Владелец ещё не добавил ключ';if(btn)btn.style.display='none';if(clear)clear.style.display='none';if(st)st.textContent=d.has_key?'Поиск использует общий ключ сервера':'Попроси владельца сервера настроить YouTube API';}
    }
    if(btn)btn.onclick=async()=>{const key=(inp.value||'').trim();if(!key){if(st)st.textContent='Вставь новый ключ. Пустое поле не изменяет сохранённый ключ.';return;}const r=await api('server_youtube_set',{method:'POST',body:{youtube_api_key:key}});if(r&&r.ok){inp.value='';if(st)st.textContent='Общий ключ сохранён и скрыт от участников';spaPlay('ok');}else alert((r&&r.error)||'Не удалось сохранить YouTube key');};
    if(clear)clear.onclick=async()=>{if(!confirm('Удалить общий YouTube API key сервера?'))return;const r=await api('server_youtube_set',{method:'POST',body:{youtube_api_key:''}});if(r&&r.ok){if(st)st.textContent='Общий ключ удалён';spaPlay('ok');}else alert((r&&r.error)||'Не удалось удалить ключ');};
  })();

  // TMDB shared key: encrypted at rest and never sent to members.
  (async()=>{
    const d=await api('server_tmdb_get');const inp=$('srv-tmdb-key'),st=$('srv-tmdb-status'),btn=$('srv-tmdb-save'),clear=$('srv-tmdb-clear');if(!inp)return;
    if(d&&d.ok){if(d.is_owner){if(st)st.textContent=d.has_key?'TMDB key задан · хранится зашифрованным':'Ключ ещё не задан';}else{inp.disabled=true;inp.placeholder=d.has_key?'Общий TMDB key задан владельцем':'Владелец ещё не добавил ключ';if(btn)btn.style.display='none';if(clear)clear.style.display='none';if(st)st.textContent=d.has_key?'Поиск TMDB использует общий ключ сервера':'Попроси владельца сервера добавить TMDB key';}}
    if(btn)btn.onclick=async()=>{const key=(inp.value||'').trim();if(!key){if(st)st.textContent='Вставь новый TMDB API key.';return;}const r=await api('server_tmdb_set',{method:'POST',body:{tmdb_api_key:key}});if(r&&r.ok){inp.value='';if(st)st.textContent='TMDB key сохранён и скрыт от участников';spaPlay('ok');}else alert(r&&r.error||'Не удалось сохранить TMDB key');};
    if(clear)clear.onclick=async()=>{if(!confirm('Удалить общий TMDB API key сервера?'))return;const r=await api('server_tmdb_set',{method:'POST',body:{tmdb_api_key:''}});if(r&&r.ok){if(st)st.textContent='TMDB key удалён';spaPlay('ok');}else alert(r&&r.error||'Не удалось удалить TMDB key');};
  })();

  // IMDb/OMDb shared key: encrypted at rest, never sent to members.
  (async()=>{const d=await api('server_imdb_get'),inp=$('srv-imdb-key'),st=$('srv-imdb-status'),btn=$('srv-imdb-save'),clear=$('srv-imdb-clear'),toggle=$('srv-imdb-enabled');if(!inp)return;if(d&&d.ok){if(toggle){toggle.checked=!!d.enabled;toggle.disabled=!d.is_owner||!d.has_key;}if(d.is_owner){if(st)st.textContent=d.has_key?(d.enabled?'IMDb включён для участников сервера':'Ключ сохранён · IMDb пока выключен'):'Ключ ещё не задан';}else{inp.disabled=true;inp.placeholder=d.has_key?'Общий IMDb key задан владельцем':'Владелец ещё не добавил IMDb key';if(btn)btn.style.display='none';if(clear)clear.style.display='none';if(toggle)toggle.style.display='none';if(st)st.textContent=d.enabled?'IMDb включён владельцем для участников':'IMDb выключен владельцем сервера';}}if(btn)btn.onclick=async()=>{const key=(inp.value||'').trim();if(!key){if(st)st.textContent='Вставь OMDb API key.';return;}const r=await api('server_imdb_set',{method:'POST',body:{imdb_api_key:key,imdb_enabled:'1'}});if(r&&r.ok){inp.value='';if(toggle){toggle.checked=true;toggle.disabled=false;}if(st)st.textContent='IMDb key сохранён · IMDb включён для участников';spaPlay('ok');}else alert(r&&r.error||'Не удалось сохранить IMDb key');};if(clear)clear.onclick=async()=>{if(!confirm('Удалить общий IMDb key сервера?'))return;const r=await api('server_imdb_set',{method:'POST',body:{imdb_api_key:'',imdb_enabled:'0'}});if(r&&r.ok){if(toggle){toggle.checked=false;toggle.disabled=true;}if(st)st.textContent='IMDb key удалён · IMDb выключен';spaPlay('ok');}else alert(r&&r.error||'Не удалось удалить IMDb key');};if(toggle)toggle.onchange=async()=>{const r=await api('server_imdb_set',{method:'POST',body:{imdb_enabled:toggle.checked?'1':'0'}});if(!r||!r.ok){toggle.checked=!toggle.checked;alert(r&&r.error||'Не удалось изменить настройку IMDb');return;}if(st)st.textContent=r.enabled?'IMDb включён для всех участников':'IMDb выключен · остаётся только Кинопоиск';spaPlay('ok');};})();

  // KP key bind
  (async () => {
    const sk = await api('server_kp_get');
    const inp = $('srv-kp-key');
    const st = $('srv-kp-status');
    const btn = $('srv-kp-save');
    if (!inp) return;
    if (sk && sk.ok) {
      if (sk.is_owner) {
        if (st) st.textContent = sk.has_key ? 'Ключ задан · зашифрован на сервере. Для замены введи новый.' : 'Ключ ещё не задан';
      } else {
        inp.disabled = true;
        inp.placeholder = sk.has_key ? 'Общий ключ задан владельцем' : 'Владелец ещё не задал';
        if (btn) btn.style.display = 'none';
        if (st) st.textContent = sk.has_key ? 'Используется ключ сервера (можно сменить на свой в Кино)' : 'Ждём ключ от владельца';
      }
    }
    if (btn) btn.onclick = async () => {
      const r = await api('server_kp_set', { method:'POST', body:{ kp_api_key: (inp.value||'').trim() } });
      if (r && r.ok) {
        if (st) st.textContent = 'Сохранено';
        inp.value=''; spaServerKpHasKey=!!r.has_key;
        spaPlay('ok');
        await refreshServerKpKey();
      } else {
        alert((r && r.error) || 'Ошибка сохранения');
      }
    };
  })();

  // Настройка автоматического исключения за неактивность (по умолчанию выключена)
  (async()=>{
    const sel=$('srv-inactive-days'), save=$('srv-inactive-save'), status=$('srv-inactive-status');
    if(!sel)return;
    const d=await api('server_inactivity_get'); if(d&&d.ok){sel.value=String(d.days||0);if(status)status.textContent=d.days?('Автоисключение через '+d.days+' дней'):'Автоисключение выключено';}
    if(save)save.onclick=async()=>{const r=await api('server_inactivity_set',{method:'POST',body:{days:sel.value}});if(!r.ok){alert(r.error||'Ошибка');return;}if(status)status.textContent=r.days?('Сохранено: '+r.days+' дней'):'Автоисключение выключено';spaPlay('ok');};
  })();

  // API Hub: server app directory
  (async()=>{
    const canManage = canManageApps;
    const platformLabel=(p)=>({universal:'Все платформы',android:'Android',ios:'iOS',desktop:'Компьютер',web:'Веб',api:'API'})[p]||'Все платформы';
    const add=$('srv-app-add'); if(add && !canManage){add.style.display='none';}
    async function openServerApp(a){
      if(a&&a.service_type==='api'&&a.manifest&&a.manifest.api){
        const m=a.manifest, apiCfg=m.api, ops=apiCfg.operations||[];
        const requestHistory=[];
        const cards=ops.map((op,i)=>`<form class="card srv-api-op" data-api-op="${i}" style="margin:9px 0"><div class="row" style="gap:8px;align-items:center"><span class="badge">${esc(op.method)}</span><b>${esc(op.summary||op.id)}</b></div><div class="sub" style="overflow-wrap:anywhere">${esc(op.path)}</div>${['POST','PUT','PATCH','DELETE'].includes(String(op.method).toUpperCase())?'<div class="sub" style="color:var(--warning,#d99b32);margin-top:5px">Изменяющий запрос: перед отправкой потребуется подтверждение.</div>':''}${(op.fields||[]).map((f,j)=>`<label class="sub" style="display:block;margin-top:7px">${esc(f.label||f.name)}${f.required?' *':''}${f.type==='select'?`<select class="field" data-api-field="${esc(f.name)}" data-api-in="${esc(f.in)}" ${f.required?'required':''}>${(f.options||[]).map(v=>`<option value="${esc(v)}">${esc(v)}</option>`).join('')}</select>`:`<input class="field" data-api-field="${esc(f.name)}" data-api-in="${esc(f.in)}" type="${f.type==='number'?'number':'text'}" ${f.required?'required':''} placeholder="${esc(f.name)}">`}</label>`).join('')}${(op.body_fields||[]).length?`<div class="sub" style="margin-top:8px">JSON body</div>${op.body_fields.map(f=>`<label class="sub" style="display:block;margin-top:6px">${esc(f.label||f.name)}${f.required?' *':''}${f.type==='select'?`<select class="field" data-api-body="${esc(f.name)}" ${f.required?'required':''}>${(f.options||[]).map(v=>`<option value="${esc(v)}">${esc(v)}</option>`).join('')}</select>`:`<input class="field" data-api-body="${esc(f.name)}" type="${f.type==='number'?'number':f.type==='checkbox'?'checkbox':'text'}" ${f.required?'required':''}>`}</label>`).join('')}`:''}<button class="btn-primary" type="submit" style="margin-top:10px">Выполнить запрос</button><pre class="sub srv-api-result" style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:260px;overflow:auto;margin-top:8px"></pre></form>`).join('');
        const authCfg=apiCfg.auth||{type:'bearer',name:'Authorization'};const authLabel=authCfg.type==='header'?'API key / заголовок':(authCfg.type==='query'?'API key / параметр URL':'Bearer-токен');
        showInPane(`<div class="prof-head"><div style="font-size:32px">${esc(a.icon||'🔌')}</div><h2>${esc(m.title||a.name)}</h2><div class="sub">${esc(m.subtitle||a.description||'')}</div><button type="button" class="btn-primary" id="srv-api-export" style="width:auto">Скачать конфигурацию</button></div><div class="card"><b>Подключение к API</b><div class="sub">${esc(a.base_url)} · Запросы выполняются из браузера. API должен разрешать CORS для домена StreamLive.</div><label class="sub" style="display:block;margin-top:8px">${esc(authLabel)}<input id="srv-api-token" class="field" type="password" autocomplete="off" placeholder="Ключ/токен этого участника"></label><div class="sub">Ключ/токен не сохраняется на сервере и очищается при закрытии приложения. Для безопасности запросы выполняются без cookie; время ожидания ограничено 20 секундами, ответы для просмотра ограничены 64 КБ.</div></div><div class="card" id="srv-api-diagnostics"><div class="row" style="justify-content:space-between;align-items:center"><b>Диагностика запросов</b><button type="button" class="icon-btn" id="srv-api-history-clear">Очистить</button></div><div class="sub" id="srv-api-history-summary">Запросов в этой сессии пока нет. История не содержит токенов и тел запросов.</div><div id="srv-api-history-list" class="sub" style="margin-top:7px"></div></div><div>${cards||'<div class="empty">В спецификации нет операций</div>'}</div>`);
        const root=$('pane-body')||$('detail-body')||document;spaTranslateDom(root);
        const exportBtn=$('srv-api-export');if(exportBtn)exportBtn.onclick=()=>{const payload={format:'streamlive-api-app',version:1,name:a.name,base_url:a.base_url,description:a.description||'',icon:a.icon||'🔌',manifest:a.manifest};const blob=new Blob([JSON.stringify(payload,null,2)],{type:'application/json'});const url=URL.createObjectURL(blob);const link=document.createElement('a');link.href=url;link.download=(String(a.name||'streamlive-app').replace(/[^a-z0-9_-]+/gi,'_').slice(0,48)||'streamlive-app')+'.json';link.click();setTimeout(()=>URL.revokeObjectURL(url),1500);};
        const historyList=$('srv-api-history-list'),historySummary=$('srv-api-history-summary');
        const drawApiHistory=()=>{if(historySummary)historySummary.textContent='Запросов в этой сессии: '+requestHistory.length+'. История не содержит токенов и тел запросов.';if(historyList)historyList.innerHTML=requestHistory.slice(0,8).map(x=>`<div style="padding:5px 0;border-top:1px solid var(--line)"><b>${esc(x.method)}</b> ${esc(x.path)} · ${esc(x.status)} · ${esc(x.duration)} мс</div>`).join('')||'История пока пуста';};
        const clearHistory=$('srv-api-history-clear');if(clearHistory)clearHistory.onclick=()=>{requestHistory.length=0;drawApiHistory();};
        root.querySelectorAll('.srv-api-op').forEach(form=>form.addEventListener('submit',async ev=>{
          ev.preventDefault();const idx=Number(form.dataset.apiOp),op=ops[idx];if(!op)return;const result=form.querySelector('.srv-api-result'),btn=form.querySelector('button[type="submit"]');const method=String(op.method||'GET').toUpperCase();if(['POST','PUT','PATCH','DELETE'].includes(method)&&!confirm(spaLocaleText('Этот запрос может изменить или удалить данные во внешнем сервисе. Продолжить?')))return;btn.disabled=true;result.textContent=spaLocaleText('Выполняю запрос…');const startedAt=Date.now();
          try{
            let path=String(op.path||'/');const query=new URLSearchParams(),body={};
            form.querySelectorAll('[data-api-field]').forEach(inp=>{const nm=inp.dataset.apiField,val=inp.value;if(inp.dataset.apiIn==='path')path=path.replace('{'+nm+'}',encodeURIComponent(val));else if(val!=='')query.set(nm,val);});
            form.querySelectorAll('[data-api-body]').forEach(inp=>{if(inp.type==='checkbox')body[inp.dataset.apiBody]=inp.checked;else if(inp.value!=='')body[inp.dataset.apiBody]=inp.type==='number'?Number(inp.value):inp.value;});
            if(/\{[^}]+\}/.test(path))throw new Error('Заполни все параметры пути');
            const base=String(a.base_url||'').replace(/\/+$/,'');let target=base+(path.startsWith('/')?path:'/'+path);if(query.toString())target+=(target.includes('?')?'&':'?')+query.toString();
            const headers={Accept:'application/json'};const token=$('srv-api-token')?.value||'';if(token){if(authCfg.type==='header')headers[authCfg.name||'X-API-Key']=token;else if(authCfg.type==='query')query.set(authCfg.name||'api_key',token);else headers.Authorization='Bearer '+token;}if(authCfg.type==='query'&&token){target=base+(path.startsWith('/')?path:'/'+path)+'?'+query.toString();}
            const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),20000);const init={method,headers,mode:'cors',credentials:'omit',redirect:'error',signal:controller.signal};if(['POST','PUT','PATCH','DELETE'].includes(method)&&op.body_fields&&op.body_fields.length){headers['Content-Type']='application/json';init.body=JSON.stringify(body);}
            let resp;try{resp=await fetch(target,init);}finally{clearTimeout(timeout);}let txt=await resp.text();const tooLarge=txt.length>65536;if(tooLarge)txt=txt.slice(0,65536)+'\n… Ответ обрезан после 64 КБ.';let parsed;try{parsed=JSON.parse(txt);}catch(_){parsed=txt;}result.textContent='HTTP '+resp.status+' '+resp.statusText+'\n'+(typeof parsed==='string'?parsed:JSON.stringify(parsed,null,2));requestHistory.unshift({method,path:op.path||'/',status:'HTTP '+resp.status,duration:Date.now()-startedAt});requestHistory.splice(8);drawApiHistory();
          }catch(err){const msg=err&&err.name==='AbortError'?spaLocaleText('Превышено время ожидания (20 секунд)'):(err&&err.message||String(err));result.textContent='Ошибка: '+msg+'\nПроверь HTTPS, CORS, адрес API и авторизацию.';requestHistory.unshift({method,path:op.path||'/',status:'Ошибка',duration:Date.now()-startedAt});requestHistory.splice(8);drawApiHistory();}finally{btn.disabled=false;}
        }));
        return;
      }
      if(a&&a.manifest&&a.manifest.sections){const m=a.manifest;const body=(m.sections||[]).map(sec=>`<div class="card"><b>${esc(sec.title)}</b>${(sec.fields||[]).map(f=>{if(f.type==='select')return `<label class="sub">${esc(f.label)}<select class="field"><option>${(f.options||[]).map(esc).join('</option><option>')}</option></select></label>`;if(f.type==='textarea')return `<label class="sub">${esc(f.label)}<textarea class="field" rows="3" placeholder="${esc(f.label)}"></textarea></label>`;return `<label class="sub">${esc(f.label)}<input class="field" type="${f.type==='number'?'number':f.type==='url'?'url':'text'}" placeholder="${esc(f.label)}"></label>`;}).join('')}</div>`).join('');showInPane(`<div class="prof-head"><div style="font-size:32px">${esc(a.icon||'🔌')}</div><h2>${esc(m.title||a.name)}</h2><div class="sub">${esc(m.subtitle||a.description||'')}</div></div>${body}<div class="card"><button class="btn-primary" id="srv-generated-open">Перейти к подключённому сервису</button></div>`);const go=$('srv-generated-open');if(go)go.onclick=()=>window.open(a.base_url,'_blank','noopener');return;}
      if(a.display_mode==='embed'){showInPane(`<div class="card"><b>Внешний сервис</b><iframe src="${esc(a.base_url)}" style="width:100%;height:65vh;border:0;border-radius:14px" sandbox="allow-scripts allow-forms allow-same-origin allow-popups"></iframe></div>`);}else window.open(a.base_url,'_blank','noopener');
    }
    window.__openStreamLiveService = openServerApp;
    async function refreshApps(){const d=await api('server_apps_list');const el=$('srv-app-list');if(!el)return;const q=d.creation_quota||{};const qel=$('srv-app-quota');if(qel)qel.textContent=q.active?`Подписка активна · создано ${q.used||0}/10 за 30 дней · осталось ${q.remaining||0}. Удаление не возвращает попытку.`:'Создание сервисов доступно только с активной подпиской SPA. Лимит подписки: 10 сервисов за 30 дней.';el.innerHTML=(d.apps||[]).map(a=>`<div class="row" style="gap:8px"><div style="font-size:24px">${esc(a.icon||'🔌')}</div><div class="body"><div class="title">${esc(a.name)} ${a.service_type==='api'?'<span class="badge">API-приложение</span>':(a.service_type==='manifest'?'<span class="badge">Интерфейс сервиса</span>':'')}</div><div class="sub">${esc(a.description||a.base_url)} · ${esc(platformLabel(a.platform))} · ${a.is_published?'Опубликовано':'Черновик'}</div></div><button class="btn-primary srv-app-open" data-id="${a.id}" style="width:auto">Открыть</button>${canManage?`<button class="icon-btn srv-app-publish" data-id="${a.id}" data-published="${a.is_published?1:0}" title="Публикация">${a.is_published?'Снять':'Опубликовать'}</button><button class="icon-btn srv-app-del" data-id="${a.id}">✕</button>`:''}</div>`).join('')||'<div class="empty">Сервисы пока не добавлены</div>';spaTranslateDom(el);el.querySelectorAll('.srv-app-open').forEach(b=>b.onclick=()=>{const a=(d.apps||[]).find(x=>+x.id===+b.dataset.id);if(a)openServerApp(a);});el.querySelectorAll('.srv-app-del').forEach(b=>b.onclick=async()=>{if(!confirm('Удалить сервис? Попытка создания не восстановится.'))return;const r=await api('server_app_delete',{method:'POST',body:{id:b.dataset.id}});if(!r.ok)alert(r.error||'Не удалось удалить');refreshApps();});el.querySelectorAll('.srv-app-publish').forEach(b=>b.onclick=async()=>{const r=await api('server_app_publish',{method:'POST',body:{id:b.dataset.id,published:b.dataset.published==='1'?0:1}});if(!r.ok)alert(r.error||'Не удалось изменить публикацию');refreshApps();});}
    async function refreshAppCatalog(){const d=await api('server_apps_catalog');const el=$('srv-app-catalog');if(!el)return;const search=($('srv-app-catalog-search')?.value||'').trim().toLowerCase();const items=(d.apps||[]).filter(a=>!search||(a.name+' '+a.description).toLowerCase().includes(search));el.innerHTML=items.map(a=>`<div class="card" style="margin:8px 0"><div class="row" style="gap:8px"><div style="font-size:26px">${esc(a.icon||'🔌')}</div><div class="body"><b>${esc(a.name)}</b><div class="sub">${esc(a.description||'')} · ${esc(platformLabel(a.platform))} · Установок: ${a.install_count||0}</div><div class="sub">⭐ ${Number(a.avg_rating||0).toFixed(1)} / 5 · ${a.review_count||0} ${slT('оценок','ratings','оцінок')}</div></div><button class="btn-primary srv-catalog-open" data-id="${a.id}" style="width:auto">Открыть</button></div><button class="btn-primary srv-catalog-install" data-id="${a.id}" data-installed="${a.installed?1:0}" style="margin-top:8px;background:rgba(255,255,255,.08)">${a.installed?'Убрать из моих приложений':'Добавить в мои приложения'}</button><div class="row" style="gap:8px;margin-top:10px"><select class="field srv-app-rating" data-id="${a.id}" style="max-width:115px"><option value="5" ${+a.my_rating===5?'selected':''}>5 ★</option><option value="4" ${+a.my_rating===4?'selected':''}>4 ★</option><option value="3" ${+a.my_rating===3?'selected':''}>3 ★</option><option value="2" ${+a.my_rating===2?'selected':''}>2 ★</option><option value="1" ${+a.my_rating===1?'selected':''}>1 ★</option></select><input class="field srv-app-review" data-id="${a.id}" maxlength="500" placeholder="Отзыв (необязательно)" value="" style="flex:1;min-width:0"><button class="btn-primary srv-app-review-save" data-id="${a.id}" style="width:auto;margin:0">Оценить</button></div>${(a.reviews||[]).map(r=>`<div class="sub" style="margin-top:7px;padding-top:6px;border-top:1px solid var(--line)">⭐ ${r.rating}/5 · ${esc(r.username)}: ${esc(r.review)}</div>`).join('')}</div>`).join('')||'<div class="empty">В каталоге пока нет опубликованных приложений</div>';spaTranslateDom(el);el.querySelectorAll('.srv-catalog-open').forEach(b=>b.onclick=()=>{const a=(d.apps||[]).find(x=>+x.id===+b.dataset.id);if(a)openServerApp(a);});el.querySelectorAll('.srv-catalog-install').forEach(b=>b.onclick=async()=>{const r=await api('server_app_install',{method:'POST',body:{id:b.dataset.id,installed:b.dataset.installed==='1'?0:1}});if(!r.ok)alert(r.error||'Не удалось обновить приложения');refreshAppCatalog();});el.querySelectorAll('.srv-app-review-save').forEach(b=>b.onclick=async()=>{const id=b.dataset.id;const rating=el.querySelector('.srv-app-rating[data-id="'+id+'"]')?.value||5;const review=el.querySelector('.srv-app-review[data-id="'+id+'"]')?.value||'';b.disabled=true;const r=await api('server_app_review_save',{method:'POST',body:{id,rating,review}});b.disabled=false;if(!r.ok){alert(r.error||'Не удалось сохранить оценку');return;}refreshAppCatalog();});}

    if(add)add.onclick=async()=>{const r=await api('server_app_save',{method:'POST',body:{name:$('srv-app-name').value,url:$('srv-app-url').value,icon:$('srv-app-icon').value,description:$('srv-app-desc').value,manifest:$('srv-app-manifest').value,api_spec:$('srv-app-api-spec').value,display_mode:$('srv-app-mode').value,platform:$('srv-app-platform').value}});if(!r.ok){alert(r.error||'Не удалось добавить сервис');return;}spaAchUnlock('server_service','Создатель сервисов');['srv-app-name','srv-app-url','srv-app-desc','srv-app-manifest','srv-app-api-spec'].forEach(id=>{if($(id))$(id).value='';});refreshApps();};
    const catSearch=$('srv-app-catalog-search');if(catSearch)catSearch.oninput=()=>refreshAppCatalog();
    refreshApps();refreshAppCatalog();
    const hookAdd=$('srv-hook-add');if(hookAdd&&!canManage)hookAdd.style.display='none';
    async function refreshHooks(){const d=await api('server_webhooks_list');const el=$('srv-hook-list');if(!el)return;el.innerHTML=(d.webhooks||[]).map(h=>`<div class="card" style="margin:6px 0"><b>${esc(h.name)}</b><div class="sub">${esc(h.endpoint_url)}</div><small>${(h.events||[]).map(esc).join(' · ')} · ${h.active?'активен':'черновик — доставка выключена'}</small></div>`).join('')||'<div class="empty">Нет подключений</div>';}
    if(hookAdd)hookAdd.onclick=async()=>{const events=[...document.querySelectorAll('.srv-hook-event:checked')].map(x=>x.value);const r=await api('server_webhook_save',{method:'POST',body:{name:$('srv-hook-name').value,url:$('srv-hook-url').value,events:JSON.stringify(events)}});if(!r.ok){alert(r.error||'Ошибка');return;}alert(r.note||'Webhook сохранён');$('srv-hook-name').value='';$('srv-hook-url').value='';refreshHooks();};refreshHooks();
    const roleAdd=$('srv-role-add');if(roleAdd&&!canManage)roleAdd.style.display='none';
    async function refreshRoles(){const d=await api('server_roles_list');const el=$('srv-role-list');if(!el)return;el.innerHTML=(d.roles||[]).map(r=>`<div class="card" style="margin:6px 0"><b>${esc(r.name)}</b><div class="sub">${Object.keys(r.permissions||{}).filter(k=>r.permissions[k]).map(esc).join(' · ')||'Без дополнительных прав'}</div></div>`).join('')||'<div class="empty">Пока нет пользовательских ролей</div>';document.querySelectorAll('.srv-role-assign').forEach(sel=>{const current=(d.assignments||{})[sel.dataset.uid]||0;sel.innerHTML='<option value="0">Участник</option>'+(d.roles||[]).map(r=>`<option value="${r.id}">${esc(r.name)}</option>`).join('');sel.value=String(current);sel.onchange=async()=>{const a=await api('server_role_assign',{method:'POST',body:{user_id:sel.dataset.uid,role_id:sel.value}});if(!a.ok){alert(a.error||'Не удалось назначить роль');return;}const row=sel.closest('.row');const badge=row&&row.querySelector('.srv-custom-role');const line=row&&row.querySelector('.srv-custom-role-line');const role=(d.roles||[]).find(r=>String(r.id)===String(sel.value));const roleName=String(a.role_name||(role&&role.name)||'');if(badge)badge.textContent=roleName;if(line)line.style.display=roleName?'':'none';spaPlay('ok');};});}
    if(roleAdd)roleAdd.onclick=async()=>{const permissions={};document.querySelectorAll('#srv-role-perms input').forEach(x=>permissions[x.value]=x.checked);const r=await api('server_role_save',{method:'POST',body:{name:$('srv-role-name').value,permissions:JSON.stringify(permissions)}});if(!r.ok){alert(r.error||'Ошибка');return;}$('srv-role-name').value='';document.querySelectorAll('#srv-role-perms input').forEach(x=>x.checked=false);refreshRoles();};refreshRoles();
    if(!canManageApps){['srv-app-name','srv-app-url','srv-app-icon','srv-app-desc','srv-app-manifest','srv-app-api-spec','srv-app-mode','srv-app-platform'].forEach(id=>{const e=$(id);if(e)e.disabled=true;});}if(!canManageHooks){['srv-hook-name','srv-hook-url'].forEach(id=>{const e=$(id);if(e)e.disabled=true;});document.querySelectorAll('.srv-hook-event').forEach(e=>e.disabled=true);}if(!canManageRoles){const e=$('srv-role-name');if(e)e.disabled=true;document.querySelectorAll('#srv-role-perms input').forEach(e=>e.disabled=true);}
  })();

  // Server Hub advanced modules: all operations are server-side and permission checked.
  (async()=>{
    const can=(key)=>!!(serverPerms[key]||amOwner);
    const setHidden=(id,allowed)=>{const e=$(id);if(e&&!allowed)e.style.display='none';};
    setHidden('srv-welcome-save',can('manage_welcome'));setHidden('srv-badge-create',can('manage_badges'));setHidden('srv-poll-create',can('manage_polls'));setHidden('srv-event-create',can('manage_events'));setHidden('srv-audit-box',can('view_audit'));
    ['srv-poll-question','srv-poll-options'].forEach(id=>setHidden(id,can('manage_polls')));['srv-event-title','srv-event-desc','srv-event-date','srv-event-location'].forEach(id=>setHidden(id,can('manage_events')));
    setHidden('srv-inactivity-box',amOwner);setHidden('srv-kp-box',amOwner);setHidden('srv-platform-hub',can('manage_apps'));setHidden('srv-webhooks-box',can('manage_webhooks'));setHidden('srv-roles-box',can('manage_roles'));setHidden('srv-welcome-box',can('manage_welcome'));setHidden('srv-badges-box',can('manage_badges'));
    const w=await api('server_welcome_get');if(w.ok){$('srv-welcome-enabled').checked=String(w.config.enabled)==='1';$('srv-welcome-message').value=w.config.message||'';$('srv-welcome-rules').value=w.config.rules_url||'';if(!w.can_manage){['srv-welcome-enabled','srv-welcome-message','srv-welcome-rules'].forEach(id=>$(id).disabled=true);}}
    $('srv-welcome-save').onclick=async()=>{const r=await api('server_welcome_save',{method:'POST',body:{enabled:$('srv-welcome-enabled').checked?1:0,message:$('srv-welcome-message').value,rules_url:$('srv-welcome-rules').value}});if(!r.ok)alert(r.error||'Ошибка');else spaPlay('ok');};
    async function badges(){const d=await api('server_badges_list');const el=$('srv-badges-list');if(!d.ok)return;if((d.items||[]).some(x=>+x.user_id===+(state.user&&state.user.id)))spaAchUnlock('server_badge','Коллекционер значков');const unique=[];(d.items||[]).forEach(b=>{if(!unique.some(x=>+x.id===+b.id))unique.push(b);});el.innerHTML=unique.map(b=>`<div class="row" style="gap:8px;padding:7px 0"><span style="font-size:22px">${esc(b.icon)}</span><div class="body"><b>${esc(b.title)}</b><div class="sub">${esc(b.description||'')}</div></div></div>`).join('')||'<div class="empty">Значков пока нет</div>';const def=$('srv-badge-default');if(def){const currentDefault=Number((await api('server_badge_default_get')).badge_id||0);def.innerHTML='<option value="0">Не показывать общий значок</option>'+unique.map(b=>`<option value="${b.id}">${esc(b.icon)} ${esc(b.title)}</option>`).join('');def.value=String(currentDefault);}spaTranslateDom($('detail-body'));document.querySelectorAll('.srv-badge-assign').forEach(sel=>{sel.innerHTML='<option value="">Выдать значок…</option>'+unique.map(b=>`<option value="${b.id}">${esc(b.icon)} ${esc(b.title)}</option>`).join('');sel.onchange=async()=>{if(!sel.value)return;const r=await api('server_badge_award',{method:'POST',body:{user_id:sel.dataset.uid,badge_id:sel.value}});if(!r.ok)alert(r.error||'Не удалось выдать значок');else{sel.value='';spaPlay('ok');await openMyServer();}};});}
    const defaultSave=$('srv-badge-default-save');if(defaultSave)defaultSave.onclick=async()=>{const r=await api('server_badge_default_set',{method:'POST',body:{badge_id:$('srv-badge-default').value}});if(!r.ok)alert(r.error||'Не удалось сохранить общий значок');else{spaPlay('ok');await openMyServer();}};
    $('srv-badge-create').onclick=async()=>{const r=await api('server_badge_create',{method:'POST',body:{title:$('srv-badge-title').value,code:$('srv-badge-code').value,icon:$('srv-badge-icon').value,description:$('srv-badge-desc').value}});if(!r.ok)alert(r.error||'Ошибка');else{['srv-badge-title','srv-badge-code','srv-badge-desc'].forEach(id=>$(id).value='');badges();}};badges();
    async function collections(){const d=await api('server_collections_list');const el=$('srv-collections-list');if(!d.ok)return;el.innerHTML=(d.items||[]).map(c=>`<div class="card" style="margin-top:8px"><b>${esc(c.title)}</b><div class="sub">${esc(c.description||'')} · ${esc(c.username)} · ${(c.items||[]).length} элементов</div>${(c.items||[]).map(i=>`<div><a href="${esc(i.url)}" target="_blank" rel="noopener">${esc(i.title)}</a></div>`).join('')}</div>`).join('')||'<div class="empty">Подборок пока нет</div>';spaTranslateDom($('detail-body'));}
    $('srv-collection-save').onclick=async()=>{const r=await api('server_collection_save',{method:'POST',body:{title:$('srv-collection-title').value,description:$('srv-collection-desc').value,items:$('srv-collection-items').value,is_shared:$('srv-collection-shared').checked?1:0}});if(!r.ok)alert(r.error||'Проверь JSON подборки');else{if($('srv-collection-shared').checked)spaAchUnlock('collection_shared','Куратор коллекций');['srv-collection-title','srv-collection-desc','srv-collection-items'].forEach(id=>$(id).value='');collections();}};collections();
    const st=await api('server_stats');if(st.ok){const x=st.stats;$('srv-stats-content').innerHTML=`<div class="grid" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px"><div class="card"><b>${x.members}</b><div>Участников</div></div><div class="card"><b>+${x.joined_7d}</b><div>Новых за 7 дней</div></div><div class="card"><b>${x.messages_7d}</b><div>Сообщений за 7 дней</div></div><div class="card"><b>${x.xp}</b><div>XP сервера</div></div><div class="card"><b>${x.achievements}</b><div>Достижений</div></div><div class="card"><b>${x.upcoming_events}</b><div>Предстоящих событий</div></div></div>`;}
    async function tickets(){const d=await api('server_tickets_list');const el=$('srv-tickets-list');if(!d.ok)return;el.innerHTML=(d.items||[]).map(t=>`<div class="card" style="margin-top:8px"><b>#${t.id} · ${esc(t.subject)}</b><div class="sub">${esc(t.username)} · ${esc(t.status)} · ${esc(t.created_at)}</div><p>${esc(t.body)}</p>${t.staff_reply?`<div class="sub">Ответ администрации: ${esc(t.staff_reply)}</div>`:''}${d.can_manage?`<textarea class="field" id="ticket-reply-${t.id}" placeholder="Ответ администрации">${esc(t.staff_reply||'')}</textarea><select class="field" id="ticket-status-${t.id}"><option value="open" ${t.status==='open'?'selected':''}>Открыта</option><option value="in_progress" ${t.status==='in_progress'?'selected':''}>В работе</option><option value="closed" ${t.status==='closed'?'selected':''}>Закрыта</option></select><button class="btn-primary ticket-update" data-id="${t.id}">Обновить заявку</button>`:''}</div>`).join('')||'<div class="empty">Обращений пока нет</div>';spaTranslateDom($('detail-body'));el.querySelectorAll('.ticket-update').forEach(b=>b.onclick=async()=>{const id=b.dataset.id;const r=await api('server_ticket_update',{method:'POST',body:{id,status:$('ticket-status-'+id).value,reply:$('ticket-reply-'+id).value}});if(!r.ok)alert(r.error||'Ошибка');else tickets();});}
    $('srv-ticket-create').onclick=async()=>{const r=await api('server_ticket_create',{method:'POST',body:{subject:$('srv-ticket-subject').value,body:$('srv-ticket-body').value}});if(!r.ok)alert(r.error||'Ошибка');else{$('srv-ticket-subject').value='';$('srv-ticket-body').value='';tickets();}};tickets();
    async function polls(){const d=await api('server_polls_list');const el=$('srv-polls-list');if(!d.ok)return;el.innerHTML=(d.items||[]).map(p=>`<div class="card" style="margin-top:8px"><b>${esc(p.question)}</b><div class="sub">${esc(p.username)} · ${p.ends_at?'до '+esc(p.ends_at):'без срока'}</div>${(p.options||[]).map((o,i)=>`<label style="display:block;padding:5px"><input type="radio" name="poll-${p.id}" value="${i}" ${String(p.my_vote)===String(i)?'checked':''}> ${esc(o)} <span class="sub">(${p.counts[i]||0})</span></label>`).join('')}<button class="btn-primary poll-vote" data-id="${p.id}">Проголосовать</button></div>`).join('')||'<div class="empty">Голосований пока нет</div>';spaTranslateDom($('detail-body'));el.querySelectorAll('.poll-vote').forEach(b=>b.onclick=async()=>{const v=el.querySelector(`input[name="poll-${b.dataset.id}"]:checked`);if(!v){alert('Выбери вариант');return;}const r=await api('server_poll_vote',{method:'POST',body:{id:b.dataset.id,option_index:v.value}});if(!r.ok)alert(r.error||'Ошибка');else{spaAchUnlock('server_poll','Голос сообщества');polls();}});}
    if($('srv-poll-create'))$('srv-poll-create').onclick=async()=>{const opts=$('srv-poll-options').value.split(/\r?\n/).map(x=>x.trim()).filter(Boolean);const r=await api('server_poll_create',{method:'POST',body:{question:$('srv-poll-question').value,options:JSON.stringify(opts)}});if(!r.ok)alert(r.error||'Ошибка');else{$('srv-poll-question').value='';$('srv-poll-options').value='';polls();}};polls();
    async function events(){const d=await api('server_events_list');const el=$('srv-events-list');if(!d.ok)return;el.innerHTML=(d.items||[]).map(e=>`<div class="card" style="margin-top:8px"><b>${esc(e.title)}</b><div class="sub">${esc(e.starts_at)} · ${esc(e.location||'Без места')} · ${e.attendees} идут</div><p>${esc(e.description||'')}</p><button class="btn-primary event-rsvp" data-id="${e.id}" data-status="going">Пойду</button><button class="btn-primary event-rsvp" data-id="${e.id}" data-status="maybe">Возможно</button></div>`).join('')||'<div class="empty">Событий пока нет</div>';spaTranslateDom($('detail-body'));el.querySelectorAll('.event-rsvp').forEach(b=>b.onclick=async()=>{const r=await api('server_event_rsvp',{method:'POST',body:{id:b.dataset.id,status:b.dataset.status}});if(!r.ok)alert(r.error||'Ошибка');else events();});}
    if($('srv-event-create'))$('srv-event-create').onclick=async()=>{const r=await api('server_event_create',{method:'POST',body:{title:$('srv-event-title').value,description:$('srv-event-desc').value,starts_at:$('srv-event-date').value,location:$('srv-event-location').value}});if(!r.ok)alert(r.error||'Ошибка');else{spaAchUnlock('server_event','Организатор');['srv-event-title','srv-event-desc','srv-event-date','srv-event-location'].forEach(id=>$(id).value='');events();}};events();
    const au=await api('server_audit_list');if(au.ok)$('srv-audit-list').innerHTML=(au.items||[]).map(a=>`<div style="padding:6px 0;border-bottom:1px solid var(--line)"><b>${esc(a.action)}</b> · ${esc(a.username||'Система')}<div class="sub">${esc(a.details)} · ${esc(a.created_at)}</div></div>`).join('')||'Записей пока нет';spaTranslateDom($('detail-body'));
  })();

  async function loadChat() {
    await loadEngineStickers();
    const ch = await api('server_chat');
    const el = $('srv-chat');
    if (!el || !ch.ok) return;
    el.innerHTML = (ch.messages||[]).map(m => {
      if (m.is_system) {
        return `<div style="padding:8px 0;text-align:center;color:var(--muted);font-size:12px;border-bottom:1px solid var(--line)">${renderStickerText(m.body)}</div>`;
      }
      const badges=(m.badges||[]).map(b=>`<span class="srv-badge-chip" title="${esc(b.title)}">${esc(b.icon)} ${esc(b.title)}</span>`).join(' ');
      const safeBody=renderStickerText(m.body).replace(/(^|\s)@([\p{L}\p{N}_-]{2,32})/gu,'$1<span class="srv-chat-mention">@$2</span>');
      return `<div style="padding:6px 0;border-bottom:1px solid var(--line)" data-mid="${m.id||0}"><b>@${esc(m.username||'')}</b> ${badges} <span style="color:var(--muted);font-size:11px">${esc(m.created_at||'')}</span><div>${safeBody}</div>
        <div class="msg-reacts">${['👍','❤️','🔥','😂'].map(e=>`<span class="msg-react" data-mid="${m.id||0}" data-e="${e}">${e}</span>`).join('')}<span class="react-sum" data-mid="${m.id||0}" style="font-size:11px;color:var(--muted)"></span></div></div>`;
    }).join('') || '<div class="empty" style="padding:8px">Пока тихо</div>';
    el.scrollTop = el.scrollHeight;
  }
  async function loadReacts() {
    const ids = [...document.querySelectorAll('[data-mid]')].map(el => +el.dataset.mid).filter(Boolean);
    if (!ids.length) return;
    const r = await api('react_list', { params:{ scope:'server', ids: ids.join(',') } });
    if (!r || !r.ok || !r.map) return;
    Object.keys(r.map).forEach(mid => {
      const sum = document.querySelector('.react-sum[data-mid="'+mid+'"]');
      if (!sum) return;
      sum.textContent = (r.map[mid]||[]).map(x => x.emoji + (x.count>1?x.count:'')).join(' ');
    });
  }
  async function bindChatReactions(){
    document.querySelectorAll('#srv-chat .msg-react').forEach(el => {
      el.onclick = async () => {
        const r=await api('react_toggle', { method:'POST', body:{ scope:'server', msg_id: el.dataset.mid, emoji: el.dataset.e } });
        if(r&&r.ok){try { spaAchUnlock('react_first', 'Эмоциональный'); } catch(e) {} await loadChat(); await loadReacts();}
      };
    });
  }
  loadChat().then(async()=>{await bindChatReactions();await loadReacts();});
  try{if(window.__slServerChatTimer)clearInterval(window.__slServerChatTimer);}catch(e){}
  window.__slServerChatTimer=setInterval(async()=>{
    const chat=document.getElementById('srv-chat');
    if(!chat||!document.body.contains(chat)||document.hidden)return;
    const active=document.activeElement;
    if(active&&chat.contains(active)&&/INPUT|TEXTAREA/.test(active.tagName))return;
    const nearBottom=(chat.scrollHeight-chat.scrollTop-chat.clientHeight)<90;
    const before=chat.scrollTop;
    await loadChat();
    if(!nearBottom)chat.scrollTop=before;
    await bindChatReactions();
    await loadReacts();
  },20000);
  // playlists block
  (async () => {
    const host = document.createElement('div');
    host.className = 'card';
    host.innerHTML = `<div class="label">Общие плейлисты сервера</div>
      <input class="field" id="srv-pl-title" placeholder="Название плейлиста">
      <input class="field" id="srv-pl-urls" placeholder="URL треков через запятую">
      <button type="button" class="btn-primary" id="srv-pl-add" style="margin:0">Добавить плейлист</button>
      <div id="srv-pl-list" style="margin-top:10px"></div>`;
    const leaveCard = $('srv-leave') && $('srv-leave').closest('.card');
    if (leaveCard && leaveCard.parentNode) leaveCard.parentNode.insertBefore(host, leaveCard);
    async function refreshPl() {
      const d = await api('server_playlist_list');
      const el = $('srv-pl-list');
      if (!el) return;
      if (!d || !d.ok) { el.innerHTML = '<div class="empty">'+(d&&d.error||'—')+'</div>'; return; }
      el.innerHTML = (d.playlists||[]).map(p => `<div class="card" style="margin:0 0 8px"><div class="val">${esc(p.title)}</div>
        <div style="font-size:12px;color:var(--muted)">@${esc(p.username)} · ${(p.tracks||[]).length} треков</div>
        <button type="button" class="btn-primary srv-pl-open" data-tracks='${esc(JSON.stringify(p.tracks||[]))}' style="margin-top:8px;background:rgba(255,255,255,.08)">Открыть в музыке</button></div>`).join('') || '<div class="empty">Пока нет</div>';
      el.querySelectorAll('.srv-pl-open').forEach(b => {
        b.onclick = () => {
          try {
            const tracks = JSON.parse(b.dataset.tracks||'[]');
            let L = musicLibGet();
            tracks.forEach((u,i) => {
              if (!u) return;
              const url = typeof u === 'string' ? u : (u.url||'');
              if (!url) return;
              L.unshift({ id: 'srvpl-'+Date.now()+'-'+i, url, title: (u.title||musicCleanTitle(url)), artist: 'Сервер', cover:'', added: Date.now() });
            });
            musicLibSet(L);
            try { spaAchUnlock('playlist_share', 'Куратор'); } catch(e) {}
            openMusicHub();
          } catch(e) { alert('Ошибка'); }
        };
      });
    }
    if ($('srv-pl-add')) $('srv-pl-add').onclick = async () => {
      const title = ($('srv-pl-title').value||'').trim();
      const tracks = ($('srv-pl-urls').value||'').split(',').map(s => s.trim()).filter(Boolean).map(url => ({ url }));
      const r = await api('server_playlist_add', { method:'POST', body:{ title, tracks: JSON.stringify(tracks) } });
      if (r && r.ok) { spaPlay('ok'); spaAchUnlock('playlist_share','Куратор'); refreshPl(); }
      else alert((r&&r.error)||'Ошибка');
    };
    refreshPl();
  })();
  fillEngineStickerBar('srv-engine-st', async (code) => {
    const r = await api('server_chat_send', { method:'POST', body:{ body: code } });
    if (r && r.ok) loadChat();
  });
  bindServerStickers('srv-server-st', async (emoji) => {
    const r = await api('server_chat_send', { method:'POST', body:{ body: emoji } });
    if (r && r.ok) loadChat();
  });
  if ($('srv-qr-copy')) $('srv-qr-copy').onclick = () => {
    const code = s.invite_code || '';
    navigator.clipboard.writeText(code).then(()=>alert('Скопировано: '+code)).catch(()=>prompt('Код', code));
  };
  const send = $('srv-send');
  if (send) send.onclick = async () => {
    const inp = $('srv-msg');
    const body = (inp&&inp.value||'').trim();
    if (!body) return;
    await api('server_chat_send', { method:'POST', body:{ body } }); try { spaQuestDone('server_chat'); } catch(e) {}
    if (inp) inp.value = '';
    loadChat();
  };
  document.querySelectorAll('.srv-st').forEach(b => {
    b.onclick = async () => {
      await api('server_chat_send', { method:'POST', body:{ body: b.dataset.s } });
      try { spaQuestDone('server_chat'); } catch(e) {}
      spaPlay('click');
      loadChat();
    };
  });
  const leave = $('srv-leave');
  if (leave) leave.onclick = async () => {
    if (!confirm('Выйти из сервера?')) return;
    const r = await api('server_leave', { method:'POST', body:{} });
    if (!r.ok) { alert(r.error||'Ошибка'); return; }
    state.myServer = null;
    await refreshMyServer();
    openServerHub();
  };
  presence('server', 'Сервер: '+s.name);
  api('server_rank').then(d => {
    const el = $('srv-my-rank');
    if (!el || !d || !d.ok) return;
    const ranks = d.rank || d.servers || [];
    let place = 0;
    ranks.forEach((r, i) => { if (r.id == s.id || r.name === s.name) place = i+1; });
    el.textContent = place ? ('🏆 Место в рейтинге: #' + place + ' из ' + ranks.length) : ('Рейтинг: ' + ranks.length + ' серверов');
  }).catch(()=>{});
}

$('rail-add').onclick = () => {
  if (state.myServer) openMyServer();
  else openServerHub();
};
if ($('rail-server')) $('rail-server').onclick = () => openMyServer();
if ($('btn-clan-mobile')) $('btn-clan-mobile').onclick = () => {
  if (state.myServer) openMyServer(); else openServerHub();
};



$('update-reload').onclick = acceptUpdate;
$('update-later').onclick = deferUpdate;
applyBuild(BOOT.build);
checkBuild();

(function spaChromeScroll(){
  var lastY=0, ticking=false;
  function setVvh(){try{var h=(window.visualViewport&&window.visualViewport.height)||window.innerHeight;document.documentElement.style.setProperty('--spa-vvh',h+'px');}catch(e){}}
  function onScroll(){if(ticking)return;ticking=true;requestAnimationFrame(function(){ticking=false;var y=window.scrollY||document.documentElement.scrollTop||0;var dy=y-lastY;if(y<24){document.body.classList.remove('spa-scroll-down');document.body.classList.add('spa-scroll-up');}else if(dy>8){document.body.classList.add('spa-scroll-down');document.body.classList.remove('spa-scroll-up');}else if(dy<-8){document.body.classList.remove('spa-scroll-down');document.body.classList.add('spa-scroll-up');}lastY=y;});}
  setVvh();window.addEventListener('scroll',onScroll,{passive:true});window.addEventListener('resize',setVvh,{passive:true});
  if(window.visualViewport){window.visualViewport.addEventListener('resize',setVvh,{passive:true});window.visualViewport.addEventListener('scroll',setVvh,{passive:true});}
})();


/* ===== StreamLive Feature Pack: continue / queue / notify / stats ===== */
function spaFeatStore(key, val){ try{ localStorage.setItem(key, JSON.stringify(val)); }catch(e){} }
function spaFeatLoad(key, fallback){ try{ var a=JSON.parse(localStorage.getItem(key)||'null'); return a==null?fallback:a; }catch(e){ return fallback; } }

/* Smart resume: position + episode + player */
function spaSaveResume(item){
  if(!item||!item.id) return;
  var all=spaFeatLoad('sl_smart_resume_v1',{});
  all[String(item.kind||'video')+':'+String(item.id)]={
    kind:item.kind||'video', id:String(item.id), title:item.title||'',
    position:Number(item.position)||0, duration:Number(item.duration)||0,
    episode:item.episode||null, season:item.season||null,
    player:item.player||'', src:item.src||'', poster:item.poster||'',
    updatedAt:Date.now()
  };
  spaFeatStore('sl_smart_resume_v1', all);
}
function spaGetResume(kind,id){
  var all=spaFeatLoad('sl_smart_resume_v1',{});
  return all[String(kind)+':'+String(id)]||null;
}
function spaListResume(){
  var all=spaFeatLoad('sl_smart_resume_v1',{});
  return Object.keys(all).map(function(k){return all[k];}).sort(function(a,b){return (b.updatedAt||0)-(a.updatedAt||0);}).slice(0,40);
}

/* Watch queue */
function spaQueueList(){ return spaFeatLoad('sl_watch_queue_v1',[]); }
function spaQueueSave(arr){ spaFeatStore('sl_watch_queue_v1', Array.isArray(arr)?arr:[]); }
function spaQueueAdd(item){
  var q=spaQueueList().filter(function(x){ return !(x.kind===item.kind && String(x.id)===String(item.id)); });
  q.unshift({kind:item.kind||'video', id:String(item.id), title:item.title||'Без названия', poster:item.poster||'', addedAt:Date.now(), done:false});
  spaQueueSave(q.slice(0,100));
  spaNotifyPush({type:'queue', title:'В очереди', body:item.title||'Контент добавлен в очередь'});
  return q;
}
function spaQueueRemove(kind,id){
  spaQueueSave(spaQueueList().filter(function(x){ return !(x.kind===kind && String(x.id)===String(id)); }));
}
function spaQueueToggleDone(kind,id){
  var q=spaQueueList(); q.forEach(function(x){ if(x.kind===kind&&String(x.id)===String(id)) x.done=!x.done; }); spaQueueSave(q);
}

/* Notifications center (local, on-site) */
function spaNotifyList(){ return spaFeatLoad('sl_notify_center_v1',[]); }
function spaNotifyPush(n){
  var list=spaNotifyList();
  list.unshift({id:'n'+Date.now()+Math.random().toString(36).slice(2,6), type:n.type||'info', title:n.title||'Уведомление', body:n.body||'', at:Date.now(), read:false});
  spaFeatStore('sl_notify_center_v1', list.slice(0,100));
  try{ var b=document.getElementById('sl-notify-badge'); if(b){ var c=list.filter(function(x){return !x.read;}).length; b.textContent=c>0?String(c):''; b.style.display=c>0?'':'none'; } }catch(e){}
}
function spaNotifyMarkAll(){
  var list=spaNotifyList(); list.forEach(function(x){x.read=true;}); spaFeatStore('sl_notify_center_v1', list);
  try{ var b=document.getElementById('sl-notify-badge'); if(b){ b.textContent=''; b.style.display='none'; } }catch(e){}
}

/* Profile watch stats */
function spaStatsBump(minutes, genre){
  var day=(new Date()).toISOString().slice(0,10);
  var st=spaFeatLoad('sl_watch_stats_v1',{days:{}, genres:{}, totalMin:0, titles:0});
  st.days[day]=(st.days[day]||0)+Math.max(0,Number(minutes)||0);
  st.totalMin=(st.totalMin||0)+Math.max(0,Number(minutes)||0);
  if(genre){ st.genres[genre]=(st.genres[genre]||0)+1; }
  st.titles=(st.titles||0)+1;
  spaFeatStore('sl_watch_stats_v1', st);
}
function spaStatsGet(){ return spaFeatLoad('sl_watch_stats_v1',{days:{}, genres:{}, totalMin:0, titles:0}); }

function spaOpenNotifyCenter(){
  destroySpaPlayer(); setDetailTitle('Уведомления');
  var list=spaNotifyList();
  var html='<div class="card" style="display:flex;justify-content:space-between;align-items:center"><div class="val">🔔 Центр уведомлений</div><button type="button" class="btn-primary" id="sl-notify-readall" style="width:auto;margin:0;padding:7px 10px">Прочитать все</button></div>';
  if(!list.length) html+='<div class="empty">Пока нет уведомлений</div>';
  else html+=list.map(function(n){
    return '<div class="card" style="opacity:'+(n.read?'.65':'1')+'"><div class="val" style="font-size:14px">'+esc(n.title)+'</div><div class="sub">'+esc(n.body)+'</div><div class="sub" style="margin-top:4px">'+new Date(n.at).toLocaleString('ru-RU')+'</div></div>';
  }).join('');
  var box=showInPane(html);
  var btn=box.querySelector('#sl-notify-readall'); if(btn) btn.onclick=function(){ spaNotifyMarkAll(); spaOpenNotifyCenter(); };
}
function spaOpenWatchQueue(){
  destroySpaPlayer(); setDetailTitle('Очередь просмотра');
  var q=spaQueueList();
  var html='<div class="card"><div class="val">🎞 Очередь просмотра</div><div class="sub">Собери список и смотри по порядку</div></div>';
  if(!q.length) html+='<div class="empty">Очередь пуста. Добавляй контент кнопкой «В очередь»</div>';
  else html+=q.map(function(x,i){
    return '<div class="card" style="display:flex;gap:10px;align-items:center">'
      +'<div style="min-width:0;flex:1"><div class="val" style="font-size:14px">'+(x.done?'✅ ':'')+esc(x.title)+'</div><div class="sub">'+esc(x.kind)+' · '+new Date(x.addedAt||Date.now()).toLocaleDateString('ru-RU')+'</div></div>'
      +'<button type="button" class="icon-btn" data-q-done="'+i+'" title="Готово">✓</button>'
      +'<button type="button" class="icon-btn" data-q-del="'+i+'" title="Убрать">✕</button></div>';
  }).join('');
  var box=showInPane(html);
  box.querySelectorAll('[data-q-done]').forEach(function(b){ b.onclick=function(){ var x=q[+b.dataset.qDone]; if(x){ spaQueueToggleDone(x.kind,x.id); spaOpenWatchQueue(); } }; });
  box.querySelectorAll('[data-q-del]').forEach(function(b){ b.onclick=function(){ var x=q[+b.dataset.qDel]; if(x){ spaQueueRemove(x.kind,x.id); spaOpenWatchQueue(); } }; });
}
function spaOpenMyStats(){
  destroySpaPlayer(); setDetailTitle('Моя статистика');
  var st=spaStatsGet();
  var days=Object.keys(st.days||{}).sort().slice(-14);
  var weekMin=days.slice(-7).reduce(function(n,d){return n+(st.days[d]||0);},0);
  var genres=Object.keys(st.genres||{}).sort(function(a,b){return (st.genres[b]||0)-(st.genres[a]||0);}).slice(0,8);
  var html='<div class="card" style="background:linear-gradient(135deg,rgba(109,94,252,.24),rgba(30,190,170,.12))"><div class="val">📊 Моя статистика</div><div class="sub">Локально на устройстве · без лишних хитов</div></div>';
  html+='<div class="card"><div class="label">Всего</div><div class="val">'+Math.round((st.totalMin||0)/60)+' ч '+(st.totalMin||0)%60+' мин</div><div class="sub">Записей: '+(st.titles||0)+'</div></div>';
  html+='<div class="card"><div class="label">За 7 дней</div><div class="val">'+Math.round(weekMin/60)+' ч '+weekMin%60+' мин</div></div>';
  if(genres.length) html+='<div class="card"><div class="label">Любимые жанры / метки</div><div class="sub">'+genres.map(function(g){return esc(g)+' · '+(st.genres[g]||0);}).join('<br>')+'</div></div>';
  var resume=spaListResume().slice(0,8);
  if(resume.length){
    html+='<div class="card"><div class="val">▶ Продолжить просмотр</div></div>';
    html+=resume.map(function(r){
      var pct=r.duration?Math.min(100,Math.round((r.position||0)/(r.duration)*100)):0;
      return '<div class="card"><div class="val" style="font-size:14px">'+esc(r.title||'Без названия')+'</div>'
        +'<div class="sub">'+(r.season?('S'+r.season+'E'+(r.episode||'?')+' · '):'')+Math.floor((r.position||0)/60)+' мин · плеер: '+esc(r.player||'—')+'</div>'
        +'<div class="progress"><i style="width:'+pct+'%"></i></div></div>';
    }).join('');
  }
  showInPane(html);
}
function spaOpenMyActivity(){
  destroySpaPlayer(); setDetailTitle('Моя активность');
  var resume=spaListResume().slice(0,6);
  var q=spaQueueList().filter(function(x){return !x.done;}).slice(0,6);
  var notes=spaNotifyList().slice(0,6);
  var html='<div class="card"><div class="val">✨ Моя активность</div><div class="sub">Просмотры · очередь · уведомления</div></div>';
  html+='<div class="card" style="display:flex;gap:8px;flex-wrap:wrap">'
    +'<button type="button" class="btn-primary" id="act-resume" style="width:auto;margin:0">Продолжить</button>'
    +'<button type="button" class="btn-primary" id="act-queue" style="width:auto;margin:0">Очередь</button>'
    +'<button type="button" class="btn-primary" id="act-stats" style="width:auto;margin:0">Статистика</button>'
    +'<button type="button" class="btn-primary" id="act-notify" style="width:auto;margin:0">Уведомления</button></div>';
  if(resume.length){ html+='<div class="label" style="padding:8px 6px">Недавно смотрели</div>'; resume.forEach(function(r){ html+='<div class="card"><b>'+esc(r.title||'')+'</b><div class="sub">'+Math.floor((r.position||0)/60)+' мин</div></div>'; }); }
  if(q.length){ html+='<div class="label" style="padding:8px 6px">В очереди</div>'; q.forEach(function(x){ html+='<div class="card"><b>'+esc(x.title)+'</b></div>'; }); }
  if(notes.length){ html+='<div class="label" style="padding:8px 6px">Уведомления</div>'; notes.forEach(function(n){ html+='<div class="card"><b>'+esc(n.title)+'</b><div class="sub">'+esc(n.body)+'</div></div>'; }); }
  var box=showInPane(html);
  var a=box.querySelector('#act-resume'); if(a) a.onclick=function(){ spaOpenMyStats(); };
  var b=box.querySelector('#act-queue'); if(b) b.onclick=function(){ spaOpenWatchQueue(); };
  var c=box.querySelector('#act-stats'); if(c) c.onclick=function(){ spaOpenMyStats(); };
  var d=box.querySelector('#act-notify'); if(d) d.onclick=function(){ spaOpenNotifyCenter(); };
}

/* Hook continuePush if exists — enrich with position */
try{
  if(typeof continuePush==='function' && !window.__slContinuePatched){
    var __cp=continuePush;
    window.continuePush=function(item){
      try{
        if(item){
          spaSaveResume({
            kind:item.kind||item.type||'video', id:item.id||item.slug||item.video_id||item.title,
            title:item.title||item.name||'', position:item.position||item.time||0, duration:item.duration||0,
            episode:item.episode, season:item.season, player:item.player||item.player_id||'', poster:item.poster||item.avatar||''
          });
          if((item.position||0)>30) spaStatsBump(1, item.genre||item.kind||'');
        }
      }catch(e){}
      return __cp.apply(this, arguments);
    };
    window.__slContinuePatched=1;
  }
}catch(e){}

setInterval(checkBuild, 20000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) checkBuild(); });
window.addEventListener('focus', () => checkBuild());
setInterval(() => { if (state.user) {
  if (__slMusicAudio && !__slMusicAudio.paused) { var q = (__slMusicQueue && __slMusicQueue.length) ? __slMusicQueue : musicLibGet(); var tr = q[__slMusicIdx] || {}; presence('music', 'Слушает: ' + (tr.title || 'Музыка')); }
  else presence(state.screen, state._presenceLabel||state.screen);
} }, 15000);

if (state.user) showApp(); else showAuth();
setTimeout(hideSplash, 2000);
</script>
</body>
</html>
