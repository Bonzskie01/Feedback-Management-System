<?php
// includes/functions.php

/**
 * I-sanitize ang string para safe i-output sa HTML
 */
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * I-redirect ang user sa ibang page
 */
function redirect($url) {
    header("Location: $url");
    exit();
}

/**
 * I-check kung naka-login ang admin
 */
function is_admin_logged_in() {
    return isset($_SESSION['admin_id']);
}

/**
 * I-check kung naka-login ang voter
 */
function is_voter_logged_in() {
    return isset($_SESSION['voter_id']);
}

/**
 * I-check kung ang voter ay active pa (hindi pa bumoboto)
 */
function is_voter_active($conn, $voter_id) {
    $sql = "SELECT status FROM voters WHERE voter_id = $1";
    $result = pg_query_params($conn, $sql, array($voter_id));
    
    if ($result && pg_num_rows($result) === 1) {
        $voter = pg_fetch_assoc($result);
        return $voter['status'] === 'active';
    }
    return false;
}


function ensure_audit_logs_table($conn) {
    $result = pg_query($conn, "SELECT to_regclass('public.audit_logs')");
    return $result && pg_fetch_result($result, 0, 0) !== null;
}

function audit_log($conn, $action, $target_type = null, $target_id = null, $details = []) {
    if (!$conn || !ensure_audit_logs_table($conn)) return false;
    $admin_id = $_SESSION['admin_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000);
    $details_json = is_array($details) ? json_encode($details, JSON_UNESCAPED_SLASHES) : json_encode(['message' => (string) $details]);
    return (bool) pg_query_params($conn,
        "INSERT INTO audit_logs (admin_id, action, target_type, target_id, ip_address, user_agent, details)
         VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb)",
        [$admin_id, $action, $target_type, $target_id !== null ? (string) $target_id : null, $ip, $agent, $details_json ?: '{}']
    );
}

/**
 * Atomically claim a TOTP counter for an admin.
 * Returns true when claimed, false when already used, and null on storage failure.
 */
function claim_admin_totp_counter($conn, $admin_id, $counter) {
    $admin_id = (int) $admin_id;
    $counter = (int) $counter;
    if (!$conn || $admin_id <= 0 || $counter < 0 || !ensure_audit_logs_table($conn)) {
        return null;
    }

    if (!pg_query($conn, 'BEGIN')) {
        return null;
    }

    try {
        // A transaction-scoped lock prevents two simultaneous requests from claiming one code.
        $lock = pg_query_params($conn,
            'SELECT pg_advisory_xact_lock($1::integer, $2::integer)',
            [1447904083, $admin_id]
        );
        if (!$lock) {
            pg_query($conn, 'ROLLBACK');
            return null;
        }

        $last = pg_query_params($conn,
            "SELECT details->>'counter' AS counter
               FROM audit_logs
              WHERE action = 'admin_totp_counter_used'
                AND target_type = 'admin'
                AND target_id = $1
              ORDER BY id DESC
              LIMIT 1",
            [(string) $admin_id]
        );
        if (!$last) {
            pg_query($conn, 'ROLLBACK');
            return null;
        }

        if (pg_num_rows($last) === 1) {
            $lastCounter = pg_fetch_result($last, 0, 'counter');
            if ($lastCounter !== false && ctype_digit((string) $lastCounter)
                && (int) $lastCounter >= $counter) {
                pg_query($conn, 'ROLLBACK');
                return false;
            }
        }

        $details = json_encode(['counter' => $counter]);
        $insert = pg_query_params($conn,
            "INSERT INTO audit_logs
                (admin_id, action, target_type, target_id, ip_address, user_agent, details)
             VALUES ($1, 'admin_totp_counter_used', 'admin', $2, $3, $4, $5::jsonb)",
            [
                $admin_id,
                (string) $admin_id,
                $_SERVER['REMOTE_ADDR'] ?? '',
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 1000),
                $details
            ]
        );
        if (!$insert || !pg_query($conn, 'COMMIT')) {
            pg_query($conn, 'ROLLBACK');
            return null;
        }
        return true;
    } catch (Throwable $e) {
        pg_query($conn, 'ROLLBACK');
        error_log('Unable to claim TOTP counter: ' . $e->getMessage());
        return null;
    }
}

function app_encryption_key() {
    $encoded = getenv('APP_KEY') ?: '';
    $key = base64_decode($encoded, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('APP_KEY is missing or invalid.');
    }
    return $key;
}

function encrypt_sensitive_value($value) {
    if ($value === null || $value === '') return null;
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt((string)$value, 'aes-256-gcm', app_encryption_key(),
        OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ciphertext === false) throw new RuntimeException('Unable to encrypt security value.');
    return 'enc:v1:' . base64_encode($iv . $tag . $ciphertext);
}

function decrypt_sensitive_value($value) {
    if ($value === null || $value === '') return '';
    if (!str_starts_with((string)$value, 'enc:v1:')) return (string)$value;
    $payload = base64_decode(substr((string)$value, 7), true);
    if ($payload === false || strlen($payload) <= 28) {
        throw new RuntimeException('Encrypted security value is invalid.');
    }
    $iv = substr($payload, 0, 12);
    $tag = substr($payload, 12, 16);
    $ciphertext = substr($payload, 28);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', app_encryption_key(),
        OPENSSL_RAW_DATA, $iv, $tag);
    if ($plaintext === false) throw new RuntimeException('Unable to decrypt security value.');
    return $plaintext;
}
function verify_admin_password($conn, $admin_id, $password) {
    if (!$admin_id || $password === '') return false;
    $result = pg_query_params($conn, "SELECT password_hash FROM admins WHERE id = $1", [$admin_id]);
    return $result && pg_num_rows($result) === 1
        && password_verify($password, pg_fetch_result($result, 0, 'password_hash'));
}

function save_secure_candidate_photo_upload($root_path, $file, $existing_path = '') {
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $existing_path;
    }

    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || empty($file['tmp_name'])
        || !is_uploaded_file($file['tmp_name'])) {
        throw new Exception('Candidate photo upload failed. Please select the image again.');
    }

    $file_size = filesize($file['tmp_name']);
    if ($file_size === false || $file_size < 100 || $file_size > 15 * 1024 * 1024) {
        throw new Exception('Candidate photo must be 15MB or smaller.');
    }

    if (!extension_loaded('gd') || !function_exists('imagecreatefromstring') || !function_exists('finfo_open')) {
        throw new Exception('Secure candidate photo processing is unavailable.');
    }

    $allowed = [
        'image/jpeg' => ['extension' => 'jpg', 'type' => IMAGETYPE_JPEG],
        'image/png' => ['extension' => 'png', 'type' => IMAGETYPE_PNG],
        'image/gif' => ['extension' => 'gif', 'type' => IMAGETYPE_GIF],
        'image/webp' => ['extension' => 'webp', 'type' => IMAGETYPE_WEBP],
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo) finfo_close($finfo);
    $image_type = @exif_imagetype($file['tmp_name']);
    $image_info = @getimagesize($file['tmp_name']);

    if (!isset($allowed[$mime]) || $image_type !== $allowed[$mime]['type'] || !$image_info) {
        throw new Exception('Candidate photo must be a genuine JPG, PNG, GIF, or WEBP image.');
    }

    $width = (int)$image_info[0];
    $height = (int)$image_info[1];
    if ($width < 1 || $height < 1 || $width > 8000 || $height > 8000
        || ($width * $height) > 40000000) {
        throw new Exception('Candidate photo exceeds the safe limit of 8000px per side or 40 megapixels.');
    }

    $bytes = file_get_contents($file['tmp_name']);
    $source = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
    unset($bytes);
    if (!$source || imagesx($source) !== $width || imagesy($source) !== $height) {
        if ($source) imagedestroy($source);
        throw new Exception('Candidate photo could not be decoded safely.');
    }

    $scale = min(1, 2000 / max($width, $height));
    $target_width = max(1, (int)round($width * $scale));
    $target_height = max(1, (int)round($height * $scale));
    $normalized = imagecreatetruecolor($target_width, $target_height);
    if (!$normalized) {
        imagedestroy($source);
        throw new Exception('Candidate photo could not be normalized.');
    }

    if ($mime !== 'image/jpeg') {
        imagealphablending($normalized, false);
        imagesavealpha($normalized, true);
        $transparent = imagecolorallocatealpha($normalized, 0, 0, 0, 127);
        imagefill($normalized, 0, 0, $transparent);
    }

    $resampled = @imagecopyresampled(
        $normalized, $source,
        0, 0, 0, 0,
        $target_width, $target_height,
        $width, $height
    );
    imagedestroy($source);
    if (!$resampled) {
        imagedestroy($normalized);
        throw new Exception('Candidate photo resizing failed.');
    }

    $upload_dir = $root_path . '/assets/photos/';
    if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) {
        imagedestroy($normalized);
        throw new Exception('Candidate photo storage is unavailable.');
    }

    $extension = $allowed[$mime]['extension'];
    $filename = 'candidate_' . bin2hex(random_bytes(12)) . '.' . $extension;
    $target = $upload_dir . $filename;
    $saved = false;
    if ($mime === 'image/jpeg') {
        imageinterlace($normalized, false);
        $saved = @imagejpeg($normalized, $target, 88);
    } elseif ($mime === 'image/png') {
        $saved = @imagepng($normalized, $target, 6);
    } elseif ($mime === 'image/gif') {
        $saved = @imagegif($normalized, $target);
    } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
        $saved = @imagewebp($normalized, $target, 85);
    }
    imagedestroy($normalized);

    $saved_info = $saved && is_file($target) ? @getimagesize($target) : false;
    $saved_size = $saved && is_file($target) ? filesize($target) : false;
    if (!$saved_info || $saved_size === false || $saved_size < 100 || $saved_size > 15 * 1024 * 1024
        || (int)$saved_info[0] !== $target_width || (int)$saved_info[1] !== $target_height) {
        @unlink($target);
        throw new Exception('Candidate photo verification failed after secure processing.');
    }

    chmod($target, 0644);
    if ($existing_path) {
        $photo_root = realpath($root_path . '/assets/photos');
        $absolute_old = realpath($root_path . '/' . ltrim($existing_path, '/'));
        $safe_prefix = $photo_root ? $photo_root . DIRECTORY_SEPARATOR : '';
        if ($absolute_old && $safe_prefix !== '' && is_file($absolute_old)
            && strncasecmp($absolute_old, $safe_prefix, strlen($safe_prefix)) === 0) {
            @unlink($absolute_old);
        }
    }

    return 'assets/photos/' . $filename;
}

function validate_safe_docx_template($path, &$error = null) {
    $error = null;
    $fail = static function ($message) use (&$error) {
        $error = $message;
        return false;
    };

    if (!is_file($path) || !is_readable($path)) {
        return $fail('The uploaded file cannot be read.');
    }

    $fileSize = filesize($path);
    if ($fileSize === false || $fileSize < 100 || $fileSize > 5 * 1024 * 1024) {
        return $fail('The DOCX file size is invalid.');
    }

    if (!class_exists('ZipArchive') || !class_exists('DOMDocument') || !function_exists('finfo_open')) {
        return $fail('Required secure DOCX validation modules are unavailable.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $path) : false;
    if ($finfo) finfo_close($finfo);
    if (!in_array($mime, [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
    ], true)) {
        return $fail('The file content is not a valid DOCX package.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return $fail('The DOCX package cannot be opened.');
    }

    try {
        if ($zip->numFiles < 3 || $zip->numFiles > 200
            || $zip->locateName('[Content_Types].xml') === false
            || $zip->locateName('_rels/.rels') === false
            || $zip->locateName('word/document.xml') === false) {
            return $fail('The DOCX package structure is invalid.');
        }

        $totalUncompressed = 0;
        $xmlEntries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            if (!is_array($entry)) return $fail('The DOCX contains an unreadable entry.');

            $entryName = str_replace(chr(92), '/', (string)($entry['name'] ?? ''));
            $entrySize = (int)($entry['size'] ?? 0);
            $lowerName = strtolower($entryName);
            $totalUncompressed += $entrySize;

            if ($entryName === '' || str_contains($entryName, chr(0))
                || str_starts_with($entryName, '/')
                || preg_match('#(^|/)\.\.(/|$)#', $entryName)
                || preg_match('/^[a-z]:/i', $entryName)
                || $entrySize > 10 * 1024 * 1024
                || $totalUncompressed > 25 * 1024 * 1024) {
                return $fail('The DOCX contains an unsafe or oversized entry.');
            }

            if (preg_match('#(^|/)(vbaproject\.bin|embeddings/|activex/|customui/|afchunk)#i', $entryName)
                || preg_match('/\.(php\d*|phtml|phar|cgi|pl|asp|aspx|jsp|exe|dll|com|scr|msi|hta|cmd|bat|ps1|vbs|js|jar)$/i', $entryName)) {
                return $fail('Embedded code, macros, or active content are not allowed.');
            }

            if (str_ends_with($lowerName, '.xml') || str_ends_with($lowerName, '.rels')) {
                if ($entrySize > 3 * 1024 * 1024) {
                    return $fail('The DOCX contains an oversized XML component.');
                }
                $xmlEntries[] = [$i, $entryName];
            }
        }

        foreach ($xmlEntries as [$index, $entryName]) {
            $xml = $zip->getFromIndex($index);
            if (!is_string($xml)
                || stripos($xml, '<!DOCTYPE') !== false
                || stripos($xml, '<!ENTITY') !== false) {
                return $fail('Unsafe XML declarations are not allowed.');
            }

            $previousErrors = libxml_use_internal_errors(true);
            $document = new DOMDocument();
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
            if (!$loaded) return $fail('The DOCX contains malformed XML.');

            foreach ($document->getElementsByTagName('Relationship') as $relationship) {
                $target = trim(html_entity_decode($relationship->getAttribute('Target'), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                $targetMode = strtolower(trim($relationship->getAttribute('TargetMode')));
                $type = strtolower(trim($relationship->getAttribute('Type')));
                if ($targetMode === 'external'
                    || preg_match('#^[a-z][a-z0-9+.-]*:#i', $target)
                    || str_starts_with($target, '//')
                    || str_starts_with($target, chr(92) . chr(92))
                    || preg_match('/(?:oleobject|attachedtemplate|control|package)$/i', $type)) {
                    return $fail('External links and embedded objects are not allowed.');
                }
            }

            foreach ($document->getElementsByTagName('*') as $element) {
                $localName = strtolower($element->localName ?? '');
                if ($localName === 'altchunk') {
                    return $fail('Alternate embedded document content is not allowed.');
                }
                if ($localName === 'instrtext' || $localName === 'fldsimple') {
                    $instruction = $element->textContent . ' ' . $element->getAttribute('instr');
                    if (preg_match('/\b(?:DDE|DDEAUTO|INCLUDETEXT|INCLUDEPICTURE)\b/i', $instruction)) {
                        return $fail('Dynamic document fields are not allowed.');
                    }
                }
            }

            if ($entryName === '[Content_Types].xml'
                && preg_match('/(?:macroenabled|vbaproject|activex|oleobject)/i', $xml)) {
                return $fail('Macro-enabled or active DOCX content is not allowed.');
            }
        }
    } finally {
        $zip->close();
    }

    return true;
}
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf_token($token) {
    return is_string($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function require_valid_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid security token. Please refresh the page and try again.');
    }
}

function login_identity_hash($identity) {
    return hash('sha256', strtolower(trim((string) $identity)));
}

function login_rate_limit_status($conn, $identity, $attempt_type = 'password', $max_attempts = 5, $window_seconds = 900) {
    $identity_hash = login_identity_hash($identity);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip_limit = max(20, $max_attempts * 4);
    $result = pg_query_params($conn, "
        SELECT COUNT(*) FILTER (WHERE identity_hash = $2) AS identity_attempts,
               COUNT(*) AS ip_attempts,
               EXTRACT(EPOCH FROM (MAX(attempted_at) + ($4::integer * INTERVAL '1 second') - NOW()))::integer AS retry_after
          FROM login_attempts
         WHERE succeeded = FALSE AND attempt_type = $1 AND ip_address = $3
           AND attempted_at >= NOW() - ($4::integer * INTERVAL '1 second')
    ", [$attempt_type, $identity_hash, $ip, $window_seconds]);
    if (!$result) return ['limited' => true, 'retry_after' => $window_seconds];
    $row = pg_fetch_assoc($result);
    return [
        'limited' => (int)($row['identity_attempts'] ?? 0) >= $max_attempts || (int)($row['ip_attempts'] ?? 0) >= $ip_limit,
        'retry_after' => max(1, (int)($row['retry_after'] ?? $window_seconds)),
    ];
}

function record_login_attempt($conn, $identity, $attempt_type, $succeeded) {
    $identity_hash = login_identity_hash($identity);
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if ($succeeded) {
        pg_query_params($conn, "DELETE FROM login_attempts WHERE identity_hash = $1 AND ip_address = $2 AND attempt_type = $3",
            [$identity_hash, $ip, $attempt_type]);
    }
    $inserted = pg_query_params($conn,
        "INSERT INTO login_attempts (identity_hash, ip_address, attempt_type, succeeded) VALUES ($1, $2, $3, $4)",
        [$identity_hash, $ip, $attempt_type, $succeeded ? 'true' : 'false']);
    if (random_int(1, 100) === 1) pg_query($conn, "DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL '7 days'");
    return (bool)$inserted;
}

function ensure_voter_turnout_unique_index($conn) {
    $result = pg_query($conn, "
        SELECT 1 FROM pg_constraint
         WHERE conrelid = 'voter_turnout'::regclass AND contype = 'u'
           AND pg_get_constraintdef(oid) LIKE '%(voter_id, election_id)%'
        UNION ALL
        SELECT 1 FROM pg_indexes
         WHERE schemaname = 'public' AND tablename = 'voter_turnout'
           AND indexdef LIKE '%UNIQUE INDEX%voter_id, election_id%'
         LIMIT 1
    ");
    return $result && pg_num_rows($result) > 0;
}

function column_exists($conn, $table, $column) {
    $result = pg_query_params($conn, "
        SELECT 1
          FROM information_schema.columns
         WHERE table_schema = 'public'
           AND table_name = $1
           AND column_name = $2
         LIMIT 1
    ", [$table, $column]);

    return $result && pg_num_rows($result) > 0;
}

function ensure_voters_election_id_column($conn) {
    return column_exists($conn, 'voters', 'election_id');
}

function ensure_election_access_columns($conn) {
    return column_exists($conn, 'elections', 'voting_access_mode')
        && column_exists($conn, 'elections', 'allowed_grade_section');
}

function election_is_mutable($conn, $election_id) {
    $result = pg_query_params($conn, "SELECT status FROM elections WHERE id = $1", [(int)$election_id]);
    if (!$result || pg_num_rows($result) !== 1) return false;
    return in_array(pg_fetch_result($result, 0, 'status'), ['draft', 'published'], true);
}

function position_election_id($conn, $position_id) {
    $result = pg_query_params($conn, "SELECT election_id FROM positions WHERE id = $1", [(int)$position_id]);
    return $result && pg_num_rows($result) === 1 ? (int)pg_fetch_result($result, 0, 'election_id') : 0;
}

function party_list_matches_election($conn, $party_list_id, $election_id) {
    if (!$party_list_id) return true;
    $result = pg_query_params($conn, "SELECT 1 FROM party_lists WHERE id = $1 AND election_id = $2",
        [(int)$party_list_id, (int)$election_id]);
    return $result && pg_num_rows($result) === 1;
}

function election_access_allows_voter($election, $grade_section) {
    $mode = strtolower(trim($election['voting_access_mode'] ?? 'all'));
    $allowed = trim($election['allowed_grade_section'] ?? '');

    if ($mode !== 'restricted' || $allowed === '') {
        return true;
    }

    $grade_section = trim((string) $grade_section);
    if ($grade_section === '') {
        return false;
    }

    return hash_equals(strtolower($allowed), strtolower($grade_section));
}

function election_access_label($election) {
    $mode = strtolower(trim($election['voting_access_mode'] ?? 'all'));
    $allowed = trim($election['allowed_grade_section'] ?? '');

    if ($mode === 'restricted' && $allowed !== '') {
        return $allowed . ' only';
    }

    return 'All voters';
}

/**
 * Mag-display ng alert message
 */
function display_alert($message, $type = 'info') {
    $types = [
        'info' => 'alert-info',
        'success' => 'alert-success',
        'error' => 'alert-error',
        'warning' => 'alert-warning'
    ];
    
    $class = isset($types[$type]) ? $types[$type] : $types['info'];
    
    return "<div class='alert $class'>" . h($message) . "</div>";
}

/**
 * Convert timestamp to "time ago" format
 */
function timeAgo($timestamp) {
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $time_difference = $current_time - $time_ago;
    $seconds = $time_difference;
    
    $minutes = round($seconds / 60);
    $hours = round($seconds / 3600);
    $days = round($seconds / 86400);
    $weeks = round($seconds / 604800);
    $months = round($seconds / 2629440);
    $years = round($seconds / 31553280);
    
    if ($seconds <= 60) {
        return "Just Now";
    } else if ($minutes <= 60) {
        return ($minutes == 1) ? "1 minute ago" : "$minutes minutes ago";
    } else if ($hours <= 24) {
        return ($hours == 1) ? "1 hour ago" : "$hours hours ago";
    } else if ($days <= 7) {
        return ($days == 1) ? "Yesterday" : "$days days ago";
    } else if ($weeks <= 4.3) {
        return ($weeks == 1) ? "1 week ago" : "$weeks weeks ago";
    } else if ($months <= 12) {
        return ($months == 1) ? "1 month ago" : "$months months ago";
    } else {
        return ($years == 1) ? "1 year ago" : "$years years ago";
    }
}


// ============================================
// SETTINGS FUNCTIONS (with error handling)
// ============================================

function get_setting($key) {
    global $conn;
    if (!$conn) {
        error_log("Database connection not available in get_setting()");
        return null;
    }
    $result = pg_query_params($conn, "SELECT setting_value FROM settings WHERE setting_key = $1", [$key]);
    if ($result && pg_num_rows($result) > 0) {
        return pg_fetch_result($result, 0, 'setting_value');
    }
    return null;
}

function update_setting($key, $value) {
    global $conn;
    if (!$conn) return false;
    $result = pg_query_params($conn, 
        "INSERT INTO settings (setting_key, setting_value) VALUES ($1, $2) 
         ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP",
        [$key, $value]);
    return $result !== false;
}

function site_name() {
    static $cached = null;
    if ($cached === null) {
        $name = get_setting('site_name');
        $cached = ($name !== null) ? $name : 'Feedback Management System';
    }
    return $cached;
}

function site_logo() {
    static $cached = null;
    if ($cached === null) {
        $logo = get_setting('logo_path');
        $cached = ($logo !== null) ? $logo : 'assets/images/OLLCF_LOGO.png';
    }
    return $cached;
}

function site_background() {
    static $cached = null;
    if ($cached === null) {
        $bg = get_setting('background_path');
        $cached = ($bg !== null) ? $bg : 'assets/images/OLLCF_MAIN.jpg';
    }
    return $cached;
}

// Optional: Clear cache after update (call after updating settings)
function clear_settings_cache() {
    // Static cache is per request, no need to clear globally
    // For long-running scripts, you can add a version parameter
}

// Add this function to the existing settings functions
function site_favicon() {
    static $cached = null;
    if ($cached === null) {
        $fav = get_setting('favicon_path');
        $cached = ($fav !== null) ? $fav : 'assets/images/OLLCF_LOGO.png';
    }
    return $cached;
}

function school_name() {
    static $cached = null;
    if ($cached === null) {
        $name = get_setting('school_name');
        $cached = ($name !== null) ? $name : 'Our Lady of Lourdes College Foundation, Inc.';
    }
    return $cached;
}

function school_address() {
    static $cached = null;
    if ($cached === null) {
        $addr = get_setting('school_address');
        $cached = ($addr !== null) ? $addr : 'Vinzons Ave. Daet, Camarines Norte';
    }
    return $cached;
}
?>
