<?php
// admin/voters/download_credentials.php (FIXED VERSION)
// This version is simpler and more reliable

$root_path = dirname(__FILE__) . '/../..';
require_once $root_path . '/config/database.php';
require_once $root_path . '/includes/session.php';
require_once $root_path . '/includes/functions.php';

if (!is_admin_logged_in()) {
    redirect('../../index.php');
}

$voters = [];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Invalid request method.');
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('Invalid security token. Please go back and try again.');
}

if (!verify_admin_password($conn, $_SESSION['admin_id'] ?? 0, $_POST['admin_password'] ?? '')) {
    http_response_code(403);
    die('Admin password confirmation is required to download credentials.');
}

// Priority 1: Session downloadable_credentials

if (isset($_SESSION['downloadable_credentials']) && !empty($_SESSION['downloadable_credentials'])) {
    $type = $_SESSION['downloadable_type'] ?? 'bulk';
    if ($type === 'bulk') {
        $voters = $_SESSION['downloadable_credentials'];
    } elseif ($type === 'single') {
        $voters = [$_SESSION['downloadable_credentials']];
    }
}

// Priority 2: Session last_generated fallback
if (empty($voters) && !empty($_SESSION['last_generated'])) {
    foreach ($_SESSION['last_generated'] as $v) {
        $voters[] = [
            'voter_id'      => $v['voter_id'],
            'password'      => $v['password'],
            'full_name'     => $v['full_name'],
            'grade_section' => $v['grade_section'] ?: '',
            'election_id'   => $v['election_id'] ?? null,
            'election_title'=> $v['election_title'] ?? '',
        ];
    }
}

if (empty($voters)) {
    die('No credential data found. Please go back and try downloading again from the credentials page.');
}

$audit_type = $_SESSION['downloadable_type'] ?? 'unknown';
audit_log($conn, 'credential_download', 'voters', null, ['count' => count($voters), 'type' => $audit_type]);
unset($_SESSION['downloadable_credentials'], $_SESSION['downloadable_type'], $_SESSION['last_generated'], $_SESSION['reset_credentials']);

function credential_election_title($voters, $conn) {
    if (!empty($voters[0]['election_title'])) {
        return $voters[0]['election_title'];
    }

    if (!empty($voters[0]['election_id'])) {
        $elec_r = pg_query_params($conn, "SELECT title FROM elections WHERE id = $1", [$voters[0]['election_id']]);
        if ($elec_r && pg_num_rows($elec_r) > 0) {
            return pg_fetch_result($elec_r, 0, 'title');
        }
    }

    $elec_r = pg_query($conn, "SELECT title FROM elections WHERE status='ongoing' LIMIT 1");
    return ($elec_r && pg_num_rows($elec_r) > 0) ? pg_fetch_result($elec_r, 0, 'title') : '';
}

$template_path = $root_path . '/templates/credentials_template.docx';

// Use the uploaded Word template first. The generated DOCX is only a fallback
// when there is no uploaded template or PHP cannot edit DOCX files.
$template_validation_error = '';
if (file_exists($template_path) && extension_loaded('zip')
    && validate_safe_docx_template($template_path, $template_validation_error)) {
    try {
        download_using_template($voters, $template_path, $conn, $root_path);
        exit;
    } catch (Exception $e) {
        // Fall through to the default generator so admins can still download
        // credentials even if the uploaded template is malformed.
    }
}

download_using_gen_docx($voters, $conn, $root_path);
exit;

// ============================================================================
// FUNCTION: Download Using Template
// ============================================================================
function download_using_template($voters, $template_path, $conn, $root_path) {
    
    // Get election info
    $elec_title = credential_election_title($voters, $conn);
    
    $school_name = school_name();
    $generated_date = date('F d, Y h:i A');
    
    // Create temp working file
    $temp_file = sys_get_temp_dir() . '/vms_cred_' . uniqid() . '.docx';
    
    if (!copy($template_path, $temp_file)) {
        throw new Exception('Failed to create working copy');
    }
    
    $zip = new ZipArchive();
    
    if ($zip->open($temp_file) !== true) {
        @unlink($temp_file);
        throw new Exception('Failed to open template as ZIP');
    }
    
    // Get document.xml
    $xml_content = $zip->getFromName('word/document.xml');
    if ($xml_content === false) {
        $zip->close();
        @unlink($temp_file);
        throw new Exception('Template has no document.xml');
    }
    
    // PREPARE REPLACEMENTS
    // ─────────────────────────────────────────────────────────────────
    
    $replacements = [
        '{{SCHOOL_NAME}}'      => $school_name,
        '{{ELECTION_TITLE}}'   => $elec_title,
        '{{GENERATED_DATE}}'   => $generated_date,
    ];
    
    // Single voter - replace individual fields
    if (count($voters) === 1) {
        $v = $voters[0];
        $replacements['{{VOTER_ID}}']       = $v['voter_id'];
        $replacements['{{PASSWORD}}']       = $v['password'];
        $replacements['{{FULL_NAME}}']      = $v['full_name'];
        $replacements['{{GRADE_SECTION}}']  = $v['grade_section'] ?? '—';
        
        $filename = 'credentials_' . $v['voter_id'] . '.docx';
    } 
    // Multiple voters - prepare as text for admin
    else {
        $voter_list = '';
        foreach ($voters as $i => $v) {
            $voter_list .= ($i + 1) . '. ' . $v['full_name'] . 
                          ' (ID: ' . $v['voter_id'] . ', Pass: ' . $v['password'] . ")\n";
        }
        $replacements['{{VOTER_LIST}}'] = $voter_list;
        $replacements['{{TOTAL_VOTERS}}'] = count($voters);
        $replacements['{{TABLE_ROWS}}'] = '';
        
        $filename = 'credentials_bulk_' . date('Ymd_His') . '.docx';
    }
    
    // DO REPLACEMENTS
    // ─────────────────────────────────────────────────────────────────
    
    $has_single_voter_placeholders = docx_has_any_placeholder($xml_content, [
        '{{VOTER_ID}}',
        '{{PASSWORD}}',
        '{{FULL_NAME}}',
        '{{GRADE_SECTION}}',
    ]);
    $has_bulk_voter_placeholders = docx_has_any_placeholder($xml_content, [
        '{{VOTER_LIST}}',
        '{{TOTAL_VOTERS}}',
    ]);

    $xml_content = replace_docx_placeholders($xml_content, $replacements);

    // If the uploaded template only contains header placeholders, add the
    // credential data so the downloaded document is never blank/incomplete.
    if (count($voters) === 1 && !$has_single_voter_placeholders) {
        $xml_content = insert_before_docx_body_end($xml_content, build_single_voter_xml($voters[0]));
    } elseif (count($voters) > 1 && !$has_bulk_voter_placeholders) {
        $xml_content = insert_before_docx_body_end($xml_content, build_bulk_voters_xml($voters));
    }
    
    // UPDATE XML IN ZIP
    // ─────────────────────────────────────────────────────────────────
    
    if (!$zip->addFromString('word/document.xml', $xml_content)) {
        $zip->close();
        @unlink($temp_file);
        throw new Exception('Failed to update document.xml in template');
    }
    
    $zip->close();
    
    // SEND FILE
    // ─────────────────────────────────────────────────────────────────
    
    if (!file_exists($temp_file) || filesize($temp_file) < 100) {
        throw new Exception('Generated file is invalid');
    }
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($temp_file));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    readfile($temp_file);
    @unlink($temp_file);
    
    exit;
}

function replace_docx_placeholders($xml_content, $replacements) {
    foreach ($replacements as $placeholder => $value) {
        $xml_content = str_replace($placeholder, escape_docx_text($value), $xml_content);
    }

    return $xml_content;
}

function docx_has_any_placeholder($xml_content, $placeholders) {
    foreach ($placeholders as $placeholder) {
        if (strpos($xml_content, $placeholder) !== false) {
            return true;
        }
    }

    return false;
}

function insert_before_docx_body_end($xml_content, $insert_xml) {
    $body_end = '</w:body>';
    $pos = strrpos($xml_content, $body_end);

    if ($pos === false) {
        return $xml_content . $insert_xml;
    }

    return substr($xml_content, 0, $pos) . $insert_xml . substr($xml_content, $pos);
}

function escape_docx_text($value) {
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function build_docx_paragraph($text, $bold = false, $center = false) {
    $bold_xml = $bold ? '<w:b/>' : '';
    $center_xml = $center ? '<w:pPr><w:jc w:val="center"/></w:pPr>' : '';
    $safe_text = escape_docx_text($text);

    return '<w:p>' . $center_xml . '<w:r><w:rPr>' . $bold_xml . '</w:rPr><w:t>' . $safe_text . '</w:t></w:r></w:p>';
}

function build_docx_table_cell($text, $bold = false) {
    $bold_xml = $bold ? '<w:b/>' : '';
    $safe_text = escape_docx_text($text);

    return '<w:tc><w:tcPr><w:tcW w:w="1872" w:type="dxa"/></w:tcPr><w:p><w:r><w:rPr>' .
        $bold_xml . '</w:rPr><w:t>' . $safe_text . '</w:t></w:r></w:p></w:tc>';
}

function build_docx_table_row($cells, $header = false) {
    $xml = '<w:tr>';
    foreach ($cells as $cell) {
        $xml .= build_docx_table_cell($cell, $header);
    }
    $xml .= '</w:tr>';

    return $xml;
}

function build_single_voter_xml($voter) {
    $grade_section = $voter['grade_section'] ?? '-';

    return
        build_docx_paragraph('Voter Credentials', true, true) .
        '<w:tbl><w:tblPr><w:tblW w:w="9360" w:type="dxa"/><w:tblBorders>' .
        '<w:top w:val="single" w:sz="8" w:space="0" w:color="999999"/>' .
        '<w:left w:val="single" w:sz="8" w:space="0" w:color="999999"/>' .
        '<w:bottom w:val="single" w:sz="8" w:space="0" w:color="999999"/>' .
        '<w:right w:val="single" w:sz="8" w:space="0" w:color="999999"/>' .
        '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="CCCCCC"/>' .
        '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="CCCCCC"/>' .
        '</w:tblBorders></w:tblPr>' .
        build_docx_table_row(['Field', 'Information'], true) .
        build_docx_table_row(['Voter ID', $voter['voter_id'] ?? '']) .
        build_docx_table_row(['Password', $voter['password'] ?? '']) .
        build_docx_table_row(['Full Name', $voter['full_name'] ?? '']) .
        build_docx_table_row(['Grade/Section', $grade_section]) .
        '</w:tbl>' .
        build_docx_paragraph('CONFIDENTIAL - Keep this document secure. Password is shown only once.', false, true);
}

function build_bulk_voters_xml($voters) {
    $xml =
        build_docx_paragraph('Generated Voter Credentials', true, true) .
        '<w:tbl><w:tblPr><w:tblW w:w="9360" w:type="dxa"/><w:tblBorders>' .
        '<w:top w:val="single" w:sz="8" w:space="0" w:color="999999"/>' .
        '<w:left w:val="single" w:sz="8" w:space="0" w:color="999999"/>' .
        '<w:bottom w:val="single" w:sz="8" w:space="0" w:color="999999"/>' .
        '<w:right w:val="single" w:sz="8" w:space="0" w:color="999999"/>' .
        '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="CCCCCC"/>' .
        '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="CCCCCC"/>' .
        '</w:tblBorders></w:tblPr>' .
        build_docx_table_row(['#', 'Voter ID', 'Password', 'Full Name', 'Grade/Section'], true);

    foreach ($voters as $i => $voter) {
        $xml .= build_docx_table_row([
            (string) ($i + 1),
            $voter['voter_id'] ?? '',
            $voter['password'] ?? '',
            $voter['full_name'] ?? '',
            $voter['grade_section'] ?? '-',
        ]);
    }

    $xml .= '</w:tbl>' .
        build_docx_paragraph('CONFIDENTIAL - Keep this document secure. Passwords are shown only once.', false, true);

    return $xml;
}

// ============================================================================
// FUNCTION: Download Using gen_docx.js (Fallback)
// ============================================================================
function download_using_gen_docx($voters, $conn, $root_path) {
    
    // Get election info
    $elec_title = credential_election_title($voters, $conn);
    
    $payload = [
        'voters'         => $voters,
        'generated_date' => date('F d, Y h:i A'),
        'election_title' => $elec_title,
        'school_name'    => school_name(),
    ];
    
    $tmpJson   = sys_get_temp_dir() . '/vms_creds_' . uniqid() . '.json';
    $tmpDocx   = sys_get_temp_dir() . '/vms_creds_' . uniqid() . '.docx';
    $genScript = $root_path . '/gen_docx.js';
    $logo1     = $root_path . '/assets/images/vms_logo1.png';
    $logo2     = $root_path . '/assets/images/vms_logo2.png';
    
    file_put_contents($tmpJson, json_encode($payload, JSON_UNESCAPED_UNICODE));
    
    $cmd = escapeshellcmd("node " . escapeshellarg($genScript)) .
           " credentials " .
           escapeshellarg($tmpJson) . " " .
           escapeshellarg($tmpDocx) . " " .
           escapeshellarg($logo1)   . " " .
           escapeshellarg($logo2)   . " 2>&1";
    
    $output = shell_exec($cmd);
    @unlink($tmpJson);
    
    if (!file_exists($tmpDocx) || filesize($tmpDocx) < 100) {
        die('Credential generation failed. Please contact administrator.');
    }
    
    $suffix   = count($voters) === 1 ? $voters[0]['voter_id'] : 'bulk_' . date('Ymd_His');
    $filename = 'voter_credentials_' . $suffix . '.docx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpDocx));
    header('Cache-Control: no-cache');
    readfile($tmpDocx);
    @unlink($tmpDocx);
}
?>
